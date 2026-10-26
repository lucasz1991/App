import { hasUnquotedSignatureLayoutCarrier, managedTemplateContract, managedTemplateReplacement, standaloneSignatureContract } from './managed-template.js';
import { createNativeLayoutNonce, nativeLayoutRestoreCarrierPresent, nativeLayoutRestoreCss, nativeLayoutRestoreRetained, prepareNativeLayoutRestore, removeNativeLayoutRestoreCarrier } from './native-layout-restore.js';
import { confirmedOfficeWrite, markSignatureWriteConfirmed } from './office-write.js';

// Automatic insertion only prepends. Explicit taskpane replacement below owns
// one verified current-message shell and preserves every outside/quoted byte.
export const TEMPLATE_MARKER = 'RT-TEMPLATE-MANAGED-V1';
export const NATIVE_TEMPLATE_MARKER = `${TEMPLATE_MARKER}:NATIVE-SIGNATURE`;
export const COMBINED_TEMPLATE_MARKER = `${TEMPLATE_MARKER}:COMBINED-DOCUMENT`;
const NATIVE_MARKER_END = '(?=-->|[\\s\"\'<>]|$)';
const nativeTemplateMarker = new RegExp(`${NATIVE_TEMPLATE_MARKER}${NATIVE_MARKER_END}`);
const combinedTemplateMarker = new RegExp(`${COMBINED_TEMPLATE_MARKER}${NATIVE_MARKER_END}`);
const legacyTemplateMarker = new RegExp(`${TEMPLATE_MARKER}(?!:NATIVE-SIGNATURE${NATIVE_MARKER_END})`);
const TEMPLATE_SESSION_KEY = 'railtime.template.inserted.v1';
const appliedItems = new WeakSet();
const observedTemplateRemovals = new WeakSet();
const insertionOperations = new WeakMap();
const signatureOperations = new WeakMap();
const SIGNATURE_SESSION_KEY = 'railtime.signature.layout.v1';
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

export function isMobileComposeHost(office) {
    const platform = String(office?.context?.platform || '').toLowerCase();
    const host = String(office?.context?.mailbox?.diagnostics?.hostName || '').toLowerCase();
    return ['ios', 'android'].includes(platform) || /^(?:outlook)?(?:ios|android)$/.test(host);
}

function mobileReplyBodyMode(office) {
    const diagnostics = office?.context?.mailbox?.diagnostics;
    const host = String(diagnostics?.hostName || '').toLowerCase();
    // hostVersion is the mobile app version only on these canonical hosts.
    // Web/new Windows report the Exchange version, never a mobile capability.
    if (!['outlookios', 'outlookandroid'].includes(host)) return null;
    const match = /^(\d+)\.(\d+)\.(\d+)(?:\.\d+)?$/.exec(String(diagnostics?.hostVersion || ''));
    if (!match) return null;
    const [major, minor, patch] = match.slice(1, 4).map(Number);
    if (![major, minor, patch].every(Number.isSafeInteger)
        || major < 4 || (major === 4 && minor < 2538)) return null;
    const mode = office?.MailboxEnums?.BodyMode?.HostConfig;
    // BodyMode is numeric: FullBody=0, HostConfig=1. An unknown enum value
    // must not be treated as evidence that the quoted conversation is absent.
    return mode === 1 ? mode : null;
}

