import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import { test } from 'node:test';
import { ConnectorService } from '../src/connector-service.js';
import { createConnectorServer } from '../src/http-server.js';
import { MeshctrlClient } from '../src/meshctrl.js';
import { IdempotencyStore } from '../src/state-store.js';
import { nodeId, temporaryRuntime } from './helpers.js';

test('HTTP contract authenticates, reports truthful health and uses loginkey-only meshctrl', async () => {
  const runtime = await temporaryRuntime();
  const server = await startServer(runtime.config);
  try {
    const unauthenticated = await fetch(`${server.url}/v1/health`);
    assert.equal(unauthenticated.status, 401);

    const response = await request(server.url, '/v1/health', runtime.config.auth.bearerToken);
    assert.equal(response.status, 200);
    assert.deepEqual(Object.keys(response.body).sort(), [
      'capabilities', 'connector_version', 'contract_version', 'details', 'healthy', 'provider', 'status', 'upstream',
    ]);
    assert.equal(response.body.healthy, true);
    assert.equal(response.body.provider, 'meshcentral');
    assert.equal(response.body.contract_version, '1.0.0');
    assert.equal(response.body.capabilities.enrollment, false);
    assert.deepEqual(response.body.capabilities.commands, ['execute_script', 'collect_diagnostics']);
    assert.equal(response.body.upstream.api_version, '1.2.5');

    const calls = await runtime.calls();
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], 'ServerInfo');
    assert.ok(calls[0].includes('--json'));
    assert.ok(calls[0].includes('--loginkeyfile'));
    assert.ok(!calls[0].includes('--loginpass'));
  } finally {
    await server.close();
    await runtime.cleanup();
  }
});

test('enrollment and restart fail closed without invoking meshctrl', async () => {
  const runtime = await temporaryRuntime();
  const server = await startServer(runtime.config);
  try {
    const enrollment = {
      enrollment_id: 'enrollment-001',
      device_id: 'device-001',
      provider_device_id: null,
      platform: 'windows',
      mode: 'corporate',
      expires_at: new Date(Date.now() + 3600000).toISOString(),
    };
    const first = await request(server.url, '/v1/enrollments', runtime.config.auth.bearerToken, enrollment);
    assert.equal(first.status, 409);
    assert.equal(first.body.error, 'enrollment_not_supported');
    assert.match(first.body.message, /Node-ID/);

    const command = commandRequest('restart', 'correlation-restart-001');
    const restart = await request(server.url, '/v1/commands', runtime.config.auth.bearerToken, command);
    assert.equal(restart.status, 422);
    assert.equal(restart.body.error, 'validation_failed');
    assert.deepEqual(await runtime.calls(), []);
  } finally {
    await server.close();
    await runtime.cleanup();
  }
});

