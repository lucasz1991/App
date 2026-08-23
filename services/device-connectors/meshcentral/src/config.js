import fs from 'node:fs';
import path from 'node:path';

const TOP_LEVEL_KEYS = new Set([
  'server',
  'auth',
  'meshcentral',
  'commands',
  'artifacts',
  'runtime',
  'state',
  'security',
]);

const SUPPORTED_PLATFORMS = new Set(['windows', 'macos', 'linux']);
const SECTION_KEYS = {
  server: new Set(['host', 'port', 'allow_non_loopback', 'request_body_bytes', 'response_body_bytes', 'request_timeout_ms']),
  auth: new Set(['bearer_token_file', 'railtime_hmac_secret_file']),
  meshcentral: new Set(['meshctrl_path', 'url', 'login_user', 'login_domain', 'login_key_file', 'platforms', 'remote_support', 'unattended_remote_support']),
  commands: new Set(['execute_script', 'collect_diagnostics']),
  artifacts: new Set(['allowed_hosts', 'allow_private_hosts', 'max_bytes', 'download_timeout_ms', 'windows_target', 'posix_target']),
  runtime: new Set(['meshctrl_timeout_ms', 'meshctrl_output_bytes']),
  state: new Set(['directory', 'temp_directory']),
  security: new Set(['require_strict_file_permissions']),
};

