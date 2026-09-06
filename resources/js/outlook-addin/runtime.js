import {
    InteractionRequiredAuthError,
    createNestablePublicClientApplication,
} from '@azure/msal-browser';
import {
    automaticTemplate,
    isTemplateInsertionBlocked,
    prependTemplate,
    readTemplateState,
} from './compose-template.js';
import { readComposeSender, assertMailboxBinding } from './mailbox-guard.js';
import { diagnoseStep, recordDiagnostic } from './diagnostics.js';
import { confirmedOfficeWrite, hasUncertainWrite } from './office-write.js';

const CONFIG_META_NAME = 'railtime-outlook-config-url';
const CONFIG_TIMEOUT_MS = 8000;
const API_TIMEOUT_MS = 12000;
const LOG_PREFIX = '[RailTime Outlook Add-in]';

let configPromise;
let authenticationClientPromise;
const composeOperations = new WeakMap();

function codedError(code) {
    const error = new Error(code);
    error.code = code;

    return error;
}

function firstNonEmptyString(values) {
    for (let index = 0; index < values.length; index += 1) {
        const value = values[index];

        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }
    }

    return '';
}

function buildTimeConfigUrl() {
    if (typeof __RAILTIME_OUTLOOK_CONFIG_URL__ === 'string') {
        return __RAILTIME_OUTLOOK_CONFIG_URL__.trim();
    }

    return '';
}

function configuredUrl() {
    const documentConfigUrl = typeof document === 'undefined'
        ? ''
        : firstNonEmptyString([
            document.querySelector(`meta[name="${CONFIG_META_NAME}"]`)?.getAttribute('content'),
            document.documentElement?.dataset?.outlookConfigUrl,
            document.body?.dataset?.outlookConfigUrl,
        ]);
    const value = firstNonEmptyString([
        documentConfigUrl,
        globalThis.RAILTIME_OUTLOOK_CONFIG_URL,
        buildTimeConfigUrl(),
    ]);

    if (value === '') {
        throw codedError('CONFIG_URL_MISSING');
    }

    let url;

    try {
        url = new URL(value);
    } catch {
        throw codedError('CONFIG_URL_INVALID');
    }

    if (url.protocol !== 'https:') {
        throw codedError('CONFIG_URL_NOT_HTTPS');
    }

    return url.toString();
}

function requiredString(value, code) {
    if (typeof value !== 'string' || value.trim() === '') {
        throw codedError(code);
    }

    return value.trim();
}

function requiredHttpsUrl(value, code) {
    const stringValue = requiredString(value, code);
    let url;

    try {
        url = new URL(stringValue);
    } catch {
        throw codedError(code);
    }

    if (url.protocol !== 'https:') {
        throw codedError(code);
    }

    return url.toString();
}

function mailboxAddress() {
    const address = requiredString(
        globalThis.Office?.context?.mailbox?.userProfile?.emailAddress,
        'MAILBOX_ADDRESS_MISSING',
    ).toLowerCase();

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address)) {
        throw codedError('MAILBOX_ADDRESS_INVALID');
    }

    return address;
}

function validateConfig(payload) {
    if (!payload || typeof payload !== 'object' || payload.ready !== true) {
        throw codedError('CONFIG_NOT_READY');
    }

    const scopes = Array.isArray(payload.auth?.scopes)
        ? payload.auth.scopes.map((scope) => requiredString(scope, 'CONFIG_SCOPE_INVALID'))
        : [];

    if (scopes.length === 0) {
        throw codedError('CONFIG_SCOPES_MISSING');
    }

    return Object.freeze({
        ready: true,
        auth: Object.freeze({
            clientId: requiredString(payload.auth?.clientId, 'CONFIG_CLIENT_ID_MISSING'),
            authority: requiredHttpsUrl(payload.auth?.authority, 'CONFIG_AUTHORITY_INVALID'),
            scopes: Object.freeze(scopes),
        }),
        endpoints: Object.freeze({
            bootstrap: requiredHttpsUrl(payload.endpoints?.bootstrap, 'CONFIG_BOOTSTRAP_URL_INVALID'),
        }),
        marker: requiredString(payload.marker, 'CONFIG_MARKER_MISSING'),
    });
}

