import crypto from 'node:crypto';
import { spawn } from 'node:child_process';
import path from 'node:path';
import { ConnectorError } from './errors.js';

const FAILURE_PATTERNS = [
  /invalid login/i,
  /authentication token required/i,
  /unable to (?:resolve|connect)/i,
  /agent not connected/i,
  /invalid nodeid/i,
  /invalid domain/i,
  /access denied/i,
  /permission denied/i,
  /unable to route/i,
  /node not found/i,
  /no power operation specified/i,
  /upload (?:canceled|error)/i,
];

export class MeshctrlClient {
  #config;
  #spawn;

  constructor(config, spawnImplementation = spawn) {
    this.#config = config;
    this.#spawn = spawnImplementation;
  }

  async serverInfo() {
    const result = await this.run('ServerInfo', ['--json']);
    return parseJsonOutput(result.stdout, 'ServerInfo');
  }

  async diagnostics(providerDeviceId) {
    const result = await this.run('DeviceInfo', [
      '--id', providerDeviceId,
      '--json',
    ]);
    const data = parseJsonOutput(result.stdout, 'DeviceInfo');
    const encoded = JSON.stringify(data);
    const general = data?.General;
    const meshAgent = data?.['Mesh Agent'];

    return {
      diagnostics_sha256: crypto.createHash('sha256').update(encoded, 'utf8').digest('hex'),
      output_bytes: Buffer.byteLength(encoded),
      device_name: scalarString(general?.['Computer Name'] ?? general?.['Server Name'] ?? null, 191),
      agent_status: scalarString(meshAgent?.['Agent status'] ?? null, 80),
    };
  }

  async upload(providerDeviceId, localFile, remoteDirectory, expectedSize) {
    const result = await this.run('Upload', [
      '--id', providerDeviceId,
      '--file', localFile,
      '--target', remoteDirectory,
    ]);
    const match = result.stdout.match(/Upload completed,\s*(\d+)\s*bytes sent\./i);
    if (!match || Number.parseInt(match[1], 10) !== expectedSize) {
      throw cliFailure('MeshCentral hat den vollständigen Upload nicht belegt.', result.stdout);
    }
  }

  async executeUploadedScript(providerDeviceId, extension, remotePath, correlationId, expectedSha256) {
    const marker = `RAILTIME_OK_${crypto.createHash('sha256').update(correlationId, 'utf8').digest('hex').slice(0, 24)}`;
    const { command, powershell } = fixedScriptCommand(extension, remotePath, marker, expectedSha256);
    const args = ['--id', providerDeviceId, '--run', command, '--reply'];
    if (powershell) {
      args.push('--powershell');
    }
    const result = await this.run('RunCommand', args);
    if (hasTargetIntegrityFailure(result)) {
      throw targetIntegrityFailure();
    }
    if (!result.stdout.split(/\r?\n/).some((line) => line.trim() === marker)) {
      throw cliFailure('Das Geräteskript hat keinen belegten Erfolgsmarker zurückgegeben.', result.stdout);
    }
  }

