import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

export async function temporaryRuntime() {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'railtime-meshcentral-test-'));
  const stateDirectory = path.join(root, 'state');
  const tempDirectory = path.join(root, 'tmp');
  const meshctrlPath = path.join(root, 'meshctrl.js');
  const callsPath = path.join(root, 'meshctrl-calls.jsonl');
  await fs.mkdir(stateDirectory, { mode: 0o700 });
  await fs.mkdir(tempDirectory, { mode: 0o700 });
  await fs.writeFile(meshctrlPath, fakeMeshctrlSource(callsPath), { mode: 0o700 });

  const config = {
    server: {
      host: '127.0.0.1',
      port: 9442,
      requestBodyBytes: 32768,
      responseBodyBytes: 65536,
      requestTimeoutMs: 45000,
    },
    auth: {
      bearerToken: 'test-bearer-token-at-least-thirty-two-characters',
      railtimeHmacSecret: 'test-hmac-secret-at-least-thirty-two-characters',
    },
    meshcentral: {
      meshctrlPath,
      url: 'wss://127.0.0.1:8443',
      loginUser: 'railtime-connector',
      loginDomain: '',
      loginKeyFile: path.join(root, 'meshcentral-login-key'),
      platforms: ['windows', 'macos', 'linux'],
      remoteSupport: true,
      unattendedRemoteSupport: true,
    },
    commands: {
      executeScript: true,
      collectDiagnostics: true,
    },
    artifacts: {
      allowedHosts: ['app.example.com'],
      allowPrivateHosts: false,
      maxBytes: 1024 * 1024,
      downloadTimeoutMs: 5000,
      windowsTarget: 'C:\\ProgramData\\RailTime\\Staging',
      posixTarget: '/var/tmp/railtime',
    },
    runtime: {
      meshctrlTimeoutMs: 5000,
      meshctrlOutputBytes: 65536,
    },
    state: { directory: stateDirectory, tempDirectory },
  };
  await fs.writeFile(config.meshcentral.loginKeyFile, `${'ab'.repeat(80)}\n`, { mode: 0o600 });

  return {
    root,
    config,
    callsPath,
    async calls() {
      try {
        return (await fs.readFile(callsPath, 'utf8')).trim().split(/\r?\n/).filter(Boolean).map(JSON.parse);
      } catch (error) {
        if (error?.code === 'ENOENT') return [];
        throw error;
      }
    },
    async cleanup() {
      await fs.rm(root, { recursive: true, force: true });
    },
  };
}

export function nodeId() {
  return `node//${'B'.repeat(64)}`;
}

function fakeMeshctrlSource(callsPath) {
  return `import fs from 'node:fs';
const args = process.argv.slice(2);
fs.appendFileSync(${JSON.stringify(callsPath)}, JSON.stringify(args) + '\\n');
if (args.includes('--loginpass')) { console.error('password argument forbidden'); process.exit(9); }
if (!args.includes('--loginkeyfile')) { console.error('missing loginkey file'); process.exit(8); }
const action = args[0];
const value = (name) => args[args.indexOf(name) + 1];
switch (action) {
  case 'ServerInfo': console.log(JSON.stringify({ domain: '', serverVersion: '1.2.5' })); break;
  case 'DeviceInfo': console.log(JSON.stringify({ General: { 'Computer Name': 'LAB-01' }, 'Mesh Agent': { 'Agent status': 'Connected now' } })); break;
  case 'Upload': {
    const size = fs.statSync(value('--file')).size;
    console.log('Connected.'); console.log('Uploading...'); console.log('Upload completed, ' + size + ' bytes sent.'); break;
  }
  case 'RunCommand': {
    const match = value('--run').match(/RAILTIME_OK_[a-f0-9]{24}/);
    if (!match) { console.error('missing marker'); process.exit(7); }
    console.log(match[0]); break;
  }
  default: console.error('unexpected action ' + action); process.exit(6);
}
`;
}