async function fetchJson(url, options, timeoutMs) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeout = controller === null
        ? null
        : setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(url, {
            ...options,
            signal: controller?.signal,
        });

        if (!response.ok) {
            throw codedError(`HTTP_${response.status}`);
        }

        return await response.json();
    } catch (error) {
        if (error?.name === 'AbortError') {
            throw codedError('REQUEST_TIMEOUT');
        }

        throw error;
    } finally {
        if (timeout !== null) {
            clearTimeout(timeout);
        }
    }
}

function loadConfig() {
    if (!configPromise) {
        configPromise = fetchJson(configuredUrl(), {
            method: 'GET',
            cache: 'no-store',
            credentials: 'omit',
            headers: {
                Accept: 'application/json',
            },
        }, CONFIG_TIMEOUT_MS).then(validateConfig);
    }

    return configPromise;
}

function supportsNestedAppAuthentication() {
    return Boolean(
        globalThis.Office?.context?.requirements?.isSetSupported
        && Office.context.requirements.isSetSupported('NestedAppAuth', '1.1'),
    );
}

function authenticationClient(config) {
    if (!supportsNestedAppAuthentication()) {
        throw codedError('NAA_NOT_SUPPORTED');
    }

    if (!authenticationClientPromise) {
        authenticationClientPromise = createNestablePublicClientApplication({
            auth: {
                clientId: config.auth.clientId,
                authority: config.auth.authority,
            },
            cache: {
                cacheLocation: 'memoryStorage',
            },
        });
    }

    return authenticationClientPromise;
}

async function acquireTokenSilently(config) {
    const client = await authenticationClient(config);
    const mailbox = mailboxAddress();
    const account = (client.getAllAccounts?.() || []).find(
        (candidate) => String(candidate?.username || '').trim().toLowerCase() === mailbox,
    ) || null;
    const request = {
        scopes: [...config.auth.scopes],
    };

    if (account) {
        request.account = account;
    } else {
        request.loginHint = mailbox;
    }

    try {
        const result = await client.acquireTokenSilent(request);

        return requiredString(result?.accessToken, 'ACCESS_TOKEN_MISSING');
    } catch (error) {
        if (error instanceof InteractionRequiredAuthError) {
            throw codedError('AUTH_INTERACTION_REQUIRED');
        }

        throw error;
    }
}

