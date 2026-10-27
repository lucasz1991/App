const KNOWN_COMPOSE_TYPES = new Set(['newMail', 'reply', 'forward']);

/** Read only the documented Office compose API; never infer from mail content. */
export function readAutomaticComposeType(office, item, { timeoutMs = 5000 } = {}) {
    const context = office?.context;
    const diagnostics = context?.mailbox?.diagnostics;
    const mobile = ['ios', 'android'].includes(String(context?.platform || '').toLowerCase())
        || /^(?:outlook)?(?:ios|android)$/i.test(String(diagnostics?.hostName || ''));
    if (typeof item?.getComposeTypeAsync !== 'function') return Promise.resolve('unknown');
    if (mobile) {
        const version = /^(\d+)\.(\d+)\.(\d+)(?:\.\d+)?$/.exec(String(diagnostics?.hostVersion || ''));
        const parts = version ? version.slice(1, 4).map(Number) : [];
        // getComposeTypeAsync is a Mailbox1.10 mobile exception from4.2352.0;
        // mobile's desktop requirement-set checker cannot establish support.
        if (!/^(?:outlookios|outlookandroid)$/i.test(String(diagnostics?.hostName || ''))
            || !version || !parts.every(Number.isSafeInteger)
            || parts[0] < 4 || (parts[0] === 4 && parts[1] < 2352)) return Promise.resolve('unknown');
    } else {
        try {
            if (context?.requirements?.isSetSupported?.('Mailbox', '1.10') !== true) return Promise.resolve('unknown');
        } catch { return Promise.resolve('unknown'); }
    }
    return new Promise(resolve => {
        let finished = false;
        const finish = value => {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            resolve(value);
        };
        const timer = setTimeout(() => finish('unknown'), timeoutMs);
        try {
            item.getComposeTypeAsync(result => {
                const success = office?.AsyncResultStatus?.Succeeded;
                const value = result?.value?.composeType;
                finish(typeof success === 'string' && result?.status === success && KNOWN_COMPOSE_TYPES.has(value)
                    ? value : 'unknown');
            });
        } catch { finish('unknown'); }
    });
}

/** Only a fresh matching v2 server decision authorizes automatic writes. */
export function automaticComposeDecision(payload, composeType, { requireTemplateChoice = false } = {}) {
    const policy = payload?.automaticInsertion;
    const none = reason => Object.freeze({ mode: 'none', signature: false, template: false, composeType: 'unknown', reason });
    if (!KNOWN_COMPOSE_TYPES.has(composeType) || policy?.schema !== 2
        || policy.composeType !== composeType || typeof policy.signature !== 'boolean' || typeof policy.template !== 'boolean'
        || typeof payload?.automaticInsertionEnabled !== 'boolean'
        || payload.automaticInsertionEnabled !== (policy.signature || policy.template)) return none('AUTOMATIC_POLICY_UNCONFIRMED');
    if (requireTemplateChoice) {
        const choice = payload?.automaticTemplateChoice;
        const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
        if (choice?.schema !== 1 || choice.composeType !== composeType
            || !['default', 'none'].includes(choice.selection) && !uuid.test(choice.selection || '')
            || policy.template && (choice.selection === 'none' || !uuid.test(choice.templateId || '')
                || choice.templateId !== payload.automaticTemplateId
                || choice.selection !== 'default' && choice.selection !== choice.templateId
                || !Array.isArray(payload.templates)
                || payload.templates.filter(entry => entry?.id === choice.templateId && entry.isDefault === true).length !== 1)
            || !policy.template && choice.templateId !== null) return none('AUTOMATIC_POLICY_UNCONFIRMED');
    }
    return Object.freeze({
        mode: policy.signature ? (policy.template ? 'both' : 'signature') : (policy.template ? 'template' : 'none'),
        signature: policy.signature, template: policy.template, composeType,
        reason: policy.signature || policy.template ? 'AUTOMATIC_POLICY_CONFIRMED' : 'AUTOMATIC_INSERTION_DISABLED',
    });
}

/** Event v2 template-only payloads intentionally have no legacy full html. */
export function automaticTemplateOnly(payload) {
    const id = typeof payload?.automaticTemplateId === 'string' ? payload.automaticTemplateId : '';
    if (!id || !Array.isArray(payload?.templates)) return null;
    const matches = payload.templates.filter(entry => entry?.isDefault === true && (entry.id === id || entry.key === id));
    return matches.length === 1 ? matches[0] : null;
}
