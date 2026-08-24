/**
 * Gemeinsamer RailTime-Adapter fuer den unveraenderten LMZ Page Builder 2.4.5.
 *
 * Der Vendor bleibt checksum-stabil. Dieser Adapter besitzt deshalb alle
 * RailTime-spezifischen Capabilities, Medienregeln, Inline-Menues und die
 * korrigierte Spacing-Ebene ausserhalb des skalierten GrapesJS-Frames.
 */

import './lmz-editor-assistant.js';

const SIDES = Object.freeze(['top', 'right', 'bottom', 'left']);
const IMAGE_TOKEN_PATTERN = /\{\{([A-Z][A-Z0-9_]*)\}\}/g;
const CSS_URL_PATTERN = /url\(\s*(?:"([^"]*)"|'([^']*)'|([^)'"\s][^)]*?))\s*\)/gi;
const EDIT_COMMAND = 'rt-lmz-edit-menu';
const INLINE_ACTION_ICON_PATHS = Object.freeze({
    assistant: '<path d="M12 3l1.25 3.75L17 8l-3.75 1.25L12 13l-1.25-3.75L7 8l3.75-1.25L12 3Z"/><path d="M18 14l.75 2.25L21 17l-2.25.75L18 20l-.75-2.25L15 17l2.25-.75L18 14Z"/>',
    content: '<path d="M4 20h4l10.5-10.5a2.83 2.83 0 0 0-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/>',
    traits: '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
    styles: '<path d="M12 3a9 9 0 1 0 0 18h1.5a1.5 1.5 0 0 0 0-3H12a2 2 0 0 1 0-4h2a7 7 0 0 0-2-11Z"/><circle cx="7.5" cy="10" r=".75"/><circle cx="10" cy="6.5" r=".75"/><circle cx="15" cy="7.5" r=".75"/>',
    spacing: '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 2v6m-2-2 2 2 2-2M9 22v-6m-2 2 2-2 2 2M2 9h6M6 7l2 2-2 2m16-2h-6m2-2-2 2 2 2"/>',
    media: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m4 17 4.5-4.5 3 3 2-2L20 19"/>',
    replace: '<path d="M20 7h-5V2M4 17h5v5"/><path d="M18.5 10A7 7 0 0 0 6.2 6.2L4 8m16 8-2.2 2.2A7 7 0 0 1 5.5 14"/>',
    animation: '<path d="m9 7 8 5-8 5V7Z"/><path d="M4 4h16v16H4z"/>',
    'gif-playback': '<path d="M8 6v12M16 6v12"/>',
    'gif-restart': '<path d="M20 7h-5V2"/><path d="M19 12a7 7 0 1 1-2.05-4.95L20 10"/>',
    move: '<path d="M12 2v20M2 12h20M9 5l3-3 3 3M9 19l3 3 3-3M5 9l-3 3 3 3M19 9l3 3-3 3"/>',
    duplicate: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    delete: '<path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14M10 11v6M14 11v6"/>',
});

export const LMZ_EDITOR_EVENTS = Object.freeze({
    requestOpen: 'rt-lmz-editor:request-open',
    requestClose: 'rt-lmz-editor:request-close',
    requestRefresh: 'rt-lmz-editor:request-refresh',
    opened: 'rt-lmz-editor:opened',
    closed: 'rt-lmz-editor:closed',
    ready: 'rt-lmz-editor:ready',
    destroyed: 'rt-lmz-editor:destroyed',
});

export function handleScopedRtePaste({ ev, rte } = {}) {
    if (!ev?.clipboardData || typeof rte?.insertHTML !== 'function') return false;
    ev.preventDefault?.();
    const text = String(ev.clipboardData.getData?.('text/plain') || ev.clipboardData.getData?.('text') || '');
    const safe = text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replace(/(?:\r\n|\r|\n)/g, '<br>');
    rte.insertHTML(safe);
    return true;
}

function pageBuilderShellContext(root) {
    const element = asElement(root);
    const directShell = element?.closest?.('[data-page-builder-shell]') || null;
    const workspace = element?.closest?.('[data-page-builder-workspace]') || null;
    // fullscreen-modal teleportiert den Editor nach <body>. Dadurch ist die
    // urspruengliche Shell kein DOM-Vorfahre mehr, die stabile ID liegt aber
    // weiterhin direkt auf der teleportierten Vollbildwurzel.
    const fullscreen = element?.closest?.('[data-page-builder-fullscreen-root]') || null;
    const shellId = fullscreen?.dataset?.pageBuilderShellId
        || directShell?.querySelector?.('[data-page-builder-fullscreen-root]')?.dataset?.pageBuilderShellId
        || '';
    let teleportOrigin = null;
    for (let current = element; current && !teleportOrigin; current = current.parentElement) {
        teleportOrigin = current._x_teleportBack || null;
    }
    const teleportedShell = teleportOrigin?.closest?.('[data-page-builder-shell]') || null;
    const shellForId = !directShell && !teleportedShell && shellId
        ? [...(element?.ownerDocument?.querySelectorAll?.('[data-page-builder-shell]') || [])].find((candidate) => {
            if (candidate.dataset?.pageBuilderShellId === shellId) return true;
            return [...candidate.querySelectorAll?.('template') || []].some((template) => {
                const teleported = template._x_teleport;
                if (!teleported) return false;
                const roots = [
                    ...(teleported.matches?.('[data-page-builder-fullscreen-root]') ? [teleported] : []),
                    ...(teleported.querySelectorAll?.('[data-page-builder-fullscreen-root]') || []),
                ];
                return roots.some((candidateRoot) => candidateRoot.dataset?.pageBuilderShellId === shellId);
            });
        }) || null
        : null;
    const shell = directShell || teleportedShell || shellForId;
    return { shell, workspace, shellId };
}

export function pageBuilderWorkspaceIsActive(root) {
    const { workspace } = pageBuilderShellContext(root);
    return !workspace || workspace.dataset.pageBuilderEditorActive !== 'false';
}

export function waitForPageBuilderActivation(root, environment = {}) {
    if (pageBuilderWorkspaceIsActive(root)) return Promise.resolve(true);
    const window_ = environment.window || globalThis.window;
    const document_ = environment.document || globalThis.document;
    const { shellId } = pageBuilderShellContext(root);
    return new Promise((resolve, reject) => {
        const cleanup = () => {
            window_?.removeEventListener?.('page-builder-shell:opened', opened);
            document_?.removeEventListener?.('livewire:navigating', cancelled);
        };
        const opened = (event) => {
            if (shellId && event.detail?.id && event.detail.id !== shellId) return;
            cleanup();
            resolve(true);
        };
        const cancelled = () => {
            cleanup();
            reject(new DOMException('Editorstart wurde durch Navigation abgebrochen.', 'AbortError'));
        };
        window_?.addEventListener?.('page-builder-shell:opened', opened);
        document_?.addEventListener?.('livewire:navigating', cancelled, { once: true });
    });
}

export function createPageBuilderLifecycleController({
    root,
    getBuilder = () => null,
    onOpen = null,
    onClose = null,
    onError = null,
    environment = {},
} = {}) {
    const window_ = environment.window || globalThis.window;
    const { shellId } = pageBuilderShellContext(root);
    let savingForClose = false;
    let destroyed = false;
    const matches = (event) => !shellId || !event.detail?.id || event.detail.id === shellId;
    const opened = (event) => {
        if (!destroyed && matches(event)) onOpen?.(event.detail || {});
    };
    const closed = (event) => {
        if (!destroyed && matches(event)) onClose?.(event.detail || {});
    };
    const beforeClose = (event) => {
        if (destroyed || !matches(event)) return;
        const builder = getBuilder?.();
        if (!builder?.hasUnsavedChanges?.()) return;
        event.preventDefault?.();
        if (savingForClose) return;
        savingForClose = true;
        Promise.resolve(builder.save?.('manual'))
            .then((saved) => {
                if (destroyed || !saved) throw new Error('Offene Änderungen konnten vor dem Schließen nicht gespeichert werden.');
                window_?.dispatchEvent?.(new CustomEvent('page-builder-shell:close-approved', { detail: { id: shellId } }));
            })
            .catch((error) => onError?.(error))
            .finally(() => { savingForClose = false; });
    };
    window_?.addEventListener?.('page-builder-shell:opened', opened);
    window_?.addEventListener?.('page-builder-shell:closed', closed);
    window_?.addEventListener?.('page-builder-shell:before-close', beforeClose);
    return {
        destroy() {
            destroyed = true;
            window_?.removeEventListener?.('page-builder-shell:opened', opened);
            window_?.removeEventListener?.('page-builder-shell:closed', closed);
            window_?.removeEventListener?.('page-builder-shell:before-close', beforeClose);
        },
    };
}

/**
 * Participant for RailTime's shared navigation coordinator. It flushes the
 * LMZ draft until stable before links, Livewire history or page unload may
 * replace the editor DOM.
 */
export function createPageBuilderNavigationController({
    getBuilder = () => null,
    onSaving = null,
    onSaved = null,
    onFlushError = null,
} = {}) {
    return {
        hasPendingWork() {
            return Boolean(getBuilder()?.hasUnsavedChanges?.());
        },
        async flush() {
            const builder = getBuilder();
            if (!builder?.hasUnsavedChanges?.()) return true;
            onSaving?.();
            const saved = await builder.save?.('manual');
            if (!saved) throw new Error('Offene Änderungen konnten vor dem Seitenwechsel nicht gespeichert werden.');
            onSaved?.();
            return true;
        },
        onFlushError(error) {
            onFlushError?.(error);
        },
    };
}

export const DEFAULT_LMZ_CAPABILITIES = Object.freeze({
    blocks: true,
    layers: true,
    traits: true,
    styles: true,
    classes: true,
    spacing: true,
    media: true,
    mediaInsert: false,
    imageReplace: false,
    animation: false,
    gifControls: true,
    upload: false,
    externalAssetUrl: false,
    writable: true,
});

/**
 * Fachliche Arbeitsprofile des gemeinsamen LMZ-Editors.
 *
 * Ein Modus beschreibt den Dokumenttyp und ist deshalb kein frei
 * umschaltbarer Ansichtsstatus. So kann ein Maildokument nicht beim Oeffnen
 * oder durch einen UI-Wechsel mit Marketing-CSS serialisiert werden.
 */
export const LMZ_EDITOR_MODES = Object.freeze({
    marketing: Object.freeze({
        id: 'marketing',
        label: 'Marketing',
        description: 'Freie Artboards mit RailTime-Medien und Motion-Werkzeugen.',
        contentModel: 'artboard',
        styleStrategy: 'canvas',
        capabilities: Object.freeze({
            classes: true,
            mediaInsert: true,
            imageReplace: true,
            animation: true,
        }),
    }),
    mail: Object.freeze({
        id: 'mail',
        label: 'Mail',
        description: 'Mailclient-sichere Bausteine, Inline-Stile und freigegebene Medien.',
        contentModel: 'email',
        styleStrategy: 'inline',
        capabilities: Object.freeze({
            classes: false,
            mediaInsert: false,
            imageReplace: 'tokens-only',
            animation: false,
            upload: false,
            externalAssetUrl: false,
        }),
    }),
});

export function resolveLmzEditorMode(mode = 'marketing') {
    const id = typeof mode === 'object' && mode !== null ? mode.id : mode;
    return LMZ_EDITOR_MODES[String(id || '').toLowerCase()] || LMZ_EDITOR_MODES.marketing;
}

