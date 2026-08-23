import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { ConnectorError } from './errors.js';

export class IdempotencyStore {
  #directory;
  #locks = new Map();

  constructor(directory) {
    this.#directory = directory;
  }

  async run(kind, key, requestFingerprint, handler) {
    if (kind !== 'commands') {
      throw new Error('Unbekannte Idempotenzklasse.');
    }

    const lockKey = `${kind}:${key}`;
    return this.#withLock(lockKey, async () => {
      const statePath = this.#path(kind, key);
      const existing = await this.#read(statePath);
      if (existing) {
        if (existing.request_fingerprint !== requestFingerprint) {
          throw new ConnectorError('Der Idempotency-Key wurde bereits mit einer anderen Anfrage verwendet.', {
            statusCode: 409,
            code: 'idempotency_conflict',
          });
        }
        if (existing.status === 'completed' && existing.response !== null) {
          return existing.response;
        }

        throw new ConnectorError('Für diesen Idempotency-Key ist das Ergebnis nicht sicher wiederholbar; bitte fachlich prüfen.', {
          statusCode: 409,
          code: 'idempotency_outcome_unknown',
        });
      }

      const started = {
        schema_version: 1,
        status: 'processing',
        request_fingerprint: requestFingerprint,
        started_at: new Date().toISOString(),
        finished_at: null,
        response: null,
        failure_code: null,
      };
      await this.#write(statePath, started, true);

      try {
        const response = await handler();
        await this.#write(statePath, {
          ...started,
          status: 'completed',
          finished_at: new Date().toISOString(),
          response,
        });
        return response;
      } catch (error) {
        try {
          await this.#write(statePath, {
            ...started,
            status: 'failed',
            finished_at: new Date().toISOString(),
            failure_code: error instanceof ConnectorError ? error.code : 'connector_error',
          });
        } catch {
          // Das vorhandene processing-Journal bleibt absichtlich erhalten.
          // Dadurch wird nach einem unklaren Seiteneffekt niemals dupliziert.
        }
        throw error;
      }
    });
  }

  #path(kind, key) {
    const digest = crypto.createHash('sha256').update(String(key), 'utf8').digest('hex');
    return path.join(this.#directory, `${kind}-${digest}.json`);
  }

  async #read(filePath) {
    try {
      const stat = await fs.lstat(filePath);
      if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 131072) {
        throw new ConnectorError('Der Idempotenzstatus ist ungültig.', {
          code: 'invalid_idempotency_state',
        });
      }
      const value = JSON.parse(await fs.readFile(filePath, 'utf8'));
      if (!value || typeof value !== 'object' || value.schema_version !== 1) {
        throw new Error('invalid state');
      }
      return value;
    } catch (error) {
      if (error?.code === 'ENOENT') {
        return null;
      }
      if (error instanceof ConnectorError) {
        throw error;
      }
      throw new ConnectorError('Der Idempotenzstatus konnte nicht sicher gelesen werden.', {
        code: 'invalid_idempotency_state',
        cause: error,
      });
    }
  }

  async #write(filePath, value, exclusive = false) {
    const encoded = `${JSON.stringify(value)}\n`;
    if (Buffer.byteLength(encoded) > 131072) {
      throw new ConnectorError('Der Idempotenzstatus überschreitet das sichere Limit.', {
        code: 'idempotency_state_too_large',
      });
    }

    if (exclusive) {
      let handle;
      try {
        handle = await fs.open(filePath, 'wx', 0o600);
        await handle.writeFile(encoded, 'utf8');
        await handle.sync();
      } catch (error) {
        if (error?.code === 'EEXIST') {
          throw new ConnectorError('Der Idempotency-Key wird bereits verarbeitet.', {
            statusCode: 409,
            code: 'idempotency_in_progress',
          });
        }
        throw error;
      } finally {
        await handle?.close();
      }
      return;
    }

    const temporary = `${filePath}.${process.pid}.${crypto.randomUUID()}.tmp`;
    let handle;
    try {
      handle = await fs.open(temporary, 'wx', 0o600);
      await handle.writeFile(encoded, 'utf8');
      await handle.sync();
      await handle.close();
      handle = null;
      await fs.rename(temporary, filePath);
    } finally {
      await handle?.close();
      await fs.rm(temporary, { force: true });
    }
  }

  async #withLock(key, handler) {
    const previous = this.#locks.get(key) ?? Promise.resolve();
    let release;
    const current = new Promise((resolve) => { release = resolve; });
    this.#locks.set(key, current);
    await previous;
    try {
      return await handler();
    } finally {
      release();
      if (this.#locks.get(key) === current) {
        this.#locks.delete(key);
      }
    }
  }
}
