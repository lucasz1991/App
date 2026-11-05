// Authenticated operational metadata only. Never serialize Office HTML or errors.
import { U2E_READBACK_CHECKPOINTS, U2E_READBACK_DIFFERENCE_KEYS, validateUniversalRedactedFixture } from './u2e-readback-diagnostics.js';
import { claimUniversalDiagnostic, universalDiagnosticId } from './u2e-session-report.js';
const CODES = new Set(['U2E_BUNDLE_STALE', 'SIGNATURE_LAYOUT_UNCONFIRMED', 'U2E_INSERT_UNCERTAIN', 'U2E_NEW_DRAFT_REQUIRED',
    'U2E_SOURCE_INVALID', 'U2E_CONTEXT_UNREADABLE', 'U2E_API_UNAVAILABLE', 'U2E_HEADER_UNAVAILABLE',
    'U2E_DOCUMENT_INVALID', 'U2E_INSERT_IN_PROGRESS', 'U2E_SESSION_UNAVAILABLE', 'U2E_HTML_REQUIRED',
    'U2E_CONTEXT_CHANGED', 'COMPOSE_ITEM_CHANGED', 'U2E_OPERATION_FAILED', 'U2E_AUTOMATIC_SKIPPED']);
const PHASES = new Set(['preflight', 'claim', 'header', 'write', 'readback', 'complete']);
const OPERATION_FIELDS = Object.freeze({
    originalCode: new Set([...CODES, 'UNAVAILABLE', 'OFFICE_WRITE_FAILED', 'MAILBOX_BINDING_INVALID',
        'SENDER_API_UNAVAILABLE', 'SENDER_READ_FAILED', 'MAILBOX_MISMATCH', 'SENDER_MISMATCH']),
    step: new Set(['bundle.contract', 'binding', 'legacy', 'session.read', 'session.claim', 'session.finalize', 'session.verify',
        'snapshot.type', 'snapshot.format', 'snapshot.body', 'snapshot.region', 'source.state', 'header.write',
        'body.write', 'context.compare', 'readback.material', 'readback.compose_type', 'readback.quote', 'readback.author']),
    bodyLengthOrigin: new Set(['original', 'actual']),
    composeType: new Set(['newMail', 'reply', 'forward', 'unknown']),
    automaticReason: new Set(['compose_type_unknown', 'automatic_disabled', 'automatic_policy_unconfirmed',
        'prior_write_uncertain', 'universal_source_unavailable', 'template_only_policy']),
});
const LIMIT = 1000000;
const MAX_JSON_BYTES = 252000;
const KEEPALIVE_BYTES = 60000;

function length(value) {
    return Number.isSafeInteger(value) && value >= 0 && value <= LIMIT ? value : null;
}

export function universalComposeHost(office = globalThis.Office) {
    const host = office?.context?.mailbox?.diagnostics?.hostName || office?.context?.diagnostics?.hostName;
    const platform = office?.context?.platform;
    if (platform === 'iOS' || platform === 'OutlookIOS') return 'OutlookIOS';
    if (platform === 'Android' || platform === 'OutlookAndroid') return 'OutlookAndroid';
    if (host === 'OutlookWebApp' || platform === 'OfficeOnline') return 'OutlookWebApp';
    return host === 'Outlook' || host === 'OutlookIOS' || host === 'OutlookAndroid' ? host : 'unknown';
}

function safeAtom(value) {
    return value === null || typeof value === 'boolean' || Number.isSafeInteger(value) && Math.abs(value) <= LIMIT
        || typeof value === 'string' && /^sha256:[0-9a-f]{64}:(0|[1-9][0-9]{0,6})$/.test(value) && Number(value.slice(72)) <= LIMIT;
}