function number(value, fallback = 0) {
    const parsed = typeof value === 'number' ? value : Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function clamp(value, minimum = Number.NEGATIVE_INFINITY, maximum = Number.POSITIVE_INFINITY) {
    return Math.min(maximum, Math.max(minimum, value));
}

function rounded(value) {
    return Math.round(number(value) * 100) / 100;
}

function asElement(root) {
    if (typeof root === 'string') return globalThis.document?.querySelector?.(root) || null;
    return root || null;
}

function dispatch(root, name, detail = {}) {
    if (!root?.dispatchEvent || typeof globalThis.CustomEvent !== 'function') return;
    root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
}

export function normalizeLmzCapabilities(mode = 'marketing', capabilities = {}) {
    const profile = resolveLmzEditorMode(mode);
    const normalizedMode = profile.id;
    const merged = {
        ...DEFAULT_LMZ_CAPABILITIES,
        ...profile.capabilities,
        ...capabilities,
    };

    // Upload und freie URLs sind in RailTime nie Builder-Capabilities.
    merged.upload = false;
    merged.externalAssetUrl = false;
    if (!merged.writable) {
        merged.mediaInsert = false;
        merged.imageReplace = false;
        merged.spacing = false;
        merged.animation = false;
    }
    if (normalizedMode === 'mail') {
        merged.classes = false;
        merged.mediaInsert = false;
        merged.animation = false;
        merged.imageReplace = capabilities.imageReplace === 'tokens-only' || capabilities.imageReplace === undefined
            ? 'tokens-only'
            : false;
    }

    return Object.freeze(merged);
}

export function canonicalMediaSource(source, baseUrl = 'http://localhost/', ignoreQuery = true) {
    const value = String(source || '').trim();
    if (!value) return '';
    if (/^(?:data:|cid:|\{\{)/i.test(value)) return value;

    try {
        const url = new URL(value, baseUrl);
        if (ignoreQuery) {
            url.search = '';
            url.hash = '';
        }
        return url.href;
    } catch {
        return value;
    }
}

export function extractCssMediaSources(css = '') {
    const sources = [];
    String(css || '').replace(CSS_URL_PATTERN, (_match, doubleQuoted, singleQuoted, bare) => {
        const source = String(doubleQuoted ?? singleQuoted ?? bare ?? '').trim();
        if (source && !/^none$/i.test(source)) sources.push(source);
        return _match;
    });
    return sources;
}

function replaceFirstCssMediaSource(css = '', source = null) {
    let replaced = false;
    return String(css || '').replace(CSS_URL_PATTERN, (match) => {
        if (replaced) return match;
        replaced = true;
        if (source === null) return 'none';

        return `url("${String(source).replaceAll('"', '\\"')}")`;
    });
}

export function extractHtmlMediaSources(html = '', environment = {}) {
    const source = String(html || '');
    const values = [];
    const DOMParserClass = environment.DOMParser || globalThis.DOMParser;

    if (typeof DOMParserClass === 'function') {
        try {
            const document_ = new DOMParserClass().parseFromString(source, 'text/html');
            document_?.querySelectorAll?.('[src]')?.forEach((element) => {
                const value = element.getAttribute('src');
                if (value) values.push(value);
            });
            document_?.querySelectorAll?.('[style]')?.forEach((element) => {
                values.push(...extractCssMediaSources(element.getAttribute('style') || ''));
            });
            return values;
        } catch {
            // Der Regex-Fallback deckt auch Test-/Legacy-DOMs ohne Parser ab.
        }
    }

    source.replace(/\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/gi, (_match, a, b, c) => {
        const value = a ?? b ?? c;
        if (value) values.push(value);
        return _match;
    });
    source.replace(/\bstyle\s*=\s*(?:"([^"]*)"|'([^']*)')/gi, (_match, a, b) => {
        values.push(...extractCssMediaSources(a ?? b ?? ''));
        return _match;
    });

    return values;
}

function assetSource(asset) {
    if (typeof asset === 'string') return asset;
    if (typeof asset?.getSrc === 'function') return asset.getSrc();
    if (typeof asset?.get === 'function') return asset.get('src');
    return asset?.src || '';
}

function normalizeAsset(asset, baseUrl) {
    const source = assetSource(asset);
    return {
        asset,
        src: source,
        key: canonicalMediaSource(source, baseUrl),
        name: String(asset?.name || asset?.get?.('name') || source.split('/').pop() || 'Bild'),
        type: String(asset?.type || asset?.get?.('type') || 'image'),
        width: number(asset?.width || asset?.get?.('width')) || null,
        height: number(asset?.height || asset?.get?.('height')) || null,
        category: String(asset?.category || asset?.get?.('category') || ''),
        mime: String(asset?.mime || asset?.mime_type || asset?.get?.('mime') || asset?.get?.('mime_type') || ''),
    };
}

function normalizedToken(token) {
    return String(token || '').replace(/^\{\{|\}\}$/g, '').trim().toUpperCase();
}

export function collectUsedMedia({
    html = '',
    css = '',
    mode = 'marketing',
    assets = [],
    trustedSources = [],
    tokenMedia = [],
    baseUrl = 'http://localhost/',
    environment = {},
} = {}) {
    const library = (Array.isArray(assets) ? assets : [])
        .map((asset) => normalizeAsset(asset, baseUrl))
        .filter((asset) => asset.src && asset.type === 'image');
    const libraryBySource = new Map(library.map((asset) => [asset.key, asset]));
    const trusted = new Set((trustedSources || []).map((source) => canonicalMediaSource(source, baseUrl)));
    const used = [];
    const seen = new Set();
    const warnings = [];

    if (mode === 'mail') {
        const source = `${html}\n${css}`;
        const markerTokens = [];
        source.replace(/data-rt-mail-preview-(?:token|train)\s*=\s*["']([A-Z0-9_]+)["']/gi, (_match, token) => {
            markerTokens.push(token);
            return _match;
        });
        source.replace(IMAGE_TOKEN_PATTERN, (_match, token) => {
            markerTokens.push(token);
            return _match;
        });
        const present = new Set(markerTokens.map(normalizedToken));
        (tokenMedia || []).forEach((definition) => {
            const token = normalizedToken(definition.token);
            if (!token || !present.has(token) || seen.has(token)) return;
            seen.add(token);
            used.push({
                id: `token:${token}`,
                token,
                src: definition.src || '',
                name: definition.label || token,
                kind: 'token',
                allowed: true,
                protected: true,
                status: 'protected',
            });
        });

        // Neben den serverkontrollierten Marken-Tokens duerfen normale
        // Inhaltsbilder im Maildokument vorkommen. Sie werden wie im
        // Marketing-Editor nur dann als ersetzbar eingestuft, wenn ihre
        // Quelle aus dem explizit freigegebenen Asset-Bestand kommt oder als
        // eingebettetes Bild bereits Bestandteil des Dokuments ist.
        extractHtmlMediaSources(html, environment).forEach((mediaSource) => {
            if (/^(?:about:blank|\{\{[A-Z0-9_]+}})$/i.test(mediaSource)) return;
            const key = canonicalMediaSource(mediaSource, baseUrl);
            if (!key || seen.has(key)) return;
            seen.add(key);
            const matched = libraryBySource.get(key);
            const embedded = /^data:image\//i.test(mediaSource);
            const isTrusted = trusted.has(key);
            const allowed = Boolean(matched || embedded || isTrusted);
            used.push({
                id: `source:${key}`,
                src: mediaSource,
                name: matched?.name || (embedded ? 'Eingebettetes Bild' : mediaSource.split('/').pop() || mediaSource),
                kind: matched ? 'library' : (embedded ? 'embedded' : (isTrusted ? 'brand' : 'unknown')),
                allowed,
                protected: embedded || isTrusted,
                status: allowed ? (matched ? 'available' : 'protected') : 'out-of-scope',
                asset: matched?.asset || null,
            });
            if (!allowed) warnings.push({
                code: 'media.out-of-scope',
                source: mediaSource,
                message: `Die verwendete Bildquelle ${mediaSource} liegt nicht in der freigegebenen Bibliothek.`,
            });
        });

        return { used, library, warnings };
    }

    const sources = [
        ...extractHtmlMediaSources(html, environment),
        ...extractCssMediaSources(css),
    ];
    sources.forEach((source) => {
        const key = canonicalMediaSource(source, baseUrl);
        if (!key || seen.has(key) || key === 'about:blank') return;
        seen.add(key);
        const matched = libraryBySource.get(key);
        const embedded = /^data:image\//i.test(source);
        const isTrusted = trusted.has(key);
        const allowed = Boolean(matched || embedded || isTrusted);
        const item = {
            id: `source:${key}`,
            src: source,
            name: matched?.name || (embedded ? 'Eingebettetes Bild' : source.split('/').pop() || source),
            kind: matched ? 'library' : (embedded ? 'embedded' : (isTrusted ? 'brand' : 'unknown')),
            allowed,
            protected: isTrusted || embedded,
            status: allowed ? (matched ? 'available' : 'protected') : 'out-of-scope',
            asset: matched?.asset || null,
        };
        used.push(item);
        if (!allowed) warnings.push({
            code: 'media.out-of-scope',
            source,
            message: `Die verwendete Bildquelle ${source} liegt nicht in der freigegebenen Bibliothek.`,
        });
    });

    return { used, library, warnings };
}

function componentAttributes(component) {
    return component?.getAttributes?.() || component?.get?.('attributes') || {};
}

function componentChildren(component) {
    const collection = component?.components?.();
    if (!collection) return [];
    if (Array.isArray(collection)) return collection;
    if (Array.isArray(collection.models)) return collection.models;
    if (typeof collection.forEach === 'function') {
        const children = [];
        collection.forEach((child) => children.push(child));
        return children;
    }
    return [];
}

function isImageComponent(component) {
    const type = String(component?.get?.('type') || '').toLowerCase();
    const tag = String(component?.get?.('tagName') || component?.tagName || '').toLowerCase();
    return type === 'image' || tag === 'img';
}

function isEditableBrandPreviewImage(component) {
    if (!isImageComponent(component)) return false;
    const previewToken = String(componentAttributes(component)['data-rt-mail-preview-token'] || '');
    return ['TRAIN_SRC', 'LOGO_SRC'].includes(previewToken);
}

function hasProtectedEditorMarker(component) {
    const attributes = componentAttributes(component);
    const block = String(attributes['data-rt-block'] || '');

    return Boolean(
        attributes['data-rt-mail-preview-token']
        || attributes['data-rt-mail-preview-train']
        || attributes['data-rt-qr-binding']
        || attributes['data-rt-brand-lockup']
        || ['logo-light', 'logo-dark', 'qr'].includes(block)
    );
}

function isMailTrainCarrier(component) {
    return Boolean(componentAttributes(component)['data-rt-mail-preview-train']);
}

function isMailSystemLayer(component) {
    const attributes = componentAttributes(component);

    return Boolean(
        attributes['data-rt-mail-preview-token']
        || attributes['data-rt-mail-preview-train']
    );
}

const FIXED_MAIL_SIGNATURE_GEOMETRY_CLASSES = Object.freeze(new Set([
    'rt-sign-stage',
    'rt-sign-train-layer',
    'rt-sign-train-frame',
    'rt-sign-train-slot',
    'rt-sign-content-frame',
]));

export function isFixedMailSignatureGeometry(component) {
    const classes = String(componentAttributes(component).class || '').split(/\s+/).filter(Boolean);
    return classes.some((name) => FIXED_MAIL_SIGNATURE_GEOMETRY_CLASSES.has(name));
}

export function isProtectedEditorImage(component) {
    if (!component) return true;

    return isProtectedEditorStructure(component);
}

export function isProtectedEditorStructure(component) {
    if (!component) return false;
    let current = component;
    let isSelectedComponent = true;
    let guard = 0;

    while (current && guard < 30) {
        if (hasProtectedEditorMarker(current)) {
            if (isSelectedComponent && isEditableBrandPreviewImage(current)) {
                current = typeof current.parent === 'function' ? current.parent() : null;
                isSelectedComponent = false;
                guard += 1;
                continue;
            }
            // Der Zug-Carrier schuetzt seine eigene mailkritische Geometrie,
            // ist aber kein Schloss fuer die darin liegenden Kontakt- und
            // Inhaltsbloecke. Diese bleiben im Ebenenpanel einzeln erreichbar.
            if (isSelectedComponent || !isMailTrainCarrier(current)) return true;
        }
        current = typeof current.parent === 'function' ? current.parent() : null;
        isSelectedComponent = false;
        guard += 1;
    }

    return false;
}

/**
 * Structure commands affect the complete selected subtree. A neutral wrapper
 * around an official logo, QR binding or train therefore has to be protected
 * from moving, cloning and deletion without making its own style fields
 * immutable.
 */
export function isProtectedEditorStructureTree(component) {
    if (!component) return false;
    if (isProtectedEditorStructure(component)) return true;
    const pending = [...componentChildren(component)];
    const visited = new Set();

    while (pending.length) {
        const current = pending.shift();
        if (!current || visited.has(current)) continue;
        visited.add(current);
        if (hasProtectedEditorMarker(current)) return true;
        pending.push(...componentChildren(current));
    }

    return false;
}

const IMMUTABLE_COMPONENT_PROPERTIES = Object.freeze({
    removable: false,
    copyable: false,
    draggable: false,
    droppable: false,
    editable: false,
    stylable: false,
    resizable: false,
});

export function enforceProtectedComponentModels(editor, { readOnly = false } = {}) {
    const wrapper = editor?.getWrapper?.() || editor?.getSelected?.() || null;
    if (!wrapper) return 0;
    let locked = 0;
    const visit = (component, protectedAncestor = false) => {
        const editableBrandImage = isEditableBrandPreviewImage(component);
        const directProtectedComponent = hasProtectedEditorMarker(component)
            && !editableBrandImage;
        // Der umgebende Marken-/Carrierblock bleibt strukturell gesperrt. Das
        // eigentliche TRAIN_SRC-/LOGO_SRC-Bild darf jedoch weiterhin direkt
        // ausgewaehlt und ueber den mail-sicheren Bildinspector bearbeitet
        // werden; im Nur-Lesen-Modus gilt die Ausnahme ausdruecklich nicht.
        const protectedComponent = directProtectedComponent
            || (protectedAncestor && !editableBrandImage);
        if (readOnly || protectedComponent) {
            Object.entries(IMMUTABLE_COMPONENT_PROPERTIES).forEach(([property, value]) => {
                component?.set?.(property, value, { silent: true });
            });
            component?.set?.(
                'layerable',
                readOnly
                    ? false
                    : Boolean(directProtectedComponent && !protectedAncestor && isMailSystemLayer(component)),
                { silent: true },
            );
            if (!readOnly && directProtectedComponent && isMailTrainCarrier(component)) {
                component?.set?.('stylable', ['background-position'], { silent: true });
            }
            locked += 1;
        }
        const protectChildren = protectedComponent && !isMailTrainCarrier(component);
        componentChildren(component).forEach((child) => visit(child, protectChildren));
    };
    visit(wrapper);
    return locked;
}

function scopedAssetFacade(asset) {
    if (typeof asset?.getSrc === 'function' && typeof asset?.get === 'function') return asset;
    const source = assetSource(asset);

    return {
        ...(asset && typeof asset === 'object' ? asset : {}),
        getSrc: () => source,
        get: (property) => (property === 'src' ? source : asset?.[property]),
    };
}

export function createScopedAssetCallbackSelection({
    assets = [],
    select,
    baseUrl = globalThis.location?.origin || 'http://localhost/',
    onSelected = null,
} = {}) {
    if (typeof select !== 'function') throw new TypeError('Der Medien-Callback fehlt.');
    const allowed = new Map((assets || []).map((asset) => [
        canonicalMediaSource(assetSource(asset), baseUrl),
        asset,
    ]));

    return {
        select(asset, complete = true) {
            const source = assetSource(asset);
            const selectedAsset = allowed.get(canonicalMediaSource(source, baseUrl));
            if (!source || !selectedAsset) {
                throw new Error('Die gewählte Bildquelle liegt nicht in der freigegebenen Dateibibliothek.');
            }
            select(scopedAssetFacade(selectedAsset), complete);
            onSelected?.({ asset: selectedAsset, source });
            return source;
        },
        cancel() {},
    };
}

export function resolveEditableImageComponent(editor, selected = null, { mode = 'marketing' } = {}) {
    const image = resolveInspectableImageComponent(editor, selected);
    if (!image || isProtectedEditorImage(image, mode)) return null;

    return image;
}

/**
 * Liefert genau ein Bild aus der aktuellen Auswahl, ohne dabei bereits eine
 * Aussage ueber dessen strukturellen Schutz zu treffen. Der Inspector darf
 * dadurch auch den Zustand eines Markenbildes erklaeren, waehrend die
 * Schreibrechte weiterhin separat und fail-closed entschieden werden.
 */
function resolveInspectableImageComponent(editor, selected = null) {
    const candidate = selected || editor?.getSelected?.();
    if (!candidate) return null;
    const found = [];
    const visit = (component) => {
        if (isImageComponent(component)) found.push(component);
        componentChildren(component).forEach(visit);
    };
    visit(candidate);
    if (found.length !== 1) return null;

    return found[0];
}

export function createImageAssetSelection({
    editor,
    target = null,
    assets = [],
    mode = 'marketing',
    baseUrl = globalThis.location?.origin || 'http://localhost/',
    onSelected = null,
} = {}) {
    const image = resolveEditableImageComponent(editor, target, { mode });
    if (!image) {
        throw new Error(mode === 'mail'
            ? 'Markenmedien und Mail-Tokens sind geschützt und können hier nicht ersetzt werden.'
            : 'Bitte genau ein bearbeitbares Bild auswählen.');
    }
    const allowed = new Map((assets || [])
        .map((asset) => ({ asset, normalized: normalizeAsset(asset, baseUrl) }))
        .filter(({ normalized }) => normalized.src && normalized.type === 'image')
        .map(({ asset, normalized }) => [normalized.key, asset]));
    editor?.AssetManager?.setTarget?.(image);

    return {
        target: image,
        select(asset, complete = true) {
            const source = assetSource(asset);
            const key = canonicalMediaSource(source, baseUrl);
            if (!source || !allowed.has(key)) {
                throw new Error('Die gewählte Bildquelle liegt nicht in der freigegebenen Dateibibliothek.');
            }
            const selectedAsset = allowed.get(key);
            const normalized = normalizeAsset(selectedAsset, baseUrl);
            const attributes = { src: source };
            if (normalized.mime) attributes['data-mime-type'] = normalized.mime.toLowerCase();
            if (isAnimatedImageSource(source, normalized.mime)) attributes['data-rt-animated-media'] = 'gif';
            image.set?.('src', source);
            image.addAttributes?.(attributes);
            if (mode === 'mail') {
                const currentAttributes = image.getAttributes?.() || image.get?.('attributes') || {};
                const currentStyle = image.getStyle?.() || {};
                const requestedWidth = Number(normalized.width || currentAttributes.width || 600);
                const width = Math.min(1200, Math.max(40, Number.isFinite(requestedWidth) ? Math.round(requestedWidth) : 600));
                image.addAttributes?.({ width: String(width) }, { silent: true });
                image.removeAttributes?.('height', { silent: true });
                image.setStyle?.({
                    ...currentStyle,
                    display: 'block',
                    width: '100%',
                    'max-width': `${width}px`,
                    height: 'auto',
                }, { silent: true });
            }
            if (!isAnimatedImageSource(source, normalized.mime)) {
                image.removeAttributes?.('data-rt-animated-media');
            }
            if (!normalized.mime) image.removeAttributes?.('data-mime-type');
            onSelected?.({ target: image, asset: selectedAsset, source });
            if (complete) {
                editor?.AssetManager?.close?.();
                editor?.AssetManager?.setTarget?.(null);
            }
            return source;
        },
        cancel() {
            editor?.AssetManager?.setTarget?.(null);
        },
    };
}

/**
 * GrapesJS exposes image replacement through several native entry points
 * (double click, image traits and style-manager backgrounds). Route all of
 * them through the scoped RailTime drawer so protected logos/QR codes and
 * out-of-pool URLs can never reach the native AssetManager dialog.
 */
export function installScopedAssetAccess({ editor, mediaDrawer, mode = 'marketing' } = {}) {
    const commands = editor?.Commands;
    const assetManager = editor?.AssetManager;
    if (!commands?.add || !mediaDrawer?.open) return () => {};

    const originalCommand = commands.get?.('open-assets') || null;
    const originalAssetOpen = typeof assetManager?.open === 'function'
        ? assetManager.open
        : null;
    const optionsFromArguments = (...values) => values.find((value) => (
        value
        && typeof value === 'object'
        && (value.target || typeof value.select === 'function')
    )) || {};
    const routeToScopedDrawer = (requestedOptions = {}) => {
        const requestedTarget = requestedOptions?.target || null;
        const candidate = requestedTarget
            || assetManager?.getTarget?.()
            || editor?.getSelected?.()
            || null;
        if (candidate && isProtectedEditorImage(candidate, mode)) {
            assetManager?.setTarget?.(null);
            return false;
        }

        // Der GrapesJS-Style-Manager liefert fuer background-image keinen
        // Komponenten-Target, sondern einen vertrauenswuerdigen select-
        // Callback. Er bleibt im RailTime-Drawer, bekommt aber ausschliesslich
        // ein bereits serverseitig freigegebenes FilePool-Asset zurueck.
        if (!requestedTarget && typeof requestedOptions?.select === 'function') {
            if (mode !== 'marketing') return false;
            mediaDrawer.open({
                selectAsset: requestedOptions.select,
                initialTab: 'library',
            });
            return true;
        }

        const image = resolveEditableImageComponent(editor, candidate, { mode });
        if (image && mediaDrawer.canReplace?.(image) === false) return false;
        const opened = mediaDrawer.open(image
            ? { replaceTarget: image, initialTab: 'library' }
            : { initialTab: 'used' });

        return opened !== false;
    };
    const scopedCommand = {
        run(_editor, sender = null, options = {}) {
            return routeToScopedDrawer(optionsFromArguments(options, sender));
        },
        stop() {
            mediaDrawer.close?.();
            return true;
        },
    };

    commands.add('open-assets', scopedCommand);
    if (assetManager && originalAssetOpen) {
        assetManager.open = (options = {}) => routeToScopedDrawer(optionsFromArguments(options));
    }

    return () => {
        if (assetManager && originalAssetOpen) assetManager.open = originalAssetOpen;
        if (originalCommand) {
            commands.add('open-assets', originalCommand);
        } else {
            commands.remove?.('open-assets');
        }
    };
}

export function isAnimatedImageSource(source = '', mime = '') {
    return /(?:^image\/gif$|\.gif(?:$|[?#]))/i.test(String(mime || '').trim())
        || /\.gif(?:$|[?#])/i.test(String(source || '').trim());
}

export function componentAnimationContext(component) {
    const attributes = componentAttributes(component);
    const style = component?.getStyle?.() || component?.get?.('style') || {};
    const backgroundSource = extractCssMediaSources(String(style['background-image'] || style.background || ''))[0] || '';
    const source = String(component?.get?.('src') || attributes.src || backgroundSource || '');
    const token = normalizedToken(attributes['data-rt-mail-preview-token'] || attributes['data-rt-mail-preview-train']);
    return {
        source,
        token,
        animated: ['LOGO_SRC', 'ICON_RT_SRC', 'TRAIN_SRC'].includes(token)
            || attributes['data-rt-animated-media'] === 'gif'
            || isAnimatedImageSource(source, attributes.type || attributes['data-mime-type'] || ''),
    };
}

export function resolveAnimatedComponent(component) {
    const matches = [];
    const visit = (candidate) => {
        if (componentAnimationContext(candidate).animated) matches.push(candidate);
        componentChildren(candidate).forEach(visit);
    };
    if (component) visit(component);
    return matches.length === 1 ? matches[0] : (componentAnimationContext(component).animated ? component : null);
}

const animatedPreviewState = new WeakMap();

async function captureAnimatedFrame(element, source, isImage) {
    if (typeof globalThis.__rtLmzCaptureAnimatedFrame === 'function') {
        return globalThis.__rtLmzCaptureAnimatedFrame({ element, source, isImage });
    }
    const document_ = element?.ownerDocument;
    const canvas = document_?.createElement?.('canvas');
    const context = canvas?.getContext?.('2d');
    if (!canvas || !context) return null;
    let image = isImage ? element : null;
    if (!image) {
        const ImageClass = document_?.defaultView?.Image || globalThis.Image;
        if (typeof ImageClass !== 'function') return null;
        image = new ImageClass();
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('Der aktuelle GIF-Frame konnte nicht gelesen werden.'));
            image.src = source;
        });
    }
    const width = Number(image.naturalWidth || image.width || element.clientWidth || 0);
    const height = Number(image.naturalHeight || image.height || element.clientHeight || 0);
    if (!width || !height || width * height > 40_000_000) return null;
    canvas.width = width;
    canvas.height = height;
    context.drawImage(image, 0, 0, width, height);
    return canvas.toDataURL('image/png');
}

export function animatedPreviewIsPlaying(component) {
    const element = component?.getEl?.();
    return element ? animatedPreviewState.get(element)?.playing !== false : false;
}

export function setAnimatedPreviewPlayback(component, playing = true) {
    const element = component?.getEl?.();
    if (!element || !componentAnimationContext(component).animated) return false;
    const isImage = String(element.tagName || '').toLowerCase() === 'img';
    const current = animatedPreviewState.get(element) || {};
    const renderedBackground = String(
        element.style?.backgroundImage
        || element.ownerDocument?.defaultView?.getComputedStyle?.(element)?.backgroundImage
        || '',
    );
    const currentBackgroundWasRehydrated = !isImage
        && current.frozenBackgroundImage
        && renderedBackground
        && renderedBackground !== 'none'
        && renderedBackground !== current.frozenBackgroundImage;
    const backgroundImage = String(
        (currentBackgroundWasRehydrated ? renderedBackground : current.backgroundImage)
        || ((!renderedBackground || renderedBackground === 'none') ? '' : renderedBackground),
    );
    const backgroundPriority = String(
        element.style?.getPropertyPriority?.('background-image')
        || current.backgroundPriority
        || '',
    );
    const imageWasRehydrated = isImage
        && current.frozenSource
        && String(element.getAttribute?.('src') || element.src || '') !== current.frozenSource;
    const renderedSource = isImage
        ? String(element.getAttribute?.('src') || element.src || '')
        : (extractCssMediaSources(backgroundImage)[0] || '');
    const source = (currentBackgroundWasRehydrated || imageWasRehydrated ? renderedSource : '')
        || current.source
        || renderedSource
        || componentAnimationContext(component).source;
    if (!source) return false;

    if (!playing) {
        const pauseState = {
            playing: false,
            source,
            isImage,
            backgroundImage,
            backgroundPriority,
            frozenSource: '',
            frozenBackgroundImage: '',
        };
        animatedPreviewState.set(element, pauseState);
        element.dataset.rtLmzAnimationPaused = 'true';
        Promise.resolve(captureAnimatedFrame(element, source, isImage)).then((frozenSource) => {
            if (!frozenSource || animatedPreviewState.get(element) !== pauseState || pauseState.playing !== false) return;
            pauseState.frozenSource = frozenSource;
            if (isImage) {
                element.setAttribute?.('src', frozenSource);
                return;
            }
            pauseState.frozenBackgroundImage = replaceFirstCssMediaSource(backgroundImage, frozenSource);
            element.style?.setProperty?.('background-image', pauseState.frozenBackgroundImage, backgroundPriority);
        }).catch(() => {
            // Das GIF bleibt sichtbar, falls der Browser das Canvas ausnahmsweise
            // nicht lesen darf; das persistierte Modell wird niemals beruehrt.
        });
        return true;
    }

    const nextFrame = globalThis.requestAnimationFrame || ((callback) => globalThis.queueMicrotask?.(callback));
    animatedPreviewState.set(element, {
        playing: true,
        source,
        isImage,
        backgroundImage,
        backgroundPriority,
        frozenSource: '',
        frozenBackgroundImage: '',
    });
    delete element.dataset.rtLmzAnimationPaused;
    if (isImage) {
        element.removeAttribute?.('src');
        nextFrame(() => element.setAttribute?.('src', source));
    } else {
        element.style?.setProperty?.(
            'background-image',
            replaceFirstCssMediaSource(backgroundImage, null) || 'none',
            backgroundPriority,
        );
        nextFrame(() => element.style?.setProperty?.(
            'background-image',
            replaceFirstCssMediaSource(backgroundImage, source),
            backgroundPriority,
        ));
    }
    return true;
}

export function refreshPausedAnimatedPreviewElement(element) {
    const current = element ? animatedPreviewState.get(element) : null;
    if (!element || current?.playing !== false) return false;

    const isImage = String(element.tagName || '').toLowerCase() === 'img';
    const backgroundImage = isImage ? '' : String(
        element.style?.backgroundImage
        || element.ownerDocument?.defaultView?.getComputedStyle?.(element)?.backgroundImage
        || '',
    );
    const source = isImage
        ? String(element.getAttribute?.('src') || element.src || '')
        : (extractCssMediaSources(backgroundImage)[0] || '');
    const stillShowsFrozenFrame = isImage
        ? source === current.frozenSource
        : backgroundImage === current.frozenBackgroundImage;
    if (!source || stillShowsFrozenFrame) return false;

    const backgroundPriority = String(
        element.style?.getPropertyPriority?.('background-image')
        || current.backgroundPriority
        || '',
    );
    const pauseState = {
        playing: false,
        source,
        isImage,
        backgroundImage,
        backgroundPriority,
        frozenSource: '',
        frozenBackgroundImage: '',
    };
    animatedPreviewState.set(element, pauseState);
    element.dataset.rtLmzAnimationPaused = 'true';
    Promise.resolve(captureAnimatedFrame(element, source, isImage)).then((frozenSource) => {
        if (!frozenSource || animatedPreviewState.get(element) !== pauseState || pauseState.playing !== false) return;
        pauseState.frozenSource = frozenSource;
        if (isImage) {
            element.setAttribute?.('src', frozenSource);
            return;
        }
        pauseState.frozenBackgroundImage = replaceFirstCssMediaSource(backgroundImage, frozenSource);
        element.style?.setProperty?.('background-image', pauseState.frozenBackgroundImage, backgroundPriority);
    }).catch(() => {
        // Wenn der aktuelle Frame nicht lesbar ist, bleibt das neue Theme-GIF
        // sichtbar. Der persistierte Builderstand wird nie veraendert.
    });

    return true;
}

export function restartAnimatedPreview(component, { nonce = Date.now() } = {}) {
    const element = component?.getEl?.();
    if (!element) return false;
    const attributes = componentAttributes(component);
    const style = component?.getStyle?.() || component?.get?.('style') || {};
    const modelSource = String(component?.get?.('src') || attributes.src || '');
    const renderedImageSource = String(element.getAttribute?.('src') || element.src || '');
    const renderedBackground = String(element.style?.backgroundImage || element.ownerDocument?.defaultView?.getComputedStyle?.(element)?.backgroundImage || '');
    const previewState = animatedPreviewState.get(element);
    const renderedBackgroundIsNew = Boolean(
        renderedBackground
        && renderedBackground !== 'none'
        && renderedBackground !== previewState?.backgroundImage
        && renderedBackground !== previewState?.frozenBackgroundImage,
    );
    const backgroundImage = String(
        (renderedBackgroundIsNew ? renderedBackground : previewState?.backgroundImage)
        || (renderedBackground !== 'none' ? renderedBackground : ''),
    );
    const backgroundPriority = String(
        element.style?.getPropertyPriority?.('background-image')
        || previewState?.backgroundPriority
        || '',
    );
    const modelBackgroundSource = extractCssMediaSources(String(style['background-image'] || style.background || ''))[0] || '';
    const renderedBackgroundSource = extractCssMediaSources(backgroundImage)[0] || '';
    const token = normalizedToken(attributes['data-rt-mail-preview-token'] || attributes['data-rt-mail-preview-train']);
    const backgroundSource = token === 'TRAIN_SRC'
        ? (renderedBackgroundIsNew ? renderedBackgroundSource : (previewState?.source || renderedBackgroundSource || ''))
        : (modelBackgroundSource || renderedBackgroundSource || previewState?.source || '');
    // Mail-Tokens tragen im Grapes-Modell absichtlich nur einen neutralen
    // Platzhalter. Für die Vorschau-Steuerung ist deshalb die ausschließlich
    // im Canvas hydrierte GIF-Quelle maßgeblich; das Modell bleibt unangetastet.
    const imageSource = token && String(element.tagName || '').toLowerCase() === 'img'
        ? (renderedImageSource || previewState?.source || modelSource)
        : modelSource;
    const persistentSource = imageSource || backgroundSource;
    if (!persistentSource) return false;
    const restartMarker = '#_rt_preview_restart=';
    const canonicalSource = persistentSource.replace(/#_rt_preview_restart=.*$/i, '');
    const dataSource = /^data:image\//i.test(canonicalSource);
    const nextFrame = globalThis.requestAnimationFrame || ((callback) => globalThis.queueMicrotask?.(callback));
    let previewSource = `${canonicalSource}${restartMarker}${encodeURIComponent(String(nonce))}`;
    if (!dataSource) {
        try {
            const url = new URL(canonicalSource, element.ownerDocument?.baseURI || globalThis.location?.href || 'http://localhost/');
            url.searchParams.set('_rt_preview_restart', String(nonce));
            previewSource = url.href;
        } catch {
            const separator = canonicalSource.includes('?') ? '&' : '?';
            previewSource = `${canonicalSource}${separator}_rt_preview_restart=${encodeURIComponent(String(nonce))}`;
        }
    }
    // Ausschliesslich das Canvas-DOM aendern. Das Grapes-Modell bleibt exakt.
    // Alle Mail-GIFs einschliesslich TRAIN_SRC sitzen in der Vorschau als IMG.
    // Der Background-Zweig bleibt nur fuer andere Builder-Medien kompatibel;
    // beide Varianten werden ohne Modellmutation neu gestartet.
    animatedPreviewState.set(element, {
        playing: true,
        source: canonicalSource,
        isImage: imageSource && String(element.tagName || '').toLowerCase() === 'img',
        backgroundImage,
        backgroundPriority,
    });
    delete element.dataset.rtLmzAnimationPaused;
    if (imageSource && String(element.tagName || '').toLowerCase() === 'img') {
        if (dataSource) {
            element.removeAttribute?.('src');
            nextFrame(() => element.setAttribute?.('src', previewSource));
        } else {
            element.setAttribute?.('src', previewSource);
        }
        return String(component?.get?.('src') || attributes.src || '') === modelSource;
    }
    if (dataSource) {
        element.style?.setProperty?.(
            'background-image',
            replaceFirstCssMediaSource(backgroundImage, null) || 'none',
            backgroundPriority,
        );
        nextFrame(() => element.style?.setProperty?.(
            'background-image',
            replaceFirstCssMediaSource(backgroundImage, previewSource),
            backgroundPriority,
        ));
    } else {
        element.style?.setProperty?.(
            'background-image',
            replaceFirstCssMediaSource(backgroundImage, previewSource),
            backgroundPriority,
        );
    }
    return JSON.stringify(component?.getStyle?.() || component?.get?.('style') || {}) === JSON.stringify(style);
}

const ALLOWED_ANIMATION_STYLES = Object.freeze({
    'animation-duration': /^(?:0|(?:\d+(?:\.\d+)?)(?:ms|s))$/i,
    'animation-delay': /^(?:-?\d+(?:\.\d+)?)(?:ms|s)$/i,
    'animation-iteration-count': /^(?:infinite|\d+(?:\.\d+)?)$/i,
    'animation-direction': /^(?:normal|reverse|alternate|alternate-reverse)$/i,
    'animation-timing-function': /^(?:linear|ease|ease-in|ease-out|ease-in-out|steps\(\s*\d+\s*(?:,\s*(?:start|end))?\s*\)|cubic-bezier\(\s*-?\d+(?:\.\d+)?(?:\s*,\s*-?\d+(?:\.\d+)?){3}\s*\))$/i,
    'animation-play-state': /^(?:running|paused)$/i,
});

export function sanitizeAnimationStyles(styles = {}) {
    const sanitized = {};
    Object.entries(styles || {}).forEach(([property, value]) => {
        const name = String(property || '').trim().toLowerCase();
        const normalized = String(value || '').trim();
        if (ALLOWED_ANIMATION_STYLES[name]?.test(normalized)) sanitized[name] = normalized;
    });
    return sanitized;
}

export function applyAnimationStyles(component, styles = {}) {
    const sanitized = sanitizeAnimationStyles(styles);
    if (Object.keys(sanitized).length) component?.addStyle?.(sanitized);
    return sanitized;
}

const ALLOWED_MOTION_VALUES = Object.freeze({
    motion: new Set(['', 'none', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'scale', 'reveal', 'parallax', 'split-lines']),
    ease: new Set(['', 'none', 'power3.out', 'power2.out', 'power4.out', 'power2.inOut', 'power3.inOut', 'sine.inOut', 'back.out(1.4)']),
    start: new Set(['', 'top 90%', 'top 85%', 'top 80%', 'top 75%', 'top center', 'center center']),
    end: new Set(['', 'bottom 10%', 'bottom 20%', 'bottom center', '+=300', '+=500', '+=800']),
    scrub: new Set(['', 'false', '0.25', '0.5', '0.8', '1.2', '2']),
});

export function sanitizeMotionSettings(settings = {}) {
    const bounded = (value, minimum, maximum) => {
        const parsed = number(value, Number.NaN);
        return Number.isFinite(parsed) && parsed >= minimum && parsed <= maximum ? rounded(parsed) : null;
    };
    const output = {};
    Object.entries(ALLOWED_MOTION_VALUES).forEach(([field, allowed]) => {
        if (!Object.prototype.hasOwnProperty.call(settings, field)) return;
        const value = String(settings[field] ?? '').trim();
        if (allowed.has(value)) output[field] = value;
    });
    [['duration', 0.1, 5], ['delay', 0, 5], ['distance', 0, 300], ['scale', 0.5, 1.5], ['stagger', 0, 1]].forEach(([field, minimum, maximum]) => {
        const value = bounded(settings[field], minimum, maximum);
        if (value !== null) output[field] = value;
    });
    if (typeof settings.once === 'boolean') output.once = settings.once;
    if (typeof settings.reverse === 'boolean') output.reverse = settings.reverse;
    return output;
}

export function applyMotionSettings(component, settings = {}) {
    const sanitized = sanitizeMotionSettings(settings);
    const attributes = {};
    ['motion', 'ease', 'start', 'end', 'scrub'].forEach((field) => {
        if (sanitized[field]) attributes[`data-lmz-${field}`] = sanitized[field];
        else if (Object.prototype.hasOwnProperty.call(sanitized, field)) component?.removeAttributes?.(`data-lmz-${field}`);
    });
    ['duration', 'delay', 'distance', 'scale', 'stagger'].forEach((field) => {
        if (Object.prototype.hasOwnProperty.call(sanitized, field)) attributes[`data-lmz-${field}`] = String(sanitized[field]);
    });
    if (Object.prototype.hasOwnProperty.call(sanitized, 'once')) attributes['data-lmz-once'] = sanitized.once ? 'true' : 'false';
    if (Object.prototype.hasOwnProperty.call(sanitized, 'reverse')) attributes['data-lmz-reverse'] = sanitized.reverse ? 'true' : 'false';
    component?.addAttributes?.(attributes);
    return sanitized;
}

export function spacingCssSnapshot(offsets = {}, zoom = 1) {
    const factor = Math.max(number(zoom, 1), 0.01);
    const read = (name, minimum = Number.NEGATIVE_INFINITY) => clamp(number(offsets[name]) / factor, minimum);
    return {
        margin: {
            top: read('marginTop'), right: read('marginRight'),
            bottom: read('marginBottom'), left: read('marginLeft'),
        },
        padding: {
            top: read('paddingTop', 0), right: read('paddingRight', 0),
            bottom: read('paddingBottom', 0), left: read('paddingLeft', 0),
        },
        border: {
            top: read('borderTopWidth', 0), right: read('borderRightWidth', 0),
            bottom: read('borderBottomWidth', 0), left: read('borderLeftWidth', 0),
        },
    };
}

function visualSpacing(offsets = {}, zoom = 1) {
    // GrapesJS Canvas.getElementOffsets() already reports the values in the
    // zoomed canvas coordinate system. Multiplying a second time shifts the
    // overlay and would persist half-sized spacing at 50% zoom.
    const read = (name, minimum = Number.NEGATIVE_INFINITY) => clamp(number(offsets[name]), minimum);
    return {
        margin: {
            top: read('marginTop'), right: read('marginRight'),
            bottom: read('marginBottom'), left: read('marginLeft'),
        },
        padding: {
            top: read('paddingTop', 0), right: read('paddingRight', 0),
            bottom: read('paddingBottom', 0), left: read('paddingLeft', 0),
        },
        border: {
            top: read('borderTopWidth', 0), right: read('borderRightWidth', 0),
            bottom: read('borderBottomWidth', 0), left: read('borderLeftWidth', 0),
        },
    };
}

function rect(left, top, width, height) {
    return { left, top, width: Math.max(0, width), height: Math.max(0, height) };
}

export function calculateSpacingOverlayGeometry({ position, offsets = {}, minimumBand = 8 } = {}) {
    if (!position || !Number.isFinite(position.width) || !Number.isFinite(position.height)) return null;
    const spacing = visualSpacing(offsets);
    const outer = rect(position.left, position.top, position.width, position.height);
    const band = (value) => Math.max(Math.abs(value), minimumBand);
    const margin = Object.fromEntries(SIDES.map((side) => [side, band(spacing.margin[side])]));
    const paddingBox = rect(
        outer.left + spacing.border.left,
        outer.top + spacing.border.top,
        outer.width - spacing.border.left - spacing.border.right,
        outer.height - spacing.border.top - spacing.border.bottom,
    );
    const padding = {
        top: Math.min(band(spacing.padding.top), paddingBox.height),
        right: Math.min(band(spacing.padding.right), paddingBox.width),
        bottom: Math.min(band(spacing.padding.bottom), paddingBox.height),
        left: Math.min(band(spacing.padding.left), paddingBox.width),
    };
    const marginRect = {
        top: rect(outer.left - margin.left, outer.top - margin.top, outer.width + margin.left + margin.right, margin.top),
        right: rect(outer.left + outer.width, outer.top, margin.right, outer.height),
        bottom: rect(outer.left - margin.left, outer.top + outer.height, outer.width + margin.left + margin.right, margin.bottom),
        left: rect(outer.left - margin.left, outer.top, margin.left, outer.height),
    };
    const paddingRect = {
        top: rect(paddingBox.left, paddingBox.top, paddingBox.width, padding.top),
        right: rect(
            paddingBox.left + paddingBox.width - padding.right,
            paddingBox.top + padding.top,
            padding.right,
            Math.max(0, paddingBox.height - padding.top - padding.bottom),
        ),
        bottom: rect(paddingBox.left, paddingBox.top + paddingBox.height - padding.bottom, paddingBox.width, padding.bottom),
        left: rect(
            paddingBox.left,
            paddingBox.top + padding.top,
            padding.left,
            Math.max(0, paddingBox.height - padding.top - padding.bottom),
        ),
    };

    return { outer, spacing, margin: marginRect, padding: paddingRect };
}

export function calculateSpacingDragValue({ startValue = 0, deltaX = 0, deltaY = 0, zoom = 1, side, type } = {}) {
    const factor = Math.max(number(zoom, 1), 0.01);
    const screenDelta = side === 'top'
        ? -deltaY
        : side === 'right'
            ? deltaX
            : side === 'bottom'
                ? deltaY
                : -deltaX;
    return rounded(clamp(number(startValue) + (screenDelta / factor), type === 'padding' ? 0 : Number.NEGATIVE_INFINITY));
}

function spacingStyle(type, values, side, value) {
    return Object.fromEntries(SIDES.map((name) => [
        `${type}-${name}`,
        `${rounded(name === side ? value : values[name])}px`,
    ]));
}

function eventPoint(editor, event, zoom) {
    const frameDocument = editor?.Canvas?.getDocument?.();
    const frameElement = editor?.Canvas?.getFrameEl?.();
    const sourceDocument = event?.target?.ownerDocument;
    if (frameDocument && frameElement && sourceDocument === frameDocument) {
        const frameRect = frameElement.getBoundingClientRect();
        return {
            x: frameRect.left + number(event.clientX) * zoom,
            y: frameRect.top + number(event.clientY) * zoom,
        };
    }
    return { x: number(event?.clientX), y: number(event?.clientY) };
}

function applyHandleRect(handle, visualRect, side, hitSize) {
    const horizontal = side === 'top' || side === 'bottom';
    const width = Math.max(visualRect.width, horizontal ? hitSize : visualRect.width);
    const height = Math.max(visualRect.height, horizontal ? visualRect.height : hitSize);
    const hitWidth = horizontal ? width : Math.max(width, hitSize);
    const hitHeight = horizontal ? Math.max(height, hitSize) : height;
    const left = visualRect.left - ((hitWidth - visualRect.width) / 2);
    const top = visualRect.top - ((hitHeight - visualRect.height) / 2);
    Object.assign(handle.style, {
        display: visualRect.width > 0 && visualRect.height > 0 ? 'block' : 'none',
        left: `${left}px`, top: `${top}px`, width: `${hitWidth}px`, height: `${hitHeight}px`,
    });
    const surface = handle.querySelector?.('.rt-lmz-spacing-overlay__surface');
    if (surface) Object.assign(surface.style, {
        left: `${visualRect.left - left}px`, top: `${visualRect.top - top}px`,
        width: `${visualRect.width}px`, height: `${visualRect.height}px`,
    });
}

export function createSpacingOverlayController({
    editor,
    root,
    enabled = true,
    environment = {},
} = {}) {
    if (!editor?.Canvas) return { setEnabled() {}, refresh() {}, destroy() {} };
    const document_ = environment.document || globalThis.document;
    const window_ = environment.window || globalThis.window;
    const requestFrame = environment.requestAnimationFrame || window_?.requestAnimationFrame?.bind(window_) || ((callback) => callback());
    const cancelFrame = environment.cancelAnimationFrame || window_?.cancelAnimationFrame?.bind(window_) || (() => {});
    const ResizeObserverClass = Object.prototype.hasOwnProperty.call(environment, 'ResizeObserver')
        ? environment.ResizeObserver
        : (window_?.ResizeObserver || globalThis.ResizeObserver);
    const coarse = environment.coarsePointer ?? window_?.matchMedia?.('(pointer: coarse)')?.matches === true;
    let active = Boolean(enabled);
    let destroyed = false;
    let overlay = null;
    let host = null;
    let scheduled = null;
    let drag = null;
    let keyboardEdit = null;
    let observedResizeTargets = [];
    const handles = new Map();
    const bindings = [];
    const resizeObserver = typeof ResizeObserverClass === 'function'
        ? new ResizeObserverClass(() => refresh())
        : null;

    const observeResizeTargets = (selectedElement = null) => {
        if (!resizeObserver) return;
        const frameElement = editor.Canvas.getFrameEl?.();
        const frameDocument = editor.Canvas.getDocument?.();
        const targets = [...new Set([
            host,
            frameElement,
            frameDocument?.documentElement,
            frameDocument?.body,
            selectedElement,
        ].filter(Boolean))];
        if (targets.length === observedResizeTargets.length
            && targets.every((target, index) => target === observedResizeTargets[index])) return;

        resizeObserver.disconnect?.();
        targets.forEach((target) => resizeObserver.observe?.(target));
        observedResizeTargets = targets;
    };

    const ensure = () => {
        const selectionTools = editor.Canvas.getToolsEl?.();
        // getToolsEl() is GrapesJS' already-positioned selection box. Mounting
        // another absolutely-positioned overlay inside it applies that selected
        // component offset twice. Its parent is the canvas-origin tools layer,
        // which is also the coordinate space returned by getElementPos().
        const nextHost = selectionTools?.parentElement || selectionTools;
        if (!nextHost || !document_) return null;
        if (host !== nextHost) {
            overlay?.remove?.();
            overlay = null;
            host = nextHost;
            handles.clear();
        }
        if (overlay) return overlay;
        overlay = document_.createElement('div');
        overlay.className = 'rt-lmz-spacing-overlay';
        overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
        ['margin', 'padding'].forEach((type) => SIDES.forEach((side) => {
            const handle = document_.createElement('button');
            handle.type = 'button';
            handle.className = `rt-lmz-spacing-overlay__handle is-${type}`;
            handle.dataset.type = type;
            handle.dataset.side = side;
            handle.setAttribute('aria-label', `${type} ${side}`);
            handle.setAttribute('role', 'spinbutton');
            if (type === 'padding') handle.setAttribute('aria-valuemin', '0');
            handle.innerHTML = '<span class="rt-lmz-spacing-overlay__surface" aria-hidden="true"></span><span class="rt-lmz-spacing-overlay__label" aria-live="polite"></span>';
            handle.addEventListener('pointerdown', startDrag);
            handle.addEventListener('keydown', editWithKeyboard);
            handle.addEventListener('blur', commitKeyboardEdit);
            overlay.appendChild(handle);
            handles.set(`${type}:${side}`, handle);
        }));
        host.appendChild(overlay);
        return overlay;
    };

    const render = () => {
        scheduled = null;
        if (destroyed) return;
        const node = ensure();
        const selected = editor.getSelected?.();
        if (!node || !active || !selected || isFixedMailSignatureGeometry(selected)
            || editor.Commands?.isActive?.('core:preview')) {
            if (node) node.hidden = true;
            return;
        }
        const element = selected.getEl?.();
        observeResizeTargets(element);
        const position = element ? editor.Canvas.getElementPos?.(element) : null;
        const offsets = element ? editor.Canvas.getElementOffsets?.(element) : null;
        const geometry = calculateSpacingOverlayGeometry({ position, offsets, minimumBand: coarse ? 10 : 8 });
        if (!geometry) {
            node.hidden = true;
            return;
        }
        node.hidden = false;
        ['margin', 'padding'].forEach((type) => SIDES.forEach((side) => {
            const handle = handles.get(`${type}:${side}`);
            applyHandleRect(handle, geometry[type][side], side, coarse ? 44 : 12);
            const value = rounded(geometry.spacing[type][side] / Math.max(number(position.zoom, 1), 0.01));
            handle.dataset.value = `${value}px`;
            handle.setAttribute('aria-valuenow', String(value));
            handle.setAttribute('aria-valuetext', `${type} ${side}: ${value}px`);
            handle.querySelector?.('.rt-lmz-spacing-overlay__label')?.replaceChildren?.(`${type} ${side}: ${value}px`);
            handle.title = `${type} ${side}: ${value}px`;
        }));
    };

    const refresh = () => {
        if (destroyed || scheduled !== null) return;
        scheduled = requestFrame(render);
    };

    function removeDragListeners() {
        bindings.splice(0).forEach(({ target, name, listener }) => target.removeEventListener?.(name, listener, true));
    }

    function updateKeyboardHandle(handle, type, side, value) {
        const label = `${type} ${side}: ${value}px`;
        handle.dataset.value = `${value}px`;
        handle.setAttribute('aria-valuenow', String(value));
        handle.setAttribute('aria-valuetext', label);
        handle.title = label;
        handle.querySelector?.('.rt-lmz-spacing-overlay__label')?.replaceChildren?.(label);
    }

    function commitKeyboardEdit(event = null) {
        if (!keyboardEdit || (event?.currentTarget && keyboardEdit.handle !== event.currentTarget)) return;
        const current = keyboardEdit;
        keyboardEdit = null;
        current.component.addStyle?.(spacingStyle(current.type, current.startValues, current.side, current.latestValue));
        refresh();
    }

    function cancelKeyboardEdit(event = null) {
        if (!keyboardEdit || (event?.currentTarget && keyboardEdit.handle !== event.currentTarget)) return;
        const current = keyboardEdit;
        keyboardEdit = null;
        current.component.addStyle?.(spacingStyle(current.type, current.startValues, current.side, current.startValues[current.side]));
        updateKeyboardHandle(current.handle, current.type, current.side, current.startValues[current.side]);
        refresh();
    }

    function editWithKeyboard(event) {
        const arrows = { ArrowUp: 1, ArrowRight: 1, ArrowDown: -1, ArrowLeft: -1 };
        if (event.key === 'Escape' && keyboardEdit?.handle === event.currentTarget) {
            event.preventDefault?.();
            event.stopPropagation?.();
            cancelKeyboardEdit(event);
            return;
        }
        if (event.key === 'Enter' && keyboardEdit?.handle === event.currentTarget) {
            event.preventDefault?.();
            commitKeyboardEdit(event);
            return;
        }
        if (!(event.key in arrows) || !active || editor.Commands?.isActive?.('core:preview')) return;
        const component = editor.getSelected?.();
        if (isFixedMailSignatureGeometry(component)) return;
        const element = component?.getEl?.();
        const position = element ? editor.Canvas.getElementPos?.(element) : null;
        const offsets = element ? editor.Canvas.getElementOffsets?.(element) : null;
        const type = event.currentTarget?.dataset?.type;
        const side = event.currentTarget?.dataset?.side;
        if (!component || !position || !offsets || !['margin', 'padding'].includes(type) || !SIDES.includes(side)) return;
        event.preventDefault?.();
        event.stopPropagation?.();
        if (!keyboardEdit || keyboardEdit.handle !== event.currentTarget || keyboardEdit.component !== component) {
            const zoom = Math.max(number(position.zoom, 1), 0.01);
            const snapshot = spacingCssSnapshot(offsets, zoom);
            keyboardEdit = {
                component,
                type,
                side,
                handle: event.currentTarget,
                startValues: snapshot[type],
                latestValue: snapshot[type][side],
            };
        }
        const step = event.shiftKey ? 10 : 1;
        keyboardEdit.latestValue = rounded(clamp(
            keyboardEdit.latestValue + (arrows[event.key] * step),
            type === 'padding' ? 0 : Number.NEGATIVE_INFINITY,
        ));
        component.addStyle?.(
            spacingStyle(type, keyboardEdit.startValues, side, keyboardEdit.latestValue),
            { partial: true },
        );
        updateKeyboardHandle(event.currentTarget, type, side, keyboardEdit.latestValue);
    }

    function finishDrag(event) {
        if (!drag) return;
        event?.preventDefault?.();
        const final = drag.latestValue ?? drag.startValues[drag.side];
        drag.component.addStyle?.(spacingStyle(drag.type, drag.startValues, drag.side, final));
        drag.handle.classList.remove('is-active');
        drag = null;
        removeDragListeners();
        refresh();
    }

    function moveDrag(event) {
        if (!drag) return;
        event.preventDefault?.();
        const point = eventPoint(editor, event, drag.zoom);
        const value = calculateSpacingDragValue({
            startValue: drag.startValues[drag.side],
            deltaX: point.x - drag.startPoint.x,
            deltaY: point.y - drag.startPoint.y,
            zoom: drag.zoom,
            side: drag.side,
            type: drag.type,
        });
        drag.latestValue = value;
        drag.component.addStyle?.(spacingStyle(drag.type, drag.startValues, drag.side, value), { partial: true });
        refresh();
    }

    function startDrag(event) {
        if (!active || editor.Commands?.isActive?.('core:preview')) return;
        commitKeyboardEdit();
        const component = editor.getSelected?.();
        if (isFixedMailSignatureGeometry(component)) return;
        const element = component?.getEl?.();
        const position = element ? editor.Canvas.getElementPos?.(element) : null;
        const offsets = element ? editor.Canvas.getElementOffsets?.(element) : null;
        if (!component || !position || !offsets) return;
        event.preventDefault?.();
        event.stopPropagation?.();
        const zoom = Math.max(number(position.zoom, 1), 0.01);
        const type = event.currentTarget?.dataset?.type || event.target?.closest?.('[data-type]')?.dataset?.type;
        const side = event.currentTarget?.dataset?.side || event.target?.closest?.('[data-side]')?.dataset?.side;
        if (!['margin', 'padding'].includes(type) || !SIDES.includes(side)) return;
        const snapshot = spacingCssSnapshot(offsets, zoom);
        const handle = handles.get(`${type}:${side}`);
        handle?.classList.add('is-active');
        drag = {
            component, element, type, side, zoom, handle,
            startValues: snapshot[type],
            startPoint: eventPoint(editor, event, zoom),
            latestValue: null,
        };
        const frameWindow = editor.Canvas.getWindow?.();
        [...new Set([window_, frameWindow].filter(Boolean))].forEach((target) => {
            [['pointermove', moveDrag], ['pointerup', finishDrag], ['pointercancel', finishDrag]].forEach(([name, listener]) => {
                target.addEventListener?.(name, listener, true);
                bindings.push({ target, name, listener });
            });
        });
    }

    const events = ['load', 'component:selected', 'component:deselected', 'component:update', 'component:styleUpdate', 'canvas:coords', 'canvas:zoom', 'canvas:frame:load', 'rt:mail:preview-resize'];
    events.forEach((event) => editor.on?.(event, refresh));
    window_?.addEventListener?.('resize', refresh);
    refresh();

    return {
        setEnabled(next) {
            active = Boolean(next);
            if (overlay) overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
            if (!active) finishDrag();
            if (!active) cancelKeyboardEdit();
            refresh();
        },
        refresh,
        destroy() {
            if (destroyed) return;
            destroyed = true;
            finishDrag();
            cancelKeyboardEdit();
            if (scheduled !== null) cancelFrame(scheduled);
            scheduled = null;
            events.forEach((event) => editor.off?.(event, refresh));
            window_?.removeEventListener?.('resize', refresh);
            resizeObserver?.disconnect?.();
            observedResizeTargets = [];
            overlay?.remove?.();
            overlay = null;
            host = null;
        },
    };
}

function panelToggle(root, panel) {
    return root?.querySelector?.(`[data-lmz-panel-toggle="${panel}"]`) || null;
}

function openPanel(root, panel) {
    const button = panelToggle(root, panel);
    if (!button) return false;
    if (button.getAttribute('aria-expanded') !== 'true') {
        button.dataset.rtLmzExplicitOpen = panel;
        button.click();
        delete button.dataset.rtLmzExplicitOpen;
    }
    return true;
}

function closeAutomaticallyOpenedStyles(root, intentionalPanel = null) {
    const rightActive = root?.querySelector?.('[data-lmz-panel-group="right"][aria-expanded="true"]');
    if (rightActive?.dataset?.lmzPanelToggle === 'right:styles'
        && intentionalPanel !== 'right:styles') {
        rightActive.dataset.rtLmzAutoClosing = 'true';
        rightActive.click();
        delete rightActive.dataset.rtLmzAutoClosing;
    }
}

function addActionLabel(button, label) {
    if (!button) return;
    button.classList.add('rt-lmz-toolbar-action');
    button.setAttribute('aria-label', label);
    const iconOnly = button.classList.contains('is-icon-only');
    const existing = button.querySelector('.lmz-builder__action-label');
    if (existing) {
        existing.classList.add('rt-lmz-toolbar-action__label');
        existing.classList.toggle('sr-only', iconOnly);
        existing.textContent = label;
    } else if (!button.querySelector('.rt-lmz-toolbar-action__label')) {
        const span = button.ownerDocument.createElement('span');
        span.className = 'rt-lmz-toolbar-action__label';
        if (iconOnly) span.classList.add('sr-only');
        span.textContent = label;
        button.appendChild(span);
    }
}

function canvasToolbars(editor, root) {
    const toolbars = [];
    const add = (toolbar) => {
        if (toolbar && !toolbars.includes(toolbar)) toolbars.push(toolbar);
    };

    add(editor?.Canvas?.getToolbarEl?.());
    root?.querySelectorAll?.('.lmzbjs-toolbar')?.forEach?.(add);

    return toolbars;
}

function visibleCanvasToolbar(editor, root) {
    const toolbars = canvasToolbars(editor, root);
    const isVisible = (toolbar) => {
        if (toolbar.hidden) return false;
        if (toolbar.getAttribute?.('aria-hidden') === 'true') return false;
        const rect = toolbar.getBoundingClientRect?.();
        return Boolean(rect && (rect.width > 0 || rect.height > 0));
    };
    const visible = [...(root?.querySelectorAll?.('.lmzbjs-toolbar') || [])].filter(isVisible).at(-1)
        || toolbars.filter(isVisible).at(-1);

    // In DOM-Testumgebungen und waehrend GrapesJS die Auswahl positioniert,
    // liefern alle Toolbars kurzzeitig 0 x 0. Die zuletzt erzeugte Toolbar ist
    // dann die dynamische Auswahlleiste, waehrend getToolbarEl() noch auf die
    // vorherige, bereits abgeloeste Instanz zeigen kann.
    return visible || toolbars.at(-1) || null;
}

function visibleToolbarCommand(editor, root, command) {
    const control = visibleCanvasToolbar(editor, root)?.querySelector?.(`[data-command="${command}"]`) || null;
    if (!control || control.hidden || control.disabled || control.getAttribute?.('aria-disabled') === 'true') return null;
    return control;
}

function inlineActionIcon(action) {
    const paths = INLINE_ACTION_ICON_PATHS[action] || INLINE_ACTION_ICON_PATHS.content;
    return `<svg class="rt-lmz-inline-menu__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${paths}</svg>`;
}

function consumeEscape(event) {
    event.preventDefault?.();
    event.stopPropagation?.();
    event.stopImmediatePropagation?.();
}

function openVendorPopover(root, eventTarget = null) {
    const contextual = eventTarget?.closest?.('[data-lmz-popover].is-open');
    if (contextual && contextual.hidden !== true && root.contains?.(contextual)) return contextual;

    return [...(root.querySelectorAll?.('[data-lmz-popover].is-open') || [])]
        .filter((popover) => popover.hidden !== true)
        .at(-1) || null;
}

function closeVendorPopover(root, popover) {
    if (!popover) return false;
    const activePanel = popover.querySelector?.('[data-lmz-popover-panel].is-active:not([hidden])');
    const panelValue = activePanel?.dataset?.lmzPopoverPanel || null;
    const group = popover.dataset?.lmzPopover || String(panelValue || '').split(':')[0];
    const closeButton = activePanel?.querySelector?.('[data-lmz-panel-close]')
        || popover.querySelector?.('[data-lmz-panel-close]');
    const toggle = [...(root.querySelectorAll?.('[data-lmz-panel-toggle]') || [])]
        .find((button) => button.dataset?.lmzPanelToggle === panelValue)
        || [...(root.querySelectorAll?.('[data-lmz-panel-group]') || [])]
            .find((button) => button.dataset?.lmzPanelGroup === group && button.getAttribute?.('aria-expanded') === 'true');

    if (closeButton && !closeButton.disabled) {
        closeButton.click?.();
    } else {
        // Defensive fallback for incomplete test/legacy markup. Normal LMZ
        // 2.4.5 panels always close through their vendor button above.
        popover.classList?.remove?.('is-open');
        popover.hidden = true;
        activePanel?.classList?.remove?.('is-active');
        if (activePanel) activePanel.hidden = true;
        if (group) root.classList?.remove?.(`has-${group}-popover`);
        [...(root.querySelectorAll?.('[data-lmz-panel-group]') || [])].forEach((button) => {
            if (button.dataset?.lmzPanelGroup !== group) return;
            button.classList?.remove?.('is-active');
            button.setAttribute?.('aria-expanded', 'false');
        });
    }

    toggle?.focus?.();
    return true;
}

let lmzElementorLayoutSequence = 0;

function captureNodePosition(node) {
    return node ? { node, parent: node.parentNode, nextSibling: node.nextSibling } : null;
}

function restoreNodePosition(position) {
    const { node, parent, nextSibling } = position || {};
    if (!node || !parent) return;
    parent.insertBefore(node, nextSibling?.parentNode === parent ? nextSibling : null);
}

/**
 * Baut aus den bereits vom Vendor verdrahteten Bedienelementen zwei dauerhafte
 * Elementor-artige Kontroll-Docks. Die Knoten werden bewusst nur umgehaengt:
 * GrapesJS-Manager, Listener und Mounts bleiben dadurch dieselben Instanzen.
 */
function installElementorEditorLayout({ root, modeIndicator }) {
    const viewport = root.querySelector('.lmz-builder__viewport');
    const main = viewport?.querySelector(':scope > .lmz-builder__main')
        || viewport?.querySelector('.lmz-builder__main');
    const leftActions = root.querySelector('.lmz-builder__panel-actions--left');
    const rightActions = root.querySelector('.lmz-builder__panel-actions--right');
    const meta = root.querySelector('.lmz-builder__meta');
    const leftPopover = root.querySelector('[data-lmz-popover="left"]');
    const rightPopover = root.querySelector('[data-lmz-popover="right"]');
    if (!viewport || !main || (!leftActions && !rightActions)) return null;

    const document_ = root.ownerDocument;
    const layoutId = ++lmzElementorLayoutSequence;
    const previousLayout = root.hasAttribute('data-rt-lmz-layout')
        ? root.getAttribute('data-rt-lmz-layout')
        : null;
    const toolbarPositions = [leftActions, rightActions, meta].map(captureNodePosition).filter(Boolean);
    const popoverPositions = [leftPopover, rightPopover].map(captureNodePosition).filter(Boolean);
    const rightActionOrder = [...(rightActions?.children || [])];

    const createDock = ({ side, label, modifier }) => {
        const dock = document_.createElement('aside');
        dock.className = `rt-lmz-control-dock rt-lmz-control-dock--${modifier}`;
        dock.dataset.rtLmzControlDock = '';
        dock.dataset.rtLmzSide = side;
        dock.setAttribute('aria-label', label);
        const header = document_.createElement('header');
        header.className = 'rt-lmz-control-dock__header';
        const tabs = document_.createElement('div');
        tabs.className = 'rt-lmz-control-dock__tabs';
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', label);
        const panels = document_.createElement('div');
        panels.className = 'rt-lmz-control-dock__panels';
        const footer = document_.createElement('footer');
        footer.className = 'rt-lmz-control-dock__footer';
        dock.append(header, tabs, panels, footer);
        return { dock, header, tabs, panels, footer };
    };
    const navigation = createDock({ side: 'left', label: 'Editor-Navigation', modifier: 'navigation' });
    const inspector = createDock({ side: 'right', label: 'Editor-Einstellungen', modifier: 'inspector' });
    const docks = [navigation, inspector];

    if (modeIndicator) navigation.header.append(modeIndicator);
    if (leftActions) navigation.tabs.append(leftActions);
    if (rightActions) {
        // Die Bedienreihenfolge folgt dem Arbeitsfluss: Inhalt vor Gestaltung.
        const traits = rightActions.querySelector('[data-lmz-panel-toggle="right:traits"]');
        const styles = rightActions.querySelector('[data-lmz-panel-toggle="right:styles"]');
        if (traits && styles) rightActions.insertBefore(traits, styles);
        inspector.tabs.append(rightActions);
    }
    if (leftPopover) navigation.panels.append(leftPopover);
    if (rightPopover) inspector.panels.append(rightPopover);
    if (meta) inspector.footer.append(meta);
    viewport.insertBefore(navigation.dock, main);
    viewport.insertBefore(inspector.dock, main.nextSibling);
    root.dataset.rtLmzLayout = 'elementor';

    const labels = Object.freeze({
        'left:blocks': 'Bausteine',
        'left:layers': 'Ebenen',
        'right:traits': 'Eigenschaften',
        'right:styles': 'Stile',
        'right:classes': 'Klassen',
    });
    const tabLists = docks.map(({ tabs }) => tabs);
    const toggles = tabLists.flatMap((tabs) => [...tabs.querySelectorAll('[data-lmz-panel-toggle]')]);
    const tabSnapshots = toggles.map((toggle) => ({
        toggle,
        role: toggle.getAttribute('role'),
        ariaSelected: toggle.getAttribute('aria-selected'),
        ariaControls: toggle.getAttribute('aria-controls'),
        ariaHaspopup: toggle.getAttribute('aria-haspopup'),
        tabindex: toggle.getAttribute('tabindex'),
        id: toggle.getAttribute('id'),
        label: toggle.querySelector('.lmz-builder__action-label')?.textContent ?? null,
    }));
    const panelSnapshots = [];

    toggles.forEach((toggle) => {
        const panelId = toggle.dataset.lmzPanelToggle;
        const panel = root.querySelector(`[data-lmz-popover-panel="${panelId}"]`);
        const domId = `rt-lmz-elementor-${layoutId}-${String(panelId).replace(/[^a-z0-9]+/gi, '-')}`;
        toggle.setAttribute('role', 'tab');
        toggle.removeAttribute('aria-haspopup');
        toggle.setAttribute('aria-controls', domId);
        const label = toggle.querySelector('.lmz-builder__action-label');
        if (label && labels[panelId]) label.textContent = labels[panelId];
        if (!panel) return;
        panelSnapshots.push({
            panel,
            id: panel.getAttribute('id'),
            role: panel.getAttribute('role'),
            ariaLabelledby: panel.getAttribute('aria-labelledby'),
            ariaHidden: panel.getAttribute('aria-hidden'),
        });
        panel.id = domId;
        panel.setAttribute('role', 'tabpanel');
        if (!toggle.id) toggle.id = `${domId}-tab`;
        panel.setAttribute('aria-labelledby', toggle.id);
    });

    const panelGroups = [...new Set(toggles.map((toggle) => toggle.dataset.lmzPanelGroup).filter(Boolean))];
    const availableTabs = (group) => toggles.filter((toggle) => (
        toggle.dataset.lmzPanelGroup === group
        && !toggle.hidden
        && !toggle.inert
        && toggle.getAttribute('aria-disabled') !== 'true'
    ));
    const syncTabs = () => {
        panelGroups.forEach((group) => {
            const groupToggles = toggles.filter((toggle) => toggle.dataset.lmzPanelGroup === group);
            const available = availableTabs(group);
            const selected = available.find((toggle) => toggle.getAttribute('aria-expanded') === 'true') || available[0] || null;
            groupToggles.forEach((toggle) => {
                const active = toggle === selected && toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-selected', active ? 'true' : 'false');
                toggle.setAttribute('tabindex', toggle === selected ? '0' : '-1');
                const controlled = toggle.getAttribute('aria-controls');
                const panel = controlled ? document_.getElementById(controlled) : null;
                panel?.setAttribute('aria-hidden', active && panel.classList.contains('is-active') ? 'false' : 'true');
            });
        });
    };
    const compactViewport = document_.defaultView?.matchMedia?.('(max-width: 1099.98px)') || null;
    const usesOverlayDrawers = () => compactViewport?.matches === true;
    const closeOtherPanelGroups = (targetGroup) => {
        if (!usesOverlayDrawers()) return;
        [...root.querySelectorAll('[data-lmz-popover].is-open')].forEach((popover) => {
            if (popover.dataset.lmzPopover !== targetGroup) closeVendorPopover(root, popover);
        });
    };
    const closeOppositePanelBeforeOpen = (event) => {
        const toggle = event.target?.closest?.('[data-lmz-panel-toggle]');
        const tabList = event.currentTarget;
        if (!toggle || !tabList.contains(toggle) || toggle.getAttribute('aria-expanded') === 'true') return;
        closeOtherPanelGroups(toggle.dataset.lmzPanelGroup);
    };
    const enforceSinglePanelAfterOpen = (event) => {
        const toggle = event.currentTarget;
        if (toggle.getAttribute('aria-expanded') === 'true') closeOtherPanelGroups(toggle.dataset.lmzPanelGroup);
        syncTabs();
    };
    const scheduleTabSync = () => {
        if (typeof globalThis.queueMicrotask === 'function') globalThis.queueMicrotask(syncTabs);
        else Promise.resolve().then(syncTabs);
    };
    const handleTabKeys = (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const current = event.target?.closest?.('[role="tab"]');
        const available = availableTabs(current?.dataset.lmzPanelGroup);
        const index = available.indexOf(current);
        if (index < 0 || available.length === 0) return;
        event.preventDefault();
        let nextIndex = index;
        if (event.key === 'Home') nextIndex = 0;
        else if (event.key === 'End') nextIndex = available.length - 1;
        else nextIndex = (index + (event.key === 'ArrowRight' ? 1 : -1) + available.length) % available.length;
        available[nextIndex].click?.();
        available[nextIndex].focus?.();
    };
    tabLists.forEach((tabs) => {
        tabs.addEventListener('click', closeOppositePanelBeforeOpen, true);
        tabs.addEventListener('click', scheduleTabSync);
        tabs.addEventListener('keydown', handleTabKeys);
    });
    toggles.forEach((toggle) => toggle.addEventListener('click', enforceSinglePanelAfterOpen));
    root.addEventListener('pointerdown', scheduleTabSync);
    const MutationObserverClass = document_.defaultView?.MutationObserver || globalThis.MutationObserver;
    const observer = typeof MutationObserverClass === 'function'
        ? new MutationObserverClass(syncTabs)
        : null;
    observer?.observe(root, {
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-expanded', 'hidden', 'class'],
    });

    const leftTabs = availableTabs('left');
    const firstTab = leftTabs.find((toggle) => toggle.dataset.lmzPanelToggle === 'left:blocks') || leftTabs[0];
    if (!leftTabs.some((toggle) => toggle.getAttribute('aria-expanded') === 'true')) firstTab?.click?.();
    syncTabs();

    return {
        syncTabs,
        openContextPanel() {
            const preferred = ['right:traits', 'right:styles']
                .map((panelId) => panelToggle(root, panelId))
                .find((toggle) => toggle && !toggle.hidden && !toggle.inert && toggle.getAttribute('aria-disabled') !== 'true');
            if (!preferred) return false;
            closeOtherPanelGroups(preferred.dataset.lmzPanelGroup);
            const opened = openPanel(root, preferred.dataset.lmzPanelToggle);
            syncTabs();
            return opened;
        },
        destroy() {
            observer?.disconnect?.();
            tabLists.forEach((tabs) => {
                tabs.removeEventListener('click', closeOppositePanelBeforeOpen, true);
                tabs.removeEventListener('click', scheduleTabSync);
                tabs.removeEventListener('keydown', handleTabKeys);
            });
            toggles.forEach((toggle) => toggle.removeEventListener('click', enforceSinglePanelAfterOpen));
            root.removeEventListener('pointerdown', scheduleTabSync);
            tabSnapshots.forEach(({ toggle, role, ariaSelected, ariaControls, ariaHaspopup, tabindex, id, label }) => {
                const restore = (name, value) => value === null ? toggle.removeAttribute(name) : toggle.setAttribute(name, value);
                restore('role', role);
                restore('aria-selected', ariaSelected);
                restore('aria-controls', ariaControls);
                restore('aria-haspopup', ariaHaspopup);
                restore('tabindex', tabindex);
                restore('id', id);
                const labelElement = toggle.querySelector('.lmz-builder__action-label');
                if (labelElement && label !== null) labelElement.textContent = label;
            });
            panelSnapshots.forEach(({ panel, id, role, ariaLabelledby, ariaHidden }) => {
                const restore = (name, value) => value === null ? panel.removeAttribute(name) : panel.setAttribute(name, value);
                restore('id', id);
                restore('role', role);
                restore('aria-labelledby', ariaLabelledby);
                restore('aria-hidden', ariaHidden);
            });
            rightActionOrder.forEach((button) => rightActions?.append(button));
            [...popoverPositions].reverse().forEach(restoreNodePosition);
            [...toolbarPositions].reverse().forEach(restoreNodePosition);
            docks.forEach(({ dock }) => dock.remove());
            if (previousLayout === null) delete root.dataset.rtLmzLayout;
            else root.setAttribute('data-rt-lmz-layout', previousLayout);
        },
    };
}

function markMoveHandleReady(handle) {
    if (!handle) return false;
    const marker = `${Date.now()}-${Math.random()}`;
    const previous = {
        tabindex: handle.getAttribute?.('tabindex'),
        title: handle.getAttribute?.('title'),
        ariaLabel: handle.getAttribute?.('aria-label'),
    };
    handle.__rtLmzMoveReadyMarker = marker;
    handle.classList?.add?.('rt-lmz-move-ready');
    handle.dataset.rtLmzMoveReady = 'true';
    if (!handle.hasAttribute?.('tabindex')) handle.setAttribute?.('tabindex', '-1');
    handle.setAttribute?.('title', 'Diesen Griff ziehen, um das Element zu verschieben');
    handle.setAttribute?.('aria-label', 'Element ziehen und umpositionieren');
    handle.focus?.();
    const timeout = globalThis.setTimeout?.(() => {
        if (handle.__rtLmzMoveReadyMarker !== marker) return;
        delete handle.__rtLmzMoveReadyMarker;
        delete handle.dataset.rtLmzMoveReady;
        handle.classList?.remove?.('rt-lmz-move-ready');
        Object.entries(previous).forEach(([attribute, value]) => {
            const name = attribute === 'ariaLabel' ? 'aria-label' : attribute;
            if (value === null || value === undefined) handle.removeAttribute?.(name);
            else handle.setAttribute?.(name, value);
        });
    }, 1800);
    timeout?.unref?.();
    return true;
}

function scopedImageReplacementEnabled(capabilities, mode) {
    return capabilities.imageReplace === true
        || (mode === 'mail' && capabilities.imageReplace === 'tokens-only');
}

function imageParentByAttribute(component, attribute, maximumDepth = 12) {
    let current = component;
    let depth = 0;

    while (current && depth < maximumDepth) {
        if (Object.prototype.hasOwnProperty.call(componentAttributes(current), attribute)) return current;
        current = current.parent?.() || null;
        depth += 1;
    }

    return null;
}

function imageParentCell(component, maximumDepth = 12) {
    let current = component?.parent?.() || null;
    let depth = 0;

    while (current && depth < maximumDepth) {
        if (String(current.get?.('tagName') || current.tagName || '').toLowerCase() === 'td') return current;
        current = current.parent?.() || null;
        depth += 1;
    }

    return null;
}

function imageSourceIsSafe(source, baseUrl = globalThis.location?.href || 'http://localhost/') {
    const normalized = String(source || '').trim();
    if (!normalized || /[\u0000-\u001f\u007f]/.test(normalized)) return false;
    if (/^\{\{[A-Z][A-Z0-9_]{1,63}\}\}$/.test(normalized)) return true;
    if (/^\/\//.test(normalized)) return false;
    if (/^cid:/i.test(normalized)) return true;
    const dataImage = normalized.match(/^data:image\/(?:png|jpe?g|gif|webp);base64,([a-z0-9+/]*={0,2})$/i);
    if (dataImage) return dataImage[1].length > 0 && dataImage[1].length % 4 === 0;
    if (!/^[a-z][a-z0-9+.\-]*:/i.test(normalized)) return true;

    try {
        const base = new URL(baseUrl, globalThis.location?.href || 'http://localhost/');
        const candidate = new URL(normalized, base);
        return ['http:', 'https:'].includes(candidate.protocol) && candidate.host === base.host;
    } catch (_) {
        return false;
    }
}

function inferredImageAlignment(image, layer = null) {
    const attributes = componentAttributes(layer || image);
    const imageAttributes = componentAttributes(image);
    const cell = imageParentCell(image);
    const cellAttributes = componentAttributes(cell);
    const style = image?.getStyle?.() || {};
    const requested = attributes['data-rt-layer-align']
        || imageAttributes['data-rt-image-align']
        || cellAttributes.align;

    if (['left', 'center', 'right'].includes(requested)) return requested;
    if (style.margin === '0 auto') return 'center';
    if (style.margin === '0 0 0 auto') return 'right';
    return 'left';
}

function numericImageWidth(image, fallback = 600) {
    const attributes = componentAttributes(image);
    const style = image?.getStyle?.() || {};
    const candidate = String(attributes.width || style['max-width'] || '').match(/\d{1,4}/)?.[0];
    const width = Number.parseInt(candidate || String(fallback), 10);

    return Math.min(1200, Math.max(40, Number.isFinite(width) ? width : fallback));
}

let imagePropertiesPanelSequence = 0;

/**
 * Kompakter, mail-sicherer Bildinspector im Eigenschaften-Panel.
 *
 * Systemmedien behalten ihren data-rt-mail-preview-token. Eine geaenderte
 * Quelle steuert damit die Canvas-Vorschau beziehungsweise den bestehenden
 * Medienpfad, waehrend der Serializer den kanonischen Slot weiter speichern
 * kann. Zugbreite und -ausrichtung werden auf dessen vorhandene Preset-
 * Attribute geschrieben. Die vertikale Ueberlappung bleibt dagegen ein
 * normaler, editierbarer Pixel-Margin und darf ausdruecklich negativ sein.
 */
function createImagePropertiesPanel({ root, editor, capabilities, media = {}, onChanged }) {
    const document_ = root.ownerDocument;
    imagePropertiesPanelSequence += 1;
    const sourceHintId = `rt-lmz-image-source-hint-${imagePropertiesPanelSequence}`;
    const traitsPanel = root.querySelector('[data-lmz-popover-panel="right:traits"]');
    const traitsMount = traitsPanel?.querySelector?.('[data-lmz-mount="traits"]');
    const traitsBody = traitsMount?.parentElement || null;
    if (!traitsBody) {
        return {
            refresh() {},
            hasTarget: () => false,
            destroy() {},
        };
    }

    const panel = document_.createElement('section');
    panel.className = 'rt-lmz-image-properties';
    panel.hidden = true;
    panel.setAttribute('aria-label', 'Bildeigenschaften');
    panel.innerHTML = `
        <header class="rt-lmz-image-properties__header">
            <span class="rt-lmz-image-properties__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m5 18 5-5 3 3 2-2 4 4"/></svg>
            </span>
            <span><strong>Bild</strong><small data-rt-lmz-image-kind>Mail-sichere Darstellung</small></span>
        </header>
        <form class="rt-lmz-image-properties__form" data-rt-lmz-image-form>
            <label class="rt-lmz-image-properties__field rt-lmz-image-properties__field--wide">
                <span>Quelle</span>
                <input type="text" name="source" inputmode="url" autocomplete="off" spellcheck="false" aria-describedby="${sourceHintId}">
            </label>
            <p class="rt-lmz-image-properties__hint" id="${sourceHintId}" data-rt-lmz-image-source-hint>HTTPS-, lokale, CID-, Daten- oder Vorlagenquelle.</p>
            <label class="rt-lmz-image-properties__field rt-lmz-image-properties__field--wide">
                <span>Alternativtext</span>
                <input type="text" name="alt" autocomplete="off" maxlength="240" placeholder="Bildinhalt kurz beschreiben">
            </label>
            <label class="rt-lmz-image-properties__field" data-rt-lmz-image-width-pixels>
                <span>Breite (px)</span>
                <input type="number" name="width" min="40" max="1200" step="1" inputmode="numeric">
            </label>
            <label class="rt-lmz-image-properties__field" data-rt-lmz-image-width-preset hidden>
                <span>Zugbreite</span>
                <select name="trainWidth">
                    <option value="100">100 %</option>
                    <option value="108.67">108,67 % · Halt bei 65 %</option>
                    <option value="125">125 % · Standard</option>
                    <option value="150">150 %</option>
                    <option value="200">200 %</option>
                </select>
            </label>
            <label class="rt-lmz-image-properties__field">
                <span>Ausrichtung</span>
                <select name="alignment">
                    <option value="left">Links</option>
                    <option value="center">Mittig</option>
                    <option value="right">Rechts</option>
                </select>
            </label>
            <p class="rt-lmz-image-properties__message" data-rt-lmz-image-message aria-live="polite"></p>
            <button type="submit" class="rt-lmz-image-properties__apply">Übernehmen</button>
        </form>`;
    traitsBody.insertBefore(panel, traitsMount);

    const form = panel.querySelector('[data-rt-lmz-image-form]');
    const kind = panel.querySelector('[data-rt-lmz-image-kind]');
    const sourceHint = panel.querySelector('[data-rt-lmz-image-source-hint]');
    const message = panel.querySelector('[data-rt-lmz-image-message]');
    const pixelWidthField = panel.querySelector('[data-rt-lmz-image-width-pixels]');
    const presetWidthField = panel.querySelector('[data-rt-lmz-image-width-preset]');
    let target = null;
    let trainLayer = null;
    let targetIsTrain = false;
    let editable = false;

    const refresh = (selection = editor.getSelected?.()) => {
        target = resolveInspectableImageComponent(editor, selection);
        trainLayer = target ? imageParentByAttribute(target, 'data-rt-layer-train') : null;
        targetIsTrain = false;
        editable = false;
        panel.hidden = !target;
        if (!target) return false;

        const attributes = componentAttributes(target);
        const token = String(attributes['data-rt-mail-preview-token'] || '').trim();
        const tokenMedia = typeof media.tokenMedia === 'function' ? media.tokenMedia() : media.tokenMedia;
        const systemSource = (Array.isArray(tokenMedia) ? tokenMedia : [])
            .find((item) => normalizedToken(item?.token) === normalizedToken(token))?.src;
        const renderedSource = target.getEl?.()?.getAttribute?.('src');
        const source = String(renderedSource || systemSource || attributes.src || target.get?.('src') || '').trim();
        const animated = isAnimatedImageSource(source, attributes['data-mime-type']);
        targetIsTrain = Boolean(trainLayer || attributes['data-rt-train'] !== undefined);
        editable = Boolean(
            capabilities.writable
            && (!isProtectedEditorStructure(target) || ['TRAIN_SRC', 'LOGO_SRC'].includes(token))
        );
        const layerAttributes = componentAttributes(trainLayer);
        form.elements.source.value = source;
        form.elements.alt.value = String(attributes.alt || '');
        form.elements.width.value = String(numericImageWidth(target));
        form.elements.trainWidth.value = ['100', '108.67', '125', '150', '200'].includes(String(layerAttributes['data-rt-layer-size']))
            ? String(layerAttributes['data-rt-layer-size'])
            : '125';
        form.elements.alignment.value = inferredImageAlignment(target, trainLayer);
        pixelWidthField.hidden = targetIsTrain;
        presetWidthField.hidden = !targetIsTrain;
        form.toggleAttribute('data-system-medium', Boolean(token));
        kind.textContent = token
            ? `Systemmedium · ${token}${animated ? ' · GIF' : ''}`
            : (animated ? 'Animiertes GIF' : 'Mail-sicheres Bild');
        sourceHint.textContent = token
            ? 'Die Quelle ändert die Vorschau; der Systemmedien-Slot bleibt beim Speichern erhalten.'
            : 'HTTPS-, lokale, CID-, Daten- oder Vorlagenquelle. Unsichere Protokolle werden abgewiesen.';
        message.textContent = '';
        form.querySelectorAll('input, select, button').forEach((control) => {
            control.disabled = !editable;
        });
        form.elements.alt.disabled = !editable || targetIsTrain;
        if (targetIsTrain) {
            form.elements.alt.value = '';
            form.elements.alt.title = 'Der dekorative Zug bleibt für Mailclients mit leerem Alternativtext ausgeblendet.';
        } else {
            form.elements.alt.removeAttribute('title');
        }
        if (!editable) {
            message.dataset.state = 'muted';
            message.textContent = 'Dieses Systemmedium ist strukturell gebunden und wird über seinen System-Slot verwaltet.';
        }
        return editable;
    };

    const onSubmit = (event) => {
        event.preventDefault();
        if (!target || !editable) return;

        const source = String(form.elements.source.value || '').trim();
        if (!imageSourceIsSafe(source, document_.baseURI)) {
            message.dataset.state = 'error';
            message.textContent = 'Bitte eine sichere HTTPS-, lokale, CID-, Daten- oder Vorlagenquelle verwenden.';
            form.elements.source.focus();
            return;
        }

        const alt = targetIsTrain ? '' : String(form.elements.alt.value || '').trim().slice(0, 240);
        const alignment = ['left', 'center', 'right'].includes(form.elements.alignment.value)
            ? form.elements.alignment.value
            : 'left';
        target.set?.('src', source);
        target.addAttributes?.({ src: source, alt });

        if (trainLayer) {
            const size = ['100', '108.67', '125', '150', '200'].includes(form.elements.trainWidth.value)
                ? form.elements.trainWidth.value
                : '125';
            trainLayer.addAttributes?.({
                'data-rt-layer-align': alignment,
                'data-rt-layer-size': size,
            });
            // Der Mail-Adapter lauscht auf genau dieses Ereignis und setzt
            // daraus die kanonischen Layer-/IMG-Inline-Stile. Das explizite
            // Triggern stellt die Synchronisierung auch bei Adapterversionen
            // sicher, deren addAttributes-Aufruf gebuendelt wird.
            editor.trigger?.('component:update', trainLayer);
        } else {
            const requestedWidth = Number.parseInt(String(form.elements.width.value || ''), 10);
            const width = Math.min(1200, Math.max(40, Number.isFinite(requestedWidth) ? requestedWidth : 600));
            const margin = { left: '0', center: '0 auto', right: '0 0 0 auto' }[alignment];
            target.addAttributes?.({ width: String(width), 'data-rt-image-align': alignment });
            target.removeAttributes?.('height');
            target.addStyle?.({
                display: 'block',
                width: '100%',
                'max-width': `${width}px`,
                height: 'auto',
                margin,
                border: '0',
                outline: 'none',
                'vertical-align': 'top',
            });
            const cell = imageParentCell(target);
            if (cell) {
                cell.addAttributes?.({ align: alignment });
                cell.addStyle?.({ 'text-align': alignment });
            }
            form.elements.width.value = String(width);
        }

        onChanged?.();
        message.dataset.state = 'success';
        message.textContent = 'Bildeigenschaften übernommen.';
        editor.select?.(target);
    };

    form.addEventListener('submit', onSubmit);
    refresh();

    return {
        refresh,
        hasTarget: () => Boolean(target),
        canEdit: () => editable,
        destroy() {
            form.removeEventListener('submit', onSubmit);
            panel.remove();
            target = null;
            trainLayer = null;
            targetIsTrain = false;
            editable = false;
        },
    };
}

function createMediaDrawer({ root, editor, mode, media, capabilities, onChanged }) {
    const document_ = root.ownerDocument;
    const viewport = root.querySelector('.lmz-builder__viewport') || root;
    const drawer = document_.createElement('section');
    drawer.className = 'rt-lmz-media-drawer';
    drawer.hidden = true;
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'false');
    drawer.setAttribute('aria-label', mode === 'mail' ? 'Mail-Bilder' : 'Medien');
    drawer.innerHTML = `
        <header class="rt-lmz-media-drawer__header">
            <div><strong>${mode === 'mail' ? 'Mail-Bilder' : 'Medien'}</strong><small data-rt-lmz-media-summary></small></div>
            <button type="button" data-rt-lmz-media-close aria-label="Medien schliessen">&times;</button>
        </header>
        <div class="rt-lmz-media-drawer__tabs" role="tablist">
            <button type="button" role="tab" data-rt-lmz-media-tab="used" aria-selected="true">Verwendet</button>
            <button type="button" role="tab" data-rt-lmz-media-tab="library" aria-selected="false">Bibliothek</button>
        </div>
        <label class="rt-lmz-media-drawer__filter"><span class="sr-only">Medien filtern</span><input type="search" data-rt-lmz-media-filter placeholder="Medien filtern"></label>
        <div class="rt-lmz-media-drawer__warning" data-rt-lmz-media-warning hidden role="alert"></div>
        <div class="rt-lmz-media-drawer__list" data-rt-lmz-media-list></div>
        <p class="rt-lmz-media-drawer__hint" data-rt-lmz-media-hint></p>`;
    viewport.appendChild(drawer);
    const list = drawer.querySelector('[data-rt-lmz-media-list]');
    const warning = drawer.querySelector('[data-rt-lmz-media-warning]');
    const summary = drawer.querySelector('[data-rt-lmz-media-summary]');
    const hint = drawer.querySelector('[data-rt-lmz-media-hint]');
    const filter = drawer.querySelector('[data-rt-lmz-media-filter]');
    let tab = 'used';
    let replaceSession = null;
    let boundSelection = null;
    let state = { used: [], library: [], warnings: [] };
    let returnFocus = null;
    const currentTokenMedia = () => {
        const value = typeof media.tokenMedia === 'function' ? media.tokenMedia() : media.tokenMedia;
        return Array.isArray(value) ? value : [];
    };
    const currentAssets = () => {
        const value = typeof media.assets === 'function' ? media.assets() : media.assets;
        return (Array.isArray(value) ? value : []).filter((asset) => {
            const normalized = normalizeAsset(asset, media.baseUrl || globalThis.location?.origin || 'http://localhost/');
            return normalized.src && normalized.type === 'image';
        });
    };
    const canReplace = (target = editor.getSelected?.()) => (
        scopedImageReplacementEnabled(capabilities, mode)
        && currentAssets().length > 0
        && Boolean(resolveEditableImageComponent(editor, target, { mode }))
    );

    const currentDocument = () => ({
        html: editor.getHtml?.() || '',
        css: editor.getCss?.() || '',
    });
    const refresh = () => {
        const documentState = currentDocument();
        state = collectUsedMedia({
            ...documentState,
            mode,
            assets: currentAssets(),
            trustedSources: media.trustedSources || [],
            tokenMedia: currentTokenMedia(),
            baseUrl: media.baseUrl || globalThis.location?.origin || 'http://localhost/',
            environment: { DOMParser: globalThis.DOMParser },
        });
        const query = String(filter.value || '').trim().toLocaleLowerCase('de');
        const tokenLibrary = currentTokenMedia().map((item) => ({
            id: `token:${normalizedToken(item.token)}`, token: normalizedToken(item.token),
            src: item.src || '', name: item.label || item.token, protected: true, allowed: true,
        }));
        const items = (tab === 'used'
            ? state.used
            : (mode === 'mail' ? [...tokenLibrary, ...state.library] : state.library))
            .filter((item) => !query || `${item.name} ${item.category || ''} ${item.token || ''}`.toLocaleLowerCase('de').includes(query));
        list.replaceChildren();
        items.forEach((item) => {
            const button = document_.createElement('button');
            button.type = 'button';
            button.className = 'rt-lmz-media-item';
            button.dataset.status = item.status || (item.protected ? 'protected' : 'available');
            const source = item.src || '';
            let sameOrigin = /^\//.test(source);
            try {
                sameOrigin = new URL(source, media.baseUrl || globalThis.location?.origin || 'http://localhost/').origin
                    === new URL(media.baseUrl || globalThis.location?.origin || 'http://localhost/').origin;
            } catch {
                sameOrigin = false;
            }
            // Ein im HTML gefundener Fremd-URL darf nicht allein durch das
            // Oeffnen des Medienpanels einen externen Request ausloesen.
            const safePreview = item.allowed !== false
                && (/^data:image\/(?:png|jpe?g|gif|webp);base64,/i.test(source) || sameOrigin);
            button.innerHTML = `${safePreview ? `<img src="${source.replaceAll('&', '&amp;').replaceAll('"', '&quot;')}" alt="">` : '<span class="rt-lmz-media-item__placeholder">RT</span>'}<span><strong></strong><small></small></span>`;
            button.querySelector('strong').textContent = item.name;
            button.querySelector('small').textContent = item.allowed === false
                ? 'Nicht mehr im freigegebenen Dateibereich'
                : (item.protected ? 'Geschütztes Markenmedium' : (item.category || 'Dateibibliothek'));
            const selectable = tab === 'library' && replaceSession && !item.protected;
            button.disabled = !selectable;
            if (selectable) button.addEventListener('click', () => {
                try {
                    replaceSession.select(item.asset || item, true);
                    replaceSession = null;
                    onChanged?.();
                    refresh();
                    close({ restoreFocus: true });
                } catch (error) {
                    warning.hidden = false;
                    warning.textContent = error.message;
                }
            });
            list.appendChild(button);
        });
        if (!items.length) {
            const empty = document_.createElement('p');
            empty.className = 'rt-lmz-media-drawer__empty';
            empty.textContent = query ? 'Keine passenden Medien gefunden.' : (tab === 'used' ? 'In diesem Dokument wurden keine Medien erkannt.' : 'Keine Medien verfügbar.');
            list.appendChild(empty);
        }
        warning.hidden = state.warnings.length === 0;
        warning.textContent = state.warnings.map((item) => item.message).join(' ');
        summary.textContent = `${state.used.length} verwendet · ${mode === 'mail' ? currentTokenMedia().length + state.library.length : state.library.length} verfügbar`;
        hint.textContent = mode === 'mail'
            ? (replaceSession
                ? 'Wähle ein ausdrücklich für E-Mails freigegebenes Bild. Markenmedien und System-Tokens bleiben geschützt.'
                : 'Markenmedien und System-Tokens sind geschützt. Inhaltsbilder lassen sich nur aus dem freigegebenen Mail-Asset-Bestand ersetzen.')
            : (replaceSession ? 'Wähle ein Bild aus der freigegebenen Bibliothek.' : 'Zum Ersetzen zuerst ein bearbeitbares Bild im Motiv auswählen.');
    };

    const close = ({ restoreFocus = false } = {}) => {
        const focusTarget = returnFocus;
        replaceSession?.cancel?.();
        replaceSession = null;
        boundSelection = null;
        drawer.hidden = true;
        returnFocus = null;
        if (restoreFocus) focusTarget?.focus?.();
    };
    const onDrawerKeydown = (event) => {
        if (event.key !== 'Escape' || drawer.hidden) return;
        consumeEscape(event);
        close({ restoreFocus: true });
    };
    const onOutsidePointer = (event) => {
        if (drawer.hidden || drawer.contains(event.target)) return;
        close();
    };
    drawer.addEventListener('keydown', onDrawerKeydown);
    document_.addEventListener('pointerdown', onOutsidePointer, true);
    drawer.querySelector('[data-rt-lmz-media-close]').addEventListener('click', () => close({ restoreFocus: true }));
    drawer.querySelectorAll('[data-rt-lmz-media-tab]').forEach((button) => button.addEventListener('click', () => {
        tab = button.dataset.rtLmzMediaTab;
        drawer.querySelectorAll('[data-rt-lmz-media-tab]').forEach((item) => item.setAttribute('aria-selected', String(item === button)));
        refresh();
    }));
    filter.addEventListener('input', refresh);

    return {
        open({ replaceTarget = null, selectAsset = null, initialTab = 'used' } = {}) {
            returnFocus = document_.activeElement;
            tab = initialTab === 'library' ? 'library' : 'used';
            replaceSession?.cancel?.();
            replaceSession = null;
            boundSelection = null;
            if (replaceTarget && !canReplace(replaceTarget)) return false;
            if (replaceTarget) {
                replaceSession = createImageAssetSelection({
                    editor,
                    target: replaceTarget,
                    assets: currentAssets(),
                    mode,
                    baseUrl: media.baseUrl,
                    onSelected: onChanged,
                });
                const selected = editor.getSelected?.() || null;
                boundSelection = resolveEditableImageComponent(editor, selected, { mode }) === replaceSession.target
                    ? selected
                    : replaceTarget;
            } else if (typeof selectAsset === 'function' && mode === 'marketing') {
                replaceSession = createScopedAssetCallbackSelection({
                    assets: media.assets || [],
                    select: selectAsset,
                    baseUrl: media.baseUrl,
                    onSelected: onChanged,
                });
                boundSelection = editor.getSelected?.() || null;
            }
            drawer.querySelectorAll('[data-rt-lmz-media-tab]').forEach((item) => item.setAttribute('aria-selected', String(item.dataset.rtLmzMediaTab === tab)));
            drawer.hidden = false;
            refresh();
            drawer.querySelector(`[data-rt-lmz-media-tab="${tab}"]`)?.focus?.();
            return true;
        },
        canReplace,
        close,
        selectionChanged(selected, { deselected = false } = {}) {
            if (drawer.hidden || !replaceSession || !boundSelection) return false;
            if (!deselected && selected === boundSelection) return false;
            close();
            return true;
        },
        refresh,
        state: () => state,
        destroy() {
            replaceSession?.cancel?.();
            drawer.removeEventListener('keydown', onDrawerKeydown);
            document_.removeEventListener('pointerdown', onOutsidePointer, true);
            drawer.remove();
        },
    };
}

function createAnimationDrawer({ root, editor, capabilities, mode, onChanged }) {
    const document_ = root.ownerDocument;
    const viewport = root.querySelector('.lmz-builder__viewport') || root;
    const drawer = document_.createElement('section');
    drawer.className = 'rt-lmz-animation-drawer';
    drawer.hidden = true;
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'false');
    drawer.setAttribute('aria-label', 'Animation bearbeiten');
    drawer.innerHTML = `
        <header class="rt-lmz-animation-drawer__header">
            <div><strong>Animation</strong><small>Ausgewähltes Segment</small></div>
            <button type="button" data-rt-lmz-animation-close aria-label="Animation schliessen">&times;</button>
        </header>
        <form class="rt-lmz-animation-drawer__form" data-rt-lmz-animation-form>
            <fieldset data-rt-lmz-motion-fields>
            <label><span>Effekt</span><select name="motion">
                <option value="">Keine / Standard</option><option value="none">Deaktiviert</option>
                <option value="fade-up">Von unten einblenden</option><option value="fade-down">Von oben einblenden</option>
                <option value="fade-left">Von links einblenden</option><option value="fade-right">Von rechts einblenden</option>
                <option value="scale">Skalieren</option><option value="reveal">Reveal</option>
            </select></label>
            <div class="rt-lmz-animation-drawer__grid">
                <label><span>Dauer (s)</span><input name="duration" type="number" min="0.1" max="5" step="0.05"></label>
                <label><span>Verzögerung (s)</span><input name="delay" type="number" min="0" max="5" step="0.05"></label>
                <label><span>Distanz (px)</span><input name="distance" type="number" min="0" max="300" step="1"></label>
                <label><span>Start-Skalierung</span><input name="scale" type="number" min="0.5" max="1.5" step="0.01"></label>
            </div>
            <label><span>Bewegungskurve</span><select name="ease">
                <option value="">Standard</option><option value="power3.out">Power 3</option><option value="power2.out">Power 2</option>
                <option value="power4.out">Power 4</option><option value="power2.inOut">Power 2 In/Out</option>
                <option value="sine.inOut">Sine In/Out</option><option value="back.out(1.4)">Back</option><option value="none">Linear</option>
            </select></label>
            <label class="rt-lmz-animation-drawer__check"><input name="once" type="checkbox"><span>Nur einmal abspielen</span></label>
            </fieldset>
            <div class="rt-lmz-animation-drawer__gif" data-rt-lmz-animation-gif hidden>
                <strong>GIF-Vorschau</strong>
                <p>Frames und Geschwindigkeit gehören zur GIF-Datei. Die Vorschau kann hier verlustfrei neu gestartet werden.</p>
                <div class="rt-lmz-animation-drawer__gif-actions">
                    <button type="button" data-rt-lmz-gif-playback>Vorschau anhalten</button>
                    <button type="button" data-rt-lmz-gif-restart>GIF neu starten</button>
                </div>
            </div>
            <p class="rt-lmz-animation-drawer__message" data-rt-lmz-animation-message aria-live="polite"></p>
            <button type="submit" class="rt-lmz-animation-drawer__apply">Animation übernehmen</button>
        </form>`;
    viewport.appendChild(drawer);
    const form = drawer.querySelector('[data-rt-lmz-animation-form]');
    const gif = drawer.querySelector('[data-rt-lmz-animation-gif]');
    const motionFields = drawer.querySelector('[data-rt-lmz-motion-fields]');
    const applyButton = drawer.querySelector('.rt-lmz-animation-drawer__apply');
    const playbackButton = drawer.querySelector('[data-rt-lmz-gif-playback]');
    const message = drawer.querySelector('[data-rt-lmz-animation-message]');
    let component = null;
    let returnFocus = null;

    const control = (name) => form.querySelector(`[name="${name}"]`);
    const setControlValue = (name, value) => {
        const field = control(name);
        if (!field) return;
        if (String(field.tagName || '').toLowerCase() === 'select') {
            [...field.querySelectorAll('option')].forEach((option) => {
                option.selected = option.value === String(value);
            });
            return;
        }
        field.value = value;
    };
    const read = (name, fallback = '') => String(componentAttributes(component)[`data-lmz-${name}`] ?? fallback);
    const populate = () => {
        if (!component) return;
        setControlValue('motion', read('motion'));
        setControlValue('duration', read('duration', '0.9'));
        setControlValue('delay', read('delay', '0'));
        setControlValue('distance', read('distance', '48'));
        setControlValue('scale', read('scale', '0.92'));
        setControlValue('ease', read('ease'));
        control('once').checked = read('once') === 'true';
        const animatedTarget = resolveAnimatedComponent(component) || resolveEditableImageComponent(editor, component, { mode }) || component;
        const context = componentAnimationContext(animatedTarget);
        gif.hidden = !context.animated;
        playbackButton.textContent = animatedPreviewIsPlaying(animatedTarget) ? 'Vorschau anhalten' : 'Vorschau abspielen';
        motionFields.hidden = !capabilities.animation;
        applyButton.hidden = !capabilities.animation;
        message.textContent = '';
    };
    const close = ({ restoreFocus = false } = {}) => {
        const focusTarget = returnFocus;
        drawer.hidden = true;
        component = null;
        returnFocus = null;
        if (restoreFocus) focusTarget?.focus?.();
    };
    drawer.querySelector('[data-rt-lmz-animation-close]').addEventListener('click', () => close({ restoreFocus: true }));
    playbackButton.addEventListener('click', () => {
        const target = resolveAnimatedComponent(component) || resolveEditableImageComponent(editor, component, { mode }) || component;
        const playing = animatedPreviewIsPlaying(target);
        message.textContent = setAnimatedPreviewPlayback(target, !playing)
            ? (!playing ? 'GIF-Vorschau wird abgespielt.' : 'GIF-Vorschau wurde angehalten.')
            : 'Für dieses Segment wurde keine animierte Quelle erkannt.';
        playbackButton.textContent = animatedPreviewIsPlaying(target) ? 'Vorschau anhalten' : 'Vorschau abspielen';
    });
    drawer.querySelector('[data-rt-lmz-gif-restart]').addEventListener('click', () => {
        const target = resolveAnimatedComponent(component) || resolveEditableImageComponent(editor, component, { mode }) || component;
        message.textContent = restartAnimatedPreview(target) ? 'GIF-Vorschau wurde neu gestartet.' : 'Für dieses Segment wurde keine animierte Quelle erkannt.';
        playbackButton.textContent = animatedPreviewIsPlaying(target) ? 'Vorschau anhalten' : 'Vorschau abspielen';
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!component || !capabilities.animation) return;
        const applied = applyMotionSettings(component, {
            motion: control('motion').value,
            duration: control('duration').value,
            delay: control('delay').value,
            distance: control('distance').value,
            scale: control('scale').value,
            ease: control('ease').value,
            once: control('once').checked,
        });
        message.textContent = Object.keys(applied).length ? 'Animation übernommen.' : 'Die Eingaben liegen außerhalb der freigegebenen Werte.';
        onChanged?.();
    });
    const onDrawerKeydown = (event) => {
        if (event.key !== 'Escape' || drawer.hidden) return;
        consumeEscape(event);
        close({ restoreFocus: true });
    };
    const onOutsidePointer = (event) => {
        if (drawer.hidden || drawer.contains(event.target)) return;
        close();
    };
    drawer.addEventListener('keydown', onDrawerKeydown);
    document_.addEventListener('pointerdown', onOutsidePointer, true);

    return {
        open(target = null) {
            component = target || editor.getSelected?.();
            const context = componentAnimationContext(resolveAnimatedComponent(component) || resolveEditableImageComponent(editor, component, { mode }) || component);
            if (!component || (!capabilities.animation && !(capabilities.gifControls && context.animated))) return false;
            returnFocus = document_.activeElement;
            populate();
            drawer.hidden = false;
            (capabilities.animation ? control('motion') : playbackButton)?.focus?.();
            return true;
        },
        close,
        selectionChanged(selected, { deselected = false } = {}) {
            if (drawer.hidden || !component) return false;
            if (!deselected && selected === component) return false;
            close();
            return true;
        },
        destroy() {
            drawer.removeEventListener('keydown', onDrawerKeydown);
            document_.removeEventListener('pointerdown', onOutsidePointer, true);
            drawer.remove();
        },
    };
}

function addInlineEditToolbar(editor, root, menu) {
    const document_ = root.ownerDocument;
    let attachQueued = false;
    const attach = () => {
        canvasToolbars(editor, root).forEach((toolbar) => {
            if (toolbar.querySelector?.(`[data-command="${EDIT_COMMAND}"]`)) return;
            const button = document_.createElement('button');
            button.type = 'button';
            button.className = 'lmzbjs-toolbar-item rt-lmz-inline-edit-trigger';
            button.dataset.command = EDIT_COMMAND;
            button.title = 'Bearbeiten';
            button.setAttribute('aria-label', 'Bearbeiten');
            button.setAttribute('aria-haspopup', 'menu');
            button.innerHTML = '<span class="rt-lmz-inline-edit-icon" aria-hidden="true">&bull;&bull;&bull;</span>';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                menu.open(editor.getSelected?.());
            });
            toolbar.appendChild(button);
        });
    };
    const onSelected = () => {
        if (attachQueued) return;
        attachQueued = true;
        Promise.resolve().then(() => {
            attachQueued = false;
            attach();
        });
    };
    const MutationObserverClass = document_.defaultView?.MutationObserver || globalThis.MutationObserver;
    const toolbarObserver = typeof MutationObserverClass === 'function'
        ? new MutationObserverClass(onSelected)
        : null;
    toolbarObserver?.observe?.(root, { childList: true, subtree: true });
    editor.on?.('component:selected', onSelected);
    editor.on?.('canvas:frame:load', onSelected);
    attach();
    return () => {
        toolbarObserver?.disconnect?.();
        editor.off?.('component:selected', onSelected);
        editor.off?.('canvas:frame:load', onSelected);
        canvasToolbars(editor, root).forEach((toolbar) => {
            toolbar.querySelectorAll?.(`[data-command="${EDIT_COMMAND}"]`)?.forEach?.((button) => button.remove?.());
        });
    };
}

