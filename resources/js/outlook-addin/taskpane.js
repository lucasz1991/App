import {
    InteractionRequiredAuthError,
    createNestablePublicClientApplication,
} from '@azure/msal-browser';
import {
    assertTemplateInsertable,
    currentComposeBodyHtml as scopedComposeBodyHtml,
    prependTemplate,
    readTemplateState,
    supportsTemplatePrepend,
} from './compose-template.js';

const CONFIG_META_NAME = 'railtime-outlook-config-url';
const CONFIG_TIMEOUT_MS = 8000;
const API_TIMEOUT_MS = 12000;
const SILENT_BOOTSTRAP_REFRESH_INTERVAL_MS = 15000;
const SIGNATURE_VERSION_PATTERN = /^([0-9a-f]{16})$/i;
const SIGNATURE_BODY_VERSION_PATTERN = /RT-SIGNATURE-VERSION:([0-9a-f]{16})/i;

let configPromise;
let authenticationClientPromise;
let currentConfig;
let currentTokenResult;
let currentBootstrap;
let signatureStateRevision = 0;
let mailboxItemRevision = 0;
let bootstrapStateRevision = 0;
let lastBootstrapRefreshAt = 0;
let silentBootstrapRefreshPromise;
const boundDialogRoots = new WeakSet();
const startedTaskpaneRoots = new WeakSet();
const taskpaneState = {
    authenticated: false,
    bootstrapReady: false,
    busy: false,
    configReady: false,
    itemChangedMonitoringReady: false,
    signatureCurrent: false,
    signatureVersion: '',
    templates: [],
    selectedTemplateId: '',
    templatePresent: false,
};

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

function scalarString(value) {
    if (typeof value === 'string') {
        return value.trim();
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }

    return '';
}

function displayVersion(value, hash = '') {
    const direct = scalarString(value);

    if (direct !== '') {
        return direct;
    }

    const normalizedHash = scalarString(hash);

    return /^[0-9a-f]{16,64}$/i.test(normalizedHash)
        ? normalizedHash.slice(0, 16).toLowerCase()
        : '';
}

/**
 * Normalisiert den neuen Mehrvorlagenvertrag und behaelt alte Server bei,
 * die nur `template` liefern. Der Dokumentinhalt wird dabei nicht veraendert.
 */
export function normalizeTemplateChoices(payload) {
    if (!payload || typeof payload !== 'object') {
        return Object.freeze([]);
    }

    const entries = Array.isArray(payload.templates) ? payload.templates : [];
    const choices = [];
    const usedIds = new Set();

    entries.forEach((entry, index) => {
        if (!entry || typeof entry !== 'object' || scalarString(entry.html) === '') {
            return;
        }

        const baseId = firstNonEmptyString([entry.id, entry.key]) || `template-${index + 1}`;
        let id = baseId;
        let suffix = 2;

        while (usedIds.has(id)) {
            id = `${baseId}-${suffix}`;
            suffix += 1;
        }

        usedIds.add(id);
        const name = firstNonEmptyString([entry.name, entry.label]) || `Vorlage ${index + 1}`;

        choices.push(Object.freeze({
            id,
            key: firstNonEmptyString([entry.key, entry.id]) || id,
            name,
            label: firstNonEmptyString([entry.label, entry.name]) || name,
            active: entry.active === true,
            isDefault: entry.isDefault === true,
            version: displayVersion(entry.version, entry.hash),
            hash: scalarString(entry.hash),
            document: entry,
        }));
    });

    if (choices.length === 0
        && payload.template
        && typeof payload.template === 'object'
        && scalarString(payload.template.html) !== '') {
        const name = firstNonEmptyString([
            payload.template.name,
            payload.template.label,
        ]) || 'Standardvorlage';

        choices.push(Object.freeze({
            id: firstNonEmptyString([payload.template.id, payload.template.key]) || 'active-template',
            key: firstNonEmptyString([payload.template.key, payload.template.id]) || 'active-template',
            name,
            label: firstNonEmptyString([payload.template.label, payload.template.name]) || name,
            active: true,
            isDefault: payload.template.isDefault === true,
            version: displayVersion(payload.template.version, payload.version?.template),
            hash: scalarString(payload.template.hash),
            document: payload.template,
        }));
    }

    return Object.freeze(choices);
}

