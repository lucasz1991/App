import QRCode from 'qrcode';

export const MARKETING_ARTBOARDS = Object.freeze({
    story: Object.freeze({ label: 'Story', width: 1080, height: 1920 }),
    post: Object.freeze({ label: 'Post', width: 1080, height: 1080 }),
    web: Object.freeze({ label: 'Web', width: 1200, height: 630 }),
});

const VENDOR_RUNTIME_KEY = '2.4.5';
const SAFE_ZONE_STYLE_ID = 'rt-marketing-safe-zone-style';
const MARKETING_CATEGORY = 'RailTime Marketing';
const BLOCK_SURFACE = 'background:#fff;color:#172033;font-family:Arial,sans-serif;';
const EMPTY_QR_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

let vendorRuntimePromise = null;

export function createStudioBootGuard() {
    let activeStudio = null;
    let activeWorkspace = null;
    let bootPromise = null;
    let bootSequence = 0;

    return {
        async boot(workspace, createStudio, isCurrent = () => true) {
            if (!workspace) {
                this.destroy();

                return null;
            }

            if (activeWorkspace === workspace && (bootPromise || activeStudio)) {
                return bootPromise || activeStudio;
            }

            this.destroy();
            activeWorkspace = workspace;
            const sequence = bootSequence;
            const pending = Promise.resolve()
                .then(createStudio)
                .then((studio) => {
                    if (sequence !== bootSequence || !isCurrent()) {
                        studio?.destroy?.();

                        return null;
                    }

                    activeStudio = studio;

                    return studio;
                });

            bootPromise = pending;

            try {
                return await pending;
            } finally {
                if (bootPromise === pending) {
                    bootPromise = null;
                    if (!activeStudio) activeWorkspace = null;
                }
            }
        },

        destroy() {
            bootSequence += 1;
            bootPromise = null;
            activeWorkspace = null;
            activeStudio?.destroy?.();
            activeStudio = null;
        },

        getActive() {
            return activeStudio;
        },
    };
}

const studioBootGuard = createStudioBootGuard();

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function dispatchToast(type, text, title = '') {
    window.dispatchEvent(new CustomEvent('swal:toast', {
        detail: { type, text, title: title || undefined },
    }));
}

export function replaceEndpointToken(template, token, value) {
    return String(template || '').replace(token, encodeURIComponent(String(value)));
}

export function resolveArtboard(format) {
    return MARKETING_ARTBOARDS[format] || MARKETING_ARTBOARDS.story;
}

function readJsonScript(workspace) {
    const script = workspace?.querySelector('[data-marketing-editor-config]');

    if (!script) {
        throw new Error('Marketing-Editor-Konfiguration fehlt.');
    }

    return JSON.parse(script.textContent || '{}');
}

function loadStyleOnce(href) {
    if (!href || document.querySelector(`link[data-rt-marketing-style="${href}"]`)) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.dataset.rtMarketingStyle = href;
        link.addEventListener('load', resolve, { once: true });
        link.addEventListener('error', () => reject(new Error(`Stylesheet konnte nicht geladen werden: ${href}`)), { once: true });
        document.head.appendChild(link);
    });
}

function loadScriptOnce(src) {
    if (!src) {
        return Promise.reject(new Error('Builder-Script fehlt.'));
    }

    const existing = Array.from(document.scripts).find((script) => script.src === new URL(src, window.location.href).href);
    if (existing?.dataset.rtLoaded === 'true') {
        return Promise.resolve();
    }

    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', resolve, { once: true });
            existing.addEventListener('error', reject, { once: true });
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.defer = true;
        script.dataset.rtMarketingRuntime = VENDOR_RUNTIME_KEY;
        script.addEventListener('load', () => {
            script.dataset.rtLoaded = 'true';
            resolve();
        }, { once: true });
        script.addEventListener('error', () => reject(new Error(`Script konnte nicht geladen werden: ${src}`)), { once: true });
        document.head.appendChild(script);
    });
}

async function ensureVendorRuntime(vendor) {
    if (window.LMZBuilder?.create) {
        return window.LMZBuilder;
    }

    if (!vendorRuntimePromise) {
        vendorRuntimePromise = Promise.all([
            loadStyleOnce(vendor.grapesCss),
            loadStyleOnce(vendor.builderCss),
        ])
            .then(() => loadScriptOnce(vendor.builderJs))
            .then(() => {
                if (!window.LMZBuilder?.create) {
                    throw new Error('LMZ Page Builder 2.4.5 wurde nicht initialisiert.');
                }

                return window.LMZBuilder;
            })
            .catch((error) => {
                vendorRuntimePromise = null;
                throw error;
            });
    }

    return vendorRuntimePromise;
}

async function requestJson(url, {
    method = 'GET',
    json = null,
    formData = null,
    signal = null,
} = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = csrfToken();

    if (token) {
        headers['X-CSRF-TOKEN'] = token;
    }

    if (json !== null) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers,
        body: formData || (json !== null ? JSON.stringify(json) : null),
        signal,
    });

    let payload = {};
    try {
        payload = await response.json();
    } catch (_) {
        payload = {};
    }

    if (!response.ok) {
        const validationMessage = payload.errors
            ? Object.values(payload.errors).flat().filter(Boolean)[0]
            : null;
        const error = new Error(validationMessage || payload.message || `Anfrage fehlgeschlagen (${response.status}).`);
        error.status = response.status;
        error.payload = payload;
        throw error;
    }

    return payload;
}