const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'iframe',
    '[contenteditable="true"]',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function isFocusableElement(element) {
    if (!element || element.hidden || element.disabled) return false;
    if (element.getAttribute?.('aria-hidden') === 'true') return false;
    if (element.closest?.('[hidden], [inert]')) return false;
    return element.tabIndex !== -1 || element.matches?.('a[href],button,input,select,textarea,iframe,[contenteditable="true"]');
}

function installCanvasTabBoundary(editor, root) {
    let frameDocument = null;
    const outerBoundary = (forward) => {
        const frameElement = editor?.Canvas?.getFrameEl?.() || frameDocument?.defaultView?.frameElement || null;
        const fullscreen = root.closest?.('[data-rt-fullscreen-modal]')
            || root.closest?.('[data-page-builder-fullscreen-root]')?.closest?.('[data-rt-fullscreen-modal]')
            || pageBuilderShellContext(root).workspace?.closest?.('[data-rt-fullscreen-modal]')
            || root;
        const outer = [...(fullscreen.querySelectorAll?.(FOCUSABLE_SELECTOR) || [])].filter(isFocusableElement);
        const frameIndex = frameElement ? outer.indexOf(frameElement) : -1;
        if (frameIndex >= 0) {
            const candidates = forward ? outer.slice(frameIndex + 1) : outer.slice(0, frameIndex).reverse();
            if (candidates.length) return candidates[0];
        }
        if (!forward) {
            const editorControls = [...(root.querySelectorAll?.(FOCUSABLE_SELECTOR) || [])]
                .filter((element) => isFocusableElement(element) && element !== frameElement);
            if (editorControls.length) return editorControls.at(-1);
        }
        return fullscreen.querySelector?.('[data-page-builder-assist]')
            || fullscreen.querySelector?.('[aria-label*="schliessen" i], [aria-label*="schließen" i]')
            || null;
    };
    const onFrameKeydown = (event) => {
        if (event.key !== 'Tab') return;
        const focusables = [...(frameDocument?.querySelectorAll?.(FOCUSABLE_SELECTOR) || [])].filter(isFocusableElement);
        if (!focusables.length) return;
        const index = focusables.findIndex((element) => element === event.target || element.contains?.(event.target));
        const exitsBackward = event.shiftKey && index <= 0;
        const exitsForward = !event.shiftKey && index === focusables.length - 1;
        if (!exitsBackward && !exitsForward) return;
        const target = outerBoundary(exitsForward);
        if (!target) return;
        event.preventDefault?.();
        event.stopPropagation?.();
        target.focus?.();
    };
    const bind = () => {
        frameDocument?.removeEventListener?.('keydown', onFrameKeydown, true);
        try {
            const candidate = editor?.Canvas?.getDocument?.() || null;
            const frameElement = editor?.Canvas?.getFrameEl?.() || candidate?.defaultView?.frameElement || null;
            frameDocument = candidate && candidate !== root.ownerDocument && frameElement ? candidate : null;
            frameDocument?.addEventListener?.('keydown', onFrameKeydown, true);
        } catch {
            frameDocument = null;
        }
    };

    editor?.on?.('canvas:frame:load', bind);
    bind();
    return () => {
        editor?.off?.('canvas:frame:load', bind);
        frameDocument?.removeEventListener?.('keydown', onFrameKeydown, true);
        frameDocument = null;
    };
}

