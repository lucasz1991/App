/**
 * RailTime Assist <-> LMZ editor bridge.
 *
 * Domain editors register exactly one active adapter through
 * `railtime-pagebuilder-adapter-register` with `{ adapter }`. An adapter must
 * expose `getContext()` and may expose the command methods listed in
 * METHOD_BY_COMMAND below. It must return true (or `{ status: 'applied' }`)
 * only after the local operation completed. No editor project, HTML or CSS is
 * ever sent through this bridge.
 */

const REGISTER_EVENT = 'railtime-pagebuilder-adapter-register';
const UNREGISTER_EVENT = 'railtime-pagebuilder-adapter-unregister';
const CONTEXT_REQUEST_EVENT = 'railtime-pagebuilder-context-request';
const CONTEXT_CHANGED_EVENT = 'railtime-pagebuilder-context-changed';
const CONTEXT_UPDATED_EVENT = 'railtime-pagebuilder-context-updated';
const RESULT_EVENT = 'railtime-pagebuilder-assistant-result';

const ROUTES = Object.freeze({
    'admin.marketing.creatives.editor': 'marketing',
    'admin.mail-documents.editor': 'mail',
});

const PANELS = new Set([
    'blocks', 'layers', 'styles', 'traits', 'assets', 'spacing', 'media', 'animation',
]);

const MARKETING_BLOCKS = new Set([
    'rt-marketing-logo-light', 'rt-marketing-logo-dark', 'rt-marketing-hero',
    'rt-marketing-kicker', 'rt-marketing-headline', 'rt-marketing-facts',
    'rt-marketing-tasks', 'rt-marketing-profile', 'rt-marketing-benefits',
    'rt-marketing-contact', 'rt-marketing-cta', 'rt-marketing-qr',
]);

const MAIL_BLOCKS = new Set([
    'rt-mail-section', 'rt-mail-two-columns', 'rt-mail-heading',
    'rt-mail-paragraph', 'rt-mail-button', 'rt-mail-divider', 'rt-mail-spacer',
]);

const MARKETING_STYLES = new Set([
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'width', 'height', 'max-width', 'min-height', 'font-size', 'font-weight',
    'line-height', 'letter-spacing', 'text-align', 'color', 'background-color',
    'border', 'border-radius', 'opacity', 'object-fit', 'object-position',
    'display', 'gap', 'justify-content', 'align-items',
]);

const MAIL_STYLES = new Set([
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'width', 'max-width', 'height', 'font-size', 'font-weight', 'line-height',
    'text-align', 'vertical-align', 'color', 'background-color', 'border', 'border-radius',
]);

const BASE_CAPABILITIES = new Set([
    'open_fullscreen', 'open_panel', 'focus_selection', 'edit_text', 'set_style',
    'add_block', 'undo', 'redo', 'preview', 'save', 'animation', 'gif_preview',
]);

const MARKETING_CAPABILITIES = new Set([...BASE_CAPABILITIES, 'replace_image']);
const STRUCTURAL_TAGS = new Set([
    'html', 'head', 'body', 'style', 'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th',
]);
const IMMUTABLE_MAIL_PREFIXES = [
    'rt-mail-signature', 'rt-mail-person-', 'rt-mail-token', 'rt-mail-field',
];

const MOTIONS = new Set([
    '', 'none', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'scale',
    'reveal', 'parallax', 'split-lines',
]);
const EASES = new Set([
    '', 'none', 'power3.out', 'power2.out', 'power4.out', 'power2.inOut',
    'power3.inOut', 'sine.inOut', 'back.out(1.4)',
]);
const STARTS = new Set([
    '', 'top 90%', 'top 85%', 'top 80%', 'top 75%', 'top center', 'center center',
]);
const ENDS = new Set([
    '', 'bottom 10%', 'bottom 20%', 'bottom center', '+=300', '+=500', '+=800',
]);
const SCRUBS = new Set(['', 'false', '0.25', '0.5', '0.8', '1.2', '2']);

const METHOD_BY_COMMAND = Object.freeze({
    open_fullscreen: 'openFullscreen',
    focus_selection: 'focusSelection',
    open_panel: 'openPanel',
    edit_text: 'editText',
    set_style: 'setStyle',
    replace_image: 'replaceImageByFileId',
    add_block: 'addBlock',
    undo: 'undo',
    redo: 'redo',
    preview: 'setPreview',
    restart_gif: 'restartGifPreview',
    set_animation: 'setAnimation',
    save: 'save',
});

