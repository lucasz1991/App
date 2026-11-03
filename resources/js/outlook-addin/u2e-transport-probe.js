import { assertMailboxBinding } from './mailbox-guard.js';
import { confirmedOfficeWrite, hasUncertainWrite } from './office-write.js';
import { isTemplateInsertionBlocked, isStandaloneSignatureInsertionBlocked } from './compose-template.js';
import { createNativeLayoutNonce } from './native-layout-restore.js';
import { parseManagedHtml } from './managed-template.js';
import { U2E_PROBE_SUBJECT, U2E_PROBE_MAILBOX, U2E_PROBE_SESSION_KEY,
    u2eProbeContextAvailable, u2eProbeMethod, u2eProbeDocument, inspectU2eProbe } from './u2e-editor-probe.js';

// A diagnostic only. Nothing in the automatic compose runtime calls this.
// It never clears a signature, replaces a body, attaches files or sends mail.
const operations = new WeakMap();
const ordinarySessionKeys = ['railtime.template.inserted.v1', 'railtime.signature.layout.v1', 'railtime.head-probe.v1'];
const fail = (code, phase = 'u2e-probe-preflight') => Object.assign(new Error(code), { code, phase });

export function u2eTransportProbeReport(item) {
    const operation = item && operations.get(item);
    return operation ? { ...operation.report } : null;
}

function readCall(office, invoke, code = 'U2E_PROBE_TARGET_UNREADABLE') {
    return new Promise((resolve, reject) => {
        let settled = false;
        const finish = (error, value) => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            if (error) reject(error); else resolve(value);
        };
        const timer = setTimeout(() => finish(fail(code)), 10000);
        try {
            invoke(result => {
                if (result?.status === office.AsyncResultStatus.Succeeded) finish(null, result.value);
                else finish(Object.assign(fail(code), { officeCode: String(result?.error?.code || '') }));
            });
        } catch { finish(fail(code)); }
    });
}

async function sessionValue(office, item, key) {
    try {
        const value = await readCall(office, callback => item.sessionData.getAsync(key, callback), 'U2E_PROBE_SESSION_UNAVAILABLE');
        if (value === null || value === undefined) return '';
        if (typeof value !== 'string') throw fail('U2E_PROBE_SESSION_UNAVAILABLE');
        return value;
    } catch (error) {
        if (error?.officeCode === '9050') return '';
        throw error;
    }
}