function installStructureActionGuard(editor, root, { writable = true } = {}) {
    const blockedCommands = new Set(['tlb-move', 'tlb-clone', 'tlb-delete']);
    const selectionContentIsProtected = () => !writable || isProtectedEditorStructure(editor?.getSelected?.());
    const selectionStructureIsProtected = () => !writable || isProtectedEditorStructureTree(editor?.getSelected?.());
    const refresh = () => {
        const protectedSelection = selectionStructureIsProtected();
        const protectedContent = selectionContentIsProtected();
        const protectedValue = protectedSelection ? 'true' : 'false';
        if (root.dataset.rtLmzProtectedSelection !== protectedValue) {
            root.dataset.rtLmzProtectedSelection = protectedValue;
        }
        if (protectedContent) {
            root.querySelectorAll?.('[data-lmz-panel-group="right"][aria-expanded="true"]')?.forEach?.((button) => {
                button.dataset.rtLmzProtectedClosing = 'true';
                button.click?.();
                delete button.dataset.rtLmzProtectedClosing;
            });
        }
        canvasToolbars(editor, root).forEach((toolbar) => {
            toolbar.querySelectorAll?.('[data-command]')?.forEach?.((button) => {
                if (!blockedCommands.has(String(button.dataset?.command || ''))) return;
                if (button.hidden !== protectedSelection) button.hidden = protectedSelection;
                if (button.disabled !== protectedSelection) button.disabled = protectedSelection;
                if (button.getAttribute('aria-disabled') !== protectedValue) {
                    button.setAttribute('aria-disabled', protectedValue);
                }
            });
        });
    };
    let refreshQueued = false;
    const structureSelector = '.lmzbjs-toolbar, [data-command="tlb-move"], [data-command="tlb-clone"], [data-command="tlb-delete"]';
    const queueRefresh = (mutations = []) => {
        const relevant = !mutations.length || mutations.some((mutation) => [
            ...(mutation.addedNodes || []),
        ].some((node) => node.matches?.(structureSelector) || node.querySelector?.(structureSelector)));
        if (!relevant) return;
        if (refreshQueued) return;
        refreshQueued = true;
        Promise.resolve().then(() => {
            refreshQueued = false;
            refresh();
        });
    };
    const blockToolbarAction = (event) => {
        const action = event.target?.closest?.('[data-command], [data-lmz-panel-toggle]');
        const command = action?.dataset?.command;
        const panel = action?.dataset?.lmzPanelToggle;
        const protectedPanel = ['right:styles', 'right:traits'].includes(String(panel || ''))
            && action?.dataset?.rtLmzProtectedClosing !== 'true'
            && selectionContentIsProtected();
        const protectedCommand = blockedCommands.has(String(command || '')) && selectionStructureIsProtected();
        if (!protectedCommand && !protectedPanel) return;
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
    };
    const blockKeyboardRemoval = (event) => {
        const structuralShortcut = (event.ctrlKey || event.metaKey)
            && ['c', 'd', 'v', 'x'].includes(String(event.key || '').toLowerCase());
        if ((!['Delete', 'Backspace'].includes(event.key) && !structuralShortcut) || !selectionStructureIsProtected()) return;
        const editable = event.target?.closest?.('input,textarea,[contenteditable="true"]');
        if (editable) return;
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
    };
    let frameDocument = null;
    const bindFrame = () => {
        frameDocument?.removeEventListener?.('keydown', blockKeyboardRemoval, true);
        frameDocument = editor?.Canvas?.getDocument?.() || null;
        frameDocument?.addEventListener?.('keydown', blockKeyboardRemoval, true);
        refresh();
    };

    root.addEventListener('pointerdown', blockToolbarAction, true);
    root.addEventListener('click', blockToolbarAction, true);
    const MutationObserverClass = root.ownerDocument?.defaultView?.MutationObserver || globalThis.MutationObserver;
    const toolbarObserver = typeof MutationObserverClass === 'function'
        ? new MutationObserverClass(queueRefresh)
        : null;
    toolbarObserver?.observe?.(root, { childList: true, subtree: true });
    editor?.on?.('component:selected', refresh);
    editor?.on?.('component:deselected', refresh);
    editor?.on?.('canvas:frame:load', bindFrame);
    bindFrame();

    return () => {
        toolbarObserver?.disconnect?.();
        root.removeEventListener('pointerdown', blockToolbarAction, true);
        root.removeEventListener('click', blockToolbarAction, true);
        editor?.off?.('component:selected', refresh);
        editor?.off?.('component:deselected', refresh);
        editor?.off?.('canvas:frame:load', bindFrame);
        frameDocument?.removeEventListener?.('keydown', blockKeyboardRemoval, true);
        delete root.dataset.rtLmzProtectedSelection;
    };
}

