import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { EventEmitter } from 'node:events';
import fs from 'node:fs/promises';
import { Readable } from 'node:stream';
import { test } from 'node:test';
import { artifactSignature, stableJson } from '../src/security.js';
import { downloadArtifact, isPrivateOrReservedIp, validateArtifactUrl } from '../src/artifacts.js';
import { temporaryRuntime } from './helpers.js';

test('artifact download pins DNS, signs the exact RailTime path and verifies bytes plus SHA-256', async () => {
  const runtime = await temporaryRuntime();
  const payload = Buffer.from('echo RailTime\n');
  const sha256 = crypto.createHash('sha256').update(payload).digest('hex');
  const destination = `${runtime.config.state.tempDirectory}/artifact.sh`;
  let capturedOptions;
  const request = (options, callback) => {
    capturedOptions = options;
    const outgoing = new EventEmitter();
    outgoing.setTimeout = () => {};
    outgoing.end = () => {
      const response = Readable.from([payload]);
      response.statusCode = 200;
      response.headers = {
        'content-length': String(payload.length),
        'x-content-sha256': sha256,
      };
      callback(response);
    };
    outgoing.destroy = (error) => outgoing.emit('error', error);
    return outgoing;
  };
  try {
    const artifact = {
      download_url: 'https://app.example.com/api/device-management/providers/meshcentral/artifacts/abc',
      sha256,
      size_bytes: payload.length,
      name: 'artifact.sh',
      authentication: 'railtime-hmac-v1',
    };
    const result = await downloadArtifact(runtime.config, artifact, destination, {
      lookup: async () => [{ address: '93.184.216.34', family: 4 }],
      request,
    });
    assert.equal(result.sha256, sha256);
    assert.deepEqual(await fs.readFile(destination), payload);
    assert.equal(capturedOptions.hostname, 'app.example.com');
    assert.equal(capturedOptions.path, '/api/device-management/providers/meshcentral/artifacts/abc');
    assert.equal(capturedOptions.rejectUnauthorized, true);
    assert.equal(capturedOptions.lookup('ignored', {}, () => {}), undefined);

    const timestamp = capturedOptions.headers['X-RailTime-Timestamp'];
    assert.equal(
      capturedOptions.headers['X-RailTime-Signature'],
      `sha256=${artifactSignature(timestamp, capturedOptions.path, runtime.config.auth.railtimeHmacSecret)}`,
    );
  } finally {
    await runtime.cleanup();
  }
});

test('artifact URL allowlist, integrity and reserved-address checks fail closed', async () => {
  const runtime = await temporaryRuntime();
  try {
    assert.throws(
      () => validateArtifactUrl(runtime.config, 'https://evil.example.net/artifact'),
      (error) => error.code === 'unsafe_artifact_url',
    );
    await assert.rejects(
      downloadArtifact(runtime.config, {
        download_url: 'https://app.example.com/artifact',
        sha256: 'a'.repeat(64),
        size_bytes: 1,
        name: 'artifact.sh',
        authentication: 'railtime-hmac-v1',
      }, `${runtime.config.state.tempDirectory}/blocked.sh`, {
        lookup: async () => [{ address: '127.0.0.1', family: 4 }],
        request: () => { throw new Error('must not request'); },
      }),
      (error) => error.code === 'artifact_unsafe_address',
    );
    assert.equal(isPrivateOrReservedIp('10.0.0.1'), true);
    assert.equal(isPrivateOrReservedIp('93.184.216.34'), false);
    assert.equal(isPrivateOrReservedIp('2001:db8::1'), true);
  } finally {
    await runtime.cleanup();
  }
});

test('stable JSON fingerprints do not depend on object insertion order', () => {
  assert.equal(stableJson({ b: 2, a: { d: 4, c: 3 } }), stableJson({ a: { c: 3, d: 4 }, b: 2 }));
});