export function loadConfig(configPath) {
  const absoluteConfigPath = path.resolve(String(configPath || 'config.json'));
  assertRegularFile(absoluteConfigPath, 'Konfigurationsdatei');

  let raw;
  try {
    raw = JSON.parse(fs.readFileSync(absoluteConfigPath, 'utf8'));
  } catch (error) {
    throw new Error(`Die Connector-Konfiguration ist kein valides JSON: ${safeError(error)}`);
  }

  assertPlainObject(raw, 'Konfiguration');
  rejectUnknownKeys(raw, TOP_LEVEL_KEYS, 'Konfiguration');

  const baseDirectory = path.dirname(absoluteConfigPath);
  const security = objectAt(raw, 'security');
  rejectUnknownKeys(security, SECTION_KEYS.security, 'security');
  const strictPermissions = booleanAt(security, 'require_strict_file_permissions', true);
  assertSecureFilePermissions(absoluteConfigPath, strictPermissions, 'Konfigurationsdatei');

  const server = objectAt(raw, 'server');
  rejectUnknownKeys(server, SECTION_KEYS.server, 'server');
  const host = stringAt(server, 'host', '127.0.0.1', 253);
  const allowNonLoopback = booleanAt(server, 'allow_non_loopback', false);
  if (!isLoopbackHost(host) && !allowNonLoopback) {
    throw new Error('Der Connector darf ohne explizite Freigabe nur an Loopback lauschen.');
  }

  const auth = objectAt(raw, 'auth', true);
  rejectUnknownKeys(auth, SECTION_KEYS.auth, 'auth');
  const bearerTokenFile = resolveConfiguredPath(baseDirectory, stringAt(auth, 'bearer_token_file', null, 1000));
  const railtimeHmacSecretFile = resolveConfiguredPath(baseDirectory, stringAt(auth, 'railtime_hmac_secret_file', null, 1000));
  const bearerToken = readSecret(bearerTokenFile, strictPermissions, 'Bearer-Token');
  const railtimeHmacSecret = readSecret(railtimeHmacSecretFile, strictPermissions, 'RailTime-HMAC-Secret');

  const meshcentral = objectAt(raw, 'meshcentral', true);
  rejectUnknownKeys(meshcentral, SECTION_KEYS.meshcentral, 'meshcentral');
  const meshctrlPath = resolveConfiguredPath(baseDirectory, stringAt(meshcentral, 'meshctrl_path', null, 1000));
  assertRegularFile(meshctrlPath, 'meshctrl.js');
  if (path.basename(meshctrlPath).toLowerCase() !== 'meshctrl.js') {
    throw new Error('meshcentral.meshctrl_path muss auf die offizielle meshctrl.js zeigen.');
  }

  const loginKeyFile = resolveConfiguredPath(baseDirectory, stringAt(meshcentral, 'login_key_file', null, 1000));
  assertSecureFilePermissions(loginKeyFile, strictPermissions, 'MeshCentral-Loginkey');
  const loginKey = fs.readFileSync(loginKeyFile, 'utf8').replaceAll(/\s/g, '');
  if (!/^[a-fA-F0-9]{160}$/.test(loginKey)) {
    throw new Error('Der MeshCentral-Loginkey muss exakt 80 Byte als 160 Hex-Zeichen enthalten.');
  }

  const meshcentralUrl = secureUrl(stringAt(meshcentral, 'url', null, 2000), 'wss:', 'MeshCentral-URL');
  // meshctrl.js 1.2.5 setzt rejectUnauthorized:false fest. Der Adapter lässt
  // deshalb bewusst nur eine lokale Upstream-Verbindung auf demselben Host zu;
  // Browser und Geräte erreichen die getrennt abgesicherte MeshCentral-Origin.
  if (!isLoopbackHost(meshcentralUrl.hostname)) {
    throw new Error('meshcentral.url muss wegen der TLS-Eigenschaften von meshctrl.js auf Loopback zeigen.');
  }
  const loginUser = stringAt(meshcentral, 'login_user', null, 128);
  const loginDomain = stringAt(meshcentral, 'login_domain', '', 64);
  if (!/^[A-Za-z0-9_.@-]{1,128}$/.test(loginUser)) {
    throw new Error('meshcentral.login_user enthält nicht erlaubte Zeichen.');
  }
  if (loginDomain !== '' && !/^[A-Za-z0-9_.@-]{1,64}$/.test(loginDomain)) {
    throw new Error('meshcentral.login_domain enthält nicht erlaubte Zeichen.');
  }

  const platforms = arrayOfStringsAt(meshcentral, 'platforms', ['windows', 'macos', 'linux'], 3);
  if (platforms.length === 0 || platforms.some((platform) => !SUPPORTED_PLATFORMS.has(platform))) {
    throw new Error('meshcentral.platforms enthält eine nicht unterstützte Plattform.');
  }

  const commands = objectAt(raw, 'commands');
  rejectUnknownKeys(commands, SECTION_KEYS.commands, 'commands');
  const artifacts = objectAt(raw, 'artifacts', true);
  rejectUnknownKeys(artifacts, SECTION_KEYS.artifacts, 'artifacts');
  const allowedHosts = arrayOfStringsAt(artifacts, 'allowed_hosts', null, 32)
    .map((hostName) => hostName.toLowerCase());
  if (allowedHosts.length === 0 || allowedHosts.some((hostName) => !isDnsName(hostName))) {
    throw new Error('artifacts.allowed_hosts muss mindestens einen exakten DNS-Namen enthalten.');
  }

  const windowsTarget = stringAt(artifacts, 'windows_target', 'C:\\ProgramData\\RailTime\\Staging', 240);
  const posixTarget = stringAt(artifacts, 'posix_target', '/var/tmp/railtime', 240);
  if (!/^[A-Za-z]:\\[A-Za-z0-9 ._\\-]+$/.test(windowsTarget) || /["'\r\n]/.test(windowsTarget)) {
    throw new Error('artifacts.windows_target ist kein sicherer absoluter Windows-Zielordner.');
  }
  if (!/^\/[A-Za-z0-9._\/-]+$/.test(posixTarget) || posixTarget.includes('..')) {
    throw new Error('artifacts.posix_target ist kein sicherer absoluter POSIX-Zielordner.');
  }

  const state = objectAt(raw, 'state', true);
  rejectUnknownKeys(state, SECTION_KEYS.state, 'state');
  const stateDirectory = resolveConfiguredPath(baseDirectory, stringAt(state, 'directory', './state', 1000));
  const tempDirectory = resolveConfiguredPath(baseDirectory, stringAt(state, 'temp_directory', './tmp', 1000));
  preparePrivateDirectory(stateDirectory, strictPermissions, 'State-Verzeichnis');
  preparePrivateDirectory(tempDirectory, strictPermissions, 'Temp-Verzeichnis');

  const runtime = objectAt(raw, 'runtime');
  rejectUnknownKeys(runtime, SECTION_KEYS.runtime, 'runtime');

  return {
    configPath: absoluteConfigPath,
    server: {
      host,
      port: integerAt(server, 'port', 9442, 1024, 65535),
      allowNonLoopback,
      requestBodyBytes: integerAt(server, 'request_body_bytes', 32768, 1024, 1048576),
      responseBodyBytes: integerAt(server, 'response_body_bytes', 65536, 4096, 1048576),
      requestTimeoutMs: integerAt(server, 'request_timeout_ms', 45000, 1000, 120000),
    },
    auth: {
      bearerToken,
      railtimeHmacSecret,
      bearerTokenFile,
      railtimeHmacSecretFile,
    },
    meshcentral: {
      meshctrlPath,
      url: meshcentralUrl.toString().replace(/\/$/, ''),
      loginUser,
      loginDomain,
      loginKeyFile,
      platforms: [...new Set(platforms)],
      remoteSupport: booleanAt(meshcentral, 'remote_support', true),
      unattendedRemoteSupport: booleanAt(meshcentral, 'unattended_remote_support', true),
    },
    commands: {
      executeScript: booleanAt(commands, 'execute_script', true),
      collectDiagnostics: booleanAt(commands, 'collect_diagnostics', true),
    },
    artifacts: {
      allowedHosts: [...new Set(allowedHosts)],
      allowPrivateHosts: booleanAt(artifacts, 'allow_private_hosts', false),
      maxBytes: integerAt(artifacts, 'max_bytes', 104857600, 1, 1073741824),
      downloadTimeoutMs: integerAt(artifacts, 'download_timeout_ms', 20000, 1000, 120000),
      windowsTarget: windowsTarget.replace(/\\$/, ''),
      posixTarget: posixTarget.replace(/\/$/, ''),
    },
    runtime: {
      meshctrlTimeoutMs: integerAt(runtime, 'meshctrl_timeout_ms', 30000, 1000, 120000),
      meshctrlOutputBytes: integerAt(runtime, 'meshctrl_output_bytes', 65536, 4096, 1048576),
    },
    state: {
      directory: stateDirectory,
      tempDirectory,
    },
    security: {
      strictPermissions,
    },
  };
}

export function isMeshIdentifier(value, prefix = 'node') {
  if (typeof value !== 'string' || value.length < 20 || value.length > 191 || value.includes(',')) {
    return false;
  }

  const escapedPrefix = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const full = new RegExp(`^${escapedPrefix}/[A-Za-z0-9_.@-]{0,64}/[A-Za-z0-9_@$+=.-]{20,160}$`);
  const short = /^[A-Za-z0-9_@$+=.]{20,160}$/;

  return full.test(value) || short.test(value);
}

function objectAt(object, key, required = false) {
  const value = object[key];
  if (value === undefined && !required) {
    return {};
  }
  assertPlainObject(value, key);

  return value;
}

function stringAt(object, key, fallback, maximumLength) {
  const value = object[key] ?? fallback;
  if (typeof value !== 'string' || value.length > maximumLength || (fallback === null && value.trim() === '')) {
    throw new Error(`${key} ist kein gültiger Textwert.`);
  }

  return value.trim();
}

function booleanAt(object, key, fallback) {
  const value = object[key] ?? fallback;
  if (typeof value !== 'boolean') {
    throw new Error(`${key} muss true oder false sein.`);
  }

  return value;
}

function integerAt(object, key, fallback, minimum, maximum) {
  const value = object[key] ?? fallback;
  if (!Number.isInteger(value) || value < minimum || value > maximum) {
    throw new Error(`${key} muss zwischen ${minimum} und ${maximum} liegen.`);
  }

  return value;
}

function arrayOfStringsAt(object, key, fallback, maximumItems) {
  const value = object[key] ?? fallback;
  if (!Array.isArray(value) || value.length > maximumItems || value.some((item) => typeof item !== 'string' || item === '')) {
    throw new Error(`${key} muss eine begrenzte Liste nichtleerer Texte sein.`);
  }

  return value.map((item) => item.trim());
}

function assertPlainObject(value, label) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`${label} muss ein JSON-Objekt sein.`);
  }
}