function duplicateSelectedComponent(editor, component) {
    const parent = component?.parent?.();
    const clone = component?.clone?.();
    if (!parent || !clone) return false;
    const collection = parent.components?.();
    const models = collection?.models || [];
    const at = Math.max(0, models.indexOf(component)) + 1;
    const inserted = parent.append?.(clone, { at })?.[0] || clone;
    editor?.select?.(inserted);
    return true;
}

function deleteSelectedComponent(editor, component) {
    const parent = component?.parent?.();
    if (!component || !parent) return false;
    const siblings = parent.components?.()?.models || [];
    const index = siblings.indexOf(component);
    component.remove?.();
    editor?.select?.(siblings[Math.min(index, Math.max(0, siblings.length - 1))] || parent);
    return true;
}

function openSelectedContent(editor, component, root) {
    const type = String(component?.get?.('type') || '').toLowerCase();
    const tag = String(component?.get?.('tagName') || '').toLowerCase();
    const textLike = ['text', 'textnode', 'link'].includes(type)
        || ['p', 'span', 'strong', 'em', 'a', 'h1', 'h2', 'h3', 'h4', 'li'].includes(tag);
    if (textLike) {
        try {
            editor?.runCommand?.('core:component-enter', { component });
            component?.getEl?.()?.focus?.();
            return true;
        } catch {
            // Eigenschaften bleiben der sichere Fallback fuer Strukturteile.
        }
    }
    const opened = openPanel(root, 'right:traits');
    if (opened) panelToggle(root, 'right:traits')?.focus?.();
    return opened;
}

