import fs from 'node:fs/promises';
import path from 'node:path';
import { artifactExtension, downloadArtifact, safeArtifactFileName } from './artifacts.js';
import { isMeshIdentifier } from './config.js';
import { ConnectorError } from './errors.js';
import { remotePath } from './meshctrl.js';
import { deterministicJobId, fingerprint } from './security.js';

const CONTRACT_VERSION = '1.0.0';
const CONNECTOR_VERSION = '1.0.0';
const PROVIDER_VERSION = '1.2.5';
const COMMAND_KEYS = new Set(['command_id', 'correlation_id', 'device_id', 'provider_device_id', 'command', 'options', 'artifact']);
const ARTIFACT_KEYS = new Set(['download_url', 'sha256', 'size_bytes', 'name', 'authentication']);

export class ConnectorService {
  #config;
  #meshctrl;
  #store;
  #artifactDownloader;
  #lastSuccessAt = null;

  constructor(config, meshctrl, store, artifactDownloader = downloadArtifact) {
    this.#config = config;
    this.#meshctrl = meshctrl;
    this.#store = store;
    this.#artifactDownloader = artifactDownloader;
  }

  capabilities() {
    const commands = [];
    if (this.#config.commands.executeScript) commands.push('execute_script');
    if (this.#config.commands.collectDiagnostics) commands.push('collect_diagnostics');

    return {
      platforms: this.#config.meshcentral.platforms,
      inventory: false,
      enrollment: false,
      remote_support: this.#config.meshcentral.remoteSupport,
      unattended_remote_support: this.#config.meshcentral.unattendedRemoteSupport,
      commands,
      readiness_checks: ['remote_support'],
    };
  }

  async health() {
    try {
      const info = await this.#meshctrl.serverInfo();
      this.#lastSuccessAt = new Date().toISOString();
      return {
        healthy: true,
        status: 'operational',
        contract_version: CONTRACT_VERSION,
        connector_version: CONNECTOR_VERSION,
        provider: 'meshcentral',
        capabilities: this.capabilities(),
        upstream: {
          reachable: true,
          authenticated: true,
          status: 'connected',
          api_version: upstreamVersion(info),
          last_success_at: this.#lastSuccessAt,
        },
        details: {
          meshcentral_pinned_version: PROVIDER_VERSION,
          meshctrl_transport: 'loopback-wss',
          authentication: 'loginkey-file',
        },
      };
    } catch (error) {
      const authenticationFailed = error instanceof ConnectorError
        && error.code === 'meshcentral_authentication_failed';
      return {
        healthy: false,
        status: authenticationFailed ? 'authentication_failed' : 'upstream_unavailable',
        contract_version: CONTRACT_VERSION,
        connector_version: CONNECTOR_VERSION,
        provider: 'meshcentral',
        capabilities: this.capabilities(),
        upstream: {
          reachable: authenticationFailed,
          authenticated: false,
          status: authenticationFailed ? 'authentication_failed' : 'unavailable',
          api_version: null,
          last_success_at: this.#lastSuccessAt,
        },
        details: {
          meshcentral_pinned_version: PROVIDER_VERSION,
          meshctrl_transport: 'loopback-wss',
          authentication: 'loginkey-file',
        },
      };
    }
  }

  async prepareEnrollment() {
    // A native MeshCentral group invitation has no RailTime assignment/device
    // correlation and its effective lifetime/revocation cannot be proven by
    // this adapter. Never expose that weaker primitive as RailTime enrollment.
    throw new ConnectorError(
      'MeshCentral-Enrollment ist deaktiviert. Einen bereits installierten Agenten nach Prüfung manuell über seine native Node-ID mit dem RailTime-Gerät verknüpfen.',
      { statusCode: 409, code: 'enrollment_not_supported' },
    );
  }

  async dispatchCommand(rawRequest) {
    const request = validateCommand(rawRequest, this.capabilities().commands, this.#config.artifacts.maxBytes);
    return this.#store.run('commands', request.correlation_id, fingerprint(request), async () => {
      const providerJobId = deterministicJobId(request.correlation_id);

      if (request.command === 'collect_diagnostics') {
        const details = await this.#meshctrl.diagnostics(request.provider_device_id);
        return {
          accepted: true,
          completed: true,
          provider_job_id: providerJobId,
          message: 'MeshCentral DeviceInfo wurde erfolgreich gelesen und auf eine datensparsame Zusammenfassung reduziert.',
          details,
        };
      }

      if (request.command === 'execute_script') {
        return this.#executeScript(request, providerJobId);
      }

      throw new ConnectorError('Der Befehl ist für diesen Connector nicht freigegeben.', {
        statusCode: 422,
        code: 'unsupported_command',
      });
    });
  }

  async #executeScript(request, providerJobId) {
    const extension = artifactExtension(request.artifact);
    const fileName = safeArtifactFileName(request.artifact, request.correlation_id);
    const commandDirectory = await fs.mkdtemp(path.join(this.#config.state.tempDirectory, 'command-'));
    await fs.chmod(commandDirectory, 0o700);
    const localFile = path.join(commandDirectory, fileName);
    const remoteDirectory = extension === 'sh'
      ? this.#config.artifacts.posixTarget
      : this.#config.artifacts.windowsTarget;
    const targetPath = remotePath(remoteDirectory, fileName, extension);

    try {
      await this.#artifactDownloader(this.#config, request.artifact, localFile);
      await this.#meshctrl.upload(
        request.provider_device_id,
        localFile,
        remoteDirectory,
        request.artifact.size_bytes,
      );
      await this.#meshctrl.executeUploadedScript(
        request.provider_device_id,
        extension,
        targetPath,
        request.correlation_id,
        request.artifact.sha256,
      );

      return {
        accepted: true,
        completed: true,
        provider_job_id: providerJobId,
        message: 'Das geprüfte Artefakt wurde mit verifizierter Prüfsumme übertragen und hat den Erfolgsmarker geliefert.',
        details: {
          artifact_sha256: request.artifact.sha256,
          artifact_size_bytes: request.artifact.size_bytes,
          script_type: extension,
        },
      };
    } finally {
      await fs.rm(commandDirectory, { recursive: true, force: true });
    }
  }
}

