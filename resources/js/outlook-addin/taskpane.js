import {
    InteractionRequiredAuthError,
    createNestablePublicClientApplication,
} from '@azure/msal-browser';

const CONFIG_META_NAME = 'railtime-outlook-config-url';
const CONFIG_TIMEOUT_MS = 8000;
const API_TIMEOUT_MS = 12000;

let configPromise;
let authenticationClientPromise;
let currentConfig;
let currentTokenResult;

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
    const value = firstNonEmptyString([
        document.querySelector(`meta[name="${CONFIG_META_NAME}"]`)?.getAttribute('content'),
        document.documentElement?.dataset?.outlookConfigUrl,
        document.body?.dataset?.outlookConfigUrl,
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

function isInteractionRequired(error) {
    return error instanceof InteractionRequiredAuthError
        || ['interaction_required', 'login_required', 'consent_required', 'no_account_error']
            .includes(String(error?.errorCode || error?.code || '').toLowerCase());
}

async function acquireAccessToken(config, allowPopup) {
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
        currentTokenResult = await client.acquireTokenSilent(request);
    } catch (error) {
        if (!allowPopup || !isInteractionRequired(error)) {
            throw error;
        }

        const popupRequest = {
            scopes: [...config.auth.scopes],
            loginHint: mailbox,
        };

        currentTokenResult = await client.acquireTokenPopup(popupRequest);
    }

    return requiredString(currentTokenResult?.accessToken, 'ACCESS_TOKEN_MISSING');
}

async function loadBootstrap(config, accessToken) {
    const payload = await fetchJson(config.endpoints.bootstrap, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${accessToken}`,
            'X-RailTime-Outlook-Context': 'taskpane',
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
    const existingNames = await existingAttachmentNames(item);

    for (let index = 0; index < media.length; index += 1) {
        const attachment = media[index];
        const normalizedName = attachment.name.toLowerCase();

        if (existingNames.has(normalizedName)) {
            continue;
        }

        await addInlineAttachment(item, attachment);
        existingNames.add(normalizedName);
    }
}

function existingAttachmentNames(item) {
    if (typeof item?.getAttachmentsAsync !== 'function') {
        return Promise.resolve(new Set());
    }

    return new Promise((resolve) => {
        item.getAttachmentsAsync((result) => {
            if (result?.status !== Office.AsyncResultStatus.Succeeded || !Array.isArray(result.value)) {
                resolve(new Set());
                return;
            }

            resolve(new Set(result.value
                .map((attachment) => String(attachment?.name || '').trim().toLowerCase())
                .filter((name) => name !== '')));
        });
    });
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

function replaceBody(item, html) {
    return new Promise((resolve, reject) => {
        item.body.setAsync(
            html,
            { coercionType: Office.CoercionType.Html },
            (result) => {
                const error = officeFailure(result, 'SET_TEMPLATE_FAILED');

                if (error) {
                    reject(error);
                    return;
                }

                resolve();
            },
        );
    });
}

function composeItem() {
    const item = Office.context.mailbox.item;

    if (!item?.body || !item?.addFileAttachmentFromBase64Async) {
        throw codedError('COMPOSE_API_UNAVAILABLE');
    }

    return item;
}

function elements() {
    return {
        root: document.querySelector('[data-outlook-addin-taskpane]'),
        status: document.querySelector('[data-outlook-status]'),
        statusTitle: document.querySelector('[data-outlook-status-title]'),
        statusDetail: document.querySelector('[data-outlook-status-detail]'),
        account: document.querySelector('[data-outlook-account]'),
        login: document.querySelector('[data-outlook-action="login"]'),
        signature: document.querySelector('[data-outlook-action="signature"]'),
        template: document.querySelector('[data-outlook-action="template"]'),
    };
}

function setStatus(tone, title, detail = '') {
    const view = elements();

    view.status.dataset.tone = tone;
    view.statusTitle.textContent = title;
    view.statusDetail.textContent = detail;
}

function setBusy(busy, activeButton = null) {
    const view = elements();

    view.root.setAttribute('aria-busy', busy ? 'true' : 'false');
    [view.login, view.signature, view.template].forEach((button) => {
        button.disabled = busy || button.dataset.available !== 'true';
        button.dataset.busy = busy && button === activeButton ? 'true' : 'false';
    });
}

function setActionAvailability(authenticated) {
    const view = elements();

    view.login.dataset.available = 'true';
    view.signature.dataset.available = authenticated ? 'true' : 'false';
    view.template.dataset.available = authenticated ? 'true' : 'false';
    setBusy(false);
}

function displayAccount() {
    const account = currentTokenResult?.account;
    const mailbox = Office.context.mailbox?.userProfile?.emailAddress || '';
    const label = firstNonEmptyString([account?.name, account?.username, mailbox]);
    const view = elements();

    view.account.textContent = label;
    view.account.hidden = label === '';
}

function safeErrorCode(error) {
    if (typeof error?.code === 'string' && error.code !== '') {
        return error.code;
    }

    if (typeof error?.errorCode === 'string' && error.errorCode !== '') {
        return error.errorCode;
    }

    return typeof error?.name === 'string' ? error.name : 'UNAVAILABLE';
}

function userMessage(error) {
    const code = safeErrorCode(error);

    if (code === 'NAA_NOT_SUPPORTED') {
        return 'Diese Outlook-Version unterstützt die sichere Microsoft-Anmeldung noch nicht.';
    }

    if (code === 'CONFIG_NOT_READY') {
        return 'Das RailTime-Add-in ist administrativ noch nicht freigeschaltet.';
    }

    if (code === 'AUTH_INTERACTION_REQUIRED' || isInteractionRequired(error)) {
        return 'Bitte einmal mit Ihrem Firmenkonto anmelden.';
    }

    if (code === 'InvalidFormatError') {
        return 'Die Nachricht ist im Nur-Text-Format. HTML-Signaturen können dort nicht eingesetzt werden.';
    }

    if (code.startsWith('HTTP_')) {
        return 'RailTime konnte die veröffentlichten Inhalte nicht laden. Bitte erneut versuchen.';
    }

    return 'Die Aktion konnte nicht abgeschlossen werden. Bitte erneut versuchen.';
}

async function authenticateInteractively(button) {
    setBusy(true, button);
    setStatus('working', 'Microsoft-Anmeldung wird geöffnet …');

    try {
        await acquireAccessToken(currentConfig, true);
        displayAccount();
        setActionAvailability(true);
        setStatus('success', 'Outlook ist verbunden', 'Veröffentlichte RailTime-Inhalte stehen bereit.');
    } catch (error) {
        setActionAvailability(false);
        setStatus('error', 'Anmeldung nicht abgeschlossen', userMessage(error));
    }
}

async function withAuthenticatedBootstrap(button, callback) {
    setBusy(true, button);

    try {
        const accessToken = await acquireAccessToken(currentConfig, true);
        const bootstrap = await loadBootstrap(currentConfig, accessToken);

        displayAccount();
        setActionAvailability(true);
        setBusy(true, button);
        await callback(bootstrap);
        setBusy(false);
    } catch (error) {
        setActionAvailability(Boolean(currentTokenResult?.accessToken));
        setStatus('error', 'Aktion nicht abgeschlossen', userMessage(error));
    }
}

async function updateSignature(button) {
    setStatus('working', 'Signatur wird aktualisiert …', 'Bilder werden sicher in die Nachricht eingebettet.');

    await withAuthenticatedBootstrap(button, async (bootstrap) => {
        const item = composeItem();
        const signature = validatedDocument(bootstrap.signature, 'signature', currentConfig.marker);

        if (!item.body.setSignatureAsync) {
            throw codedError('SET_SIGNATURE_UNAVAILABLE');
        }

        await attachInlineMedia(item, signature.media);
        await setSignature(item, signature.html);
        setStatus('success', 'Signatur ist aktuell', 'Die veröffentlichte RailTime-Signatur wurde eingesetzt.');
    });
}

async function insertTemplate(button) {
    setStatus('working', 'Vorlage wird eingefügt …', 'Die aktuelle Veröffentlichung wird geladen.');

    await withAuthenticatedBootstrap(button, async (bootstrap) => {
        const item = composeItem();
        const template = validatedDocument(bootstrap.template, 'template', currentConfig.marker);

        if (!item.body.setAsync) {
            throw codedError('SET_TEMPLATE_UNAVAILABLE');
        }

        await attachInlineMedia(item, template.media);
        await replaceBody(item, template.html);
        setStatus('success', 'Vorlage wurde eingesetzt', 'Der Nachrichteninhalt entspricht der aktuellen Veröffentlichung.');
    });
}

function bindActions() {
    const view = elements();

    view.login.addEventListener('click', () => authenticateInteractively(view.login));
    view.signature.addEventListener('click', () => updateSignature(view.signature));
    view.template.addEventListener('click', () => insertTemplate(view.template));
}

async function initialize() {
    bindActions();
    setActionAvailability(false);
    setBusy(true);
    setStatus('working', 'Outlook wird vorbereitet …');

    try {
        currentConfig = await loadConfig();

        if (!supportsNestedAppAuthentication()) {
            throw codedError('NAA_NOT_SUPPORTED');
        }

        try {
            await acquireAccessToken(currentConfig, false);
            displayAccount();
            setActionAvailability(true);
            setStatus('success', 'Outlook ist verbunden', 'Signatur und Vorlage können verwendet werden.');
        } catch (error) {
            if (!isInteractionRequired(error)) {
                throw error;
            }

            setActionAvailability(false);
            setStatus('neutral', 'Einmalig verbinden', 'Melden Sie sich sicher mit Ihrem RailTime-Firmenkonto an.');
        }
    } catch (error) {
        setActionAvailability(false);
        elements().login.dataset.available = 'false';
        setBusy(false);
        setStatus('error', 'Add-in nicht verfügbar', userMessage(error));
    }
}

Office.onReady((info) => {
    if (info.host !== Office.HostType.Outlook) {
        setStatus('error', 'Nur in Outlook verfügbar');
        return;
    }

    initialize();
});