export function supportsTemplatePrepend(office, item) {
    return !isMobileComposeHost(office)
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

async function readSession(office, item, { allowMissing = false } = {}) {
    const result = await officeResult(office, (callback) => item.sessionData.getAsync(TEMPLATE_SESSION_KEY, callback));
    // Office.js maps 9050 to KeyNotFound: a fresh compose item has no key yet.
    // Accept only this failed get result before a claim, never exceptions,
    // timeouts, or a missing key when verifying/releasing a written claim.
    if (allowMissing && result.succeeded === false
        && result.reason === 'failed' && result.officeCode === '9050') {
        return { succeeded: true, value: '' };
    }
    return result;
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
    const access = operation.mobileNative ? { supported: true } : sessionAccess(office, item);
    if (access.succeeded === false) throw sessionReadError(access, access.phase);
    if (!access.supported || typeof item.sessionData?.setAsync !== 'function') return;

    // Outlook runtimes may be isolated. SessionData narrows reopen/concurrent
    // races where available, but read/set/readback is NOT an atomic CAS lock.
    const existing = await readSession(office, item, { allowMissing: true });
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

export function templateStateFromBody(html, composeType, { currentMessageOnly = false } = {}) {
    if (typeof html !== 'string') {
        return { present: false, readable: false, bodyLength: null, tooLarge: false, legacySignatureEmbedded: true };
    }
    const bodyLength = html.length;
    if (bodyLength > TEMPLATE_INSERT_LIMITS.bodyLength) {
        return { present: false, readable: true, bodyLength, tooLarge: true, legacySignatureEmbedded: true };
    }
    const current = currentMessageOnly ? html : currentComposeBodyHtml(html, composeType);
    // Historical markers are excluded only by a known boundary or the verified
    // mobile HostConfig current-reply read, never by their mere absence.
    // Ambiguous bodies stay conservative instead of duplicating a template.
    const scopedHtml = current === null ? html : current;
    const present = scopedHtml.includes(TEMPLATE_MARKER);
    return {
        present,
        // Old/mixed markers can contain an embedded signature. A native marker
        // in an unscoped reply is not proof about the current compose region.
        legacySignatureEmbedded: present && (current === null || legacyTemplateMarker.test(scopedHtml)),
        readable: true,
        bodyLength,
        tooLarge: false,
    };
}

export async function readTemplateState(office, item, { forceBody = false } = {}) {
    const unknownSize = { bodyLength: null, tooLarge: false, legacySignatureEmbedded: true };
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
        const session = await readSession(office, item, { allowMissing: true });
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

    const bodyMode = mobileReplyBodyMode(office);
    const composeRead = officeRead(office, (callback) => item.getComposeTypeAsync(callback));
    let html;
    let compose;
    let currentMessageOnly = false;
    if (bodyMode !== null) {
        compose = await composeRead;
        // Mobile HostConfig guarantees the current reply from 4.2538.0.
        // Unknown compose types and forwards retain the FullBody heuristic.
        currentMessageOnly = compose?.composeType === 'reply';
        html = await officeRead(office, (callback) => currentMessageOnly
            ? item.body.getAsync(office.CoercionType.Html, { bodyMode }, callback)
            : item.body.getAsync(office.CoercionType.Html, callback));
        // A failed scoped read stays unreadable. Never silently retry FullBody
        // while claiming that quoted template/signature markers were excluded.
    } else {
        [html, compose] = await Promise.all([
            officeRead(office, (callback) => item.body.getAsync(office.CoercionType.Html, callback)),
            composeRead,
        ]);
    }
    const state = templateStateFromBody(html, compose?.composeType, { currentMessageOnly });
    const current = currentMessageOnly && typeof html === 'string'
        ? html : currentComposeBodyHtml(html, compose?.composeType);
    // A completed session is still a duplicate-prevention claim, not proof
    // that the user left the template in the body. Only a readable, scoped,
    // plain-text editing result can disprove an embedded signature here.
    // Tables/media/hidden styles, unknown reply boundaries and pending claims
    // remain conservative; never clear the session or auto-repeat a write.
    const removed = knownPresent && state.readable && !state.tooLarge && !state.present
        && current !== null
        && !/<\s*(?:table|img|picture|svg|style|script|object|iframe|video|canvas|v:|o:)|RT-SIGNATURE-|\b(?:background|src)\s*=|url\s*\(/i.test(current);
    if (removed) observedTemplateRemovals.add(item);
    const removalConfirmed = observedTemplateRemovals.has(item) && current !== null
        && state.readable && !state.tooLarge && !state.present;
    return {
        ...state,
        present: knownPresent || state.present,
        // Session v1 deliberately keeps its old value for cached runtimes. It
        // cannot prove whether a template had a separate or embedded signature.
        legacySignatureEmbedded: state.legacySignatureEmbedded || (knownPresent && !state.present && !removalConfirmed),
    };
}

/**
 * Select the server's explicit signature-free compose artifact without editing
 * its HTML. A legacy document has no mode; callers may retain its old path.
 * An incomplete/unknown new contract must never fall back to its full HTML.
 */
export function nativeComposeTemplate(document) {
    if (document?.signatureMode === undefined) return null;
    if (document?.signatureMode !== 'native'
        || typeof document.composeHtml !== 'string' || document.composeHtml.trim() === ''
        || !Array.isArray(document.composeMedia)
        || !nativeTemplateMarker.test(document.composeHtml)
        || legacyTemplateMarker.test(document.composeHtml)) {
        throw failure('NATIVE_TEMPLATE_INVALID');
    }
    try {
        validatedInsertionHtml(document.composeHtml, document.composeMedia);
    } catch {
        throw failure('NATIVE_TEMPLATE_INVALID');
    }
    return Object.freeze({ ...document, html: document.composeHtml, media: document.composeMedia });
}

/** Select no signature, including when a malformed event payload mixes modes. */
export function signatureFreeComposeTemplate(document) {
    const selected = nativeComposeTemplate(document);
    if (!selected || /RT-SIGNATURE-(?:MANAGED|VERSION)|RT-MOBILE-COMPOSE-VERSION|\bdata-rt-compose-document\s*=/i.test(selected.html)) {
        throw failure('NATIVE_TEMPLATE_INVALID');
    }
    return selected;
}

/** Explicit signature-free mobile slot contract, not a combined-document fallback. */
export function mobileTemplateOnlyComposeDocument(document) {
    const selected = signatureFreeComposeTemplate(document);
    const html = `<div data-rt-compose-document="template-native-v1">${selected.html}</div>`;
    if (html.length > 30000) throw failure('SIGNATURE_TOO_LARGE');
    validatedInsertionHtml(html, selected.media);
    return Object.freeze({ ...selected, html });
}

/**
 * Additive contract: cached clients retain native composeHtml, while updated
 * clients insert the complete ordinary-body document exactly once. The separate
 * explicit mobile-native contract below is not a fallback for this Desktop path.
 */
export function combinedComposeDocument(document) {
    if (document?.composeDocumentMode === undefined) return null;
    if (document?.composeDocumentMode !== 'combined-v1'
        || typeof document.combinedComposeHtml !== 'string' || document.combinedComposeHtml.trim() === ''
        || !Array.isArray(document.combinedComposeMedia)
        || !combinedTemplateMarker.test(document.combinedComposeHtml)
        || !/\bdata-rt-compose-document\s*=\s*["']combined-v1["']/i.test(document.combinedComposeHtml)
        || !document.combinedComposeHtml.includes('RT-SIGNATURE-MANAGED-V1')
        || nativeTemplateMarker.test(document.combinedComposeHtml)) {
        throw failure('COMBINED_TEMPLATE_INVALID');
    }
    try {
        validatedInsertionHtml(document.combinedComposeHtml, document.combinedComposeMedia);
    } catch {
        throw failure('COMBINED_TEMPLATE_INVALID');
    }
    return Object.freeze({ ...document, html: document.combinedComposeHtml, media: document.combinedComposeMedia });
}

/** Explicit mobile-only ownership contract approved independently of Desktop. */
export function mobileCombinedComposeDocument(document) {
    if (document?.mobileComposeDocumentMode === undefined) return null;
    if (document.mobileComposeDocumentMode !== 'combined-native-v1'
        || typeof document.mobileComposeHtml !== 'string' || document.mobileComposeHtml.trim() === ''
        || document.mobileComposeHtml.length > 30000
        || !Array.isArray(document.mobileComposeMedia)
        || !/^[0-9a-f]{16}$/.test(document.mobileComposeVersion || '')
        || !combinedTemplateMarker.test(document.mobileComposeHtml)
        || !/\bdata-rt-compose-document\s*=\s*["']combined-native-v1["']/i.test(document.mobileComposeHtml)
        || !new RegExp(`RT-MOBILE-COMPOSE-VERSION:${document.mobileComposeVersion}${NATIVE_MARKER_END}`).test(document.mobileComposeHtml)
        || !document.mobileComposeHtml.includes('RT-SIGNATURE-MANAGED-V1')
        || nativeTemplateMarker.test(document.mobileComposeHtml)) {
        throw failure('MOBILE_COMBINED_TEMPLATE_INVALID');
    }
    try {
        validatedInsertionHtml(document.mobileComposeHtml, document.mobileComposeMedia);
    } catch {
        throw failure('MOBILE_COMBINED_TEMPLATE_INVALID');
    }
    return Object.freeze({ ...document, html: document.mobileComposeHtml, media: document.mobileComposeMedia });
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

// Shared pure preflight for callers coordinating signature + template writes.
// It uses exactly the same limits as prependTemplate and performs no host calls.
export function validateTemplateInsertionPayload(html, media = []) {
    return validatedInsertionHtml(html, media);
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

function nativeTemplateWrite(office, item, html, operation, replace = false) {
    return new Promise((resolve, reject) => {
        let completed = false;
        const phase = replace ? 'body-replace' : 'body-prepend';
        const uncertain = (details = {}) => {
            operation.phase = 'uncertain';
            reject(failure('TEMPLATE_INSERT_UNCERTAIN', { phase, reason: 'timeout', ...details }));
        };
        const timeout = setTimeout(uncertain, TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
        const callback = async (result) => {
            if (completed) return;
            completed = true;
            clearTimeout(timeout);
            try {
                if (result?.status === office?.AsyncResultStatus?.Succeeded) {
                    if (operation.deferCompletion) {
                        // A late body confirmation cannot authorize an unrelated
                        // second SDK write after the operation became uncertain.
                        if (operation.phase === 'uncertain') return;
                        operation.bodyApplied = true;
                        operation.phase = 'restoring-layout';
                        resolve();
                        return;
                    }
                    // A late success after timeout still establishes idempotency.
                    await markTemplateApplied(item, operation);
                    resolve();
                } else if (operation.phase !== 'uncertain') {
                    const officeCode = safeOfficeCode(result?.error);
                    reject(failure(officeCode || 'SET_TEMPLATE_FAILED', { phase, reason: 'failed', officeCode }));
                }
            } catch (error) {
                uncertain({ reason: 'exception', officeCode: safeOfficeCode(error) });
            }
        };
        try {
            if (replace) item.body.setAsync(html, { coercionType: office.CoercionType.Html }, callback);
            else item.body.prependAsync(html, { coercionType: office.CoercionType.Html }, callback);
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
    if (signatureOperations.has(item)) throw failure('SIGNATURE_INSERT_UNCERTAIN');
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
        await nativeTemplateWrite(office, item, markedHtml, operation);
    } catch (error) {
        if (['INLINE_ATTACHMENT_UNCERTAIN', 'SIGNATURE_INSERT_UNCERTAIN'].includes(error?.code)) operation.phase = 'uncertain';
        await releaseDefiniteSessionFailure(office, item, operation);
        throw error;
    } finally {
        if (operation.phase !== 'uncertain' && insertionOperations.get(item) === operation) {
            insertionOperations.delete(item);
        }
    }
}

async function replacementSnapshot(office, item) {
    const format = await officeResult(office, callback => item.body.getTypeAsync(callback));
    if (!format.succeeded) throw failure('COMPOSE_BODY_UNREADABLE', { ...format, phase: 'body-type-read' });
    if (format.value !== office.CoercionType.Html) throw failure('TEMPLATE_REQUIRES_HTML');
    const compose = await officeResult(office, callback => item.getComposeTypeAsync(callback));
    if (!compose.succeeded) throw failure('COMPOSE_BODY_UNREADABLE', { ...compose, phase: 'compose-type-read' });
    // Read HTML last. No asynchronous preparation follows the final snapshot;
    // its equality check and the single SDK write run in the same continuation.
    const body = await officeResult(office, callback => item.body.getAsync(office.CoercionType.Html, callback));
    if (!body.succeeded || typeof body.value !== 'string') throw failure('COMPOSE_BODY_UNREADABLE', { ...body, phase: 'body-read' });
    if (body.value.length > TEMPLATE_INSERT_LIMITS.bodyLength) throw failure('COMPOSE_BODY_TOO_LARGE');
    return { html: body.value, composeType: compose.value?.composeType };
}

export function requiresStandaloneSignatureLayout(html) {
    return typeof html === 'string' && /\bdata-rt-personal-flow\s*=/.test(html);
}

export function isStandaloneSignatureInsertionBlocked(item) {
    return signatureOperations.has(item);
}

function standaloneReadback(snapshot, ids, version) {
    const current = currentComposeBodyHtml(snapshot.html, snapshot.composeType);
    const contract = standaloneSignatureContract(snapshot.html, current, ids, version);
    return layoutIdsContract(snapshot.html, current, ids, contract);
}

function existingStandalone(snapshot) {
    const current = currentComposeBodyHtml(snapshot.html, snapshot.composeType);
    let contract;
    try { contract = standaloneSignatureContract(snapshot.html, current); } catch { return null; }
    if (!contract.layoutIds) return null;
    return { contract: standaloneReadback(snapshot, contract.layoutIds, contract.version), ids: contract.layoutIds };
}

export function standaloneSignatureLayoutIsCurrent(html, currentHtml, version) {
    try {
        const contract = standaloneSignatureContract(html, currentHtml, null, version);
        if (!contract.layoutIds) return false;
        const scoped = layoutIdsContract(html, currentHtml, contract.layoutIds, contract);
        return nativeLayoutRestoreRetained(html, scoped, contract.layoutIds) === true;
    } catch { return false; }
}

async function signatureSession(office, item, operation, mode) {
    const access = sessionAccess(office, item);
    if (access.succeeded === false) throw failure('SIGNATURE_INSERT_UNCERTAIN', { phase: 'signature-session-capability' });
    if (!access.supported || typeof item.sessionData?.setAsync !== 'function') return;
    const read = await officeResult(office, callback => item.sessionData.getAsync(SIGNATURE_SESSION_KEY, callback));
    const missing = !read.succeeded && read.reason === 'failed' && read.officeCode === '9050';
    if (mode === 'claim') {
        if ((!read.succeeded && !missing) || (!missing && ![null, undefined, '', '1'].includes(read.value))) throw failure('SIGNATURE_INSERT_UNCERTAIN', { phase: 'signature-session-claim' });
        operation.previousSession = read.value === '1' ? '1' : '';
        await operation.beforeWrite();
        const stored = await officeResult(office, callback => item.sessionData.setAsync(SIGNATURE_SESSION_KEY, operation.token, callback));
        if (!stored.succeeded) { operation.phase = 'uncertain'; throw failure('SIGNATURE_INSERT_UNCERTAIN', { phase: 'signature-session-write' }); }
        operation.sessionClaimed = true;
        return signatureSession(office, item, operation, 'verify');
    }
    if (!operation.sessionClaimed) return;
    if (!read.succeeded || read.value !== operation.token) { operation.phase = 'uncertain'; throw failure('SIGNATURE_INSERT_UNCERTAIN', { phase: 'signature-session-readback' }); }
    if (mode === 'complete' || mode === 'release') {
        await operation.beforeWrite();
        const value = mode === 'complete' ? '1' : operation.previousSession;
        const written = await officeResult(office, callback => item.sessionData.setAsync(SIGNATURE_SESSION_KEY, value, callback));
        if (!written.succeeded) { operation.phase = 'uncertain'; throw failure('SIGNATURE_INSERT_UNCERTAIN', { phase: 'signature-session-finish' }); }
    }
}

/** Native slot only; no full-body rewrite and no Template/session ownership. */
export async function insertStandaloneSignature(office, item, html, beforeWrite = () => {}, options = {}) {
    if (insertionOperations.has(item)) throw failure('TEMPLATE_INSERT_UNCERTAIN');
    const pending = signatureOperations.get(item);
    if (pending) throw failure(pending.phase === 'uncertain' ? 'SIGNATURE_INSERT_UNCERTAIN' : 'SIGNATURE_INSERT_IN_PROGRESS');
    if (isMobileComposeHost(office) || typeof item?.body?.setSignatureAsync !== 'function') throw failure('SIGNATURE_LAYOUT_UNAVAILABLE');
    validatedInsertionHtml(html, options.media ?? []);
    const incoming = standaloneSignatureContract(html);
    const operation = { phase: 'preflight', beforeWrite, token: `pending:signature:${createNativeLayoutNonce()}` };
    signatureOperations.set(item, operation);
    try {
        const original = await replacementSnapshot(office, item);
        const current = currentComposeBodyHtml(original.html, original.composeType);
        if (current === null || templateStateFromBody(original.html, original.composeType).present) throw failure('SIGNATURE_LAYOUT_UNAVAILABLE');
        const previous = existingStandalone(original);
        let reuse = null;
        if (previous) {
            let valid = false;
            try { valid = nativeLayoutRestoreRetained(original.html, previous.contract, previous.ids) === true; } catch { /* No retry against a stale carrier. */ }
            if (!valid) { operation.phase = 'uncertain'; throw failure('SIGNATURE_LAYOUT_UNCONFIRMED'); }
            if (previous.contract.version === incoming.version) {
                await beforeWrite(); await signatureSession(office, item, operation, 'claim'); await beforeWrite();
                const latest = await replacementSnapshot(office, item);
                if (latest.html !== original.html || latest.composeType !== original.composeType) throw failure('COMPOSE_BODY_CHANGED');
                options.beforeCommit?.(); await signatureSession(office, item, operation, 'complete'); markSignatureWriteConfirmed(item); return 'current';
            }
            reuse = /^rt-native-layout-([0-9a-f]{32})-r$/.exec(previous.ids.root)?.[1];
            if (!reuse) throw failure('SIGNATURE_LAYOUT_UNAVAILABLE');
        } else if (hasUnquotedSignatureLayoutCarrier(original.html, current)) { operation.phase = 'uncertain'; throw failure('SIGNATURE_LAYOUT_UNCONFIRMED'); }
        const layout = prepareNativeLayoutRestore(html, incoming, reuse || undefined);
        if (layout?.html.length > 30000) throw failure('SIGNATURE_TOO_LARGE');
        if (!layout?.ids.signature || original.html.length + layout.html.length + layout.stylePayload.length > TEMPLATE_INSERT_LIMITS.bodyLength) throw failure('SIGNATURE_LAYOUT_UNAVAILABLE');
        await beforeWrite();
        await signatureSession(office, item, operation, 'claim');
        await beforeWrite(); await options.beforeInsert?.(); await beforeWrite();
        await signatureSession(office, item, operation, 'verify');
        const latest = await replacementSnapshot(office, item);
        if (latest.html !== original.html || latest.composeType !== original.composeType) throw failure('COMPOSE_BODY_CHANGED');
        options.beforeCommit?.(); operation.phase = 'writing';
        await confirmedOfficeWrite(office, item, 'signature-write', 'SIGNATURE_INSERT_UNCERTAIN',
            callback => item.body.setSignatureAsync(layout.html, { coercionType: office.CoercionType.Html }, callback),
            { deferSignatureConfirmation: true });
        operation.bodyApplied = true; operation.phase = 'restoring-layout';
        await beforeWrite(); await signatureSession(office, item, operation, 'verify'); await beforeWrite();
        let written = await replacementSnapshot(office, item);
        if (written.composeType !== original.composeType) throw failure('SIGNATURE_LAYOUT_UNCONFIRMED');
        let owned = standaloneReadback(written, layout.ids, incoming.version);
        let retained = false;
        try { retained = nativeLayoutRestoreRetained(written.html, owned, layout.ids) === true; } catch { /* Positive proof only. */ }
        if (!retained) {
            if (nativeLayoutRestoreCarrierPresent(written.html, owned, layout.ids)) throw failure('SIGNATURE_LAYOUT_UNCONFIRMED');
            options.beforeCommit?.();
            await nativeLayoutStyleWrite(office, item, layout.stylePayload, operation);
            await beforeWrite(); await signatureSession(office, item, operation, 'verify'); await beforeWrite();
            written = await replacementSnapshot(office, item);
            if (written.composeType !== original.composeType) throw failure('SIGNATURE_LAYOUT_UNCONFIRMED');
            owned = standaloneReadback(written, layout.ids, incoming.version);
            if (nativeLayoutRestoreRetained(written.html, owned, layout.ids) !== true) throw failure('SIGNATURE_LAYOUT_UNCONFIRMED');
        }
        options.beforeCommit?.(); await signatureSession(office, item, operation, 'complete');
        markSignatureWriteConfirmed(item); operation.phase = 'applied'; return 'applied';
    } catch (error) {
        if (operation.bodyApplied || ['SIGNATURE_INSERT_UNCERTAIN', 'INLINE_ATTACHMENT_UNCERTAIN'].includes(error?.code) || operation.phase === 'uncertain') {
            operation.phase = 'uncertain';
            throw failure(operation.bodyApplied || error?.code === 'SIGNATURE_LAYOUT_UNCONFIRMED' ? 'SIGNATURE_LAYOUT_UNCONFIRMED' : 'SIGNATURE_INSERT_UNCERTAIN', { phase: error?.phase || 'signature-layout-verify' });
        }
        try { await signatureSession(office, item, operation, 'release'); } catch { operation.phase = 'uncertain'; }
        throw error;
    } finally {
        if (operation.phase !== 'uncertain' && signatureOperations.get(item) === operation) signatureOperations.delete(item);
    }
}

function withinContract(contract, index, parent) {
    for (let current = index; current !== undefined; current = contract.elements[current].parent) {
        if (current === parent) return true;
    }
    return false;
}

function layoutIdsContract(html, currentHtml, ids, standalone = null) {
    const contract = standalone || managedTemplateContract(html, currentHtml);
    if (!['text-v1', 'standalone-signature-v1'].includes(contract.kind)) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
    const targets = {};
    for (const key of Object.keys(ids)) {
        // Count every matching ID in the whole body, including quoted history.
        // A prefix is accepted only after its exact Office form is established.
        const matches = contract.elements.map((node, index) => ({ node, index })).filter(value => typeof value.node.attrs.id === 'string'
            && value.node.attrs.id.endsWith(ids[key]));
        if (matches.length !== 1 || matches[0].node.tag !== 'table'
            || ![ids[key], `x_${ids[key]}`].includes(matches[0].node.attrs.id)
            || !withinContract(contract, matches[0].index, contract.signature.index)) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
        targets[key] = matches[0];
    }
    const children = parent => contract.elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === parent);
    const only = (parent, tag) => {
        const values = children(parent);
        if (values.length !== 1 || values[0].node.tag !== tag) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
        return values[0].index;
    };
    const cell = only(only(only(targets.root.index, 'tbody'), 'tr'), 'td');
    const groups = children(cell);
    if (groups.length !== 3 || groups.some((value, index) => value.index !== [targets.person.index, targets.company.index, targets.train.index][index])
        || (targets.header && withinContract(contract, targets.header.index, targets.root.index))) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
    if (targets.header) {
        const rows = children(only(targets.header.index, 'tbody'));
        if (!rows.length || rows.some(value => value.node.tag !== 'tr')
            || withinContract(contract, targets.root.index, rows[0].index)) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
    }
    if (targets.signature) {
        const rows = children(only(targets.signature.index, 'tbody'));
        if (targets.signature.index !== contract.signature.index || rows.length !== 2 || rows.some(value => value.node.tag !== 'tr')) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
        const firstCell = only(rows[0].index, 'td');
        const lastCell = only(rows[1].index, 'td');
        if (!withinContract(contract, targets.root.index, firstCell)
            || !Number.isInteger(contract.signatureMetadata?.managed) || !Number.isInteger(contract.signatureMetadata?.version)
            || !withinContract(contract, contract.signatureMetadata.managed, lastCell)
            || !withinContract(contract, contract.signatureMetadata.version, lastCell)
            || (targets.header && !withinContract(contract, targets.header.index, firstCell))) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
    }
    return contract;
}

function removePreviousLayoutSheet(html, contract) {
    // Delete only an exact sheet from our own fixed factory whose operation IDs
    // still belong to this signature. Unknown and quoted styles remain opaque.
    const roots = contract.elements.filter((node, index) => withinContract(contract, index, contract.signature.index)
        && /^(?:x_)?rt-native-layout-[0-9a-f]{32}-r$/.test(node.attrs.id || ''));
    if (roots.length !== 1) return html;
    const base = roots[0].attrs.id.replace(/^x_/, '').slice(0, -1);
    const ids = { root: base + 'r', person: base + 'p', company: base + 'c', train: base + 't' };
    if (contract.elements.some((node, index) => withinContract(contract, index, contract.signature.index)
        && [base + 'h', 'x_' + base + 'h'].includes(node.attrs.id))) ids.header = base + 'h';
    if (contract.elements.some((node, index) => withinContract(contract, index, contract.signature.index)
        && [base + 's', 'x_' + base + 's'].includes(node.attrs.id))) ids.signature = base + 's';
    try { layoutIdsContract(html, html.slice(0, contract.root.end), ids); } catch { return html; }
    const withoutCarrier = removeNativeLayoutRestoreCarrier(html, contract, ids);
    if (withoutCarrier !== html) return withoutCarrier;
    const expected = nativeLayoutRestoreCss(ids).replace(/\s+/g, '');
    const edits = contract.elements.filter(node => node.tag === 'style' && (node.end <= contract.root.start || node.start >= contract.root.end)
        && html.slice(node.openEnd, node.close).replace(/\s+/g, '') === expected);
    // An exact sheet in a quote must not be removed. The verified global ID
    // uniqueness above also prevents choosing a signature copied into a quote.
    const quoted = index => {
        for (let current = index; current !== undefined; current = contract.elements[current].parent) {
            const node = contract.elements[current];
            if (node.tag === 'blockquote' || /^(?:x_)?(?:divrplyfwdmsg|mail-editor-reference-message-container|stopspelling)$/i.test(node.attrs.id || '')
                || /(?:^|\s)(?:x_)?(?:gmail_quote|yahoo_quoted|moz-cite-prefix)(?:\s|$)/.test(node.attrs.class || '')) return true;
        }
        return false;
    };
    let clean = html;
    for (const node of edits.sort((left, right) => right.start - left.start)) {
        if (!quoted(contract.elements.indexOf(node))) clean = clean.slice(0, node.start) + clean.slice(node.end);
    }
    return clean;
}

function nativeLayoutStyleWrite(office, item, payload, operation) {
    return new Promise((resolve, reject) => {
        let finished = false;
        const uncertain = (reason, error) => {
            if (finished) return;
            finished = true;
            operation.phase = 'uncertain';
            reject(failure('TEMPLATE_LAYOUT_UNCONFIRMED', { phase: 'layout-style-prepend', reason, officeCode: safeOfficeCode(error) }));
        };
        const timer = setTimeout(() => uncertain('timeout'), TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
        try {
            item.body.prependAsync(payload, { coercionType: office.CoercionType.Html }, async result => {
                if (finished) return;
                clearTimeout(timer);
                if (result?.status !== office?.AsyncResultStatus?.Succeeded) { uncertain('failed', result?.error); return; }
                finished = true;
                resolve();
            });
        } catch (error) { clearTimeout(timer); uncertain('exception', error); }
    });
}

async function restoreNativeLayout(office, item, layout, operation, original, options) {
    await operation.beforeWrite();
    if (operation.sessionClaimed) {
        const ownership = await readSession(office, item);
        if (!ownership.succeeded || ownership.value !== operation.sessionToken) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED', { phase: 'layout-session-readback' });
    }
    await operation.beforeWrite();
    const written = await replacementSnapshot(office, item);
    if (written.composeType !== original.composeType) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
    layoutIdsContract(written.html, currentComposeBodyHtml(written.html, written.composeType), layout.ids);
    options.beforeCommit?.();
    operation.phase = 'restoring-layout';
    await nativeLayoutStyleWrite(office, item, layout.stylePayload, operation);
    // A successful prepend callback is not proof that Outlook kept its STYLE.
    // Re-read the actual SDK body before completing the shared operation.
    await operation.beforeWrite();
    if (operation.sessionClaimed) {
        const ownership = await readSession(office, item);
        if (!ownership.succeeded || ownership.value !== operation.sessionToken) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED', { phase: 'layout-final-session-readback' });
    }
    await operation.beforeWrite();
    const restored = await replacementSnapshot(office, item);
    if (restored.composeType !== original.composeType) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED');
    const retained = layoutIdsContract(restored.html, currentComposeBodyHtml(restored.html, restored.composeType), layout.ids);
    if (nativeLayoutRestoreRetained(restored.html, retained, layout.ids) !== true) throw failure('TEMPLATE_LAYOUT_UNCONFIRMED', { phase: 'layout-retention-readback' });
    options.beforeCommit?.();
    await markTemplateApplied(item, operation);
}

/** Explicit user replacement only; automatic/event insertion never calls this. */
export async function replaceManagedTemplate(office, item, html, beforeWrite = () => {}, options = {}) {
    if (signatureOperations.has(item)) throw failure('SIGNATURE_INSERT_UNCERTAIN');
    const pending = insertionOperations.get(item);
    if (pending) throw failure(pending.phase === 'uncertain' ? 'TEMPLATE_INSERT_UNCERTAIN' : 'TEMPLATE_INSERT_IN_PROGRESS');
    const markedHtml = validatedInsertionHtml(html, options.media ?? []);
    if (!supportsTemplatePrepend(office, item) || typeof item.body?.setAsync !== 'function') throw failure('TEMPLATE_REPLACEMENT_UNAVAILABLE');
    // Reuse the established session claim and rollback. Permission here comes
    // from the explicit replacement button, never an automatic repeat/fallback.
    const operation = { phase: 'preflight', beforeWrite, additionalConfirmed: true };
    insertionOperations.set(item, operation);
    try {
        const state = await assertTemplateInsertable(office, item, { allowAdditional: true });
        if (!state.present) throw failure('TEMPLATE_REPLACEMENT_UNAVAILABLE');
        const original = await replacementSnapshot(office, item);
        const incoming = managedTemplateContract(markedHtml);
        const nativePersonal = incoming.elements.some((node, index) => withinContract(incoming, index, incoming.signature.index)
            && (Object.prototype.hasOwnProperty.call(node.attrs, 'data-rt-personal-flow') || (node.attrs.class || '').split(/\s+/).includes('rt-personal-columns')));
        if (nativePersonal && incoming.kind !== 'text-v1') throw failure('TEMPLATE_REPLACEMENT_UNAVAILABLE');
        const layout = nativePersonal ? prepareNativeLayoutRestore(markedHtml, incoming) : null;
        const oldContract = managedTemplateContract(original.html, currentComposeBodyHtml(original.html, original.composeType));
        const cleaned = removePreviousLayoutSheet(original.html, oldContract);
        const plan = managedTemplateReplacement(cleaned, currentComposeBodyHtml(cleaned, original.composeType), layout?.html ?? markedHtml);
        if (plan.html.length + (layout?.stylePayload.length ?? 0) > TEMPLATE_INSERT_LIMITS.bodyLength) throw failure('COMPOSE_BODY_TOO_LARGE');
        operation.deferCompletion = Boolean(layout);
        await beforeWrite();
        await claimSession(office, item, operation);
        await beforeWrite();
        operation.phase = 'preparing';
        await options.beforeReplace?.();
        await beforeWrite();
        if (operation.sessionClaimed) {
            const ownership = await readSession(office, item);
            if (!ownership.succeeded || ownership.value !== operation.sessionToken) {
                operation.phase = 'uncertain';
                throw failure('TEMPLATE_INSERT_UNCERTAIN', { ...ownership, phase: 'session-readback', reason: ownership.succeeded ? 'mismatch' : ownership.reason });
            }
        }
        const latest = await replacementSnapshot(office, item);
        if (latest.html !== original.html || latest.composeType !== original.composeType) throw failure('COMPOSE_BODY_CHANGED');
        options.beforeCommit?.();
        operation.phase = 'writing';
        await nativeTemplateWrite(office, item, plan.html, operation, true);
        if (layout) await restoreNativeLayout(office, item, layout, operation, original, options);
    } catch (error) {
        if (operation.bodyApplied && operation.phase !== 'applied') {
            operation.phase = 'uncertain';
            throw failure('TEMPLATE_LAYOUT_UNCONFIRMED', { phase: error?.phase || 'layout-verify', reason: error?.reason || 'unconfirmed' });
        }
        if (['INLINE_ATTACHMENT_UNCERTAIN', 'SIGNATURE_INSERT_UNCERTAIN'].includes(error?.code)) operation.phase = 'uncertain';
        await releaseDefiniteSessionFailure(office, item, operation);
        throw error;
    } finally {
        if (operation.phase !== 'uncertain' && insertionOperations.get(item) === operation) insertionOperations.delete(item);
    }
}

function assertMobileSessionAvailable(office, item) {
    const diagnostics = office?.context?.mailbox?.diagnostics;
    const host = String(diagnostics?.hostName || '').toLowerCase();
    const version = /^(\d+)\.(\d+)\.(\d+)(?:\.\d+)?$/.exec(String(diagnostics?.hostVersion || ''));
    const numbers = version ? version.slice(1, 4).map(Number) : [];
    // SessionData is a documented mobile exception to the baseline Mailbox1.5
    // matrix. The desktop 1.11 checker cannot establish mobile availability.
    if (!isMobileComposeHost(office) || !['outlookios', 'outlookandroid'].includes(host)
        || !version || !numbers.every(Number.isSafeInteger)
        || numbers[0] < 4 || (numbers[0] === 4 && numbers[1] < 2425)
        || typeof item?.sessionData?.getAsync !== 'function'
        || typeof item?.sessionData?.setAsync !== 'function') {
        throw failure('MOBILE_COMPOSE_SESSION_UNAVAILABLE');
    }
}

async function mobileBodyState(office, item) {
    const compose = await officeResult(office, (callback) => item.getComposeTypeAsync(callback));
    if (!compose.succeeded || !['newMail', 'reply', 'forward'].includes(compose.value?.composeType)
        || compose.value?.coercionType !== office?.CoercionType?.Html) {
        throw failure('TEMPLATE_REQUIRES_HTML');
    }
    const bodyMode = mobileReplyBodyMode(office);
    const currentMessageOnly = compose.value.composeType === 'reply' && bodyMode !== null;
    const body = await officeResult(office, (callback) => currentMessageOnly
        ? item.body.getAsync(office.CoercionType.Html, { bodyMode }, callback)
        : item.body.getAsync(office.CoercionType.Html, callback));
    const state = templateStateFromBody(body.succeeded ? body.value : null, compose.value.composeType, { currentMessageOnly });
    if (!state.readable) throw failure('COMPOSE_BODY_UNREADABLE');
    if (state.tooLarge) throw failure('COMPOSE_BODY_TOO_LARGE');
    if (state.present || state.legacySignatureEmbedded) throw failure('TEMPLATE_ALREADY_INSERTED');
    return state;
}

/** Retain native-slot ownership even if default selection/metadata disappears. */
export async function hasMobileTemplateOwnership(office, item) {
    // Capability uncertainty must not be interpreted as absent ownership.
    // Reading an existing claim does not need a setter or host-version gate.
    if (typeof item?.sessionData?.getAsync !== 'function') throw failure('MOBILE_COMPOSE_SESSION_UNAVAILABLE');
    const existing = await readSession(office, item, { allowMissing: true });
    if (!existing.succeeded) throw sessionReadError(existing);
    if (existing.value === '1') return true;
    if (pendingSession(existing.value) || ![null, undefined, ''].includes(existing.value)) throw failure('TEMPLATE_INSERT_UNCERTAIN');
    return false;
}

function mobileNativeSignature(office, item, html, operation) {
    return new Promise((resolve, reject) => {
        let completed = false;
        const uncertain = (details = {}) => {
            operation.phase = 'uncertain';
            reject(failure('TEMPLATE_INSERT_UNCERTAIN', { phase: 'mobile-combined-signature', reason: 'timeout', ...details }));
        };
        const timeout = setTimeout(uncertain, TEMPLATE_INSERT_LIMITS.writeTimeoutMs);
        const callback = async (result) => {
            if (completed) return;
            completed = true;
            clearTimeout(timeout);
            try {
                if (result?.status === office?.AsyncResultStatus?.Succeeded) {
                    // Late success establishes ownership only: no subsequent
                    // template/signature/media write may be started by it.
                    await markTemplateApplied(item, operation);
                    resolve();
                } else if (operation.phase !== 'uncertain') {
                    throw failure('OFFICE_WRITE_FAILED', { phase: 'mobile-combined-signature', reason: 'callback', officeCode: safeOfficeCode(result?.error) });
                }
            } catch (error) {
                if (error?.code === 'OFFICE_WRITE_FAILED' && operation.phase !== 'uncertain') reject(error);
                else uncertain({ reason: 'exception', officeCode: safeOfficeCode(error) });
            }
        };
        try {
            item.body.setSignatureAsync(html, { coercionType: office.CoercionType.Html }, callback);
        } catch (error) {
            clearTimeout(timeout);
            if (!completed) uncertain({ reason: 'exception', officeCode: safeOfficeCode(error) });
        }
    });
}

/** One mobile native-slot write; never rewrites or selects the ordinary body. */
export async function insertMobileCombinedTemplate(office, item, html, beforeWrite = () => {}, options = {}) {
    const pending = insertionOperations.get(item);
    if (pending) throw failure(pending.phase === 'uncertain' ? 'TEMPLATE_INSERT_UNCERTAIN' : 'TEMPLATE_INSERT_IN_PROGRESS');
    if (appliedItems.has(item)) throw failure('TEMPLATE_ALREADY_INSERTED');
    assertMobileSessionAvailable(office, item);
    if (typeof item?.body?.setSignatureAsync !== 'function'
        || typeof item?.body?.getAsync !== 'function'
        || typeof item?.getComposeTypeAsync !== 'function') throw failure('COMPOSE_API_UNAVAILABLE');
    const markedHtml = validatedInsertionHtml(html, options.media ?? []);
    if (options.documentMode === 'template-native-v1') {
        const opening = '<div data-rt-compose-document="template-native-v1">';
        if (!markedHtml.startsWith(opening) || !markedHtml.endsWith('</div>')) throw failure('MOBILE_TEMPLATE_ONLY_INVALID');
        const inner = markedHtml.slice(opening.length, -6);
        try {
            signatureFreeComposeTemplate({ signatureMode: 'native', composeHtml: inner, composeMedia: options.media ?? [] });
        } catch { throw failure('MOBILE_TEMPLATE_ONLY_INVALID'); }
    } else if (options.documentMode !== undefined || !combinedTemplateMarker.test(markedHtml)
        || !/\bdata-rt-compose-document\s*=\s*["']combined-native-v1["']/i.test(markedHtml)) throw failure('MOBILE_COMBINED_TEMPLATE_INVALID');
    if (markedHtml.length > 30000) throw failure('SIGNATURE_TOO_LARGE');
    const operation = { phase: 'preflight', beforeWrite, mobileNative: true };
    insertionOperations.set(item, operation);
    try {
        const initial = await readSession(office, item, { allowMissing: true });
        if (!initial.succeeded) throw sessionReadError(initial);
        if (initial.value === '1') throw failure('TEMPLATE_ALREADY_INSERTED');
        if (pendingSession(initial.value) || ![null, undefined, ''].includes(initial.value)) throw failure('TEMPLATE_INSERT_UNCERTAIN');
        const state = await mobileBodyState(office, item);
        if (state.bodyLength + markedHtml.length > TEMPLATE_INSERT_LIMITS.bodyLength) throw failure('COMPOSE_BODY_TOO_LARGE');
        await beforeWrite();
        await claimSession(office, item, operation);
        await beforeWrite();
        operation.phase = 'preparing';
        await options.beforeInsert?.();
        await beforeWrite();
        // SessionData has no atomic compare-and-swap. Revalidate both the
        // claim and current-message marker after the slow media work, then
        // again immediately before the only native body mutation.
        await mobileBodyState(office, item);
        const owned = await readSession(office, item);
        if (!owned.succeeded || owned.value !== operation.sessionToken) {
            operation.phase = 'uncertain';
            throw failure('TEMPLATE_INSERT_UNCERTAIN', { phase: 'session-readback', reason: owned.succeeded ? 'mismatch' : owned.reason });
        }
        await beforeWrite();
        operation.phase = 'writing';
        await mobileNativeSignature(office, item, markedHtml, operation);
    } catch (error) {
        if (['INLINE_ATTACHMENT_UNCERTAIN', 'SIGNATURE_INSERT_UNCERTAIN'].includes(error?.code)) operation.phase = 'uncertain';
        await releaseDefiniteSessionFailure(office, item, operation);
        throw error;
    } finally {
        if (operation.phase !== 'uncertain' && insertionOperations.get(item) === operation) insertionOperations.delete(item);
    }
}
