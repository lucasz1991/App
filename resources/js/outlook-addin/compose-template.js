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

function failure(code) {
    const error = new Error(code);
    error.code = code;
    return error;
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
        const timeout = setTimeout(() => finish({ succeeded: false }), TEMPLATE_INSERT_LIMITS.readTimeoutMs);
        try {
            invoke((result) => {
                try {
                    finish(result?.status === office?.AsyncResultStatus?.Succeeded
                        ? { succeeded: true, value: result.value } : { succeeded: false });
                } catch {
                    finish({ succeeded: false });
                }
            });
        } catch {
            finish({ succeeded: false });
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
    if (typeof item.sessionData?.getAsync !== 'function' || typeof item.sessionData?.setAsync !== 'function') return;

    // Outlook runtimes may be isolated. SessionData narrows reopen/concurrent
    // races where available, but read/set/readback is NOT an atomic CAS lock.
    const existing = await readSession(office, item);
    if (!existing.succeeded) {
        operation.phase = 'uncertain';
        throw failure('TEMPLATE_INSERT_UNCERTAIN');
    }
    if (existing.value === '1' && !operation.additionalConfirmed) throw failure('TEMPLATE_ALREADY_INSERTED');
    if (pendingSession(existing.value) || ![null, undefined, '', '1'].includes(existing.value)) {
        throw failure('TEMPLATE_INSERT_UNCERTAIN');
    }
    operation.previousSessionValue = existing.value === '1' ? '1' : '';
    operation.sessionToken = newOperationToken();
    const stored = await writeSession(office, item, operation.sessionToken);
    if (!stored.succeeded) {
        operation.phase = 'uncertain';
        throw failure('TEMPLATE_INSERT_UNCERTAIN');
    }
    operation.sessionClaimed = true;
    const verified = await readSession(office, item);
    if (!verified.succeeded || verified.value !== operation.sessionToken) {
        operation.phase = 'uncertain';
        throw failure('TEMPLATE_INSERT_UNCERTAIN');
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
    if (typeof item?.sessionData?.getAsync === 'function') {
        const session = await readSession(office, item);
        if (!session.succeeded || pendingSession(session.value)
            || ![null, undefined, '', '1'].includes(session.value)) {
            // Pending/unknown claims never expire automatically. A reopened
            // taskpane must not assume that the previous native write failed.
            return { present: false, readable: false, uncertain: true, ...unknownSize };
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
    if (state.uncertain) throw failure('TEMPLATE_INSERT_UNCERTAIN');
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

function markTemplateApplied(item, operation) {
    appliedItems.add(item);
    operation.phase = 'applied';
    if (insertionOperations.get(item) === operation) insertionOperations.delete(item);
    try {
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
        const uncertain = () => {
            operation.phase = 'uncertain';
            reject(failure('TEMPLATE_INSERT_UNCERTAIN'));
        };
        const timeout = setTimeout(uncertain, TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
        const callback = (result) => {
            if (completed) return;
            completed = true;
            clearTimeout(timeout);
            try {
                if (result?.status === office?.AsyncResultStatus?.Succeeded) {
                    // A late success after timeout still establishes idempotency.
                    markTemplateApplied(item, operation);
                    resolve();
                } else if (operation.phase !== 'uncertain') {
                    reject(failure(result?.error?.code || 'SET_TEMPLATE_FAILED'));
                }
            } catch {
                uncertain();
            }
        };
        try {
            item.body.prependAsync(html, { coercionType: office.CoercionType.Html }, callback);
        } catch {
            clearTimeout(timeout);
            // Once invoked, a thrown host error cannot prove that no write ran.
            if (!completed) uncertain();
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
    const operation = { phase: 'preflight' };
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