function htmlEscape(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

export async function createMarketingBlocks(
    logoLightUrl = '/rt-brand/img/logo-horizontal.png',
    logoDarkUrl = '/rt-brand/img/logo-horizontal-darkbg.png',
    ctaUrl = 'https://www.rail-time.de/de/karriere',
) {
    const qrValue = String(ctaUrl || '').trim();
    const qrDataUrl = qrValue
        ? await QRCode.toDataURL(qrValue, {
            errorCorrectionLevel: 'M',
            margin: 1,
            width: 360,
            color: {
                dark: '#071a2c',
                light: '#ffffff',
            },
        })
        : EMPTY_QR_DATA_URL;
    const qrAlt = qrValue ? 'QR-Code zur Bewerbung' : 'Kein QR-Code: Zieladresse fehlt';
    const list = (key, title) => (
        `<section data-rt-block="${key}" style="${BLOCK_SURFACE}padding:32px">`
        + `<h2 style="margin:0 0 18px;font-size:26px;font-weight:800;text-transform:uppercase">${title}</h2>`
        + `<ul data-rt-binding-list="${key}" style="display:grid;gap:10px;margin:0;padding-left:22px"><li>Inhalt ergänzen</li></ul>`
        + '</section>'
    );

    return [
        {
            id: 'rt-marketing-logo-light',
            definition: {
                label: 'RailTime-Logo · heller Hintergrund',
                category: MARKETING_CATEGORY,
                content: `<div data-rt-block="logo-light" data-rt-logo-surface="light" style="padding:24px;background:#fff"><img src="${htmlEscape(logoLightUrl)}" width="2114" height="365" alt="RT Rail Time GmbH" style="display:block;width:360px;max-width:100%;height:auto"></div>`,
            },
        },
        {
            id: 'rt-marketing-logo-dark',
            definition: {
                label: 'RailTime-Logo · dunkler Hintergrund',
                category: MARKETING_CATEGORY,
                content: `<div data-rt-block="logo-dark" data-rt-logo-surface="dark" style="padding:24px;background:#102237"><img src="${htmlEscape(logoDarkUrl)}" width="2114" height="365" alt="RT Rail Time GmbH" style="display:block;width:360px;max-width:100%;height:auto"></div>`,
            },
        },
        {
            id: 'rt-marketing-hero',
            definition: {
                label: 'Hero-Bild',
                category: MARKETING_CATEGORY,
                content: '<figure data-rt-block="hero" style="height:520px;margin:0;background:#dbe2ea;overflow:hidden"><img src="/rt-brand/img/hero-railtime.jpg" alt="RailTime im Bahnbetrieb" style="display:block;width:100%;height:100%;object-fit:cover"></figure>',
            },
        },
        {
            id: 'rt-marketing-kicker',
            definition: {
                label: 'Kicker',
                category: MARKETING_CATEGORY,
                content: `<p data-rt-block="kicker" data-rt-binding="kicker" style="${BLOCK_SURFACE}margin:0;color:#e4002b;font-size:22px;font-weight:800;letter-spacing:.12em;text-transform:uppercase">Wir suchen</p>`,
            },
        },
        {
            id: 'rt-marketing-headline',
            definition: {
                label: 'Überschrift',
                category: MARKETING_CATEGORY,
                content: `<h1 data-rt-block="headline" data-rt-binding="title" style="${BLOCK_SURFACE}margin:0;font-size:76px;line-height:.95;font-weight:900;text-transform:uppercase">Wagenmeister (m/w/d)</h1>`,
            },
        },
        {
            id: 'rt-marketing-facts',
            definition: {
                label: 'Faktenleiste',
                category: MARKETING_CATEGORY,
                content: `<div data-rt-block="facts" data-rt-binding-facts="facts" style="display:flex;flex-wrap:wrap;gap:12px;background:#071a2c;padding:24px;color:#fff;font:800 19px Arial,sans-serif"><div><strong>60+</strong><span>Wagenmeister</span></div><div><strong>24/7</strong><span>Einsatzbereitschaft</span></div><div><strong>DE</strong><span>deutschlandweit</span></div></div>`,
            },
        },
        { id: 'rt-marketing-tasks', definition: { label: 'Aufgaben', category: MARKETING_CATEGORY, content: list('tasks', 'Deine Aufgaben') } },
        { id: 'rt-marketing-profile', definition: { label: 'Profil', category: MARKETING_CATEGORY, content: list('profile', 'Dein Profil') } },
        { id: 'rt-marketing-benefits', definition: { label: 'Vorteile', category: MARKETING_CATEGORY, content: list('benefits', 'Was dich erwartet') } },
        {
            id: 'rt-marketing-contact',
            definition: {
                label: 'Kontakt',
                category: MARKETING_CATEGORY,
                content: `<address data-rt-block="contact" style="${BLOCK_SURFACE}display:grid;gap:8px;padding:28px;font-style:normal"><strong data-rt-binding="company_name">RT Rail Time GmbH</strong><span data-rt-binding="contact_phone">04171 546803</span><span data-rt-binding="contact_email">info@rail-time.de</span><span data-rt-binding="website">www.rail-time.de</span></address>`,
            },
        },
        {
            id: 'rt-marketing-cta',
            definition: {
                label: 'CTA',
                category: MARKETING_CATEGORY,
                content: '<a data-rt-block="cta" data-rt-binding="cta_label" data-rt-binding-href="cta_url" href="https://www.rail-time.de/de/karriere" style="display:inline-block;background:#e4002b;color:#fff;padding:18px 28px;font:800 20px Arial,sans-serif;text-decoration:none">Jetzt bewerben</a>',
            },
        },
        {
            id: 'rt-marketing-qr',
            definition: {
                label: 'QR-Code',
                category: MARKETING_CATEGORY,
                content: `<img data-rt-block="qr" data-rt-qr-binding="cta_url" data-rt-qr-value="${htmlEscape(qrValue)}" src="${qrDataUrl}" alt="${qrAlt}" style="display:block;width:180px;height:180px;object-fit:contain;background:#fff;padding:8px;box-shadow:0 0 0 1px #d7e0ea">`,
            },
        },
    ];
}

function assetPayload(asset) {
    return {
        public_id: asset.public_id,
        src: asset.url || asset.src,
        name: asset.original_name || asset.name,
        type: asset.mime_type || asset.type,
        width: asset.width || null,
        height: asset.height || null,
    };
}

async function uploadFiles(files, config) {
    const uploaded = [];

    for (const file of Array.from(files || [])) {
        const body = new FormData();
        body.append('file', file, file.name);
        const payload = await requestJson(config.endpoints.assetUpload, {
            method: 'POST',
            formData: body,
        });

        if (payload.asset) {
            uploaded.push(assetPayload(payload.asset));
        }
    }

    return uploaded;
}

export function serializeSharedData(data) {
    const content = {};

    for (const [name, rawValue] of data.entries()) {
        const field = /^shared_content\[([^\]]+)\]((?:\[[^\]]*\])*)$/.exec(name);
        if (!field) continue;

        const parts = [
            field[1],
            ...Array.from(field[2].matchAll(/\[([^\]]*)\]/g), (match) => match[1])
                .filter((part) => part !== ''),
        ];

        const value = String(rawValue).trim();

        if (parts.length === 1 && name.endsWith('[]')) {
            content[parts[0]] ||= [];
            if (value !== '') content[parts[0]].push(value);
            continue;
        }

        let target = content;
        parts.forEach((part, index) => {
            const last = index === parts.length - 1;
            if (last) {
                target[part] = value;
                return;
            }

            const nextIsIndex = /^\d+$/.test(parts[index + 1]);
            target[part] ||= nextIsIndex ? [] : {};
            target = target[part];
        });
    }

    Object.entries(content).forEach(([key, value]) => {
        if (!Array.isArray(value)) return;

        content[key] = value.filter((item) => {
            if (typeof item === 'string') return item !== '';
            if (item && typeof item === 'object') {
                return Object.values(item).some((field) => String(field || '').trim() !== '');
            }
            return false;
        });
    });

    if (Array.isArray(content.facts)) {
        content.facts = content.facts.map((fact) => ({
            value: String(fact?.value || '').trim(),
            label: String(fact?.label || '').trim(),
        }));
    }

    return {
        title: String(data.get('title') || '').trim(),
        shared_content: content,
    };
}

function serializeSharedForm(form) {
    return serializeSharedData(new FormData(form));
}

export async function syncQrCode(editor, value) {
    const wrapper = editor?.DomComponents?.getWrapper?.();
    const qrValue = String(value || '').trim();
    const components = wrapper?.find?.('[data-rt-qr-binding="cta_url"]') || [];
    const stale = components.filter((component) => component.getAttributes?.()['data-rt-qr-value'] !== qrValue);
    if (stale.length === 0) return false;

    if (!qrValue) {
        stale.forEach((component) => {
            component.addAttributes({
                src: EMPTY_QR_DATA_URL,
                'data-rt-qr-value': '',
                alt: 'Kein QR-Code: Zieladresse fehlt',
            });
        });

        return true;
    }

    const qrDataUrl = await QRCode.toDataURL(qrValue, {
        errorCorrectionLevel: 'M',
        margin: 1,
        width: 360,
        color: { dark: '#071a2c', light: '#ffffff' },
    });
    stale.forEach((component) => {
        component.addAttributes({
            src: qrDataUrl,
            'data-rt-qr-value': qrValue,
            alt: 'QR-Code zur Bewerbung',
        });
    });

    return true;
}