async function loadBootstrap(config, accessToken, item) {
    const sender = await readComposeSender(Office, item);
    const payload = await fetchJson(config.endpoints.bootstrap, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${accessToken}`,
            'X-RailTime-Outlook-Context': 'event',
            'X-RailTime-Outlook-Mailbox': mailboxAddress(),
            'X-RailTime-Outlook-Sender': sender,
        },
    }, API_TIMEOUT_MS);

    if (!payload || typeof payload !== 'object') {
        throw codedError('BOOTSTRAP_INVALID');
    }

    if (payload.marker !== config.marker) {
        throw codedError('BOOTSTRAP_MARKER_MISMATCH');
    }

    await assertMailboxBinding(Office, item, payload.binding);
    return payload;
}

function validatedMedia(media) {
    if (!Array.isArray(media)) {
        throw codedError('MEDIA_INVALID');
    }

    const attachmentNames = new Set();

    return media.map((entry) => {
        const name = requiredString(entry?.name, 'MEDIA_NAME_MISSING');
        const contentId = requiredString(entry?.contentId, 'MEDIA_CONTENT_ID_MISSING');
        let base64 = requiredString(entry?.base64, 'MEDIA_BASE64_MISSING');

        if (/[^\x20-\x7E]/.test(name) || /[\\/<>:"|?*]/.test(name)) {
            throw codedError('MEDIA_NAME_INVALID');
        }

        if (!/^[A-Za-z0-9._@+-]+$/.test(contentId)) {
            throw codedError('MEDIA_CONTENT_ID_INVALID');
        }

        if (base64.startsWith('data:')) {
            const comma = base64.indexOf(',');
            base64 = comma === -1 ? '' : base64.slice(comma + 1);
        }

        if (base64 === '' || !/^[A-Za-z0-9+/=\r\n]+$/.test(base64)) {
            throw codedError('MEDIA_BASE64_INVALID');
        }

        if (attachmentNames.has(name.toLowerCase())) {
            throw codedError('MEDIA_NAME_DUPLICATE');
        }

        attachmentNames.add(name.toLowerCase());

        return Object.freeze({ name, contentId, base64 });
    });
}

function markerComment(marker, kind) {
    const safeMarker = marker.replace(/--+/g, '-').replace(/[<>]/g, '').slice(0, 160);

    return `<!--${safeMarker}:${kind}-->`;
}

function validatedDocument(payload, kind, marker) {
    if (!payload || typeof payload !== 'object') {
        throw codedError(`${kind.toUpperCase()}_MISSING`);
    }

    let html = requiredString(payload.html, `${kind.toUpperCase()}_HTML_MISSING`);
    const media = validatedMedia(payload.media || []);

    for (let index = 0; index < media.length; index += 1) {
        const entry = media[index];
        html = html.split(`cid:${entry.contentId}`).join(`cid:${entry.name}`);
    }

    const markerValue = markerComment(marker, kind);

    if (!html.includes(marker) && !html.includes(markerValue)) {
        html += markerValue;
    }

    return Object.freeze({ html, media });
}

function officeFailure(result, fallbackCode) {
    if (result?.status === Office.AsyncResultStatus.Succeeded) {
        return null;
    }

    return codedError(result?.error?.code || fallbackCode);
}

function addInlineAttachment(item, media) {
    return confirmedOfficeWrite(Office, item, 'attachment-write', 'INLINE_ATTACHMENT_UNCERTAIN',
        (callback) => item.addFileAttachmentFromBase64Async(media.base64, media.name, { isInline: true }, callback));
}

async function attachInlineMedia(item, media, assertTarget) {
    const existingNames = await new Promise((resolve, reject) => {
        if (typeof item.getAttachmentsAsync !== 'function') {
            resolve(new Set());
            return;
        }
        const timeout = setTimeout(() => reject(codedError('COMPOSE_ATTACHMENTS_UNREADABLE')), 10000);
        try {
            item.getAttachmentsAsync((result) => {
                clearTimeout(timeout);
                if (result?.status !== Office.AsyncResultStatus.Succeeded || !Array.isArray(result.value)) {
                    reject(codedError('COMPOSE_ATTACHMENTS_UNREADABLE'));
                    return;
                }
                resolve(new Set(result.value.filter((entry) => entry.isInline === true)
                    .map((entry) => String(entry.name || '').toLowerCase())));
            });
        } catch (error) {
            clearTimeout(timeout);
            reject(error);
        }
    });
    for (let index = 0; index < media.length; index += 1) {
        await assertTarget();
        if (existingNames.has(media[index].name.toLowerCase())) continue;
        await addInlineAttachment(item, media[index]);
        existingNames.add(media[index].name.toLowerCase());
    }
}

function setSignature(item, html) {
    if (html.length > 30000) throw codedError('SIGNATURE_TOO_LARGE');
    return confirmedOfficeWrite(Office, item, 'signature-write', 'SIGNATURE_INSERT_UNCERTAIN',
        (callback) => item.body.setSignatureAsync(html, { coercionType: Office.CoercionType.Html }, callback));
}

function completeOnce(event) {
    let completed = false;

    return () => {
        if (completed) {
            return;
        }

        completed = true;
        event.completed();
    };
}

function safeErrorCode(error) {
    if (typeof error?.code === 'string' && error.code !== '') {
        return error.code;
    }

    if (typeof error?.name === 'string' && error.name !== '') {
        return error.name;
    }

    return 'UNAVAILABLE';
}

async function applyPublishedContent(item) {
    let binding;
    const assertTarget = async () => {
        if (Office.context.mailbox.item !== item) throw codedError('ITEM_CHANGED');
        await assertMailboxBinding(Office, item, binding);
    };
    const config = await diagnoseStep('configuration', loadConfig);
    const accessToken = await diagnoseStep('authentication', () => acquireTokenSilently(config));
    const bootstrap = await diagnoseStep('bootstrap-binding', () => loadBootstrap(config, accessToken, item));
    binding = bootstrap.binding;
    await assertTarget();

    if (isTemplateInsertionBlocked(item) || hasUncertainWrite(item)) return 'uncertain';

    const selected = automaticTemplate(bootstrap);
    if (selected) {
        try {
            const template = validatedDocument(selected, 'template', config.marker);
            await diagnoseStep('template-write', () => prependTemplate(Office, item, template.html, assertTarget, {
                media: template.media,
                beforeInsert: () => attachInlineMedia(item, template.media, assertTarget),
            }));
            return 'applied';
        } catch (error) {
            if (safeErrorCode(error) === 'ITEM_CHANGED' || /MAILBOX|SENDER/.test(safeErrorCode(error))) throw error;
            if (safeErrorCode(error) === 'TEMPLATE_ALREADY_INSERTED') return 'already-present';
            if (['TEMPLATE_ALREADY_INSERTED', 'TEMPLATE_INSERT_IN_PROGRESS',
                'TEMPLATE_INSERT_UNCERTAIN', 'INLINE_ATTACHMENT_UNCERTAIN'].includes(safeErrorCode(error))) return 'uncertain';
            // Unsupported mobile/prepend APIs keep the existing signature path.
            // No setAsync fallback: it would replace typed text and quotes.
            console.info(`${LOG_PREFIX} Default template skipped (${safeErrorCode(error)}).`);
        }
    }

    // Before a signature-only fallback, retain the guard for existing full
    // templates. Failed or oversized body reads never trigger another write.
    const templateState = await readTemplateState(Office, item);
    await assertTarget();
    if (templateState.present) return 'already-present';
    if (!templateState.readable || templateState.tooLarge) {
        recordDiagnostic('compose-preflight', 'skipped', { code: templateState.errorCode || 'COMPOSE_BODY_UNREADABLE' });
        return 'skipped';
    }

    const signature = validatedDocument(bootstrap.signature, 'signature', config.marker);
    if (signature.html.length > 30000) throw codedError('SIGNATURE_TOO_LARGE');

    if (!item?.body?.setSignatureAsync || !item?.addFileAttachmentFromBase64Async) {
        throw codedError('COMPOSE_API_UNAVAILABLE');
    }

    await attachInlineMedia(item, signature.media, assertTarget);
    await assertTarget();
    await setSignature(item, signature.html);
    return 'applied';
}

async function handleComposeEvent(event) {
    const complete = completeOnce(event);

    try {
        const item = Office.context.mailbox.item;
        if (!item) throw codedError('COMPOSE_API_UNAVAILABLE');
        let operation = composeOperations.get(item);
        if (!operation) {
            operation = applyPublishedContent(item).then((result) => {
                if (!['applied', 'already-present'].includes(result)) composeOperations.delete(item);
                return result;
            }).catch((error) => {
                // Keep successful items idempotent, but a transient bootstrap
                // or Office failure must not poison later activation forever.
                composeOperations.delete(item);
                throw error;
            });
            composeOperations.set(item, operation);
        }
        await operation;
    } catch (error) {
        recordDiagnostic('compose-event', 'failed', error);
        // Event activation must never prevent the user from composing or sending.
        console.info(`${LOG_PREFIX} Signature skipped (${safeErrorCode(error)}).`);
    } finally {
        complete();
    }
}

function associateHandlers() {
    if (!globalThis.Office?.actions?.associate) {
        console.info(`${LOG_PREFIX} Office.actions is unavailable.`);
        return;
    }

    Office.actions.associate('onMessageComposeHandler', handleComposeEvent);
    Office.actions.associate('onNewMessageComposeHandler', handleComposeEvent);
}

// Initializes Office.js on Outlook on the web while handler registration stays synchronous.
Office.onReady(() => {});
associateHandlers();
