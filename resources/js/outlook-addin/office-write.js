import { recordDiagnostic } from './diagnostics.js';

const uncertainWrites = new WeakMap();

export function hasUncertainWrite(item) {
    return Boolean(item && uncertainWrites.get(item)?.size);
}

/** One Office mutation, never retried. A late callback clears only its own lock. */
export function confirmedOfficeWrite(office, item, phase, uncertainCode, invoke, options = {}) {
    return new Promise((resolve, reject) => {
        const token = {};
        const start = Date.now();
        let callbackReceived = false;
        let timedOut = false;
        const block = () => {
            const tokens = uncertainWrites.get(item) || new Set();
            tokens.add(token);
            uncertainWrites.set(item, tokens);
        };
        const clear = () => {
            const tokens = uncertainWrites.get(item);
            tokens?.delete(token);
            if (tokens?.size === 0) uncertainWrites.delete(item);
        };
        const failure = (reason, hostError) => {
            const error = Object.assign(new Error(uncertainCode), {
                code: uncertainCode, phase, reason,
                officeCode: String(hostError?.code || hostError?.name || ''),
            });
            recordDiagnostic(phase, 'uncertain', error, Date.now() - start);
            reject(error);
        };
        const timeout = setTimeout(() => {
            timedOut = true;
            block();
            failure('timeout');
        }, options.timeoutMs ?? 30000);
        recordDiagnostic(phase, 'started');
        try {
            invoke((result) => {
                if (callbackReceived) return;
                callbackReceived = true;
                clearTimeout(timeout);
                clear();
                if (result?.status === office.AsyncResultStatus.Succeeded) {
                    recordDiagnostic(phase, timedOut ? 'late-success' : 'succeeded', null, Date.now() - start);
                    resolve(result.value);
                } else {
                    const error = Object.assign(new Error('OFFICE_WRITE_FAILED'), {
                        code: 'OFFICE_WRITE_FAILED', phase, reason: 'callback',
                        officeCode: String(result?.error?.code || ''),
                    });
                    recordDiagnostic(phase, 'failed', error, Date.now() - start);
                    reject(error);
                }
                options.onSettled?.();
            });
        } catch (error) {
            clearTimeout(timeout);
            if (callbackReceived) return;
            // A synchronous bridge exception is not a timeout and is not proof
            // that the host did not receive the write. Keep its lock, no retry.
            block();
            failure('exception', error);
        }
    });
}
