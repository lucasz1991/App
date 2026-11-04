import { U2E_READBACK_CHECKPOINTS, u2eDiagnosticHash } from './u2e-readback-diagnostics.js';

// Explanatory metadata only. Neither this key nor its contents authorize a write.
export const UNIVERSAL_REPORT_SESSION_KEY = 'railtime.u2e.report.v1';
const composeKey = 'railtime.u2e.compose.v1';
const dedupeKey = 'railtime.u2e.diagnostics.v1';
const pending = /^pending:([0-9a-f]{24}):[0-9a-f]{32}$/;
const validPending = value => typeof value === 'string' && value.length === 65 && pending.test(value);
export const U2E_REPORT_CODES = Object.freeze(['SIGNATURE_LAYOUT_UNCONFIRMED', 'U2E_INSERT_UNCERTAIN', 'U2E_NEW_DRAFT_REQUIRED',
    'U2E_SOURCE_INVALID', 'U2E_CONTEXT_UNREADABLE', 'U2E_API_UNAVAILABLE', 'U2E_HEADER_UNAVAILABLE',
    'U2E_DOCUMENT_INVALID', 'U2E_INSERT_IN_PROGRESS', 'U2E_SESSION_UNAVAILABLE', 'U2E_HTML_REQUIRED',
    'U2E_CONTEXT_CHANGED', 'COMPOSE_ITEM_CHANGED', 'U2E_OPERATION_FAILED', 'U2E_AUTOMATIC_SKIPPED']);
export const U2E_REPORT_STEPS = Object.freeze(['binding', 'legacy', 'session.read', 'session.claim', 'session.finalize', 'session.verify',
    'snapshot.type', 'snapshot.format', 'snapshot.body', 'snapshot.region', 'source.state', 'header.write',
    'body.write', 'context.compare', 'readback.material', 'readback.compose_type', 'readback.quote', 'readback.author']);
const originalCodes = [...U2E_REPORT_CODES, 'UNAVAILABLE', 'OFFICE_WRITE_FAILED', 'MAILBOX_BINDING_INVALID',
    'SENDER_API_UNAVAILABLE', 'SENDER_READ_FAILED', 'MAILBOX_MISMATCH', 'SENDER_MISMATCH'];
const phases = ['preflight', 'claim', 'header', 'write', 'readback', 'complete'];
const knownPoint = point => U2E_READBACK_CHECKPOINTS.includes(point) || U2E_REPORT_STEPS.includes(point);
const localDedupe = new WeakMap();
const current = (office, item) => Boolean(item && office?.context?.mailbox?.item === item);

function io(office, item, method, key, value) {
    return new Promise(resolve => {
        let settled = false;
        const finish = result => { if (!settled) { settled = true; clearTimeout(timer); resolve(result); } };
        const timer = setTimeout(() => finish(null), 500);
        if (!current(office, item)) return finish(null);
        try {
            const callback = result => finish(current(office, item) && office?.AsyncResultStatus?.Succeeded !== undefined
                && result && result.status === office.AsyncResultStatus.Succeeded
                ? { value: result.value } : null);
            if (method === 'getAsync') item.sessionData.getAsync(key, callback);
            else item.sessionData.setAsync(key, value, callback);
        } catch { finish(null); }
    });
}

function safeReport(report) {
    if (!report || !['combined', 'signature'].includes(report.kind) || !U2E_REPORT_CODES.includes(report.code)
        || !phases.includes(report.phase) || !U2E_REPORT_STEPS.includes(report.step)
        || typeof report.bodyWriteStarted !== 'boolean' || typeof report.bodyWriteSucceeded !== 'boolean'
        || report.bodyWriteSucceeded && !report.bodyWriteStarted
        || typeof report.at !== 'string' || report.at.length !== 24 || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(report.at)
        || !Number.isFinite(Date.parse(report.at))) return null;
    return { schema: 1, state: 'uncertain', kind: report.kind, code: report.code,
        originalCode: originalCodes.includes(report.originalCode) ? report.originalCode : 'UNAVAILABLE',
        checkpoint: U2E_READBACK_CHECKPOINTS.includes(report.checkpoint) ? report.checkpoint : null,
        step: report.step, phase: report.phase, at: report.at,
        bodyWriteStarted: report.bodyWriteStarted, bodyWriteSucceeded: report.bodyWriteSucceeded };
}

export async function persistUniversalSessionReport(office, item, claim, report) {
    const safe = safeReport(report);
    if (!safe || !validPending(claim) || !current(office, item)) return false;
    const actual = await io(office, item, 'getAsync', composeKey);
    if (actual?.value !== claim) return false;
    // The lock is never modified, including on timeout or a failed report write.
    return Boolean(await io(office, item, 'setAsync', UNIVERSAL_REPORT_SESSION_KEY, JSON.stringify({ ...safe, claim })));
}

export async function readUniversalSessionReport(office, item) {
    const before = await io(office, item, 'getAsync', composeKey);
    if (!validPending(before?.value)) return null;
    const stored = await io(office, item, 'getAsync', UNIVERSAL_REPORT_SESSION_KEY);
    try {
        if (typeof stored?.value !== 'string' || stored.value.length > 2048) return null;
        const report = JSON.parse(stored.value);
        if (report.schema !== 1 || report.claim !== before.value) return null;
        const safe = safeReport(report);
        if (!safe) return null;
        const after = await io(office, item, 'getAsync', composeKey);
        return current(office, item) && after?.value === before.value ? safe : null;
    } catch { return null; }
}

/** Reserve one diagnostic per draft/code/checkpoint before sending. Session data
 * survives taskpane reloads; the WeakMap also covers an unavailable session API. */
export async function claimUniversalDiagnostic(office, item, code, checkpoint, step) {
    if (!current(office, item) || !U2E_REPORT_CODES.includes(code)) return false;
    const point = knownPoint(checkpoint) ? checkpoint : knownPoint(step) ? step : 'readback.unknown';
    const key = code + ':' + point;
    const local = localDedupe.get(item) || new Set();
    if (local.has(key)) return false;
    local.add(key); localDedupe.set(item, local);
    const stored = await io(office, item, 'getAsync', dedupeKey);
    let keys = [];
    try {
        if (typeof stored?.value === 'string' && stored.value.length <= 6000) {
            const parsed = JSON.parse(stored.value);
            if (parsed?.schema === 1 && Array.isArray(parsed.keys) && parsed.keys.length <= 40) {
                keys = parsed.keys.filter(entry => typeof entry === 'string' && U2E_REPORT_CODES.some(c =>
                    entry.startsWith(c + ':') && knownPoint(entry.slice(c.length + 1))));
            }
        }
    } catch { /* Invalid metadata cannot authorize a body write or expose content. */ }
    if (keys.includes(key)) return false;
    await io(office, item, 'setAsync', dedupeKey, JSON.stringify({ schema: 1, keys: [...keys, key].slice(-40) }));
    return current(office, item);
}

// A random per-draft pending nonce permits server-side deduplication across
// concurrent runtimes. An applied version alone is shared by many messages and
// must never be used as a draft identity. No claim or address leaves the client.
export async function universalDiagnosticId(office, item, code, checkpoint, step) {
    if (!U2E_REPORT_CODES.includes(code)) return null;
    const claim = await io(office, item, 'getAsync', composeKey);
    if (!current(office, item) || !validPending(claim?.value)) return null;
    const point = knownPoint(checkpoint) ? checkpoint : knownPoint(step) ? step : 'readback.unknown';
    return u2eDiagnosticHash(claim.value + '|' + code + '|' + point);
}
