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

let vendorRuntimePromise = null;
let activeStudio = null;
let bootSequence = 0;

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

async function requestJson(url, { method = 'GET', json = null, formData = null } = {}) {
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
    logoUrl = '/rt-brand/rt-logo.svg',
    ctaUrl = 'https://www.rail-time.de/de/karriere',
) {
    const qrDataUrl = await QRCode.toDataURL(ctaUrl, {
        errorCorrectionLevel: 'M',
        margin: 1,
        width: 360,
        color: {
            dark: '#071a2c',
            light: '#ffffff',
        },
    });
    const list = (key, title) => (
        `<section data-rt-block="${key}" style="${BLOCK_SURFACE}padding:32px">`
        + `<h2 style="margin:0 0 18px;font-size:26px;font-weight:800;text-transform:uppercase">${title}</h2>`
        + `<ul data-rt-binding-list="${key}" style="display:grid;gap:10px;margin:0;padding-left:22px"><li>Inhalt ergänzen</li></ul>`
        + '</section>'
    );

    return [
        {
            id: 'rt-marketing-logo',
            definition: {
                label: 'RailTime-Logo',
                category: MARKETING_CATEGORY,
                content: `<div data-rt-block="logo" style="padding:24px"><img src="${htmlEscape(logoUrl)}" alt="RT RailTime" style="display:block;width:240px;max-width:100%;height:auto"></div>`,
            },
        },
        {
            id: 'rt-marketing-hero',
            definition: {
                label: 'Hero-Bild',
                category: MARKETING_CATEGORY,
                content: '<figure data-rt-block="hero" style="height:520px;margin:0;background:#dbe2ea;overflow:hidden"><div style="display:grid;height:100%;place-items:center;color:#667085;font:700 24px Arial,sans-serif">Bild aus der Medienbibliothek wählen</div></figure>',
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
                content: `<img data-rt-block="qr" data-rt-qr-binding="cta_url" data-rt-qr-value="${htmlEscape(ctaUrl)}" src="${qrDataUrl}" alt="QR-Code zur Bewerbung" style="display:block;width:180px;height:180px;object-fit:contain;background:#fff;padding:8px;box-shadow:0 0 0 1px #d7e0ea">`,
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

function serializeSharedForm(form) {
    const data = new FormData(form);
    const content = {};

    for (const [name, rawValue] of data.entries()) {
        if (!name.startsWith('shared_content[')) continue;

        const parts = Array.from(name.matchAll(/(?:^|\[)([^\]]*)\]?/g))
            .map((match) => match[1])
            .filter((part, index) => index === 0 || part !== '');
        if (parts.shift() !== 'shared_content' || parts.length === 0) continue;

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

    const qrValue = String(content?.cta_url || '').trim();
    if (qrValue) {
        const qrDataUrl = await QRCode.toDataURL(qrValue, {
            errorCorrectionLevel: 'M',
            margin: 1,
            width: 360,
            color: { dark: '#071a2c', light: '#ffffff' },
        });
        (wrapper.find?.('[data-rt-qr-binding="cta_url"]') || []).forEach((component) => {
            component.addAttributes({
                src: qrDataUrl,
                'data-rt-qr-value': qrValue,
                alt: 'QR-Code zur Bewerbung',
            });
        });
    }
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

function applyArtboard(instance, format, frame, zoom = 'fit') {
    const artboard = resolveArtboard(format);
    const editor = instance?.editor;
    if (!editor) return;

    const deviceId = `rt-marketing-${format}`;
    const devices = editor.DeviceManager;
    if (!devices.get(deviceId)) {
        devices.add(deviceId, {
            id: deviceId,
            name: artboard.label,
            width: `${artboard.width}px`,
            height: `${artboard.height}px`,
        });
    }
    editor.setDevice(deviceId);

    frame.dataset.format = format;
    frame.style.setProperty('--rt-marketing-artboard-width', String(artboard.width));
    frame.style.setProperty('--rt-marketing-artboard-height', String(artboard.height));

    window.requestAnimationFrame(() => {
        let percentage = Number(zoom);
        if (zoom === 'fit' || !Number.isFinite(percentage)) {
            const viewport = frame.querySelector('.lmz-builder__main');
            const availableWidth = Math.max(240, (viewport?.clientWidth || frame.clientWidth) - 56);
            const availableHeight = Math.max(280, (viewport?.clientHeight || frame.clientHeight) - 56);
            percentage = Math.min(100, (availableWidth / artboard.width) * 100, (availableHeight / artboard.height) * 100);
        }

        editor.Canvas?.setZoom?.(Math.max(10, Math.min(100, percentage)));
    });
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
            contentHash: variant.content_hash || variant.contentHash || '',
            version: Number(variant.version || 1),
        };
        return variants;
    }, {});
}

export async function createMarketingStudio(workspace, config) {
    const root = workspace.querySelector('[data-marketing-editor-root]');
    const frame = workspace.querySelector('[data-marketing-editor-frame]');
    const sharedForm = workspace.querySelector('[data-marketing-shared-form]');
    const safeToggle = workspace.querySelector('[data-marketing-safe-zone]');
    const zoomControl = workspace.querySelector('[data-marketing-zoom]');
    const exportButton = workspace.querySelector('[data-marketing-export]');

    if (!root || !frame) {
        throw new Error('Marketing-Editor-Fläche fehlt.');
    }

    const builderRuntime = await ensureVendorRuntime(config.vendor || {});
    const abortController = new AbortController();
    const timers = new Set();
    let currentFormat = MARKETING_ARTBOARDS[config.currentFormat] ? config.currentFormat : 'story';
    let instance = null;
    let destroyed = false;
    let renderRequest = 0;

    const schedule = (callback, delay) => {
        const timer = window.setTimeout(() => {
            timers.delete(timer);
            callback();
        }, delay);
        timers.add(timer);
        return timer;
    };

    const startBuilder = async (format) => {
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

        const variant = config.variants?.[format] || { builderData: {}, contentHash: '', version: 1 };
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
                custom: await createMarketingBlocks(config.logoUrl, config.sharedContent?.cta_url),
            },
            storage: {
                onLoad: async () => variant.builderData || {},
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
                    variant.builderData = saved.builder_data || project;
                    variant.contentHash = saved.content_hash || variant.contentHash;
                    variant.version = saved.version || variant.version;
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
        applyArtboard(instance, format, frame, zoomControl?.value || 'fit');
        applySafeZone(instance, safeToggle?.checked !== false);
        instance.editor.on('canvas:frame:load', () => {
            applyArtboard(instance, currentFormat, frame, zoomControl?.value || 'fit');
            applySafeZone(instance, safeToggle?.checked !== false);
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
        applyArtboard(instance, currentFormat, frame, zoomControl.value);
    }, { signal: abortController.signal });

    sharedForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
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
            status.textContent = error.message;
            dispatchToast('error', error.message, 'Inhalte nicht gespeichert');
        } finally {
            button.disabled = false;
        }
    }, { signal: abortController.signal });

    const pollRender = async (render, requestId) => {
        if (destroyed || requestId !== renderRequest) return;
        const statusUrl = render.status_url
            || replaceEndpointToken(config.endpoints.renderShow, '__RENDER__', render.public_id);

        try {
            const payload = await requestJson(statusUrl);
            const current = payload.render || payload;
            if (current.status === 'completed') {
                const downloadUrl = current.download_url
                    || replaceEndpointToken(config.endpoints.renderDownload, '__RENDER__', current.public_id);
                setRenderStatus(workspace, 'completed', `${resolveArtboard(currentFormat).label}-PNG ist bereit.`, downloadUrl);
                exportButton.disabled = false;
                return;
            }
            if (current.status === 'failed') {
                setRenderStatus(workspace, 'failed', current.error || 'PNG-Erstellung fehlgeschlagen.');
                exportButton.disabled = false;
                return;
            }
            setRenderStatus(workspace, 'processing', 'PNG wird serverseitig gerendert …');
            schedule(() => pollRender(current, requestId), 1800);
        } catch (error) {
            setRenderStatus(workspace, 'failed', error.message);
            exportButton.disabled = false;
        }
    };

    exportButton?.addEventListener('click', async () => {
        exportButton.disabled = true;
        setRenderStatus(workspace, 'processing', 'Layout wird gespeichert …');
        renderRequest += 1;
        const requestId = renderRequest;

        try {
            const saved = await instance?.save('manual');
            if (!saved) throw new Error('Das Motiv konnte vor dem Export nicht gespeichert werden.');

            setRenderStatus(workspace, 'processing', 'PNG wird serverseitig gerendert …');
            const payload = await requestJson(config.endpoints.renderStore, {
                method: 'POST',
                json: { format: currentFormat },
            });
            const render = payload.render || payload;
            if (render.status === 'completed') {
                const downloadUrl = render.download_url
                    || replaceEndpointToken(config.endpoints.renderDownload, '__RENDER__', render.public_id);
                setRenderStatus(workspace, 'completed', `${resolveArtboard(currentFormat).label}-PNG ist bereit.`, downloadUrl);
                exportButton.disabled = false;
                return;
            }
            await pollRender(render, requestId);
        } catch (error) {
            setRenderStatus(workspace, 'failed', error.message);
            dispatchToast('error', error.message, 'PNG nicht erstellt');
            exportButton.disabled = false;
        }
    }, { signal: abortController.signal });

    await startBuilder(currentFormat);

    return {
        hasUnsavedChanges: () => Boolean(instance?.hasUnsavedChanges?.()),
        destroy() {
            destroyed = true;
            renderRequest += 1;
            abortController.abort();
            timers.forEach((timer) => window.clearTimeout(timer));
            timers.clear();
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
    const sequence = ++bootSequence;

    activeStudio?.destroy?.();
    activeStudio = null;

    if (!workspace) return;

    try {
        const config = readJsonScript(workspace);
        const studio = await createMarketingStudio(workspace, config);
        if (sequence !== bootSequence || !document.contains(workspace)) {
            studio.destroy();
            return;
        }
        activeStudio = studio;
    } catch (error) {
        const root = workspace.querySelector('[data-marketing-editor-root]');
        if (root) {
            root.dataset.runtimeState = 'failed';
            root.innerHTML = `<div class="rt-marketing-editor-error" role="alert"><strong>Editor konnte nicht geladen werden.</strong><span>${htmlEscape(error.message)}</span></div>`;
        }
        dispatchToast('error', error.message, 'Marketing-Studio nicht verfügbar');
    }
}

function destroyMarketingStudio() {
    bootSequence += 1;
    activeStudio?.destroy?.();
    activeStudio = null;
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
        if (!activeStudio?.hasUnsavedChanges?.()) return;
        event.preventDefault();
        event.returnValue = '';
    });
}