export function emptyU2eProbeBody(html) {
    if (typeof html !== 'string' || html.length > 100000) return false;
    try {
        const parsed = parseManagedHtml(html);
        const elements = parsed.elements;
        const heads = elements.filter(node => node.tag === 'head');
        if (heads.length > 1 || elements.some(node => ['script', 'iframe', 'object', 'embed', 'input', 'textarea'].includes(node.tag))) return false;
        const inHead = node => heads.some(head => node.start >= head.start && node.end <= head.end);
        if (elements.some(node => !inHead(node) && !['html', 'body', 'div', 'p', 'span', 'font', 'br', 'o:p'].includes(node.tag))) return false;
        if (parsed.comments.some(comment => !heads.some(head => comment.start >= head.start && comment.end <= head.end))) return false;
        let remaining = html;
        for (const head of [...heads].sort((a, b) => b.start - a.start)) remaining = remaining.slice(0, head.start) + remaining.slice(head.end);
        return remaining.replace(/<!doctype\b[^>]*>|<\?[^>]*\?>|<[^>]*>/gi, '')
            .replace(/&nbsp;|&#160;|&#x0*a0;/gi, ' ').trim() === '';
    } catch { return false; }
}

async function target(office, item, binding, beforeWrite, method) {
    await beforeWrite();
    await assertMailboxBinding(office, item, binding);
    if (!u2eProbeContextAvailable(office, binding, method)
        || ![item.subject, item.to, item.cc, item.bcc].every(value => typeof value?.getAsync === 'function')
        || typeof item.getAttachmentsAsync !== 'function' || typeof item.getComposeTypeAsync !== 'function'
        || typeof item.body.getTypeAsync !== 'function' || typeof item.body.getAsync !== 'function') throw fail('U2E_PROBE_NOT_ELIGIBLE');
    const subject = await readCall(office, cb => item.subject.getAsync(cb));
    const to = await readCall(office, cb => item.to.getAsync(cb));
    const cc = await readCall(office, cb => item.cc.getAsync(cb));
    const bcc = await readCall(office, cb => item.bcc.getAsync(cb));
    const attachments = await readCall(office, cb => item.getAttachmentsAsync(cb));
    const compose = await readCall(office, cb => item.getComposeTypeAsync(cb));
    const format = await readCall(office, cb => item.body.getTypeAsync(cb));
    if (subject !== U2E_PROBE_SUBJECT || !Array.isArray(to) || to.length !== 1
        || String(to[0]?.emailAddress || '').trim().toLowerCase() !== U2E_PROBE_MAILBOX
        || !Array.isArray(cc) || cc.length || !Array.isArray(bcc) || bcc.length
        || !Array.isArray(attachments) || attachments.length || compose?.composeType !== 'newMail'
        || format !== office.CoercionType.Html) throw fail('U2E_PROBE_NOT_ELIGIBLE');
    await beforeWrite();
    await assertMailboxBinding(office, item, binding);
}

async function ordinarySessionsSettled(office, item) {
    for (const key of ordinarySessionKeys) {
        if (!['', '1'].includes(await sessionValue(office, item, key))) throw fail('U2E_PROBE_ALREADY_ATTEMPTED');
    }
    if (isTemplateInsertionBlocked(item) || isStandaloneSignatureInsertionBlocked(item) || hasUncertainWrite(item)) throw fail('U2E_PROBE_ALREADY_ATTEMPTED');
}

/** One Office.js write to a positively empty technical message. No arbitrary
 * markup or method arguments: platform determines the permitted API. */
export async function insertU2eTransportProbe(office, item, { binding, beforeWrite = () => {} } = {}) {
    if (!item || operations.has(item) || hasUncertainWrite(item) || isTemplateInsertionBlocked(item)
        || isStandaloneSignatureInsertionBlocked(item)) throw fail('U2E_PROBE_ALREADY_ATTEMPTED');
    const method = u2eProbeMethod(office);
    if (!u2eProbeContextAvailable(office, binding, method) || typeof item?.sessionData?.getAsync !== 'function'
        || typeof item?.sessionData?.setAsync !== 'function') throw fail('U2E_PROBE_NOT_ELIGIBLE');
    const operation = { claimed: false, writing: false, report: { schema: 1, attempted: false, state: 'preflight', method } };
    operations.set(item, operation);
    try {
        await target(office, item, binding, beforeWrite, method);
        await ordinarySessionsSettled(office, item);
        if (await sessionValue(office, item, U2E_PROBE_SESSION_KEY) !== '') throw fail('U2E_PROBE_ALREADY_ATTEMPTED');
        const original = await readCall(office, cb => item.body.getAsync(office.CoercionType.Html, cb));
        if (!emptyU2eProbeBody(original)) throw fail('U2E_PROBE_BODY_NOT_EMPTY');
        const probe = u2eProbeDocument();
        if (probe.html.length > 27000 || !inspectU2eProbe(probe.html).confirmed) throw fail('U2E_PROBE_SOURCE_INVALID');
        const token = 'pending:u2e:' + createNativeLayoutNonce();
        await beforeWrite();
        await assertMailboxBinding(office, item, binding);
        if (office?.context?.mailbox?.item !== item) throw fail('U2E_PROBE_CONTEXT_CHANGED');
        // Claim is conservative: a lost bridge callback must never allow retry.
        operation.claimed = true;
        operation.report = { ...operation.report, attempted: true, state: 'pending' };
        await readCall(office, cb => item.sessionData.setAsync(U2E_PROBE_SESSION_KEY, token, cb), 'U2E_PROBE_UNCONFIRMED');
        if (await sessionValue(office, item, U2E_PROBE_SESSION_KEY) !== token) throw fail('U2E_PROBE_UNCONFIRMED');
        await target(office, item, binding, beforeWrite, method);
        await ordinarySessionsSettled(office, item);
        const latest = await readCall(office, cb => item.body.getAsync(office.CoercionType.Html, cb));
        if (latest !== original || !emptyU2eProbeBody(latest)) throw fail('U2E_PROBE_CONTEXT_CHANGED');
        if (await sessionValue(office, item, U2E_PROBE_SESSION_KEY) !== token) throw fail('U2E_PROBE_UNCONFIRMED');
        // The asynchronous claim read may outlive a context change. Bind again
        // after it; nothing asynchronous may separate that check from writing.
        await beforeWrite();
        await assertMailboxBinding(office, item, binding);
        if (office?.context?.mailbox?.item !== item) throw fail('U2E_PROBE_CONTEXT_CHANGED');
        operation.writing = true;
        await confirmedOfficeWrite(office, item, 'u2e-probe-write', 'U2E_PROBE_UNCONFIRMED',
            cb => item.body[method](probe.html, { coercionType: office.CoercionType.Html }, cb), { deferSignatureConfirmation: true });
        await target(office, item, binding, beforeWrite, method);
        const readback = await readCall(office, cb => item.body.getAsync(office.CoercionType.Html, cb), 'SIGNATURE_LAYOUT_UNCONFIRMED');
        const inspected = inspectU2eProbe(readback);
        operation.report = { ...operation.report, ...inspected.report, state: 'pending', method };
        if (!inspected.confirmed || await sessionValue(office, item, U2E_PROBE_SESSION_KEY) !== token) throw fail('SIGNATURE_LAYOUT_UNCONFIRMED', 'u2e-probe-readback');
        await beforeWrite();
        await assertMailboxBinding(office, item, binding);
        if (office?.context?.mailbox?.item !== item) throw fail('U2E_PROBE_CONTEXT_CHANGED');
        await readCall(office, cb => item.sessionData.setAsync(U2E_PROBE_SESSION_KEY, '1', cb), 'U2E_PROBE_UNCONFIRMED');
        if (await sessionValue(office, item, U2E_PROBE_SESSION_KEY) !== '1') throw fail('U2E_PROBE_UNCONFIRMED');
        await beforeWrite();
        await assertMailboxBinding(office, item, binding);
        operation.report = { ...operation.report, attempted: true, state: 'complete' };
        return { ...operation.report };
    } catch (error) {
        if (operation.claimed || operation.writing) {
            operation.report = { ...operation.report, attempted: true, state: 'uncertain' };
            if (error?.code === 'SIGNATURE_LAYOUT_UNCONFIRMED') throw error;
            throw fail('U2E_PROBE_UNCONFIRMED', error?.phase);
        }
        operations.delete(item);
        throw error;
    }
}