const CAPABILITY_BY_COMMAND = Object.freeze({
    open_fullscreen: 'open_fullscreen',
    focus_selection: 'focus_selection',
    open_panel: 'open_panel',
    edit_text: 'edit_text',
    set_style: 'set_style',
    replace_image: 'replace_image',
    add_block: 'add_block',
    undo: 'undo',
    redo: 'redo',
    preview: 'preview',
    restart_gif: 'gif_preview',
    set_animation: 'animation',
    save: 'save',
});

const SELECTION_COMMANDS = new Set([
    'focus_selection', 'edit_text', 'set_style', 'replace_image', 'add_block',
    'set_animation', 'restart_gif',
]);

let activeAdapter = null;
let commandQueue = Promise.resolve();
let installed = false;
let contextPublishTimer = null;
const consumedActionTokens = new Set();
let confirmationDispatcherIssued = false;

function string(value) {
    return typeof value === 'string' ? value.trim() : '';
}

function validNonce(value) {
    return /^[a-zA-Z0-9_-]{22,96}$/.test(string(value));
}

function validHash(value) {
    return /^[a-f0-9]{64}$/.test(string(value).toLowerCase());
}

function validUuid(value) {
    return /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(string(value));
}

function validRevision(value) {
    return Number.isInteger(value) && value >= 0 && value <= 2_147_483_647;
}

function positiveInteger(value) {
    return Number.isInteger(value) && value > 0 ? value : null;
}

function boundedText(value, maxLength) {
    if (typeof value !== 'string') return '';
    return value.replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/gu, '').trim().slice(0, maxLength);
}

function plainText(value) {
    if (typeof value !== 'string') return null;
    if (value.length > 6000) return null;
    let decoded = value;
    const named = Object.freeze({
        amp: '&', lt: '<', gt: '>', quot: '"', apos: "'",
        lbrace: '{', rbrace: '}', lcub: '{', rcub: '}',
    });
    for (let pass = 0; pass < 3; pass += 1) {
        const next = decoded
            .replace(/&#(?:x([0-9a-f]+)|(\d+));?/giu, (_match, hex, decimal) => {
                const codepoint = Number.parseInt(hex || decimal, hex ? 16 : 10);
                try { return Number.isInteger(codepoint) ? String.fromCodePoint(codepoint) : _match; } catch { return _match; }
            })
            .replace(/&(amp|lt|gt|quot|apos|lbrace|rbrace|lcub|rcub);/giu, (_match, name) => named[name.toLowerCase()] ?? _match);
        if (next === decoded) break;
        decoded = next;
    }
    const normalized = decoded.replaceAll('\r\n', '\n').replaceAll('\r', '\n').trim();
    if (!normalized || normalized.length > 1000 || /[<>\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/u.test(normalized)) {
        return null;
    }
    return normalized;
}

function uniqueAllowlisted(values, allowed, limit = 24) {
    if (!Array.isArray(values)) return [];
    return [...new Set(values.map(string).filter((value) => allowed.has(value)))].slice(0, limit);
}