export function validateCommand(value, supportedCommands, maximumArtifactBytes) {
  assertExactObject(value, COMMAND_KEYS, 'Command');
  const command = boundedString(value.command, 'command', 80);
  if (!supportedCommands.includes(command)) {
    throw validationError('Der Befehl ist für diesen MeshCentral-Connector nicht freigegeben.');
  }
  if (!value.options || typeof value.options !== 'object' || Array.isArray(value.options) || Object.keys(value.options).length !== 0) {
    throw validationError('MeshCentral-Befehle akzeptieren keine freien Requestoptionen.');
  }
  const providerDeviceId = nullableString(value.provider_device_id, 'provider_device_id', 191);
  if (providerDeviceId === null || !isMeshIdentifier(providerDeviceId, 'node')) {
    throw validationError('provider_device_id ist für MeshCentral-Befehle erforderlich und muss eine sichere Node-ID sein.');
  }

  let artifact;
  if (command === 'execute_script') {
    artifact = validateArtifact(value.artifact, maximumArtifactBytes);
  } else if (value.artifact !== undefined && value.artifact !== null) {
    throw validationError('Dieser MeshCentral-Befehl akzeptiert kein Artefakt.');
  }

  return {
    command_id: safeIdentifier(boundedString(value.command_id, 'command_id', 64), 'command_id'),
    correlation_id: safeIdentifier(boundedString(value.correlation_id, 'correlation_id', 128), 'correlation_id'),
    device_id: safeIdentifier(boundedString(value.device_id, 'device_id', 64), 'device_id'),
    provider_device_id: providerDeviceId,
    command,
    options: {},
    ...(artifact ? { artifact } : {}),
  };
}

function validateArtifact(value, maximumArtifactBytes) {
  assertExactObject(value, ARTIFACT_KEYS, 'Artifact');
  const size = value.size_bytes;
  if (!Number.isInteger(size) || size < 1 || size > maximumArtifactBytes) {
    throw validationError('Das Artefakt überschreitet die freigegebene Größe.');
  }
  const sha256 = boundedString(value.sha256, 'sha256', 64).toLowerCase();
  if (!/^[a-f0-9]{64}$/.test(sha256)) {
    throw validationError('sha256 muss eine hexadezimale SHA-256-Prüfsumme sein.');
  }
  if (value.authentication !== 'railtime-hmac-v1') {
    throw validationError('Das Artefakt muss railtime-hmac-v1 verwenden.');
  }
  const artifact = {
    download_url: boundedString(value.download_url, 'download_url', 2000),
    sha256,
    size_bytes: size,
    name: boundedString(value.name, 'name', 191),
    authentication: 'railtime-hmac-v1',
  };
  artifactExtension(artifact);
  return artifact;
}

function assertExactObject(value, allowedKeys, label) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw validationError(`${label} muss ein JSON-Objekt sein.`);
  }
  for (const key of Object.keys(value)) {
    if (!allowedKeys.has(key)) {
      throw validationError(`${label} enthält den unbekannten Schlüssel ${key}.`);
    }
  }
}

function boundedString(value, key, maximumLength) {
  if (typeof value !== 'string' || value.length < 1 || value.length > maximumLength || /[\0\r\n]/.test(value)) {
    throw validationError(`${key} ist ungültig.`);
  }
  return value;
}

function nullableString(value, key, maximumLength) {
  if (value === null || value === undefined) return null;
  return boundedString(value, key, maximumLength);
}

function safeIdentifier(value, key) {
  if (!/^[A-Za-z0-9:._-]+$/.test(value)) {
    throw validationError(`${key} enthält nicht erlaubte Zeichen.`);
  }
  return value;
}

function validationError(message) {
  return new ConnectorError(message, {
    statusCode: 422,
    code: 'validation_failed',
  });
}

function upstreamVersion(info) {
  for (const key of ['serverVersion', 'version', 'ServerVersion', 'Version']) {
    const value = info[key];
    if (typeof value === 'string' || typeof value === 'number') {
      return String(value).slice(0, 64);
    }
  }
  return null;
}