  run(action, actionArguments = []) {
    const commonArguments = [
      '--url', this.#config.meshcentral.url,
      '--loginuser', this.#config.meshcentral.loginUser,
      '--loginkeyfile', this.#config.meshcentral.loginKeyFile,
    ];
    if (this.#config.meshcentral.loginDomain !== '') {
      commonArguments.push('--logindomain', this.#config.meshcentral.loginDomain);
    }

    const argumentsList = [
      this.#config.meshcentral.meshctrlPath,
      action,
      ...actionArguments,
      ...commonArguments,
    ];
    return spawnBounded(
      this.#spawn,
      process.execPath,
      argumentsList,
      this.#config.runtime.meshctrlTimeoutMs,
      this.#config.runtime.meshctrlOutputBytes,
    );
  }
}

export function fixedScriptCommand(extension, remotePath, marker, expectedSha256) {
  if (!/^[A-Za-z0-9_./:\\ -]+$/.test(remotePath) || /["'\r\n;&|`$<>]/.test(remotePath)) {
    throw new ConnectorError('Der vorbereitete Remote-Pfad ist nicht sicher.', {
      statusCode: 500,
      code: 'unsafe_remote_path',
    });
  }
  if (!/^RAILTIME_OK_[a-f0-9]{24}$/.test(marker)) {
    throw new Error('Ungültiger Erfolgsmarker.');
  }
  if (!/^[a-f0-9]{64}$/.test(expectedSha256)) {
    throw new Error('Ungültige erwartete SHA-256-Prüfsumme.');
  }

  const windowsPrefix = `$rtPath = '${remotePath}'; $rtExpected = '${expectedSha256}'; try { $rtActual = (Get-FileHash -Algorithm SHA256 -LiteralPath $rtPath -ErrorAction Stop).Hash.ToLowerInvariant(); if (-not [string]::Equals($rtActual, $rtExpected, [StringComparison]::OrdinalIgnoreCase)) { throw 'RAILTIME_ARTIFACT_HASH_MISMATCH' };`;
  const windowsSuffix = `if (-not $?) { throw 'RAILTIME_SCRIPT_FAILED' }; Write-Output '${marker}' } finally { Remove-Item -LiteralPath $rtPath -Force -ErrorAction SilentlyContinue }`;

  switch (extension) {
    case 'ps1':
      return {
        powershell: true,
        command: `${windowsPrefix} & $rtPath; ${windowsSuffix}`,
      };
    case 'bat':
    case 'cmd':
      return {
        powershell: true,
        command: `${windowsPrefix} & $rtPath; if ($LASTEXITCODE -ne 0) { throw 'RAILTIME_SCRIPT_FAILED' }; Write-Output '${marker}' } finally { Remove-Item -LiteralPath $rtPath -Force -ErrorAction SilentlyContinue }`,
      };
    case 'sh':
      return {
        powershell: false,
        command: `rt_path='${remotePath}'; rt_expected='${expectedSha256}'; rt_cleanup() { rm -f -- "$rt_path"; }; trap rt_cleanup EXIT HUP INT TERM; if command -v sha256sum >/dev/null 2>&1; then rt_actual=$(sha256sum -- "$rt_path") || exit 1; rt_actual=${'${rt_actual%% *}'}; elif command -v shasum >/dev/null 2>&1; then rt_actual=$(shasum -a 256 -- "$rt_path") || exit 1; rt_actual=${'${rt_actual%% *}'}; else printf '%s\\n' 'RAILTIME_HASH_TOOL_UNAVAILABLE' >&2; exit 1; fi; if [ "$rt_actual" != "$rt_expected" ]; then printf '%s\\n' 'RAILTIME_ARTIFACT_HASH_MISMATCH' >&2; exit 42; fi; /bin/sh "$rt_path"; rt_status=$?; if [ "$rt_status" -eq 0 ]; then printf '%s\\n' '${marker}'; fi; exit "$rt_status"`,
      };
    default:
      throw new ConnectorError('Nur freigegebene PS1-, BAT-, CMD- oder SH-Skripte dürfen ausgeführt werden.', {
        statusCode: 422,
        code: 'unsupported_script_type',
      });
  }
}

function spawnBounded(spawnImplementation, executable, args, timeoutMs, outputLimit) {
  return new Promise((resolve, reject) => {
    const child = spawnImplementation(executable, args, {
      shell: false,
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    const stdout = [];
    const stderr = [];
    let outputBytes = 0;
    let settled = false;
    let killedForLimit = false;

    const timer = setTimeout(() => {
      if (!settled) {
        child.kill('SIGKILL');
      }
    }, timeoutMs);
    timer.unref?.();

    const capture = (target) => (chunk) => {
      outputBytes += chunk.length;
      if (outputBytes > outputLimit) {
        killedForLimit = true;
        child.kill('SIGKILL');
        return;
      }
      target.push(Buffer.from(chunk));
    };
    child.stdout.on('data', capture(stdout));
    child.stderr.on('data', capture(stderr));

    child.on('error', (error) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      reject(new ConnectorError('meshctrl.js konnte nicht gestartet werden.', {
        statusCode: 502,
        code: 'meshctrl_spawn_failed',
        retryable: true,
        cause: error,
      }));
    });

    child.on('close', (code, signal) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      const stdoutText = Buffer.concat(stdout).toString('utf8').trim();
      const stderrText = Buffer.concat(stderr).toString('utf8').trim();
      if (killedForLimit) {
        reject(new ConnectorError('meshctrl.js hat das zulässige Ausgabelimit überschritten.', {
          statusCode: 502,
          code: 'meshctrl_output_limit',
        }));
        return;
      }
      if (signal === 'SIGKILL') {
        reject(new ConnectorError('meshctrl.js hat das Zeitlimit überschritten.', {
          statusCode: 504,
          code: 'meshctrl_timeout',
          retryable: true,
        }));
        return;
      }
      if (code !== 0) {
        reject(cliFailure('meshctrl.js ist mit einem Fehlerstatus beendet worden.', `${stdoutText}\n${stderrText}`));
        return;
      }
      const combined = `${stdoutText}\n${stderrText}`;
      const failure = FAILURE_PATTERNS.find((pattern) => pattern.test(combined));
      if (failure) {
        reject(cliFailure('MeshCentral hat die Operation abgelehnt oder konnte sie nicht zustellen.', combined));
        return;
      }
      resolve({ stdout: stdoutText, stderr: stderrText });
    });
  });
}

function parseJsonOutput(output, operation) {
  const start = output.indexOf('{');
  const end = output.lastIndexOf('}');
  if (start < 0 || end < start) {
    throw cliFailure(`MeshCentral ${operation} hat kein JSON geliefert.`, output);
  }
  try {
    const parsed = JSON.parse(output.slice(start, end + 1));
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new Error('not object');
    }
    return parsed;
  } catch (error) {
    throw new ConnectorError(`MeshCentral ${operation} hat ungültiges JSON geliefert.`, {
      statusCode: 502,
      code: 'meshctrl_invalid_json',
      cause: error,
    });
  }
}

function cliFailure(message, output) {
  if (/RAILTIME_ARTIFACT_HASH_MISMATCH/i.test(output)) {
    return targetIntegrityFailure();
  }
  const authFailure = /invalid login|authentication token required/i.test(output);
  return new ConnectorError(message, {
    statusCode: 502,
    code: authFailure ? 'meshcentral_authentication_failed' : 'meshcentral_operation_failed',
    retryable: !authFailure,
  });
}

function hasTargetIntegrityFailure(result) {
  return /RAILTIME_ARTIFACT_HASH_MISMATCH/i.test(`${result.stdout}\n${result.stderr}`);
}

function targetIntegrityFailure() {
  return new ConnectorError('Die SHA-256-Prüfung auf dem Zielgerät ist fehlgeschlagen; das Artefakt wurde nicht ausgeführt.', {
    statusCode: 502,
    code: 'artifact_target_integrity_failed',
    retryable: false,
  });
}

function scalarString(value, maximumLength) {
  if (typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'boolean') {
    return null;
  }
  return String(value).slice(0, maximumLength);
}

export function remotePath(directory, fileName, extension) {
  if (extension === 'sh') {
    return `${directory}/${path.posix.basename(fileName)}`;
  }
  return `${directory}\\${path.win32.basename(fileName)}`;
}
