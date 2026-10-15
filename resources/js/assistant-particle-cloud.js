/*!
 * Thinking Orbs — Copyright (c) 2026 Jakub Antalik — MIT License.
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
/**
 * Thinking Orb adapter for the existing Alpine/Livewire assistant slots.
 * Original MIT geometry: vendor/thinking-orbs (Jakub Antalik).
 * Keep the Cloud registration/slot names stable across Livewire navigation.
 */
import { paintFrame } from './vendor/thinking-orbs/engine/core.ts';
import { frameRibbon } from './vendor/thinking-orbs/engine/ribbon.ts';
import { frameGlobe, frameWave } from './vendor/thinking-orbs/engine/lattice.ts';
import { BASE_PROFILES, scaleCounts, scaleRadii } from './vendor/thinking-orbs/engine/profiles.ts';

export const ASSISTANT_CLOUD_TRANSITION_MS = 650;

// Public runtime states, not an invented sequence of reasoning stages.
const STATES = Object.freeze({
    idle: { mode: 'ribbon', pace: 0.45 },
    thinking: { mode: 'ribbon', pace: 1 },
    listening: { mode: 'wave', pace: 1 },
    speaking: { mode: 'wave', pace: 0.85 },
    curious: { mode: 'globe', pace: 0.65 },
    happy: { mode: 'ribbon', pace: 1.15 },
    wave: { mode: 'ribbon', pace: 0.8 },
    offline: { mode: 'ribbon', pace: 0 },
});

// Shipped upstream presets, separately tuned for avatar and launcher sizes.
const PRESETS = {
    ribbon: {
        64: { speed: 2.34, count: 0.25, size: 0.85, extra: { spin: 0, bandMul: 3.9, wobMul: 1 } },
        32: { speed: 2.7776, count: 0.0969, size: 0.9766, extra: { spin: 0, bandMul: 4.49, wobMul: 1 } },
    },
    globe: {
        64: { speed: 2.015, count: 0.42, size: 1.15, extra: { scanMul: 4.08, dimBase: 0.45 } },
        32: { speed: 2.3803, count: 0.1839, size: 1.4769, extra: { scanMul: 4.2301, dimBase: 0.45 } },
    },
    wave: {
        64: { speed: 4.388, count: 0.341, size: 1 },
        32: { speed: 4.1512, count: 0.169, size: 1.3232 },
    },
};
const FRAMES = { ribbon: frameRibbon, globe: frameGlobe, wave: frameWave };
const presets = new Map();
const activeRenderers = new WeakMap();
const HOST_SELECTOR = '.rt-chatbot, [data-ai-assist]';

export function resolveAssistantOrb(state = 'idle', size = 64) {
    const resolvedState = STATES[state] || STATES.idle;
    const tuningSize = size < 60 ? 32 : 64;
    const key = `${resolvedState.mode}-${tuningSize}`;
    if (!presets.has(key)) {
        const preset = PRESETS[resolvedState.mode][tuningSize];
        const opts = scaleRadii(scaleCounts(BASE_PROFILES[resolvedState.mode], preset.count), preset.size);
        presets.set(key, { speed: preset.speed, opts: { ...opts, ...preset.extra } });
    }
    return { ...presets.get(key), frame: FRAMES[resolvedState.mode], pace: resolvedState.pace };
}

export function assistantCloudStyleBlend(msSinceChange, transitionMs = ASSISTANT_CLOUD_TRANSITION_MS) {
    const progress = Math.max(0, Math.min(1, (Number(msSinceChange) || 0) / Math.max(1, transitionMs)));
    return progress * progress * (3 - 2 * progress);
}

