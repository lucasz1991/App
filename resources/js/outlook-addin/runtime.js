import {
    InteractionRequiredAuthError,
    createNestablePublicClientApplication,
} from '@azure/msal-browser';

const CONFIG_META_NAME = 'railtime-outlook-config-url';
const CONFIG_TIMEOUT_MS = 8000;
const API_TIMEOUT_MS = 12000;
const LOG_PREFIX = '[RailTime Outlook Add-in]';

let configPromise;
let authenticationClientPromise;

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

async function loadBootstrap(config, accessToken) {
    const payload = await fetchJson(config.endpoints.bootstrap, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${accessToken}`,
            'X-RailTime-Outlook-Context': 'event',
            'X-RailTime-Outlook-Mailbox': mailboxAddress(),
        },
    }, API_TIMEOUT_MS);

    if (!payload || typeof payload !== 'object') {
        throw codedError('BOOTSTRAP_INVALID');
    }

    if (payload.marker !== config.marker) {
        throw codedError('BOOTSTRAP_MARKER_MISMATCH');
    }

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
    return new Promise((resolve, reject) => {
        item.addFileAttachmentFromBase64Async(
            media.base64,
            media.name,
            { isInline: true },
            (result) => {
                const error = officeFailure(result, 'INLINE_ATTACHMENT_FAILED');

                if (error) {
                    reject(error);
                    return;
                }

                resolve(result.value);
            },
        );
    });
}

async function attachInlineMedia(item, media) {
    for (let index = 0; index < media.length; index += 1) {
        await addInlineAttachment(item, media[index]);
    }
}

function setSignature(item, html) {
    return new Promise((resolve, reject) => {
        item.body.setSignatureAsync(
            html,
            { coercionType: Office.CoercionType.Html },
            (result) => {
                const error = officeFailure(result, 'SET_SIGNATURE_FAILED');

                if (error) {
                    reject(error);
                    return;
                }

                resolve();
            },
        );
    });
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

async function applyPublishedSignature() {
    const config = await loadConfig();
    const accessToken = await acquireTokenSilently(config);
    const bootstrap = await loadBootstrap(config, accessToken);
    const signature = validatedDocument(bootstrap.signature, 'signature', config.marker);
    const item = Office.context.mailbox.item;

    if (!item?.body?.setSignatureAsync || !item?.addFileAttachmentFromBase64Async) {
        throw codedError('COMPOSE_API_UNAVAILABLE');
    }

    await attachInlineMedia(item, signature.media);
    await setSignature(item, signature.html);
}

async function handleComposeEvent(event) {
    const complete = completeOnce(event);

    try {
        await applyPublishedSignature();
    } catch (error) {
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