function rejectUnknownKeys(object, allowed, label) {
  for (const key of Object.keys(object)) {
    if (!allowed.has(key)) {
      throw new Error(`${label} enthält den unbekannten Schlüssel ${key}.`);
    }
  }
}

function resolveConfiguredPath(baseDirectory, configuredPath) {
  return path.isAbsolute(configuredPath)
    ? path.normalize(configuredPath)
    : path.resolve(baseDirectory, configuredPath);
}

function readSecret(filePath, strictPermissions, label) {
  assertSecureFilePermissions(filePath, strictPermissions, label);
  const value = fs.readFileSync(filePath, 'utf8').trim();
  if (value.length < 32 || value.length > 512 || /[\r\n\0]/.test(value)) {
    throw new Error(`${label} muss zwischen 32 und 512 Zeichen lang und einzeilig sein.`);
  }

  return value;
}

function assertSecureFilePermissions(filePath, strictPermissions, label) {
  assertRegularFile(filePath, label);
  if (!strictPermissions || process.platform === 'win32') {
    return;
  }

  const mode = fs.statSync(filePath).mode & 0o777;
  if ((mode & 0o077) !== 0) {
    throw new Error(`${label} darf für Gruppe und andere Benutzer keine Rechte besitzen (erwartet 0600/0400).`);
  }
}