export function hasCurrentSnapshot(payload) {
    if (!payload || typeof payload !== 'object' || payload.snapshot?.current === false) {
        return false;
    }

    const personalVersion = scalarString(payload.version?.personal);
    const signatureVersion = scalarString(payload.version?.signature);

    return personalVersion !== '' && SIGNATURE_VERSION_PATTERN.test(signatureVersion);
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

async function attachInlineMedia(target, media) {
    const item = assertComposeTarget(target);
    const existingNames = new Set(
        (await existingAttachments(item)).filter((attachment) => attachment.isInline)
            .map((attachment) => attachment.name.toLowerCase()),
    );

    for (let index = 0; index < media.length; index += 1) {
        const attachment = media[index];
        const normalizedName = attachment.name.toLowerCase();

        if (existingNames.has(normalizedName)) {
            continue;
        }

        assertComposeTarget(target);
        await addInlineAttachment(item, attachment);
        existingNames.add(normalizedName);
    }
}

function existingAttachments(item) {
    if (typeof item?.getAttachmentsAsync !== 'function') {
        return Promise.resolve([]);
    }

    return new Promise((resolve) => {
        item.getAttachmentsAsync((result) => {
            if (result?.status !== Office.AsyncResultStatus.Succeeded || !Array.isArray(result.value)) {
                resolve([]);
                return;
            }

            resolve(result.value.map((attachment) => ({
                id: String(attachment?.id || '').trim(),
                isInline: attachment?.isInline === true,
                name: String(attachment?.name || '').trim(),
            })).filter((attachment) => attachment.name !== ''));
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

function composeItem() {
    const item = Office.context.mailbox.item;

    if (!item?.body || !item?.addFileAttachmentFromBase64Async) {
        throw codedError('COMPOSE_API_UNAVAILABLE');
    }

    return item;
}

function captureComposeTarget() {
    return Object.freeze({
        item: composeItem(),
        revision: mailboxItemRevision,
    });
}

function assertComposeTarget(target) {
    if (!target
        || target.revision !== mailboxItemRevision
        || Office.context.mailbox.item !== target.item) {
        throw codedError('ITEM_CHANGED');
    }

    return target.item;
}

function elements() {
    return {
        root: document.querySelector('[data-outlook-addin-taskpane]'),
        status: document.querySelector('[data-outlook-status]'),
        statusSymbol: document.querySelector('[data-outlook-status-symbol]'),
        statusTitle: document.querySelector('[data-outlook-status-title]'),
        statusDetail: document.querySelector('[data-outlook-status-detail]'),
        statusSummary: document.querySelector('[data-outlook-status-summary]'),
        account: document.querySelector('[data-outlook-account]'),
        accountLabel: document.querySelector('[data-outlook-account-label]'),
        connectionChip: document.querySelector('[data-outlook-connection-chip]'),
        maintenanceActions: document.querySelector('[data-outlook-maintenance-actions]'),
        templateRegion: document.querySelector('[data-outlook-template-region]'),
        templateCount: document.querySelector('[data-outlook-template-count]'),
        templateSelect: document.querySelector('[data-outlook-template-select]'),
        templateName: document.querySelector('[data-outlook-template-name]'),
        templateVersion: document.querySelector('[data-outlook-template-version]'),
        templateActive: document.querySelector('[data-outlook-template-active]'),
        templateActionDetail: document.querySelector('[data-outlook-template-action-detail]'),
        loginTitle: document.querySelector('[data-outlook-login-title]'),
        loginDetail: document.querySelector('[data-outlook-login-detail]'),
        signatureDetail: document.querySelector('[data-outlook-signature-detail]'),
        login: document.querySelector('[data-outlook-action="login"]'),
        signature: document.querySelector('[data-outlook-action="signature"]'),
        template: document.querySelector('[data-outlook-action="template"]'),
    };
}

export function setStatus(tone, title, detail = '', focus = false) {
    const view = elements();
    const symbolByTone = {
        error: '!',
        neutral: 'i',
        success: '✓',
        working: '…',
    };

    view.status.dataset.tone = tone;
    view.status.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    view.status.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
    view.statusSymbol.textContent = symbolByTone[tone] || 'i';
    view.statusTitle.textContent = title;
    view.statusDetail.textContent = detail;
    if (view.statusSummary) {
        view.statusSummary.textContent = title;
        view.statusSummary.dataset.tone = tone;
        view.statusSummary.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
        const statusButton = view.statusSummary.closest('button');
        if (statusButton) {
            statusButton.dataset.tone = tone;
            statusButton.setAttribute('aria-label', `${title}. ${detail}`);
        }
    }

    let openDialogFeedback = null;
    document.querySelectorAll('[data-outlook-dialog-feedback]').forEach((feedback) => {
        feedback.textContent = [title, detail].filter(Boolean).join('. ');
        feedback.dataset.tone = tone;
        feedback.hidden = false;
        feedback.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        feedback.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
        feedback.setAttribute('tabindex', '-1');
        if (feedback.closest('dialog')?.open) openDialogFeedback = feedback;
    });

    const statusDialog = view.status.closest('dialog');
    const focusTarget = openDialogFeedback || (statusDialog && !statusDialog.open
        ? document.querySelector('[data-outlook-dialog-open="status"]')
        : view.status);
    if (focus && typeof focusTarget?.focus === 'function') {
        try {
            focusTarget.focus({ preventScroll: true });
        } catch {
            focusTarget.focus();
        }
    }
}

function setConnectionChip(tone, label) {
    const view = elements();

    view.connectionChip.dataset.tone = tone;
    view.connectionChip.textContent = label;
}

function setTemplateRegionState(state) {
    const view = elements();
    const labels = {
        empty: '0 Vorlagen',
        error: 'Nicht verfügbar',
        loading: 'Laden …',
        locked: 'Anmeldung nötig',
    };

    view.templateRegion.dataset.state = state;

    if (labels[state]) {
        view.templateCount.textContent = labels[state];
    }
}

function selectedTemplate() {
    return taskpaneState.templates.find(
        (template) => template.id === taskpaneState.selectedTemplateId,
    ) || null;
}

function renderSelectedTemplate() {
    const view = elements();
    const template = selectedTemplate();

    if (!template) {
        view.templateName.textContent = 'Keine Vorlage ausgewählt';
        view.templateVersion.textContent = 'Version nicht verfügbar';
        view.templateVersion.removeAttribute('title');
        view.templateActive.hidden = true;
        view.templateActionDetail.textContent = 'Ausgewählte Vorlage in die Nachricht übernehmen';
        view.template.removeAttribute('aria-label');
        return;
    }

    const versionLabel = template.version !== ''
        ? `Version ${template.version}`
        : 'Version nicht verfügbar';

    view.templateName.textContent = template.name;
    view.templateVersion.textContent = versionLabel;
    view.templateVersion.title = template.hash || versionLabel;
    view.templateActive.hidden = !template.isDefault && !template.active;
    view.templateActive.textContent = template.isDefault ? 'Standard' : 'Systemvorlage';
    const additional = taskpaneState.templatePresent;
    const actionLabel = additional ? 'Zusätzlich oberhalb einfügen' : 'Oberhalb einfügen';
    const actionTitle = view.template.querySelector('strong');
    if (actionTitle) actionTitle.textContent = actionLabel;
    view.templateActionDetail.textContent = additional
        ? 'Vorhandene Vorlage, Nachricht und Zitate bleiben erhalten.'
        : 'Wird oberhalb Ihres Textes eingefügt. Vorhandener Inhalt bleibt erhalten.';
    view.template.setAttribute('aria-label', `${template.name}: ${actionLabel}`);
}

function renderTemplateChoices(payload) {
    const view = elements();
    const previousSelection = taskpaneState.selectedTemplateId;
    const templates = normalizeTemplateChoices(payload);

    taskpaneState.templates = [...templates];
    view.templateSelect.replaceChildren();

    if (templates.length === 0) {
        taskpaneState.selectedTemplateId = '';
        setTemplateRegionState('empty');
        renderSelectedTemplate();
        return;
    }

    templates.forEach((template) => {
        const option = document.createElement('option');

        option.value = template.id;
        option.textContent = template.name;
        view.templateSelect.append(option);
    });

    const selected = templates.find((template) => template.id === previousSelection)
        || templates.find((template) => template.isDefault)
        || templates.find((template) => template.active)
        || templates[0];

    taskpaneState.selectedTemplateId = selected.id;
    view.templateSelect.value = selected.id;
    view.templateCount.textContent = templates.length === 1
        ? '1 Vorlage'
        : `${templates.length} Vorlagen`;
    setTemplateRegionState('ready');
    renderSelectedTemplate();
}

function clearTemplateChoices(state) {
    const view = elements();

    taskpaneState.templates = [];
    taskpaneState.selectedTemplateId = '';
    view.templateSelect.replaceChildren();
    renderSelectedTemplate();
    setTemplateRegionState(state);
}

function snapshotSignatureVersion(payload) {
    const version = scalarString(payload?.version?.signature).toLowerCase();

    return SIGNATURE_VERSION_PATTERN.test(version) ? version : '';
}

export function signatureVersionsFromBody(html) {
    if (typeof html !== 'string' || html === '') {
        return Object.freeze([]);
    }

    const pattern = new RegExp(SIGNATURE_BODY_VERSION_PATTERN.source, 'ig');
    const versions = [];
    let match;

    while ((match = pattern.exec(html)) !== null) {
        const version = String(match[1] || '').toLowerCase();

        if (version !== '' && !versions.includes(version)) {
            versions.push(version);
        }
    }

    return Object.freeze(versions);
}

function readBodyHtml(item) {
    if (typeof item?.body?.getAsync !== 'function') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        try {
            item.body.getAsync(Office.CoercionType.Html, (result) => {
                if (result?.status !== Office.AsyncResultStatus.Succeeded
                    || typeof result.value !== 'string') {
                    resolve(null);
                    return;
                }

                resolve(result.value);
            });
        } catch {
            resolve(null);
        }
    });
}

function readComposeType(item) {
    if (typeof item?.getComposeTypeAsync !== 'function') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        try {
            item.getComposeTypeAsync((result) => {
                if (result?.status !== Office.AsyncResultStatus.Succeeded) {
                    resolve(null);
                    return;
                }

                const composeType = scalarString(result.value?.composeType);

                resolve(['newMail', 'reply', 'forward'].includes(composeType) ? composeType : null);
            });
        } catch {
            resolve(null);
        }
    });
}

/**
 * Liefert nur den vom Benutzer aktuell bearbeiteten Bereich. Marker aus
 * zitierten Nachrichten duerfen eine Antwort oder Weiterleitung niemals als
 * aktuell markieren. Wenn Outlook keinen eindeutigen Trenner liefert, bleibt
 * die Aktualisieren-Aktion absichtlich sichtbar.
 */
export function currentComposeBodyHtml(html, composeType) {
    return scopedComposeBodyHtml(html, composeType);
}

async function signatureIsCurrent(payload) {
    const expectedVersion = snapshotSignatureVersion(payload);

    if (!hasCurrentSnapshot(payload) || expectedVersion === '') {
        return false;
    }

    const item = Office.context.mailbox?.item;
    const [bodyHtml, composeType] = await Promise.all([
        readBodyHtml(item),
        readComposeType(item),
    ]);

    const currentBodyHtml = currentComposeBodyHtml(bodyHtml, composeType);
    if (typeof currentBodyHtml !== 'string') {
        // Fail open: Ohne lesbaren Compose-Body darf ein alter mailboxweiter
        // Speicherwert die Aktualisieren-Aktion niemals ausblenden.
        return false;
    }

    return signatureVersionsFromBody(currentBodyHtml).includes(expectedVersion);
}

async function refreshSignatureCurrentState() {
    const payload = currentBootstrap;

    if (!taskpaneState.authenticated || !taskpaneState.bootstrapReady || !payload) {
        return;
    }

    const revision = ++signatureStateRevision;
    let isCurrent = false;
    let templatePresent = false;

    try {
        isCurrent = await signatureIsCurrent(payload);
        templatePresent = (await readTemplateState(Office, Office.context.mailbox.item)).present;
    } catch {
        // Fail open: Wenn Outlook den aktuellen Compose-Zustand nicht liefern
        // kann, muss die sichere Aktualisieren-Aktion sichtbar bleiben.
        isCurrent = false;
    }

    if (revision !== signatureStateRevision
        || payload !== currentBootstrap
        || !taskpaneState.authenticated
        || !taskpaneState.bootstrapReady) {
        return;
    }

    taskpaneState.signatureCurrent = isCurrent;
    taskpaneState.templatePresent = templatePresent;
    renderSelectedTemplate();
    syncActionState();
}

function requestSignatureCurrentStateRefresh() {
    void refreshSignatureCurrentState().catch((error) => {
        signatureStateRevision += 1;
        taskpaneState.signatureCurrent = false;

        try {
            syncActionState();
        } catch {
            // Das Taskpane kann waehrend eines spaeten Office-Events bereits
            // entladen sein. Der Fehler darf nicht als Promise-Rejection enden.
        }

        console.warn('RailTime signature state refresh failed.', error);
    });
}

function failOpenSignatureCurrentState() {
    signatureStateRevision += 1;
    taskpaneState.signatureCurrent = false;
    syncActionState();
}

function handleMailboxItemChanged() {
    // Beim Wechsel in einen anderen Entwurf gehoert der alte Markerstatus
    // sofort zum vorherigen Item. Die sichere Aktion bleibt sichtbar, bis
    // Outlook den neuen Compose-Body eindeutig als aktuell bestaetigt.
    mailboxItemRevision += 1;
    taskpaneState.templatePresent = false;
    failOpenSignatureCurrentState();
    requestSignatureCurrentStateRefresh();
}

function syncConnectionChip() {
    if (taskpaneState.busy) {
        setConnectionChip('working', 'Lädt');
        return;
    }

    if (taskpaneState.authenticated && taskpaneState.bootstrapReady) {
        setConnectionChip('success', 'Bereit');
        return;
    }

    if (taskpaneState.authenticated || taskpaneState.configReady) {
        setConnectionChip('working', taskpaneState.authenticated ? 'Prüfen' : 'Verbinden');
        return;
    }

    setConnectionChip('error', 'Offline');
}

function updateDisabledStates() {
    const view = elements();

    [view.login, view.signature, view.template].forEach((button) => {
        button.disabled = taskpaneState.busy || button.dataset.available !== 'true';
    });
    view.templateSelect.disabled = taskpaneState.busy
        || view.templateRegion.dataset.state !== 'ready'
        || taskpaneState.templates.length === 0;
}

function syncActionState() {
    const view = elements();
    const authenticatedBootstrap = taskpaneState.authenticated && taskpaneState.bootstrapReady;
    const currentTemplate = selectedTemplate();

    view.login.dataset.available = taskpaneState.configReady ? 'true' : 'false';
    view.signature.dataset.available = taskpaneState.authenticated ? 'true' : 'false';
    view.template.dataset.available = authenticatedBootstrap && currentTemplate ? 'true' : 'false';
    if (!supportsTemplatePrepend(globalThis.Office, globalThis.Office?.context?.mailbox?.item)) {
        view.template.dataset.available = 'false';
        view.templateActionDetail.textContent = 'Vollständige Vorlagen benötigen Outlook im Browser oder am Desktop. Mobil bleibt die automatische Signatur verfügbar.';
    }

    view.login.hidden = authenticatedBootstrap;
    view.signature.hidden = authenticatedBootstrap
        && taskpaneState.itemChangedMonitoringReady
        && taskpaneState.signatureCurrent;
    view.maintenanceActions.hidden = view.login.hidden && view.signature.hidden;

    view.loginTitle.textContent = taskpaneState.authenticated
        ? 'Verbindung prüfen'
        : 'Mit Microsoft verbinden';
    view.loginDetail.textContent = taskpaneState.authenticated
        ? 'RailTime-Inhalte erneut sicher laden'
        : 'Einmalig mit dem Firmenkonto anmelden';
    view.signatureDetail.textContent = taskpaneState.signatureVersion !== ''
        ? `Veröffentlichte Version ${taskpaneState.signatureVersion} einsetzen`
        : 'Veröffentlichte Signatur laden und einsetzen';

    updateDisabledStates();
    syncConnectionChip();
}

function setBusy(busy, activeButton = null) {
    const view = elements();

    taskpaneState.busy = busy;
    view.root.setAttribute('aria-busy', busy ? 'true' : 'false');
    [view.login, view.signature, view.template].forEach((button) => {
        button.dataset.busy = busy && button === activeButton ? 'true' : 'false';
        button.setAttribute('aria-busy', busy && button === activeButton ? 'true' : 'false');
    });
    updateDisabledStates();
    syncConnectionChip();
}

function displayAccount() {
    const account = currentTokenResult?.account;
    const mailbox = Office.context.mailbox?.userProfile?.emailAddress || '';
    const label = firstNonEmptyString([account?.name, account?.username, mailbox]);
    const view = elements();

    view.accountLabel.textContent = label;
    view.account.hidden = label === '';
}

function clearAccount() {
    const view = elements();

    view.accountLabel.textContent = '';
    view.account.hidden = true;
}

async function acceptBootstrap(payload) {
    bootstrapStateRevision += 1;
    currentBootstrap = payload;
    lastBootstrapRefreshAt = Date.now();
    taskpaneState.bootstrapReady = true;
    taskpaneState.signatureVersion = snapshotSignatureVersion(payload);
    renderTemplateChoices(payload);
    await refreshSignatureCurrentState();
    syncActionState();
}

function invalidateBootstrap(templateState, authenticationLost = false) {
    bootstrapStateRevision += 1;
    signatureStateRevision += 1;
    currentBootstrap = null;
    taskpaneState.bootstrapReady = false;
    taskpaneState.signatureCurrent = false;
    taskpaneState.signatureVersion = '';
    clearTemplateChoices(templateState);

    if (authenticationLost) {
        taskpaneState.authenticated = false;
        currentTokenResult = null;
        clearAccount();
    }

    syncActionState();
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

function authenticationWasLost(error) {
    return isInteractionRequired(error) || safeErrorCode(error) === 'HTTP_401';
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
        return 'Die Nachricht ist im Nur-Text-Format. HTML-Inhalte können dort nicht eingesetzt werden.';
    }

    if (code === 'REQUEST_TIMEOUT') {
        return 'RailTime antwortet gerade zu langsam. Bitte erneut versuchen.';
    }

    if (code === 'TEMPLATE_SELECTION_MISSING') {
        return 'Bitte zuerst eine veröffentlichte Vorlage auswählen.';
    }

    if (code === 'TEMPLATE_PREPEND_UNAVAILABLE') {
        return 'Vollständige Vorlagen können hier nicht sicher eingefügt werden. Bitte Outlook im Browser oder am Desktop verwenden. Die automatische Signatur bleibt separat verfügbar.';
    }
    if (code === 'TEMPLATE_ALREADY_INSERTED') {
        return 'In dieser Nachricht ist bereits eine RailTime-Vorlage. Weitere Vorlagen werden nur nach Bestätigung zusätzlich oberhalb eingefügt.';
    }
    if (code === 'SIGNATURE_WITHIN_TEMPLATE') {
        return 'Diese Nachricht enthält bereits eine vollständige Vorlage mit Signatur. Sie bleibt unverändert, damit Ihr bearbeiteter Inhalt erhalten bleibt.';
    }
    if (code === 'COMPOSE_BODY_UNREADABLE') {
        return 'Der aktuelle Nachrichtentext konnte nicht sicher geprüft werden. Es wurde kein Inhalt ersetzt. Bitte erneut versuchen.';
    }
    if (code === 'TEMPLATE_REQUIRES_HTML') {
        return 'Die Nachricht muss im HTML-Format geöffnet sein. RailTime ändert Ihr Nachrichtenformat nicht automatisch.';
    }
    if (code === 'TEMPLATE_TOO_LARGE') {
        return 'Die Vorlage überschreitet das Einfügelimit dieses Outlook-Clients.';
    }

    if (code === 'SET_SIGNATURE_UNAVAILABLE' || code === 'SET_TEMPLATE_UNAVAILABLE') {
        return 'Diese Outlook-Ansicht unterstützt die gewünschte Einfügefunktion nicht.';
    }

    if (code === 'COMPOSE_API_UNAVAILABLE') {
        return 'Bitte das Add-in in einer geöffneten neuen Nachricht verwenden.';
    }

    if (code === 'ITEM_CHANGED') {
        return 'Die geöffnete Nachricht hat gewechselt. Bitte die Aktion im aktuellen Entwurf erneut starten.';
    }

    if (code.startsWith('HTTP_')) {
        return 'RailTime konnte die veröffentlichten Inhalte nicht laden. Bitte erneut versuchen.';
    }

    return 'Die Aktion konnte nicht abgeschlossen werden. Bitte erneut versuchen.';
}

function readyStatusDetail() {
    const templateCount = taskpaneState.templates.length;
    const templateLabel = templateCount === 1
        ? '1 Vorlage verfügbar'
        : `${templateCount} Vorlagen verfügbar`;
    const signatureLabel = taskpaneState.signatureCurrent
        ? 'Signatur aktuell'
        : 'Signatur prüfen';

    const templateSupport = supportsTemplatePrepend(Office, Office.context.mailbox?.item)
        ? 'Vorlagen werden oberhalb eingefügt; Outlook kann CSS anpassen.'
        : 'Mobile/eingeschränkte Ansicht: automatische Signatur, vollständige Vorlagen nur im Browser/Desktop.';
    return `${templateLabel} · ${signatureLabel}. ${templateSupport}`;
}

function handleFailure(error, bootstrapWasLoaded = false) {
    const authLost = authenticationWasLost(error);

    if (!bootstrapWasLoaded || authLost) {
        invalidateBootstrap(authLost ? 'locked' : 'error', authLost);
    }

    setBusy(false);
    syncActionState();
    setStatus('error', 'Aktion nicht abgeschlossen', userMessage(error), true);
}

function requestSilentBootstrapRefresh() {
    if (!currentConfig
        || !taskpaneState.authenticated
        || !taskpaneState.bootstrapReady
        || taskpaneState.busy) {
        requestSignatureCurrentStateRefresh();
        return;
    }

    if (silentBootstrapRefreshPromise) {
        return;
    }

    if (Date.now() - lastBootstrapRefreshAt < SILENT_BOOTSTRAP_REFRESH_INTERVAL_MS) {
        requestSignatureCurrentStateRefresh();
        return;
    }

    // Bis der Server den aktuellen Snapshot bestaetigt hat, darf die
    // Aktualisieren-Aktion nicht aufgrund eines veralteten Stands fehlen.
    failOpenSignatureCurrentState();

    silentBootstrapRefreshPromise = (async () => {
        const expectedRevision = bootstrapStateRevision;

        try {
            const accessToken = await acquireAccessToken(currentConfig, false);
            const bootstrap = await loadBootstrap(currentConfig, accessToken);

            if (expectedRevision !== bootstrapStateRevision
                || taskpaneState.busy
                || !taskpaneState.authenticated) {
                return;
            }

            await acceptBootstrap(bootstrap);

            if (bootstrapStateRevision !== expectedRevision + 1 || taskpaneState.busy) {
                return;
            }

            setStatus('success', 'RailTime ist bereit', readyStatusDetail());
        } catch (error) {
            if (expectedRevision !== bootstrapStateRevision || taskpaneState.busy) {
                return;
            }

            if (authenticationWasLost(error)) {
                invalidateBootstrap('locked', true);
                setStatus('neutral', 'Verbindung erneuern', 'Bitte einmal erneut mit Ihrem RailTime-Firmenkonto anmelden.');
                return;
            }

            // Ohne bestaetigten Serverstand bleibt die sichere Aktualisieren-
            // Aktion sichtbar; die zuletzt geladenen Vorlagen bleiben nutzbar.
            failOpenSignatureCurrentState();
            console.warn('RailTime bootstrap refresh failed.', error);
        } finally {
            silentBootstrapRefreshPromise = null;
        }
    })();
}

async function authenticateInteractively(button) {
    bootstrapStateRevision += 1;
    setBusy(true, button);
    setStatus('working', 'Microsoft-Anmeldung wird geöffnet …', 'Danach lädt RailTime Ihre veröffentlichten Inhalte.');

    try {
        const accessToken = await acquireAccessToken(currentConfig, true);

        taskpaneState.authenticated = true;
        displayAccount();
        const bootstrap = await loadBootstrap(currentConfig, accessToken);

        await acceptBootstrap(bootstrap);
        setBusy(false);
        syncActionState();
        setStatus('success', 'RailTime ist bereit', readyStatusDetail());
    } catch (error) {
        handleFailure(error);
    }
}

async function withAuthenticatedBootstrap(button, callback) {
    let bootstrapWasLoaded = false;

    bootstrapStateRevision += 1;
    setBusy(true, button);

    try {
        const target = captureComposeTarget();
        const accessToken = await acquireAccessToken(currentConfig, true);
        taskpaneState.authenticated = true;
        displayAccount();
        const bootstrap = await loadBootstrap(currentConfig, accessToken);

        bootstrapWasLoaded = true;
        await acceptBootstrap(bootstrap);
        setBusy(true, button);
        await callback(bootstrap, target);
        setBusy(false);
        syncActionState();
    } catch (error) {
        handleFailure(error, bootstrapWasLoaded);
    }
}

async function updateSignature(button) {
    setStatus('working', 'Signatur wird aktualisiert …', 'Bilder werden sicher in die Nachricht eingebettet.');

    await withAuthenticatedBootstrap(button, async (bootstrap, target) => {
        const item = assertComposeTarget(target);
        if ((await readTemplateState(Office, item)).present) {
            throw codedError('SIGNATURE_WITHIN_TEMPLATE');
        }
        const signature = validatedDocument(bootstrap.signature, 'signature', currentConfig.marker);

        if (!item.body.setSignatureAsync) {
            throw codedError('SET_SIGNATURE_UNAVAILABLE');
        }

        await attachInlineMedia(target, signature.media);
        assertComposeTarget(target);
        await setSignature(item, signature.html);
        assertComposeTarget(target);
        await refreshSignatureCurrentState();
        assertComposeTarget(target);
        setStatus(
            'success',
            'Signatur ist aktuell',
            taskpaneState.signatureVersion !== ''
                ? `Version ${taskpaneState.signatureVersion} wurde eingesetzt.`
                : 'Die veröffentlichte RailTime-Signatur wurde eingesetzt.',
        );
    });
}

async function insertTemplate(button) {
    const selected = selectedTemplate();

    setStatus(
        'working',
        'Vorlage wird eingefügt …',
        selected ? `${selected.name} wird sicher geladen.` : 'Die aktuelle Veröffentlichung wird geladen.',
    );

    await withAuthenticatedBootstrap(button, async (bootstrap, target) => {
        const item = assertComposeTarget(target);
        const templateChoice = selectedTemplate();

        if (!templateChoice) {
            throw codedError('TEMPLATE_SELECTION_MISSING');
        }

        const template = validatedDocument(templateChoice.document, 'template', currentConfig.marker);
        const state = await readTemplateState(Office, item);
        assertComposeTarget(target);
        if (state.present && !window.confirm(
            'Es ist bereits eine RailTime-Vorlage eingefügt. Die gewählte Vorlage wird ZUSÄTZLICH oberhalb eingefügt. Vorhandene Vorlage, eigener Text und Zitate bleiben erhalten. Fortfahren?',
        )) {
            setStatus('neutral', 'Einfügen abgebrochen', 'Ihre Nachricht wurde nicht verändert.');
            return;
        }
        const options = { allowAdditional: state.present };
        await assertTemplateInsertable(Office, item, options);
        await attachInlineMedia(target, template.media);
        assertComposeTarget(target);
        await prependTemplate(Office, item, template.html, () => assertComposeTarget(target), options);
        assertComposeTarget(target);
        await refreshSignatureCurrentState();
        assertComposeTarget(target);

        setStatus(
            'success',
            'Vorlage wurde oberhalb eingefügt',
            templateChoice.version !== ''
                ? `${templateChoice.name} · Version ${templateChoice.version}`
                : templateChoice.name,
        );
    });
}

export function bindOutlookDialogs(root = document) {
    if (boundDialogRoots.has(root)) return;
    boundDialogRoots.add(root);
    const dialogs = Array.from(root.querySelectorAll('[data-outlook-dialog]'));
    const returnFocus = new WeakMap();

    root.querySelectorAll('[data-outlook-dialog-open]').forEach((button) => {
        const dialog = dialogs.find((candidate) => (
            candidate.dataset.outlookDialog === button.dataset.outlookDialogOpen
        ));
        if (!dialog) return;
        button.addEventListener('click', () => {
            if (dialog.open || typeof dialog.showModal !== 'function') return;
            returnFocus.set(dialog, button);
            dialog.showModal();
        });
    });

    dialogs.forEach((dialog) => {
        dialog.querySelectorAll('[data-outlook-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        // Native Escape and the dialog top layer provide focus containment.
        dialog.addEventListener('close', () => {
            const button = returnFocus.get(dialog);
            if (button?.isConnected !== false) button?.focus({ preventScroll: true });
            returnFocus.delete(dialog);
        });
    });
}

function bindActions() {
    const view = elements();

    view.login.addEventListener('click', () => authenticateInteractively(view.login));
    view.signature.addEventListener('click', () => updateSignature(view.signature));
    view.template.addEventListener('click', () => insertTemplate(view.template));
    view.templateSelect.addEventListener('change', () => {
        taskpaneState.selectedTemplateId = view.templateSelect.value;
        renderSelectedTemplate();
        syncActionState();
    });

    window.addEventListener('focus', requestSilentBootstrapRefresh);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            requestSilentBootstrapRefresh();
        }
    });

    if (typeof Office.context.mailbox?.addHandlerAsync === 'function'
        && Office.EventType?.ItemChanged) {
        try {
            Office.context.mailbox.addHandlerAsync(
                Office.EventType.ItemChanged,
                handleMailboxItemChanged,
                (result) => {
                    taskpaneState.itemChangedMonitoringReady = result?.status === Office.AsyncResultStatus.Succeeded;

                    if (!taskpaneState.itemChangedMonitoringReady) {
                        failOpenSignatureCurrentState();
                        return;
                    }

                    requestSignatureCurrentStateRefresh();
                },
            );
        } catch {
            taskpaneState.itemChangedMonitoringReady = false;
            failOpenSignatureCurrentState();
        }
    } else {
        taskpaneState.itemChangedMonitoringReady = false;
        failOpenSignatureCurrentState();
    }
}