export function createAssistantCloudRenderer(controllerElement, options = {}) {
    if (!controllerElement) return null;
    const documentObject = options.documentObject || controllerElement.ownerDocument;
    const view = options.windowObject || documentObject.defaultView;
    const reducedMotion = view.matchMedia('(prefers-reduced-motion: reduce)');
    const forcedColors = view.matchMedia('(forced-colors: active)');
    const root = controllerElement.closest(HOST_SELECTOR) || controllerElement;
    activeRenderers.get(root)?.destroy();
    const surfaces = [];

    function ownsSlot(slot) {
        return slot.isConnected && root.contains(slot) && (slot.closest(HOST_SELECTOR) || root) === root;
    }

    function releaseSurface(surface) {
        resizeObserver?.unobserve(surface.slot);
        intersectionObserver?.unobserve(surface.slot);
        surface.canvas.remove();
        surface.slot.classList.remove('is-cloud-ready');
    }

    function restoreSurface(surface) {
        if (surface.canvas.parentNode !== surface.slot) {
            // A Livewire morph may preserve the slot but remove or replace its client-owned canvas.
            surface.slot.querySelectorAll('.rt-assistant-cloud__canvas').forEach((canvas) => canvas.remove());
            surface.slot.appendChild(surface.canvas);
            surface.staticKey = null;
            surface.slot.classList.remove('is-cloud-ready');
        } else if (!surface.slot.classList.contains('is-cloud-ready')) {
            surface.staticKey = null;
        }
    }

    // Messages are inserted/removed by Livewire; all slots share one clock.
    function syncSurfaces() {
        for (let index = surfaces.length - 1; index >= 0; index--) {
            const surface = surfaces[index];
            if (!ownsSlot(surface.slot)) {
                releaseSurface(surface);
                surfaces.splice(index, 1);
            } else {
                restoreSurface(surface);
            }
        }
        root.querySelectorAll('[data-assistant-cloud-slot]').forEach((slot) => {
            if (!ownsSlot(slot)) return;
            if (surfaces.some((surface) => surface.slot === slot)) return;
            const canvas = documentObject.createElement('canvas');
            canvas.className = 'rt-assistant-cloud__canvas';
            canvas.setAttribute('aria-hidden', 'true');
            let context;
            try { context = canvas.getContext('2d', { alpha: true }); } catch (_) { return; }
            if (!context) return;
            // A remounted controller owns one canvas, including after an interrupted teardown.
            slot.querySelectorAll('.rt-assistant-cloud__canvas').forEach((existing) => existing.remove());
            slot.appendChild(canvas);
            slot.classList.remove('is-cloud-ready');
            surfaces.push({ slot, canvas, context, visible: true, role: slot.dataset.assistantCloudSlot });
            resizeObserver?.observe(slot);
            intersectionObserver?.observe(slot);
        });
    }

    let started = false;
    let destroyed = false;
    let animationFrame = null;
    let lastFrameAt = null;
    let clock = 0;
    let currentState = controllerElement.dataset.state || 'idle';
    let previousState = currentState;
    let stateChangedAt = 0;

    function isVisible(surface) {
        const open = controllerElement.dataset.petOpen === 'true';
        return surface.visible && surface.slot.isConnected
            && (surface.role === 'launcher' ? !open : open)
            && surface.slot.getClientRects().length > 0;
    }

    function drawSurface(surface, state, alpha, staticFrame, dark) {
        const { context, cssSize } = surface;
        const preset = resolveAssistantOrb(state, cssSize);
        const time = staticFrame || preset.pace === 0 ? 0.6 : clock * preset.speed * preset.pace;
        context.globalAlpha = alpha * (state === 'offline' ? 0.55 : 1);
        paintFrame(context, preset.frame(cssSize, time, preset.opts), dark);
        context.globalAlpha = 1;
    }

    function draw() {
        const staticFrame = reducedMotion.matches;
        const dark = documentObject.documentElement.classList.contains('dark');
        const blend = staticFrame ? 1 : assistantCloudStyleBlend(clock * 1000 - stateChangedAt);
        surfaces.filter(isVisible).forEach((surface) => {
            const size = Math.min(surface.slot.clientWidth, surface.slot.clientHeight);
            if (!size) return;
            const dpr = Math.min(Number(view.devicePixelRatio) || 1, 2);
            const pixels = Math.max(1, Math.round(size * dpr));
            const messageState = surface.role === 'message' ? (surface.slot.dataset.state || 'idle') : null;
            const restingMessage = messageState === 'idle' || messageState === 'offline';
            const cacheKey = staticFrame || restingMessage
                ? `${size}-${dpr}-${dark}-${messageState || currentState}` : null;
            if (cacheKey && surface.staticKey === cacheKey && surface.slot.classList.contains('is-cloud-ready')) return;
            if (surface.canvas.width !== pixels || surface.cssSize !== size) {
                surface.canvas.width = pixels;
                surface.canvas.height = pixels;
                surface.cssSize = size;
            }
            surface.context.setTransform(dpr, 0, 0, dpr, 0, 0);
            surface.context.clearRect(0, 0, size, size);
            if (messageState) {
                drawSurface(surface, messageState, 1, staticFrame || restingMessage, dark);
                surface.staticKey = cacheKey;
                if (!surface.slot.classList.contains('is-cloud-ready')) surface.slot.classList.add('is-cloud-ready');
                return;
            }
            if (blend < 1 && previousState !== currentState) {
                drawSurface(surface, previousState, 1 - blend, staticFrame, dark);
            }
            drawSurface(surface, currentState, blend < 1 && previousState !== currentState ? blend : 1, staticFrame, dark);
            surface.staticKey = cacheKey;
            if (!surface.slot.classList.contains('is-cloud-ready')) surface.slot.classList.add('is-cloud-ready');
        });
    }

    function canAnimate() {
        return started && !destroyed && !reducedMotion.matches && !forcedColors.matches
            && documentObject.visibilityState !== 'hidden' && surfaces.some(isVisible)
            && (currentState !== 'offline' || clock * 1000 - stateChangedAt < ASSISTANT_CLOUD_TRANSITION_MS
                || surfaces.some((surface) => surface.role === 'message' && isVisible(surface)
                    && surface.slot.dataset.state && !['idle', 'offline'].includes(surface.slot.dataset.state)));
    }

    function queueFrame() {
        if (animationFrame === null && canAnimate()) animationFrame = view.requestAnimationFrame(render);
    }

    function render(timestamp) {
        animationFrame = null;
        if (!canAnimate()) return;
        // Cap ambient rendering at 30fps; do not advance by time spent hidden.
        if (lastFrameAt === null || timestamp - lastFrameAt >= 1000 / 30) {
            if (lastFrameAt !== null) clock += Math.min(timestamp - lastFrameAt, 100) / 1000;
            lastFrameAt = timestamp;
            draw();
        }
        queueFrame();
    }

    function reconcile() {
        if (!started || destroyed) return;
        const state = controllerElement.dataset.state || 'idle';
        if (state !== currentState) {
            previousState = currentState;
            currentState = state;
            stateChangedAt = clock * 1000;
        }
        if (animationFrame !== null) view.cancelAnimationFrame(animationFrame);
        animationFrame = null;
        lastFrameAt = null;
        if (!forcedColors.matches && documentObject.visibilityState !== 'hidden') draw();
        queueFrame();
    }

    const stateObserver = new view.MutationObserver(reconcile);
    stateObserver.observe(controllerElement, { attributes: true, attributeFilter: ['data-state', 'data-pet-open'] });
    const resizeObserver = view.ResizeObserver ? new view.ResizeObserver(reconcile) : null;
    const intersectionObserver = view.IntersectionObserver ? new view.IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            const surface = surfaces.find((item) => item.slot === entry.target);
            if (surface) surface.visible = entry.isIntersecting;
        });
        reconcile();
    }) : null;
    const slotObserver = new view.MutationObserver((records) => {
        const surfacesChanged = records.some((record) => {
            if (record.type === 'attributes') return record.target.matches?.('[data-assistant-cloud-slot]');
            if (record.type !== 'childList') return false;
            return record.target.matches?.('[data-assistant-cloud-slot]')
                || [...record.addedNodes, ...record.removedNodes].some((node) =>
                    node.nodeType === 1 && (node.matches('[data-assistant-cloud-slot]')
                        || node.querySelector('[data-assistant-cloud-slot]')));
        });
        if (surfacesChanged) {
            syncSurfaces();
            reconcile();
        }
    });
    slotObserver.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-state', 'class'] });
    const themeObserver = new view.MutationObserver(reconcile);
    themeObserver.observe(documentObject.documentElement, { attributes: true, attributeFilter: ['class'] });
    syncSurfaces();
    documentObject.addEventListener('visibilitychange', reconcile);
    reducedMotion.addEventListener('change', reconcile);
    forcedColors.addEventListener('change', reconcile);
    view.addEventListener('resize', reconcile);

    const renderer = {
        get surfaceCount() { return surfaces.length; },
        start() {
            if (started || destroyed) return;
            started = true;
            reconcile();
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            started = false;
            if (animationFrame !== null) view.cancelAnimationFrame(animationFrame);
            stateObserver.disconnect();
            slotObserver.disconnect();
            themeObserver.disconnect();
            resizeObserver?.disconnect();
            intersectionObserver?.disconnect();
            documentObject.removeEventListener('visibilitychange', reconcile);
            reducedMotion.removeEventListener('change', reconcile);
            forcedColors.removeEventListener('change', reconcile);
            view.removeEventListener('resize', reconcile);
            surfaces.forEach(releaseSurface);
            surfaces.length = 0;
            if (activeRenderers.get(root) === renderer) activeRenderers.delete(root);
        },
    };
    activeRenderers.set(root, renderer);
    return renderer;
}

export function railtimeAssistantCloud() {
    return {
        cloudRenderer: null,
        cloudGeneration: 0,
        cloudDestroyed: false,
        init() {
            const element = this.$el;
            const generation = ++this.cloudGeneration;
            this.cloudDestroyed = false;
            this.$nextTick(() => {
                if (this.cloudDestroyed || generation !== this.cloudGeneration || !element?.isConnected) return;
                this.cloudRenderer?.destroy();
                this.cloudRenderer = createAssistantCloudRenderer(element);
                this.cloudRenderer?.start();
            });
        },
        destroy() {
            this.cloudDestroyed = true;
            this.cloudGeneration += 1;
            this.cloudRenderer?.destroy();
            this.cloudRenderer = null;
        },
    };
}