async function syncBoundContent(editor, content) {
    const wrapper = editor?.DomComponents?.getWrapper?.();
    if (!wrapper) return;

    Object.entries(content || {}).forEach(([key, value]) => {
        const components = wrapper.find?.(`[data-rt-binding="${key}"]`) || [];

        components.forEach((component) => {
            component.components(htmlEscape(value));
        });

        (wrapper.find?.(`[data-rt-binding-list="${key}"]`) || []).forEach((component) => {
            const values = Array.isArray(value) ? value : [];
            component.components(values.map((item) => `<li>${htmlEscape(item)}</li>`).join(''));
        });

        (wrapper.find?.(`[data-rt-binding-facts="${key}"]`) || []).forEach((component) => {
            const facts = Array.isArray(value) ? value : [];
            component.components(facts.map((fact) => (
                `<div><strong>${htmlEscape(fact?.value)}</strong><span>${htmlEscape(fact?.label)}</span></div>`
            )).join(''));
        });

        for (const attribute of ['href', 'src']) {
            (wrapper.find?.(`[data-rt-binding-${attribute}="${key}"]`) || []).forEach((component) => {
                component.addAttributes({ [attribute]: String(value || '') });
            });
        }
    });

    await syncQrCode(editor, content?.cta_url);
}

function safeZoneStyle() {
    return `
        html.rt-marketing-safe-zone body::after {
            content: '';
            position: fixed;
            inset: 5%;
            z-index: 2147483646;
            border: 3px dashed rgba(228, 0, 43, .75);
            box-shadow: 0 0 0 1px rgba(255, 255, 255, .75);
            pointer-events: none;
        }
    `;
}

function applySafeZone(instance, enabled) {
    const documentFrame = instance?.editor?.Canvas?.getDocument?.();
    if (!documentFrame) return;

    let style = documentFrame.getElementById(SAFE_ZONE_STYLE_ID);
    if (!style) {
        style = documentFrame.createElement('style');
        style.id = SAFE_ZONE_STYLE_ID;
        style.textContent = safeZoneStyle();
        documentFrame.head?.appendChild(style);
    }

    documentFrame.documentElement.classList.toggle('rt-marketing-safe-zone', Boolean(enabled));
}

export function calculateArtboardGeometry({
    format = 'story',
    hostWidth = 0,
    hostHeight = 0,
    zoom = 'fit',
    inset = 28,
} = {}) {
    const artboard = resolveArtboard(format);
    const normalizedInset = Math.max(0, Number.isFinite(Number(inset)) ? Number(inset) : 0);
    const normalizedHostWidth = Math.max(0, Number.isFinite(Number(hostWidth)) ? Number(hostWidth) : 0);
    const normalizedHostHeight = Math.max(0, Number.isFinite(Number(hostHeight)) ? Number(hostHeight) : 0);
    const availableWidth = Math.max(0, normalizedHostWidth - (normalizedInset * 2));
    const availableHeight = Math.max(0, normalizedHostHeight - (normalizedInset * 2));
    const numericZoom = typeof zoom === 'string' && zoom.trim() === '' ? Number.NaN : Number(zoom);
    const fit = zoom === 'fit' || !Number.isFinite(numericZoom);
    let scale;

    if (fit) {
        const constraints = [1];
        if (availableWidth > 0) constraints.push(availableWidth / artboard.width);
        if (availableHeight > 0) constraints.push(availableHeight / artboard.height);
        scale = Math.max(0.0001, Math.min(...constraints));
    } else {
        scale = Math.max(0.01, Math.min(1, numericZoom / 100));
    }

    return Object.freeze({
        format: MARKETING_ARTBOARDS[format] ? format : 'story',
        mode: fit ? 'fit' : 'fixed',
        logicalWidth: artboard.width,
        logicalHeight: artboard.height,
        hostWidth: normalizedHostWidth,
        hostHeight: normalizedHostHeight,
        availableWidth,
        availableHeight,
        scale,
        zoom: scale * 100,
        displayWidth: artboard.width * scale,
        displayHeight: artboard.height * scale,
    });
}

export function createArtboardViewportController({
    instance,
    frame,
    format = 'story',
    zoom = 'fit',
    inset = 28,
    onChange = null,
    environment = {},
} = {}) {
    const editor = instance?.editor;
    if (!editor || !frame) {
        throw new TypeError('Artboard-Viewport benötigt eine LMZ-Editorinstanz und einen Host.');
    }

    const browserWindow = typeof window !== 'undefined' ? window : null;
    const requestFrame = environment.requestAnimationFrame
        || browserWindow?.requestAnimationFrame?.bind(browserWindow)
        || null;
    const cancelFrame = environment.cancelAnimationFrame
        || browserWindow?.cancelAnimationFrame?.bind(browserWindow)
        || null;
    const ResizeObserverClass = Object.prototype.hasOwnProperty.call(environment, 'ResizeObserver')
        ? environment.ResizeObserver
        : (browserWindow?.ResizeObserver || globalThis.ResizeObserver);
    const artboard = resolveArtboard(format);
    const deviceId = `rt-marketing-${MARKETING_ARTBOARDS[format] ? format : 'story'}`;
    let zoomMode = zoom;
    let observedHost = null;
    let scheduledFrame = null;
    let destroyed = false;
    let latestGeometry = null;
    let controller = null;

    const existingDevice = editor.DeviceManager?.get?.(deviceId);
    const deviceAttributes = {
        id: deviceId,
        name: artboard.label,
        width: `${artboard.width}px`,
        height: `${artboard.height}px`,
    };
    if (existingDevice?.set) {
        existingDevice.set(deviceAttributes);
    } else if (!existingDevice) {
        editor.DeviceManager?.add?.(deviceId, deviceAttributes);
    }
    editor.setDevice?.(deviceId);

    const logicalWidth = `${artboard.width}px`;
    const logicalHeight = `${artboard.height}px`;
    frame.dataset.format = MARKETING_ARTBOARDS[format] ? format : 'story';
    frame.dataset.logicalWidth = String(artboard.width);
    frame.dataset.logicalHeight = String(artboard.height);
    frame.style?.setProperty?.('--rt-marketing-artboard-width', logicalWidth);
    frame.style?.setProperty?.('--rt-marketing-artboard-height', logicalHeight);
    frame.style?.setProperty?.('--rt-marketing-logical-width', logicalWidth);
    frame.style?.setProperty?.('--rt-marketing-logical-height', logicalHeight);

    const resolveHost = () => frame.querySelector?.('.lmz-builder__main') || frame;
    const observer = typeof ResizeObserverClass === 'function'
        ? new ResizeObserverClass(() => controller?.refresh())
        : null;

    const observeCurrentHost = () => {
        const host = resolveHost();
        if (host === observedHost) return host;

        if (observedHost) observer?.disconnect?.();
        observer?.observe?.(host);
        observedHost = host;

        return host;
    };

    const applyGeometry = () => {
        scheduledFrame = null;
        if (destroyed) return;

        const host = observeCurrentHost();
        const measuredWidth = Number(host?.clientWidth) > 0 ? host.clientWidth : frame.clientWidth;
        const measuredHeight = Number(host?.clientHeight) > 0 ? host.clientHeight : frame.clientHeight;
        latestGeometry = calculateArtboardGeometry({
            format,
            hostWidth: measuredWidth,
            hostHeight: measuredHeight,
            zoom: zoomMode,
            inset,
        });
        editor.Canvas?.setZoom?.(latestGeometry.zoom);
        frame.dataset.zoomMode = latestGeometry.mode;
        frame.dataset.editorZoom = String(latestGeometry.zoom);
        frame.dataset.displayWidth = String(latestGeometry.displayWidth);
        frame.dataset.displayHeight = String(latestGeometry.displayHeight);
        frame.style?.setProperty?.('--rt-marketing-scale', String(latestGeometry.scale));
        frame.style?.setProperty?.('--rt-marketing-zoom-percent', `${latestGeometry.zoom}%`);
        frame.style?.setProperty?.('--rt-marketing-display-width', `${latestGeometry.displayWidth}px`);
        frame.style?.setProperty?.('--rt-marketing-display-height', `${latestGeometry.displayHeight}px`);
        onChange?.(latestGeometry);
    };

    const onFrameLoad = () => controller?.refresh();
    controller = {
        refresh() {
            if (destroyed) return;

            observeCurrentHost();
            if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);
            if (requestFrame) {
                scheduledFrame = requestFrame(applyGeometry);
            } else {
                applyGeometry();
            }
        },

        setZoom(nextZoom) {
            zoomMode = nextZoom;
            this.refresh();
        },

        getGeometry() {
            return latestGeometry;
        },

        destroy() {
            if (destroyed) return;
            destroyed = true;
            if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);
            scheduledFrame = null;
            observer?.disconnect?.();
            observedHost = null;
            editor.off?.('canvas:frame:load', onFrameLoad);
        },
    };

    editor.on?.('canvas:frame:load', onFrameLoad);
    controller.refresh();

    return controller;
}