async function initialize() {
    bindActions();
    clearTemplateChoices('loading');
    syncActionState();
    setBusy(true);
    setStatus('working', 'Outlook wird vorbereitet …', 'Die sichere Verbindung wird geprüft.');

    try {
        currentConfig = await loadConfig();
        taskpaneState.configReady = true;
        syncActionState();

        if (!supportsNestedAppAuthentication()) {
            throw codedError('NAA_NOT_SUPPORTED');
        }

        try {
            const accessToken = await acquireAccessToken(currentConfig, false);

            taskpaneState.authenticated = true;
            displayAccount();
            const bootstrap = await loadBootstrap(currentConfig, accessToken);

            await acceptBootstrap(bootstrap);
            setBusy(false);
            syncActionState();
            setStatus('success', 'RailTime ist bereit', readyStatusDetail());
        } catch (error) {
            if (!isInteractionRequired(error)) {
                throw error;
            }

            taskpaneState.authenticated = false;
            invalidateBootstrap('locked', true);
            setBusy(false);
            syncActionState();
            setStatus('neutral', 'Einmalig verbinden', 'Melden Sie sich sicher mit Ihrem RailTime-Firmenkonto an.');
        }
    } catch (error) {
        const authLost = authenticationWasLost(error);

        invalidateBootstrap(authLost ? 'locked' : 'error', authLost);
        setBusy(false);
        syncActionState();
        setStatus('error', 'Add-in nicht verfügbar', userMessage(error));
    }
}