function assertRegularFile(filePath, label) {
  let stat;
  try {
    stat = fs.lstatSync(filePath);
  } catch {
    throw new Error(`${label} wurde nicht gefunden.`);
  }
  if (!stat.isFile() || stat.isSymbolicLink()) {
    throw new Error(`${label} muss eine reguläre Datei und darf kein Symlink sein.`);
  }
}

function preparePrivateDirectory(directory, strictPermissions, label) {
  fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
  const stat = fs.lstatSync(directory);
  if (!stat.isDirectory() || stat.isSymbolicLink()) {
    throw new Error(`${label} muss ein echtes Verzeichnis und darf kein Symlink sein.`);
  }
  if (strictPermissions && process.platform !== 'win32' && ((stat.mode & 0o077) !== 0)) {
    throw new Error(`${label} darf für Gruppe und andere Benutzer keine Rechte besitzen (erwartet 0700).`);
  }
  fs.accessSync(directory, fs.constants.R_OK | fs.constants.W_OK | fs.constants.X_OK);
}

function secureUrl(value, protocol, label) {
  let parsed;
  try {
    parsed = new URL(value);
  } catch {
    throw new Error(`${label} ist keine gültige URL.`);
  }
  if (parsed.protocol !== protocol || parsed.username !== '' || parsed.password !== '' || parsed.hash !== '') {
    throw new Error(`${label} muss ${protocol}// ohne Zugangsdaten oder Fragment verwenden.`);
  }
  if (parsed.search !== '') {
    throw new Error(`${label} darf keine Query und damit keine URL-Secrets enthalten.`);
  }

  return parsed;
}

function isDnsName(hostName) {
  return typeof hostName === 'string'
    && hostName.length <= 253
    && hostName.includes('.')
    && hostName.split('.').every((label) => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i.test(label));
}

function isLoopbackHost(host) {
  const normalized = host.toLowerCase();
  return normalized === '127.0.0.1'
    || normalized === '::1'
    || normalized === '[::1]'
    || normalized === 'localhost';
}

function safeError(error) {
  return error instanceof Error ? error.message : 'unbekannter Fehler';
}
