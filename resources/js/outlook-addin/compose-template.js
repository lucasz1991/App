// Shared by the event runtime and taskpane. Never replace a compose body:
// Outlook may include the complete quoted conversation in body.getAsync().
export const TEMPLATE_MARKER = 'RT-TEMPLATE-MANAGED-V1';
const TEMPLATE_SESSION_KEY = 'railtime.template.inserted.v1';
const appliedItems = new WeakSet();
const insertionOperations = new WeakMap();
export const TEMPLATE_INSERT_LIMITS = Object.freeze({
    htmlLength: 100000,
    bodyLength: 500000,
    images: 20,
    mediaBytes: 2 * 1024 * 1024,
    readTimeoutMs: 10000,
    writeTimeoutMs: 30000,
});

function failure(code, details = {}) {
    const error = new Error(code);
    error.code = code;
    for (const name of ['phase', 'officeCode', 'reason']) {
        if (typeof details[name] === 'string' && details[name] !== '') error[name] = details[name];
    }
    return error;
}

function safeOfficeCode(error) {
    try {
        const value = String(error?.code ?? error?.name ?? '');
        return /^[A-Za-z0-9_.:-]{1,80}$/.test(value) ? value : '';
    } catch {
        return '';
    }
}

function failedOfficeResult(reason, error = null) {
    // Host messages can contain mailbox/content details. Retain codes only.
    return { succeeded: false, reason, officeCode: safeOfficeCode(error) };
}