export function createFixedArtboardPanController({
    instance,
    frame,
    hint = null,
    threshold = 8,
    environment = {},
} = {}) {
    const editor = instance?.editor;
    if (!editor || !frame) {
        throw new TypeError('Touch-Panning benötigt eine LMZ-Editorinstanz und einen Host.');
    }

    const browserWindow = typeof window !== 'undefined' ? window : null;
    const supportsPointerEvents = Object.prototype.hasOwnProperty.call(environment, 'supportsPointerEvents')
        ? Boolean(environment.supportsPointerEvents)
        : Boolean(browserWindow?.PointerEvent);
    const touchCapable = Object.prototype.hasOwnProperty.call(environment, 'touchCapable')
        ? Boolean(environment.touchCapable)
        : Boolean(
            (browserWindow?.navigator?.maxTouchPoints || 0) > 0
            || browserWindow?.matchMedia?.('(pointer: coarse)')?.matches,
        );
    const queueTask = environment.queueMicrotask
        || browserWindow?.queueMicrotask?.bind(browserWindow)
        || globalThis.queueMicrotask?.bind(globalThis)
        || ((callback) => Promise.resolve().then(callback));
    const movementThreshold = Math.max(1, Number(threshold) || 8);
    const listenerOptions = { capture: true, passive: false };
    const bindings = [];
    let shell = null;
    let enabled = false;
    let destroyed = false;
    let bindGeneration = 0;
    let gesture = null;

    frame.dataset.touchPanning = 'false';
    frame.dataset.touchPanningActive = 'false';
    if (hint) hint.hidden = true;

    const canStart = () => enabled
        && frame.dataset.zoomMode === 'fixed'
        && shell
        && (shell.scrollWidth > shell.clientWidth || shell.scrollHeight > shell.clientHeight);

    const preventPanDefaults = (event, stopPropagation = true) => {
        if (event?.cancelable !== false) event?.preventDefault?.();
        if (stopPropagation) event?.stopPropagation?.();
    };

    const finishGesture = (event = null) => {
        if (!gesture) return;
        const finished = gesture;
        gesture = null;
        frame.dataset.touchPanningActive = 'false';
        if (finished.panning) preventPanDefaults(event, false);
        try {
            finished.captureTarget?.releasePointerCapture?.(finished.id);
        } catch {
            // The iframe may have been replaced while a pointer was active.
        }
    };

    const beginGesture = ({ id, pointerType, clientX, clientY, captureTarget }) => {
        if (gesture || !canStart() || !['touch', 'pen'].includes(pointerType)) return;

        gesture = {
            id,
            pointerType,
            startX: Number(clientX) || 0,
            startY: Number(clientY) || 0,
            startScrollLeft: Number(shell.scrollLeft) || 0,
            startScrollTop: Number(shell.scrollTop) || 0,
            captureTarget,
            panning: false,
        };
        try {
            captureTarget?.setPointerCapture?.(id);
        } catch {
            // Pointer capture is optional inside embedded documents.
        }
    };

    const moveGesture = ({ id, clientX, clientY, event }) => {
        if (!gesture || gesture.id !== id || !canStart()) return;

        const deltaX = (Number(clientX) || 0) - gesture.startX;
        const deltaY = (Number(clientY) || 0) - gesture.startY;
        if (!gesture.panning && Math.hypot(deltaX, deltaY) < movementThreshold) return;

        gesture.panning = true;
        frame.dataset.touchPanningActive = 'true';
        const maxScrollLeft = Math.max(0, shell.scrollWidth - shell.clientWidth);
        const maxScrollTop = Math.max(0, shell.scrollHeight - shell.clientHeight);
        shell.scrollLeft = Math.max(0, Math.min(maxScrollLeft, gesture.startScrollLeft - deltaX));
        shell.scrollTop = Math.max(0, Math.min(maxScrollTop, gesture.startScrollTop - deltaY));
        preventPanDefaults(event);
    };

    const pointerDown = (event) => beginGesture({
        id: event.pointerId,
        pointerType: event.pointerType,
        clientX: event.clientX,
        clientY: event.clientY,
        captureTarget: event.target,
    });
    const pointerMove = (event) => moveGesture({
        id: event.pointerId,
        clientX: event.clientX,
        clientY: event.clientY,
        event,
    });
    const pointerEnd = (event) => {
        if (gesture?.id !== event.pointerId) return;
        finishGesture(event);
    };

    const findTouch = (touches, identifier) => Array.from(touches || [])
        .find((touch) => touch.identifier === identifier);
    const touchStart = (event) => {
        if ((event.touches?.length || 0) !== 1) return;
        const touch = event.touches[0];
        beginGesture({
            id: touch.identifier,
            pointerType: 'touch',
            clientX: touch.clientX,
            clientY: touch.clientY,
            captureTarget: null,
        });
    };
    const touchMove = (event) => {
        const touch = findTouch(event.touches, gesture?.id);
        if (!touch) return;
        moveGesture({
            id: touch.identifier,
            clientX: touch.clientX,
            clientY: touch.clientY,
            event,
        });
    };
    const touchEnd = (event) => {
        if (!gesture || !findTouch(event.changedTouches, gesture.id)) return;
        finishGesture(event);
    };

    const addBinding = (target, type, listener) => {
        if (!target?.addEventListener) return;
        target.addEventListener(type, listener, listenerOptions);
        bindings.push({ target, type, listener });
    };

    const detachTargets = () => {
        finishGesture();
        bindings.splice(0).forEach(({ target, type, listener }) => {
            target.removeEventListener?.(type, listener, listenerOptions);
        });
        shell = null;
    };

    const bindTargets = () => {
        if (destroyed) return;
        detachTargets();
        shell = frame.querySelector?.('.lmz-builder__canvas-shell') || null;
        if (!shell) return;

        let canvasDocument = null;
        try {
            canvasDocument = editor.Canvas?.getDocument?.() || null;
        } catch {
            canvasDocument = null;
        }
        const targets = [...new Set([shell, canvasDocument].filter(Boolean))];
        targets.forEach((target) => {
            if (supportsPointerEvents) {
                addBinding(target, 'pointerdown', pointerDown);
                addBinding(target, 'pointermove', pointerMove);
                addBinding(target, 'pointerup', pointerEnd);
                addBinding(target, 'pointercancel', pointerEnd);
                addBinding(target, 'lostpointercapture', pointerEnd);
            } else {
                addBinding(target, 'touchstart', touchStart);
                addBinding(target, 'touchmove', touchMove);
                addBinding(target, 'touchend', touchEnd);
                addBinding(target, 'touchcancel', touchEnd);
            }
        });
    };

    const onFrameLoad = () => {
        const generation = ++bindGeneration;
        queueTask(() => {
            if (!destroyed && generation === bindGeneration) bindTargets();
        });
    };

    editor.on?.('canvas:frame:load', onFrameLoad);
    bindTargets();

    return {
        setEnabled(nextEnabled) {
            const next = Boolean(nextEnabled)
                && touchCapable
                && frame.dataset.zoomMode === 'fixed';
            if (enabled === next) return;
            enabled = next;
            frame.dataset.touchPanning = enabled ? 'true' : 'false';
            if (hint) hint.hidden = !enabled;
            if (!enabled) finishGesture();
        },

        refresh: bindTargets,

        destroy() {
            if (destroyed) return;
            destroyed = true;
            bindGeneration += 1;
            enabled = false;
            frame.dataset.touchPanning = 'false';
            frame.dataset.touchPanningActive = 'false';
            if (hint) hint.hidden = true;
            editor.off?.('canvas:frame:load', onFrameLoad);
            detachTargets();
        },
    };
}

