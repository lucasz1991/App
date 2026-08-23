import crypto from 'node:crypto';
import dns from 'node:dns/promises';
import fs from 'node:fs/promises';
import https from 'node:https';
import net from 'node:net';
import path from 'node:path';
import { ConnectorError } from './errors.js';
import { artifactSignature } from './security.js';

export const SCRIPT_EXTENSIONS = new Set(['ps1', 'bat', 'cmd', 'sh']);

export function artifactExtension(artifact) {
  const extension = path.extname(artifact.name).slice(1).toLowerCase();
  if (!SCRIPT_EXTENSIONS.has(extension)) {
    throw new ConnectorError('Für MeshCentral sind nur freigegebene PS1-, BAT-, CMD- oder SH-Artefakte erlaubt.', {
      statusCode: 422,
      code: 'unsupported_artifact_type',
    });
  }

  return extension;
}

export function safeArtifactFileName(artifact, correlationId) {
  const extension = artifactExtension(artifact);
  const stem = path.basename(artifact.name, path.extname(artifact.name))
    .normalize('NFKD')
    .replaceAll(/[^A-Za-z0-9._-]+/g, '-')
    .replaceAll(/^-+|-+$/g, '')
    .slice(0, 64) || 'script';
  const prefix = crypto.createHash('sha256').update(correlationId, 'utf8').digest('hex').slice(0, 16);
  return `${prefix}-${stem}.${extension}`;
}

export async function downloadArtifact(config, artifact, destinationFile, dependencies = {}) {
  const lookup = dependencies.lookup ?? dns.lookup;
  const request = dependencies.request ?? https.request;
  const url = validateArtifactUrl(config, artifact.download_url);
  const addresses = await lookup(url.hostname, { all: true, verbatim: true });
  if (!Array.isArray(addresses) || addresses.length === 0) {
    throw new ConnectorError('Der RailTime-Artefakthost konnte nicht aufgelöst werden.', {
      statusCode: 502,
      code: 'artifact_dns_failed',
      retryable: true,
    });
  }
  if (!config.artifacts.allowPrivateHosts && addresses.some((entry) => isPrivateOrReservedIp(entry.address))) {
    throw new ConnectorError('Der RailTime-Artefakthost wurde auf eine private oder reservierte Adresse aufgelöst.', {
      statusCode: 502,
      code: 'artifact_unsafe_address',
    });
  }

  const selected = addresses[0];
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = artifactSignature(timestamp, url.pathname, config.auth.railtimeHmacSecret);
  let handle;
  try {
    handle = await fs.open(destinationFile, 'wx', 0o600);
    const response = await makeRequest(request, url, selected, {
      'Accept': 'application/octet-stream',
      'X-RailTime-Timestamp': timestamp,
      'X-RailTime-Signature': `sha256=${signature}`,
      'User-Agent': 'RailTime-MeshCentral-Connector/1.0.0',
    }, config.artifacts.downloadTimeoutMs);

    if (response.statusCode !== 200) {
      response.resume();
      throw new ConnectorError('RailTime hat das Artefakt nicht ausgeliefert.', {
        statusCode: 502,
        code: 'artifact_download_rejected',
        retryable: response.statusCode >= 500,
      });
    }

    const declaredLength = parseContentLength(response.headers['content-length']);
    if (declaredLength !== null && declaredLength !== artifact.size_bytes) {
      response.destroy();
      throw new ConnectorError('Die deklarierte Artefaktgröße stimmt nicht mit RailTime überein.', {
        statusCode: 502,
        code: 'artifact_size_mismatch',
      });
    }
    const headerHash = response.headers['x-content-sha256'];
    if (typeof headerHash === 'string' && !constantHexEquals(headerHash, artifact.sha256)) {
      response.destroy();
      throw new ConnectorError('Der RailTime-Prüfsummenheader stimmt nicht mit dem Auftrag überein.', {
        statusCode: 502,
        code: 'artifact_hash_mismatch',
      });
    }

    const hash = crypto.createHash('sha256');
    let bytes = 0;
    for await (const chunk of response) {
      bytes += chunk.length;
      if (bytes > artifact.size_bytes || bytes > config.artifacts.maxBytes) {
        response.destroy();
        throw new ConnectorError('Das Artefakt überschreitet die freigegebene Größe.', {
          statusCode: 413,
          code: 'artifact_too_large',
        });
      }
      hash.update(chunk);
      await handle.write(chunk);
    }
    await handle.sync();
    await handle.close();
    handle = null;

    const actualHash = hash.digest('hex');
    if (bytes !== artifact.size_bytes || !constantHexEquals(actualHash, artifact.sha256)) {
      throw new ConnectorError('Größe oder SHA-256 des geladenen Artefakts ist ungültig.', {
        statusCode: 502,
        code: 'artifact_integrity_failed',
      });
    }

    return { bytes, sha256: actualHash };
  } catch (error) {
    await handle?.close();
    await fs.rm(destinationFile, { force: true });
    if (error instanceof ConnectorError) {
      throw error;
    }
    throw new ConnectorError('Das Artefakt konnte nicht sicher geladen werden.', {
      statusCode: 502,
      code: 'artifact_download_failed',
      retryable: true,
      cause: error,
    });
  }
}

