import { pathToFileURL } from 'node:url';
import { loadConfig } from './config.js';
import { ConnectorService } from './connector-service.js';
import { createConnectorServer } from './http-server.js';
import { MeshctrlClient } from './meshctrl.js';
import { safeLog } from './security.js';
import { IdempotencyStore } from './state-store.js';

export function buildRuntime(config, logger = console) {
  const meshctrl = new MeshctrlClient(config);
  const store = new IdempotencyStore(config.state.directory);
  const service = new ConnectorService(config, meshctrl, store);
  const server = createConnectorServer(config, service, logger);
  return { server, service, meshctrl, store };
}

export async function start(configPath, logger = console) {
  const config = loadConfig(configPath);
  const { server } = buildRuntime(config, logger);

  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(config.server.port, config.server.host, resolve);
  });
  safeLog(logger, 'info', 'connector.started', {
    host: config.server.host,
    port: config.server.port,
    connector_version: '1.0.0',
    provider: 'meshcentral',
  });

  let closing = false;
  const shutdown = (signal) => {
    if (closing) return;
    closing = true;
    safeLog(logger, 'info', 'connector.stopping', { signal });
    server.close((error) => {
      if (error) {
        safeLog(logger, 'error', 'connector.stop_failed', { error_code: 'server_close_failed' });
        process.exitCode = 1;
      }
    });
    setTimeout(() => {
      server.closeAllConnections?.();
      process.exitCode = 1;
    }, 10000).unref();
  };
  process.once('SIGTERM', () => shutdown('SIGTERM'));
  process.once('SIGINT', () => shutdown('SIGINT'));

  return server;
}

function configArgument(argv) {
  if (argv.length !== 2 || argv[0] !== '--config' || argv[1].trim() === '') {
    throw new Error('Startsyntax: node src/server.js --config /absoluter/pfad/config.json');
  }
  return argv[1];
}

const invokedDirectly = process.argv[1]
  && import.meta.url === pathToFileURL(process.argv[1]).href;
if (invokedDirectly) {
  try {
    await start(configArgument(process.argv.slice(2)));
  } catch (error) {
    safeLog(console, 'error', 'connector.start_failed', {
      error_code: 'startup_failed',
      reason: error instanceof Error ? error.message : 'unknown',
    });
    process.exitCode = 1;
  }
}
