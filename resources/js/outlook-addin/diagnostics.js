// Local only: never record addresses, tokens, message HTML, attachment names or media.
const events = [];
const safeWord = (value) => typeof value === 'string' && /^[a-z0-9_.-]{1,80}$/i.test(value) ? value : 'unknown';

export function recordDiagnostic(phase, outcome, error = null, elapsedMs = 0) {
    events.push({
        time: new Date().toISOString(), phase: safeWord(phase), outcome: safeWord(outcome),
        code: error ? safeWord(String(error.code || error.errorCode || error.name || 'unknown')) : null,
        officeCode: error?.officeCode ? safeWord(String(error.officeCode)) : null,
        reason: error?.reason ? safeWord(error.reason) : null,
        elapsedMs: Math.max(0, Math.round(Number(elapsedMs) || 0)),
    });
    if (events.length > 40) events.shift();
}

export async function diagnoseStep(phase, operation) {
    const start = Date.now();
    recordDiagnostic(phase, 'started');
    try {
        const value = await operation();
        recordDiagnostic(phase, 'succeeded', null, Date.now() - start);
        return value;
    } catch (error) {
        recordDiagnostic(error?.phase || phase, 'failed', error, Date.now() - start);
        throw error;
    }
}

export function diagnosticReport(office, state = {}) {
    const requirements = office?.context?.requirements;
    const supported = (version) => {
        try { return requirements?.isSetSupported?.('Mailbox', version) === true; } catch { return false; }
    };
    const item = office?.context?.mailbox?.item;
    return {
        schema: 1, clientRevision: 'mailbox-binding-20260906',
        generatedAt: new Date().toISOString(),
        host: safeWord(office?.context?.diagnostics?.host),
        platform: safeWord(office?.context?.diagnostics?.platform),
        version: safeWord(office?.context?.diagnostics?.version),
        capabilities: {
            mailbox17: supported('1.7'), mailbox110: supported('1.10'), mailbox111: supported('1.11'),
            fromRead: typeof item?.from?.getAsync === 'function',
            signature: typeof item?.body?.setSignatureAsync === 'function',
            template: typeof item?.body?.prependAsync === 'function',
        },
        checks: {
            configured: state.configured === true, authenticated: state.authenticated === true,
            boundMailbox: state.boundMailbox === true, bootstrapReady: state.bootstrapReady === true,
            automaticTemplate: state.automaticTemplate === true, writeBlocked: state.writeBlocked === true,
        },
        events: events.map((entry) => ({ ...entry })),
    };
}
