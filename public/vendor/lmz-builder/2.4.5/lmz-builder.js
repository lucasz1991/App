(function () {
    'use strict';

    const CANVAS_TAILWIND_RELATIVE = '/templates/lmz_railtime/l3/css/tailwind.min.css';
    const CANVAS_STYLE_SYNC_INTERVAL_MS = 2000;
    const MOTION_REFRESH_DEBOUNCE_MS = 180;
    const LMZ_VENDOR_VERSION = '20260331-3';
    const GRAPESJS_VERSION = '0.22.14';
    const SPACING_SIDES = ['top', 'right', 'bottom', 'left'];
    const SPACING_MIN_HANDLE_SIZE = 10;

    function toAbsoluteUrl(href, baseUrl) {
        try {
            return new URL(href, baseUrl || window.location.href).href;
        } catch (error) {
            return href;
        }
    }

    function normalizeCanvasStyles(styles, baseUrl) {
        if (!Array.isArray(styles) || styles.length === 0) {
            return [];
        }

        const seen = new Set();
        const normalized = [];

        styles
            .filter(Boolean)
            .forEach((href) => {
                const absoluteHref = toAbsoluteUrl(String(href), baseUrl);
                if (!seen.has(absoluteHref)) {
                    seen.add(absoluteHref);
                    normalized.push(absoluteHref);
                }
            });

        return normalized;
    }

    function normalizeCanvasScripts(scripts, baseUrl) {
        return normalizeCanvasStyles(scripts, baseUrl).filter((src) => {
            try {
                const url = new URL(src);
                return url.origin === window.location.origin
                    && ['http:', 'https:'].includes(url.protocol)
                    && /\.js$/i.test(url.pathname);
            } catch (error) {
                return false;
            }
        });
    }

    function normalizeCanvasBaseUrl(baseUrl) {
        const candidate = String(baseUrl || '').trim() || '/';
        const withTrailingSlash = candidate.endsWith('/') ? candidate : candidate + '/';

        return toAbsoluteUrl(withTrailingSlash, window.location.origin + '/');
    }

    function toPortableSiteUrl(href, baseUrl) {
        try {
            const url = new URL(href, normalizeCanvasBaseUrl(baseUrl));
            return url.origin === window.location.origin
                ? url.pathname + url.search + url.hash
                : url.href;
        } catch (error) {
            return href;
        }
    }

    function getDefaultCanvasStyles() {
        return normalizeCanvasStyles([CANVAS_TAILWIND_RELATIVE], window.location.href);
    }

    const DEFAULT_CANVAS_STYLES = getDefaultCanvasStyles();
    // The Joomla package is deliberately self-contained. Optional plugins may
    // be supplied as local runtime extensions, but the editor never falls back
    // to third-party CDNs.
    const STUDIO_WEB_DEFAULT_PLUGIN_SPECS = [];

    const DEFAULT_PROJECT_DATA = {
        assets: [],
        styles: [],
        pages: [
            {
                name: 'Startseite',
                component:
                    '<section class="container mx-auto px-6 py-12">' +
                    '<h1 class="text-3xl font-semibold">Neue Seite</h1>' +
                    '<p class="mt-4 text-gray-700">Dieser Inhalt wurde mit dem LMZ Builder erstellt.</p>' +
                    '</section>',
            },
        ],
    };

    const DEFAULT_OPTIONS = {
        root: '#studio-editor',
        projectId: null,
        gjsScript: '/media/com_lmzpagebuilder/js/lmz-builder-core.js?v=' + LMZ_VENDOR_VERSION,
        gjsStyle: '/media/com_lmzpagebuilder/css/lmz-builder-core.css?v=' + LMZ_VENDOR_VERSION,
        canvasStyles: DEFAULT_CANVAS_STYLES,
        canvasScripts: [],
        canvasBaseUrl: '/',
        canvasBodyClasses: [],
        canvasLanguage: '',
        motion: {
            enabled: true,
            preview: true,
            debounceMs: MOTION_REFRESH_DEBOUNCE_MS,
        },
        integrations: {
            embedCatalog: {},
            sharedElements: [],
            sharedLanguage: '',
            currentElementId: 0,
        },
        endpoints: {
            load: '/administrator/pagebuilder/load/:id',
            save: '/administrator/pagebuilder/save',
            assets: '/administrator/pagebuilder/assets',
            upload: '/administrator/pagebuilder/upload',
        },
        fetch: {
            credentials: 'same-origin',
            headers: {},
        },
        autosave: {
            enabled: true,
            intervalMs: 10000,
            changesBeforeSave: 30,
        },
        blocks: {
            addDefault: true,
            custom: [],
        },
        styleManager: {
            sectors: [
                { name: 'General', open: false, buildProps: ['display', 'position', 'top', 'right', 'bottom', 'left', 'float', 'clear', 'overflow'] },
                { name: 'Dimension', open: true, buildProps: ['width', 'height', 'min-height', 'max-width', 'padding', 'margin'] },
                {
                    name: 'Flex',
                    open: false,
                    buildProps: [
                        'flex-direction',
                        'flex-wrap',
                        'justify-content',
                        'align-items',
                        'align-content',
                        'order',
                        'flex-basis',
                        'flex-grow',
                        'flex-shrink',
                        'align-self',
                    ],
                },
                {
                    name: 'Typography',
                    open: true,
                    buildProps: [
                        'font-family',
                        'font-size',
                        'font-weight',
                        'line-height',
                        'letter-spacing',
                        'color',
                        'text-align',
                        'text-decoration',
                        'text-transform',
                    ],
                },
                {
                    name: 'Background',
                    open: false,
                    buildProps: ['background-color', 'background-image', 'background-repeat', 'background-position', 'background-size', 'background-attachment'],
                },
                { name: 'Border', open: false, buildProps: ['border', 'border-width', 'border-style', 'border-color', 'border-radius'] },
                { name: 'Effects', open: false, buildProps: ['opacity', 'box-shadow', 'text-shadow', 'transform', 'transition'] },
            ],
        },
        storage: {
            onLoad: null,
            onSave: null,
        },
        assets: {
            onLoad: null,
            onUpload: null,
        },
        plugins: [],
        pluginSpecs: [],
        pluginsOpts: {},
        useStudioWebDefaults: true,
        allowFallbackProject: false,
        gjsOptions: {},
    };

    function isPlainObject(value) {
        return !!value && typeof value === 'object' && !Array.isArray(value);
    }

    function deepMerge(base, extra) {
        const output = Array.isArray(base) ? base.slice() : { ...base };

        if (!isPlainObject(extra) && !Array.isArray(extra)) {
            return output;
        }

        Object.keys(extra).forEach((key) => {
            const incoming = extra[key];
            const current = output[key];

            if (Array.isArray(incoming)) {
                output[key] = incoming.slice();
                return;
            }

            if (isPlainObject(incoming) && isPlainObject(current)) {
                output[key] = deepMerge(current, incoming);
                return;
            }

            output[key] = incoming;
        });

        return output;
    }

    function ensureArray(value) {
        return Array.isArray(value) ? value.filter(Boolean) : [];
    }

    function uniquePlugins(plugins) {
        const seenStrings = new Set();
        const seenFunctions = new Set();
        const output = [];

        ensureArray(plugins).forEach((plugin) => {
            if (typeof plugin === 'string') {
                if (!seenStrings.has(plugin)) {
                    seenStrings.add(plugin);
                    output.push(plugin);
                }
                return;
            }

            if (typeof plugin === 'function') {
                if (!seenFunctions.has(plugin)) {
                    seenFunctions.add(plugin);
                    output.push(plugin);
                }
                return;
            }

            output.push(plugin);
        });

        return output;
    }

    function normalizePluginSpecs(specs) {
        const output = [];
        const byKey = new Map();

        ensureArray(specs).forEach((entry) => {
            const normalizedEntry = typeof entry === 'string' ? { src: entry } : entry;
            if (!isPlainObject(normalizedEntry)) {
                return;
            }

            const id = typeof normalizedEntry.id === 'string' && normalizedEntry.id.trim() ? normalizedEntry.id.trim() : null;
            const src = typeof normalizedEntry.src === 'string' && normalizedEntry.src.trim() ? normalizedEntry.src.trim() : null;
            const key = id ? 'id:' + id : src ? 'src:' + toAbsoluteUrl(src) : null;

            if (!key) {
                return;
            }

            const existing = byKey.get(key) || { id: null, src: null, options: {} };
            const merged = {
                id: id || existing.id,
                src: src || existing.src,
                options: deepMerge(existing.options || {}, isPlainObject(normalizedEntry.options) ? normalizedEntry.options : {}),
            };

            byKey.set(key, merged);
        });

        byKey.forEach((value) => output.push(value));
        return output;
    }

    async function resolvePluginSpecs(grapesjs, specs) {
        const plugins = [];
        const pluginOptions = {};
        const normalizedSpecs = normalizePluginSpecs(specs);

        for (const spec of normalizedSpecs) {
            if (spec.src) {
                try {
                    await loadScriptOnce(spec.src);
                } catch (error) {
                    console.warn('LMZBuilder: plugin script konnte nicht geladen werden:', spec.src);
                    continue;
                }
            }

            if (!spec.id) {
                continue;
            }

            let hasPlugin = true;
            if (typeof grapesjs?.plugins?.get === 'function') {
                try {
                    hasPlugin = !!grapesjs.plugins.get(spec.id);
                } catch (error) {
                    hasPlugin = false;
                }
            }

            if (!hasPlugin && spec.src) {
                console.warn('LMZBuilder: plugin wurde nicht registriert:', spec.id);
                continue;
            }

            plugins.push(spec.id);

            if (isPlainObject(spec.options) && Object.keys(spec.options).length) {
                pluginOptions[spec.id] = deepMerge(pluginOptions[spec.id] || {}, spec.options);
            }
        }

        return {
            plugins,
            pluginOptions,
        };
    }

    function resolveRuntimeExtensions(root) {
        const globalConfig = isPlainObject(window.__lmzBuilderConfig) ? window.__lmzBuilderConfig : {};
        const rootConfig = isPlainObject(root?.__lmzBuilderConfig) ? root.__lmzBuilderConfig : {};
        const pluginOptions = deepMerge(globalConfig.pluginsOpts || {}, rootConfig.pluginsOpts || {});
        const runtimeUseStudioDefaults =
            typeof rootConfig.useStudioWebDefaults === 'boolean'
                ? rootConfig.useStudioWebDefaults
                : typeof globalConfig.useStudioWebDefaults === 'boolean'
                ? globalConfig.useStudioWebDefaults
                : null;

        return {
            plugins: [...ensureArray(globalConfig.plugins), ...ensureArray(rootConfig.plugins)],
            pluginOptions,
            pluginSpecs: normalizePluginSpecs([
                ...ensureArray(globalConfig.pluginSpecs),
                ...ensureArray(globalConfig.pluginScripts),
                ...ensureArray(rootConfig.pluginSpecs),
                ...ensureArray(rootConfig.pluginScripts),
            ]),
            blockRegistrars: [...ensureArray(globalConfig.blockRegistrars), ...ensureArray(rootConfig.blockRegistrars)],
            customBlocks: [...ensureArray(globalConfig.customBlocks), ...ensureArray(rootConfig.customBlocks)],
            useStudioWebDefaults: runtimeUseStudioDefaults,
        };
    }

    function getRootElement(root) {
        if (typeof root === 'string') {
            const node = document.querySelector(root);
            if (!node) {
                throw new Error('LMZBuilder: root element not found for selector ' + root);
            }
            return node;
        }

        if (root instanceof HTMLElement) {
            return root;
        }

        throw new Error('LMZBuilder: invalid root option');
    }

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function withCsrfHeaders(headers) {
        const token = getCsrfToken();
        const merged = { ...(headers || {}) };

        if (token && !merged['X-CSRF-TOKEN']) {
            merged['X-CSRF-TOKEN'] = token;
        }

        return merged;
    }

    function loadStyleOnce(href) {
        if (!href) {
            return Promise.resolve();
        }

        if (document.querySelector('link[data-lmz-style="' + href + '"]')) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.dataset.lmzStyle = href;
            link.onload = resolve;
            link.onerror = () => reject(new Error('LMZBuilder: failed to load style ' + href));
            document.head.appendChild(link);
        });
    }

    function loadScriptOnce(src) {
        if (!src) {
            return Promise.resolve();
        }

        const existing = document.querySelector('script[data-lmz-script="' + src + '"]');
        if (existing) {
            if (existing.dataset.lmzLoaded === '1') {
                return Promise.resolve();
            }

            if (existing.dataset.lmzError === '1') {
                return Promise.reject(new Error('LMZBuilder: failed to load script ' + src));
            }

            return new Promise((resolve, reject) => {
                existing.addEventListener(
                    'load',
                    () => {
                        existing.dataset.lmzLoaded = '1';
                        resolve();
                    },
                    { once: true }
                );
                existing.addEventListener(
                    'error',
                    () => {
                        existing.dataset.lmzError = '1';
                        reject(new Error('LMZBuilder: failed to load script ' + src));
                    },
                    { once: true }
                );
            });
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.lmzScript = src;
            script.onload = () => {
                script.dataset.lmzLoaded = '1';
                resolve();
            };
            script.onerror = () => {
                script.dataset.lmzError = '1';
                reject(new Error('LMZBuilder: failed to load script ' + src));
            };
            document.head.appendChild(script);
        });
    }

    function resolveGrapesJsGlobal() {
        if (window.grapesjs) {
            return window.grapesjs;
        }

        if (window.exports && window.exports.grapesjs) {
            window.grapesjs = window.exports.grapesjs;
            return window.grapesjs;
        }

        if (window.module && window.module.exports) {
            const exported = window.module.exports;
            if (exported.init || exported.default || exported.plugins) {
                window.grapesjs = exported.default || exported;
                return window.grapesjs;
            }
        }

        if (window.globalThis && window.globalThis.grapesjs) {
            window.grapesjs = window.globalThis.grapesjs;
            return window.grapesjs;
        }

        return null;
    }

    async function ensureGrapesJs(options) {
        const expectedSource = toAbsoluteUrl(options.gjsScript, window.location.href);
        const current = resolveGrapesJsGlobal();
        const trackedSource = String(window.__lmzBuilderGrapesJsAssetUrl || '');
        const matchingScript = Array.from(document.querySelectorAll('script[src]'))
            .some((script) => toAbsoluteUrl(script.src, window.location.href) === expectedSource);
        const expectedVersion = String(current?.version || '') === GRAPESJS_VERSION;

        if (current && expectedVersion && (trackedSource === expectedSource || (!trackedSource && matchingScript))) {
            window.__lmzBuilderGrapesJsAssetUrl = expectedSource;
            return current;
        }

        // Ein langer Livewire-Tab darf nach einem Core-Update nicht still die
        // alte globale GrapesJS-Instanz weiterverwenden. Die bereits erzeugten
        // Editoren behalten ihre Objekt-Referenzen; der naechste Editor erhaelt
        // die versionierte, serverseitig konfigurierte Core-Datei.
        if (current) {
            try { window.grapesjs = undefined; } catch (_) {}
            try {
                if (window.globalThis) window.globalThis.grapesjs = undefined;
            } catch (_) {}
            try {
                if (window.exports?.grapesjs === current) delete window.exports.grapesjs;
            } catch (_) {}
            try {
                if (window.module?.exports === current) window.module.exports = {};
            } catch (_) {}
        }

        await loadStyleOnce(options.gjsStyle);
        await loadScriptOnce(options.gjsScript);

        const loaded = resolveGrapesJsGlobal();
        if (!loaded || String(loaded.version || '') !== GRAPESJS_VERSION) {
            throw new Error('LMZBuilder: grapesjs could not be loaded');
        }

        window.__lmzBuilderGrapesJsAssetUrl = expectedSource;
        return loaded;
    }

    function resolveEndpoint(urlTemplate, projectId) {
        if (!urlTemplate) {
            return null;
        }

        if (urlTemplate.includes(':id')) {
            return urlTemplate.replace(':id', encodeURIComponent(projectId || ''));
        }

        return urlTemplate;
    }

    async function parseJson(response) {
        const text = await response.text();
        if (!text) {
            return null;
        }

        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    }

    function normalizeProjectPayload(payload) {
        if (!payload) {
            return null;
        }

        let value = payload;
        if (isPlainObject(value) && value.project) {
            value = value.project;
        }

        if (typeof value === 'string') {
            try {
                value = JSON.parse(value);
            } catch (error) {
                return null;
            }
        }

        return isPlainObject(value) ? value : null;
    }

    function getShellIcon(name) {
        const icons = {
            brand:
                '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
                '<path d="M4 6.75C4 5.784 4.784 5 5.75 5h4.5C11.216 5 12 5.784 12 6.75v4.5c0 .966-.784 1.75-1.75 1.75h-4.5A1.75 1.75 0 0 1 4 11.25v-4.5Z" stroke="currentColor" stroke-width="1.6"/>' +
                '<path d="M12.75 12.75c0-.966.784-1.75 1.75-1.75h3.75c.966 0 1.75.784 1.75 1.75v5.5c0 .966-.784 1.75-1.75 1.75H14.5a1.75 1.75 0 0 1-1.75-1.75v-5.5Z" stroke="currentColor" stroke-width="1.6"/>' +
                '<path d="M12.75 5.75A1.75 1.75 0 0 1 14.5 4h3.75A1.75 1.75 0 0 1 20 5.75v1.5A1.75 1.75 0 0 1 18.25 9H14.5a1.75 1.75 0 0 1-1.75-1.75v-1.5Z" fill="currentColor" opacity=".22"/>' +
                '<path d="M4 16.5c0-.966.784-1.75 1.75-1.75h2.5c.966 0 1.75.784 1.75 1.75v1.75c0 .966-.784 1.75-1.75 1.75h-2.5A1.75 1.75 0 0 1 4 18.25V16.5Z" fill="currentColor"/>' +
                '</svg>',
            undo:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M7.75 5.25 4.25 8.5l3.5 3.25" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<path d="M5 8.5h6.5a4 4 0 1 1 0 8H9.25" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>',
            redo:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="m12.25 5.25 3.5 3.25-3.5 3.25" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<path d="M15 8.5H8.5a4 4 0 1 0 0 8h2.25" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>',
            preview:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M1.75 10s3.25-5 8.25-5 8.25 5 8.25 5-3.25 5-8.25 5-8.25-5-8.25-5Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<circle cx="10" cy="10" r="2.35" stroke="currentColor" stroke-width="1.7"/>' +
                '</svg>',
            assets:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<rect x="2.75" y="3.25" width="14.5" height="13.5" rx="2.25" stroke="currentColor" stroke-width="1.7"/>' +
                '<path d="m5.25 13.25 3-3 2.25 2.25 2.75-3.25 1.5 1.75" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<circle cx="7" cy="7.25" r="1.15" fill="currentColor"/>' +
                '</svg>',
            upload:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M10 13.75V3.5m0 0L6.5 7M10 3.5 13.5 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<path d="M4 11.5v3.25A1.75 1.75 0 0 0 5.75 16.5h8.5A1.75 1.75 0 0 0 16 14.75V11.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>' +
                '</svg>',
            motion:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="m7.25 5 6 5-6 5V5Z" fill="currentColor"/>' +
                '<path d="M3 4v12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity=".45"/>' +
                '</svg>',
            pause:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<rect x="5.25" y="4" width="3" height="12" rx="1" fill="currentColor"/>' +
                '<rect x="11.75" y="4" width="3" height="12" rx="1" fill="currentColor"/>' +
                '</svg>',
            restart:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M5.25 6.25V2.75m0 0h-3.5m3.5 0L2.9 5.1A7 7 0 1 0 5 14.95" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>',
            save:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M4 3.25h9.75L16.75 6v10.75H4V3.25Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>' +
                '<path d="M6.75 3.25V7h6V3.25" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>' +
                '<path d="M6.75 16.25V11.5h7v4.75" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>' +
                '</svg>',
            blocks:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<rect x="2.75" y="2.75" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.6"/>' +
                '<rect x="11.25" y="2.75" width="6" height="6" rx="1.5" fill="currentColor" opacity=".2"/>' +
                '<rect x="11.25" y="11.25" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.6"/>' +
                '<rect x="2.75" y="11.25" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.6"/>' +
                '</svg>',
            layers:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="m10 3 7 3.5-7 3.5-7-3.5L10 3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>' +
                '<path d="m3 10 7 3.5 7-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<path d="m3 13.5 7 3.5 7-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>',
            styles:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M4.5 5.25h11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>' +
                '<path d="M4.5 10h8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>' +
                '<path d="M4.5 14.75h6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>' +
                '<circle cx="14.25" cy="10" r="2" stroke="currentColor" stroke-width="1.5"/>' +
                '<circle cx="12.75" cy="14.75" r="2" fill="currentColor" opacity=".24"/>' +
                '</svg>',
            traits:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M10 2.75 3.75 6.25v7.5L10 17.25l6.25-3.5v-7.5L10 2.75Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>' +
                '<path d="M10 7.25v5.5M7.25 10h5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
                '</svg>',
            classes:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="M3.75 6.25h8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
                '<path d="M3.75 10h12.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
                '<path d="M3.75 13.75h9.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
                '<circle cx="14.75" cy="6.25" r="1.75" fill="currentColor" opacity=".22"/>' +
                '<circle cx="16.25" cy="13.75" r="1.75" stroke="currentColor" stroke-width="1.5"/>' +
                '</svg>',
            chevron:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="m6 8 4 4 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>',
            close:
                '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
                '<path d="m6 6 8 8M14 6l-8 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>' +
                '</svg>',
        };

        return icons[name] || '';
    }

    function createActionButton(action, label, icon, options) {
        const config = options || {};
        const classes = ['lmz-builder__action'];

        if (config.primary) {
            classes.push('is-primary');
        }

        if (config.iconOnly) {
            classes.push('is-icon-only');
        }

        return (
            '<button type="button" class="' +
            classes.join(' ') +
            '" data-lmz-action="' +
            action +
            '" aria-label="' +
            label +
            '">' +
            '<span class="lmz-builder__action-icon">' +
            getShellIcon(icon) +
            '</span>' +
            (config.iconOnly
                ? ''
                : '<span class="lmz-builder__action-label">' + label + '</span>') +
            '</button>'
        );
    }

    function createPanelToggleButton(config) {
        return (
            '<button type="button" class="lmz-builder__action lmz-builder__action--panel" data-lmz-panel-toggle="' +
            config.id +
            '" data-lmz-panel-group="' +
            config.group +
            '" aria-haspopup="dialog" aria-expanded="false">' +
            '<span class="lmz-builder__action-icon">' +
            getShellIcon(config.icon) +
            '</span>' +
            '<span class="lmz-builder__action-label">' +
            config.label +
            '</span>' +
            '</button>'
        );
    }

    function createPopoverPanel(config) {
        return (
            '<section class="lmz-builder__popover-panel" data-lmz-popover-panel="' +
            config.id +
            '" hidden>' +
            '<header class="lmz-builder__popover-head">' +
            '<div class="lmz-builder__popover-title">' +
            '<span class="lmz-builder__popover-icon">' +
            getShellIcon(config.icon) +
            '</span>' +
            '<strong>' +
            config.label +
            '</strong>' +
            '</div>' +
            '<button type="button" class="lmz-builder__popover-close" data-lmz-panel-close="' +
            config.group +
            '" aria-label="' +
            config.label +
            ' schliessen">' +
            getShellIcon('close') +
            '</button>' +
            '</header>' +
            '<div class="lmz-builder__popover-body">' +
            '<div class="lmz-builder__mount" data-lmz-mount="' +
            config.mount +
            '"></div>' +
            '</div>' +
            '</section>'
        );
    }

    function createPopover(side, sections) {
        return (
            '<aside class="lmz-builder__popover lmz-builder__popover--' +
            side +
            '" data-lmz-popover="' +
            side +
            '" hidden>' +
            sections.map((section) => createPopoverPanel(section)).join('') +
            '</aside>'
        );
    }

    function setupPopovers(shell) {
        const buttons = Array.from(shell.querySelectorAll('[data-lmz-panel-toggle]'));
        const panels = Array.from(shell.querySelectorAll('[data-lmz-popover-panel]'));
        const popovers = Array.from(shell.querySelectorAll('[data-lmz-popover]'));
        const closeButtons = Array.from(shell.querySelectorAll('[data-lmz-panel-close]'));
        const state = {
            left: null,
            right: null,
        };

        const getGroup = (value) => String(value || '').split(':')[0];

        function renderGroup(group) {
            const activeValue = state[group];

            buttons.forEach((button) => {
                if (button.dataset.lmzPanelGroup !== group) {
                    return;
                }

                const isActive = button.dataset.lmzPanelToggle === activeValue;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-expanded', isActive ? 'true' : 'false');
            });

            panels.forEach((panel) => {
                if (getGroup(panel.dataset.lmzPopoverPanel) !== group) {
                    return;
                }

                const isActive = panel.dataset.lmzPopoverPanel === activeValue;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });

            popovers.forEach((popover) => {
                if (popover.dataset.lmzPopover !== group) {
                    return;
                }

                const isOpen = !!activeValue;
                popover.classList.toggle('is-open', isOpen);
                popover.hidden = !isOpen;
            });

            shell.classList.toggle('has-' + group + '-popover', !!activeValue);
        }

        function open(value) {
            const group = getGroup(value);
            state[group] = value;
            renderGroup(group);
        }

        function closeGroup(group) {
            state[group] = null;
            renderGroup(group);
        }

        function toggle(value) {
            const group = getGroup(value);
            if (state[group] === value) {
                closeGroup(group);
                return;
            }

            open(value);
        }

        function closeAll() {
            closeGroup('left');
            closeGroup('right');
        }

        const pointerHandler = (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            if (target.closest('[data-lmz-panel-toggle]') || target.closest('.lmz-builder__popover') || target.closest('[data-lmz-action="selection"]')) {
                return;
            }

            closeAll();
        };

        const keyHandler = (event) => {
            if (event.key === 'Escape') {
                closeAll();
            }
        };

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                toggle(button.dataset.lmzPanelToggle);
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                closeGroup(button.dataset.lmzPanelClose);
            });
        });

        document.addEventListener('pointerdown', pointerHandler);
        document.addEventListener('keydown', keyHandler);

        renderGroup('left');
        renderGroup('right');

        return {
            open,
            toggle,
            closeGroup,
            closeAll,
            getActive(group) {
                return state[group] || null;
            },
            destroy() {
                document.removeEventListener('pointerdown', pointerHandler);
                document.removeEventListener('keydown', keyHandler);
            },
        };
    }

    function createShell(root) {
        const leftSections = [
            { id: 'left:blocks', group: 'left', mount: 'blocks', icon: 'blocks', label: 'Bausteine' },
            { id: 'left:layers', group: 'left', mount: 'layers', icon: 'layers', label: 'Ebenen' },
        ];
        const rightSections = [
            { id: 'right:styles', group: 'right', mount: 'styles', icon: 'styles', label: 'Stile' },
            { id: 'right:traits', group: 'right', mount: 'traits', icon: 'traits', label: 'Eigenschaften' },
            { id: 'right:classes', group: 'right', mount: 'classes', icon: 'classes', label: 'Klassen' },
        ];

        root.classList.add('lmz-builder-host');
        root.innerHTML = '';

        const shell = document.createElement('div');
        shell.className = 'lmz-builder';
        shell.innerHTML =
            '<div class="lmz-builder__topbar">' +
            '<div class="lmz-builder__actions">' +
            createActionButton('undo', 'Undo', 'undo', { iconOnly: true }) +
            createActionButton('redo', 'Redo', 'redo', { iconOnly: true }) +
            createActionButton('preview', 'Vorschau', 'preview', { iconOnly: true }) +
            createActionButton('assets', 'Medienbibliothek', 'assets', { iconOnly: true }) +
            createActionButton('upload', 'Medien hochladen', 'upload', { iconOnly: true }) +
            '<span class="lmz-builder__motion-actions" aria-label="Motion-Vorschau">' +
            createActionButton('motion-preview', 'Motion-Vorschau', 'motion', { iconOnly: true }) +
            createActionButton('motion-pause', 'Motion pausieren', 'pause', { iconOnly: true }) +
            createActionButton('motion-restart', 'Motion neu starten', 'restart', { iconOnly: true }) +
            '<span class="lmz-builder__motion-state" data-lmz-motion-state data-state="loading">Motion</span>' +
            '</span>' +
            createActionButton('save', 'Speichern', 'save', { primary: true }) +
            '</div>' +
            '<div class="lmz-builder__panel-actions lmz-builder__panel-actions--left">' +
            leftSections.map((section) => createPanelToggleButton(section)).join('') +
            '</div>' +
            '<div class="lmz-builder__panel-actions lmz-builder__panel-actions--right">' +
            rightSections.map((section) => createPanelToggleButton(section)).join('') +
            '</div>' +
            '<div class="lmz-builder__meta">' +
            '<button type="button" class="lmz-builder__selection is-empty" data-lmz-action="selection">Keine Auswahl</button>' +
            '<div class="lmz-builder__status" data-lmz-status data-state="muted">Initialisierung...</div>' +
            '</div>' +
            '</div>' +
            '<div class="lmz-builder__viewport">' +
            '<main class="lmz-builder__main">' +
            '<div class="lmz-builder__canvas-shell">' +
            '<div class="lmz-builder__canvas" data-lmz-mount="canvas"></div>' +
            '</div>' +
            '</main>' +
            createPopover('left', leftSections) +
            createPopover('right', rightSections) +
            '</div>' +
            '<input type="file" data-lmz-upload-input multiple hidden />';

        root.appendChild(shell);
        const panels = setupPopovers(shell);

        return {
            shell,
            panels,
            status: shell.querySelector('[data-lmz-status]'),
            selection: shell.querySelector('[data-lmz-action="selection"]'),
            canvas: shell.querySelector('[data-lmz-mount="canvas"]'),
            blocks: shell.querySelector('[data-lmz-mount="blocks"]'),
            layers: shell.querySelector('[data-lmz-mount="layers"]'),
            styles: shell.querySelector('[data-lmz-mount="styles"]'),
            traits: shell.querySelector('[data-lmz-mount="traits"]'),
            classes: shell.querySelector('[data-lmz-mount="classes"]'),
            uploadInput: shell.querySelector('[data-lmz-upload-input]'),
            motionState: shell.querySelector('[data-lmz-motion-state]'),
        };
    }

    function setStatus(refs, text, state) {
        if (!refs.status) {
            return;
        }

        refs.status.textContent = text;
        refs.status.dataset.state = state || 'muted';
    }

    function getSelectionLabel(component) {
        if (!component) {
            return 'Keine Auswahl';
        }

        const attributes = typeof component.getAttributes === 'function' ? component.getAttributes() || {} : {};
        const explicitName = attributes['data-gjs-name'] || attributes['aria-label'] || attributes.title || null;
        const type = typeof component.get === 'function' ? component.get('type') || component.get('tagName') : null;
        const fallback = explicitName || (typeof component.getName === 'function' ? component.getName() : null) || type || 'Element';
        const normalized = String(fallback)
            .replace(/[-_]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        const label = normalized ? normalized.charAt(0).toUpperCase() + normalized.slice(1) : 'Element';

        return attributes.id ? label + ' #' + attributes.id : label;
    }

    function syncShellState(editor, refs) {
        if (!editor || !refs?.shell) {
            return;
        }

        const selected = typeof editor.getSelected === 'function' ? editor.getSelected() : null;

        if (refs.selection) {
            refs.selection.textContent = getSelectionLabel(selected);
            refs.selection.classList.toggle('is-empty', !selected);
        }

        const previewActive = !!editor.Commands?.isActive?.('core:preview');
        const previewButton = refs.shell.querySelector('[data-lmz-action="preview"]');
        if (previewButton) {
            previewButton.classList.toggle('is-active', previewActive);
        }

        refs.shell.classList.toggle('is-preview', previewActive);

        const undoManager = editor.UndoManager;
        const undoButton = refs.shell.querySelector('[data-lmz-action="undo"]');
        const redoButton = refs.shell.querySelector('[data-lmz-action="redo"]');

        if (undoButton && undoManager?.isAvailable) {
            undoButton.disabled = !undoManager.isAvailable('undo');
        }

        if (redoButton && undoManager?.isAvailable) {
            redoButton.disabled = !undoManager.isAvailable('redo');
        }
    }

    function getFirstChildComponent(wrapper) {
        if (!wrapper || typeof wrapper.components !== 'function') {
            return null;
        }

        const components = wrapper.components();
        if (!components) {
            return null;
        }

        if (typeof components.at === 'function') {
            return components.at(0) || null;
        }

        if (Array.isArray(components)) {
            return components[0] || null;
        }

        if (Array.isArray(components.models)) {
            return components.models[0] || null;
        }

        return null;
    }

    function selectInitialComponent(editor) {
        if (!editor || typeof editor.select !== 'function') {
            return;
        }

        if (typeof editor.getSelected === 'function' && editor.getSelected()) {
            return;
        }

        const wrapper = typeof editor.getWrapper === 'function' ? editor.getWrapper() : null;
        const target = getFirstChildComponent(wrapper) || wrapper;

        if (!target) {
            return;
        }

        try {
            editor.select(target);
        } catch (error) {
            console.warn('LMZBuilder: initiale Komponentenauswahl fehlgeschlagen');
        }
    }

    function dedupeClassManagerUis(container) {
        if (!container) {
            return;
        }

        const managers = Array.from(container.querySelectorAll('.lmzbjs-clm-tags'));
        if (managers.length <= 1) {
            return;
        }

        managers.slice(1).forEach((node) => node.remove());
    }

    function toNumericValue(value) {
        const parsed = typeof value === 'number' ? value : parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function roundSpacingValue(value) {
        return Math.round(toNumericValue(value) * 100) / 100;
    }

    function clampSpacingValue(value, min) {
        return Math.max(roundSpacingValue(value), typeof min === 'number' ? min : Number.NEGATIVE_INFINITY);
    }

    function getSpacingSnapshot(offsets) {
        const source = offsets || {};

        return {
            margin: {
                top: toNumericValue(source.marginTop),
                right: toNumericValue(source.marginRight),
                bottom: toNumericValue(source.marginBottom),
                left: toNumericValue(source.marginLeft),
            },
            padding: {
                top: Math.max(0, toNumericValue(source.paddingTop)),
                right: Math.max(0, toNumericValue(source.paddingRight)),
                bottom: Math.max(0, toNumericValue(source.paddingBottom)),
                left: Math.max(0, toNumericValue(source.paddingLeft)),
            },
            border: {
                top: Math.max(0, toNumericValue(source.borderTopWidth)),
                right: Math.max(0, toNumericValue(source.borderRightWidth)),
                bottom: Math.max(0, toNumericValue(source.borderBottomWidth)),
                left: Math.max(0, toNumericValue(source.borderLeftWidth)),
            },
        };
    }

    function getScaledSpacingBox(spacing, zoom) {
        return {
            top: spacing.top * zoom,
            right: spacing.right * zoom,
            bottom: spacing.bottom * zoom,
            left: spacing.left * zoom,
        };
    }

    function getHandleSize(value) {
        return value > 0 ? Math.max(value, SPACING_MIN_HANDLE_SIZE) : SPACING_MIN_HANDLE_SIZE;
    }

    function createRect(left, top, width, height) {
        return {
            left,
            top,
            width: Math.max(0, width),
            height: Math.max(0, height),
        };
    }

    function getPointerPosition(editor, event) {
        const frameDocument = editor?.Canvas?.getDocument?.();
        const frameElement = editor?.Canvas?.getFrameEl?.();
        const sourceDocument = event?.target?.ownerDocument;
        const clientX = toNumericValue(event?.clientX);
        const clientY = toNumericValue(event?.clientY);

        if (frameDocument && frameElement && sourceDocument === frameDocument) {
            const frameRect = frameElement.getBoundingClientRect();
            return {
                x: clientX + frameRect.left,
                y: clientY + frameRect.top,
            };
        }

        return {
            x: clientX,
            y: clientY,
        };
    }

    function getSpacingStylePayload(type, values, changedSide, nextValue) {
        const payload = {};

        SPACING_SIDES.forEach((side) => {
            const value = side === changedSide ? nextValue : values[side];
            payload[type + '-' + side] = roundSpacingValue(value) + 'px';
        });

        return payload;
    }

    function getSpacingDelta(side, deltaX, deltaY) {
        if (side === 'top') {
            return -deltaY;
        }

        if (side === 'right') {
            return deltaX;
        }

        if (side === 'bottom') {
            return deltaY;
        }

        return -deltaX;
    }

    function getActiveSpacingSnapshot(drag) {
        if (!drag) {
            return null;
        }

        const spacing = {
            margin: { ...drag.startSpacing.margin },
            padding: { ...drag.startSpacing.padding },
            border: { ...drag.startSpacing.border },
        };

        if (drag.latestValues) {
            spacing[drag.type] = { ...drag.latestValues };
        }

        return spacing;
    }

    function setupSpacingEditor(editor) {
        if (!editor?.Canvas) {
            return () => {};
        }

        const state = {
            disposed: false,
            rafId: 0,
            overlay: null,
            host: null,
            handles: new Map(),
            drag: null,
            dragTargets: [],
        };

        const getOverlayHost = () => editor.Canvas.getSpotsEl?.() || editor.Canvas.getToolsEl?.();

        const applyDragPreview = () => {
            if (!state.drag) {
                return;
            }

            state.drag.previewFrame = 0;

            if (!state.drag.pendingValues) {
                return;
            }

            state.drag.latestValues = { ...state.drag.pendingValues };
            state.drag.pendingValues = null;
            state.drag.component.addStyle(
                getSpacingStylePayload(state.drag.type, state.drag.startValues, state.drag.side, state.drag.latestValues[state.drag.side]),
                { partial: true }
            );

            scheduleRefresh();
        };

        const getLayoutMetrics = (element, dragState) => {
            if (dragState) {
                return {
                    position: {
                        ...dragState.startOuter,
                        zoom: dragState.zoom,
                    },
                    spacing: getActiveSpacingSnapshot(dragState),
                };
            }

            const position = editor.Canvas.getElementPos?.(element);
            if (!position || !Number.isFinite(position.width) || !Number.isFinite(position.height)) {
                return null;
            }

            return {
                position,
                spacing: getSpacingSnapshot(editor.Canvas.getElementOffsets?.(element)),
            };
        };

        const hideOverlay = () => {
            if (state.overlay) {
                state.overlay.style.display = 'none';
            }
        };

        const clearActiveHandles = () => {
            state.handles.forEach((handle) => {
                handle.classList.remove('is-active');
            });

            if (state.overlay) {
                state.overlay.classList.remove('is-dragging');
            }
        };

        const ensureOverlay = () => {
            const host = getOverlayHost();
            if (!host) {
                return null;
            }

            if (state.host !== host) {
                if (state.overlay?.parentNode) {
                    state.overlay.parentNode.removeChild(state.overlay);
                }

                state.overlay = null;
                state.host = host;
                state.handles.clear();
            }

            if (state.overlay) {
                return state.overlay;
            }

            const overlay = document.createElement('div');
            overlay.className = 'lmz-spacing-editor';
            overlay.style.display = 'none';

            const bindHandle = (type, side) => {
                const handle = document.createElement('button');
                handle.type = 'button';
                handle.className = 'lmz-spacing-editor__handle lmz-spacing-editor__handle--' + type;
                handle.dataset.type = type;
                handle.dataset.side = side;
                handle.setAttribute('aria-label', type + ' ' + side);
                handle.addEventListener('pointerdown', (event) => startDrag(event, type, side));
                overlay.appendChild(handle);
                state.handles.set(type + ':' + side, handle);
            };

            ['margin', 'padding'].forEach((type) => {
                SPACING_SIDES.forEach((side) => bindHandle(type, side));
            });

            host.appendChild(overlay);
            state.overlay = overlay;

            return overlay;
        };

        const applyRect = (handle, rect) => {
            if (!handle) {
                return;
            }

            const isVisible = rect.width > 0 && rect.height > 0;
            handle.style.display = isVisible ? 'block' : 'none';

            if (!isVisible) {
                handle.dataset.value = '';
                return;
            }

            handle.style.left = rect.left + 'px';
            handle.style.top = rect.top + 'px';
            handle.style.width = rect.width + 'px';
            handle.style.height = rect.height + 'px';
        };

        const refresh = () => {
            state.rafId = 0;

            if (state.disposed) {
                return;
            }

            const overlay = ensureOverlay();
            if (!overlay) {
                return;
            }

            const selected = editor.getSelected?.();
            if (!selected || editor.Commands?.isActive?.('core:preview')) {
                hideOverlay();
                return;
            }

            const element = selected.getEl?.();
            if (!element) {
                hideOverlay();
                return;
            }

            const dragState =
                state.drag && state.drag.component === selected && state.drag.element === element ? state.drag : null;
            const metrics = getLayoutMetrics(element, dragState);

            if (!metrics?.position || !metrics.spacing) {
                hideOverlay();
                return;
            }

            const position = metrics.position;
            const zoom = Math.max(toNumericValue(position.zoom) || 1, 0.01);
            const spacing = metrics.spacing;
            const margin = getScaledSpacingBox(spacing.margin, zoom);
            const padding = getScaledSpacingBox(spacing.padding, zoom);
            const border = getScaledSpacingBox(spacing.border, zoom);
            const outer = {
                left: position.left,
                top: position.top,
                width: position.width,
                height: position.height,
            };

            const displayMargin = {
                top: getHandleSize(margin.top),
                right: getHandleSize(margin.right),
                bottom: getHandleSize(margin.bottom),
                left: getHandleSize(margin.left),
            };

            const marginOuter = {
                left: outer.left - displayMargin.left,
                top: outer.top - displayMargin.top,
                width: outer.width + displayMargin.left + displayMargin.right,
                height: outer.height + displayMargin.top + displayMargin.bottom,
            };

            const paddingBox = {
                left: outer.left + border.left,
                top: outer.top + border.top,
                width: Math.max(0, outer.width - border.left - border.right),
                height: Math.max(0, outer.height - border.top - border.bottom),
            };

            const displayPadding = {
                top: paddingBox.height > 0 ? Math.min(getHandleSize(padding.top), paddingBox.height) : 0,
                right: paddingBox.width > 0 ? Math.min(getHandleSize(padding.right), paddingBox.width) : 0,
                bottom:
                    paddingBox.height > 0 ? Math.min(getHandleSize(padding.bottom), Math.max(paddingBox.height - Math.min(getHandleSize(padding.top), paddingBox.height), 0) || paddingBox.height) : 0,
                left: paddingBox.width > 0 ? Math.min(getHandleSize(padding.left), paddingBox.width) : 0,
            };

            overlay.style.display = 'block';

            applyRect(state.handles.get('margin:top'), createRect(marginOuter.left, marginOuter.top, marginOuter.width, displayMargin.top));
            applyRect(
                state.handles.get('margin:right'),
                createRect(outer.left + outer.width, outer.top, displayMargin.right, outer.height)
            );
            applyRect(
                state.handles.get('margin:bottom'),
                createRect(marginOuter.left, outer.top + outer.height, marginOuter.width, displayMargin.bottom)
            );
            applyRect(state.handles.get('margin:left'), createRect(marginOuter.left, outer.top, displayMargin.left, outer.height));

            applyRect(
                state.handles.get('padding:top'),
                createRect(paddingBox.left, paddingBox.top, paddingBox.width, displayPadding.top)
            );
            applyRect(
                state.handles.get('padding:right'),
                createRect(
                    Math.max(paddingBox.left + paddingBox.width - displayPadding.right, paddingBox.left),
                    paddingBox.top + displayPadding.top,
                    displayPadding.right,
                    Math.max(0, paddingBox.height - displayPadding.top - displayPadding.bottom)
                )
            );
            applyRect(
                state.handles.get('padding:bottom'),
                createRect(
                    paddingBox.left,
                    Math.max(paddingBox.top + paddingBox.height - displayPadding.bottom, paddingBox.top),
                    paddingBox.width,
                    displayPadding.bottom
                )
            );
            applyRect(
                state.handles.get('padding:left'),
                createRect(
                    paddingBox.left,
                    paddingBox.top + displayPadding.top,
                    displayPadding.left,
                    Math.max(0, paddingBox.height - displayPadding.top - displayPadding.bottom)
                )
            );

            state.handles.forEach((handle, key) => {
                const [type, side] = key.split(':');
                const rawValue = spacing[type][side];
                const value = roundSpacingValue(rawValue) + 'px';
                handle.title = type + ' ' + side + ': ' + value;
                handle.dataset.value = value;
                handle.dataset.label = type + ' ' + side + ': ' + value;
            });
        };

        const scheduleRefresh = () => {
            if (state.disposed || state.rafId) {
                return;
            }

            state.rafId = window.requestAnimationFrame(refresh);
        };

        const removeDragListeners = () => {
            state.dragTargets.forEach((target) => {
                target.removeEventListener('pointermove', onPointerMove, true);
                target.removeEventListener('pointerup', stopDrag, true);
                target.removeEventListener('pointercancel', stopDrag, true);
            });

            state.dragTargets = [];
        };

        function stopDrag() {
            if (!state.drag) {
                return;
            }

            const { component, handleEl, type, side, startValues, pointerId } = state.drag;

            if (state.drag.previewFrame) {
                window.cancelAnimationFrame(state.drag.previewFrame);
                state.drag.previewFrame = 0;
            }

            if (state.drag.pendingValues) {
                state.drag.latestValues = { ...state.drag.pendingValues };
                state.drag.pendingValues = null;
            }

            removeDragListeners();
            clearActiveHandles();

            if (handleEl?.releasePointerCapture && pointerId !== undefined && handleEl.hasPointerCapture?.(pointerId)) {
                handleEl.releasePointerCapture(pointerId);
            }

            if (state.drag.latestValues) {
                component.addStyle(getSpacingStylePayload(type, startValues, side, state.drag.latestValues[side]));
            }

            state.drag = null;
            scheduleRefresh();
        }

        function onPointerMove(event) {
            if (!state.drag) {
                return;
            }

            event.preventDefault();

            const pointer = getPointerPosition(editor, event);
            const deltaX = (pointer.x - state.drag.startPointer.x) / state.drag.zoom;
            const deltaY = (pointer.y - state.drag.startPointer.y) / state.drag.zoom;
            const delta = getSpacingDelta(state.drag.side, deltaX, deltaY);
            const minValue = state.drag.type === 'padding' ? 0 : undefined;
            const nextValue = clampSpacingValue(state.drag.startValues[state.drag.side] + delta, minValue);
            const nextValues = {
                ...state.drag.startValues,
                [state.drag.side]: nextValue,
            };

            state.drag.pendingValues = nextValues;

            if (!state.drag.previewFrame) {
                state.drag.previewFrame = window.requestAnimationFrame(applyDragPreview);
            }
        }

        function startDrag(event, type, side) {
            if (editor.Commands?.isActive?.('core:preview')) {
                return;
            }

            const selected = editor.getSelected?.();
            const element = selected?.getEl?.();
            if (!selected || !element) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            removeDragListeners();
            clearActiveHandles();

            const position = editor.Canvas.getElementPos?.(element);
            if (!position || !Number.isFinite(position.width) || !Number.isFinite(position.height)) {
                return;
            }

            const zoom = Math.max(toNumericValue(position?.zoom) || 1, 0.01);
            const spacing = getSpacingSnapshot(editor.Canvas.getElementOffsets?.(element));
            const handle = state.handles.get(type + ':' + side);
            const pointer = getPointerPosition(editor, event);
            const frameWindow = editor.Canvas.getWindow?.();
            const targets = [window];

            if (frameWindow && frameWindow !== window) {
                targets.push(frameWindow);
            }

            if (handle) {
                handle.classList.add('is-active');
                if (handle.setPointerCapture && event.pointerId !== undefined) {
                    handle.setPointerCapture(event.pointerId);
                }
            }

            if (state.overlay) {
                state.overlay.classList.add('is-dragging');
            }

            state.drag = {
                component: selected,
                element,
                type,
                side,
                zoom,
                pointerId: event.pointerId,
                handleEl: handle,
                startPointer: pointer,
                startOuter: {
                    left: position.left,
                    top: position.top,
                    width: position.width,
                    height: position.height,
                },
                startSpacing: spacing,
                startValues: { ...spacing[type] },
                latestValues: null,
                pendingValues: null,
                previewFrame: 0,
            };

            state.dragTargets = targets;
            targets.forEach((target) => {
                target.addEventListener('pointermove', onPointerMove, true);
                target.addEventListener('pointerup', stopDrag, true);
                target.addEventListener('pointercancel', stopDrag, true);
            });
        }

        const editorEvents = ['load', 'component:selected', 'component:deselected', 'component:update', 'component:styleUpdate', 'canvas:coords', 'canvas:zoom', 'canvas:frame:load'];
        editorEvents.forEach((eventName) => editor.on(eventName, scheduleRefresh));
        window.addEventListener('resize', scheduleRefresh);
        scheduleRefresh();

        return () => {
            state.disposed = true;
            removeDragListeners();
            clearActiveHandles();
            window.removeEventListener('resize', scheduleRefresh);
            editorEvents.forEach((eventName) => editor.off(eventName, scheduleRefresh));

            if (state.rafId) {
                window.cancelAnimationFrame(state.rafId);
                state.rafId = 0;
            }

            if (state.overlay?.parentNode) {
                state.overlay.parentNode.removeChild(state.overlay);
            }
        };
    }

    function addDefaultBlocks(editor) {
        const blockManager = editor.BlockManager;

        blockManager.add('lmz-section', {
            label: 'Section',
            category: 'Layout',
            content: '<section class="container mx-auto px-6 py-8"><div class="max-w-4xl mx-auto">Neuer Abschnitt</div></section>',
        });

        blockManager.add('lmz-two-columns', {
            label: '2 Columns',
            category: 'Layout',
            content:
                '<section class="container mx-auto px-6 py-8">' +
                '<div class="grid gap-6 md:grid-cols-2">' +
                '<div class="p-6 rounded bg-gray-100">Spalte 1</div>' +
                '<div class="p-6 rounded bg-gray-100">Spalte 2</div>' +
                '</div>' +
                '</section>',
        });

        blockManager.add('lmz-hero', {
            label: 'Hero',
            category: 'Layout',
            content:
                '<section class="py-16 bg-slate-900 text-white">' +
                '<div class="container mx-auto px-6">' +
                '<h2 class="text-4xl font-bold">Hero Titel</h2>' +
                '<p class="mt-4 text-slate-200 max-w-xl">Beschreibe hier dein Angebot mit einem kurzen Intro.</p>' +
                '<a class="inline-block mt-6 px-5 py-3 rounded bg-blue-500 hover:bg-blue-600" href="#">Call to Action</a>' +
                '</div>' +
                '</section>',
        });

        blockManager.add('lmz-text', {
            label: 'Text',
            category: 'Basic',
            content: '<p class="text-gray-700">Bearbeitbarer Textblock</p>',
        });

        blockManager.add('lmz-button', {
            label: 'Button',
            category: 'Basic',
            content: '<a href="#" class="inline-block rounded bg-blue-600 px-4 py-2 text-white">Button</a>',
        });

        blockManager.add('lmz-image', {
            label: 'Image',
            category: 'Basic',
            content: '<img src="images/l3/railtime-hero.png" alt="RailTime" class="w-full h-auto rounded" />',
        });
    }

    function normalizeAssetDefinition(asset, baseUrl) {
        if (typeof asset === 'string') {
            return { src: toPortableSiteUrl(asset, baseUrl) };
        }

        if (!isPlainObject(asset) || !asset.src) {
            return null;
        }

        return {
            ...asset,
            src: toPortableSiteUrl(String(asset.src), baseUrl),
        };
    }

    function upsertAsset(editor, asset) {
        if (!asset?.src) return null;
        const collection = editor.AssetManager?.getAll?.();
        const models = Array.isArray(collection) ? collection : Array.isArray(collection?.models) ? collection.models : [];
        const existing = models.find((model) => {
            const source = typeof model?.get === 'function' ? model.get('src') : model?.src;
            return source === asset.src;
        });

        if (existing?.set) {
            existing.set(asset);
            return existing;
        }

        return editor.AssetManager.add(asset);
    }

    async function loadAssetLibrary(editor, options) {
        let assets = null;

        if (typeof options.assets.onLoad === 'function') {
            assets = await options.assets.onLoad({ editor });
        } else if (options.endpoints.assets) {
            const response = await fetch(options.endpoints.assets, {
                credentials: options.fetch.credentials,
                headers: options.fetch.headers,
            });

            if (!response.ok) {
                throw new Error('LMZBuilder: could not load assets (status ' + response.status + ')');
            }

            assets = await parseJson(response);
        }

        if (!Array.isArray(assets)) {
            return 0;
        }

        assets.forEach((asset) => {
            const normalizedAsset = normalizeAssetDefinition(asset, options.canvasBaseUrl);
            if (normalizedAsset) upsertAsset(editor, normalizedAsset);
        });

        return assets.length;
    }

    async function uploadAssets(editor, files, options) {
        if (!files || files.length === 0) {
            return [];
        }

        if (typeof options.assets.onUpload === 'function') {
            const uploaded = await options.assets.onUpload({ editor, files });
            const normalizedUploaded = Array.isArray(uploaded)
                ? uploaded.map((asset) => normalizeAssetDefinition(asset, options.canvasBaseUrl)).filter(Boolean)
                : [];
            normalizedUploaded.forEach((asset) => upsertAsset(editor, asset));
            return normalizedUploaded;
        }

        if (!options.endpoints.upload) {
            throw new Error('LMZBuilder: missing upload endpoint');
        }

        const uploaded = [];

        for (const file of files) {
            const body = new FormData();
            body.append('file', file);

            const response = await fetch(options.endpoints.upload, {
                method: 'POST',
                body,
                credentials: options.fetch.credentials,
                headers: withCsrfHeaders(options.fetch.headers),
            });

            if (!response.ok) {
                throw new Error('LMZBuilder: upload failed (status ' + response.status + ')');
            }

            const result = await parseJson(response);
            const src = result?.url || result?.src || null;

            if (src) {
                const asset = normalizeAssetDefinition({ src }, options.canvasBaseUrl);
                upsertAsset(editor, asset);
                uploaded.push(asset);
            }
        }

        return uploaded;
    }

    function ensureCanvasBase(editor, baseUrl) {
        const documentFrame = editor?.Canvas?.getDocument?.();
        if (!documentFrame?.head) {
            return null;
        }

        const normalizedBaseUrl = normalizeCanvasBaseUrl(baseUrl);
        let baseElement = documentFrame.head.querySelector('base[data-lmz-canvas-base]');

        if (!baseElement) {
            baseElement = documentFrame.createElement('base');
            baseElement.dataset.lmzCanvasBase = 'true';
            documentFrame.head.insertBefore(baseElement, documentFrame.head.firstChild);
        }

        if (baseElement.getAttribute('href') !== normalizedBaseUrl) {
            baseElement.setAttribute('href', normalizedBaseUrl);
        }

        return normalizedBaseUrl;
    }

    function ensureCanvasStyles(editor, styles, baseUrl) {
        const normalizedBaseUrl = ensureCanvasBase(editor, baseUrl) || normalizeCanvasBaseUrl(baseUrl);
        const normalizedStyles = normalizeCanvasStyles(styles, normalizedBaseUrl);
        if (normalizedStyles.length === 0) {
            return;
        }

        const documentFrame = editor?.Canvas?.getDocument?.();
        if (!documentFrame?.head) {
            return;
        }

        const normalizeHref = (href) => toAbsoluteUrl(href, normalizedBaseUrl);
        const expectedHrefs = new Set(normalizedStyles.map(normalizeHref));

        normalizedStyles.forEach((href) => {
            const normalizedHref = normalizeHref(href);
            let link = Array.from(documentFrame.head.querySelectorAll('link[rel="stylesheet"][href]')).find(
                (candidate) => normalizeHref(candidate.getAttribute('href') || candidate.href || '') === normalizedHref
            );

            if (!link) {
                link = documentFrame.createElement('link');
                link.rel = 'stylesheet';
                link.href = normalizedHref;
                documentFrame.head.appendChild(link);
            }

            link.dataset.lmzCanvasStyle = normalizedHref;
        });

        Array.from(documentFrame.head.querySelectorAll('link[data-lmz-canvas-style]')).forEach((link) => {
            const href = normalizeHref(link.dataset.lmzCanvasStyle || link.getAttribute('href') || '');
            if (!expectedHrefs.has(href)) {
                link.remove();
            }
        });
    }

    function ensureCanvasContext(editor, bodyClasses, language, baseUrl) {
        ensureCanvasBase(editor, baseUrl);
        const documentFrame = editor?.Canvas?.getDocument?.();
        if (!documentFrame?.body) {
            return;
        }

        ensureArray(bodyClasses)
            .map((name) => String(name || '').trim())
            .filter((name) => /^[a-z0-9_-]+$/i.test(name))
            .forEach((name) => documentFrame.body.classList.add(name));

        const normalizedLanguage = String(language || '').toLowerCase();
        if (/^[a-z]{2}(?:-[a-z]{2})?$/.test(normalizedLanguage)) {
            documentFrame.documentElement.lang = normalizedLanguage;
        }

        if (documentFrame.body.classList.contains('is-home')) {
            const headquarters = Array.from(documentFrame.querySelectorAll('.rt-location')).find(
                (location) => location.querySelector('.rt-location__tooltip strong')?.textContent.trim() === 'Winsen (Luhe)'
            );

            if (headquarters) {
                const role = normalizedLanguage.startsWith('en') ? 'Headquarters' : 'Hauptsitz';
                headquarters.classList.add('is-headquarters');
                headquarters.setAttribute('aria-label', `${role}: Winsen (Luhe)`);
                const hint = headquarters.querySelector('.rt-location__tooltip small');
                if (hint) hint.textContent = role;
            }
        }
    }

    function startCanvasStyleSync(editor, styles, bodyClasses, language, baseUrl) {
        const normalizedBaseUrl = normalizeCanvasBaseUrl(baseUrl);
        const normalizedStyles = normalizeCanvasStyles(styles, normalizedBaseUrl);
        const normalizedBodyClasses = ensureArray(bodyClasses);
        if (normalizedStyles.length === 0 && normalizedBodyClasses.length === 0 && !language && !baseUrl) {
            return () => {};
        }

        let frameEl = null;
        let headObserver = null;

        const syncNow = () => {
            ensureCanvasStyles(editor, normalizedStyles, normalizedBaseUrl);
            ensureCanvasContext(editor, normalizedBodyClasses, language, normalizedBaseUrl);
        };

        const bindHeadObserver = () => {
            if (headObserver) {
                headObserver.disconnect();
                headObserver = null;
            }

            const documentFrame = editor?.Canvas?.getDocument?.();
            if (!documentFrame?.head || typeof MutationObserver === 'undefined') {
                return;
            }

            headObserver = new MutationObserver(() => {
                syncNow();
            });
            headObserver.observe(documentFrame.head, { childList: true });
        };

        const bindFrameListener = () => {
            const currentFrameEl = editor?.Canvas?.getFrameEl?.();
            if (!currentFrameEl || currentFrameEl === frameEl) {
                return;
            }

            if (frameEl) {
                frameEl.removeEventListener('load', onFrameReady);
            }

            frameEl = currentFrameEl;
            frameEl.addEventListener('load', onFrameReady);
        };

        const onFrameReady = () => {
            syncNow();
            bindHeadObserver();
        };

        editor.on('load', onFrameReady);
        editor.on('canvas:frame:load', onFrameReady);

        bindFrameListener();
        onFrameReady();

        const intervalId = window.setInterval(() => {
            bindFrameListener();
            syncNow();
        }, CANVAS_STYLE_SYNC_INTERVAL_MS);

        return () => {
            window.clearInterval(intervalId);
            editor.off('load', onFrameReady);
            editor.off('canvas:frame:load', onFrameReady);
            if (frameEl) {
                frameEl.removeEventListener('load', onFrameReady);
            }
            if (headObserver) {
                headObserver.disconnect();
                headObserver = null;
            }
        };
    }

    const MOTION_TRAIT_CATEGORY = { id: 'lmz-motion', label: 'Motion', open: true };
    const MOTION_TRAITS = [
        {
            type: 'select',
            name: 'data-lmz-motion',
            label: 'Animation',
            category: MOTION_TRAIT_CATEGORY,
            options: [
                { id: '', name: 'Keine / Legacy-Automatik' },
                { id: 'fade-up', name: 'Von unten einblenden' },
                { id: 'fade-down', name: 'Von oben einblenden' },
                { id: 'fade-left', name: 'Von links einblenden' },
                { id: 'fade-right', name: 'Von rechts einblenden' },
                { id: 'scale', name: 'Skalieren' },
                { id: 'reveal', name: 'Masken-Reveal' },
                { id: 'parallax', name: 'Parallax' },
                { id: 'split-lines', name: 'Text zeilenweise aufdecken' },
                { id: 'none', name: 'Explizit deaktiviert' },
            ],
        },
        { type: 'number', name: 'data-lmz-duration', label: 'Dauer (s)', category: MOTION_TRAIT_CATEGORY, placeholder: '0.9', attributes: { min: 0.1, max: 5, step: 0.05 } },
        { type: 'number', name: 'data-lmz-delay', label: 'Verzögerung (s)', category: MOTION_TRAIT_CATEGORY, placeholder: '0', attributes: { min: 0, max: 5, step: 0.05 } },
        { type: 'number', name: 'data-lmz-distance', label: 'Distanz (px / %)', category: MOTION_TRAIT_CATEGORY, placeholder: '48', attributes: { min: 0, max: 300, step: 1 } },
        { type: 'number', name: 'data-lmz-scale', label: 'Start-Skalierung', category: MOTION_TRAIT_CATEGORY, placeholder: '0.92', attributes: { min: 0.5, max: 1.5, step: 0.01 } },
        { type: 'number', name: 'data-lmz-stagger', label: 'Kinder-Staffelung (s)', category: MOTION_TRAIT_CATEGORY, placeholder: '0', attributes: { min: 0, max: 1, step: 0.01 } },
        {
            type: 'select',
            name: 'data-lmz-ease',
            label: 'Easing',
            category: MOTION_TRAIT_CATEGORY,
            options: [{ id: '', name: 'Standard (power3.out)' }, ...['power3.out', 'power2.out', 'power4.out', 'power2.inOut', 'power3.inOut', 'sine.inOut', 'back.out(1.4)', 'none'].map((id) => ({ id, name: id }))],
        },
        {
            type: 'select',
            name: 'data-lmz-start',
            label: 'Scroll-Start',
            category: MOTION_TRAIT_CATEGORY,
            options: [{ id: '', name: 'Standard (top 86%)' }, ...['top bottom', 'top 92%', 'top 90%', 'top 88%', 'top 86%', 'top 80%', 'top 70%', 'center bottom', 'center center'].map((id) => ({ id, name: id }))],
        },
        {
            type: 'select',
            name: 'data-lmz-end',
            label: 'Scroll-Ende',
            category: MOTION_TRAIT_CATEGORY,
            options: [{ id: '', name: 'Standard (bottom 20%)' }, ...['bottom top', 'bottom 20%', 'center top', '+=240', '+=400', '+=620', '+=800'].map((id) => ({ id, name: id }))],
        },
        {
            type: 'select',
            name: 'data-lmz-scrub',
            label: 'Scroll-Scrub',
            category: MOTION_TRAIT_CATEGORY,
            options: [
                { id: '', name: 'Aus (Standard)' },
                { id: 'false', name: 'Aus' },
                { id: '0.25', name: 'Direkt (0.25)' },
                { id: '0.5', name: 'Sanft (0.5)' },
                { id: '0.8', name: 'Weich (0.8)' },
                { id: '1.2', name: 'Träge (1.2)' },
                { id: '2', name: 'Sehr träge (2)' },
            ],
        },
        {
            type: 'select',
            name: 'data-lmz-reverse',
            label: 'Beim Zurückscrollen umkehren',
            category: MOTION_TRAIT_CATEGORY,
            options: [
                { id: '', name: 'Ja (Standard)' },
                { id: 'true', name: 'Ja' },
                { id: 'false', name: 'Nein' },
            ],
        },
        { type: 'checkbox', name: 'data-lmz-once', label: 'Nur einmal abspielen', category: MOTION_TRAIT_CATEGORY, valueTrue: 'true', valueFalse: 'false' },
    ];

    function getChildComponents(component) {
        const collection = component?.components?.();
        if (!collection) return [];
        if (Array.isArray(collection)) return collection;
        if (Array.isArray(collection.models)) return collection.models;

        const output = [];
        if (typeof collection.each === 'function') {
            collection.each((child) => output.push(child));
        }
        return output;
    }

    function addMotionTraits(component) {
        if (!component || component.get?.('type') === 'textnode' || component.get?.('type') === 'wrapper') {
            return;
        }

        const traits = component.get?.('traits');
        MOTION_TRAITS.forEach((definition, index) => {
            // getTrait() initialisiert die Trait-Collection lazy und loest
            // dabei selbst ein component:update aus. Innerhalb des frueheren
            // update-Listeners entstand dadurch eine rekursive Renderkette.
            const existing = Array.isArray(traits)
                ? traits.some((trait) => trait?.name === definition.name || trait?.get?.('name') === definition.name)
                : traits?.findWhere?.({ name: definition.name });
            if (!existing) {
                component.addTrait?.({ ...definition }, { at: index, silent: true });
            }
        });
    }

    function getEmbedCatalogItems(integrations, kind) {
        const catalog = isPlainObject(integrations?.embedCatalog) ? integrations.embedCatalog : {};
        const key = kind === 'article' ? 'articles' : kind === 'module' ? 'modules' : kind === 'smartslider' ? 'smartsliders' : '';
        return key && Array.isArray(catalog[key]) ? catalog[key] : [];
    }

    function getEmbedItemLabel(item) {
        const title = String(item?.title || item?.name || ('ID ' + item?.id)).trim();
        const context = item?.category_title || item?.position || item?.module || '';
        return context ? title + ' · ' + String(context).trim() : title;
    }

    function getSharedElementItems(integrations) {
        const language = String(integrations?.sharedLanguage || '').trim();
        const currentElementId = Number(integrations?.currentElementId) || 0;
        return Array.isArray(integrations?.sharedElements)
            ? integrations.sharedElements.filter((item) => {
                const key = String(item?.element_key || '').trim();
                const variant = language ? item?.variants?.[language] : null;
                return String(item?.kind || '') === 'global'
                    && Number.isInteger(Number(item?.id))
                    && Number(item.id) > 0
                    && Number(item.id) !== currentElementId
                    && Number(item?.state) === 1
                    && (!language || Number(variant?.state) === 1)
                    && /^[a-z0-9][a-z0-9._-]{0,99}$/i.test(key);
            })
            : [];
    }

    function getSharedElementLabel(item) {
        const title = String(item?.title || item?.element_key || ('Element ' + item?.id)).trim();
        const languageCount = Array.isArray(item?.translations) ? item.translations.length : 0;
        const state = Number(item?.state) === 1 ? 'veröffentlicht' : 'nicht veröffentlicht';
        return `${title} · ${languageCount} Sprachen · ${state}`;
    }

    function addIntegrationTraits(component, integrations) {
        if (!component || component.get?.('type') === 'textnode' || component.get?.('type') === 'wrapper') {
            return;
        }

        const attributes = component.getAttributes?.() || {};
        const definitions = [];
        if (attributes['data-lmz-embed-kind'] !== undefined) {
            const embedCategory = { id: 'lmz-embed', label: 'Joomla-Embed', open: true };
            const kind = String(attributes['data-lmz-embed-kind'] || 'article');
            const catalogItems = getEmbedCatalogItems(integrations, kind)
                .filter((item) => Number.isInteger(Number(item?.id)) && Number(item.id) > 0);
            const idTrait = catalogItems.length
                ? {
                    type: 'select',
                    name: 'data-lmz-embed-id',
                    label: 'Element',
                    category: embedCategory,
                    options: [
                        { id: '', name: 'Bitte auswählen' },
                        ...catalogItems.map((item) => ({ id: String(Number(item.id)), name: getEmbedItemLabel(item) })),
                    ],
                }
                : { type: 'text', name: 'data-lmz-embed-id', label: 'Element-ID', category: embedCategory, placeholder: 'ID auswählen' };
            definitions.push(
                {
                    type: 'select',
                    name: 'data-lmz-embed-kind',
                    label: 'Inhaltstyp',
                    category: embedCategory,
                    options: [
                        { id: 'article', name: 'Joomla-Beitrag' },
                        { id: 'module', name: 'Joomla-Modul' },
                        { id: 'smartslider', name: 'Smart Slider 3' },
                    ],
                },
                idTrait
            );
        }

        if (attributes['data-lmz-shared-element'] !== undefined) {
            const sharedCategory = { id: 'lmz-shared', label: 'Globales Element', open: true };
            const sharedItems = getSharedElementItems(integrations);
            const elementTrait = sharedItems.length
                ? {
                    type: 'select',
                    name: 'data-lmz-shared-element',
                    label: 'Gespeichertes Element',
                    category: sharedCategory,
                    options: [
                        { id: '', name: 'Bitte auswählen' },
                        ...sharedItems.map((item) => ({ id: String(item.element_key), name: getSharedElementLabel(item) })),
                    ],
                }
                : {
                    type: 'text',
                    name: 'data-lmz-shared-element',
                    label: 'Element-Schlüssel',
                    category: sharedCategory,
                    placeholder: 'Noch keine gespeicherten Elemente vorhanden',
                };
            definitions.push(
                elementTrait,
                {
                    type: 'select',
                    name: 'data-lmz-shared-language',
                    label: 'Sprachauflösung',
                    category: sharedCategory,
                    options: [
                        { id: 'current', name: 'Aktuelle Sprache' },
                    ],
                }
            );
        }

        definitions.forEach((definition) => {
            const existing = component.getTrait?.(definition.name)
                || component.get?.('traits')?.findWhere?.({ name: definition.name });
            if (!existing) {
                component.addTrait?.({ ...definition }, { silent: true });
                return;
            }

            const catalogTrait = definition.name === 'data-lmz-embed-id' || definition.name === 'data-lmz-shared-element';
            if (catalogTrait && existing.get?.('type') !== definition.type) {
                component.removeTrait?.(definition.name, { silent: true });
                component.addTrait?.({ ...definition }, { silent: true });
                return;
            }

            if (catalogTrait && Array.isArray(definition.options)) {
                const previous = existing.get?.('options') || [];
                if (JSON.stringify(previous) !== JSON.stringify(definition.options)) {
                    existing.set?.('options', definition.options, { silent: true });
                }
            }
        });
    }

    function setupMotionTraits(editor, integrations, motionOptions) {
        const motionSettings = isPlainObject(motionOptions) ? motionOptions : {};
        const motionEnabled = motionOptions !== false && motionSettings.enabled !== false;
        const integrationsEnabled = integrations !== false;

        // Mail-Dokumente schalten Motion und Joomla-Integrationen bewusst ab.
        // Ohne diesen Guard wurden trotzdem fuer jede verschachtelte
        // table/tr/td/img-Komponente alle Motion-Traits erzeugt. GrapesJS
        // rendert den TraitManager dabei synchron und kann den Browser-Thread
        // bei grossen E-Mail-Tabellen fuer mehrere Sekunden blockieren.
        if (!motionEnabled && !integrationsEnabled) {
            return () => {};
        }

        const hydrateTraits = (component) => {
            if (motionEnabled) addMotionTraits(component);
            if (integrationsEnabled) addIntegrationTraits(component, integrations);
        };
        const visit = (component) => {
            hydrateTraits(component);
            getChildComponents(component).forEach(visit);
        };
        const refreshAll = () => visit(editor.getWrapper?.());
        const onCreate = (component) => hydrateTraits(component);
        const onSelected = (component) => hydrateTraits(component);

        editor.on('component:create', onCreate);
        editor.on('component:selected', onSelected);
        editor.on('load', refreshAll);
        refreshAll();

        return () => {
            editor.off('component:create', onCreate);
            editor.off('component:selected', onSelected);
            editor.off('load', refreshAll);
        };
    }

    function setupSharedElementPreview(editor, integrations) {
        // Der Mailmodus setzt integrations=false. Dort existieren keine
        // Joomla-Shared-Elemente; ohne den Guard wuerde dennoch jedes
        // component:create/add/update einen rekursiven Preview-Refresh planen.
        if (integrations === false) {
            return () => {};
        }

        const language = String(integrations?.sharedLanguage || '').trim();
        const previewItems = getSharedElementItems(integrations).reduce((items, item) => {
            const key = String(item?.element_key || '').trim();
            const variant = language ? item?.variants?.[language] : null;
            const html = String(variant?.published_html || '').trim();
            if (key && html && Number(variant?.state) === 1 && Number(item?.state) === 1) {
                items.set(key, {
                    html,
                    css: String(variant?.published_css || '').trim(),
                });
            }
            return items;
        }, new Map());
        const markerSelector = '[data-lmz-shared-element]';
        let refreshFrame = 0;

        const hasModelContent = (component) => getChildComponents(component).some((child) => {
            if (child?.get?.('type') !== 'textnode') return true;
            return String(child.get?.('content') || child.get?.('text') || '').trim() !== '';
        });
        const clearPreview = (element) => {
            if (!element) return;
            Array.from(element.children || []).forEach((child) => {
                if (child.hasAttribute?.('data-lmz-shared-preview-root')
                    || child.hasAttribute?.('data-lmz-shared-preview-style')) {
                    child.remove();
                }
            });
        };
        const safePreviewFragment = (documentRef, html) => {
            const template = documentRef.createElement('template');
            template.innerHTML = html;
            template.content.querySelectorAll('script, object, embed, base, meta[http-equiv]').forEach((node) => node.remove());
            template.content.querySelectorAll('*').forEach((node) => {
                Array.from(node.attributes || []).forEach((attribute) => {
                    const name = attribute.name.toLowerCase();
                    const value = String(attribute.value || '').trim().toLowerCase();
                    if (name.startsWith('on') || ((name === 'href' || name === 'src') && value.startsWith('javascript:'))) {
                        node.removeAttribute(attribute.name);
                    }
                });
            });
            return template.content;
        };
        const hydrateDomMarker = (marker, visited) => {
            const key = String(marker?.getAttribute?.('data-lmz-shared-element') || '').trim();
            const item = previewItems.get(key);
            if (!item || visited.has(key)) return;

            const nextVisited = new Set(visited);
            nextVisited.add(key);
            const documentRef = marker.ownerDocument;
            if (item.css) {
                const style = documentRef.createElement('style');
                style.dataset.lmzSharedPreviewStyle = key;
                style.textContent = item.css;
                marker.appendChild(style);
            }
            const preview = documentRef.createElement('div');
            preview.dataset.lmzSharedPreviewRoot = key;
            preview.appendChild(safePreviewFragment(documentRef, item.html));
            marker.appendChild(preview);
            preview.querySelectorAll(markerSelector).forEach((nestedMarker) => {
                if (!nestedMarker.children.length) hydrateDomMarker(nestedMarker, nextVisited);
            });
        };
        const refreshComponent = (component) => {
            if (!component || component.get?.('type') === 'textnode') return;
            const attributes = component.getAttributes?.() || {};
            if (attributes['data-lmz-shared-element'] === undefined) return;
            const element = component.getEl?.();
            if (!element) return;
            clearPreview(element);
            if (hasModelContent(component)) return;
            hydrateDomMarker(element, new Set());
        };
        const visit = (component) => {
            refreshComponent(component);
            getChildComponents(component).forEach(visit);
        };
        const refreshAll = () => {
            window.cancelAnimationFrame(refreshFrame);
            refreshFrame = window.requestAnimationFrame(() => visit(editor.getWrapper?.()));
        };
        const onComponentChange = (component) => {
            refreshComponent(component);
            refreshAll();
        };

        editor.on('load', refreshAll);
        editor.on('canvas:frame:load', refreshAll);
        editor.on('component:create', onComponentChange);
        editor.on('component:add', onComponentChange);
        editor.on('component:update', onComponentChange);
        refreshAll();

        return () => {
            window.cancelAnimationFrame(refreshFrame);
            editor.off('load', refreshAll);
            editor.off('canvas:frame:load', refreshAll);
            editor.off('component:create', onComponentChange);
            editor.off('component:add', onComponentChange);
            editor.off('component:update', onComponentChange);
            const wrapper = editor.getWrapper?.();
            if (wrapper) {
                const clear = (component) => {
                    clearPreview(component.getEl?.());
                    getChildComponents(component).forEach(clear);
                };
                clear(wrapper);
            }
        };
    }

    function setupMotionEditor(editor, refs, motionOptions, trustedCanvasScripts) {
        const settings = isPlainObject(motionOptions) ? motionOptions : {};
        const enabled = motionOptions !== false && settings.enabled !== false;
        const previewButton = refs.shell.querySelector('[data-lmz-action="motion-preview"]');
        const pauseButton = refs.shell.querySelector('[data-lmz-action="motion-pause"]');
        const restartButton = refs.shell.querySelector('[data-lmz-action="motion-restart"]');

        // Ein deaktivierter Mail-Modus benoetigt weder die zehn Editor-Events
        // noch einen Debounce-Timer pro Komponenten-Aenderung. Die API-Form
        // bleibt erhalten, damit aufrufender Code keinen Sonderfall braucht.
        if (!enabled) {
            if (refs.motionState) {
                refs.motionState.dataset.state = 'unavailable';
                refs.motionState.textContent = 'Nicht konfiguriert';
            }
            [previewButton, pauseButton, restartButton].forEach((button) => {
                if (button) button.disabled = true;
            });

            return {
                refresh() {},
                restart() {},
                pause() {},
                resume() {},
                setEnabled() {},
                getState() {
                    return { enabled: false, previewEnabled: false, paused: false, available: false };
                },
                destroy() {},
            };
        }

        const debounceMs = Math.max(40, Number(settings.debounceMs) || MOTION_REFRESH_DEBOUNCE_MS);
        const editorEvents = [
            'load',
            'canvas:frame:load',
            'component:add',
            'component:remove',
            'component:update',
            'component:styleUpdate',
            'component:deselected',
            'rte:disable',
            'undo',
            'redo',
        ];
        let controller = null;
        let timer = null;
        let disposed = false;
        let previewEnabled = enabled && settings.preview !== false;
        let paused = false;
        let rebuildToken = 0;
        let runtimeDocument = null;
        let runtimeLoadPromise = null;

        const loadTrustedScript = (frameDocument, src) => new Promise((resolve) => {
            const existing = Array.from(frameDocument.scripts)
                .find((script) => script.dataset.lmzMotionRuntimeSrc === src);
            if (existing?.dataset.lmzMotionRuntimeState === 'loaded') {
                resolve(true);
                return;
            }
            if (existing) {
                existing.addEventListener('load', () => resolve(true), { once: true });
                existing.addEventListener('error', () => resolve(false), { once: true });
                return;
            }

            const script = frameDocument.createElement('script');
            script.src = src;
            script.async = false;
            script.dataset.lmzMotionRuntimeSrc = src;
            script.dataset.lmzMotionRuntimeState = 'loading';
            script.addEventListener('load', () => {
                script.dataset.lmzMotionRuntimeState = 'loaded';
                resolve(true);
            }, { once: true });
            script.addEventListener('error', () => {
                script.dataset.lmzMotionRuntimeState = 'failed';
                resolve(false);
            }, { once: true });
            (frameDocument.head || frameDocument.documentElement).appendChild(script);
        });

        const ensureMotionRuntime = (frameWindow, frameDocument) => {
            if (frameWindow?.LMZMotionRuntime?.mount && frameWindow.gsap && frameWindow.ScrollTrigger) {
                return Promise.resolve(true);
            }
            if (runtimeDocument !== frameDocument) {
                runtimeDocument = frameDocument;
                runtimeLoadPromise = null;
            }
            if (!runtimeLoadPromise) {
                runtimeLoadPromise = (async () => {
                    for (const src of ensureArray(trustedCanvasScripts)) {
                        if (!await loadTrustedScript(frameDocument, src)) return false;
                    }
                    return Boolean(frameWindow?.LMZMotionRuntime?.mount && frameWindow.gsap && frameWindow.ScrollTrigger);
                })();
            }

            return runtimeLoadPromise;
        };

        const renderState = (state, label) => {
            if (refs.motionState) {
                refs.motionState.dataset.state = state;
                refs.motionState.textContent = label;
            }
            previewButton?.classList.toggle('is-active', previewEnabled);
            pauseButton?.classList.toggle('is-active', paused);
            if (previewButton) previewButton.disabled = !enabled;
            if (pauseButton) pauseButton.disabled = !previewEnabled || !controller?.available;
            if (restartButton) restartButton.disabled = !previewEnabled || !controller?.available;
        };

        const destroyController = () => {
            const finalizeDestroy = controller?.destroy?.();
            finalizeDestroy?.();
            controller = null;
        };

        const rebuild = async (token) => {
            if (disposed || token !== rebuildToken) return;
            destroyController();

            if (!previewEnabled) {
                renderState(enabled ? 'disabled' : 'unavailable', enabled ? 'Motion aus' : 'Nicht konfiguriert');
                return;
            }

            const frameWindow = editor.Canvas?.getWindow?.();
            const frameDocument = editor.Canvas?.getDocument?.();
            if (!frameWindow || !frameDocument?.body) {
                renderState('unavailable', 'Runtime fehlt');
                return;
            }

            renderState('loading', 'Motion lädt');
            const runtimeReady = await ensureMotionRuntime(frameWindow, frameDocument);
            if (disposed || token !== rebuildToken) return;
            const runtime = frameWindow.LMZMotionRuntime;
            if (!runtimeReady || !runtime?.mount) {
                renderState('unavailable', 'Runtime fehlt');
                return;
            }

            controller = runtime.mount({
                window: frameWindow,
                document: frameDocument,
                root: frameDocument.body,
                enabled: true,
                editorMode: true,
                excludeElements: [editor.getSelected?.()?.getEl?.()].filter(Boolean),
                respectReducedMotion: settings.respectReducedMotion !== false,
            });

            if (controller.reducedMotion) {
                renderState('reduced', 'Reduziert');
            } else if (!controller.available) {
                renderState('unavailable', 'GSAP fehlt');
            } else if (paused) {
                controller.pause?.();
                renderState('paused', 'Pausiert');
            } else {
                renderState('playing', 'Motion aktiv');
            }
        };

        const schedule = () => {
            if (disposed) return;
            window.clearTimeout(timer);
            const token = ++rebuildToken;
            timer = window.setTimeout(() => rebuild(token), debounceMs);
        };

        const suspendEditableTarget = () => {
            if (disposed) return;
            window.clearTimeout(timer);
            rebuildToken += 1;
            destroyController();
            schedule();
        };

        const togglePreview = () => {
            if (!enabled) return;
            previewEnabled = !previewEnabled;
            if (previewEnabled) {
                schedule();
            } else {
                window.clearTimeout(timer);
                rebuildToken += 1;
                destroyController();
                paused = false;
                renderState('disabled', 'Motion aus');
            }
        };

        const togglePause = () => {
            if (!controller?.available) return;
            paused = !paused;
            if (paused) controller.pause?.();
            else controller.resume?.();
            renderState(paused ? 'paused' : 'playing', paused ? 'Pausiert' : 'Motion aktiv');
        };

        const restart = () => {
            if (!controller?.available) {
                schedule();
                return;
            }
            paused = false;
            controller.restart?.();
            renderState('playing', 'Neu gestartet');
        };

        previewButton?.addEventListener('click', togglePreview);
        pauseButton?.addEventListener('click', togglePause);
        restartButton?.addEventListener('click', restart);
        editor.on('component:selected', suspendEditableTarget);
        editor.on('rte:enable', suspendEditableTarget);
        editorEvents.forEach((eventName) => editor.on(eventName, schedule));
        renderState(enabled ? 'loading' : 'unavailable', enabled ? 'Motion lädt' : 'Nicht konfiguriert');
        schedule();

        return {
            refresh: schedule,
            restart,
            pause() {
                if (!paused) togglePause();
            },
            resume() {
                if (paused) togglePause();
            },
            setEnabled(nextEnabled) {
                previewEnabled = enabled && Boolean(nextEnabled);
                schedule();
            },
            getState() {
                return { enabled, previewEnabled, paused, available: Boolean(controller?.available) };
            },
            destroy() {
                disposed = true;
                window.clearTimeout(timer);
                rebuildToken += 1;
                destroyController();
                previewButton?.removeEventListener('click', togglePreview);
                pauseButton?.removeEventListener('click', togglePause);
                restartButton?.removeEventListener('click', restart);
                editor.off('component:selected', suspendEditableTarget);
                editor.off('rte:enable', suspendEditableTarget);
                editorEvents.forEach((eventName) => editor.off(eventName, schedule));
            },
        };
    }

    function waitForNextPaint() {
        return new Promise((resolve) => {
            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(resolve);
            });
        });
    }

    async function create(userOptions) {
        const options = deepMerge(DEFAULT_OPTIONS, userOptions || {});
        const configuredCanvasBaseUrl = normalizeCanvasBaseUrl(options.canvasBaseUrl);
        const configuredCanvasStyles = normalizeCanvasStyles([
            ...(Array.isArray(options.canvasStyles) ? options.canvasStyles : []),
            ...(Array.isArray(options.gjsOptions?.canvas?.styles) ? options.gjsOptions.canvas.styles : []),
        ], configuredCanvasBaseUrl);
        const configuredCanvasScripts = normalizeCanvasScripts([
            ...(Array.isArray(options.canvasScripts) ? options.canvasScripts : []),
            ...(Array.isArray(options.gjsOptions?.canvas?.scripts) ? options.gjsOptions.canvas.scripts : []),
        ], configuredCanvasBaseUrl);
        const configuredCanvasBodyClasses = ensureArray(options.canvasBodyClasses);
        const configuredCanvasLanguage = String(options.canvasLanguage || '');
        const root = getRootElement(options.root);
        const runtimeExtensions = resolveRuntimeExtensions(root);
        const useStudioWebDefaults =
            typeof runtimeExtensions.useStudioWebDefaults === 'boolean'
                ? runtimeExtensions.useStudioWebDefaults
                : options.useStudioWebDefaults !== false;
        const configuredPluginSpecs = normalizePluginSpecs([
            ...(useStudioWebDefaults ? STUDIO_WEB_DEFAULT_PLUGIN_SPECS : []),
            ...ensureArray(options.pluginSpecs),
            ...runtimeExtensions.pluginSpecs,
        ]);
        const configuredPluginOptionsBase = deepMerge(options.pluginsOpts || {}, runtimeExtensions.pluginOptions || {});
        const configuredCustomBlocks = [...ensureArray(options.blocks?.custom), ...runtimeExtensions.customBlocks];
        const refs = createShell(root);
        const grapesjs = await ensureGrapesJs(options);
        const resolvedPluginSpecs = await resolvePluginSpecs(grapesjs, configuredPluginSpecs);
        const configuredPlugins = uniquePlugins([
            ...ensureArray(options.plugins),
            ...runtimeExtensions.plugins,
            ...resolvedPluginSpecs.plugins,
        ]);
        const configuredPluginOptions = deepMerge(configuredPluginOptionsBase, resolvedPluginSpecs.pluginOptions || {});
        setStatus(refs, 'Editor wird gestartet...', 'muted');

        const grapesOptions = deepMerge(
            {
                container: refs.canvas,
                height: '100%',
                width: 'auto',
                stylePrefix: 'lmzbjs-',
                fromElement: false,
                noticeOnUnload: false,
                showOffsets: false,
                showOffsetsSelected: false,
                storageManager: false,
                panels: { defaults: [] },
                blockManager: { appendTo: refs.blocks },
                layerManager: { appendTo: refs.layers },
                selectorManager: { componentFirst: true, appendTo: refs.classes },
                styleManager: deepMerge({ appendTo: refs.styles, custom: false }, options.styleManager),
                traitManager: { appendTo: refs.traits },
                canvas: { styles: configuredCanvasStyles, scripts: configuredCanvasScripts },
                plugins: configuredPlugins,
                pluginsOpts: configuredPluginOptions,
            },
            options.gjsOptions || {}
        );

        grapesOptions.canvas = deepMerge({ styles: configuredCanvasStyles, scripts: configuredCanvasScripts }, grapesOptions.canvas || {});
        grapesOptions.canvas.styles = configuredCanvasStyles;
        grapesOptions.canvas.scripts = configuredCanvasScripts;
        grapesOptions.parser = deepMerge({ optionsHtml: { allowScripts: false } }, grapesOptions.parser || {});
        grapesOptions.parser.optionsHtml = deepMerge({ allowScripts: false }, grapesOptions.parser.optionsHtml || {});
        grapesOptions.parser.optionsHtml.allowScripts = false;

        const editor = grapesjs.init(grapesOptions);
        const canvasStyles = configuredCanvasStyles;
        dedupeClassManagerUis(refs.classes || refs.styles);

        if (options.blocks.addDefault && editor.BlockManager.getAll().length === 0) {
            addDefaultBlocks(editor);
        }

        if (configuredCustomBlocks.length) {
            configuredCustomBlocks.forEach((block) => {
                if (block?.id && block?.definition) {
                    editor.BlockManager.add(block.id, block.definition);
                }
            });
        }

        runtimeExtensions.blockRegistrars.forEach((registrar, index) => {
            if (typeof registrar !== 'function') {
                return;
            }

            try {
                registrar(editor);
            } catch (error) {
                console.error('LMZBuilder: blockRegistrar fehlgeschlagen (Index ' + index + ')', error);
            }
        });

        const stopMotionTraits = setupMotionTraits(editor, options.integrations, options.motion);
        const stopSharedElementPreview = setupSharedElementPreview(editor, options.integrations);
        const motionEditor = setupMotionEditor(editor, refs, options.motion, configuredCanvasScripts);

        const state = {
            dirtyCount: 0,
            changeVersion: 0,
            loading: true,
            saveChain: Promise.resolve(true),
            autosavePromise: null,
            manualSavePending: 0,
            autosaveTimer: null,
            actionLocked: false,
            destroyed: false,
        };

        let api = null;
        let stopCanvasStyleSync = () => {};
        let stopSpacingEditor = () => {};

        const saveOnce = async (reason) => {
            if (state.destroyed) return { success: false, stable: false };

            const versionAtStart = state.changeVersion;
            const dirtyAtStart = state.dirtyCount;
            setStatus(refs, reason === 'manual' ? 'Speichern...' : 'Autosave...', 'muted');

            try {
                const projectData = editor.getProjectData();
                const html = editor.getHtml();
                const css = editor.getCss();
                const shouldGeneratePreviews = reason === 'manual' || reason === 'shortcut';

                if (typeof options.storage.onSave === 'function') {
                    await options.storage.onSave({
                        project: projectData,
                        html,
                        css,
                        generatePreviews: shouldGeneratePreviews,
                        reason,
                        editor,
                        projectId: options.projectId,
                    });
                } else if (options.endpoints.save) {
                    const body = new FormData();
                    if (options.projectId !== null && options.projectId !== undefined && options.projectId !== '') {
                        body.append('id', String(options.projectId));
                    }
                    body.append('data', JSON.stringify(projectData));
                    body.append('html', '<style>' + css + '</style>' + html);
                    if (shouldGeneratePreviews) {
                        body.append('generate_previews', '1');
                    }

                    const response = await fetch(options.endpoints.save, {
                        method: 'POST',
                        body,
                        credentials: options.fetch.credentials,
                        headers: withCsrfHeaders(options.fetch.headers),
                    });

                    if (!response.ok) {
                        throw new Error('LMZBuilder: save failed (status ' + response.status + ')');
                    }
                }

                const stable = state.changeVersion === versionAtStart;
                if (stable) {
                    state.dirtyCount = 0;
                    if (typeof editor.clearDirtyCount === 'function') {
                        editor.clearDirtyCount();
                    }
                } else {
                    state.dirtyCount = Math.max(1, state.dirtyCount - dirtyAtStart);
                }

                const now = new Date();
                setStatus(refs, 'Gespeichert ' + now.toLocaleTimeString(), 'success');
                return { success: true, stable };
            } catch (error) {
                console.error(error);
                setStatus(refs, 'Speichern fehlgeschlagen', 'error');
                return { success: false, stable: false };
            } finally {
                if (typeof syncEditorShell === 'function') {
                    syncEditorShell();
                }
            }
        };

        const performSave = async (reason) => {
            const flushUntilStable = reason === 'manual' || reason === 'shortcut';
            const maxAttempts = flushUntilStable ? 5 : 1;

            for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
                const result = await saveOnce(reason);
                if (!result.success) return false;
                if (result.stable || !flushUntilStable) return true;
            }

            setStatus(refs, 'Während des Speicherns gab es weitere Änderungen', 'error');
            return false;
        };

        const saveProject = (reason) => {
            const isAutosave = String(reason || '').startsWith('autosave');
            if (state.actionLocked && reason !== 'manual') {
                return state.saveChain;
            }
            if (isAutosave && state.autosavePromise) {
                return state.autosavePromise;
            }
            if (isAutosave && state.manualSavePending > 0) {
                return state.saveChain;
            }

            const queuedSave = state.saveChain.then(
                () => performSave(reason),
                () => performSave(reason)
            );

            if (!isAutosave) {
                state.manualSavePending += 1;
                const trackedManualSave = queuedSave.finally(() => {
                    state.manualSavePending = Math.max(0, state.manualSavePending - 1);
                });
                state.saveChain = trackedManualSave;

                return trackedManualSave;
            }

            state.saveChain = queuedSave;

            let trackedAutosave = null;
            const completeAutosave = (successful) => {
                if (state.autosavePromise !== trackedAutosave) {
                    return;
                }

                state.autosavePromise = null;
                if (successful && !state.destroyed && !state.actionLocked && state.manualSavePending === 0 && state.dirtyCount > 0) {
                    // A change that happened while the request was in flight stays
                    // dirty. Queue at most one follow-up instead of one request per
                    // GrapesJS update event during drags and style adjustments.
                    window.queueMicrotask(() => {
                        if (!state.destroyed && !state.actionLocked && !state.autosavePromise && state.dirtyCount > 0) {
                            saveProject('autosave-follow-up');
                        }
                    });
                }
            };
            trackedAutosave = queuedSave.then(
                (result) => {
                    completeAutosave(result === true);
                    return result;
                },
                (error) => {
                    console.error(error);
                    completeAutosave(false);
                    return false;
                }
            );
            state.autosavePromise = trackedAutosave;

            return trackedAutosave;
        };

        const loadProjectIntoEditor = (projectData) => {
            editor.loadProjectData(projectData);
            ensureCanvasStyles(editor, canvasStyles, configuredCanvasBaseUrl);
            ensureCanvasContext(editor, configuredCanvasBodyClasses, configuredCanvasLanguage, configuredCanvasBaseUrl);
            window.setTimeout(() => {
                selectInitialComponent(editor);
                dedupeClassManagerUis(refs.classes || refs.styles);
            }, 0);
        };

        const loadProject = async () => {
            setStatus(refs, 'Projekt wird geladen...', 'muted');

            try {
                let payload = null;

                if (typeof options.storage.onLoad === 'function') {
                    payload = await options.storage.onLoad({ editor, projectId: options.projectId });
                } else if (options.endpoints.load && options.projectId) {
                    const url = resolveEndpoint(options.endpoints.load, options.projectId);
                    const response = await fetch(url, {
                        credentials: options.fetch.credentials,
                        headers: options.fetch.headers,
                    });

                    if (!response.ok) {
                        throw new Error('LMZBuilder: load failed (status ' + response.status + ')');
                    }

                    payload = await parseJson(response);
                }

                const projectData = normalizeProjectPayload(payload) || DEFAULT_PROJECT_DATA;
                loadProjectIntoEditor(projectData);

                if (typeof editor.clearDirtyCount === 'function') {
                    editor.clearDirtyCount();
                }
                state.dirtyCount = 0;
                state.changeVersion = 0;
                setStatus(refs, 'Projekt geladen', 'success');
            } catch (error) {
                console.error(error);
                if (options.allowFallbackProject === true) {
                    loadProjectIntoEditor(DEFAULT_PROJECT_DATA);
                    setStatus(refs, 'Fallback-Projekt geladen', 'error');
                    return;
                }

                setStatus(refs, 'Projekt konnte nicht geladen werden', 'error');
                throw error;
            }
        };

        api = {
            editor,
            motion: motionEditor,
            save(reason) {
                return saveProject(reason || 'manual');
            },
            hasUnsavedChanges() {
                return state.dirtyCount > 0 || Boolean(state.autosavePromise) || state.manualSavePending > 0;
            },
            setActionLocked(locked) {
                state.actionLocked = Boolean(locked);
            },
            async load() {
                await loadProject();
                // Nur loadProjectData() erzeugt die internen Dokumentupdates,
                // die nicht als Benutzerbearbeitung zaehlen duerfen. Die
                // Medienbibliothek laedt danach asynchron; das bereits
                // sichtbare Dokument bleibt waehrenddessen editierbar und
                // jede echte Aenderung muss Dirty-State/Autosave erreichen.
                state.loading = false;
                try {
                    await loadAssetLibrary(editor, options);
                } catch (error) {
                    console.error(error);
                    setStatus(refs, 'Medien konnten nicht geladen werden: ' + (error?.message || 'Unbekannter Fehler'), 'error');
                }
            },
            async openAssets() {
                setStatus(refs, 'Medienbibliothek wird geladen...', 'muted');
                try {
                    editor.runCommand('open-assets');
                    await loadAssetLibrary(editor, options);
                    editor.Modal?.setTitle?.('Medienbibliothek');
                    setStatus(refs, 'Medienbibliothek geöffnet', 'success');
                    return true;
                } catch (error) {
                    console.error(error);
                    editor.Modal?.setTitle?.('Medienbibliothek · Laden fehlgeschlagen');
                    setStatus(refs, 'Medienbibliothek nicht verfügbar: ' + (error?.message || 'Zugriff verweigert'), 'error');
                    throw error;
                }
            },
            async upload(files) {
                return uploadAssets(editor, files, options);
            },
            destroy() {
                if (state.destroyed) return;
                state.destroyed = true;
                stopCanvasStyleSync();
                stopSpacingEditor();
                motionEditor.destroy();
                stopSharedElementPreview();
                stopMotionTraits();
                refs.panels?.destroy?.();
                if (state.autosaveTimer) {
                    window.clearInterval(state.autosaveTimer);
                }
                editor.destroy();
                root.innerHTML = '';
                root.classList.remove('lmz-builder-host');
            },
        };

        const syncEditorShell = () => syncShellState(editor, refs);

        refs.shell.querySelector('[data-lmz-action="undo"]').addEventListener('click', () => {
            editor.runCommand('core:undo');
            window.requestAnimationFrame(syncEditorShell);
        });

        refs.shell.querySelector('[data-lmz-action="redo"]').addEventListener('click', () => {
            editor.runCommand('core:redo');
            window.requestAnimationFrame(syncEditorShell);
        });
        refs.shell.querySelector('[data-lmz-action="save"]').addEventListener('click', () => api.save('manual'));
        refs.shell.querySelector('[data-lmz-action="assets"]').addEventListener('click', () => {
            api.openAssets().catch(() => {});
        });
        refs.shell.querySelector('[data-lmz-action="upload"]').addEventListener('click', () => refs.uploadInput.click());
        refs.selection.addEventListener('click', () => {
            refs.panels.toggle(editor.getSelected() ? 'right:traits' : 'right:styles');
        });
        refs.shell.querySelector('[data-lmz-action="preview"]').addEventListener('click', () => {
            if (editor.Commands.isActive('core:preview')) {
                editor.stopCommand('core:preview');
            } else {
                editor.runCommand('core:preview');
            }

            window.requestAnimationFrame(syncEditorShell);
        });

        refs.uploadInput.addEventListener('change', async (event) => {
            const files = Array.from(event.target.files || []);
            if (!files.length) {
                return;
            }

            setStatus(refs, 'Upload...', 'muted');
            try {
                await api.upload(files);
                setStatus(refs, 'Assets hochgeladen', 'success');
                editor.runCommand('open-assets');
            } catch (error) {
                console.error(error);
                editor.Modal?.setTitle?.('Medienbibliothek · Upload fehlgeschlagen');
                setStatus(refs, 'Upload fehlgeschlagen: ' + (error?.message || 'Zugriff verweigert'), 'error');
            } finally {
                refs.uploadInput.value = '';
            }
        });

        editor.on('update', () => {
            // loadProjectData() emittiert zahlreiche interne Updates. Sie sind
            // keine Benutzeraktion und duerfen weder Shell-Arbeit noch einen
            // Autosave ausloesen, waehrend die Instanz noch startet.
            if (state.loading || state.destroyed) return;
            state.dirtyCount += 1;
            state.changeVersion += 1;
            syncEditorShell();
            if (options.autosave.enabled && state.dirtyCount >= options.autosave.changesBeforeSave) {
                api.save('autosave-threshold');
            }
        });

        editor.on('component:selected', () => {
            if (!refs.panels.getActive('right')) {
                refs.panels.open('right:styles');
            }
        });

        ['load', 'component:selected', 'component:deselected', 'component:update', 'component:styleUpdate', 'undo', 'redo'].forEach((eventName) => {
            editor.on(eventName, syncEditorShell);
        });

        stopCanvasStyleSync = startCanvasStyleSync(
            editor,
            canvasStyles,
            configuredCanvasBodyClasses,
            configuredCanvasLanguage,
            configuredCanvasBaseUrl
        );
        stopSpacingEditor = setupSpacingEditor(editor);

        if (options.autosave.enabled) {
            state.autosaveTimer = window.setInterval(() => {
                if (!state.actionLocked && state.dirtyCount > 0) {
                    api.save('autosave-interval');
                }
            }, options.autosave.intervalMs);
        }

        try {
            editor.Keymaps.add('lmz-save', '⌘+s, ctrl+s', () => {
                api.save('shortcut');
                return false;
            });
        } catch (error) {
            console.warn('LMZBuilder: save keymap could not be registered');
        }

        try {
            await api.load();
            if (state.destroyed) {
                throw new Error('LMZBuilder: initialization cancelled');
            }
            ensureCanvasStyles(editor, canvasStyles, configuredCanvasBaseUrl);
            ensureCanvasContext(editor, configuredCanvasBodyClasses, configuredCanvasLanguage, configuredCanvasBaseUrl);
            syncEditorShell();

            return api;
        } catch (error) {
            // create() darf nie eine halbfertige GrapesJS-Instanz samt
            // Intervallen und Listenern zuruecklassen. Sonst wird jeder
            // weitere Oeffnungsversuch langsamer als der vorherige.
            try {
                api.destroy();
            } catch (destroyError) {
                console.error('LMZBuilder: cleanup after failed initialization failed', destroyError);
            }
            throw error;
        }
    }

    async function initFromStudioNode() {
        const root = document.getElementById('studio-editor');
        if (!root) {
            if (window.__lmzBuilderInstance) {
                window.__lmzBuilderInstance.destroy();
                window.__lmzBuilderInstance = null;
                window.__lmzBuilderProjectId = null;
            }
            return null;
        }

        const projectId = root.getAttribute('data-project') || null;

        if (
            window.__lmzBuilderInstance &&
            window.__lmzBuilderProjectId === projectId &&
            window.__lmzBuilderRoot === root
        ) {
            return window.__lmzBuilderInstance;
        }

        if (window.__lmzBuilderInstance) {
            window.__lmzBuilderInstance.destroy();
            window.__lmzBuilderInstance = null;
        }

        window.__lmzBuilderInstance = await create({
            root,
            projectId,
            canvasStyles: DEFAULT_CANVAS_STYLES,
            endpoints: {
                load: '/administrator/pagebuilder/load/:id',
                save: '/administrator/pagebuilder/save',
                assets: '/administrator/pagebuilder/assets',
                upload: '/administrator/pagebuilder/upload',
            },
        });

        window.__lmzBuilderProjectId = projectId;
        window.__lmzBuilderRoot = root;
        return window.__lmzBuilderInstance;
    }

    window.LMZBuilder = {
        create,
        version: '2.4.5-joomla+railtime.20260904.1',
        assetVersion: (() => {
            try {
                const currentScriptUrl = new URL(document.currentScript?.src || '', window.location.href);
                return currentScriptUrl.searchParams.get('runtime') || currentScriptUrl.searchParams.get('v') || '';
            } catch (_) {
                return '';
            }
        })(),
    };
    window.initLMZBuilder = initFromStudioNode;
})();