function createInlineMenu({ root, editor, capabilities, mode, mediaDrawer, animationDrawer }) {
    const document_ = root.ownerDocument;
    const menu = document_.createElement('div');
    menu.className = 'rt-lmz-inline-menu';
    menu.hidden = true;
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', 'Element bearbeiten');
    root.appendChild(menu);
    let component = null;
    let returnFocus = null;
    const groups = Object.freeze({
        assistant: { label: 'Assist', description: 'Kontextbezogene Hilfe' },
        edit: { label: 'Bearbeiten', description: 'Inhalt, Darstellung und Medien' },
        structure: { label: 'Struktur', description: 'Element anordnen oder verwalten' },
    });

    const actionDefinitions = () => {
        const image = resolveEditableImageComponent(editor, component, { mode });
        const protectedStructure = isProtectedEditorStructure(component);
        const protectedStructureTree = isProtectedEditorStructureTree(component);
        const selectedStylable = component?.get?.('stylable');
        const protectedStyleAllowed = protectedStructure
            && (selectedStylable === true
                || (Array.isArray(selectedStylable) && selectedStylable.length > 0));
        const animationTarget = resolveAnimatedComponent(component);
        const animation = componentAnimationContext(animationTarget || image || component);
        return [
            { id: 'assistant', label: 'Mit Assist bearbeiten', group: 'assistant', enabled: true },
            { id: 'content', label: 'Inhalt', group: 'edit', enabled: capabilities.writable && !protectedStructure },
            { id: 'traits', label: 'Eigenschaften', group: 'edit', panel: 'right:traits', enabled: capabilities.writable && capabilities.traits && !protectedStructure },
            { id: 'styles', label: 'Stile', group: 'edit', panel: 'right:styles', enabled: capabilities.writable && capabilities.styles && (!protectedStructure || protectedStyleAllowed) },
            { id: 'spacing', label: 'Abstände', group: 'edit', panel: 'right:styles', enabled: capabilities.writable && capabilities.spacing && !protectedStructure && !isFixedMailSignatureGeometry(component) },
            { id: 'media', label: 'Medien', group: 'edit', enabled: capabilities.media && (image || mode === 'mail') },
            { id: 'replace', label: 'Bild ersetzen', group: 'edit', enabled: Boolean(image) && mediaDrawer.canReplace?.(component) === true },
            {
                id: 'animation',
                label: animation.animated ? 'Animation & GIF' : 'Animation',
                group: 'edit',
                enabled: (!protectedStructure && capabilities.animation)
                    || (capabilities.gifControls && animation.animated),
            },
            {
                id: 'gif-playback',
                label: animatedPreviewIsPlaying(animationTarget || image || component) ? 'GIF-Vorschau anhalten' : 'GIF-Vorschau abspielen',
                group: 'edit',
                enabled: capabilities.gifControls && animation.animated,
            },
            { id: 'gif-restart', label: 'GIF-Vorschau neu starten', group: 'edit', enabled: capabilities.gifControls && animation.animated },
            { id: 'move', label: 'Umpositionieren', group: 'structure', enabled: capabilities.writable && !protectedStructureTree },
            { id: 'duplicate', label: 'Duplizieren', group: 'structure', enabled: capabilities.writable && !protectedStructureTree },
            { id: 'delete', label: 'Löschen', group: 'structure', enabled: capabilities.writable && !protectedStructureTree, danger: true },
        ].filter((item) => item.enabled !== false);
    };

    const close = ({ restoreFocus = false } = {}) => {
        const focusTarget = returnFocus;
        menu.hidden = true;
        component = null;
        returnFocus = null;
        if (restoreFocus) focusTarget?.focus?.();
    };
    const focusInlineTrigger = () => {
        const focus = () => {
            const trigger = visibleCanvasToolbar(editor, root)?.querySelector?.(`[data-command="${EDIT_COMMAND}"]`);
            trigger?.focus?.();
            return Boolean(trigger);
        };
        Promise.resolve().then(() => {
            if (!focus()) globalThis.setTimeout?.(focus, 0);
        });
    };
    const openAssistant = (selected) => {
        editor.select?.(selected);
        const window_ = document_.defaultView || globalThis.window;
        const EventConstructor = window_?.CustomEvent || globalThis.CustomEvent;
        if (typeof EventConstructor !== 'function') return false;
        window_?.dispatchEvent?.(new EventConstructor('railtime-pagebuilder-context-changed'));
        const readOnly = !capabilities.writable || isProtectedEditorStructure(selected);
        window_?.dispatchEvent?.(new EventConstructor('railtime-assistant-open', {
            detail: { source: 'page-builder', mode, read_only: readOnly },
        }));
        return true;
    };
    const runAction = (definition) => {
        const selected = component;
        if (!selected) return;

        if (definition.id === 'assistant') {
            close({ restoreFocus: true });
            openAssistant(selected);
            return;
        }
        if (definition.panel) {
            close({ restoreFocus: true });
            if (openPanel(root, definition.panel)) panelToggle(root, definition.panel)?.focus?.();
            return;
        }
        if (definition.id === 'content') {
            close({ restoreFocus: true });
            openSelectedContent(editor, selected, root);
            return;
        }
        if (definition.id === 'media' || definition.id === 'replace') {
            close({ restoreFocus: true });
            mediaDrawer.open(definition.id === 'replace'
                ? { replaceTarget: selected, initialTab: 'library' }
                : { initialTab: 'used' });
            return;
        }
        if (definition.id === 'animation') {
            close({ restoreFocus: true });
            animationDrawer.open(selected);
            return;
        }
        if (definition.id === 'gif-playback') {
            const target = resolveAnimatedComponent(selected) || resolveEditableImageComponent(editor, selected, { mode }) || selected;
            close({ restoreFocus: true });
            setAnimatedPreviewPlayback(target, !animatedPreviewIsPlaying(target));
            return;
        }
        if (definition.id === 'gif-restart') {
            close({ restoreFocus: true });
            restartAnimatedPreview(resolveAnimatedComponent(selected) || resolveEditableImageComponent(editor, selected, { mode }) || selected);
            return;
        }
        if (definition.id === 'move') {
            const moveHandle = visibleToolbarCommand(editor, root, 'tlb-move');
            if (moveHandle) {
                close();
                markMoveHandleReady(moveHandle);
            } else {
                close({ restoreFocus: true });
            }
            return;
        }
        if (definition.id === 'duplicate') {
            close({ restoreFocus: true });
            if (duplicateSelectedComponent(editor, selected)) focusInlineTrigger();
            return;
        }
        if (definition.id === 'delete') {
            close({ restoreFocus: true });
            if (deleteSelectedComponent(editor, selected)) focusInlineTrigger();
        }
    };
    const render = () => {
        menu.replaceChildren();
        const renderedGroups = new Map();
        actionDefinitions().forEach((definition) => {
            let group = renderedGroups.get(definition.group);
            if (!group) {
                const metadata = groups[definition.group] || groups.edit;
                group = document_.createElement('section');
                group.className = 'rt-lmz-inline-menu__group';
                group.dataset.rtLmzInlineGroup = definition.group;
                group.setAttribute('role', 'group');
                group.setAttribute('aria-label', metadata.label);
                const header = document_.createElement('header');
                header.className = 'rt-lmz-inline-menu__group-header';
                header.innerHTML = '<strong></strong><small></small>';
                header.querySelector('strong').textContent = metadata.label;
                header.querySelector('small').textContent = metadata.description;
                group.appendChild(header);
                menu.appendChild(group);
                renderedGroups.set(definition.group, group);
            }
            const button = document_.createElement('button');
            button.type = 'button';
            button.role = 'menuitem';
            button.dataset.rtLmzInlineAction = definition.id;
            if (definition.danger) button.dataset.tone = 'danger';
            button.setAttribute('aria-label', definition.label);
            button.innerHTML = `${inlineActionIcon(definition.id)}<span class="rt-lmz-inline-menu__action-label"></span>`;
            button.querySelector('.rt-lmz-inline-menu__action-label').textContent = definition.label;
            button.addEventListener('click', () => runAction(definition));
            group.appendChild(button);
        });
        if (componentAnimationContext(resolveAnimatedComponent(component) || resolveEditableImageComponent(editor, component, { mode }) || component).animated) {
            const note = document_.createElement('p');
            note.className = 'rt-lmz-inline-menu__note';
            note.textContent = 'Timing und Frames lassen sich nur durch den Austausch der GIF-Quelldatei ändern.';
            menu.appendChild(note);
        }
    };

    const onKeydown = (event) => {
        if (menu.hidden) return;
        const items = [...menu.querySelectorAll('[role="menuitem"]')];
        const index = items.indexOf(document_.activeElement);
        if (event.key === 'Escape') {
            consumeEscape(event);
            close({ restoreFocus: true });
            return;
        }
        if (event.key === 'ArrowDown') { event.preventDefault(); items[(index + 1 + items.length) % items.length]?.focus(); }
        if (event.key === 'ArrowUp') { event.preventDefault(); items[(index - 1 + items.length) % items.length]?.focus(); }
    };
    menu.addEventListener('keydown', onKeydown);
    const outside = (event) => {
        if (menu.hidden || menu.contains(event.target) || returnFocus?.contains?.(event.target)) return;
        close();
    };
    document_.addEventListener('pointerdown', outside, true);

    return {
        open(selected) {
            component = selected || editor.getSelected?.();
            if (!component) return;
            returnFocus = document_.activeElement;
            render();
            const toolbar = visibleCanvasToolbar(editor, root);
            menu.hidden = false;
            const previousVisibility = menu.style.visibility;
            menu.style.visibility = 'hidden';
            menu.style.left = '8px';
            menu.style.top = '8px';
            const canvas = root.getBoundingClientRect?.() || { left: 0, top: 0, width: 0, height: 0 };
            const anchor = toolbar?.getBoundingClientRect?.() || {
                left: number(canvas.left) + 12,
                top: number(canvas.top) + 12,
                bottom: number(canvas.top) + 54,
            };
            const menuRect = menu.getBoundingClientRect?.() || {};
            const menuWidth = Math.max(0, number(menuRect.width), number(menu.offsetWidth), number(menu.scrollWidth));
            const menuHeight = Math.max(0, number(menuRect.height), number(menu.offsetHeight), number(menu.scrollHeight));
            const canvasWidth = Math.max(0, number(canvas.width, number(canvas.right) - number(canvas.left)));
            const canvasHeight = Math.max(0, number(canvas.height, number(canvas.bottom) - number(canvas.top)));
            const inset = 8;
            const gap = 6;
            const maximumLeft = Math.max(inset, canvasWidth - menuWidth - inset);
            const maximumTop = Math.max(inset, canvasHeight - menuHeight - inset);
            const desiredLeft = number(anchor.left) - number(canvas.left);
            const below = number(anchor.bottom, number(anchor.top)) - number(canvas.top) + gap;
            const above = number(anchor.top, number(anchor.bottom)) - number(canvas.top) - menuHeight - gap;
            const desiredTop = menuHeight > 0 && below + menuHeight > canvasHeight - inset && above >= inset
                ? above
                : below;
            menu.style.left = `${clamp(desiredLeft, inset, maximumLeft)}px`;
            menu.style.top = `${clamp(desiredTop, inset, maximumTop)}px`;
            if (previousVisibility) menu.style.visibility = previousVisibility;
            else menu.style.removeProperty?.('visibility');
            menu.querySelector('[role="menuitem"]')?.focus?.();
        },
        close,
        selectionChanged(selected, { deselected = false } = {}) {
            if (menu.hidden || !component) return false;
            if (!deselected && selected === component) return false;
            close();
            return true;
        },
        destroy() {
            document_.removeEventListener('pointerdown', outside, true);
            menu.removeEventListener('keydown', onKeydown);
            menu.remove();
        },
    };
}

