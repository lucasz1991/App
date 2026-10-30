import {
    InteractionRequiredAuthError,
    createNestablePublicClientApplication,
} from '@azure/msal-browser';
import {
    isTemplateInsertionBlocked,
    isMobileComposeHost,
    isStandaloneSignatureInsertionBlocked,
    insertStandaloneSignature,
    requiresStandaloneSignatureLayout,
    standaloneSignatureLayoutIsCurrent,
    signatureHostProfileIsCurrent,
    combinedComposeDocument,
    currentComposeBodyHtml as scopedComposeBodyHtml,
    prependTemplate,
    replaceManagedTemplate,
    readTemplateState,
    supportsTemplatePrepend,
    templateStateFromBody,
    nativeComposeTemplate,
    TEMPLATE_MARKER,
    validateTemplateInsertionPayload,
} from './compose-template.js';
import { automaticTemplate } from './compose-template.js';
import { readComposeSender, assertMailboxBinding } from './mailbox-guard.js';
import { diagnoseStep, recordDiagnostic, diagnosticReport } from './diagnostics.js';
import { confirmedOfficeWrite, hasUncertainWrite } from './office-write.js';
import { bindAutomaticPreferencesView, createAccountPreferencesSession, normalizeAutomaticPreferences } from './automatic-preferences-view.js';
import { bindWordingPreferencesView, bindTemplateChoiceView, createAccountOptionsSession,
    normalizeWordingPreferences, normalizeTemplateChoicePreferences } from './account-options-view.js';

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
let signatureStateRefreshPromise;
let lastActionError = null;
let observedComposeItem = null;
let automaticPreferencesView;
let accountPreferencesSession;
let accountSettingsBusy = false;
let accountSettingsReady = false;
let lastAccountSettingsRefreshAt = 0;
let wordingSession;
let templateChoicesSession;
let wordingView;
let templateChoicesView;
let accountOptionsBusy = false;
let wordingReady = false;
let templateChoicesReady = false;
// Display evidence only, never cached authorization for another Office write.
const confirmedInsertions = new WeakMap();
const boundDialogRoots = new WeakSet();
const startedTaskpaneRoots = new WeakSet();
const taskpaneState = {
    settingsMode: false,
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
        && !Array.isArray(payload.templates)
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
            preferences: payload.endpoints?.preferences
                ? requiredHttpsUrl(payload.endpoints.preferences, 'CONFIG_PREFERENCES_URL_INVALID') : null,
            accountPreferences: payload.endpoints?.accountPreferences
                ? requiredHttpsUrl(payload.endpoints.accountPreferences, 'CONFIG_ACCOUNT_PREFERENCES_URL_INVALID') : null,
            accountWording: payload.endpoints?.accountWording
                ? requiredHttpsUrl(payload.endpoints.accountWording, 'CONFIG_ACCOUNT_WORDING_URL_INVALID') : null,
            accountTemplateChoices: payload.endpoints?.accountTemplateChoices
                ? requiredHttpsUrl(payload.endpoints.accountTemplateChoices, 'CONFIG_ACCOUNT_CHOICES_URL_INVALID') : null,
        }),
        urls: Object.freeze({ profileSettings: payload.urls?.profileSettings
            ? requiredHttpsUrl(payload.urls.profileSettings, 'CONFIG_PROFILE_SETTINGS_URL_INVALID') : null }),
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
            const error = codedError(`HTTP_${response.status}`);
            if (response.status === 409) {
                try {
                    const payload = await response.json();
                    const current = normalizeAutomaticPreferences(payload?.automaticInsertionPreferences);
                    if (current) error.currentPreferences = current;
                    const wording = normalizeWordingPreferences(payload?.wordingPreferences);
                    const choices = normalizeTemplateChoicePreferences(payload?.templateChoicesPreferences);
                    if (wording || choices) error.currentAccountOptions = {
                        ...(wording ? { wordingPreferences: wording } : {}),
                        ...(choices ? { templateChoicesPreferences: choices } : {}), binding: payload?.binding,
                    };
                } catch { /* Only the bounded preferences contract is retained. */ }
            }
            throw error;
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
        }, CONFIG_TIMEOUT_MS).then(validateConfig).catch((error) => {
            configPromise = null;
            throw error;
        });
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