export function closeInitialMobilePopovers(root, mediaQuery) {
    if (!root || mediaQuery?.matches !== true) return 0;

    let closed = 0;
    root.querySelectorAll?.('[data-lmz-popover].is-open')?.forEach((popover) => {
        if (popover.hidden === true) return;

        const activePanel = popover.querySelector?.('[data-lmz-popover-panel].is-active:not([hidden])');
        const closeButton = activePanel?.querySelector?.('[data-lmz-panel-close]')
            || popover.querySelector?.('[data-lmz-panel-close]');
        if (!closeButton || closeButton.disabled) return;

        closeButton.click();
        closed += 1;
    });

    return closed;
}

export function scheduleInitialMobilePopoverClose({
    root,
    mediaQuery,
    editor = null,
    isCurrent = () => true,
    fallbackDelay = 400,
    environment = {},
} = {}) {
    if (!root || mediaQuery?.matches !== true) return () => {};

    const browserWindow = typeof window !== 'undefined' ? window : null;
    const queueTask = environment.queueMicrotask
        || browserWindow?.queueMicrotask?.bind(browserWindow)
        || globalThis.queueMicrotask?.bind(globalThis)
        || ((callback) => Promise.resolve().then(callback));
    const requestFrame = environment.requestAnimationFrame
        || browserWindow?.requestAnimationFrame?.bind(browserWindow)
        || null;
    const cancelFrame = environment.cancelAnimationFrame
        || browserWindow?.cancelAnimationFrame?.bind(browserWindow)
        || null;
    const setTimer = environment.setTimeout
        || browserWindow?.setTimeout?.bind(browserWindow)
        || globalThis.setTimeout?.bind(globalThis);
    const clearTimer = environment.clearTimeout
        || browserWindow?.clearTimeout?.bind(browserWindow)
        || globalThis.clearTimeout?.bind(globalThis);
    const watchedEvents = ['canvas:frame:load', 'component:selected'];
    const seenEvents = new Set();
    const eventHandlers = new Map();
    let disposed = false;
    let scheduledFrame = null;
    let fallbackTimer = null;

    const cleanup = () => {
        if (disposed) return;
        disposed = true;
        if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);
        scheduledFrame = null;
        if (fallbackTimer !== null && clearTimer) clearTimer(fallbackTimer);
        fallbackTimer = null;
        eventHandlers.forEach((handler, eventName) => editor?.off?.(eventName, handler));
        eventHandlers.clear();
    };

    const scheduleClose = (finish = false) => {
        if (disposed || !isCurrent()) {
            cleanup();
            return;
        }
        if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);

        const close = () => {
            scheduledFrame = null;
            if (disposed || !isCurrent()) {
                cleanup();
                return;
            }
            closeInitialMobilePopovers(root, mediaQuery);
            if (finish) cleanup();
        };

        if (requestFrame) {
            scheduledFrame = requestFrame(close);
        } else {
            close();
        }
    };

    watchedEvents.forEach((eventName) => {
        const handler = () => {
            if (disposed) return;
            seenEvents.add(eventName);
            scheduleClose(seenEvents.size === watchedEvents.length);
        };
        eventHandlers.set(eventName, handler);
        editor?.on?.(eventName, handler);
    });

    queueTask(() => {
        if (disposed || !isCurrent()) {
            cleanup();
            return;
        }
        scheduleClose(false);
    });

    if (setTimer) {
        fallbackTimer = setTimer(() => {
            fallbackTimer = null;
            scheduleClose(true);
        }, Math.max(0, Number(fallbackDelay) || 0));
    }

    return cleanup;
}