export function currentComposeBodyHtml(html, composeType) {
    if (typeof html !== 'string') return null;
    if (composeType === 'newMail') return html;
    if (!['reply', 'forward'].includes(composeType)) return null;

    const boundaries = [
        /<[^>]+\bid\s*=\s*["'](?:x_)?divrplyfwdmsg["'][^>]*>/ig,
        /<[^>]+\bid\s*=\s*["']mail-editor-reference-message-container["'][^>]*>/ig,
        /<[^>]+\bclass\s*=\s*["'][^"']*(?:gmail_quote|yahoo_quoted|moz-cite-prefix)[^"']*["'][^>]*>/ig,
        /<blockquote\b[^>]*(?:\btype\s*=\s*["']cite["']|\bcite\s*=)[^>]*>/ig,
        /<hr\b[^>]*\bid\s*=\s*["']stopspelling["'][^>]*>/ig,
        /<!--\s*(?:original message|urspruengliche nachricht)\s*-->/ig,
    ];
    let boundary = -1;
    boundaries.forEach((pattern) => {
        const match = pattern.exec(html);
        if (match && (boundary === -1 || match.index < boundary)) boundary = match.index;
    });
    return boundary === -1 ? null : html.slice(0, boundary);
}

export function supportsTemplatePrepend(office, item) {
    const platform = String(office?.context?.platform || '').toLowerCase();
    const host = String(office?.context?.mailbox?.diagnostics?.hostName || '').toLowerCase();
    return !['ios', 'android'].includes(platform)
        && !/android|ios/.test(host)
        && typeof item?.body?.prependAsync === 'function'
        && typeof item?.body?.getTypeAsync === 'function';
}

function officeResult(office, invoke) {
    return new Promise((resolve) => {
        let settled = false;
        const finish = (value) => {
            if (settled) return;
            settled = true;
            clearTimeout(timeout);
            resolve(value);
        };
        const timeout = setTimeout(() => finish(failedOfficeResult('timeout')), TEMPLATE_INSERT_LIMITS.readTimeoutMs);
        try {
            invoke((result) => {
                try {
                    finish(result?.status === office?.AsyncResultStatus?.Succeeded
                        ? { succeeded: true, value: result.value } : failedOfficeResult('failed', result?.error));
                } catch (error) {
                    finish(failedOfficeResult('exception', error));
                }
            });
        } catch (error) {
            finish(failedOfficeResult('exception', error));
        }
    });
}

async function officeRead(office, invoke) {
    const result = await officeResult(office, invoke);
    return result.succeeded ? result.value : null;
}

function pendingSession(value) {
    return typeof value === 'string' && /^(?:pending|uncertain):/.test(value);
}

function sessionAccess(office, item) {
    // Function stubs can exist on Mailbox 1.10 hosts, although SessionData
    // requires 1.11. An explicit unsupported result must bypass those stubs.
    // JavaScript-only runtimes may lack the requirement checker altogether;
    // existing APIs are then probed conservatively, never assumed successful.
    try {
        const requirements = office?.context?.requirements;
        if (typeof requirements?.isSetSupported === 'function'
            && requirements.isSetSupported('Mailbox', '1.11') === false) {
            return { supported: false };
        }
        return { supported: typeof item?.sessionData?.getAsync === 'function' };
    } catch (error) {
        return { ...failedOfficeResult('exception', error), phase: 'session-capability' };
    }
}

function sessionReadError(result, phase = 'session-read') {
    return failure('COMPOSE_SESSION_UNREADABLE', { ...result, phase });
}

function readSession(office, item) {
    return officeResult(office, (callback) => item.sessionData.getAsync(TEMPLATE_SESSION_KEY, callback));
}

function writeSession(office, item, value) {
    return officeResult(office, (callback) => item.sessionData.setAsync(TEMPLATE_SESSION_KEY, value, callback));
}

function newOperationToken() {
    try {
        if (typeof globalThis.crypto?.randomUUID === 'function') return `pending:${globalThis.crypto.randomUUID()}`;
    } catch {
        // This is a non-secret ownership label, never an authorization token.
    }
    return `pending:${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

async function claimSession(office, item, operation) {
    const access = sessionAccess(office, item);
    if (access.succeeded === false) throw sessionReadError(access, access.phase);
    if (!access.supported || typeof item.sessionData?.setAsync !== 'function') return;

    // Outlook runtimes may be isolated. SessionData narrows reopen/concurrent
    // races where available, but read/set/readback is NOT an atomic CAS lock.
    const existing = await readSession(office, item);
    if (!existing.succeeded) {
        // No session/media/body write has started. This is retryable reading,
        // not evidence that an insertion may already have happened.
        throw sessionReadError(existing);
    }
    if (existing.value === '1' && !operation.additionalConfirmed) throw failure('TEMPLATE_ALREADY_INSERTED');
    if (pendingSession(existing.value) || ![null, undefined, '', '1'].includes(existing.value)) {
        throw failure('TEMPLATE_INSERT_UNCERTAIN', { phase: 'session-read', reason: 'pending' });
    }
    operation.previousSessionValue = existing.value === '1' ? '1' : '';
    operation.sessionToken = newOperationToken();
    await operation.beforeWrite();
    const stored = await writeSession(office, item, operation.sessionToken);
    if (!stored.succeeded) {
        operation.phase = 'uncertain';
        throw failure('TEMPLATE_INSERT_UNCERTAIN', { ...stored, phase: 'session-write' });
    }
    operation.sessionClaimed = true;
    const verified = await readSession(office, item);
    if (!verified.succeeded || verified.value !== operation.sessionToken) {
        operation.phase = 'uncertain';
        throw failure('TEMPLATE_INSERT_UNCERTAIN', {
            ...verified, phase: 'session-readback', reason: verified.succeeded ? 'mismatch' : verified.reason,
        });
    }
}

async function releaseDefiniteSessionFailure(office, item, operation) {
    if (!operation.sessionClaimed || operation.phase === 'uncertain' || operation.phase === 'applied') return;
    const current = await readSession(office, item);
    if (!current.succeeded || current.value !== operation.sessionToken) {
        // Never erase a foreign, completed or unreadable claim.
        operation.phase = 'uncertain';
        return;
    }
    // A failed additional insertion must keep the original template's state.
    try {
        await operation.beforeWrite();
    } catch {
        operation.phase = 'uncertain';
        return;
    }
    const cleared = await writeSession(office, item, operation.previousSessionValue);
    if (!cleared.succeeded) operation.phase = 'uncertain';
}

export function isTemplateInsertionBlocked(item) {
    // Completed templates have their own marker state, not an uncertainty warning.
    return insertionOperations.has(item);
}

export function templateStateFromBody(html, composeType) {
    if (typeof html !== 'string') {
        return { present: false, readable: false, bodyLength: null, tooLarge: false };
    }
    const bodyLength = html.length;
    if (bodyLength > TEMPLATE_INSERT_LIMITS.bodyLength) {
        return { present: false, readable: true, bodyLength, tooLarge: true };
    }
    const current = currentComposeBodyHtml(html, composeType);
    // A marker from a quoted older mail is ignored only with a known boundary.
    // Ambiguous bodies stay conservative instead of duplicating a template.
    return {
        present: (current === null ? html : current).includes(TEMPLATE_MARKER),
        readable: true,
        bodyLength,
        tooLarge: false,
    };
}

export async function readTemplateState(office, item, { forceBody = false } = {}) {
    const unknownSize = { bodyLength: null, tooLarge: false };
    if (insertionOperations.get(item)?.phase === 'uncertain') {
        return { present: false, readable: false, uncertain: true, ...unknownSize };
    }
    let knownPresent = appliedItems.has(item);
    const access = sessionAccess(office, item);
    if (access.succeeded === false) {
        return { present: false, readable: false, uncertain: false, ...unknownSize,
            errorCode: 'COMPOSE_SESSION_UNREADABLE', phase: access.phase, reason: access.reason, officeCode: access.officeCode };
    }
    if (access.supported) {
        const session = await readSession(office, item);
        if (!session.succeeded) {
            return { present: false, readable: false, uncertain: false, ...unknownSize,
                errorCode: 'COMPOSE_SESSION_UNREADABLE', phase: 'session-read', reason: session.reason, officeCode: session.officeCode };
        }
        if (pendingSession(session.value) || ![null, undefined, '', '1'].includes(session.value)) {
            // Pending/unknown claims never expire automatically. A reopened
            // taskpane must not assume that the previous native write failed.
            return { present: false, readable: false, uncertain: true, ...unknownSize,
                phase: 'session-read', reason: 'pending' };
        }
        knownPresent ||= session.value === '1';
    }
    if (knownPresent && !forceBody) return { present: true, readable: true, ...unknownSize };

    const [html, compose] = await Promise.all([
        officeRead(office, (callback) => item.body.getAsync(office.CoercionType.Html, callback)),
        officeRead(office, (callback) => item.getComposeTypeAsync(callback)),
    ]);
    const state = templateStateFromBody(html, compose?.composeType);
    return { ...state, present: knownPresent || state.present };
}

export function automaticTemplate(payload) {
    const id = typeof payload?.automaticTemplateId === 'string' ? payload.automaticTemplateId : '';
    if (id === '' || !Array.isArray(payload?.templates)) return null;
    const matches = payload.templates.filter((entry) => entry?.isDefault === true
        && (entry.id === id || entry.key === id)
        && typeof entry.html === 'string' && entry.html.trim() !== '');
    return matches.length === 1 ? matches[0] : null;
}

export function markedTemplateHtml(html) {
    if (typeof html !== 'string' || html.trim() === '') throw failure('TEMPLATE_HTML_MISSING');
    if (html.includes(TEMPLATE_MARKER)) return html;
    return `<!--${TEMPLATE_MARKER}-->${html}<span id="${TEMPLATE_MARKER}" aria-hidden="true" style="display:none;mso-hide:all"></span>`;
}

export async function assertTemplateInsertable(office, item, { allowAdditional = false } = {}) {
    if (!supportsTemplatePrepend(office, item)) throw failure('TEMPLATE_PREPEND_UNAVAILABLE');
    const state = await readTemplateState(office, item, { forceBody: allowAdditional });
    if (state.errorCode) throw failure(state.errorCode, state);
    if (state.uncertain) throw failure('TEMPLATE_INSERT_UNCERTAIN', state);
    if (state.tooLarge) throw failure('COMPOSE_BODY_TOO_LARGE');
    if (state.present && !allowAdditional) throw failure('TEMPLATE_ALREADY_INSERTED');
    if (!state.readable) throw failure('COMPOSE_BODY_UNREADABLE');
    const format = await officeRead(office, (callback) => item.body.getTypeAsync(callback));
    if (format !== office.CoercionType.Html) throw failure('TEMPLATE_REQUIRES_HTML');
    return state;
}

function validatedInsertionHtml(html, media) {
    if (typeof html === 'string' && html.length > TEMPLATE_INSERT_LIMITS.htmlLength) {
        throw failure('TEMPLATE_TOO_LARGE');
    }
    const markedHtml = markedTemplateHtml(html);
    if (markedHtml.length > TEMPLATE_INSERT_LIMITS.htmlLength) throw failure('TEMPLATE_TOO_LARGE');
    if (!Array.isArray(media)) throw failure('TEMPLATE_MEDIA_INVALID');
    if (media.length > TEMPLATE_INSERT_LIMITS.images
        || (markedHtml.match(/<img\b/gi) || []).length > TEMPLATE_INSERT_LIMITS.images) {
        throw failure('TEMPLATE_MEDIA_TOO_LARGE');
    }
    let totalBytes = 0;
    for (const entry of media) {
        if (typeof entry?.base64 !== 'string') throw failure('TEMPLATE_MEDIA_INVALID');
        // Reject oversized input before allocating its whitespace-free copy.
        if (entry.base64.length > Math.ceil(TEMPLATE_INSERT_LIMITS.mediaBytes / 3) * 8 + 256) {
            throw failure('TEMPLATE_MEDIA_TOO_LARGE');
        }
        const encoded = entry.base64.replace(/^data:image\/[a-z0-9.+-]+;base64,/i, '').replace(/[\r\n]/g, '');
        const padding = encoded.endsWith('==') ? 2 : (encoded.endsWith('=') ? 1 : 0);
        if (!encoded || !/^[A-Za-z0-9+/]*={0,2}$/.test(encoded)
            || encoded.length % 4 === 1 || (padding > 0 && encoded.length % 4 !== 0)) {
            throw failure('TEMPLATE_MEDIA_INVALID');
        }
        totalBytes += Math.floor(encoded.length * 3 / 4) - padding;
        if (totalBytes > TEMPLATE_INSERT_LIMITS.mediaBytes) throw failure('TEMPLATE_MEDIA_TOO_LARGE');
    }
    return markedHtml;
}

async function markTemplateApplied(item, operation) {
    appliedItems.add(item);
    operation.phase = 'applied';
    if (insertionOperations.get(item) === operation) insertionOperations.delete(item);
    try {
        if (!operation.sessionClaimed) return;
        await operation.beforeWrite();
        // Best effort only: a missing session callback must never hold the UI.
        // This stores neither mail text, identity data nor tokens.
        item.sessionData?.setAsync?.(TEMPLATE_SESSION_KEY, '1', () => {});
    } catch {
        // The in-memory marker remains authoritative for this compose item.
    }
}

function nativePrepend(office, item, html, operation) {
    return new Promise((resolve, reject) => {
        let completed = false;
        const uncertain = (details = {}) => {
            operation.phase = 'uncertain';
            reject(failure('TEMPLATE_INSERT_UNCERTAIN', { phase: 'body-prepend', reason: 'timeout', ...details }));
        };
        const timeout = setTimeout(uncertain, TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
        const callback = async (result) => {
            if (completed) return;
            completed = true;
            clearTimeout(timeout);
            try {
                if (result?.status === office?.AsyncResultStatus?.Succeeded) {
                    // A late success after timeout still establishes idempotency.
                    await markTemplateApplied(item, operation);
                    resolve();
                } else if (operation.phase !== 'uncertain') {
                    const officeCode = safeOfficeCode(result?.error);
                    reject(failure(officeCode || 'SET_TEMPLATE_FAILED', { phase: 'body-prepend', reason: 'failed', officeCode }));
                }
            } catch (error) {
                uncertain({ reason: 'exception', officeCode: safeOfficeCode(error) });
            }
        };
        try {
            item.body.prependAsync(html, { coercionType: office.CoercionType.Html }, callback);
        } catch (error) {
            clearTimeout(timeout);
            // Once invoked, a thrown host error cannot prove that no write ran.
            if (!completed) uncertain({ reason: 'exception', officeCode: safeOfficeCode(error) });
        }
    });
}

/**
 * One locked preflight owns the entire insertion, including media preparation.
 * Callers must pass attachment work via beforeInsert, never perform it first.
 * Additional templates require a fresh explicit confirmAdditional callback;
 * a boolean allowAdditional option never authorizes another native write.
 */
export async function prependTemplate(office, item, html, beforeWrite = () => {}, options = {}) {
    const pending = insertionOperations.get(item);
    if (pending) throw failure(pending.phase === 'uncertain' ? 'TEMPLATE_INSERT_UNCERTAIN' : 'TEMPLATE_INSERT_IN_PROGRESS');
    const canConfirmAdditional = typeof options.confirmAdditional === 'function';
    if (appliedItems.has(item) && !canConfirmAdditional) throw failure('TEMPLATE_ALREADY_INSERTED');
    const markedHtml = validatedInsertionHtml(html, options.media ?? []);
    if (!supportsTemplatePrepend(office, item)) throw failure('TEMPLATE_PREPEND_UNAVAILABLE');
    const operation = { phase: 'preflight', beforeWrite };
    insertionOperations.set(item, operation);
    try {
        const state = await assertTemplateInsertable(office, item, { allowAdditional: canConfirmAdditional });
        if (state.bodyLength + markedHtml.length > TEMPLATE_INSERT_LIMITS.bodyLength) {
            throw failure('COMPOSE_BODY_TOO_LARGE');
        }
        await beforeWrite();
        if (state.present) {
            if (await options.confirmAdditional(state) !== true) throw failure('TEMPLATE_INSERT_CANCELLED');
            operation.additionalConfirmed = true;
            await beforeWrite();
        }
        await claimSession(office, item, operation);
        await beforeWrite();
        operation.phase = 'preparing';
        await options.beforeInsert?.();
        await beforeWrite();
        operation.phase = 'writing';
        await nativePrepend(office, item, markedHtml, operation);
    } catch (error) {
        if (error?.code === 'INLINE_ATTACHMENT_UNCERTAIN') operation.phase = 'uncertain';
        await releaseDefiniteSessionFailure(office, item, operation);
        throw error;
    } finally {
        if (operation.phase !== 'uncertain' && insertionOperations.get(item) === operation) {
            insertionOperations.delete(item);
        }
    }
}
