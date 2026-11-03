// Authenticated operational metadata only. Never serialize Office HTML or errors.
const CODES = new Set(['SIGNATURE_LAYOUT_UNCONFIRMED', 'U2E_INSERT_UNCERTAIN', 'U2E_NEW_DRAFT_REQUIRED',
    'U2E_SOURCE_INVALID', 'U2E_CONTEXT_UNREADABLE', 'U2E_API_UNAVAILABLE', 'U2E_HEADER_UNAVAILABLE',
    'U2E_DOCUMENT_INVALID', 'U2E_INSERT_IN_PROGRESS', 'U2E_SESSION_UNAVAILABLE', 'U2E_HTML_REQUIRED',
    'U2E_CONTEXT_CHANGED', 'COMPOSE_ITEM_CHANGED', 'U2E_OPERATION_FAILED']);
const PHASES = new Set(['preflight', 'claim', 'header', 'write', 'readback', 'complete']);
const LIMIT = 1000000;

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

export function universalFailurePayload(error, report = {}, sourceLength = null) {
    if (!error) return null;
    const code = CODES.has(error?.code) ? error.code : 'U2E_OPERATION_FAILED';
    // A report from an earlier operation cannot describe this failure.
    const current = report?.code === code;
    return Object.freeze({ schema: 1, host: universalComposeHost(), code,
        phase: current && PHASES.has(report?.phase) ? report.phase : 'preflight',
        sourceLength: length(sourceLength) ?? (current ? length(report?.sourceLength) : null),
        bodyLength: current ? length(report?.bodyLength) : null });
}

export async function reportUniversalFailure({ config, accessToken, item, binding, context, error, report, sourceLength, isCurrent } = {}) {
    let timer, controller;
    try {
        const payload = universalFailurePayload(error, report, sourceLength);
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
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timeout = new Promise(resolve => { timer = setTimeout(() => { controller?.abort(); resolve(false); }, 2000); });
        const request = Promise.resolve().then(() => {
            if (!current()) return false;
            return fetch(endpoint.toString(), {
                method: 'POST', cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer',
                signal: controller?.signal, keepalive: true,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${accessToken}`,
                    'X-RailTime-Compose-Contract': 'native-signature-v1', 'X-RailTime-Outlook-Context': context,
                    'X-RailTime-Outlook-Mailbox': binding.mailboxAddress, 'X-RailTime-Outlook-Sender': binding.senderAddress },
                body: JSON.stringify(payload),
            }).then(response => response?.ok === true);
        }).catch(() => false);
        return await Promise.race([request, timeout]);
    } catch {
        return false;
    } finally {
        if (timer !== undefined) clearTimeout(timer);
    }
}