function assistantNonce(prefix = 'rt') {
    const bytes = new Uint8Array(18);
    try {
        const fillRandomValues = globalThis.crypto?.getRandomValues;
        if (typeof fillRandomValues !== 'function') throw new Error('Web Crypto ist nicht verfuegbar.');
        fillRandomValues.call(globalThis.crypto, bytes);
    } catch {
        bytes.forEach((_value, index) => { bytes[index] = Math.floor(Math.random() * 256); });
    }
    const encoded = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    return `${prefix}_${encoded}_${Date.now().toString(36)}`;
}

async function assistantFingerprint(value) {
    const source = new TextEncoder().encode(JSON.stringify(value));
    try {
        const digest = await globalThis.crypto?.subtle?.digest?.('SHA-256', source);
        if (digest) return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
    } catch {
        // Der Fallback ist nur ein Stale-Context-Marker, kein Sicherheitskey.
    }
    let hash = 2166136261;
    source.forEach((byte) => { hash = Math.imul(hash ^ byte, 16777619) >>> 0; });
    return Array.from({ length: 8 }, (_item, index) => ((hash ^ Math.imul(index + 1, 2654435761)) >>> 0)
        .toString(16).padStart(8, '0')).join('');
}

function componentTag(component) {
    return String(component?.get?.('tagName') || component?.getEl?.()?.tagName || '').toLowerCase();
}

function assistantSelectionProtected(component, mode) {
    const attributes = componentAttributes(component);
    const tag = componentTag(component);
    const block = String(attributes['data-rt-block'] || '');
    if (attributes['data-rt-brand-lockup'] || attributes['data-rt-qr-binding'] || ['logo-light', 'logo-dark', 'qr'].includes(block)) return true;
    if (mode !== 'mail') return false;
    const structural = new Set(['html', 'head', 'body', 'style', 'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th']);
    return structural.has(tag)
        || Boolean(attributes['data-rt-mail-preview-token'] || attributes['data-rt-mail-preview-train'])
        || String(component?.getEl?.()?.textContent || '').includes('{{');
}

