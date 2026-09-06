// The signed-in profile is not the message's From address. Read the Compose
// From API again immediately before every mutation; never infer an alias.
export const MAILBOX_GUARD_TIMEOUT_MS = 10000;

const MESSAGES = Object.freeze({
    MAILBOX_BINDING_INVALID: 'Die Postfachfreigabe fehlt oder ist ungueltig. Bitte neu verbinden.',
    MAILBOX_MISSING: 'Das geoeffnete Outlook-Postfach konnte nicht sicher ermittelt werden.',
    MAILBOX_MISMATCH: 'Die Freigabe gehoert nicht zum aktuell geoeffneten Outlook-Postfach.',
    COMPOSE_ITEM_CHANGED: 'Der geoeffnete Entwurf hat sich geaendert. Bitte erneut versuchen.',
    SENDER_API_UNAVAILABLE: 'Outlook stellt den aktuellen Absender nicht sicher bereit.',
    SENDER_READ_FAILED: 'Der aktuelle Absender konnte nicht sicher gelesen werden.',
    SENDER_READ_TIMEOUT: 'Outlook hat den aktuellen Absender nicht rechtzeitig bestaetigt.',
    SENDER_MISSING: 'Im geoeffneten Entwurf fehlt ein eindeutiger Absender.',
    SENDER_MISMATCH: 'Der aktuelle Absender stimmt nicht mit der Postfachfreigabe ueberein.',
});

export class MailboxGuardError extends Error {
    constructor(code) {
        super(MESSAGES[code] || MESSAGES.MAILBOX_BINDING_INVALID);
        this.name = 'MailboxGuardError';
        this.code = code;
    }
}

function normalizeAddress(value, code) {
    // Only normalize case and surrounding spaces. No display-name parsing,
    // mailto stripping, plus-address removal, wildcard or domain matching.
    if (typeof value !== 'string' || /[\u0000-\u001f\u007f]/.test(value)) {
        throw new MailboxGuardError(code);
    }
    const address = value.trim().toLowerCase();
    if (address.length > 254 || !/^[^\s@<>,;:"\\()[\]*]+@[^\s@<>,;:"\\()[\]*]+$/u.test(address)) {
        throw new MailboxGuardError(code);
    }
    return address;
}

function assertCurrentItem(office, item) {
    if (!item || typeof item !== 'object' || office?.context?.mailbox?.item !== item) {
        throw new MailboxGuardError('COMPOSE_ITEM_CHANGED');
    }
}

function currentMailbox(office) {
    return normalizeAddress(office?.context?.mailbox?.userProfile?.emailAddress, 'MAILBOX_MISSING');
}

function bindingSnapshot(binding) {
    if (!binding || typeof binding !== 'object' || binding.schema !== 1
        || !Array.isArray(binding.allowedSenderAddresses) || binding.allowedSenderAddresses.length === 0) {
        throw new MailboxGuardError('MAILBOX_BINDING_INVALID');
    }
    const mailboxAddress = normalizeAddress(binding.mailboxAddress, 'MAILBOX_BINDING_INVALID');
    const senderAddress = normalizeAddress(binding.senderAddress, 'MAILBOX_BINDING_INVALID');
    const allowedSenders = new Set();
    for (const address of binding.allowedSenderAddresses) {
        allowedSenders.add(normalizeAddress(address, 'MAILBOX_BINDING_INVALID'));
    }
    if (!allowedSenders.has(senderAddress)) {
        throw new MailboxGuardError('MAILBOX_BINDING_INVALID');
    }
    return Object.freeze({ mailboxAddress, senderAddress });
}

/** Read-only, fail-closed Compose From lookup. Late/duplicate callbacks do nothing. */
export async function readComposeSender(office, item, options = {}) {
    assertCurrentItem(office, item);
    const succeeded = office?.AsyncResultStatus?.Succeeded;
    if (typeof item.from?.getAsync !== 'function' || typeof succeeded !== 'string' || succeeded === '') {
        throw new MailboxGuardError('SENDER_API_UNAVAILABLE');
    }
    const timeoutMs = Number.isFinite(options?.timeoutMs) && options.timeoutMs > 0
        ? Math.min(options.timeoutMs, MAILBOX_GUARD_TIMEOUT_MS) : MAILBOX_GUARD_TIMEOUT_MS;

    return new Promise((resolve, reject) => {
        let settled = false;
        const finish = (error, address) => {
            if (settled) return;
            settled = true;
            clearTimeout(timeout);
            if (error) reject(error);
            else resolve(address);
        };
        const timeout = setTimeout(() => finish(new MailboxGuardError('SENDER_READ_TIMEOUT')), timeoutMs);
        try {
            item.from.getAsync((result) => {
                if (settled) return;
                try {
                    assertCurrentItem(office, item);
                    if (result?.status !== succeeded) {
                        throw new MailboxGuardError('SENDER_READ_FAILED');
                    }
                    finish(null, normalizeAddress(result.value?.emailAddress, 'SENDER_MISSING'));
                } catch (error) {
                    finish(error instanceof MailboxGuardError ? error : new MailboxGuardError('SENDER_READ_FAILED'));
                }
            });
        } catch {
            finish(new MailboxGuardError('SENDER_READ_FAILED'));
        }
    });
}

/**
 * Assert a server-issued binding against this item and its freshly read sender.
 * The return value describes this check only: it must not be cached as approval
 * for later writes. Office.js offers no atomic From-check-and-write operation.
 */
export async function assertMailboxBinding(office, item, binding, options = {}) {
    const expected = bindingSnapshot(binding);
    assertCurrentItem(office, item);
    if (currentMailbox(office) !== expected.mailboxAddress) {
        throw new MailboxGuardError('MAILBOX_MISMATCH');
    }

    const senderAddress = await readComposeSender(office, item, options);
    assertCurrentItem(office, item);
    if (currentMailbox(office) !== expected.mailboxAddress) {
        throw new MailboxGuardError('MAILBOX_MISMATCH');
    }
    if (senderAddress !== expected.senderAddress) {
        throw new MailboxGuardError('SENDER_MISMATCH');
    }

    return Object.freeze({ item, senderAddress, mailboxAddress: expected.mailboxAddress });
}
