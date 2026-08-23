import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { test } from 'node:test';
import { loadConfig } from '../src/config.js';
import { temporaryRuntime } from './helpers.js';

test('file configuration loads relative secrets without any environment variable', async () => {
  const runtime = await temporaryRuntime();
  const configPath = path.join(runtime.root, 'config.json');
  const tokenPath = path.join(runtime.root, 'bearer-token');
  const hmacPath = path.join(runtime.root, 'hmac-secret');
  await fs.writeFile(tokenPath, `${runtime.config.auth.bearerToken}\n`, { mode: 0o600 });
  await fs.writeFile(hmacPath, `${runtime.config.auth.railtimeHmacSecret}\n`, { mode: 0o600 });
  const fileConfig = {
    server: { host: '127.0.0.1', port: 9442 },
    auth: {
      bearer_token_file: './bearer-token',
      railtime_hmac_secret_file: './hmac-secret',
    },
    meshcentral: {
      meshctrl_path: './meshctrl.js',
      url: 'wss://127.0.0.1:8443',
      login_user: 'railtime-connector',
      login_key_file: './meshcentral-login-key',
    },
    artifacts: {
      allowed_hosts: ['app.example.com'],
      windows_target: 'C:\\ProgramData\\RailTime\\Staging',
      posix_target: '/var/tmp/railtime',
    },
    state: { directory: './loaded-state', temp_directory: './loaded-tmp' },
    security: { require_strict_file_permissions: process.platform !== 'win32' },
  };
  await fs.writeFile(configPath, `${JSON.stringify(fileConfig)}\n`, { mode: 0o600 });

  try {
    const loaded = loadConfig(configPath);
    assert.equal(loaded.server.host, '127.0.0.1');
    assert.equal(loaded.auth.bearerToken, runtime.config.auth.bearerToken);
    assert.equal(loaded.meshcentral.url, 'wss://127.0.0.1:8443');
    assert.equal(loaded.commands.executeScript, true);
    assert.equal(loaded.artifacts.allowedHosts[0], 'app.example.com');
    assert.ok(!JSON.stringify(fileConfig).includes(runtime.config.auth.bearerToken));
  } finally {
    await runtime.cleanup();
  }
});

test('public meshctrl upstream is rejected because official meshctrl disables certificate verification', async () => {
  const runtime = await temporaryRuntime();
  const configPath = path.join(runtime.root, 'config-public-upstream.json');
  const tokenPath = path.join(runtime.root, 'public-token');
  const hmacPath = path.join(runtime.root, 'public-hmac');
  await fs.writeFile(tokenPath, 'x'.repeat(40), { mode: 0o600 });
  await fs.writeFile(hmacPath, 'y'.repeat(40), { mode: 0o600 });
  await fs.writeFile(configPath, JSON.stringify({
    auth: { bearer_token_file: './public-token', railtime_hmac_secret_file: './public-hmac' },
    meshcentral: {
      meshctrl_path: './meshctrl.js',
      url: 'wss://support.example.com',
      login_user: 'railtime-connector',
      login_key_file: './meshcentral-login-key',
    },
    artifacts: { allowed_hosts: ['app.example.com'] },
    state: { directory: './public-state', temp_directory: './public-tmp' },
    security: { require_strict_file_permissions: process.platform !== 'win32' },
  }), { mode: 0o600 });
  try {
    assert.throws(() => loadConfig(configPath), /Loopback/);
  } finally {
    await runtime.cleanup();
  }
});