function assetFileId(asset) {
    const direct = Number(asset?.file_id || asset?.fileId || asset?.id || asset?.get?.('file_id'));
    if (Number.isInteger(direct) && direct > 0) return direct;
    const match = String(assetSource(asset)).match(/\/marketing\/dateien\/(\d+)(?:[/?#]|$)/i);
    return match ? Number(match[1]) : null;
}

function availableEditorBlockIds(editor, mode, configured = []) {
    const prefix = mode === 'mail' ? 'rt-mail-' : 'rt-marketing-';
    const fromManager = editor?.BlockManager?.getAll?.()?.models || [];
    return [...new Set([
        ...(configured || []),
        ...fromManager.map((block) => String(block?.get?.('id') || block?.id || '')),
    ].filter((id) => String(id).startsWith(prefix)))];
}

function insertAssistantBlock(editor, blockId, position, selected) {
    const block = editor?.BlockManager?.get?.(blockId);
    const content = block?.get?.('content') ?? block?.attributes?.content;
    if (!content || !selected) return false;
    if (position === 'inside') {
        const inserted = selected.append?.(content);
        return Boolean(inserted?.length ?? inserted);
    }
    const parent = selected.parent?.();
    if (!parent) return false;
    const models = parent.components?.()?.models || [];
    const index = models.indexOf(selected);
    const at = position === 'before' ? Math.max(0, index) : Math.max(0, index) + 1;
    const inserted = parent.append?.(content, { at });
    return Boolean(inserted?.length ?? inserted);
}

export function createLmzAssistantAdapter({
    root,
    instance,
    chrome,
    mode = 'marketing',
    routeName,
    resourceId,
    formatOrKind = () => '',
    persistedHash = () => '',
    persistedVersion = () => 0,
    readOnly = false,
    assets = [],
    availableBlockIds = [],
    save = null,
    redesignDocument = null,
    fingerprint = assistantFingerprint,
} = {}) {
    const rootElement = asElement(root);
    const editor = instance?.editor;
    if (!rootElement || !editor) return null;
    const normalizedMode = mode === 'mail' ? 'mail' : 'marketing';
    const window_ = rootElement.ownerDocument?.defaultView || globalThis.window;
    const workspaceNonce = assistantNonce('workspace');
    let selectionNonce = assistantNonce('selection');
    let selectedReference = editor.getSelected?.() || null;
    let verifiedSelection = null;
    let clientRevision = 0;
    let destroyed = false;
    const capabilities = [
        'open_fullscreen', 'open_panel', 'focus_selection', 'edit_text', 'set_style',
        'add_block', 'undo', 'redo', 'preview', 'save', 'gif_preview',
        ...(normalizedMode === 'marketing' ? [
            'replace_image',
            'animation',
            ...(typeof redesignDocument === 'function' ? ['redesign_document'] : []),
        ] : []),
    ];
    const createWindowEvent = (name, detail) => {
        const EventConstructor = window_?.CustomEvent || globalThis.CustomEvent;
        return typeof EventConstructor === 'function'
            ? new EventConstructor(name, detail === undefined ? undefined : { detail })
            : null;
    };
    const dispatchWindowEvent = (name, detail) => {
        const event = createWindowEvent(name, detail);
        if (event) window_?.dispatchEvent?.(event);
    };
    const notifyContext = () => dispatchWindowEvent('railtime-pagebuilder-context-changed');
    const onUpdate = () => {
        verifiedSelection = null;
        clientRevision += 1;
        notifyContext();
    };
    const onSelected = (component) => {
        if (component !== selectedReference) {
            selectedReference = component || editor.getSelected?.() || null;
            selectionNonce = assistantNonce('selection');
        }
        verifiedSelection = null;
        notifyContext();
    };
    editor.on?.('update', onUpdate);
    editor.on?.('component:selected', onSelected);
    editor.on?.('component:deselected', onSelected);

    const currentSelection = async () => {
        for (let attempt = 0; attempt < 3; attempt += 1) {
            const component = editor.getSelected?.();
            if (!component) {
                verifiedSelection = null;
                return null;
            }
            const nonce = selectionNonce;
            const revision = clientRevision;
            const attributes = componentAttributes(component);
            const style = component?.getStyle?.() || component?.get?.('style') || {};
            const text = String(component?.getEl?.()?.textContent || component?.get?.('content') || '').trim().slice(0, 600);
            const source = String(component?.get?.('src') || attributes.src || '');
            const asset = (assets || []).find((candidate) => canonicalMediaSource(assetSource(candidate), window_?.location?.origin)
                === canonicalMediaSource(source, window_?.location?.origin));
            const protectedSelection = assistantSelectionProtected(component, normalizedMode);
            const animated = resolveAnimatedComponent(component) || (componentAnimationContext(component).animated ? component : null);
            const selectionFingerprint = await fingerprint({
                cid: component.cid || component?.get?.('id') || '',
                tag: componentTag(component), attributes, style, text, source,
            });

            if (editor.getSelected?.() !== component || selectionNonce !== nonce || clientRevision !== revision) continue;

            verifiedSelection = { component, nonce, revision, fingerprint: selectionFingerprint };
            return {
                selection_nonce: nonce,
                fingerprint: selectionFingerprint,
                tag: componentTag(component),
                block_id: String(attributes['data-rt-block'] || attributes.id || component?.get?.('name') || '').slice(0, 80),
                text,
                styles: style,
                image_file_id: normalizedMode === 'marketing' ? assetFileId(asset) : null,
                protected: protectedSelection,
                motion_allowed: normalizedMode === 'marketing' && !protectedSelection,
                gif: Boolean(animated),
            };
        }

        verifiedSelection = null;
        return null;
    };

    const verifiedComponent = () => {
        const component = editor.getSelected?.();
        if (!verifiedSelection
            || component !== verifiedSelection.component
            || selectionNonce !== verifiedSelection.nonce
            || clientRevision !== verifiedSelection.revision) {
            return null;
        }

        return component;
    };

    const adapter = {
        async getContext() {
            const mediaState = chrome?.mediaState?.() || { warnings: [] };
            const issues = (mediaState.warnings || []).map((warning) => String(warning.message || '')).filter(Boolean).slice(0, 8);
            return {
                version: 1,
                route_name: routeName,
                mode: normalizedMode,
                resource_id: resourceId,
                format_or_kind: String(formatOrKind()),
                workspace_nonce: workspaceNonce,
                fullscreen_open: pageBuilderWorkspaceIsActive(rootElement),
                editor_ready: !destroyed,
                read_only: Boolean(readOnly),
                persisted_content_hash: String(persistedHash()),
                persisted_version: Number(persistedVersion()),
                client_revision: clientRevision,
                unsaved: Boolean(instance.hasUnsavedChanges?.()),
                selection: await currentSelection(),
                capabilities,
                available_block_ids: availableEditorBlockIds(editor, normalizedMode, availableBlockIds),
                validation: { state: issues.length ? 'warning' : 'valid', issues },
            };
        },
        openFullscreen() {
            const { shell } = pageBuilderShellContext(rootElement);
            const trigger = shell?.querySelector?.('[data-page-builder-open], [data-page-builder-closed-preview] button');
            trigger?.click?.();
            return Boolean(trigger);
        },
        focusSelection() {
            const component = verifiedComponent();
            if (!component) return false;
            editor.select?.(component);
            component.getEl?.()?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
            return true;
        },
        openPanel(panel) { return chrome?.openPanel?.(panel) || false; },
        editText(text) {
            const component = verifiedComponent();
            if (!component || assistantSelectionProtected(component, normalizedMode)) return false;
            if (String(component?.get?.('type') || '').toLowerCase() === 'textnode') component.set?.('content', text);
            else component.components?.([{ type: 'textnode', content: String(text) }]);
            return true;
        },
        setStyle(property, value) {
            const component = verifiedComponent();
            if (!component || assistantSelectionProtected(component, normalizedMode)) return false;
            component.addStyle?.({ [property]: value });
            return true;
        },
        replaceImageByFileId(fileId) {
            if (normalizedMode !== 'marketing') return false;
            const asset = (assets || []).find((candidate) => assetFileId(candidate) === Number(fileId));
            if (!asset) return false;
            const target = verifiedComponent();
            if (!target) return false;
            const selection = createImageAssetSelection({ editor, assets, target, mode: 'marketing', baseUrl: window_?.location?.origin });
            selection.select(asset, true);
            return true;
        },
        addBlock(blockId, position) {
            if (!availableEditorBlockIds(editor, normalizedMode, availableBlockIds).includes(blockId)) return false;
            const component = verifiedComponent();
            return component ? insertAssistantBlock(editor, blockId, position, component) : false;
        },
        undo() { editor.runCommand?.('core:undo'); return true; },
        redo() { editor.runCommand?.('core:redo'); return true; },
        setPreview(state) {
            const active = Boolean(editor.Commands?.isActive?.('core:preview'));
            const enable = state === 'on' || (state === 'toggle' && !active);
            if (enable && !active) editor.runCommand?.('core:preview');
            if (!enable && active) editor.stopCommand?.('core:preview');
            return true;
        },
        restartGifPreview() { return chrome?.restartGif?.(verifiedComponent()) || false; },
        setAnimation(field, value) {
            if (normalizedMode !== 'marketing') return false;
            const component = verifiedComponent();
            if (!component || assistantSelectionProtected(component, normalizedMode)) return false;
            const sanitized = sanitizeMotionSettings({ [field]: value });
            if (!Object.prototype.hasOwnProperty.call(sanitized, field)) return false;
            applyMotionSettings(component, { [field]: value });
            return true;
        },
        async redesignDocument(preset) {
            if (normalizedMode !== 'marketing'
                || typeof redesignDocument !== 'function'
                || preset !== 'railtime_modern') return false;
            return redesignDocument(preset);
        },
        async save() { return typeof save === 'function' ? Boolean(await save()) : Boolean(await instance.save?.('manual')); },
        destroy({ keepRegistered = false } = {}) {
            if (!destroyed) {
                destroyed = true;
                editor.off?.('update', onUpdate);
                editor.off?.('component:selected', onSelected);
                editor.off?.('component:deselected', onSelected);
            }
            if (!keepRegistered) {
                dispatchWindowEvent('railtime-pagebuilder-adapter-unregister', { adapter });
            }
        },
    };
    dispatchWindowEvent('railtime-pagebuilder-adapter-register', { adapter });
    return adapter;
}

export function createLmzEditorChrome({
    instance,
    root,
    mode = 'marketing',
    layout = 'default',
    capabilities = {},
    media = {},
    active = true,
} = {}) {
    const rootElement = asElement(root);
    const editor = instance?.editor;
    if (!rootElement || !editor) throw new TypeError('Der gemeinsame LMZ-Adapter benötigt Root und Editorinstanz.');
    rootElement.__rtLmzEditorChrome?.destroy?.();
    const profile = resolveLmzEditorMode(mode);
    const normalizedMode = profile.id;
    const normalizedLayout = layout === 'elementor' ? 'elementor' : 'default';
    const normalized = normalizeLmzCapabilities(profile, capabilities);
    const abortController = new AbortController();
    let isOpen = Boolean(active);
    let destroyed = false;
    rootElement.classList.add('rt-lmz-editor');
    rootElement.dataset.rtLmzMode = normalizedMode;
    rootElement.dataset.rtLmzModeLabel = profile.label;
    rootElement.dataset.rtLmzContentModel = profile.contentModel;
    rootElement.dataset.rtLmzStyleStrategy = profile.styleStrategy;
    rootElement.dataset.rtLmzOpen = isOpen ? 'true' : 'false';
    rootElement.dataset.rtLmzReadOnly = normalized.writable ? 'false' : 'true';
    const readOnlyMounts = !normalized.writable
        ? [...rootElement.querySelectorAll('[data-lmz-mount="blocks"], [data-lmz-mount="layers"], [data-lmz-mount="styles"], [data-lmz-mount="traits"], [data-lmz-mount="classes"]')]
        : [];
    readOnlyMounts.forEach((mount) => {
        mount.inert = true;
        mount.setAttribute('aria-disabled', 'true');
    });
    const capabilityByPanel = Object.freeze({
        'left:blocks': 'blocks',
        'left:layers': 'layers',
        'right:styles': 'styles',
        'right:traits': 'traits',
        'right:classes': 'classes',
    });
    const unavailableCapabilityElements = [];
    const disableCapabilityElement = (element, capability, { hide = false } = {}) => {
        if (!element || unavailableCapabilityElements.some((entry) => entry.element === element)) return;
        unavailableCapabilityElements.push({
            element,
            hidden: element.hidden,
            inert: Boolean(element.inert),
            ariaDisabled: element.getAttribute('aria-disabled'),
        });
        if (hide) element.hidden = true;
        element.inert = true;
        element.setAttribute('aria-disabled', 'true');
        element.dataset.rtLmzCapability = capability;
    };
    Object.entries(capabilityByPanel).forEach(([panelId, capability]) => {
        if (normalized[capability]) return;
        const toggle = rootElement.querySelector(`[data-lmz-panel-toggle="${panelId}"]`);
        const panel = rootElement.querySelector(`[data-lmz-popover-panel="${panelId}"]`);
        disableCapabilityElement(toggle, capability, { hide: true });
        disableCapabilityElement(panel, capability, { hide: true });
        disableCapabilityElement(panel?.querySelector?.('[data-lmz-mount]'), capability);
    });
    enforceProtectedComponentModels(editor, { readOnly: !normalized.writable });

    // Vendorpfade, die in Laravel absichtlich nicht existieren, verschwinden
    // vollstaendig. Der Medienbutton bleibt erhalten und wird umgeleitet.
    rootElement.querySelectorAll('[data-lmz-action="upload"], [data-lmz-upload-input]').forEach((element) => element.remove());
    if (!normalized.animation) {
        rootElement.querySelectorAll('[data-lmz-action^="motion-"], .lmz-builder__motion-actions').forEach((element) => element.remove());
    }
    const labels = { undo: 'Rückgängig', redo: 'Wiederholen', preview: 'Vorschau', assets: 'Medien', save: 'Speichern' };
    Object.entries(labels).forEach(([action, label]) => addActionLabel(rootElement.querySelector(`[data-lmz-action="${action}"]`), label));
    const topbar = rootElement.querySelector('.lmz-builder__topbar');
    topbar?.setAttribute('data-rt-lmz-toolbar', 'true');
    const modeIndicator = rootElement.ownerDocument.createElement('div');
    modeIndicator.className = 'rt-lmz-mode-indicator';
    modeIndicator.dataset.rtLmzModeIndicator = normalizedMode;
    modeIndicator.setAttribute('role', 'status');
    modeIndicator.setAttribute('aria-label', `Aktiver Editormodus: ${profile.label}. ${profile.description}`);
    const modeEyebrow = rootElement.ownerDocument.createElement('span');
    modeEyebrow.className = 'rt-lmz-mode-indicator__eyebrow';
    modeEyebrow.textContent = 'Modus';
    const modeName = rootElement.ownerDocument.createElement('strong');
    modeName.textContent = profile.label;
    const modeDescription = rootElement.ownerDocument.createElement('small');
    modeDescription.textContent = profile.description;
    modeIndicator.append(modeEyebrow, modeName, modeDescription);
    topbar?.insertBefore(modeIndicator, topbar.querySelector('.lmz-builder__panel-actions--left'));
    const elementorLayout = normalizedLayout === 'elementor'
        ? installElementorEditorLayout({ root: rootElement, modeIndicator })
        : null;
    rootElement.querySelectorAll('[data-lmz-panel-toggle]').forEach((button) => button.classList.add('rt-lmz-drawer-trigger'));
    rootElement.querySelectorAll('[data-lmz-popover]').forEach((drawer) => drawer.setAttribute('data-rt-lmz-drawer', drawer.dataset.lmzPopover || ''));
    const onVendorPopoverEscape = (event) => {
        if (event.key !== 'Escape') return;
        const popover = openVendorPopover(rootElement, event.target);
        if (!popover) return;
        consumeEscape(event);
        closeVendorPopover(rootElement, popover);
    };
    rootElement.addEventListener('keydown', onVendorPopoverEscape, { capture: true, signal: abortController.signal });
    let intentionalRightPanel = null;
    let panelReconcileFrame = null;
    let pointerPanel = null;
    const rememberPanelIntent = (event) => {
        const toggle = event.target?.closest?.('[data-lmz-panel-group="right"][data-lmz-panel-toggle]');
        if (!toggle) return;
        const panel = toggle.dataset.lmzPanelToggle || null;
        if (toggle.dataset.rtLmzAutoClosing === 'true') {
            intentionalRightPanel = null;
            return;
        }
        if (toggle.dataset.rtLmzExplicitOpen) {
            intentionalRightPanel = toggle.dataset.rtLmzExplicitOpen;
            return;
        }
        if (event.type === 'pointerdown') {
            pointerPanel = panel;
            intentionalRightPanel = toggle.getAttribute('aria-expanded') === 'true' ? null : panel;
            return;
        }
        if (pointerPanel === panel) {
            pointerPanel = null;
            return;
        }
        intentionalRightPanel = toggle.getAttribute('aria-expanded') === 'true' ? null : panel;
    };
    rootElement.addEventListener('pointerdown', rememberPanelIntent, { capture: true, signal: abortController.signal });
    rootElement.addEventListener('click', rememberPanelIntent, { capture: true, signal: abortController.signal });

    const spacing = createSpacingOverlayController({ editor, root: rootElement, enabled: isOpen && normalized.spacing });
    let mediaDrawer;
    let animationDrawer;
    let imagePropertiesPanel;
    let lastContextSelection = null;
    const contextualElementState = new Map();
    const traitCount = (component) => {
        const traits = component?.get?.('traits');
        if (Array.isArray(traits)) return traits.length;
        if (Array.isArray(traits?.models)) return traits.models.length;
        if (typeof traits?.length === 'number') return traits.length;
        return 0;
    };
    const canStyleSelection = (component) => {
        if (!component) return false;
        const stylable = component.get?.('stylable');
        if (stylable === false || (Array.isArray(stylable) && stylable.length === 0)) return false;
        if (!isProtectedEditorStructure(component)) return true;
        return stylable === true || (Array.isArray(stylable) && stylable.length > 0);
    };
    const setContextPanelAvailable = (panelId, available) => {
        const toggle = rootElement.querySelector(`[data-lmz-panel-toggle="${panelId}"]`);
        if (!toggle || !normalized[capabilityByPanel[panelId]]) return;
        if (!contextualElementState.has(toggle)) {
            contextualElementState.set(toggle, {
                hidden: toggle.hidden,
                inert: Boolean(toggle.inert),
                ariaDisabled: toggle.getAttribute('aria-disabled'),
            });
        }
        if (!available && toggle.getAttribute('aria-expanded') === 'true') {
            closeVendorPopover(rootElement, rootElement.querySelector('[data-lmz-popover="right"]'));
        }
        toggle.hidden = !available;
        toggle.inert = !available;
        toggle.toggleAttribute('data-rt-lmz-context-unavailable', !available);
        toggle.setAttribute('aria-disabled', available ? 'false' : 'true');
    };
    const syncContextControls = () => {
        const selected = editor.getSelected?.() || null;
        const hasImage = imagePropertiesPanel?.refresh(selected) || false;
        const protectedSelection = isProtectedEditorStructure(selected);
        const traitsAvailable = Boolean(
            selected
            && normalized.writable
            && normalized.traits
            && (hasImage || (!protectedSelection && traitCount(selected) > 0))
        );
        const stylesAvailable = Boolean(
            selected
            && normalized.writable
            && normalized.styles
            && canStyleSelection(selected)
        );
        const classesAvailable = Boolean(
            selected
            && normalized.writable
            && normalized.classes
            && !protectedSelection
        );

        rootElement.dataset.rtLmzHasSelection = selected ? 'true' : 'false';
        rootElement.dataset.rtLmzHasContextActions = traitsAvailable || stylesAvailable || classesAvailable ? 'true' : 'false';
        setContextPanelAvailable('right:traits', traitsAvailable);
        setContextPanelAvailable('right:styles', stylesAvailable);
        setContextPanelAvailable('right:classes', classesAvailable);
        if (elementorLayout && selected !== lastContextSelection) {
            lastContextSelection = selected;
            if (selected) elementorLayout.openContextPanel();
        }
        elementorLayout?.syncTabs();
    };
    const refreshAll = () => {
        spacing.refresh();
        mediaDrawer?.refresh();
        syncContextControls();
    };
    mediaDrawer = createMediaDrawer({ root: rootElement, editor, mode: normalizedMode, media, capabilities: normalized, onChanged: refreshAll });
    imagePropertiesPanel = createImagePropertiesPanel({
        root: rootElement,
        editor,
        capabilities: normalized,
        media,
        onChanged: refreshAll,
    });
    const detachScopedAssetAccess = installScopedAssetAccess({ editor, mediaDrawer, mode: normalizedMode });
    animationDrawer = createAnimationDrawer({ root: rootElement, editor, capabilities: normalized, mode: normalizedMode, onChanged: refreshAll });
    const menu = createInlineMenu({ root: rootElement, editor, capabilities: normalized, mode: normalizedMode, mediaDrawer, animationDrawer });
    const detachToolbar = addInlineEditToolbar(editor, rootElement, menu);
    const detachCanvasTabBoundary = installCanvasTabBoundary(editor, rootElement);
    const detachStructureActionGuard = installStructureActionGuard(editor, rootElement, normalized);

    const mediaButton = rootElement.querySelector('[data-lmz-action="assets"]');
    const openMedia = (event) => {
        event?.preventDefault?.();
        event?.stopImmediatePropagation?.();
        mediaDrawer.open({ initialTab: 'used' });
    };
    mediaButton?.addEventListener('click', openMedia, { capture: true, signal: abortController.signal });

    // LMZ 2.4.5 oeffnet bei jeder Auswahl ungefragt den Stil-Popover. Der
    // gemeinsame Editor oeffnet Panels ausschliesslich durch Benutzerwahl.
    closeAutomaticallyOpenedStyles(rootElement, intentionalRightPanel);
    const onSelected = (selected = editor.getSelected?.()) => {
        menu.selectionChanged(selected);
        mediaDrawer.selectionChanged(selected);
        animationDrawer.selectionChanged(selected);
        const reconcile = () => {
            closeAutomaticallyOpenedStyles(rootElement, intentionalRightPanel);
            refreshAll();
        };
        globalThis.queueMicrotask?.(reconcile);
        if (typeof globalThis.requestAnimationFrame === 'function') {
            if (panelReconcileFrame !== null) globalThis.cancelAnimationFrame?.(panelReconcileFrame);
            panelReconcileFrame = globalThis.requestAnimationFrame(() => {
                panelReconcileFrame = null;
                reconcile();
            });
        }
    };
    const onDeselected = () => {
        menu.selectionChanged(null, { deselected: true });
        mediaDrawer.selectionChanged(null, { deselected: true });
        animationDrawer.selectionChanged(null, { deselected: true });
        onSelected(null);
    };
    editor.on?.('component:selected', onSelected);
    editor.on?.('component:deselected', onDeselected);
    editor.on?.('component:update', refreshAll);
    const onComponentAdded = (component) => {
        if (!normalized.writable || isProtectedEditorStructureTree(component)) {
            enforceProtectedComponentModels({
                getWrapper: () => component,
                getSelected: () => component,
            }, { readOnly: !normalized.writable });
        }
    };
    const onLoad = () => {
        enforceProtectedComponentModels(editor, { readOnly: !normalized.writable });
        syncContextControls();
    };
    editor.on?.('component:add', onComponentAdded);
    editor.on?.('load', onLoad);
    syncContextControls();

    const api = {
        editor,
        mode: profile,
        layout: normalizedLayout,
        capabilities: normalized,
        open(detail = {}) {
            if (destroyed) return false;
            isOpen = true;
            rootElement.dataset.rtLmzOpen = 'true';
            spacing.setEnabled(normalized.spacing);
            refreshAll();
            dispatch(rootElement, LMZ_EDITOR_EVENTS.opened, { mode: normalizedMode, ...detail });
            return true;
        },
        close(detail = {}) {
            if (destroyed) return false;
            isOpen = false;
            rootElement.dataset.rtLmzOpen = 'false';
            spacing.setEnabled(false);
            mediaDrawer.close();
            animationDrawer.close();
            menu.close();
            dispatch(rootElement, LMZ_EDITOR_EVENTS.closed, { mode: normalizedMode, ...detail });
            return true;
        },
        refresh: refreshAll,
        openMedia(options = {}) { mediaDrawer.open(options); },
        openAnimation(target = null) { return animationDrawer.open(target || editor.getSelected?.()); },
        openPanel(panel) {
            if (panel === 'media' || panel === 'assets') {
                mediaDrawer.open({ initialTab: 'used' });
                return true;
            }
            if (panel === 'animation') return animationDrawer.open(editor.getSelected?.());
            const mapping = {
                blocks: 'left:blocks', layers: 'left:layers', styles: 'right:styles',
                traits: 'right:traits', properties: 'right:traits', spacing: 'right:styles',
                classes: 'right:classes',
            };
            const panelId = mapping[panel];
            const capability = capabilityByPanel[panelId];
            if (!panelId || (capability && !normalized[capability])) return false;
            const toggle = rootElement.querySelector(`[data-lmz-panel-toggle="${panelId}"]`);
            if (!toggle || toggle.hidden || toggle.inert || toggle.getAttribute('aria-disabled') === 'true') return false;
            return Boolean(openPanel(rootElement, panelId));
        },
        restartGif(target = null) {
            return restartAnimatedPreview(resolveAnimatedComponent(target || editor.getSelected?.()) || target || editor.getSelected?.());
        },
        mediaState() { return mediaDrawer.state(); },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            abortController.abort();
            if (panelReconcileFrame !== null) globalThis.cancelAnimationFrame?.(panelReconcileFrame);
            panelReconcileFrame = null;
            editor.off?.('component:selected', onSelected);
            editor.off?.('component:deselected', onDeselected);
            editor.off?.('component:update', refreshAll);
            editor.off?.('component:add', onComponentAdded);
            editor.off?.('load', onLoad);
            detachScopedAssetAccess();
            detachStructureActionGuard();
            detachCanvasTabBoundary();
            detachToolbar();
            menu.destroy();
            mediaDrawer.destroy();
            animationDrawer.destroy();
            imagePropertiesPanel.destroy();
            spacing.destroy();
            elementorLayout?.destroy();
            rootElement.classList.remove('rt-lmz-editor');
            delete rootElement.dataset.rtLmzMode;
            delete rootElement.dataset.rtLmzModeLabel;
            delete rootElement.dataset.rtLmzContentModel;
            delete rootElement.dataset.rtLmzStyleStrategy;
            delete rootElement.dataset.rtLmzOpen;
            delete rootElement.dataset.rtLmzReadOnly;
            delete rootElement.dataset.rtLmzHasSelection;
            delete rootElement.dataset.rtLmzHasContextActions;
            modeIndicator.remove();
            unavailableCapabilityElements.forEach(({ element, hidden, inert, ariaDisabled }) => {
                element.hidden = hidden;
                element.inert = inert;
                delete element.dataset.rtLmzCapability;
                if (ariaDisabled === null) element.removeAttribute('aria-disabled');
                else element.setAttribute('aria-disabled', ariaDisabled);
            });
            contextualElementState.forEach(({ hidden, inert, ariaDisabled }, element) => {
                element.hidden = hidden;
                element.inert = inert;
                element.removeAttribute('data-rt-lmz-context-unavailable');
                if (ariaDisabled === null) element.removeAttribute('aria-disabled');
                else element.setAttribute('aria-disabled', ariaDisabled);
            });
            contextualElementState.clear();
            readOnlyMounts.forEach((mount) => {
                mount.inert = false;
                mount.removeAttribute('aria-disabled');
            });
            if (rootElement.__rtLmzEditorChrome === api) delete rootElement.__rtLmzEditorChrome;
            dispatch(rootElement, LMZ_EDITOR_EVENTS.destroyed, { mode: normalizedMode });
        },
    };

    rootElement.addEventListener(LMZ_EDITOR_EVENTS.requestOpen, (event) => api.open(event.detail), { signal: abortController.signal });
    rootElement.addEventListener(LMZ_EDITOR_EVENTS.requestClose, (event) => api.close(event.detail), { signal: abortController.signal });
    rootElement.addEventListener(LMZ_EDITOR_EVENTS.requestRefresh, refreshAll, { signal: abortController.signal });
    rootElement.__rtLmzEditorChrome = api;
    dispatch(rootElement, LMZ_EDITOR_EVENTS.ready, { mode: normalizedMode, profile, capabilities: normalized });
    return api;
}