export function startOutlookTaskpane(office = globalThis.Office) {
    const root = document.querySelector('[data-outlook-addin-taskpane]');
    if (!root || startedTaskpaneRoots.has(root)) return;
    startedTaskpaneRoots.add(root);

    // Installation and help are ordinary browser UI, not Office host APIs.
    // Keep them usable even if Office.js is blocked or its handshake is pending.
    bindOutlookDialogs();

    const showOutsideOutlook = () => {
        taskpaneState.configReady = false;
        clearTemplateChoices('error');
        setBusy(false);
        syncActionState();
        setStatus('neutral', 'In Outlook öffnen', 'Signaturen und Vorlagen können nur in Outlook eingefügt werden. Hilfe, Status und die Anleitung zur Browser-App sind hier weiterhin verfügbar.');
    };

    if (typeof office?.onReady !== 'function') {
        showOutsideOutlook();
        return;
    }

    try {
        const readiness = office.onReady((info) => {
            if (!office.HostType?.Outlook || info?.host !== office.HostType.Outlook
                || !office.context?.mailbox) {
                showOutsideOutlook();
                return;
            }
            initialize();
        });
        readiness?.catch?.(showOutsideOutlook);
    } catch {
        showOutsideOutlook();
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => startOutlookTaskpane(), { once: true });
    } else {
        startOutlookTaskpane();
    }
}