test('diagnostics and approved script path complete only on documented CLI evidence', async () => {
  const runtime = await temporaryRuntime();
  const content = Buffer.from('Write-Output "RailTime"\r\n', 'utf8');
  const meshctrl = new MeshctrlClient(runtime.config);
  const store = new IdempotencyStore(runtime.config.state.directory);
  const downloader = async (_config, artifact, destination) => {
    assert.equal(artifact.sha256, crypto.createHash('sha256').update(content).digest('hex'));
    await fs.writeFile(destination, content, { flag: 'wx', mode: 0o600 });
    return { bytes: content.length, sha256: artifact.sha256 };
  };
  const service = new ConnectorService(runtime.config, meshctrl, store, downloader);
  const httpServer = createConnectorServer(runtime.config, service, silentLogger());
  const server = await listen(httpServer);
  try {
    const diagnostics = await request(server.url, '/v1/commands', runtime.config.auth.bearerToken,
      commandRequest('collect_diagnostics', 'correlation-diagnostics-001'));
    assert.equal(diagnostics.status, 200);
    assert.equal(diagnostics.body.completed, true);
    assert.equal(diagnostics.body.details.device_name, 'LAB-01');
    assert.equal(diagnostics.body.details.agent_status, 'Connected now');

    const script = commandRequest('execute_script', 'correlation-script-001', {
      artifact: {
        download_url: 'https://app.example.com/api/device-management/providers/meshcentral/artifacts/artifact-001',
        sha256: crypto.createHash('sha256').update(content).digest('hex'),
        size_bytes: content.length,
        name: 'RailTime-Pruefung.ps1',
        authentication: 'railtime-hmac-v1',
      },
    });
    const executed = await request(server.url, '/v1/commands', runtime.config.auth.bearerToken, script);
    assert.equal(executed.status, 200);
    assert.equal(executed.body.completed, true);
    assert.equal(executed.body.details.script_type, 'ps1');

    const calls = await runtime.calls();
    const upload = calls.find(([action]) => action === 'Upload');
    const runCommand = calls.find(([action]) => action === 'RunCommand');
    assert.ok(upload);
    assert.ok(runCommand);
    assert.equal(upload[upload.indexOf('--target') + 1], 'C:\\ProgramData\\RailTime\\Staging');
    assert.ok(runCommand.includes('--reply'));
    assert.ok(runCommand.includes('--powershell'));
    const remoteCommand = runCommand[runCommand.indexOf('--run') + 1];
    assert.match(remoteCommand, /^\$rtPath = 'C:\\ProgramData\\RailTime\\Staging\\[a-f0-9]{16}-RailTime-Pruefung\.ps1';/);
    assert.match(remoteCommand, /Get-FileHash -Algorithm SHA256 -LiteralPath \$rtPath/);
    assert.match(remoteCommand, new RegExp(`\\$rtExpected = '${script.artifact.sha256}'`));
    assert.match(remoteCommand, /finally \{ Remove-Item -LiteralPath \$rtPath -Force -ErrorAction SilentlyContinue \}$/);
  } finally {
    await server.close();
    await runtime.cleanup();
  }
});

test('free options, unsupported artifacts and unsafe provider ids fail before meshctrl', async () => {
  const runtime = await temporaryRuntime();
  const server = await startServer(runtime.config);
  try {
    const freeOptions = await request(server.url, '/v1/commands', runtime.config.auth.bearerToken, {
      ...commandRequest('collect_diagnostics', 'correlation-bad-options'),
      options: { shell: 'whoami' },
    });
    assert.equal(freeOptions.status, 422);

    const unsafeNode = await request(server.url, '/v1/commands', runtime.config.auth.bearerToken, {
      ...commandRequest('collect_diagnostics', 'correlation-bad-node'),
      provider_device_id: '--loginpass',
    });
    assert.equal(unsafeNode.status, 422);

    const badArtifact = await request(server.url, '/v1/commands', runtime.config.auth.bearerToken, commandRequest(
      'execute_script',
      'correlation-bad-artifact',
      {
        artifact: {
          download_url: 'https://app.example.com/artifact',
          sha256: 'a'.repeat(64),
          size_bytes: 5,
          name: 'payload.exe',
          authentication: 'railtime-hmac-v1',
        },
      },
    ));
    assert.equal(badArtifact.status, 422);
    assert.deepEqual(await runtime.calls(), []);
  } finally {
    await server.close();
    await runtime.cleanup();
  }
});

function commandRequest(command, correlationId, additions = {}) {
  return {
    command_id: `command-${correlationId}`.slice(0, 64),
    correlation_id: correlationId,
    device_id: 'device-001',
    provider_device_id: nodeId(),
    command,
    options: {},
    ...additions,
  };
}

async function startServer(config) {
  const meshctrl = new MeshctrlClient(config);
  const store = new IdempotencyStore(config.state.directory);
  const service = new ConnectorService(config, meshctrl, store);
  return listen(createConnectorServer(config, service, silentLogger()));
}

async function listen(server) {
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const port = server.address().port;
  return {
    url: `http://127.0.0.1:${port}`,
    close: () => new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve())),
  };
}

async function request(baseUrl, endpoint, token, body) {
  const response = await fetch(`${baseUrl}${endpoint}`, {
    method: body === undefined ? 'GET' : 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  return { status: response.status, body: await response.json() };
}

function silentLogger() {
  return { info() {}, warn() {}, error() {} };
}
