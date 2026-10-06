import {
    InteractionRequiredAuthError,
    createNestablePublicClientApplication,
} from '@azure/msal-browser';
import {
    automaticTemplate,
    combinedComposeDocument,
    hasMobileTemplateOwnership,
    insertMobileCombinedTemplate,
    isMobileComposeHost,
    isTemplateInsertionBlocked,
    nativeComposeTemplate,
    mobileCombinedComposeDocument,
    prependTemplate,
    readTemplateState,
    supportsTemplatePrepend,
    validateTemplateInsertionPayload,
} from './compose-template.js';
import { readComposeSender, assertMailboxBinding } from './mailbox-guard.js';
import { diagnoseStep, recordDiagnostic } from './diagnostics.js';
import { confirmedOfficeWrite, hasUncertainWrite, wasSignatureWriteConfirmed } from './office-write.js';

const CONFIG_META_NAME = 'railtime-outlook-config-url';
const CONFIG_TIMEOUT_MS = 8000;
const API_TIMEOUT_MS = 12000;
const AUTH_TIMEOUT_MS = 15000;
const CONTEXT_TIMEOUT_MS = 5000;
const MOBILE_EVENT_TIMEOUT_MS = 55000;
const LOG_PREFIX = '[RailTime Outlook Add-in]';

let configPromise;
let authenticationClientPromise;
const composeOperations = new WeakMap();
const confirmedInlineMedia = new WeakMap();
const pendingNativeSignatures = new WeakMap();
const reportedRuntimePhases = new Set();
const RUNTIME_PHASES = new Set([
    'runtime-loaded', 'office-ready', 'handler-entered', 'context-ready',
    'configuration-started', 'authentication-started', 'bootstrap-started',
    'compose-started', 'compose-applied', 'compose-skipped', 'event-completed',
    'configuration-failed', 'authentication-failed', 'bootstrap-failed', 'compose-failed',
]);

// Temporary startup evidence in the existing public config access log. Only
// fixed labels/revision: no mailbox, token, message, exception or device data.
// Never await a probe, and never let diagnosis block composing/sending.
function reportRuntimePhase(phase) {
    if (typeof document === 'undefined' || typeof fetch !== 'function'
        || !RUNTIME_PHASES.has(phase) || reportedRuntimePhases.has(phase)) return;
    reportedRuntimePhases.add(phase);
    try {
        const url = new URL(configuredUrl());
        url.searchParams.set('rt_phase', phase);
        url.searchParams.set('rt_rev', 'mobile-combined-20261006');
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller ? setTimeout(() => controller.abort(), 2000) : null;
        Promise.resolve().then(() => fetch(url.toString(), {
            method: 'GET', cache: 'no-store', credentials: 'omit',
            referrerPolicy: 'no-referrer', signal: controller?.signal,
        })).catch(() => {}).finally(() => { if (timer !== null) clearTimeout(timer); });
    } catch { /* Startup diagnostics are best-effort only. */ }
}

function withDeadline(operation, timeoutMs, code) {
    let timer;
    return Promise.race([
        Promise.resolve().then(operation),
        new Promise((_resolve, reject) => { timer = setTimeout(() => reject(codedError(code)), timeoutMs); }),
    ]).finally(() => clearTimeout(timer));
}