export function universalFailurePayload(error, report = {}, sourceLength = null, allowFixture = false) {
    if (!error) return null;
    const code = CODES.has(error?.code) ? error.code : 'U2E_OPERATION_FAILED';
    // A report from an earlier operation cannot describe this failure.
    const current = report?.code === code;
    const payload = { schema: 1, host: universalComposeHost(), code,
        phase: current && PHASES.has(report?.phase) ? report.phase : 'preflight',
        sourceLength: length(sourceLength) ?? (current ? length(report?.sourceLength) : null),
        bodyLength: current ? length(report?.bodyLength) : null };
    if (current) {
        for (const [key, values] of Object.entries(OPERATION_FIELDS)) {
            if (values.has(report[key]) && (key !== 'automaticReason' || code === 'U2E_AUTOMATIC_SKIPPED')) payload[key] = report[key];
        }
        if (Object.hasOwn(report, 'originalCode') && !Object.hasOwn(payload, 'originalCode')) payload.originalCode = 'UNAVAILABLE';
    }
    if (current && U2E_READBACK_CHECKPOINTS.includes(report.checkpoint)) payload.checkpoint = report.checkpoint;
    const difference = current ? report.difference : null;
    if (difference && Object.keys(difference).length === 4 && ['key', 'index', 'expected', 'actual'].every(key => Object.hasOwn(difference, key))
        && U2E_READBACK_DIFFERENCE_KEYS.includes(difference.key)
        && (difference.index === null || Number.isSafeInteger(difference.index) && difference.index >= 0 && difference.index <= 4095)
        && safeAtom(difference.expected) && safeAtom(difference.actual)) payload.difference = Object.freeze({ ...difference });
    if (current && allowFixture && code === 'SIGNATURE_LAYOUT_UNCONFIRMED') {
        const fixture = validateUniversalRedactedFixture(report.fixture);
        if (fixture) payload.fixture = fixture;
    }
    // JSON escaping can make a valid HTML pair too large for this transport.
    if (JSON.stringify(payload).length > MAX_JSON_BYTES) delete payload.fixture;
    return Object.freeze(payload);
}

export async function reportUniversalFailure({ config, accessToken, item, binding, context, error, report, sourceLength, isCurrent } = {}) {
    let timer, controller;
    try {
        const allowFixture = binding?.mailboxAddress?.toLowerCase() === 'it-technik@rail-time.de';
        const payload = universalFailurePayload(error, report, sourceLength, allowFixture);
        if (payload?.code === 'U2E_AUTOMATIC_SKIPPED' && (!allowFixture || !payload.automaticReason)) return false;
        const endpoint = new URL(config?.endpoints?.diagnostics);
        const bootstrap = new URL(config?.endpoints?.bootstrap);
        const office = globalThis.Office;
        const current = () => office?.context?.mailbox?.item === item
            && (!isCurrent || isCurrent() === true)
            && typeof binding?.mailboxAddress === 'string' && typeof binding?.senderAddress === 'string'
            && String(office?.context?.mailbox?.userProfile?.emailAddress || '').toLowerCase() === binding.mailboxAddress.toLowerCase();
        if (!payload || !current() || !['event', 'taskpane'].includes(context)
            || typeof accessToken !== 'string' || accessToken === '' || accessToken.length > 32768
            || endpoint.protocol !== 'https:' || endpoint.origin !== bootstrap.origin) return false;
        if (!await claimUniversalDiagnostic(office, item, payload.code, payload.checkpoint, payload.step) || !current()) return false;
        const diagnosticId = await universalDiagnosticId(office, item, payload.code, payload.checkpoint, payload.step);
        if (!current()) return false;
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timeout = new Promise(resolve => { timer = setTimeout(() => { controller?.abort(); resolve(false); }, 2000); });
        const body = JSON.stringify(diagnosticId ? { ...payload, diagnosticId } : payload);
        const request = Promise.resolve().then(() => {
            if (!current()) return false;
            return fetch(endpoint.toString(), {
                method: 'POST', cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer',
                signal: controller?.signal, keepalive: body.length <= KEEPALIVE_BYTES,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${accessToken}`,
                    'X-RailTime-Compose-Contract': 'native-signature-v1', 'X-RailTime-Outlook-Context': context,
                    'X-RailTime-Outlook-Mailbox': binding.mailboxAddress, 'X-RailTime-Outlook-Sender': binding.senderAddress },
                body,
            }).then(response => response?.ok === true);
        }).catch(() => false);
        return await Promise.race([request, timeout]);
    } catch {
        return false;
    } finally {
        if (timer !== undefined) clearTimeout(timer);
    }
}
