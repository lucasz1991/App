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
    const shell = asElement(root)?.closest?.('[data-page-builder-shell]') || null;
    const workspace = asElement(root)?.closest?.('[data-page-builder-workspace]') || null;
    const shellId = shell?.querySelector?.('[data-page-builder-fullscreen-root]')?.dataset?.pageBuilderShellId || '';
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
    const normalizedMode = mode === 'mail' ? 'mail' : 'marketing';
    const merged = {
        ...DEFAULT_LMZ_CAPABILITIES,
        mediaInsert: normalizedMode === 'marketing',
        imageReplace: normalizedMode === 'marketing',
        animation: normalizedMode === 'marketing',
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
        merged.mediaInsert = false;
        merged.animation = false;
        merged.imageReplace = capabilities.imageReplace === 'tokens-only'
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

        return { used, library: [], warnings };
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

function componentOrAncestorHas(component, predicate) {
    let current = component;
    let guard = 0;
    while (current && guard < 30) {
        if (predicate(componentAttributes(current), current)) return true;
        current = typeof current.parent === 'function' ? current.parent() : null;
        guard += 1;
    }
    return false;
}

export function isProtectedEditorImage(component, mode = 'marketing') {
    if (!component) return true;
    if (mode === 'mail') return true;

    return isProtectedEditorStructure(component);
}

export function isProtectedEditorStructure(component) {
    if (!component) return false;

    return componentOrAncestorHas(component, (attributes) => {
        const block = String(attributes['data-rt-block'] || '');
        return Boolean(
            attributes['data-rt-mail-preview-token']
            || attributes['data-rt-mail-preview-train']
            || attributes['data-rt-qr-binding']
            || attributes['data-rt-brand-lockup']
            || ['logo-light', 'logo-dark', 'qr'].includes(block),
        );
    });
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
    const candidate = selected || editor?.getSelected?.();
    if (!candidate) return null;
    const found = [];
    const visit = (component) => {
        if (isImageComponent(component)) found.push(component);
        componentChildren(component).forEach(visit);
    };
    visit(candidate);
    if (found.length !== 1 || isProtectedEditorImage(found[0], mode)) return null;
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
    const allowed = new Map((assets || []).map((asset) => {
        const source = assetSource(asset);
        return [canonicalMediaSource(source, baseUrl), asset];
    }));
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
        mediaDrawer.open(image
            ? { replaceTarget: image, initialTab: 'library' }
            : { initialTab: 'used' });

        return true;
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
        animated: token === 'TRAIN_SRC'
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
    const backgroundImage = String(
        current.backgroundImage
        || ((!renderedBackground || renderedBackground === 'none') ? '' : renderedBackground),
    );
    const backgroundPriority = String(
        element.style?.getPropertyPriority?.('background-image')
        || current.backgroundPriority
        || '',
    );
    const renderedSource = isImage
        ? String(element.getAttribute?.('src') || element.src || '')
        : (extractCssMediaSources(backgroundImage)[0] || '');
    const source = renderedSource || current.source || componentAnimationContext(component).source;
    if (!source) return false;

    if (!playing) {
        animatedPreviewState.set(element, {
            playing: false,
            source,
            isImage,
            backgroundImage,
            backgroundPriority,
        });
        element.dataset.rtLmzAnimationPaused = 'true';
        if (isImage) element.removeAttribute?.('src');
        else element.style?.setProperty?.(
            'background-image',
            replaceFirstCssMediaSource(backgroundImage, null) || 'none',
            backgroundPriority,
        );
        return true;
    }

    const nextFrame = globalThis.requestAnimationFrame || ((callback) => globalThis.queueMicrotask?.(callback));
    animatedPreviewState.set(element, {
        playing: true,
        source,
        isImage,
        backgroundImage,
        backgroundPriority,
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

export function restartAnimatedPreview(component, { nonce = Date.now() } = {}) {
    const element = component?.getEl?.();
    if (!element) return false;
    const attributes = componentAttributes(component);
    const style = component?.getStyle?.() || component?.get?.('style') || {};
    const modelSource = String(component?.get?.('src') || attributes.src || '');
    const renderedBackground = String(element.style?.backgroundImage || element.ownerDocument?.defaultView?.getComputedStyle?.(element)?.backgroundImage || '');
    const previewState = animatedPreviewState.get(element);
    const backgroundImage = String(
        previewState?.backgroundImage
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
        ? (previewState?.source || renderedBackgroundSource || '')
        : (modelBackgroundSource || renderedBackgroundSource || previewState?.source || '');
    const persistentSource = modelSource || backgroundSource;
    if (!persistentSource) return false;
    const dataSource = /^data:image\//i.test(persistentSource);
    const nextFrame = globalThis.requestAnimationFrame || ((callback) => globalThis.queueMicrotask?.(callback));
    let previewSource = persistentSource;
    try {
        const url = new URL(persistentSource, element.ownerDocument?.baseURI || globalThis.location?.href || 'http://localhost/');
        url.searchParams.set('_rt_preview_restart', String(nonce));
        previewSource = url.href;
    } catch {
        const separator = persistentSource.includes('?') ? '&' : '?';
        previewSource = `${persistentSource}${separator}_rt_preview_restart=${encodeURIComponent(String(nonce))}`;
    }
    // Ausschliesslich das Canvas-DOM aendern. Das Grapes-Modell bleibt exakt.
    // TRAIN_SRC sitzt in der Signatur auf einer TD-Hintergrundflaeche, waehrend
    // normale GIFs als IMG vorkommen. Beide Pfade werden ohne Modellmutation
    // neu gestartet.
    animatedPreviewState.set(element, {
        playing: true,
        source: persistentSource,
        isImage: modelSource && String(element.tagName || '').toLowerCase() === 'img',
        backgroundImage,
        backgroundPriority,
    });
    delete element.dataset.rtLmzAnimationPaused;
    if (modelSource && String(element.tagName || '').toLowerCase() === 'img') {
        if (dataSource) {
            element.removeAttribute?.('src');
            nextFrame(() => element.setAttribute?.('src', persistentSource));
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
            replaceFirstCssMediaSource(backgroundImage, persistentSource),
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
    const coarse = environment.coarsePointer ?? window_?.matchMedia?.('(pointer: coarse)')?.matches === true;
    let active = Boolean(enabled);
    let destroyed = false;
    let overlay = null;
    let host = null;
    let scheduled = null;
    let drag = null;
    const handles = new Map();
    const bindings = [];

    const ensure = () => {
        const nextHost = editor.Canvas.getToolsEl?.();
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
            handle.innerHTML = '<span class="rt-lmz-spacing-overlay__surface" aria-hidden="true"></span><span class="rt-lmz-spacing-overlay__label"></span>';
            handle.addEventListener('pointerdown', startDrag);
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
        if (!node || !active || !selected || editor.Commands?.isActive?.('core:preview')) {
            if (node) node.hidden = true;
            return;
        }
        const element = selected.getEl?.();
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
        const component = editor.getSelected?.();
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

    const events = ['load', 'component:selected', 'component:deselected', 'component:update', 'component:styleUpdate', 'canvas:coords', 'canvas:zoom', 'canvas:frame:load'];
    events.forEach((event) => editor.on?.(event, refresh));
    window_?.addEventListener?.('resize', refresh);
    refresh();

    return {
        setEnabled(next) {
            active = Boolean(next);
            if (overlay) overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
            if (!active) finishDrag();
            refresh();
        },
        refresh,
        destroy() {
            if (destroyed) return;
            destroyed = true;
            finishDrag();
            if (scheduled !== null) cancelFrame(scheduled);
            scheduled = null;
            events.forEach((event) => editor.off?.(event, refresh));
            window_?.removeEventListener?.('resize', refresh);
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
    const existing = button.querySelector('.lmz-builder__action-label');
    if (existing) {
        existing.classList.add('rt-lmz-toolbar-action__label');
        existing.textContent = label;
    } else if (!button.querySelector('.rt-lmz-toolbar-action__label')) {
        const span = button.ownerDocument.createElement('span');
        span.className = 'rt-lmz-toolbar-action__label';
        span.textContent = label;
        button.appendChild(span);
    }
}

function createMediaDrawer({ root, editor, mode, media, capabilities, onChanged }) {
    const document_ = root.ownerDocument;
    const viewport = root.querySelector('.lmz-builder__viewport') || root;
    const drawer = document_.createElement('section');
    drawer.className = 'rt-lmz-media-drawer';
    drawer.hidden = true;
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'false');
    drawer.setAttribute('aria-label', mode === 'mail' ? 'Markenmedien' : 'Medien');
    drawer.innerHTML = `
        <header class="rt-lmz-media-drawer__header">
            <div><strong>${mode === 'mail' ? 'Markenmedien' : 'Medien'}</strong><small data-rt-lmz-media-summary></small></div>
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
    let state = { used: [], library: [], warnings: [] };
    let returnFocus = null;
    const currentTokenMedia = () => {
        const value = typeof media.tokenMedia === 'function' ? media.tokenMedia() : media.tokenMedia;
        return Array.isArray(value) ? value : [];
    };

    const currentDocument = () => ({
        html: editor.getHtml?.() || '',
        css: editor.getCss?.() || '',
    });
    const refresh = () => {
        const documentState = currentDocument();
        state = collectUsedMedia({
            ...documentState,
            mode,
            assets: media.assets || [],
            trustedSources: media.trustedSources || [],
            tokenMedia: currentTokenMedia(),
            baseUrl: media.baseUrl || globalThis.location?.origin || 'http://localhost/',
            environment: { DOMParser: globalThis.DOMParser },
        });
        const query = String(filter.value || '').trim().toLocaleLowerCase('de');
        const items = (tab === 'used'
            ? state.used
            : (mode === 'mail' ? currentTokenMedia().map((item) => ({
                id: `token:${normalizedToken(item.token)}`, token: normalizedToken(item.token),
                src: item.src || '', name: item.label || item.token, protected: true, allowed: true,
            })) : state.library))
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
            const canSelect = tab === 'library' && mode === 'marketing' && replaceSession && !item.protected;
            button.disabled = !canSelect;
            if (canSelect) button.addEventListener('click', () => {
                try {
                    replaceSession.select(item.asset || item, true);
                    replaceSession = null;
                    onChanged?.();
                    refresh();
                    drawer.hidden = true;
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
        summary.textContent = `${state.used.length} verwendet · ${mode === 'mail' ? currentTokenMedia().length : state.library.length} verfügbar`;
        hint.textContent = mode === 'mail'
            ? 'Mail-Markenmedien sind geschützt. Freie Dateipoolbilder werden nicht als private Admin-URL in E-Mails eingesetzt.'
            : (replaceSession ? 'Wähle ein Bild aus der freigegebenen Bibliothek.' : 'Zum Ersetzen zuerst ein bearbeitbares Bild im Motiv auswählen.');
    };

    const close = ({ restoreFocus = false } = {}) => {
        replaceSession?.cancel?.();
        replaceSession = null;
        drawer.hidden = true;
        if (restoreFocus) returnFocus?.focus?.();
    };
    const onDrawerKeydown = (event) => {
        if (event.key !== 'Escape' || drawer.hidden) return;
        event.preventDefault();
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
            if (replaceTarget && capabilities.imageReplace === true) {
                replaceSession = createImageAssetSelection({
                    editor,
                    target: replaceTarget,
                    assets: media.assets || [],
                    mode,
                    baseUrl: media.baseUrl,
                    onSelected: onChanged,
                });
            } else if (typeof selectAsset === 'function' && mode === 'marketing') {
                replaceSession = createScopedAssetCallbackSelection({
                    assets: media.assets || [],
                    select: selectAsset,
                    baseUrl: media.baseUrl,
                    onSelected: onChanged,
                });
            }
            drawer.querySelectorAll('[data-rt-lmz-media-tab]').forEach((item) => item.setAttribute('aria-selected', String(item.dataset.rtLmzMediaTab === tab)));
            drawer.hidden = false;
            refresh();
            drawer.querySelector(`[data-rt-lmz-media-tab="${tab}"]`)?.focus?.();
        },
        close,
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
        drawer.hidden = true;
        component = null;
        if (restoreFocus) returnFocus?.focus?.();
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
        event.preventDefault();
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
        destroy() {
            drawer.removeEventListener('keydown', onDrawerKeydown);
            document_.removeEventListener('pointerdown', onOutsidePointer, true);
            drawer.remove();
        },
    };
}

function addInlineEditToolbar(editor, root, menu) {
    const document_ = root.ownerDocument;
    const attach = () => {
        const toolbar = editor?.Canvas?.getToolbarEl?.();
        if (!toolbar || toolbar.querySelector?.(`[data-command="${EDIT_COMMAND}"]`)) return;
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
    };
    const onSelected = () => globalThis.queueMicrotask?.(attach);
    editor.on?.('component:selected', onSelected);
    editor.on?.('canvas:frame:load', onSelected);
    attach();
    return () => {
        editor.off?.('component:selected', onSelected);
        editor.off?.('canvas:frame:load', onSelected);
        editor?.Canvas?.getToolbarEl?.()?.querySelector?.(`[data-command="${EDIT_COMMAND}"]`)?.remove?.();
    };
}

function installStructureActionGuard(editor, root, { writable = true } = {}) {
    const blockedCommands = new Set(['tlb-move', 'tlb-clone', 'tlb-delete']);
    const selectionIsProtected = () => !writable || isProtectedEditorStructure(editor?.getSelected?.());
    const refresh = () => {
        const protectedSelection = selectionIsProtected();
        root.dataset.rtLmzProtectedSelection = protectedSelection ? 'true' : 'false';
        editor?.Canvas?.getToolbarEl?.()?.querySelectorAll?.('[data-command]')?.forEach?.((button) => {
            if (!blockedCommands.has(String(button.dataset?.command || ''))) return;
            button.hidden = protectedSelection;
            button.disabled = protectedSelection;
            button.setAttribute('aria-disabled', protectedSelection ? 'true' : 'false');
        });
    };
    const blockToolbarAction = (event) => {
        const command = event.target?.closest?.('[data-command]')?.dataset?.command;
        if (!blockedCommands.has(String(command || '')) || !selectionIsProtected()) return;
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
    };
    const blockKeyboardRemoval = (event) => {
        if (!['Delete', 'Backspace'].includes(event.key) || !selectionIsProtected()) return;
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
    editor?.on?.('component:selected', refresh);
    editor?.on?.('component:deselected', refresh);
    editor?.on?.('canvas:frame:load', bindFrame);
    bindFrame();

    return () => {
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
    return openPanel(root, 'right:traits');
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

    const actionDefinitions = () => {
        const image = resolveEditableImageComponent(editor, component, { mode });
        const protectedStructure = isProtectedEditorStructure(component);
        const animationTarget = resolveAnimatedComponent(component);
        const animation = componentAnimationContext(animationTarget || image || component);
        return [
            { id: 'content', label: 'Inhalt', enabled: capabilities.writable && !protectedStructure },
            { id: 'traits', label: 'Eigenschaften', panel: 'right:traits', enabled: capabilities.writable && capabilities.traits && !protectedStructure },
            { id: 'styles', label: 'Stile', panel: 'right:styles', enabled: capabilities.writable && capabilities.styles && !protectedStructure },
            { id: 'spacing', label: 'Abstände', panel: 'right:styles', enabled: capabilities.writable && capabilities.spacing && !protectedStructure },
            { id: 'media', label: 'Medien', enabled: capabilities.media && (image || mode === 'mail') },
            { id: 'replace', label: 'Bild ersetzen', enabled: capabilities.imageReplace === true && Boolean(image) },
            { id: 'animation', label: animation.animated ? 'Animation & GIF' : 'Animation', enabled: !protectedStructure && (capabilities.animation || (capabilities.gifControls && animation.animated)) },
            {
                id: 'gif-playback',
                label: animatedPreviewIsPlaying(animationTarget || image || component) ? 'GIF-Vorschau anhalten' : 'GIF-Vorschau abspielen',
                enabled: capabilities.gifControls && animation.animated,
            },
            { id: 'gif-restart', label: 'GIF-Vorschau neu starten', enabled: capabilities.gifControls && animation.animated },
            { id: 'move', label: 'Umpositionieren', enabled: capabilities.writable && !protectedStructure },
            { id: 'duplicate', label: 'Duplizieren', enabled: capabilities.writable && !protectedStructure },
            { id: 'delete', label: 'Löschen', enabled: capabilities.writable && !protectedStructure, danger: true },
        ].filter((item) => item.enabled !== false);
    };

    const close = ({ restoreFocus = false } = {}) => {
        menu.hidden = true;
        component = null;
        if (restoreFocus) returnFocus?.focus?.();
    };
    const render = () => {
        menu.replaceChildren();
        actionDefinitions().forEach((definition) => {
            const button = document_.createElement('button');
            button.type = 'button';
            button.role = 'menuitem';
            button.dataset.rtLmzInlineAction = definition.id;
            if (definition.danger) button.dataset.tone = 'danger';
            button.textContent = definition.label;
            button.addEventListener('click', () => {
                const selected = component;
                if (definition.panel) openPanel(root, definition.panel);
                if (definition.id === 'content') openSelectedContent(editor, selected, root);
                if (definition.id === 'media') mediaDrawer.open({ initialTab: 'used' });
                if (definition.id === 'replace') mediaDrawer.open({ replaceTarget: selected, initialTab: 'library' });
                if (definition.id === 'animation') animationDrawer.open(selected);
                if (definition.id === 'gif-playback') {
                    const target = resolveAnimatedComponent(selected) || resolveEditableImageComponent(editor, selected, { mode }) || selected;
                    setAnimatedPreviewPlayback(target, !animatedPreviewIsPlaying(target));
                }
                if (definition.id === 'gif-restart') restartAnimatedPreview(resolveAnimatedComponent(selected) || resolveEditableImageComponent(editor, selected, { mode }) || selected);
                if (definition.id === 'move') {
                    const moveHandle = editor.Canvas?.getToolbarEl?.()?.querySelector?.('[data-command="tlb-move"]');
                    moveHandle?.focus?.();
                    moveHandle?.classList?.add?.('rt-lmz-move-ready');
                    globalThis.setTimeout?.(() => moveHandle?.classList?.remove?.('rt-lmz-move-ready'), 1800);
                }
                if (definition.id === 'duplicate') duplicateSelectedComponent(editor, selected);
                if (definition.id === 'delete') deleteSelectedComponent(editor, selected);
                close();
            });
            menu.appendChild(button);
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
        if (event.key === 'Escape') { event.preventDefault(); close({ restoreFocus: true }); }
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
            const toolbar = editor.Canvas?.getToolbarEl?.();
            const canvas = root.getBoundingClientRect?.() || { left: 0, top: 0 };
            const anchor = toolbar?.getBoundingClientRect?.() || { left: canvas.left + 12, bottom: canvas.top + 54 };
            menu.style.left = `${clamp(anchor.left - canvas.left, 8, Math.max(8, canvas.width - 240))}px`;
            menu.style.top = `${Math.max(8, anchor.bottom - canvas.top + 6)}px`;
            menu.hidden = false;
            menu.querySelector('[role="menuitem"]')?.focus?.();
        },
        close,
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
        ...(normalizedMode === 'marketing' ? ['replace_image', 'animation'] : []),
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
            const shell = rootElement.closest?.('[data-page-builder-shell]');
            const trigger = shell?.querySelector?.('[data-page-builder-closed-preview] button');
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
        async save() { return typeof save === 'function' ? Boolean(await save()) : Boolean(await instance.save?.('manual')); },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            editor.off?.('update', onUpdate);
            editor.off?.('component:selected', onSelected);
            editor.off?.('component:deselected', onSelected);
            dispatchWindowEvent('railtime-pagebuilder-adapter-unregister', { adapter });
        },
    };
    dispatchWindowEvent('railtime-pagebuilder-adapter-register', { adapter });
    return adapter;
}

export function createLmzEditorChrome({
    instance,
    root,
    mode = 'marketing',
    capabilities = {},
    media = {},
    active = true,
} = {}) {
    const rootElement = asElement(root);
    const editor = instance?.editor;
    if (!rootElement || !editor) throw new TypeError('Der gemeinsame LMZ-Adapter benötigt Root und Editorinstanz.');
    rootElement.__rtLmzEditorChrome?.destroy?.();
    const normalized = normalizeLmzCapabilities(mode, capabilities);
    const abortController = new AbortController();
    let isOpen = Boolean(active);
    let destroyed = false;
    rootElement.classList.add('rt-lmz-editor');
    rootElement.dataset.rtLmzMode = mode === 'mail' ? 'mail' : 'marketing';
    rootElement.dataset.rtLmzOpen = isOpen ? 'true' : 'false';
    rootElement.dataset.rtLmzReadOnly = normalized.writable ? 'false' : 'true';

    // Vendorpfade, die in Laravel absichtlich nicht existieren, verschwinden
    // vollstaendig. Der Medienbutton bleibt erhalten und wird umgeleitet.
    rootElement.querySelectorAll('[data-lmz-action="upload"], [data-lmz-upload-input]').forEach((element) => element.remove());
    if (!normalized.animation) {
        rootElement.querySelectorAll('[data-lmz-action^="motion-"], .lmz-builder__motion-actions').forEach((element) => element.remove());
    }
    const labels = { undo: 'Rückgängig', redo: 'Wiederholen', preview: 'Vorschau', assets: 'Medien', save: 'Speichern' };
    Object.entries(labels).forEach(([action, label]) => addActionLabel(rootElement.querySelector(`[data-lmz-action="${action}"]`), label));
    rootElement.querySelector('.lmz-builder__topbar')?.setAttribute('data-rt-lmz-toolbar', 'true');
    rootElement.querySelectorAll('[data-lmz-panel-toggle]').forEach((button) => button.classList.add('rt-lmz-drawer-trigger'));
    rootElement.querySelectorAll('[data-lmz-popover]').forEach((drawer) => drawer.setAttribute('data-rt-lmz-drawer', drawer.dataset.lmzPopover || ''));
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
    const refreshAll = () => { spacing.refresh(); mediaDrawer?.refresh(); };
    mediaDrawer = createMediaDrawer({ root: rootElement, editor, mode, media, capabilities: normalized, onChanged: refreshAll });
    const detachScopedAssetAccess = installScopedAssetAccess({ editor, mediaDrawer, mode });
    animationDrawer = createAnimationDrawer({ root: rootElement, editor, capabilities: normalized, mode, onChanged: refreshAll });
    const menu = createInlineMenu({ root: rootElement, editor, capabilities: normalized, mode, mediaDrawer, animationDrawer });
    const detachToolbar = addInlineEditToolbar(editor, rootElement, menu);
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
    const onSelected = () => {
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
    editor.on?.('component:selected', onSelected);
    editor.on?.('component:update', refreshAll);

    const api = {
        editor,
        capabilities: normalized,
        open(detail = {}) {
            if (destroyed) return false;
            isOpen = true;
            rootElement.dataset.rtLmzOpen = 'true';
            spacing.setEnabled(normalized.spacing);
            refreshAll();
            dispatch(rootElement, LMZ_EDITOR_EVENTS.opened, { mode, ...detail });
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
            dispatch(rootElement, LMZ_EDITOR_EVENTS.closed, { mode, ...detail });
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
            };
            return Boolean(mapping[panel] && openPanel(rootElement, mapping[panel]));
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
            editor.off?.('component:update', refreshAll);
            detachScopedAssetAccess();
            detachStructureActionGuard();
            detachToolbar();
            menu.destroy();
            mediaDrawer.destroy();
            animationDrawer.destroy();
            spacing.destroy();
            rootElement.classList.remove('rt-lmz-editor');
            delete rootElement.dataset.rtLmzMode;
            delete rootElement.dataset.rtLmzOpen;
            delete rootElement.dataset.rtLmzReadOnly;
            if (rootElement.__rtLmzEditorChrome === api) delete rootElement.__rtLmzEditorChrome;
            dispatch(rootElement, LMZ_EDITOR_EVENTS.destroyed, { mode });
        },
    };

    rootElement.addEventListener(LMZ_EDITOR_EVENTS.requestOpen, (event) => api.open(event.detail), { signal: abortController.signal });
    rootElement.addEventListener(LMZ_EDITOR_EVENTS.requestClose, (event) => api.close(event.detail), { signal: abortController.signal });
    rootElement.addEventListener(LMZ_EDITOR_EVENTS.requestRefresh, refreshAll, { signal: abortController.signal });
    rootElement.__rtLmzEditorChrome = api;
    dispatch(rootElement, LMZ_EDITOR_EVENTS.ready, { mode, capabilities: normalized });
    return api;
}
