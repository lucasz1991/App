import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { test } from 'node:test';
import { fixedScriptCommand, MeshctrlClient } from '../src/meshctrl.js';
import { nodeId, temporaryRuntime } from './helpers.js';

const MARKER = `RAILTIME_OK_${'a'.repeat(24)}`;
const SHA256 = 'b'.repeat(64);

test('Windows scripts verify the target hash before execution and always remove the staged file', () => {
  for (const extension of ['ps1', 'bat', 'cmd']) {
    const remoteFile = `C:\\ProgramData\\RailTime\\Staging\\approved.${extension}`;
    const result = fixedScriptCommand(extension, remoteFile, MARKER, SHA256);

    assert.equal(result.powershell, true);
    assert.match(result.command, /^\$rtPath = 'C:\\ProgramData\\RailTime\\Staging\\approved\.(?:ps1|bat|cmd)';/);
    assert.match(result.command, new RegExp(`\\$rtExpected = '${SHA256}'`));
    assert.match(result.command, /Get-FileHash -Algorithm SHA256 -LiteralPath \$rtPath -ErrorAction Stop/);
    assert.match(result.command, /RAILTIME_ARTIFACT_HASH_MISMATCH/);
    assert.match(result.command, /finally \{ Remove-Item -LiteralPath \$rtPath -Force -ErrorAction SilentlyContinue \}$/);
    assert.ok(result.command.indexOf('Get-FileHash') < result.command.indexOf('& $rtPath'));
    assert.ok(result.command.indexOf('& $rtPath') < result.command.indexOf(`Write-Output '${MARKER}'`));
  }
});

test('POSIX scripts support Linux and macOS hash tools, fail closed and clean up via trap', () => {
  const result = fixedScriptCommand('sh', '/var/tmp/railtime/approved.sh', MARKER, SHA256);

  assert.equal(result.powershell, false);
  assert.match(result.command, new RegExp(`rt_expected='${SHA256}'`));
  assert.match(result.command, /command -v sha256sum/);
  assert.match(result.command, /command -v shasum/);
  assert.match(result.command, /RAILTIME_HASH_TOOL_UNAVAILABLE/);
  assert.match(result.command, /RAILTIME_ARTIFACT_HASH_MISMATCH/);
  assert.match(result.command, /rt_cleanup\(\) \{ rm -f -- "\$rt_path"; \}; trap rt_cleanup EXIT HUP INT TERM/);
  assert.ok(result.command.indexOf('rt_actual=') < result.command.indexOf('/bin/sh "$rt_path"'));
  assert.ok(result.command.indexOf('/bin/sh "$rt_path"') < result.command.indexOf(`'${MARKER}'`));
});

test('target hash mismatch is non-retryable and never accepted as successful execution', async () => {
  const runtime = await temporaryRuntime();
  let capturedArguments;
  const spawnImplementation = (_executable, args) => {
    capturedArguments = args;
    return completedChild('', 'RAILTIME_ARTIFACT_HASH_MISMATCH', 9);
  };
  const client = new MeshctrlClient(runtime.config, spawnImplementation);

  try {
    await assert.rejects(
      client.executeUploadedScript(
        nodeId(),
        'ps1',
        'C:\\ProgramData\\RailTime\\Staging\\approved.ps1',
        'correlation-target-mismatch',
        SHA256,
      ),
      (error) => error.code === 'artifact_target_integrity_failed'
        && error.retryable === false
        && error.statusCode === 502,
    );

    const command = capturedArguments[capturedArguments.indexOf('--run') + 1];
    assert.match(command, /RAILTIME_ARTIFACT_HASH_MISMATCH/);
    assert.match(command, /finally \{ Remove-Item -LiteralPath \$rtPath -Force -ErrorAction SilentlyContinue \}$/);
  } finally {
    await runtime.cleanup();
  }
});

test('successful execution requires the exact post-hash success marker', async () => {
  const runtime = await temporaryRuntime();
  const correlationId = 'correlation-target-success';
  let capturedArguments;
  const spawnImplementation = (_executable, args) => {
    capturedArguments = args;
    const command = args[args.indexOf('--run') + 1];
    const marker = command.match(/RAILTIME_OK_[a-f0-9]{24}/)?.[0];
    return completedChild(`${marker}\n`, '', 0);
  };
  const client = new MeshctrlClient(runtime.config, spawnImplementation);

  try {
    await client.executeUploadedScript(
      nodeId(),
      'sh',
      '/var/tmp/railtime/approved.sh',
      correlationId,
      SHA256,
    );

    const command = capturedArguments[capturedArguments.indexOf('--run') + 1];
    assert.match(command, new RegExp(`rt_expected='${SHA256}'`));
    assert.match(command, /trap rt_cleanup EXIT HUP INT TERM/);
    assert.ok(capturedArguments.includes('--reply'));
    assert.ok(!capturedArguments.includes('--powershell'));
  } finally {
    await runtime.cleanup();
  }
});

function completedChild(stdout, stderr, exitCode) {
  const child = new EventEmitter();
  child.stdout = new EventEmitter();
  child.stderr = new EventEmitter();
  child.kill = () => {};
  queueMicrotask(() => {
    if (stdout !== '') child.stdout.emit('data', Buffer.from(stdout));
    if (stderr !== '') child.stderr.emit('data', Buffer.from(stderr));
    child.emit('close', exitCode, null);
  });
  return child;
}