export function validateArtifactUrl(config, rawUrl) {
  let url;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new ConnectorError('Die Artefakt-URL ist ungültig.', {
      statusCode: 422,
      code: 'invalid_artifact_url',
    });
  }
  const host = url.hostname.toLowerCase();
  if (url.protocol !== 'https:'
    || url.username !== ''
    || url.password !== ''
    || url.hash !== ''
    || url.search !== ''
    || !config.artifacts.allowedHosts.includes(host)) {
    throw new ConnectorError('Die Artefakt-URL liegt nicht auf einem freigegebenen HTTPS-Host.', {
      statusCode: 422,
      code: 'unsafe_artifact_url',
    });
  }

  return url;
}

function makeRequest(request, url, selected, headers, timeoutMs) {
  return new Promise((resolve, reject) => {
    const requestOptions = {
      protocol: 'https:',
      hostname: url.hostname,
      port: url.port || 443,
      method: 'GET',
      path: url.pathname,
      headers,
      servername: url.hostname,
      rejectUnauthorized: true,
      family: selected.family,
      lookup: (_host, _options, callback) => callback(null, selected.address, selected.family),
      agent: false,
    };
    const outgoing = request(requestOptions, resolve);
    outgoing.setTimeout(timeoutMs, () => {
      outgoing.destroy(new Error('artifact request timeout'));
    });
    outgoing.on('error', reject);
    outgoing.end();
  });
}

function parseContentLength(value) {
  if (value === undefined) return null;
  if (Array.isArray(value) || !/^\d+$/.test(value)) {
    throw new ConnectorError('Der Artefaktserver hat eine ungültige Content-Length geliefert.', {
      statusCode: 502,
      code: 'artifact_invalid_length',
    });
  }
  return Number.parseInt(value, 10);
}

function constantHexEquals(left, right) {
  if (!/^[a-f0-9]{64}$/i.test(left) || !/^[a-f0-9]{64}$/i.test(right)) {
    return false;
  }
  return crypto.timingSafeEqual(Buffer.from(left.toLowerCase()), Buffer.from(right.toLowerCase()));
}

export function isPrivateOrReservedIp(address) {
  const family = net.isIP(address);
  if (family === 4) {
    const parts = address.split('.').map(Number);
    const [a, b] = parts;
    return a === 0
      || a === 10
      || a === 127
      || (a === 100 && b >= 64 && b <= 127)
      || (a === 169 && b === 254)
      || (a === 172 && b >= 16 && b <= 31)
      || (a === 192 && b === 0)
      || (a === 192 && b === 168)
      || (a === 198 && (b === 18 || b === 19))
      || (a === 198 && b === 51)
      || (a === 203 && b === 0)
      || a >= 224;
  }
  if (family === 6) {
    const normalized = address.toLowerCase();
    return normalized === '::'
      || normalized === '::1'
      || normalized.startsWith('fc')
      || normalized.startsWith('fd')
      || /^fe[89ab]/.test(normalized)
      || normalized.startsWith('ff')
      || normalized.startsWith('2001:db8:')
      || normalized.startsWith('::ffff:');
  }

  return true;
}
