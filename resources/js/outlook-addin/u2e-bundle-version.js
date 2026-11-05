// Capability handshake only. Personal source hashes are never bundle revisions.
import { u2eDiagnosticHash } from './u2e-readback-diagnostics.js';
export const U2E_READER_CONTRACTS = Object.freeze(typeof __RAILTIME_OUTLOOK_READER_CONTRACTS__ !== 'undefined'
    ? __RAILTIME_OUTLOOK_READER_CONTRACTS__ : [1]);
export const U2E_SOURCE_TEMPLATE = typeof __RAILTIME_OUTLOOK_SOURCE_TEMPLATE__ !== 'undefined'
    ? __RAILTIME_OUTLOOK_SOURCE_TEMPLATE__ : '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204';
const failures = new WeakMap();
const hash = value => typeof value === 'string' && value.length === 64 && /^[0-9a-f]{64}$/.test(value);
export const U2E_BUNDLE_SESSION_KEY = 'railtime.u2e.bundle.v1';

export function assertUniversalBundleCompatibility(payload, readers = U2E_READER_CONTRACTS) {
    const universal = entry => entry && (Object.hasOwn(entry, 'universalSignature') || Object.hasOwn(entry, 'universalCompose'));
    const hasSource = payload && (universal(payload) || universal(payload.template)
        || Array.isArray(payload.templates) && payload.templates.some(universal));
    if (!hasSource || !Object.hasOwn(payload, 'addinBuild') || payload.addinBuild === null) return;
    const build = payload.addinBuild;
    if (build?.schema !== 1 || !hash(build.revision) || !hash(build.sourceTemplate) || !hash(build.bundles?.runtime) || !hash(build.bundles?.taskpane)
        || !Number.isSafeInteger(build.sourceContract) || build.sourceContract < 1 || build.sourceContract > 1000000) {
        throw Object.assign(new Error('U2E_DOCUMENT_INVALID'), { code: 'U2E_DOCUMENT_INVALID' });
    }
    if (readers.includes(build.sourceContract) && build.sourceTemplate === U2E_SOURCE_TEMPLATE) return;
    const error = Object.assign(new Error('U2E_BUNDLE_STALE'), { code: 'U2E_BUNDLE_STALE' });
    failures.set(error, Object.freeze({ code: error.code, originalCode: error.code, phase: 'preflight', step: 'bundle.contract',
        sourceContract: build.sourceContract, readerContract: Math.max(...readers),
        bodyWriteStarted: false, bodyWriteSucceeded: false, at: new Date().toISOString(),
        checkpoint: 'bundle.contract', difference: Object.freeze(readers.includes(build.sourceContract)
            ? { key: 'bundle.template', index: null, expected: 'sha256:' + u2eDiagnosticHash(build.sourceTemplate) + ':64',
                actual: 'sha256:' + u2eDiagnosticHash(U2E_SOURCE_TEMPLATE) + ':64' }
            : { key: 'bundle.contract', index: null, expected: build.sourceContract, actual: Math.max(...readers) }) }));
    throw error;
}

export function universalBundleFailureReport(error) { return failures.get(error) || null; }

function bundleSessionCall(office, item, method, value) {
    return new Promise(resolve => {
        const current = () => Boolean(item && office?.context?.mailbox?.item === item);
        try {
            if (!current() || typeof item.sessionData?.[method] !== 'function') return resolve({ readable: false, value: null });
        } catch { return resolve({ readable: false, value: null }); }
        let done = false;
        const finish = result => { if (!done) { done = true; clearTimeout(timer); resolve(result); } };
        const timer = setTimeout(() => finish({ readable: false, value: null }), 500);
        const callback = result => finish(current() && result?.status === office?.AsyncResultStatus?.Succeeded
            ? { readable: true, value: result.value } : { readable: false, value: null });
        try {
            if (method === 'getAsync') item.sessionData.getAsync(U2E_BUNDLE_SESSION_KEY, callback);
            else item.sessionData.setAsync(U2E_BUNDLE_SESSION_KEY, value, callback);
        } catch { finish({ readable: false, value: null }); }
    });
}

function bundleSessionReport(value) {
    if (!value || typeof value !== 'object' || Object.keys(value).length !== 5
        || !['schema', 'code', 'at', 'sourceContract', 'readerContract'].every(key => Object.hasOwn(value, key))
        || value.schema !== 1 || value.code !== 'U2E_BUNDLE_STALE'
        || !Number.isSafeInteger(value.sourceContract) || value.sourceContract < 1 || value.sourceContract > 1000000
        || !Number.isSafeInteger(value.readerContract) || value.readerContract < 1 || value.readerContract > 1000000
        || typeof value.at !== 'string' || value.at.length !== 24
        || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(value.at) || !Number.isFinite(Date.parse(value.at))) return null;
    return Object.freeze({ schema: 1, code: value.code, at: value.at,
        sourceContract: value.sourceContract, readerContract: value.readerContract });
}

/** Diagnostic only; never claims a body write or changes the compose ledger. */
export async function persistUniversalBundleFailure(office, item, error) {
    const evidence = failures.get(error);
    if (!evidence) return false;
    const report = bundleSessionReport({ schema: 1, code: evidence.code, at: evidence.at,
        sourceContract: evidence.sourceContract, readerContract: evidence.readerContract });
    return report ? (await bundleSessionCall(office, item, 'setAsync', JSON.stringify(report))).readable : false;
}

export async function readUniversalBundleFailure(office, item) {
    const result = await bundleSessionCall(office, item, 'getAsync');
    let report = null;
    try { if (typeof result.value === 'string' && result.value.length <= 512) report = bundleSessionReport(JSON.parse(result.value)); }
    catch { /* Unknown session bytes are never copied into diagnostics or UI. */ }
    const readable = result.readable && (report !== null || result.value === '' || result.value == null);
    return Object.freeze({ readable, report });
}

/** Caller first proves its current reader accepts the source. Only our valid marker is cleared. */
export async function clearUniversalBundleFailure(office, item) {
    const stored = await readUniversalBundleFailure(office, item);
    return stored.report ? (await bundleSessionCall(office, item, 'setAsync', '')).readable : false;
}
