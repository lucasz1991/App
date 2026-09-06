// Shared by the event runtime and taskpane. Never replace a compose body:
// Outlook may include the complete quoted conversation in body.getAsync().
export const TEMPLATE_MARKER = 'RT-TEMPLATE-MANAGED-V1';
const TEMPLATE_SESSION_KEY = 'railtime.template.inserted.v1';
const appliedItems = new WeakSet();

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

function officeRead(office, invoke) {
    return new Promise((resolve) => {
        try {
            invoke((result) => resolve(result?.status === office.AsyncResultStatus.Succeeded
                ? result.value : null));
        } catch {
            resolve(null);
        }
    });
}

export async function readTemplateState(office, item) {
    if (appliedItems.has(item)) return { present: true, readable: true };
    const session = typeof item?.sessionData?.getAsync === 'function'
        ? await officeRead(office, (callback) => item.sessionData.getAsync(TEMPLATE_SESSION_KEY, callback))
        : null;
    if (session === '1') return { present: true, readable: true };

    const [html, compose] = await Promise.all([
        officeRead(office, (callback) => item.body.getAsync(office.CoercionType.Html, callback)),
        officeRead(office, (callback) => item.getComposeTypeAsync(callback)),
    ]);
    if (typeof html !== 'string') return { present: false, readable: false };
    const current = currentComposeBodyHtml(html, compose?.composeType);
    // A marker from a quoted older mail is ignored only with a known boundary.
    // Ambiguous bodies stay conservative instead of duplicating a template.
    return {
        present: (current === null ? html : current).includes(TEMPLATE_MARKER),
        readable: true,
    };
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
    const state = await readTemplateState(office, item);
    if (state.present && !allowAdditional) throw failure('TEMPLATE_ALREADY_INSERTED');
    if (!state.readable) throw failure('COMPOSE_BODY_UNREADABLE');
    const format = await officeRead(office, (callback) => item.body.getTypeAsync(callback));
    if (format !== office.CoercionType.Html) throw failure('TEMPLATE_REQUIRES_HTML');
}

export async function prependTemplate(office, item, html, beforeWrite = () => {}, options = {}) {
    await assertTemplateInsertable(office, item, options);
    const markedHtml = markedTemplateHtml(html);
    if (markedHtml.length > 1000000) throw failure('TEMPLATE_TOO_LARGE');
    beforeWrite();
    await new Promise((resolve, reject) => {
        item.body.prependAsync(markedHtml, { coercionType: office.CoercionType.Html }, (result) => {
            if (result?.status !== office.AsyncResultStatus.Succeeded) {
                reject(failure(result?.error?.code || 'SET_TEMPLATE_FAILED'));
                return;
            }
            appliedItems.add(item);
            resolve();
        });
    });
    if (typeof item.sessionData?.setAsync === 'function') {
        // A session marker never contains mail text, identity data or tokens.
        await officeRead(office, (callback) => item.sessionData.setAsync(TEMPLATE_SESSION_KEY, '1', callback));
    }
}