async function composeItemWhenReady() {
    const deadline = Date.now() + CONTEXT_TIMEOUT_MS;
    do {
        const item = globalThis.Office?.context?.mailbox?.item;
        if (item) return item;
        await new Promise((resolve) => setTimeout(resolve, 50));
    } while (Date.now() < deadline);
    throw codedError('COMPOSE_CONTEXT_UNAVAILABLE');
}

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
    const mobile = isMobileComposeHost(Office);
    const payload = await fetchJson(config.endpoints.bootstrap, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'omit',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${accessToken}`,
            'X-RailTime-Outlook-Context': 'event',
            'X-RailTime-Compose-Contract': 'native-signature-v1',
            ...(mobile ? { 'X-RailTime-Outlook-Profile': 'mobile-ledger-v1' } : {}),
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

    if (!(kind === 'template' && payload.signatureMode === 'native')
        && !html.includes(marker) && !html.includes(markerValue)) {
        html += markerValue;
    }

    return Object.freeze({ html, media });
}

function addInlineAttachment(item, media) {
    return confirmedOfficeWrite(Office, item, 'attachment-write', 'INLINE_ATTACHMENT_UNCERTAIN',
        (callback) => item.addFileAttachmentFromBase64Async(media.base64, media.name, { isInline: true }, callback));
}

async function attachInlineMedia(item, media, assertTarget) {
    if (media.length === 0) return;
    const knownNames = confirmedInlineMedia.get(item) || new Set();
    const existingNames = await new Promise((resolve, reject) => {
        // Mobile exposes some desktop API stubs although enumeration is not
        // in its supported Compose API matrix. The supported Base64 inline
        // attachment API is sufficient; retain confirmations for this item.
        if (isMobileComposeHost(Office) || typeof item.getAttachmentsAsync !== 'function') {
            resolve(new Set(knownNames));
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
        knownNames.add(media[index].name.toLowerCase());
        confirmedInlineMedia.set(item, knownNames);
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

async function applyPublishedContent(item, isActive = () => true) {
    let binding;
    const assertTarget = async () => {
        if (!isActive()) throw codedError('COMPOSE_EVENT_TIMEOUT');
        if (Office.context.mailbox.item !== item) throw codedError('ITEM_CHANGED');
        await assertMailboxBinding(Office, item, binding);
        if (!isActive()) throw codedError('COMPOSE_EVENT_TIMEOUT');
    };
    reportRuntimePhase('configuration-started');
    const config = await diagnoseStep('configuration', loadConfig).catch((error) => {
        reportRuntimePhase('configuration-failed'); throw error;
    });
    reportRuntimePhase('authentication-started');
    const accessToken = await diagnoseStep('authentication', () => withDeadline(
        () => acquireTokenSilently(config), AUTH_TIMEOUT_MS, 'AUTH_TIMEOUT',
    )).catch((error) => {
        // The native bridge can otherwise remain pending for the entire event.
        if (error?.code === 'AUTH_TIMEOUT') authenticationClientPromise = null;
        reportRuntimePhase('authentication-failed'); throw error;
    });
    reportRuntimePhase('bootstrap-started');
    const bootstrap = await diagnoseStep('bootstrap-binding', () => loadBootstrap(config, accessToken, item)).catch((error) => {
        reportRuntimePhase('bootstrap-failed'); throw error;
    });
    binding = bootstrap.binding;
    await assertTarget();
    reportRuntimePhase('compose-started');

    if (isTemplateInsertionBlocked(item) || hasUncertainWrite(item)) return 'uncertain';

    // Older templates contain an ordinary HTML signature. Never append a
    // second native signature or rewrite those drafts to convert them. Read
    // the actual body even when SessionData says a template already exists:
    // only the new, explicit native-signature contract may share this path.
    const templateState = await readTemplateState(Office, item, { forceBody: true });
    await assertTarget();
    if (templateState.uncertain) return 'uncertain';
    if (!templateState.readable || templateState.tooLarge) {
        recordDiagnostic('compose-preflight', 'skipped', { code: templateState.errorCode || 'COMPOSE_BODY_UNREADABLE' });
        return 'skipped';
    }
    if (templateState.legacySignatureEmbedded) return 'already-present';
    if (isMobileComposeHost(Office) && (templateState.present || await hasMobileTemplateOwnership(Office, item))) {
        // Never refresh a mobile native slot that may now contain editable
        // message text, even if its original default was later withdrawn.
        return 'already-present';
    }

    const canInsertTemplate = supportsTemplatePrepend(Office, item);
    let template = null;
    const selected = templateState.present ? null : automaticTemplate(bootstrap);
    const mobileSelected = isMobileComposeHost(Office) ? automaticTemplate(bootstrap) : null;
    if (mobileSelected && mobileSelected.mobileComposeDocumentMode !== undefined) {
        // The approved mobile profile is already one complete, budgeted native
        // document. No template/body-selection write or later partial signature
        // refresh follows the only nonempty supported native signature call.
        // A completed native-slot session still owns its editable text even
        // after the user removes visible/hidden markers. Never let present=true
        // bypass this coordinator and fall through to a standalone signature.
        const combined = validatedDocument(mobileCombinedComposeDocument(mobileSelected), 'template', config.marker);
        if (typeof item?.addFileAttachmentFromBase64Async !== 'function') throw codedError('COMPOSE_API_UNAVAILABLE');
        try {
            await diagnoseStep('template-write', () => insertMobileCombinedTemplate(Office, item, combined.html, assertTarget, {
                media: combined.media,
                beforeInsert: () => attachInlineMedia(item, combined.media, assertTarget),
            }));
            pendingNativeSignatures.delete(item);
            return 'applied';
        } catch (error) {
            const code = safeErrorCode(error);
            if (code === 'TEMPLATE_ALREADY_INSERTED') return 'already-present';
            if (['TEMPLATE_INSERT_IN_PROGRESS', 'TEMPLATE_INSERT_UNCERTAIN',
                'INLINE_ATTACHMENT_UNCERTAIN', 'SIGNATURE_INSERT_UNCERTAIN'].includes(code)) return 'uncertain';
            throw error;
        }
    }
    if (selected && canInsertTemplate && selected.composeDocumentMode !== undefined) {
        // One nonempty body write owns template AND signature. Clear only the
        // host-owned signature before that write, never rewrite the full body
        // or later replace the user's editable combined document.
        const combined = validatedDocument(combinedComposeDocument(selected), 'template', config.marker);
        if (typeof item?.body?.setSignatureAsync !== 'function'
            || typeof item?.addFileAttachmentFromBase64Async !== 'function') {
            throw codedError('COMPOSE_API_UNAVAILABLE');
        }
        validateTemplateInsertionPayload(combined.html, combined.media);
        try {
            await diagnoseStep('template-write', () => prependTemplate(Office, item, combined.html, assertTarget, {
                media: combined.media,
                beforeInsert: async () => {
                    await attachInlineMedia(item, combined.media, assertTarget);
                    await assertTarget();
                    await setSignature(item, '');
                    await assertTarget();
                },
            }));
            pendingNativeSignatures.delete(item);
            return 'applied';
        } catch (error) {
            const code = safeErrorCode(error);
            if (code === 'TEMPLATE_ALREADY_INSERTED') return 'already-present';
            if (['TEMPLATE_INSERT_IN_PROGRESS', 'TEMPLATE_INSERT_UNCERTAIN',
                'INLINE_ATTACHMENT_UNCERTAIN', 'SIGNATURE_INSERT_UNCERTAIN'].includes(code)) return 'uncertain';
            throw error;
        }
    }
    if (selected) {
        try {
            const composeDocument = nativeComposeTemplate(selected);
            // Legacy snapshots never fall back to their full-template HTML.
            if (composeDocument) {
                // Mobile shares the published selection and its paired
                // signature, but must never attach unused template media or
                // attempt desktop-only body writes.
                template = validatedDocument(composeDocument, 'template', config.marker);
            }
        } catch (error) {
            template = null;
            recordDiagnostic('template-preflight', 'skipped', error);
            console.info(`${LOG_PREFIX} Default template skipped (${safeErrorCode(error)}).`);
        }
    }

    const signaturePayload = template && selected
        && Object.prototype.hasOwnProperty.call(selected, 'signature')
        ? selected.signature
        : bootstrap.signature;
    const pendingSignature = pendingNativeSignatures.get(item);
    const samePendingBinding = pendingSignature?.mailboxAddress === binding.mailboxAddress
        && pendingSignature?.senderAddress === binding.senderAddress;
    const signature = wasSignatureWriteConfirmed(item) && !template
        ? null : !template && samePendingBinding
            ? pendingSignature.signature : validatedDocument(signaturePayload, 'signature', config.marker);
    if (!canInsertTemplate && template) {
        template = null;
        recordDiagnostic('template-preflight', 'skipped', { code: 'TEMPLATE_PREPEND_UNAVAILABLE' });
    }
    if (signature) {
        if (signature.html.length > 30000) throw codedError('SIGNATURE_TOO_LARGE');
        if (!item?.body?.setSignatureAsync || !item?.addFileAttachmentFromBase64Async) {
            throw codedError('COMPOSE_API_UNAVAILABLE');
        }
        validateTemplateInsertionPayload(signature.html, signature.media);
    }

    if (template) {
        // Budget both independently inserted artifacts before the first
        // Office mutation. Eine explizit vorhandene, aber ungueltige
        // Vorlagensignatur faellt dabei niemals auf den globalen Stand zurueck.
        try {
            validateTemplateInsertionPayload((signature?.html || '') + template.html,
                [...(signature?.media || []), ...template.media]);
        } catch (error) {
            template = null;
            recordDiagnostic('template-preflight', 'skipped', error);
            console.info(`${LOG_PREFIX} Default template skipped (${safeErrorCode(error)}).`);
        }
    }

    // Outlook can normalize the complete editor when prepending HTML. Finish
    // the body template first; the native signature is the LAST body write.
    // Never continue after an unconfirmed prepend: its late completion could
    // otherwise normalize a signature that was inserted in the meantime.
    if (template) {
        // Prepare both artifacts before making the template visible, matching
        // the manual path. Keep the native signature as the last body write.
        const media = signature ? [...signature.media, ...template.media] : template.media;
        try {
            await diagnoseStep('template-write', () => prependTemplate(Office, item, template.html, assertTarget, {
                media,
                beforeInsert: () => attachInlineMedia(item, media, assertTarget),
            }));
            if (signature) pendingNativeSignatures.set(item, {
                signature, mailboxAddress: binding.mailboxAddress, senderAddress: binding.senderAddress,
            });
        } catch (error) {
            const code = safeErrorCode(error);
            if (code === 'ITEM_CHANGED' || /MAILBOX|SENDER/.test(code)) throw error;
            if (['TEMPLATE_INSERT_IN_PROGRESS', 'TEMPLATE_INSERT_UNCERTAIN',
                'INLINE_ATTACHMENT_UNCERTAIN'].includes(code)) return 'uncertain';
            console.info(`${LOG_PREFIX} Default template skipped (${code}).`);
            if (!['TEMPLATE_ALREADY_INSERTED', 'TEMPLATE_PREPEND_UNAVAILABLE',
                'TEMPLATE_REQUIRES_HTML', 'NATIVE_TEMPLATE_INVALID', 'TEMPLATE_TOO_LARGE',
                'TEMPLATE_MEDIA_TOO_LARGE'].includes(code)) return 'skipped';
        }
    }

    if (signature) {
        await attachInlineMedia(item, signature.media, assertTarget);
        await assertTarget();
        await setSignature(item, signature.html);
    }
    pendingNativeSignatures.delete(item);
    return 'applied';
}

// Mobile has no compose taskpane. Surface only fixed diagnostic categories,
// never exception messages, tokens, addresses or message content.
async function notifyMobileFailure(item, error = null) {
    const context = globalThis.Office?.context;
    if (!isMobileComposeHost(globalThis.Office)
        || !item || context?.mailbox?.item !== item
        || typeof item?.notificationMessages?.replaceAsync !== 'function') return;

    const code = safeErrorCode(error);
    const reason = code === 'AUTH_INTERACTION_REQUIRED'
        ? 'Microsoft-Anmeldung erforderlich (RT-AUTH).'
        : code === 'NAA_NOT_SUPPORTED'
            ? 'Microsoft-Anmeldung hier nicht verfügbar (RT-NAA).'
            : /^(HTTP_401|HTTP_403)$/.test(code)
                ? 'Zugriff nicht bestätigt (RT-ACCESS).'
                : /^(MAILBOX|SENDER|ITEM_CHANGED)/.test(code)
                    ? 'Absenderprüfung fehlgeschlagen (RT-SENDER).'
                    : 'Einfügung nicht bestätigt (RT-COMPOSE).';

    await new Promise((resolve) => {
        const timer = setTimeout(resolve, 1000);
        const done = () => { clearTimeout(timer); resolve(); };
        try {
            item.notificationMessages.replaceAsync('railtime-signature-status', {
                type: 'informationalMessage', icon: 'none', persistent: false,
                message: `RailTime: ${reason} Signatur vor dem Senden prüfen.`,
            }, done);
        } catch { done(); }
    });
}

async function handleComposeEvent(event) {
    const complete = completeOnce(event);
    const startedAt = Date.now();
    let item;
    reportRuntimePhase('handler-entered');

    try {
        item = await composeItemWhenReady();
        reportRuntimePhase('context-ready');
        let operation = composeOperations.get(item);
        if (!operation) {
            operation = { active: true, promise: null };
            const owner = operation;
            operation.promise = applyPublishedContent(item, () => owner.active).then((result) => {
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
        let result;
        try {
            result = await (isMobileComposeHost(Office)
                ? withDeadline(() => operation.promise,
                    Math.max(1, MOBILE_EVENT_TIMEOUT_MS - (Date.now() - startedAt)), 'COMPOSE_EVENT_TIMEOUT')
                : operation.promise);
        } catch (error) {
            // Keep the operation shared until its current Office callback has
            // settled. It may not start any later writes after event expiry.
            if (error?.code === 'COMPOSE_EVENT_TIMEOUT') operation.active = false;
            throw error;
        }
        reportRuntimePhase(['applied', 'already-present'].includes(result) ? 'compose-applied' : 'compose-skipped');
        if (['skipped', 'uncertain'].includes(result)) await notifyMobileFailure(item);
    } catch (error) {
        reportRuntimePhase('compose-failed');
        recordDiagnostic('compose-event', 'failed', error);
        // Event activation must never prevent the user from composing or sending.
        console.info(`${LOG_PREFIX} Signature skipped (${safeErrorCode(error)}).`);
        await notifyMobileFailure(item, error);
    } finally {
        complete();
        reportRuntimePhase('event-completed');
    }
}

let associatedActions;
function associateHandlers() {
    const actions = globalThis.Office?.actions;
    if (typeof actions?.associate !== 'function' || actions === associatedActions) return;

    actions.associate('onMessageComposeHandler', handleComposeEvent);
    actions.associate('onNewMessageComposeHandler', handleComposeEvent);
    associatedActions = actions;
}

// Mobile HTML runtimes can resolve the manifest's FunctionName on window.
// Vite's IIFE otherwise hides it, even though the script loaded successfully.
// Keep the Office action mapping too: the Windows JS-only runtime requires it.
globalThis.onMessageComposeHandler = handleComposeEvent;
globalThis.onNewMessageComposeHandler = handleComposeEvent;
reportRuntimePhase('runtime-loaded');
associateHandlers();
// Do not postpone the first registration until onReady (JS-only activation).
// Retry only the mapping if an HTML host exposes Office.actions later.
Office.onReady(() => {
    reportRuntimePhase('office-ready');
    associateHandlers();
});