async function loadBootstrap(config, accessToken, item = null) {
    if (taskpaneState.settingsMode) throw codedError('ACCOUNT_SETTINGS_READ_ONLY');
    item = item || Office.context.mailbox.item;
    const sender = await diagnoseStep('sender-read', () => readComposeSender(Office, item));
    const payload = await fetchJson(config.endpoints.bootstrap, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${accessToken}`,
            'X-RailTime-Outlook-Context': 'taskpane',
            'X-RailTime-Outlook-Mailbox': mailboxAddress(),
            'X-RailTime-Outlook-Sender': sender,
            'X-RailTime-Compose-Contract': 'native-signature-v1',
        },
    }, API_TIMEOUT_MS);

    if (!payload || typeof payload !== 'object') {
        throw codedError('BOOTSTRAP_INVALID');
    }

    if (payload.marker !== config.marker) {
        throw codedError('BOOTSTRAP_MARKER_MISMATCH');
    }

    await diagnoseStep('mailbox-binding', () => assertMailboxBinding(Office, item, payload.binding));
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

function addInlineAttachment(item, media) {
    return confirmedOfficeWrite(Office, item, 'attachment-write', 'INLINE_ATTACHMENT_UNCERTAIN',
        (callback) => item.addFileAttachmentFromBase64Async(media.base64, media.name, { isInline: true }, callback),
        { onSettled: () => syncActionState() });
}

async function attachInlineMedia(target, media, binding) {
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

        await assertWriteTarget(target, binding);
        await addInlineAttachment(item, attachment);
        existingNames.add(normalizedName);
    }
}

function existingAttachments(item) {
    if (typeof item?.getAttachmentsAsync !== 'function') {
        return Promise.resolve([]);
    }

    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(codedError('COMPOSE_ATTACHMENTS_UNREADABLE')), 10000);
        try {
            item.getAttachmentsAsync((result) => {
                clearTimeout(timeout);
                if (result?.status !== Office.AsyncResultStatus.Succeeded || !Array.isArray(result.value)) {
                    reject(codedError('COMPOSE_ATTACHMENTS_UNREADABLE'));
                    return;
                }
                resolve(result.value.map((attachment) => ({
                    id: String(attachment?.id || '').trim(),
                    isInline: attachment?.isInline === true,
                    name: String(attachment?.name || '').trim(),
                })).filter((attachment) => attachment.name !== ''));
            });
        } catch (error) {
            clearTimeout(timeout);
            reject(error);
        }
    });
}

function setSignature(item, html) {
    if (html.length > 30000) throw codedError('SIGNATURE_TOO_LARGE');
    const binding = currentBootstrap?.binding;
    return confirmedOfficeWrite(Office, item, 'signature-write', 'SIGNATURE_INSERT_UNCERTAIN',
        (callback) => item.body.setSignatureAsync(html, { coercionType: Office.CoercionType.Html }, callback),
        { onSettled: () => syncActionState() }).then((result) => {
        if (html !== '') rememberConfirmedInsertion(item, 'signature', binding);
        return result;
    });
}

function rememberConfirmedInsertion(item, kind, binding) {
    if (!item || !binding) return;
    const existing = confirmedInsertions.get(item);
    const evidence = existing?.mailboxAddress === binding.mailboxAddress && existing?.senderAddress === binding.senderAddress
        ? existing : { mailboxAddress: binding.mailboxAddress, senderAddress: binding.senderAddress };
    evidence[kind] = true;
    confirmedInsertions.set(item, evidence);
}

function insertionDisplayEvidence() {
    const item = globalThis.Office?.context?.mailbox?.item;
    const binding = currentBootstrap?.binding;
    const evidence = item && confirmedInsertions.get(item);
    const sameBinding = Boolean(binding && evidence
        && evidence.mailboxAddress === binding.mailboxAddress && evidence.senderAddress === binding.senderAddress);
    return {
        signatureWriteConfirmed: sameBinding && evidence.signature === true,
        templateWriteConfirmed: sameBinding && evidence.template === true,
        signatureCurrent: observedComposeItem === item && taskpaneState.signatureCurrent,
        templatePresent: observedComposeItem === item && taskpaneState.templatePresent,
    };
}

function composeItem() {
    const item = Office.context.mailbox.item;

    if (!item?.body || !item?.addFileAttachmentFromBase64Async) {
        throw codedError('COMPOSE_API_UNAVAILABLE');
    }

    return item;
}

function captureComposeTarget() {
    if (taskpaneState.settingsMode) throw codedError('ACCOUNT_SETTINGS_READ_ONLY');
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

async function assertWriteTarget(target, binding) {
    const item = assertComposeTarget(target);
    await assertMailboxBinding(Office, item, binding);
    return assertComposeTarget(target);
}

function insertionBlocked() {
    const item = globalThis.Office?.context?.mailbox?.item;
    return Boolean(item && (isTemplateInsertionBlocked(item) || isStandaloneSignatureInsertionBlocked(item) || hasUncertainWrite(item)));
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
        feedback: document.querySelector('[data-outlook-pane-feedback]'),
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
    view.statusDetail.hidden = tone === 'success';
    const diagnostics = document.querySelector('[data-outlook-diagnostics]');
    if (diagnostics) diagnostics.hidden = tone !== 'error';
    if (view.feedback) {
        view.feedback.textContent = [title, detail].filter(Boolean).join('. ');
        view.feedback.dataset.tone = tone;
        view.feedback.hidden = tone === 'success';
        view.feedback.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        view.feedback.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
    }
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
    const focusTarget = openDialogFeedback || (view.feedback && !view.feedback.hidden ? view.feedback : null) || (statusDialog && !statusDialog.open
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
    view.templateActive.hidden = !template.isDefault;
    view.templateActive.textContent = 'Outlook-Standard';
    const replacing = taskpaneState.templatePresent;
    const actionLabel = replacing ? 'Vorlage und Signatur ersetzen' : 'Vorlage einfügen';
    const actionTitle = view.template.querySelector('strong');
    if (actionTitle) actionTitle.textContent = actionLabel;
    const actionIcon = view.template.querySelector('.action-icon');
    if (actionIcon) actionIcon.textContent = replacing ? '↻' : '＋';
    view.templateActionDetail.textContent = replacing
        ? 'Verwaltete Vorlage aktualisieren. Ihr Nachrichtentext und Zitate bleiben erhalten.'
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
        const timeout = setTimeout(() => resolve(null), 10000);
        try {
            item.body.getAsync(Office.CoercionType.Html, (result) => {
                clearTimeout(timeout);
                if (result?.status !== Office.AsyncResultStatus.Succeeded
                    || typeof result.value !== 'string') {
                    resolve(null);
                    return;
                }

                resolve(result.value);
            });
        } catch {
            clearTimeout(timeout);
            resolve(null);
        }
    });
}

function readComposeType(item) {
    if (typeof item?.getComposeTypeAsync !== 'function') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        const timeout = setTimeout(() => resolve(null), 10000);
        try {
            item.getComposeTypeAsync((result) => {
                clearTimeout(timeout);
                if (result?.status !== Office.AsyncResultStatus.Succeeded) {
                    resolve(null);
                    return;
                }

                const composeType = scalarString(result.value?.composeType);

                resolve(['newMail', 'reply', 'forward'].includes(composeType) ? composeType : null);
            });
        } catch {
            clearTimeout(timeout);
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

function signatureIsCurrent(payload, bodyHtml, composeType) {
    const expectedVersion = snapshotSignatureVersion(payload);

    if (!hasCurrentSnapshot(payload) || expectedVersion === '') {
        return false;
    }

    const currentBodyHtml = currentComposeBodyHtml(bodyHtml, composeType);
    if (typeof currentBodyHtml !== 'string') {
        // Fail open: Ohne lesbaren Compose-Body darf ein alter mailboxweiter
        // Speicherwert die Aktualisieren-Aktion niemals ausblenden.
        return false;
    }

    if (!isMobileComposeHost(Office) && /\bdata-rt-desktop-flow\s*=\s*["']outlook-desktop-flow-v15["']/.test(payload.signature?.html || '')) {
        return signatureHostProfileIsCurrent(bodyHtml, currentBodyHtml, expectedVersion, Office);
    }
    if (!isMobileComposeHost(Office) && requiresStandaloneSignatureLayout(payload.signature?.html)
        && !templateStateFromBody(bodyHtml, composeType).present) {
        return standaloneSignatureLayoutIsCurrent(bodyHtml, currentBodyHtml, expectedVersion, Office);
    }
    return signatureVersionsFromBody(currentBodyHtml).includes(expectedVersion);
}

async function refreshSignatureCurrentState() {
    const payload = currentBootstrap;

    if (!taskpaneState.authenticated || !taskpaneState.bootstrapReady || !payload) {
        return;
    }

    const revision = ++signatureStateRevision;
    const item = Office.context.mailbox.item;
    const itemRevision = mailboxItemRevision;
    let isCurrent = false;
    let templatePresent = false;

    try {
        const [bodyHtml, composeType] = await Promise.all([readBodyHtml(item), readComposeType(item)]);
        const templateState = templateStateFromBody(bodyHtml, composeType);
        isCurrent = !templateState.tooLarge && signatureIsCurrent(payload, bodyHtml, composeType);
        templatePresent = templateState.present;
    } catch {
        // Fail open: Wenn Outlook den aktuellen Compose-Zustand nicht liefern
        // kann, muss die sichere Aktualisieren-Aktion sichtbar bleiben.
        isCurrent = false;
    }

    if (revision !== signatureStateRevision
        || item !== Office.context.mailbox.item
        || itemRevision !== mailboxItemRevision
        || payload !== currentBootstrap
        || !taskpaneState.authenticated
        || !taskpaneState.bootstrapReady) {
        return;
    }

    observedComposeItem = item;
    taskpaneState.signatureCurrent = isCurrent;
    taskpaneState.templatePresent = templatePresent;
    renderSelectedTemplate();
    syncActionState();
}

function requestSignatureCurrentStateRefresh() {
    if (taskpaneState.busy || signatureStateRefreshPromise) return;
    signatureStateRefreshPromise = refreshSignatureCurrentState().catch((error) => {
        signatureStateRevision += 1;
        taskpaneState.signatureCurrent = false;

        try {
            syncActionState();
        } catch {
            // Das Taskpane kann waehrend eines spaeten Office-Events bereits
            // entladen sein. Der Fehler darf nicht als Promise-Rejection enden.
        }

        console.warn('RailTime signature state refresh failed.', error);
    }).finally(() => { signatureStateRefreshPromise = null; });
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
    lastActionError = null;
    taskpaneState.templatePresent = false;
    invalidateBootstrap('locked');
    setStatus('neutral', 'Postfach erneut prüfen', 'Für diese Nachricht muss der aktuelle Absender neu mit RailTime abgeglichen werden.');
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
    view.signature.dataset.available = authenticatedBootstrap ? 'true' : 'false';
    view.template.dataset.available = authenticatedBootstrap && currentTemplate ? 'true' : 'false';
    const item = globalThis.Office?.context?.mailbox?.item;
    if (item && (isTemplateInsertionBlocked(item) || isStandaloneSignatureInsertionBlocked(item) || hasUncertainWrite(item))) {
        view.template.dataset.available = 'false';
        view.signature.dataset.available = 'false';
        view.templateActionDetail.textContent = 'Outlook hat die letzte Einfügung noch nicht eindeutig bestätigt. Nachricht prüfen; nicht erneut einfügen.';
    }
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
    if (insertionBlocked()) view.signatureDetail.textContent = 'Einfügung nicht eindeutig bestätigt. Nachricht prüfen; nicht erneut einfügen.';

    updateDisabledStates();
    syncAutomaticPreferences();
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
    syncAutomaticPreferences();
    syncAccountOptions();
}

function syncAutomaticPreferences() {
    automaticPreferencesView?.update({
        preferences: currentBootstrap?.automaticInsertionPreferences,
        available: taskpaneState.authenticated && taskpaneState.bootstrapReady && Boolean(currentConfig?.endpoints?.preferences),
        busy: taskpaneState.busy,
        account: currentBootstrap?.binding?.mailboxAddress || '',
    });
}

async function saveAutomaticPreference(change) {
    if (taskpaneState.busy || !taskpaneState.authenticated || !taskpaneState.bootstrapReady
        || !currentConfig?.endpoints?.preferences || !currentBootstrap?.binding) {
        throw codedError('PREFERENCES_UNAVAILABLE');
    }
    const target = captureComposeTarget();
    const binding = currentBootstrap.binding;
    setBusy(true);
    try {
        const accessToken = await acquireAccessToken(currentConfig, false);
        await assertWriteTarget(target, binding);
        const sender = await readComposeSender(Office, target.item);
        const response = await fetchJson(currentConfig.endpoints.preferences, {
            method: 'PATCH', cache: 'no-store', credentials: 'omit',
            headers: {
                Accept: 'application/json', 'Content-Type': 'application/json',
                Authorization: `Bearer ${accessToken}`,
                'X-RailTime-Outlook-Context': 'taskpane',
                'X-RailTime-Compose-Contract': 'native-signature-v1',
                'X-RailTime-Outlook-Mailbox': mailboxAddress(),
                'X-RailTime-Outlook-Sender': sender,
            },
            body: JSON.stringify(change),
        }, API_TIMEOUT_MS);
        await assertWriteTarget(target, response?.binding);
        const preferences = normalizeAutomaticPreferences(response?.automaticInsertionPreferences);
        if (!preferences) throw codedError('PREFERENCES_RESPONSE_INVALID');
        currentBootstrap = { ...currentBootstrap, automaticInsertionPreferences: preferences };
        return response;
    } catch (error) {
        const current = normalizeAutomaticPreferences(error?.currentPreferences);
        if (current && currentBootstrap?.binding?.mailboxAddress === binding.mailboxAddress
            && currentBootstrap?.binding?.senderAddress === binding.senderAddress) {
            try {
                await assertWriteTarget(target, binding);
                currentBootstrap = { ...currentBootstrap, automaticInsertionPreferences: current };
            } catch (targetError) {
                error = targetError;
            }
        }
        if (authenticationWasLost(error) || safeErrorCode(error) === 'HTTP_403' || /MAILBOX|SENDER|ITEM_CHANGED/.test(safeErrorCode(error))) {
            invalidateBootstrap('error', authenticationWasLost(error));
        }
        throw error;
    } finally {
        setBusy(false);
        syncActionState();
    }
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

async function acceptBootstrap(payload, { inspectBody = true } = {}) {
    bootstrapStateRevision += 1;
    currentBootstrap = payload;
    lastBootstrapRefreshAt = Date.now();
    taskpaneState.bootstrapReady = true;
    taskpaneState.signatureVersion = snapshotSignatureVersion(payload);
    renderTemplateChoices(payload);
    if (inspectBody) await refreshSignatureCurrentState();
    syncActionState();
    await refreshAccountOptions();
}

function invalidateBootstrap(templateState, authenticationLost = false) {
    bootstrapStateRevision += 1;
    signatureStateRevision += 1;
    currentBootstrap = null;
    observedComposeItem = null;
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
    if (code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE') {
        return 'Diese ältere oder bearbeitete Vorlage lässt sich nicht sicher ersetzen. Ihr Entwurf bleibt erhalten. Bitte verwenden Sie für den Vorlagenwechsel einen neuen Entwurf.';
    }
    if (code === 'COMPOSE_BODY_CHANGED') {
        return 'Die Nachricht wurde während der Vorbereitung geändert. Bitte den Vorlagenwechsel nach Ihrer Bearbeitung erneut starten.';
    }
    if (code === 'TEMPLATE_LAYOUT_UNCONFIRMED') {
        return 'Die Vorlage wurde mit Ihrem Nachrichtentext ersetzt. Outlook konnte die Darstellung jedoch nicht sicher bestätigen. Bitte den Entwurf prüfen; der Austausch wird nicht automatisch wiederholt.';
    }
    if (code === 'SIGNATURE_LAYOUT_UNCONFIRMED') {
        return 'Die Darstellung der Signatur ist nicht sicher bestätigt. Nachrichtentext und Zitate wurden nicht neu geschrieben. Bitte den Entwurf prüfen; die Aktualisierung wird nicht automatisch wiederholt.';
    }
    if (code === 'SIGNATURE_LAYOUT_UNAVAILABLE') {
        return 'Die Signatur lässt sich in diesem Entwurf nicht sicher aktualisieren. Ihr Nachrichtentext bleibt erhalten. Bitte einen neuen Entwurf verwenden.';
    }
    if (code === 'SIGNATURE_LAYOUT_LEGACY_CARRIER') {
        return 'Dieser Entwurf enthält ältere Formatierungsregeln. Bitte „Vorlage und Signatur ersetzen“ wählen oder einen neuen Entwurf öffnen, damit die Darstellung auch beim Empfänger korrekt bleibt. Ihr Nachrichtentext bleibt erhalten.';
    }

    if (/MAILBOX|SENDER/.test(code) || code === 'HTTP_403') {
        return 'Der aktuelle Absender ist nicht als Ihr RailTime-Microsoft-Postfach bestätigt. In private oder andere Postfächer wird nichts eingefügt. Bitte das verknüpfte Firmenpostfach wählen und die Verbindung erneut prüfen.';
    }
    if (code === 'COMPOSE_SESSION_UNREADABLE') {
        return 'Outlook konnte die sichere Vorprüfung nicht ausführen. Die Einfügung wurde noch nicht gestartet. Unter Status können Sie die Diagnose erneut prüfen.';
    }
    if (code === 'SIGNATURE_TOO_LARGE') {
        return 'Die Signatur überschreitet das Outlook-Limit von 30.000 Zeichen. Die veröffentlichte Signatur muss verkleinert werden; es wurde nichts eingefügt.';
    }
    if (code === 'OFFICE_WRITE_FAILED') {
        return 'Outlook hat den Schreibaufruf mit einem Fehler beantwortet. Die Diagnose unter Status zeigt den betroffenen Schritt und den Outlook-Fehlercode.';
    }
    if (error?.reason === 'exception' && /UNCERTAIN$/.test(code)) {
        return 'Die Outlook-Schnittstelle hat den Schreibaufruf sofort mit einer Ausnahme unterbrochen. Ob er angekommen ist, ist unklar. Nachricht prüfen; kein automatischer weiterer Schreibversuch. Details unter Status → Diagnose.';
    }

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
        return 'In dieser Nachricht ist bereits eine RailTime-Vorlage. Eine weitere Vorlage benötigt Ihre ausdrückliche Bestätigung; Ihr vorhandener Inhalt bleibt erhalten.';
    }
    if (code === 'SIGNATURE_WITHIN_TEMPLATE') {
        return 'Vorlage und Signatur sind in dieser Nachricht bereits ein gemeinsames Dokument. Eine getrennte Signaturaktualisierung könnte Ihren geschriebenen Text beschädigen und wird deshalb nicht ausgeführt. Für einen aktuellen vollständigen Stand bitte eine neue Nachricht öffnen.';
    }
    if (code === 'COMBINED_TEMPLATE_INVALID') {
        return 'Das gemeinsame Vorlagen- und Signaturdokument ist unvollständig. Es wurde nichts eingesetzt. Bitte die Verbindung neu prüfen.';
    }
    if (code === 'NATIVE_TEMPLATE_INVALID') {
        return 'Der getrennte Outlook-Vorlageninhalt ist unvollständig. Bitte die Verbindung neu prüfen. Es wird keine zusätzliche vollständige Vorlage eingesetzt.';
    }
    if (code === 'COMPOSE_BODY_UNREADABLE') {
        return 'Der aktuelle Nachrichtentext konnte nicht sicher geprüft werden. Es wurde kein Inhalt ersetzt. Bitte erneut versuchen.';
    }
    if (code === 'TEMPLATE_REQUIRES_HTML') {
        return 'Die Nachricht muss im HTML-Format geöffnet sein. RailTime ändert Ihr Nachrichtenformat nicht automatisch.';
    }
    if (['TEMPLATE_TOO_LARGE', 'TEMPLATE_MEDIA_TOO_LARGE', 'COMPOSE_BODY_TOO_LARGE'].includes(code)) {
        return 'Vorlage, Bilder oder Nachrichtenverlauf überschreiten das RailTime-Sicherheitsbudget für die Outlook-Einfügung. Bitte eine kompaktere Vorlage oder eine neue Nachricht verwenden.';
    }
    if (['TEMPLATE_INSERT_UNCERTAIN', 'INLINE_ATTACHMENT_UNCERTAIN', 'SIGNATURE_INSERT_UNCERTAIN'].includes(code)) {
        return 'Outlook hat die Einfügung nicht rechtzeitig bestätigt. Sie kann bereits erfolgt sein. Bitte diese Nachricht prüfen und nicht erneut einfügen; RailTime startet keinen weiteren Schreibversuch.';
    }
    if (code === 'TEMPLATE_INSERT_IN_PROGRESS') {
        return 'Für diese Nachricht läuft bereits eine Einfügung. Bitte deren Abschluss abwarten.';
    }
    if (code === 'COMPOSE_ATTACHMENTS_UNREADABLE') {
        return 'Die vorhandenen Inline-Bilder konnten nicht sicher geprüft werden. Die Einfügung wurde vor dem Hinzufügen weiterer Bilder gestoppt.';
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
    const evidence = insertionDisplayEvidence();
    const templateCount = taskpaneState.templates.length;
    const templateLabel = templateCount === 1
        ? '1 Vorlage verfügbar'
        : `${templateCount} Vorlagen verfügbar`;
    const signatureLabel = evidence.signatureCurrent
        ? 'Signatur aktuell'
        : 'Signatur prüfen';

    const templateSupport = supportsTemplatePrepend(Office, Office.context.mailbox?.item)
        ? 'Vorlagen und Signaturen werden gemeinsam verwaltet; Outlook kann CSS anpassen.'
        : 'Mobile/eingeschränkte Ansicht: automatische Signatur, vollständige Vorlagen nur im Browser/Desktop.';
    const automatic = normalizeAutomaticPreferences(currentBootstrap?.automaticInsertionPreferences)
        ? 'Die automatische Einfügung folgt Ihrer Auswahl unter „Automatik“.'
        : automaticTemplate(currentBootstrap)
        ? 'Eine veröffentlichte Outlook-Standardvorlage ist konfiguriert; der automatische Start hängt vom Outlook-Client und der Add-in-Zuweisung ab.'
        : 'Keine veröffentlichte Outlook-Standardvorlage festgelegt; automatisch ist nur die Signatur vorgesehen.';
    const confirmedParts = [evidence.signatureWriteConfirmed ? 'Signatur' : '', evidence.templateWriteConfirmed ? 'Vorlage' : ''].filter(Boolean);
    const insertion = confirmedParts.length > 0
        ? `Outlook hat die Einfügung von ${confirmedParts.join(' und ')} in dieser Nachricht bestätigt.`
        : evidence.signatureCurrent || evidence.templatePresent
            ? 'RailTime-Inhalte wurden in dieser Nachricht erkannt.'
            : 'Anmeldung und Datenabruf bestätigt, noch keine Einfügung bestätigt.';
    return `${insertion} ${templateLabel} · ${signatureLabel}. ${automatic} ${templateSupport}`;
}

function handleFailure(error, bootstrapWasLoaded = false) {
    lastActionError = error;
    recordDiagnostic(error?.phase || 'user-action', 'failed', error);
    if (safeErrorCode(error) === 'TEMPLATE_INSERT_CANCELLED') {
        setBusy(false);
        syncActionState();
        setStatus('neutral', 'Einfügen abgebrochen', 'Ihre Nachricht wurde nicht verändert.');
        return;
    }
    const authLost = authenticationWasLost(error);

    if (!bootstrapWasLoaded || authLost || /MAILBOX|SENDER/.test(safeErrorCode(error))) {
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

            if (!lastActionError && !insertionBlocked()) setStatus('success', 'Mit RailTime verbunden', readyStatusDetail());
        } catch (error) {
            if (expectedRevision !== bootstrapStateRevision || taskpaneState.busy) {
                return;
            }

            if (authenticationWasLost(error)) {
                invalidateBootstrap('locked', true);
                setStatus('neutral', 'Verbindung erneuern', 'Bitte einmal erneut mit Ihrem RailTime-Firmenkonto anmelden.');
                return;
            }

            if (/MAILBOX|SENDER/.test(safeErrorCode(error)) || safeErrorCode(error) === 'HTTP_403') {
                handleFailure(error);
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
    if (taskpaneState.busy) return;
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
        if (!lastActionError && !insertionBlocked()) setStatus('success', 'Mit RailTime verbunden', readyStatusDetail());
    } catch (error) {
        handleFailure(error);
    }
}

async function withAuthenticatedBootstrap(button, callback) {
    if (taskpaneState.settingsMode) throw codedError('ACCOUNT_SETTINGS_READ_ONLY');
    if (taskpaneState.busy) return;
    let bootstrapWasLoaded = false;

    bootstrapStateRevision += 1;
    setBusy(true, button);

    try {
        const target = captureComposeTarget();
        if (isTemplateInsertionBlocked(target.item) || isStandaloneSignatureInsertionBlocked(target.item) || hasUncertainWrite(target.item)) {
            throw codedError('TEMPLATE_INSERT_UNCERTAIN');
        }
        const accessToken = await diagnoseStep('authentication', () => acquireAccessToken(currentConfig, true));
        taskpaneState.authenticated = true;
        displayAccount();
        const bootstrap = await diagnoseStep('bootstrap', () => loadBootstrap(currentConfig, accessToken, target.item));
        await assertWriteTarget(target, bootstrap.binding);

        bootstrapWasLoaded = true;
        // The insertion owns one preflight. Avoid repeatedly serializing the
        // complete Outlook conversation during the same button action.
        signatureStateRevision += 1;
        await acceptBootstrap(bootstrap, { inspectBody: false });
        setBusy(true, button);
        await callback(bootstrap, target);
        lastActionError = null;
        setBusy(false);
        syncActionState();
    } catch (error) {
        handleFailure(error, bootstrapWasLoaded);
    }
}

async function updateSignature(button) {
    if (taskpaneState.busy) return;
    setStatus('working', 'Signatur wird aktualisiert …', 'Bilder werden sicher in die Nachricht eingebettet.');

    await withAuthenticatedBootstrap(button, async (bootstrap, target) => {
        const item = assertComposeTarget(target);
        const state = await diagnoseStep('compose-preflight', () => readTemplateState(Office, item, { forceBody: true }));
        assertComposeTarget(target);
        if (state.uncertain) throw codedError('TEMPLATE_INSERT_UNCERTAIN');
        if (!state.readable) throw Object.assign(codedError(state.errorCode || 'COMPOSE_BODY_UNREADABLE'), { phase: state.phase, officeCode: state.officeCode, reason: state.reason });
        if (state.tooLarge) throw codedError('COMPOSE_BODY_TOO_LARGE');
        if (state.present && state.legacySignatureEmbedded !== false) {
            throw codedError('SIGNATURE_WITHIN_TEMPLATE');
        }
        const signature = validatedDocument(bootstrap.signature, 'signature', currentConfig.marker);
        if (signature.html.length > 30000) throw codedError('SIGNATURE_TOO_LARGE');

        if (!item.body.setSignatureAsync) {
            throw codedError('SET_SIGNATURE_UNAVAILABLE');
        }

        if (!state.present && !isMobileComposeHost(Office) && requiresStandaloneSignatureLayout(signature.html)) {
            taskpaneState.signatureCurrent = false;
            await insertStandaloneSignature(Office, item, signature.html, () => assertWriteTarget(target, bootstrap.binding), {
                media: signature.media,
                beforeInsert: () => attachInlineMedia(target, signature.media, bootstrap.binding),
                beforeCommit: () => assertComposeTarget(target),
            });
            rememberConfirmedInsertion(item, 'signature', bootstrap.binding);
        } else {
            await attachInlineMedia(target, signature.media, bootstrap.binding);
            await assertWriteTarget(target, bootstrap.binding);
            await setSignature(item, signature.html);
        }
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

async function insertTemplate(button, { replaceOnly = false, expectedTarget = null, expectedAccount = null } = {}) {
    if (taskpaneState.busy) return { applied: false, reason: 'busy' };
    let result = { applied: false, reason: 'unconfirmed' };
    const selected = selectedTemplate();

    setStatus(
        'working',
        'Vorlage wird eingefügt …',
        selected ? `${selected.name} wird sicher geladen.` : 'Die aktuelle Veröffentlichung wird geladen.',
    );

    await withAuthenticatedBootstrap(button, async (bootstrap, target) => {
        const item = assertComposeTarget(target);
        if (expectedTarget) assertComposeTarget(expectedTarget);
        if (expectedAccount && bootstrap.binding?.mailboxAddress !== expectedAccount) throw codedError('MAILBOX_MISMATCH');
        const templateChoice = selectedTemplate();

        if (!templateChoice) {
            throw codedError('TEMPLATE_SELECTION_MISSING');
        }

        const combinedDocument = combinedComposeDocument(templateChoice.document);
        const nativeDocument = combinedDocument || nativeComposeTemplate(templateChoice.document);
        if (!nativeDocument) throw codedError('NATIVE_TEMPLATE_INVALID');
        const usesNativeSignature = !combinedDocument && nativeDocument.signatureMode === 'native';
        const template = validatedDocument(nativeDocument, 'template', usesNativeSignature ? TEMPLATE_MARKER : currentConfig.marker);
        const state = await diagnoseStep('compose-preflight', () => readTemplateState(Office, item, { forceBody: true }));
        assertComposeTarget(target);
        if (state.uncertain) throw codedError('TEMPLATE_INSERT_UNCERTAIN');
        if (!state.readable) throw Object.assign(codedError(state.errorCode || 'COMPOSE_BODY_UNREADABLE'), { phase: state.phase, officeCode: state.officeCode, reason: state.reason });
        if (state.tooLarge) throw codedError('COMPOSE_BODY_TOO_LARGE');
        const replacing = state.present;
        if (replaceOnly && !replacing) {
            result = { applied: false, reason: 'no-managed-template' };
            setStatus('ready', 'Anzeige gespeichert', 'Zum Anwenden die Standardvorlage einfügen. Ihr Nachrichtentext bleibt erhalten.');
            return;
        }
        if (replacing && !combinedDocument) throw codedError('TEMPLATE_REPLACEMENT_UNAVAILABLE');
        let signature = null;
        if (usesNativeSignature) {
            const signaturePayload = Object.prototype.hasOwnProperty.call(templateChoice.document, 'signature')
                ? templateChoice.document.signature
                : bootstrap.signature;
            signature = validatedDocument(signaturePayload, 'signature', currentConfig.marker);
            if (signature.html.length > 30000) throw codedError('SIGNATURE_TOO_LARGE');
            if (typeof item.body.setSignatureAsync !== 'function') throw codedError('SET_SIGNATURE_UNAVAILABLE');
        }
        // Budget the generated parts before the first mutation. A replacement
        // preserves the editable content in its own locked, marker-bound path.
        const media = signature ? [...signature.media, ...template.media] : template.media;
        validateTemplateInsertionPayload((signature?.html || '') + template.html, media);
        if (replacing) {
            await diagnoseStep('template-replace', () => replaceManagedTemplate(Office, item, template.html, () => assertWriteTarget(target, bootstrap.binding), {
                media,
                beforeReplace: () => attachInlineMedia(target, media, bootstrap.binding),
                beforeCommit: () => assertComposeTarget(target),
            }));
        } else {
            await diagnoseStep('template-write', () => prependTemplate(Office, item, template.html, () => assertWriteTarget(target, bootstrap.binding), {
                media,
                beforeInsert: async () => {
                    await attachInlineMedia(target, media, bootstrap.binding);
                    if (combinedDocument) {
                        if (typeof item.body.setSignatureAsync !== 'function') throw codedError('SET_SIGNATURE_UNAVAILABLE');
                        await assertWriteTarget(target, bootstrap.binding);
                        await diagnoseStep('signature-write', () => setSignature(item, ''));
                        assertComposeTarget(target);
                    }
                },
            }));
        }
        assertComposeTarget(target);
        // Prepending can reserialize the existing body in Outlook. Install the
        // native signature last, and only after a confirmed template success.
        // An uncertain prepend must never start a second native body write.
        if (signature) {
            await assertWriteTarget(target, bootstrap.binding);
            await diagnoseStep('signature-write', () => setSignature(item, signature.html));
            assertComposeTarget(target);
        }
        // Native success is sufficient; do not immediately pull the full body
        // back across the Office bridge while Outlook is rendering it.
        signatureStateRevision += 1;
        rememberConfirmedInsertion(item, 'template', bootstrap.binding);
        if (combinedDocument) rememberConfirmedInsertion(item, 'signature', bootstrap.binding);
        observedComposeItem = item;
        taskpaneState.templatePresent = true;
        result = { applied: true };
        renderSelectedTemplate();

        setStatus(
            'success',
            replacing ? 'Vorlage und Signatur ersetzt' : combinedDocument ? 'Vorlage und Signatur gemeinsam eingefügt' : 'Vorlage wurde oberhalb eingefügt',
            templateChoice.version !== ''
                ? `${templateChoice.name} · Version ${templateChoice.version}`
                : templateChoice.name,
        );
    });
    return result;
}

async function showDiagnosticReport() {
    const output = document.querySelector('[data-outlook-diagnostics-output]');
    if (!output) return;
    let boundMailbox = false;
    let boundMailboxChecked = false;
    if (taskpaneState.authenticated && taskpaneState.bootstrapReady && currentBootstrap) {
        const payload = currentBootstrap;
        try {
            const target = captureComposeTarget();
            boundMailboxChecked = true;
            await diagnoseStep('diagnostic-mailbox-binding', () => assertWriteTarget(target, payload.binding));
            boundMailbox = taskpaneState.authenticated && taskpaneState.bootstrapReady
                && payload.binding?.mailboxAddress === currentBootstrap?.binding?.mailboxAddress
                && payload.binding?.senderAddress === currentBootstrap?.binding?.senderAddress;
        } catch {
            // A failed current From read is not a successful historical check.
            // Reporting itself never writes or grants permission to insert.
        }
    }
    const report = diagnosticReport(globalThis.Office, {
        configured: taskpaneState.configReady, authenticated: taskpaneState.authenticated,
        bootstrapReady: taskpaneState.bootstrapReady, boundMailbox, boundMailboxChecked,
        automaticTemplate: Boolean(automaticTemplate(currentBootstrap)), writeBlocked: insertionBlocked(),
        ...(boundMailbox ? insertionDisplayEvidence() : {}),
    });
    output.value = JSON.stringify(report, null, 2);
    output.hidden = false;
}

async function runDiagnostics(button) {
    if (taskpaneState.busy || button.disabled) return;
    button.disabled = true;
    setBusy(true);
    let boundMailbox = false;
    try {
        const target = captureComposeTarget();
        currentConfig = await diagnoseStep('configuration', loadConfig);
        taskpaneState.configReady = true;
        const accessToken = await diagnoseStep('authentication', () => acquireAccessToken(currentConfig, false));
        taskpaneState.authenticated = true;
        const bootstrap = await diagnoseStep('bootstrap', () => loadBootstrap(currentConfig, accessToken, target.item));
        await assertWriteTarget(target, bootstrap.binding);
        boundMailbox = true;
        await acceptBootstrap(bootstrap, { inspectBody: false });
        const state = await diagnoseStep('compose-preflight', () => readTemplateState(Office, target.item));
        if (!state.readable) throw Object.assign(codedError(state.errorCode || (state.uncertain ? 'TEMPLATE_INSERT_UNCERTAIN' : 'COMPOSE_BODY_UNREADABLE')), { phase: state.phase, officeCode: state.officeCode, reason: state.reason });
        if (state.tooLarge) throw codedError('COMPOSE_BODY_TOO_LARGE');
        await assertWriteTarget(target, bootstrap.binding);
        if (insertionBlocked()) throw codedError('TEMPLATE_INSERT_UNCERTAIN');
        lastActionError = null;
        setStatus('success', 'Verbindung und Vorprüfung bestätigt', `${readyStatusDetail()} Die Diagnose hat nichts in Ihre Nachricht eingefügt.`);
    } catch (error) {
        handleFailure(error, boundMailbox);
    } finally {
        setBusy(false);
        button.disabled = false;
        syncActionState();
        await showDiagnosticReport();
    }
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
    if (taskpaneState.settingsMode) throw codedError('ACCOUNT_SETTINGS_READ_ONLY');
    const view = elements();
    automaticPreferencesView = bindAutomaticPreferencesView(document, { save: async change => {
        const response = await saveAutomaticPreference(change);
        await refreshAccountOptions();
        return response;
    } });
    bindAccountOptionViews();

    view.login.addEventListener('click', () => authenticateInteractively(view.login));
    view.signature.addEventListener('click', () => updateSignature(view.signature));
    view.template.addEventListener('click', () => insertTemplate(view.template));
    const diagnosticButton = document.querySelector('[data-outlook-diagnostics-run]');
    diagnosticButton?.addEventListener('click', () => runDiagnostics(diagnosticButton));
    document.querySelector('[data-outlook-diagnostics-copy]')?.addEventListener('click', async () => {
        const output = document.querySelector('[data-outlook-diagnostics-output]');
        await showDiagnosticReport();
        if (!output?.value) return;
        try {
            await navigator.clipboard.writeText(output.value);
            document.querySelector('[data-outlook-diagnostics-feedback]').textContent = 'Diagnose kopiert – ohne Nachrichtentext, Adressen oder Zugangsdaten.';
        } catch {
            output.focus(); output.select();
            document.querySelector('[data-outlook-diagnostics-feedback]').textContent = 'Bitte den markierten Diagnosebericht kopieren.';
        }
    });
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

function bindAccountOptionViews() {
    wordingView = bindWordingPreferencesView(document, {
        save: change => changeAccountOption(wordingSession, change),
        reload: () => changeAccountOption(wordingSession, null),
        beforeSave: () => {
            if (taskpaneState.settingsMode) return null;
            try { return captureComposeTarget(); } catch { return null; }
        },
        afterSaved: async context => {
            if (taskpaneState.settingsMode || !context?.target) return 'Gespeichert. Gilt beim nächsten Einfügen der Standardvorlage.';
            try {
                if (mailboxAddress().toLowerCase() !== context.account) throw codedError('MAILBOX_MISMATCH');
                assertComposeTarget(context.target);
            } catch { return 'Gespeichert. Die Nachricht oder das Konto hat gewechselt; die offene Nachricht bleibt unverändert.'; }
            const result = await insertTemplate(null, { replaceOnly: true, expectedTarget: context.target, expectedAccount: context.account });
            return result?.applied ? 'Gespeichert und in dieser Nachricht aktualisiert.'
                : result?.reason === 'no-managed-template' ? 'Gespeichert. Zum Anwenden die Standardvorlage einfügen.'
                    : 'Gespeichert. Die offene Nachricht wurde nicht verändert; Standardvorlage erneut einsetzen.';
        },
    });
    templateChoicesView = bindTemplateChoiceView(document, { save: change => changeAccountOption(templateChoicesSession, change) });
}

function configureAccountOptions() {
    const create = (kind, endpoint) => endpoint ? createAccountOptionsSession({ kind, readMailbox: mailboxAddress,
        authenticate: allowPopup => acquireAccessToken(currentConfig, allowPopup),
        request: options => fetchJson(endpoint, options, API_TIMEOUT_MS) }) : null;
    wordingSession = create('wording', currentConfig?.endpoints.accountWording);
    templateChoicesSession = create('choices', currentConfig?.endpoints.accountTemplateChoices);
    wordingReady = templateChoicesReady = false;
}

function syncAccountOptions() {
    for (const [session, view, ready] of [[wordingSession, wordingView, wordingReady], [templateChoicesSession, templateChoicesView, templateChoicesReady]]) {
        const snapshot = session?.current();
        view?.update({ preferences: snapshot?.preferences, account: snapshot?.account || '',
            available: ready && Boolean(snapshot), busy: accountOptionsBusy || taskpaneState.busy || accountSettingsBusy });
    }
}

async function refreshAccountOptions() {
    const sessions = [wordingSession, templateChoicesSession];
    const results = await Promise.allSettled(sessions.map(session => session ? session.load(false) : Promise.resolve(null)));
    wordingReady = Boolean(wordingSession) && results[0].status === 'fulfilled';
    templateChoicesReady = Boolean(templateChoicesSession) && results[1].status === 'fulfilled';
    syncAccountOptions();
    if (results[0]?.status === 'rejected' && wordingSession) wordingView?.unavailable();
}

async function changeAccountOption(session, change) {
    if (!session || accountOptionsBusy || taskpaneState.busy || accountSettingsBusy) throw codedError('PREFERENCES_UNAVAILABLE');
    accountOptionsBusy = true;
    if (taskpaneState.settingsMode) { accountSettingsBusy = true; renderAccountSettings(); }
    else setBusy(true);
    syncAccountOptions();
    try {
        const response = change === null ? await session.load(false) : await session.save(change);
        if (session === wordingSession) wordingReady = true;
        if (session === templateChoicesSession) templateChoicesReady = true;
        if (response?.automaticInsertionPreferences) {
            const preferences = normalizeAutomaticPreferences(response.automaticInsertionPreferences);
            if (!preferences) throw codedError('PREFERENCES_RESPONSE_INVALID');
            if (taskpaneState.settingsMode) await accountPreferencesSession.load(false);
            else if (currentBootstrap) currentBootstrap = { ...currentBootstrap, automaticInsertionPreferences: preferences };
        }
        return response;
    } finally {
        accountOptionsBusy = false;
        if (taskpaneState.settingsMode) { accountSettingsBusy = false; renderAccountSettings(); }
        else setBusy(false);
        syncAccountOptions();
    }
}

function renderAccountSettings(tone, message) {
    const root = document.querySelector('[data-outlook-addin-taskpane]');
    const status = document.querySelector('[data-outlook-settings-status]');
    const connect = document.querySelector('[data-outlook-settings-connect]');
    const snapshot = accountPreferencesSession?.current();
    root?.setAttribute('aria-busy', accountSettingsBusy ? 'true' : 'false');
    if (status && typeof message === 'string') {
        status.textContent = message;
        status.dataset.tone = tone;
        status.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    }
    if (connect) {
        connect.disabled = !currentConfig || accountSettingsBusy;
        connect.textContent = accountSettingsReady ? 'Verbindung prüfen' : 'Mit Microsoft verbinden';
    }
    automaticPreferencesView?.update({ preferences: snapshot?.preferences,
        available: accountSettingsReady && Boolean(snapshot), busy: accountSettingsBusy, account: snapshot?.account || '' });
    syncAccountOptions();
}

function accountSettingsFailure(error) {
    accountSettingsReady = false;
    const code = safeErrorCode(error);
    const profileLink = document.querySelector('[data-outlook-profile-settings]');
    if (profileLink) profileLink.hidden = false;
    const message = code === 'NAA_NOT_SUPPORTED'
        ? 'Diese Outlook-Version unterstützt die sichere Anmeldung hier nicht. Öffnen Sie Ihre Outlook-Einstellungen in RailTime.'
        : code === 'MAILBOX_MISMATCH' || code === 'ACCOUNT_PREFERENCES_BINDING_INVALID'
            ? 'Das eigene Outlook-Konto konnte nicht sicher bestätigt werden. Bitte erneut verbinden.'
            : isInteractionRequired(error)
                ? 'Verbinden Sie Ihr Microsoft-Firmenkonto oder öffnen Sie Ihre Outlook-Einstellungen in RailTime.'
                : 'Die Einstellungen konnten nicht sicher geladen oder gespeichert werden. Bitte die Verbindung erneut prüfen oder RailTime öffnen.';
    renderAccountSettings(isInteractionRequired(error) ? 'neutral' : 'error', message);
}

async function refreshAccountSettings(allowPopup = false) {
    if (!taskpaneState.settingsMode || accountSettingsBusy || !accountPreferencesSession) return;
    accountSettingsBusy = true;
    renderAccountSettings('working', 'Ihr eigenes Firmenkonto wird geprüft …');
    try {
        await accountPreferencesSession.load(allowPopup);
        await refreshAccountOptions();
        accountSettingsReady = true;
        lastAccountSettingsRefreshAt = Date.now();
        renderAccountSettings('success', 'Verbunden. Ihre Auswahl gilt für Ihr Firmenkonto auf allen Geräten.');
    } catch (error) { accountSettingsFailure(error); }
    finally { accountSettingsBusy = false; renderAccountSettings(); }
}

async function saveAccountPreference(change) {
    if (!taskpaneState.settingsMode || !accountSettingsReady || accountSettingsBusy) throw codedError('PREFERENCES_UNAVAILABLE');
    accountSettingsBusy = true;
    renderAccountSettings('working', 'Ihre Auswahl wird gespeichert …');
    try {
        const response = await accountPreferencesSession.save(change);
        await refreshAccountOptions();
        renderAccountSettings('success', 'Gespeichert. Gilt für künftige Einfügungen auf allen Geräten.');
        return response;
    } catch (error) {
        if (!accountPreferencesSession.current() || authenticationWasLost(error) || /MAILBOX|BINDING/.test(safeErrorCode(error))) accountSettingsFailure(error);
        else renderAccountSettings('error', 'Speichern nicht bestätigt. Bitte den aktuellen Stand der Schalter beachten.');
        throw error;
    } finally { accountSettingsBusy = false; renderAccountSettings(); }
}

async function initializeAccountSettings() {
    automaticPreferencesView = bindAutomaticPreferencesView(document, { save: saveAccountPreference });
    bindAccountOptionViews();
    const connect = document.querySelector('[data-outlook-settings-connect]');
    connect?.addEventListener('click', () => refreshAccountSettings(true));
    accountSettingsBusy = true;
    renderAccountSettings('working', 'Die sichere Kontoverbindung wird geprüft …');
    try {
        currentConfig = await loadConfig();
        if (!currentConfig.endpoints.accountPreferences) throw codedError('CONFIG_ACCOUNT_PREFERENCES_URL_INVALID');
        const profileLink = document.querySelector('[data-outlook-profile-settings]');
        if (profileLink && currentConfig.urls.profileSettings) profileLink.href = currentConfig.urls.profileSettings;
        accountPreferencesSession = createAccountPreferencesSession({
            readMailbox: mailboxAddress,
            authenticate: allowPopup => acquireAccessToken(currentConfig, allowPopup),
            request: options => fetchJson(currentConfig.endpoints.accountPreferences, options, API_TIMEOUT_MS),
        });
        configureAccountOptions();
    } catch (error) { accountSettingsFailure(error); }
    finally { accountSettingsBusy = false; renderAccountSettings(); }
    if (!accountPreferencesSession) return;
    const refreshOnFocus = () => {
        if (!accountSettingsBusy && Date.now() - lastAccountSettingsRefreshAt >= SILENT_BOOTSTRAP_REFRESH_INTERVAL_MS) refreshAccountSettings(false);
    };
    window.addEventListener('focus', refreshOnFocus);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshOnFocus(); });
    await refreshAccountSettings(false);
}

async function initialize() {
    if (taskpaneState.settingsMode) return initializeAccountSettings();
    bindActions();
    clearTemplateChoices('loading');
    syncActionState();
    setBusy(true);
    setStatus('working', 'Outlook wird vorbereitet …', 'Die sichere Verbindung wird geprüft.');

    try {
        currentConfig = await loadConfig();
        configureAccountOptions();
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
            setStatus('success', 'Mit RailTime verbunden', readyStatusDetail());
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
    taskpaneState.settingsMode = root.dataset?.outlookContext === 'settings'
        || (typeof URLSearchParams === 'function' && new URLSearchParams(globalThis.location?.search || '').get('context') === 'settings');

    // Installation and help are ordinary browser UI, not Office host APIs.
    // Keep them usable even if Office.js is blocked or its handshake is pending.
    bindOutlookDialogs();

    const showOutsideOutlook = () => {
        if (taskpaneState.settingsMode) {
            accountSettingsReady = false;
            renderAccountSettings('neutral', 'Öffnen Sie Ihre Outlook-Einstellungen in RailTime. Hier außerhalb von Outlook wird keine Nachricht gelesen oder verändert.');
            return;
        }
        taskpaneState.configReady = false;
        clearTemplateChoices('error');
        setBusy(false);
        syncActionState();
        const view = elements();
        const message = view.templateRegion?.querySelector('[data-outlook-template-error]');
        if (message) message.textContent = 'Bitte RailTime in Outlook unter „Apps“ öffnen, um eine Vorlage in Ihre Nachricht einzufügen.';
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