function normalizeStyle(mode, rawProperty, rawValue) {
    const property = string(rawProperty).toLowerCase();
    const value = string(rawValue);
    const allowed = mode === 'marketing' ? MARKETING_STYLES : MAIL_STYLES;

    if (!allowed.has(property) || !value || value.length > 80
        || /[{};]|url\s*\(|var\s*\(|calc\s*\(|!important|expression/i.test(value)) {
        return null;
    }

    let valid;
    switch (property) {
    case 'color':
    case 'background-color':
        valid = /^(?:#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)|transparent)$/i.test(value);
        break;
    case 'font-weight':
        valid = /^(?:normal|bold|[1-9]00)$/i.test(value);
        break;
    case 'text-align':
        valid = ['left', 'center', 'right', 'justify'].includes(value.toLowerCase());
        break;
    case 'vertical-align':
        valid = ['top', 'middle', 'bottom', 'baseline'].includes(value.toLowerCase());
        break;
    case 'object-fit':
        valid = ['cover', 'contain', 'fill', 'none', 'scale-down'].includes(value.toLowerCase());
        break;
    case 'object-position':
        valid = /^(?:left|center|right|top|bottom|\d{1,3}(?:\.\d+)?%)(?:\s+(?:left|center|right|top|bottom|\d{1,3}(?:\.\d+)?%))?$/i.test(value);
        break;
    case 'display':
        valid = ['block', 'inline', 'inline-block', 'flex', 'grid', 'none'].includes(value.toLowerCase());
        break;
    case 'justify-content':
        valid = ['start', 'center', 'end', 'space-between', 'space-around'].includes(value.toLowerCase());
        break;
    case 'align-items':
        valid = ['start', 'center', 'end', 'stretch'].includes(value.toLowerCase());
        break;
    case 'opacity':
        valid = Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= 1;
        break;
    case 'border':
        valid = /^(?:0|none|\d{1,2}(?:\.\d+)?px\s+(?:solid|dashed|dotted)\s+#[0-9a-f]{3,8})$/i.test(value);
        break;
    case 'line-height':
        valid = /^(?:normal|(?:0(?:\.\d+)?|[1-4](?:\.\d+)?)(?:px|em|rem|%)?)$/i.test(value);
        break;
    default:
        valid = /^(?:0|auto|-?\d{1,4}(?:\.\d+)?(?:px|%|em|rem|vw|vh))$/i.test(value);
    }

    return valid ? { property, value } : null;
}

function normalizeAnimation(rawField, rawValue) {
    const field = string(rawField);
    const enumSets = { motion: MOTIONS, ease: EASES, start: STARTS, end: ENDS, scrub: SCRUBS };
    if (enumSets[field]) {
        const value = string(rawValue);
        return enumSets[field].has(value) ? { field, value } : null;
    }

    const ranges = {
        duration: [0.1, 5], delay: [0, 5], distance: [0, 300],
        scale: [0.5, 1.5], stagger: [0, 1],
    };
    if (ranges[field]) {
        const value = Number(rawValue);
        const [min, max] = ranges[field];
        return Number.isFinite(value) && value >= min && value <= max
            ? { field, value: Math.round(value * 1000) / 1000 }
            : null;
    }

    if (field === 'reverse' || field === 'once') {
        if (typeof rawValue === 'boolean') return { field, value: rawValue };
        if (rawValue === 'true' || rawValue === '1' || rawValue === 1) return { field, value: true };
        if (rawValue === 'false' || rawValue === '0' || rawValue === 0) return { field, value: false };
    }

    return null;
}

function normalizeSelection(mode, rawSelection) {
    if (!rawSelection || typeof rawSelection !== 'object' || Array.isArray(rawSelection)) return null;
    if (!validNonce(rawSelection.selection_nonce) || !validHash(rawSelection.fingerprint)) return null;

    const tag = string(rawSelection.tag).toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 20);
    const blockId = string(rawSelection.block_id).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 80);
    const text = boundedText(rawSelection.text, 600);
    let protectedSelection = Boolean(rawSelection.protected);
    if (mode === 'mail') {
        protectedSelection = protectedSelection
            || STRUCTURAL_TAGS.has(tag)
            || text.includes('{{')
            || IMMUTABLE_MAIL_PREFIXES.some((prefix) => blockId.startsWith(prefix));
    }

    const styles = {};
    if (rawSelection.styles && typeof rawSelection.styles === 'object' && !Array.isArray(rawSelection.styles)) {
        Object.entries(rawSelection.styles).slice(0, 48).forEach(([property, value]) => {
            const style = normalizeStyle(mode, property, value);
            if (style && Object.keys(styles).length < 24) styles[style.property] = style.value;
        });
    }

    return {
        selection_nonce: string(rawSelection.selection_nonce),
        fingerprint: string(rawSelection.fingerprint).toLowerCase(),
        tag,
        block_id: blockId,
        text,
        styles,
        image_file_id: mode === 'marketing' ? positiveInteger(rawSelection.image_file_id) : null,
        protected: protectedSelection,
        motion_allowed: !protectedSelection && Boolean(rawSelection.motion_allowed),
        // GIF restart is a canvas-only preview action. It remains safe for a
        // protected carrier such as the TRAIN_SRC signature cell; all
        // persistent text/style/animation mutations stay blocked below.
        gif: Boolean(rawSelection.gif),
    };
}

export function normalizePageBuilderAssistantContext(rawContext) {
    if (!rawContext || typeof rawContext !== 'object' || Array.isArray(rawContext)) return null;
    const routeName = string(rawContext.route_name);
    const mode = string(rawContext.mode);
    const expectedMode = ROUTES[routeName];
    const formatOrKind = string(rawContext.format_or_kind);
    const scopeAllowed = mode === 'marketing'
        ? ['story', 'post', 'web'].includes(formatOrKind)
        : ['template', 'signature'].includes(formatOrKind);

    if (Number(rawContext.version) !== 1 || expectedMode !== mode || !scopeAllowed
        || !validUuid(rawContext.resource_id) || !validNonce(rawContext.workspace_nonce)
        || !validHash(rawContext.persisted_content_hash)
        || !validRevision(rawContext.persisted_version)
        || !validRevision(rawContext.client_revision)) {
        return null;
    }

    const capabilityAllowlist = mode === 'marketing' ? MARKETING_CAPABILITIES : BASE_CAPABILITIES;
    const blockAllowlist = mode === 'marketing' ? MARKETING_BLOCKS : MAIL_BLOCKS;
    const rawValidation = rawContext.validation && typeof rawContext.validation === 'object'
        ? rawContext.validation
        : {};
    const validationState = ['valid', 'warning', 'error', 'unknown'].includes(string(rawValidation.state))
        ? string(rawValidation.state)
        : 'unknown';

    return {
        version: 1,
        route_name: routeName,
        mode,
        resource_id: string(rawContext.resource_id).toLowerCase(),
        format_or_kind: formatOrKind,
        workspace_nonce: string(rawContext.workspace_nonce),
        fullscreen_open: Boolean(rawContext.fullscreen_open),
        editor_ready: Boolean(rawContext.editor_ready),
        read_only: Boolean(rawContext.read_only),
        persisted_content_hash: string(rawContext.persisted_content_hash).toLowerCase(),
        persisted_version: Number(rawContext.persisted_version),
        client_revision: Number(rawContext.client_revision),
        unsaved: Boolean(rawContext.unsaved),
        selection: normalizeSelection(mode, rawContext.selection),
        capabilities: uniqueAllowlisted(rawContext.capabilities, capabilityAllowlist),
        available_block_ids: uniqueAllowlisted(rawContext.available_block_ids, blockAllowlist),
        validation: {
            state: validationState,
            issues: Array.isArray(rawValidation.issues)
                ? rawValidation.issues.map((issue) => boundedText(issue, 160)).filter(Boolean).slice(0, 8)
                : [],
        },
    };
}

function dispatchWindowEvent(name, detail) {
    if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function' || typeof CustomEvent !== 'function') {
        return false;
    }
    window.dispatchEvent(new CustomEvent(name, { detail }));
    return true;
}

async function adapterContext() {
    const adapter = activeAdapter;
    if (!adapter || typeof adapter.getContext !== 'function') return null;
    try {
        const context = normalizePageBuilderAssistantContext(await adapter.getContext());
        return activeAdapter === adapter ? context : null;
    } catch (_) {
        return null;
    }
}

function updateFullscreenClass(context) {
    if (typeof document === 'undefined') return;
    document.documentElement?.classList?.toggle(
        'rt-pagebuilder-fullscreen-open',
        Boolean(context?.fullscreen_open),
    );
}

export async function publishPageBuilderAssistantContext() {
    if (contextPublishTimer !== null) {
        globalThis.clearTimeout?.(contextPublishTimer);
        contextPublishTimer = null;
    }
    const context = await adapterContext();
    updateFullscreenClass(context);
    dispatchWindowEvent(CONTEXT_UPDATED_EVENT, context ?? {});
    return context;
}

export function schedulePageBuilderAssistantContextPublish(delay = 140) {
    if (contextPublishTimer !== null) globalThis.clearTimeout?.(contextPublishTimer);
    contextPublishTimer = globalThis.setTimeout?.(() => {
        contextPublishTimer = null;
        void publishPageBuilderAssistantContext();
    }, Math.max(0, Number(delay) || 0)) ?? null;
}

export function registerPageBuilderAssistantAdapter(adapter) {
    if (!adapter || typeof adapter !== 'object' || typeof adapter.getContext !== 'function') return false;
    activeAdapter = adapter;
    void publishPageBuilderAssistantContext();
    return true;
}

export function unregisterPageBuilderAssistantAdapter(adapter = null) {
    if (!activeAdapter || (adapter && activeAdapter !== adapter)) {
        if (!activeAdapter) consumedActionTokens.clear();
        return false;
    }
    if (contextPublishTimer !== null) {
        globalThis.clearTimeout?.(contextPublishTimer);
        contextPublishTimer = null;
    }
    activeAdapter = null;
    consumedActionTokens.clear();
    updateFullscreenClass(null);
    dispatchWindowEvent(CONTEXT_UPDATED_EVENT, {});
    return true;
}

function sameSnapshot(action, context) {
    return action.route_name === context.route_name
        && action.mode === context.mode
        && action.resource_id === context.resource_id
        && action.format_or_kind === context.format_or_kind
        && action.workspace_nonce === context.workspace_nonce
        && action.persisted_content_hash === context.persisted_content_hash
        && action.persisted_version === context.persisted_version
        && action.client_revision === context.client_revision;
}

function normalizeAction(rawAction) {
    if (!rawAction || typeof rawAction !== 'object' || Array.isArray(rawAction)) return null;
    const actionToken = string(rawAction.action_token);
    const routeName = string(rawAction.route_name);
    const mode = string(rawAction.mode);
    const command = string(rawAction.command);
    const scope = string(rawAction.format_or_kind);
    if (!/^[a-zA-Z0-9]{48}$/.test(actionToken) || ROUTES[routeName] !== mode
        || !METHOD_BY_COMMAND[command] || !validUuid(rawAction.resource_id)
        || !validNonce(rawAction.workspace_nonce) || !validHash(rawAction.persisted_content_hash)
        || !validRevision(rawAction.persisted_version) || !validRevision(rawAction.client_revision)
        || !(mode === 'marketing' ? ['story', 'post', 'web'] : ['template', 'signature']).includes(scope)) {
        return null;
    }

    const normalized = {
        action_token: actionToken,
        route_name: routeName,
        mode,
        command,
        resource_id: string(rawAction.resource_id).toLowerCase(),
        format_or_kind: scope,
        workspace_nonce: string(rawAction.workspace_nonce),
        persisted_content_hash: string(rawAction.persisted_content_hash).toLowerCase(),
        persisted_version: Number(rawAction.persisted_version),
        client_revision: Number(rawAction.client_revision),
    };

    if (SELECTION_COMMANDS.has(command)) {
        if (!validNonce(rawAction.selection_nonce) || !validHash(rawAction.selection_fingerprint)) return null;
        normalized.selection_nonce = string(rawAction.selection_nonce);
        normalized.selection_fingerprint = string(rawAction.selection_fingerprint).toLowerCase();
    }

    if (command === 'open_panel') {
        normalized.panel = string(rawAction.panel);
        if (!PANELS.has(normalized.panel)) return null;
    } else if (command === 'edit_text') {
        normalized.text = plainText(rawAction.text);
        if (normalized.text === null || normalized.text.includes('{{') || normalized.text.includes('}}')) return null;
    } else if (command === 'set_style') {
        const style = normalizeStyle(mode, rawAction.property, rawAction.value);
        if (!style) return null;
        Object.assign(normalized, style);
    } else if (command === 'replace_image') {
        normalized.file_id = positiveInteger(rawAction.file_id);
        if (mode !== 'marketing' || normalized.file_id === null) return null;
    } else if (command === 'add_block') {
        normalized.block_id = string(rawAction.block_id);
        normalized.position = string(rawAction.position);
        const allowed = mode === 'marketing' ? MARKETING_BLOCKS : MAIL_BLOCKS;
        if (!allowed.has(normalized.block_id) || !['before', 'after', 'inside'].includes(normalized.position)) return null;
    } else if (command === 'preview') {
        normalized.state = string(rawAction.state);
        if (!['toggle', 'on', 'off'].includes(normalized.state)) return null;
    } else if (command === 'set_animation') {
        const motion = normalizeAnimation(rawAction.field, rawAction.value);
        if (!motion) return null;
        Object.assign(normalized, motion);
    }

    return normalized;
}

function actionMatchesContext(action, context) {
    if (!context || !sameSnapshot(action, context) || !context.editor_ready || context.read_only) return false;
    if (action.command !== 'open_fullscreen' && !context.fullscreen_open) return false;
    if (!context.capabilities.includes(CAPABILITY_BY_COMMAND[action.command])) return false;

    if (SELECTION_COMMANDS.has(action.command)) {
        const selection = context.selection;
        if (!selection || selection.selection_nonce !== action.selection_nonce
            || selection.fingerprint !== action.selection_fingerprint) return false;
        if (['edit_text', 'set_style', 'replace_image', 'add_block', 'set_animation'].includes(action.command)
            && selection.protected) return false;
        if (action.command === 'replace_image' && selection.tag !== 'img') return false;
        if (action.command === 'set_animation' && !selection.motion_allowed) return false;
        if (action.command === 'restart_gif' && !selection.gif) return false;
    }

    if (action.command === 'add_block' && !context.available_block_ids.includes(action.block_id)) return false;
    return true;
}

function commandArguments(action) {
    switch (action.command) {
    case 'open_panel': return [action.panel];
    case 'edit_text': return [action.text];
    case 'set_style': return [action.property, action.value];
    case 'replace_image': return [action.file_id];
    case 'add_block': return [action.block_id, action.position];
    case 'preview': return [action.state];
    case 'set_animation': return [action.field, action.value];
    default: return [];
    }
}

function normalizedResultStatus(result, command) {
    if (result === true) return 'applied';
    if (result === false || result === null || result === undefined) return 'rejected';
    if (typeof result === 'object' && ['applied', 'rejected', 'stale_context', 'storage_error'].includes(result.status)) {
        return result.status;
    }
    return command === 'save' ? 'storage_error' : 'rejected';
}

async function executeAction(action) {
    const freshContext = await adapterContext();
    if (!actionMatchesContext(action, freshContext)) return 'stale_context';

    const methodName = METHOD_BY_COMMAND[action.command];
    const method = activeAdapter?.[methodName];
    if (typeof method !== 'function') return 'rejected';

    try {
        const result = await method.apply(activeAdapter, commandArguments(action));
        return normalizedResultStatus(result, action.command);
    } catch (error) {
        if (error?.code === 'stale_context') return 'stale_context';
        if (error?.code === 'storage_error' || action.command === 'save') return 'storage_error';
        return 'rejected';
    } finally {
        await publishPageBuilderAssistantContext();
    }
}

function dispatchPageBuilderAssistantAction(rawAction) {
    const action = normalizeAction(rawAction);
    if (!action || consumedActionTokens.has(action.action_token)) return false;
    consumedActionTokens.add(action.action_token);

    commandQueue = commandQueue
        .catch(() => {})
        .then(async () => {
            const status = await executeAction(action);
            dispatchWindowEvent(RESULT_EVENT, {
                action_token: action.action_token,
                status,
            });
        });

    return true;
}

/**
 * Hand the single confirmation channel to the Chatbot module. The PageBuilder
 * bridge deliberately exposes no ambient command event; arbitrary page
 * scripts therefore cannot manufacture confirmed editor effects, and every
 * server-issued action token is consumed only once by the returned closure.
 */
export function acquirePageBuilderAssistantActionDispatcher() {
    if (confirmationDispatcherIssued) return null;
    confirmationDispatcherIssued = true;

    return (rawAction) => dispatchPageBuilderAssistantAction(rawAction);
}

export function ensurePageBuilderAssistantBridge() {
    if (installed || typeof window === 'undefined' || typeof window.addEventListener !== 'function') return installed;
    installed = true;

    window.addEventListener(REGISTER_EVENT, (event) => {
        registerPageBuilderAssistantAdapter(event?.detail?.adapter ?? event?.detail);
    });
    window.addEventListener(UNREGISTER_EVENT, (event) => {
        unregisterPageBuilderAssistantAdapter(event?.detail?.adapter ?? event?.detail ?? null);
    });
    window.addEventListener(CONTEXT_REQUEST_EVENT, () => {
        void publishPageBuilderAssistantContext();
    });
    window.addEventListener(CONTEXT_CHANGED_EVENT, () => {
        // GrapesJS emits several update events for one visible change. Coalesce
        // them so Livewire receives the newest context without one request per
        // keystroke/style sub-event. Opening Assist still requests it at once.
        schedulePageBuilderAssistantContextPublish();
    });
    window.addEventListener('pagehide', () => unregisterPageBuilderAssistantAdapter());
    if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        document.addEventListener('livewire:navigating', () => unregisterPageBuilderAssistantAdapter());
    }

    return true;
}

ensurePageBuilderAssistantBridge();