function setFormatButtons(workspace, format) {
    workspace.querySelectorAll('[data-marketing-format]').forEach((button) => {
        const active = button.dataset.marketingFormat === format;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

function setCreativeStatus(workspace, status) {
    const badge = workspace.querySelector('[data-marketing-creative-status]');
    if (!badge || !status) return;

    badge.dataset.status = status;
    badge.textContent = status === 'approved' ? 'Freigegeben' : (status === 'archived' ? 'Archiviert' : 'Entwurf');
}

function setRenderStatus(workspace, state, message, downloadUrl = null) {
    const host = workspace.querySelector('[data-marketing-render-status]');
    const label = workspace.querySelector('[data-marketing-render-message]');
    const download = workspace.querySelector('[data-marketing-render-download]');
    if (!host || !label || !download) return;

    host.dataset.state = state;
    label.textContent = message;
    download.classList.toggle('hidden', !downloadUrl);
    download.href = downloadUrl || '#';
}

export function normalizeVariantPayload(payload) {
    const source = payload?.variants || payload?.creative?.variants || null;
    if (!source) return null;

    const records = Array.isArray(source)
        ? source.map((variant) => [variant.format, variant])
        : Object.entries(source);

    return records.reduce((variants, [format, variant]) => {
        if (!MARKETING_ARTBOARDS[format] || !variant) return variants;

        variants[format] = {
            builderData: variant.builder_data || variant.builderData || {},
            css: variant.css || '',
            contentHash: variant.content_hash || variant.contentHash || '',
            version: Number(variant.version || 1),
        };
        return variants;
    }, {});
}

export function projectForVariant(variant, parseCss = () => []) {
    const project = structuredClone(variant?.builderData || {});
    if (variant?.css && (!Array.isArray(project.styles) || project.styles.length === 0)) {
        project.styles = parseCss(variant.css) || project.styles || [];
    }

    return project;
}

export function applySavedVariant(variant, saved, fallback) {
    variant.builderData = saved.builder_data ?? fallback.project;
    variant.html = saved.html ?? fallback.html;
    variant.css = saved.css ?? fallback.css;
    variant.contentHash = saved.content_hash || variant.contentHash;
    variant.version = saved.version || variant.version;

    return variant;
}

export function renderRequestIsCurrent({
    requestId,
    activeRequestId,
    format,
    currentFormat,
    destroyed = false,
}) {
    return !destroyed
        && requestId === activeRequestId
        && format === currentFormat;
}

export function completedRenderDownloadUrl(render, fallbackUrl) {
    if (Object.prototype.hasOwnProperty.call(render || {}, 'download_url')) {
        const url = typeof render.download_url === 'string' ? render.download_url.trim() : '';

        return url || null;
    }

    return fallbackUrl || null;
}

export async function createMarketingStudio(workspace, config) {
    const root = workspace.querySelector('[data-marketing-editor-root]');
    const frame = workspace.querySelector('[data-marketing-editor-frame]');
    const sharedForm = workspace.querySelector('[data-marketing-shared-form]');
    const safeToggle = workspace.querySelector('[data-marketing-safe-zone]');
    const zoomControl = workspace.querySelector('[data-marketing-zoom]');
    const panHint = workspace.querySelector('[data-marketing-pan-hint]');
    const exportButton = workspace.querySelector('[data-marketing-export]');
    const readOnly = config.status === 'archived';

    if (!root || !frame) {
        throw new Error('Marketing-Editor-Fläche fehlt.');
    }

    const builderRuntime = await ensureVendorRuntime(config.vendor || {});
    const abortController = new AbortController();
    const timers = new Set();
    const renderTimers = new Set();
    let currentFormat = MARKETING_ARTBOARDS[config.currentFormat] ? config.currentFormat : 'story';
    let instance = null;
    let artboardViewport = null;
    let touchPanController = null;
    let detachSafeZoneFrameLoad = null;
    let cancelInitialMobilePopoverClose = null;
    let destroyed = false;
    let renderRequest = 0;
    let renderAbortController = null;

    const updateArtboardStatus = (geometry) => {
        const artboard = resolveArtboard(geometry.format);
        const artboardLabel = workspace.querySelector('[data-marketing-artboard-label]');
        const scaleLabel = workspace.querySelector('[data-marketing-scale-label]');
        if (artboardLabel) {
            artboardLabel.textContent = `${artboard.label} · ${artboard.width} × ${artboard.height} px`;
        }
        if (scaleLabel) {
            scaleLabel.textContent = `Ansicht ${Math.round(geometry.zoom)} %`;
        }
    };

    const schedule = (callback, delay) => {
        const timer = window.setTimeout(() => {
            timers.delete(timer);
            callback();
        }, delay);
        timers.add(timer);
        return timer;
    };

    const invalidateRender = () => {
        renderRequest += 1;
        renderAbortController?.abort();
        renderAbortController = null;
        renderTimers.forEach((timer) => window.clearTimeout(timer));
        renderTimers.clear();
    };

    const scheduleRender = (callback, delay) => {
        const timer = window.setTimeout(() => {
            renderTimers.delete(timer);
            callback();
        }, delay);
        renderTimers.add(timer);
        return timer;
    };

    const isActiveRender = (requestId, format) => renderRequestIsCurrent({
        requestId,
        activeRequestId: renderRequest,
        format,
        currentFormat,
        destroyed,
    });

    const startBuilder = async (format) => {
        invalidateRender();
        detachSafeZoneFrameLoad?.();
        detachSafeZoneFrameLoad = null;
        cancelInitialMobilePopoverClose?.();
        cancelInitialMobilePopoverClose = null;
        touchPanController?.destroy();
        touchPanController = null;
        artboardViewport?.destroy();
        artboardViewport = null;
        if (instance) {
            instance.destroy();
            instance = null;
        }

        currentFormat = format;
        config.currentFormat = format;
        frame.dataset.format = format;
        root.dataset.runtimeState = 'loading';
        root.innerHTML = '<div class="rt-marketing-editor-loading" role="status"><span class="rt-marketing-editor-loading__mark">RT</span><span>LMZ Page Builder wird geladen …</span></div>';
        setFormatButtons(workspace, format);
        setRenderStatus(workspace, 'idle', 'Noch kein Export für dieses Format erstellt.');
        if (exportButton && !readOnly) exportButton.disabled = false;

        const variant = config.variants?.[format] || { builderData: {}, css: '', contentHash: '', version: 1 };
        config.variants ||= {};
        config.variants[format] = variant;

        instance = await builderRuntime.create({
            root,
            projectId: `${config.creativeId}:${format}`,
            gjsScript: config.vendor.grapesJs,
            gjsStyle: config.vendor.grapesCss,
            canvasStyles: [],
            canvasScripts: [],
            canvasBaseUrl: window.location.origin + '/',
            canvasBodyClasses: ['rt-marketing-canvas', `rt-marketing-canvas--${format}`],
            canvasLanguage: 'de',
            motion: { enabled: false, preview: false },
            autosave: { enabled: true, intervalMs: 10000, changesBeforeSave: 30 },
            blocks: {
                addDefault: false,
                custom: await createMarketingBlocks(
                    config.logoLightUrl,
                    config.logoDarkUrl,
                    config.sharedContent?.cta_url,
                ),
            },
            storage: {
                onLoad: async ({ editor }) => {
                    return projectForVariant(
                        variant,
                        (css) => editor.Parser?.parseCss?.(css) || [],
                    );
                },
                onSave: async ({ project, html, css }) => {
                    const endpoint = replaceEndpointToken(config.endpoints.variantUpdate, '__FORMAT__', currentFormat);
                    const payload = await requestJson(endpoint, {
                        method: 'PUT',
                        json: {
                            builder_data: project,
                            html,
                            css,
                            expected_hash: variant.contentHash || '',
                        },
                    });
                    const saved = payload.variant || {};
                    applySavedVariant(variant, saved, { project, html, css });
                    setCreativeStatus(workspace, payload.creative?.status);
                },
            },
            assets: {
                onLoad: async () => config.assets || [],
                onUpload: async ({ files }) => {
                    const uploaded = await uploadFiles(files, config);
                    config.assets = [...(config.assets || []), ...uploaded];
                    return uploaded;
                },
            },
            useStudioWebDefaults: false,
            allowFallbackProject: false,
            gjsOptions: {
                deviceManager: { devices: [] },
                canvas: { styles: [], scripts: [] },
            },
        });

        if (destroyed) {
            instance.destroy();
            instance = null;
            return;
        }

        root.dataset.runtimeState = 'ready';
        frame.dataset.readOnly = readOnly ? 'true' : 'false';
        instance.setActionLocked(readOnly);
        if (readOnly) {
            root.querySelectorAll('[data-lmz-action="save"], [data-lmz-action="upload"], [data-lmz-action="assets"], [data-lmz-action="undo"], [data-lmz-action="redo"]').forEach((control) => {
                control.disabled = true;
                control.setAttribute('aria-disabled', 'true');
            });
        }
        const readyInstance = instance;
        touchPanController = createFixedArtboardPanController({
            instance: readyInstance,
            frame,
            hint: panHint,
        });
        artboardViewport = createArtboardViewportController({
            instance: readyInstance,
            frame,
            format,
            zoom: zoomControl?.value || 'fit',
            onChange: (geometry) => {
                updateArtboardStatus(geometry);
                touchPanController?.setEnabled(geometry.mode === 'fixed');
            },
        });
        applySafeZone(readyInstance, safeToggle?.checked !== false);
        if (!readOnly && await syncQrCode(readyInstance.editor, config.sharedContent?.cta_url)) {
            await readyInstance.save('qr-binding-sync');
        }
        if (destroyed || instance !== readyInstance) return;

        const onSafeZoneFrameLoad = () => applySafeZone(readyInstance, safeToggle?.checked !== false);
        readyInstance.editor.on('canvas:frame:load', onSafeZoneFrameLoad);
        detachSafeZoneFrameLoad = () => readyInstance.editor.off?.('canvas:frame:load', onSafeZoneFrameLoad);
        cancelInitialMobilePopoverClose = scheduleInitialMobilePopoverClose({
            root,
            mediaQuery: window.matchMedia?.('(max-width: 899.98px)'),
            editor: readyInstance.editor,
            isCurrent: () => !destroyed && instance === readyInstance,
        });
    };

    workspace.querySelectorAll('[data-marketing-format]').forEach((button) => {
        button.addEventListener('click', async () => {
            const nextFormat = button.dataset.marketingFormat;
            if (!MARKETING_ARTBOARDS[nextFormat] || nextFormat === currentFormat || button.disabled) return;

            workspace.querySelectorAll('[data-marketing-format]').forEach((item) => { item.disabled = true; });
            try {
                if (instance?.hasUnsavedChanges()) {
                    const saved = await instance.save('manual');
                    if (!saved) throw new Error('Das aktuelle Format konnte nicht gespeichert werden.');
                }
                await startBuilder(nextFormat);
            } catch (error) {
                dispatchToast('error', error.message, 'Formatwechsel fehlgeschlagen');
            } finally {
                workspace.querySelectorAll('[data-marketing-format]').forEach((item) => { item.disabled = false; });
            }
        }, { signal: abortController.signal });
    });

    safeToggle?.addEventListener('change', () => {
        frame.dataset.safeZone = safeToggle.checked ? 'true' : 'false';
        applySafeZone(instance, safeToggle.checked);
    }, { signal: abortController.signal });

    zoomControl?.addEventListener('change', () => {
        artboardViewport?.setZoom(zoomControl.value);
    }, { signal: abortController.signal });

    const refreshArtboardViewport = () => artboardViewport?.refresh();
    workspace.addEventListener('marketing-editor:viewport-change', refreshArtboardViewport, { signal: abortController.signal });
    document.addEventListener('marketing-editor:viewport-change', refreshArtboardViewport, { signal: abortController.signal });
    window.addEventListener('marketing-editor:viewport-change', refreshArtboardViewport, { signal: abortController.signal });

    sharedForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (readOnly) return;
        const button = sharedForm.querySelector('[data-marketing-content-save]');
        const status = sharedForm.querySelector('[data-marketing-content-status]');
        const request = serializeSharedForm(sharedForm);
        button.disabled = true;
        status.textContent = 'Inhalte werden gespeichert …';

        try {
            if (instance?.hasUnsavedChanges()) {
                const layoutSaved = await instance.save('manual');
                if (!layoutSaved) {
                    throw new Error('Das aktuelle Layout konnte vor der Inhaltsänderung nicht gespeichert werden.');
                }
            }

            request.expected_hashes = Object.fromEntries(
                Object.entries(config.variants || {}).map(([format, variant]) => [
                    format,
                    variant.contentHash || '',
                ]),
            );

            const payload = await requestJson(config.endpoints.creativeUpdate, {
                method: 'PATCH',
                json: request,
            });

            const refreshedVariants = normalizeVariantPayload(payload);
            if (!refreshedVariants || Object.keys(refreshedVariants).length !== Object.keys(MARKETING_ARTBOARDS).length) {
                throw new Error('Der Server hat nicht alle aktualisierten Formate zurückgegeben. Bitte Seite neu laden.');
            }

            config.sharedContent = payload.creative?.shared_content || request.shared_content;
            config.variants = refreshedVariants;
            setCreativeStatus(workspace, payload.creative?.status || 'draft');
            const title = workspace.querySelector('[data-page-header] h1');
            if (title) {
                title.textContent = request.title;
                title.setAttribute('title', request.title);
            }
            await startBuilder(currentFormat);
            status.textContent = 'Gespeichert. Layoutänderungen bleiben je Format getrennt.';
            dispatchToast('success', 'Die gemeinsamen Inhalte wurden gespeichert.');
        } catch (error) {
            const hashConflict = error.status === 422
                && Object.keys(error.payload?.errors || {}).some((key) => key.startsWith('expected_hashes.'));
            if (hashConflict) {
                status.textContent = 'Eine andere Bearbeitung ist neuer. Die aktuelle Serverversion wird geladen …';
                dispatchToast('warning', 'Das Motiv wurde zwischenzeitlich geändert. Die aktuelle Version wird neu geladen.', 'Bearbeitungskonflikt');
                schedule(() => window.location.reload(), 1400);
                return;
            }
            status.textContent = error.message;
            dispatchToast('error', error.message, 'Inhalte nicht gespeichert');
        } finally {
            button.disabled = false;
        }
    }, { signal: abortController.signal });

    const pollRender = async (render, requestId, format, signal) => {
        if (!isActiveRender(requestId, format)) return;
        const statusUrl = render.status_url
            || replaceEndpointToken(config.endpoints.renderShow, '__RENDER__', render.public_id);

        try {
            const payload = await requestJson(statusUrl, { signal });
            if (!isActiveRender(requestId, format)) return;
            const current = payload.render || payload;
            if (current.status === 'completed') {
                const downloadUrl = completedRenderDownloadUrl(
                    current,
                    replaceEndpointToken(config.endpoints.renderDownload, '__RENDER__', current.public_id),
                );
                if (!downloadUrl) {
                    setRenderStatus(workspace, 'failed', 'Dieser PNG-Export ist nicht mehr aktuell. Bitte erneut exportieren.');
                    exportButton.disabled = false;
                    return;
                }
                setRenderStatus(workspace, 'completed', `${resolveArtboard(format).label}-PNG ist bereit.`, downloadUrl);
                exportButton.disabled = false;
                return;
            }
            if (current.status === 'failed') {
                setRenderStatus(workspace, 'failed', current.error || 'PNG-Erstellung fehlgeschlagen.');
                exportButton.disabled = false;
                return;
            }
            setRenderStatus(workspace, 'processing', 'PNG wird serverseitig gerendert …');
            scheduleRender(() => pollRender(current, requestId, format, signal), 1800);
        } catch (error) {
            if (error.name === 'AbortError' || !isActiveRender(requestId, format)) return;
            setRenderStatus(workspace, 'failed', error.message);
            exportButton.disabled = false;
        }
    };

    exportButton?.addEventListener('click', async () => {
        if (readOnly) return;
        invalidateRender();
        const requestId = renderRequest;
        const exportFormat = currentFormat;
        renderAbortController = new AbortController();
        const { signal } = renderAbortController;
        exportButton.disabled = true;
        setRenderStatus(workspace, 'processing', 'Layout wird gespeichert …');

        try {
            const saved = await instance?.save('manual');
            if (!isActiveRender(requestId, exportFormat)) return;
            if (!saved) throw new Error('Das Motiv konnte vor dem Export nicht gespeichert werden.');

            setRenderStatus(workspace, 'processing', 'PNG wird serverseitig gerendert …');
            const payload = await requestJson(config.endpoints.renderStore, {
                method: 'POST',
                json: { format: exportFormat },
                signal,
            });
            if (!isActiveRender(requestId, exportFormat)) return;
            const render = payload.render || payload;
            if (render.status === 'completed') {
                const downloadUrl = completedRenderDownloadUrl(
                    render,
                    replaceEndpointToken(config.endpoints.renderDownload, '__RENDER__', render.public_id),
                );
                if (!downloadUrl) {
                    setRenderStatus(workspace, 'failed', 'Dieser PNG-Export ist nicht mehr aktuell. Bitte erneut exportieren.');
                    exportButton.disabled = false;
                    return;
                }
                setRenderStatus(workspace, 'completed', `${resolveArtboard(exportFormat).label}-PNG ist bereit.`, downloadUrl);
                exportButton.disabled = false;
                return;
            }
            await pollRender(render, requestId, exportFormat, signal);
        } catch (error) {
            if (error.name === 'AbortError' || !isActiveRender(requestId, exportFormat)) return;
            setRenderStatus(workspace, 'failed', error.message);
            dispatchToast('error', error.message, 'PNG nicht erstellt');
            exportButton.disabled = false;
        }
    }, { signal: abortController.signal });

    await startBuilder(currentFormat);

    if (readOnly) {
        sharedForm?.querySelectorAll('input, textarea, select, button').forEach((control) => {
            control.disabled = true;
        });
        if (exportButton) exportButton.disabled = true;
    }

    return {
        hasUnsavedChanges: () => Boolean(instance?.hasUnsavedChanges?.()),
        destroy() {
            destroyed = true;
            invalidateRender();
            abortController.abort();
            timers.forEach((timer) => window.clearTimeout(timer));
            timers.clear();
            detachSafeZoneFrameLoad?.();
            detachSafeZoneFrameLoad = null;
            cancelInitialMobilePopoverClose?.();
            cancelInitialMobilePopoverClose = null;
            touchPanController?.destroy();
            touchPanController = null;
            artboardViewport?.destroy();
            artboardViewport = null;
            instance?.destroy?.();
            instance = null;
        },
    };
}

export function marketingAssetLibrary(config = {}) {
    return {
        uploading: false,
        deleting: null,
        replacing: null,
        progress: '',
        progressPercent: 0,
        message: '',

        async upload(event) {
            const input = event.currentTarget.querySelector('input[type="file"]');
            const files = Array.from(input?.files || []);
            if (!files.length || this.uploading) return;

            this.uploading = true;
            this.progressPercent = 0;
            this.message = '';

            try {
                for (let index = 0; index < files.length; index += 1) {
                    this.progress = `${index + 1} / ${files.length}`;
                    const body = new FormData();
                    body.append('file', files[index], files[index].name);
                    await requestJson(config.uploadUrl, { method: 'POST', formData: body });
                    this.progressPercent = Math.round(((index + 1) / files.length) * 100);
                }
                this.message = `${files.length} ${files.length === 1 ? 'Bild wurde' : 'Bilder wurden'} sicher gespeichert.`;
                dispatchToast('success', this.message);
                await this.$wire.$refresh();
            } catch (error) {
                this.message = error.message;
                dispatchToast('error', error.message, 'Upload fehlgeschlagen');
            } finally {
                this.uploading = false;
                if (input) input.value = '';
            }
        },

        async remove(publicId, name) {
            if (this.deleting || !window.confirm(`„${name}“ wirklich aus der Marketing-Mediathek löschen?`)) return;

            this.deleting = publicId;
            try {
                const url = replaceEndpointToken(config.deleteUrl, '__ASSET__', publicId);
                await requestJson(url, { method: 'DELETE' });
                dispatchToast('success', 'Medium wurde gelöscht.');
                await this.$wire.$refresh();
            } catch (error) {
                dispatchToast('error', error.message, 'Medium nicht gelöscht');
            } finally {
                this.deleting = null;
            }
        },

        async replace(publicId, event) {
            const input = event.currentTarget;
            const file = input.files?.[0];
            if (!file || this.replacing) return;

            this.replacing = publicId;
            try {
                const body = new FormData();
                body.append('_method', 'PUT');
                body.append('file', file, file.name);
                const url = replaceEndpointToken(config.replaceUrl, '__ASSET__', publicId);
                await requestJson(url, { method: 'POST', formData: body });
                dispatchToast('success', 'Medium wurde ersetzt; seine Referenz bleibt erhalten.');
                await this.$wire.$refresh();
            } catch (error) {
                dispatchToast('error', error.message, 'Medium nicht ersetzt');
            } finally {
                this.replacing = null;
                input.value = '';
            }
        },
    };
}

async function bootMarketingStudio() {
    const workspace = document.querySelector('[data-marketing-studio]');

    if (!workspace) {
        studioBootGuard.destroy();

        return;
    }

    try {
        await studioBootGuard.boot(
            workspace,
            () => createMarketingStudio(workspace, readJsonScript(workspace)),
            () => document.contains(workspace),
        );
    } catch (error) {
        if (!document.contains(workspace)) return;

        const root = workspace.querySelector('[data-marketing-editor-root]');
        if (root) {
            root.dataset.runtimeState = 'failed';
            root.innerHTML = `<div class="rt-marketing-editor-error" role="alert"><strong>Editor konnte nicht geladen werden.</strong><span>${htmlEscape(error.message)}</span></div>`;
        }
        dispatchToast('error', error.message, 'Marketing-Studio nicht verfügbar');
    }
}

function destroyMarketingStudio() {
    studioBootGuard.destroy();
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootMarketingStudio, { once: true });
    } else {
        window.queueMicrotask(bootMarketingStudio);
    }

    document.addEventListener('livewire:navigating', destroyMarketingStudio);
    document.addEventListener('livewire:navigated', bootMarketingStudio);
    window.addEventListener('beforeunload', (event) => {
        if (!studioBootGuard.getActive()?.hasUnsavedChanges?.()) return;
        event.preventDefault();
        event.returnValue = '';
    });
}
