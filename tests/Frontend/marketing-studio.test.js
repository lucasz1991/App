import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';

import {
    animatedPreviewIsPlaying,
    applyMotionSettings,
    calculateSpacingDragValue,
    calculateSpacingOverlayGeometry,
    collectUsedMedia,
    componentAnimationContext,
    createSpacingOverlayController,
    createImageAssetSelection,
    createScopedAssetCallbackSelection,
    enforceProtectedComponentModels,
    handleScopedRtePaste,
    installScopedAssetAccess,
    isFixedMailSignatureGeometry,
    isProtectedEditorStructure,
    isProtectedEditorStructureTree,
    LMZ_EDITOR_MODES,
    createLmzAssistantAdapter,
    createLmzEditorChrome,
    createPageBuilderFidelitySession,
    createPageBuilderLifecycleController,
    createPageBuilderNavigationController,
    normalizeLmzCapabilities,
    resolveLmzEditorMode,
    restartAnimatedPreview,
    sanitizeAnimationStyles,
    sanitizeMotionSettings,
    setAnimatedPreviewPlayback,
    spacingCssSnapshot,
} from '../../resources/js/lmz-editor-core.js';

import {
    applyAuthoritativeMarketingRedesignResponse,
    applySavedVariant,
    applySavedVariantAndPublishAssistantContext,
    calculateArtboardGeometry,
    closeInitialMobilePopovers,
    completedRenderDownloadUrl,
    createArtboardViewportController,
    createFixedArtboardPanController,
    createStudioBootGuard,
    MARKETING_ARTBOARDS,
    marketingRedesignExpectedHashes,
    marketingRedesignFailureStatus,
    createMarketingBlocks,
    normalizeVariantPayload,
    projectForVariant,
    removeBuilderUploadControls,
    renderRequestIsCurrent,
    replaceEndpointToken,
    resolveArtboard,
    scheduleInitialMobilePopoverClose,
    serializeSharedData,
    syncQrCode,
} from '../../resources/js/marketing-studio.js';
import { hydrateMailCanvasAssets } from '../../resources/js/mail-builder.js';

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) || new Set();
        listeners.add(listener);
        this.listeners.set(type, listeners);
    }

    removeEventListener(type, listener) {
        const listeners = this.listeners.get(type);
        listeners?.delete(listener);
        if (listeners?.size === 0) this.listeners.delete(type);
    }

    dispatch(type, properties = {}) {
        const event = {
            type,
            cancelable: true,
            defaultPrevented: false,
            propagationStopped: false,
            target: this,
            preventDefault() {
                this.defaultPrevented = true;
            },
            stopPropagation() {
                this.propagationStopped = true;
            },
            ...properties,
        };
        [...(this.listeners.get(type) || [])].forEach((listener) => listener(event));

        return event;
    }

    listenerCount() {
        return [...this.listeners.values()].reduce((count, listeners) => count + listeners.size, 0);
    }
}

test('simultaneous initial events reuse one LMZ studio boot', async () => {
    const guard = createStudioBootGuard();
    const workspace = {};
    let createCount = 0;
    let destroyCount = 0;
    let resolveStudio;
    const createStudio = () => {
        createCount += 1;

        return new Promise((resolve) => {
            resolveStudio = () => resolve({
                destroy() {
                    destroyCount += 1;
                },
            });
        });
    };

    const domReadyBoot = guard.boot(workspace, createStudio);
    const livewireBoot = guard.boot(workspace, createStudio);
    await Promise.resolve();

    assert.equal(createCount, 1);
    resolveStudio();
    const [firstStudio, secondStudio] = await Promise.all([domReadyBoot, livewireBoot]);
    assert.equal(firstStudio, secondStudio);
    assert.equal(guard.getActive(), firstStudio);
    assert.equal(destroyCount, 0);

    guard.destroy();
    assert.equal(destroyCount, 1);
    assert.equal(guard.getActive(), null);
});

test('marketing artboards keep the exact publishing dimensions', () => {
    assert.deepEqual(MARKETING_ARTBOARDS.story, { label: 'Story', width: 1080, height: 1920 });
    assert.deepEqual(MARKETING_ARTBOARDS.post, { label: 'Post', width: 1080, height: 1080 });
    assert.deepEqual(MARKETING_ARTBOARDS.web, { label: 'Web', width: 1200, height: 630 });
    assert.equal(resolveArtboard('unknown'), MARKETING_ARTBOARDS.story);
});

test('the read-only file library removes vendor upload controls from semantics and keyboard order', () => {
    const controls = [
        { removed: false, remove() { this.removed = true; } },
        { removed: false, remove() { this.removed = true; } },
    ];
    const root = {
        querySelectorAll(selector) {
            assert.equal(selector, '[data-lmz-action="upload"], [data-lmz-upload-input]');

            return controls;
        },
    };

    assert.equal(removeBuilderUploadControls(root), 2);
    assert.deepEqual(controls.map(({ removed }) => removed), [true, true]);
    assert.equal(removeBuilderUploadControls(null), 0);
});

test('each LMZ format exposes pixel-valid logical frame variables before visual scaling', () => {
    for (const [format, artboard] of Object.entries(MARKETING_ARTBOARDS)) {
        const cssProperties = {};
        const devices = new Map();
        const handlers = new Map();
        const editor = {
            DeviceManager: {
                get: (id) => devices.get(id),
                add: (id, attributes) => devices.set(id, attributes),
            },
            Canvas: { setZoom() {} },
            setDevice() {},
            on: (event, handler) => handlers.set(event, handler),
            off: (event, handler) => {
                if (handlers.get(event) === handler) handlers.delete(event);
            },
        };
        const frame = {
            clientWidth: 390,
            clientHeight: 844,
            dataset: {},
            style: {
                setProperty(name, value) {
                    cssProperties[name] = value;
                },
            },
            querySelector: () => null,
        };
        const controller = createArtboardViewportController({
            instance: { editor },
            frame,
            format,
            environment: { ResizeObserver: null },
        });

        assert.equal(cssProperties['--rt-marketing-artboard-width'], `${artboard.width}px`);
        assert.equal(cssProperties['--rt-marketing-artboard-height'], `${artboard.height}px`);
        assert.equal(cssProperties['--rt-marketing-logical-width'], `${artboard.width}px`);
        assert.equal(cssProperties['--rt-marketing-logical-height'], `${artboard.height}px`);
        assert.equal(devices.get(`rt-marketing-${format}`).width, `${artboard.width}px`);
        assert.equal(devices.get(`rt-marketing-${format}`).height, `${artboard.height}px`);
        assert.ok(Math.abs(
            (Number.parseFloat(cssProperties['--rt-marketing-display-width'])
                / Number.parseFloat(cssProperties['--rt-marketing-display-height']))
            - (artboard.width / artboard.height),
        ) < 0.000001);

        controller.destroy();
        assert.equal(handlers.size, 0);
    }
});

test('fit geometry scales fixed artboards into desktop, mobile portrait, and mobile landscape hosts', () => {
    const desktopPost = calculateArtboardGeometry({
        format: 'post',
        hostWidth: 1440,
        hostHeight: 1000,
    });
    assert.equal(desktopPost.logicalWidth, 1080);
    assert.equal(desktopPost.logicalHeight, 1080);
    assert.equal(desktopPost.availableHeight, 944);
    assert.ok(Math.abs(desktopPost.zoom - 87.4074074074) < 0.000001);
    assert.ok(Math.abs(desktopPost.displayHeight - 944) < 0.000001);

    const mobileWeb = calculateArtboardGeometry({
        format: 'web',
        hostWidth: 390,
        hostHeight: 844,
    });
    assert.equal(mobileWeb.logicalWidth, 1200);
    assert.equal(mobileWeb.logicalHeight, 630);
    assert.ok(Math.abs(mobileWeb.zoom - 27.8333333333) < 0.000001);
    assert.ok(Math.abs(mobileWeb.displayWidth - 334) < 0.000001);
    assert.ok(mobileWeb.displayHeight <= mobileWeb.availableHeight);

    const landscapeStory = calculateArtboardGeometry({
        format: 'story',
        hostWidth: 844,
        hostHeight: 390,
    });
    assert.ok(Math.abs(landscapeStory.zoom - 17.3958333333) < 0.000001);
    assert.ok(Math.abs(landscapeStory.displayHeight - 334) < 0.000001);
    assert.ok(landscapeStory.displayWidth <= landscapeStory.availableWidth);
});

test('fit mode never upscales while explicit 50, 75, and 100 percent keep their editor scale', () => {
    const spaciousWeb = calculateArtboardGeometry({
        format: 'web',
        hostWidth: 5000,
        hostHeight: 3000,
        zoom: 'fit',
    });
    assert.equal(spaciousWeb.zoom, 100);
    assert.equal(spaciousWeb.displayWidth, 1200);
    assert.equal(spaciousWeb.displayHeight, 630);

    for (const percentage of [50, 75, 100]) {
        const mobileAtFixedZoom = calculateArtboardGeometry({
            format: 'web',
            hostWidth: 390,
            hostHeight: 844,
            zoom: String(percentage),
        });
        assert.equal(mobileAtFixedZoom.mode, 'fixed');
        assert.equal(mobileAtFixedZoom.zoom, percentage);
        assert.equal(mobileAtFixedZoom.logicalWidth, 1200);
        assert.equal(mobileAtFixedZoom.displayWidth, 1200 * (percentage / 100));
    }
});

test('artboard viewport observes host resizes and cleans pending work and editor events', () => {
    const host = { clientWidth: 390, clientHeight: 844 };
    const cssProperties = {};
    const frame = {
        clientWidth: 390,
        clientHeight: 844,
        dataset: {},
        style: {
            setProperty(name, value) {
                cssProperties[name] = value;
            },
        },
        querySelector(selector) {
            return selector === '.lmz-builder__main' ? host : null;
        },
    };
    const devices = new Map();
    const handlers = new Map();
    const zooms = [];
    const editor = {
        DeviceManager: {
            get: (id) => devices.get(id),
            add(id, attributes) {
                devices.set(id, { ...attributes });
            },
        },
        Canvas: {
            setZoom(value) {
                zooms.push(value);
            },
        },
        setDevice(id) {
            this.device = id;
        },
        on(event, handler) {
            handlers.set(event, handler);
        },
        off(event, handler) {
            if (handlers.get(event) === handler) handlers.delete(event);
        },
    };
    const animationFrames = new Map();
    let animationFrameId = 0;
    const flushAnimationFrames = () => {
        const queued = [...animationFrames.values()];
        animationFrames.clear();
        queued.forEach((callback) => callback());
    };
    const observers = [];
    class FakeResizeObserver {
        constructor(callback) {
            this.callback = callback;
            this.observed = [];
            this.disconnected = false;
            observers.push(this);
        }

        observe(element) {
            this.observed.push(element);
        }

        disconnect() {
            this.disconnected = true;
        }
    }
    const changes = [];
    const controller = createArtboardViewportController({
        instance: { editor },
        frame,
        format: 'web',
        zoom: 'fit',
        onChange: (geometry) => changes.push(geometry),
        environment: {
            ResizeObserver: FakeResizeObserver,
            requestAnimationFrame(callback) {
                animationFrameId += 1;
                animationFrames.set(animationFrameId, callback);
                return animationFrameId;
            },
            cancelAnimationFrame(id) {
                animationFrames.delete(id);
            },
        },
    });

    assert.deepEqual(devices.get('rt-marketing-web'), {
        id: 'rt-marketing-web',
        name: 'Web',
        width: '1200px',
        height: '630px',
    });
    assert.equal(editor.device, 'rt-marketing-web');
    assert.equal(frame.dataset.logicalWidth, '1200');
    assert.equal(frame.dataset.logicalHeight, '630');
    assert.equal(cssProperties['--rt-marketing-artboard-width'], '1200px');
    assert.equal(cssProperties['--rt-marketing-artboard-height'], '630px');
    assert.equal(cssProperties['--rt-marketing-logical-width'], '1200px');
    assert.equal(cssProperties['--rt-marketing-logical-height'], '630px');
    assert.equal(observers[0].observed[0], host);

    flushAnimationFrames();
    assert.ok(Math.abs(zooms.at(-1) - 27.8333333333) < 0.000001);
    assert.equal(changes.at(-1).logicalWidth, 1200);
    assert.equal(frame.dataset.zoomMode, 'fit');
    assert.ok(Math.abs(Number.parseFloat(cssProperties['--rt-marketing-display-width']) - 334) < 0.000001);

    host.clientWidth = 844;
    host.clientHeight = 390;
    observers[0].callback();
    flushAnimationFrames();
    assert.ok(Math.abs(zooms.at(-1) - 53.0158730159) < 0.000001);

    controller.setZoom('100');
    flushAnimationFrames();
    assert.equal(zooms.at(-1), 100);
    assert.equal(frame.dataset.zoomMode, 'fixed');
    assert.equal(cssProperties['--rt-marketing-display-width'], '1200px');
    assert.equal(cssProperties['--rt-marketing-display-height'], '630px');

    controller.setZoom('fit');
    handlers.get('canvas:frame:load')();
    assert.equal(animationFrames.size, 1);
    const appliedBeforeDestroy = zooms.length;
    controller.destroy();
    assert.equal(animationFrames.size, 0);
    assert.equal(observers[0].disconnected, true);
    assert.equal(handlers.has('canvas:frame:load'), false);
    flushAnimationFrames();
    assert.equal(zooms.length, appliedBeforeDestroy);
});

test('format-switch cleanup prevents a stale artboard frame from applying after the new format starts', () => {
    const pending = new Map();
    let nextId = 0;
    const environment = {
        ResizeObserver: null,
        requestAnimationFrame(callback) {
            nextId += 1;
            pending.set(nextId, callback);
            return nextId;
        },
        cancelAnimationFrame(id) {
            pending.delete(id);
        },
    };
    const frame = {
        clientWidth: 390,
        clientHeight: 844,
        dataset: {},
        style: { setProperty() {} },
        querySelector: () => null,
    };
    const makeInstance = () => {
        const devices = new Map();
        const handlers = new Map();
        const zooms = [];
        return {
            devices,
            handlers,
            zooms,
            editor: {
                DeviceManager: {
                    get: (id) => devices.get(id),
                    add: (id, attributes) => devices.set(id, attributes),
                },
                Canvas: { setZoom: (value) => zooms.push(value) },
                setDevice() {},
                on: (event, handler) => handlers.set(event, handler),
                off: (event, handler) => {
                    if (handlers.get(event) === handler) handlers.delete(event);
                },
            },
        };
    };

    const story = makeInstance();
    const storyViewport = createArtboardViewportController({
        instance: story,
        frame,
        format: 'story',
        environment,
    });
    assert.equal(pending.size, 1);
    storyViewport.destroy();
    assert.equal(pending.size, 0);

    const web = makeInstance();
    createArtboardViewportController({
        instance: web,
        frame,
        format: 'web',
        environment,
    });
    const callbacks = [...pending.values()];
    pending.clear();
    callbacks.forEach((callback) => callback());

    assert.equal(story.zooms.length, 0);
    assert.equal(story.handlers.size, 0);
    assert.deepEqual(web.devices.get('rt-marketing-web'), {
        id: 'rt-marketing-web',
        name: 'Web',
        width: '1200px',
        height: '630px',
    });
    assert.ok(Math.abs(web.zooms.at(-1) - 27.8333333333) < 0.000001);
});

test('fixed artboard pointer panning preserves taps, scrolls touch and pen gestures, and cleans iframe reloads', () => {
    const shell = Object.assign(new FakeEventTarget(), {
        scrollLeft: 0,
        scrollTop: 0,
        scrollWidth: 900,
        scrollHeight: 1000,
        clientWidth: 250,
        clientHeight: 400,
    });
    const firstCanvasDocument = new FakeEventTarget();
    const secondCanvasDocument = new FakeEventTarget();
    const thirdCanvasDocument = new FakeEventTarget();
    let canvasDocument = firstCanvasDocument;
    const editorHandlers = new Map();
    const editor = {
        Canvas: { getDocument: () => canvasDocument },
        on: (eventName, handler) => editorHandlers.set(eventName, handler),
        off: (eventName, handler) => {
            if (editorHandlers.get(eventName) === handler) editorHandlers.delete(eventName);
        },
    };
    const frame = {
        dataset: { zoomMode: 'fit' },
        querySelector: (selector) => selector === '.lmz-builder__canvas-shell' ? shell : null,
    };
    const hint = { hidden: false };
    const microtasks = [];
    const controller = createFixedArtboardPanController({
        instance: { editor },
        frame,
        hint,
        threshold: 8,
        environment: {
            supportsPointerEvents: true,
            touchCapable: true,
            queueMicrotask: (callback) => microtasks.push(callback),
        },
    });
    const flushMicrotasks = () => microtasks.splice(0).forEach((callback) => callback());

    assert.equal(shell.listenerCount(), 5);
    assert.equal(firstCanvasDocument.listenerCount(), 5);
    assert.equal(frame.dataset.touchPanning, 'false');
    assert.equal(hint.hidden, true);

    controller.setEnabled(true);
    firstCanvasDocument.dispatch('pointerdown', {
        pointerId: 1,
        pointerType: 'touch',
        clientX: 100,
        clientY: 100,
    });
    firstCanvasDocument.dispatch('pointermove', {
        pointerId: 1,
        pointerType: 'touch',
        clientX: 20,
        clientY: 20,
    });
    firstCanvasDocument.dispatch('pointerup', { pointerId: 1, pointerType: 'touch' });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [0, 0]);

    frame.dataset.zoomMode = 'fixed';
    controller.setEnabled(true);
    assert.equal(frame.dataset.touchPanning, 'true');
    assert.equal(hint.hidden, false);

    const captureTarget = {
        captured: [],
        released: [],
        setPointerCapture(id) {
            this.captured.push(id);
        },
        releasePointerCapture(id) {
            this.released.push(id);
        },
    };
    const tapDown = firstCanvasDocument.dispatch('pointerdown', {
        pointerId: 2,
        pointerType: 'touch',
        clientX: 100,
        clientY: 100,
        target: captureTarget,
    });
    const tapMove = firstCanvasDocument.dispatch('pointermove', {
        pointerId: 2,
        pointerType: 'touch',
        clientX: 95,
        clientY: 96,
    });
    const tapUp = firstCanvasDocument.dispatch('pointerup', {
        pointerId: 2,
        pointerType: 'touch',
    });
    assert.deepEqual([tapDown.defaultPrevented, tapMove.defaultPrevented, tapUp.defaultPrevented], [false, false, false]);
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [0, 0]);
    assert.deepEqual(captureTarget.captured, [2]);
    assert.deepEqual(captureTarget.released, [2]);

    shell.scrollLeft = 10;
    shell.scrollTop = 20;
    firstCanvasDocument.dispatch('pointerdown', {
        pointerId: 3,
        pointerType: 'pen',
        clientX: 100,
        clientY: 100,
    });
    const swipeMove = firstCanvasDocument.dispatch('pointermove', {
        pointerId: 3,
        pointerType: 'pen',
        clientX: 40,
        clientY: 30,
    });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [70, 90]);
    assert.equal(swipeMove.defaultPrevented, true);
    assert.equal(swipeMove.propagationStopped, true);
    assert.equal(frame.dataset.touchPanningActive, 'true');
    const swipeUp = firstCanvasDocument.dispatch('pointerup', {
        pointerId: 3,
        pointerType: 'pen',
    });
    assert.equal(swipeUp.defaultPrevented, true);
    assert.equal(swipeUp.propagationStopped, false);
    assert.equal(frame.dataset.touchPanningActive, 'false');

    firstCanvasDocument.dispatch('pointerdown', {
        pointerId: 4,
        pointerType: 'touch',
        clientX: 100,
        clientY: 100,
    });
    firstCanvasDocument.dispatch('pointermove', {
        pointerId: 4,
        pointerType: 'touch',
        clientX: -2000,
        clientY: -2000,
    });
    firstCanvasDocument.dispatch('pointercancel', { pointerId: 4, pointerType: 'touch' });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [650, 600]);

    frame.dataset.zoomMode = 'fit';
    controller.setEnabled(false);
    assert.equal(frame.dataset.touchPanning, 'false');
    assert.equal(hint.hidden, true);
    firstCanvasDocument.dispatch('pointerdown', {
        pointerId: 5,
        pointerType: 'touch',
        clientX: 0,
        clientY: 0,
    });
    firstCanvasDocument.dispatch('pointermove', {
        pointerId: 5,
        pointerType: 'touch',
        clientX: 200,
        clientY: 200,
    });
    firstCanvasDocument.dispatch('pointerup', { pointerId: 5, pointerType: 'touch' });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [650, 600]);

    frame.dataset.zoomMode = 'fixed';
    controller.setEnabled(true);
    canvasDocument = secondCanvasDocument;
    editorHandlers.get('canvas:frame:load')();
    flushMicrotasks();
    assert.equal(firstCanvasDocument.listenerCount(), 0);
    assert.equal(secondCanvasDocument.listenerCount(), 5);
    assert.equal(shell.listenerCount(), 5);

    shell.scrollLeft = 0;
    shell.scrollTop = 0;
    firstCanvasDocument.dispatch('pointerdown', {
        pointerId: 6,
        pointerType: 'touch',
        clientX: 100,
        clientY: 100,
    });
    firstCanvasDocument.dispatch('pointermove', {
        pointerId: 6,
        pointerType: 'touch',
        clientX: 0,
        clientY: 0,
    });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [0, 0]);
    secondCanvasDocument.dispatch('pointerdown', {
        pointerId: 7,
        pointerType: 'touch',
        clientX: 100,
        clientY: 100,
    });
    secondCanvasDocument.dispatch('pointermove', {
        pointerId: 7,
        pointerType: 'touch',
        clientX: 50,
        clientY: 40,
    });
    secondCanvasDocument.dispatch('pointerup', { pointerId: 7, pointerType: 'touch' });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [50, 60]);

    canvasDocument = thirdCanvasDocument;
    editorHandlers.get('canvas:frame:load')();
    controller.destroy();
    flushMicrotasks();
    assert.equal(shell.listenerCount(), 0);
    assert.equal(secondCanvasDocument.listenerCount(), 0);
    assert.equal(thirdCanvasDocument.listenerCount(), 0);
    assert.equal(editorHandlers.size, 0);
    assert.equal(frame.dataset.touchPanning, 'false');
    assert.equal(frame.dataset.touchPanningActive, 'false');
    assert.equal(hint.hidden, true);
});

test('touch-event fallback pans fixed artboards without handling fit mode', () => {
    const shell = Object.assign(new FakeEventTarget(), {
        scrollLeft: 0,
        scrollTop: 0,
        scrollWidth: 800,
        scrollHeight: 800,
        clientWidth: 300,
        clientHeight: 300,
    });
    const frame = {
        dataset: { zoomMode: 'fixed' },
        querySelector: () => shell,
    };
    const editorHandlers = new Map();
    const editor = {
        Canvas: { getDocument: () => null },
        on: (eventName, handler) => editorHandlers.set(eventName, handler),
        off: (eventName, handler) => {
            if (editorHandlers.get(eventName) === handler) editorHandlers.delete(eventName);
        },
    };
    const controller = createFixedArtboardPanController({
        instance: { editor },
        frame,
        environment: { supportsPointerEvents: false, touchCapable: true },
    });
    controller.setEnabled(true);

    shell.dispatch('touchstart', {
        touches: [{ identifier: 9, clientX: 120, clientY: 120 }],
    });
    const move = shell.dispatch('touchmove', {
        touches: [{ identifier: 9, clientX: 70, clientY: 60 }],
    });
    shell.dispatch('touchend', {
        changedTouches: [{ identifier: 9, clientX: 70, clientY: 60 }],
    });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [50, 60]);
    assert.equal(move.defaultPrevented, true);

    frame.dataset.zoomMode = 'fit';
    controller.setEnabled(false);
    shell.dispatch('touchstart', {
        touches: [{ identifier: 10, clientX: 120, clientY: 120 }],
    });
    shell.dispatch('touchmove', {
        touches: [{ identifier: 10, clientX: 0, clientY: 0 }],
    });
    shell.dispatch('touchend', {
        changedTouches: [{ identifier: 10, clientX: 0, clientY: 0 }],
    });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [50, 60]);
    controller.destroy();
    assert.equal(shell.listenerCount(), 0);
});

test('fixed zoom keeps the pan hint and gestures disabled on non-touch desktops', () => {
    const shell = Object.assign(new FakeEventTarget(), {
        scrollLeft: 0,
        scrollTop: 0,
        scrollWidth: 800,
        scrollHeight: 800,
        clientWidth: 300,
        clientHeight: 300,
    });
    const frame = {
        dataset: { zoomMode: 'fixed' },
        querySelector: () => shell,
    };
    const hint = { hidden: false };
    const editor = {
        Canvas: { getDocument: () => null },
        on() {},
        off() {},
    };
    const controller = createFixedArtboardPanController({
        instance: { editor },
        frame,
        hint,
        environment: {
            supportsPointerEvents: true,
            touchCapable: false,
        },
    });
    controller.setEnabled(true);

    assert.equal(frame.dataset.touchPanning, 'false');
    assert.equal(hint.hidden, true);
    shell.dispatch('pointerdown', {
        pointerId: 1,
        pointerType: 'touch',
        clientX: 100,
        clientY: 100,
    });
    shell.dispatch('pointermove', {
        pointerId: 1,
        pointerType: 'touch',
        clientX: 0,
        clientY: 0,
    });
    shell.dispatch('pointerup', { pointerId: 1, pointerType: 'touch' });
    assert.deepEqual([shell.scrollLeft, shell.scrollTop], [0, 0]);

    controller.destroy();
});

test('only initially open mobile LMZ popovers close through their own controls', () => {
    let mobileCloseClicks = 0;
    let desktopCloseClicks = 0;
    const createPopover = (onClick, hidden = false) => {
        const closeButton = {
            disabled: false,
            click() {
                onClick();
                popover.hidden = true;
            },
        };
        const activePanel = {
            querySelector: (selector) => selector === '[data-lmz-panel-close]' ? closeButton : null,
        };
        const popover = {
            hidden,
            querySelector(selector) {
                if (selector === '[data-lmz-popover-panel].is-active:not([hidden])') return activePanel;
                if (selector === '[data-lmz-panel-close]') return closeButton;
                return null;
            },
        };

        return popover;
    };
    const mobilePopover = createPopover(() => { mobileCloseClicks += 1; });
    const alreadyClosedPopover = createPopover(() => { mobileCloseClicks += 1; }, true);
    const mobileRoot = {
        querySelectorAll: () => [mobilePopover, alreadyClosedPopover],
    };

    assert.equal(closeInitialMobilePopovers(mobileRoot, { matches: true }), 1);
    assert.equal(mobileCloseClicks, 1);
    assert.equal(mobilePopover.hidden, true);
    assert.equal(closeInitialMobilePopovers(mobileRoot, { matches: true }), 0);

    const desktopPopover = createPopover(() => { desktopCloseClicks += 1; });
    assert.equal(closeInitialMobilePopovers({
        querySelectorAll: () => [desktopPopover],
    }, { matches: false }), 0);
    assert.equal(desktopCloseClicks, 0);
    assert.equal(desktopPopover.hidden, false);
});

test('late initial selection closes after QR setup while later deliberate panel opens stay untouched', async () => {
    let open = false;
    let closeClicks = 0;
    const closeButton = {
        disabled: false,
        click() {
            closeClicks += 1;
            open = false;
        },
    };
    const popover = {
        get hidden() {
            return !open;
        },
        querySelector(selector) {
            if (selector === '[data-lmz-popover-panel].is-active:not([hidden])') {
                return { querySelector: () => closeButton };
            }
            return selector === '[data-lmz-panel-close]' ? closeButton : null;
        },
    };
    const root = {
        querySelectorAll: () => open ? [popover] : [],
    };
    const microtasks = [];
    const animationFrames = new Map();
    const timers = new Map();
    const editorHandlers = new Map();
    let nextFrame = 0;
    let nextTimer = 0;
    const editor = {
        on: (eventName, handler) => editorHandlers.set(eventName, handler),
        off: (eventName, handler) => {
            if (editorHandlers.get(eventName) === handler) editorHandlers.delete(eventName);
        },
    };
    const environment = {
        queueMicrotask: (callback) => microtasks.push(callback),
        requestAnimationFrame(callback) {
            nextFrame += 1;
            animationFrames.set(nextFrame, callback);
            return nextFrame;
        },
        cancelAnimationFrame: (id) => animationFrames.delete(id),
        setTimeout(callback) {
            nextTimer += 1;
            timers.set(nextTimer, callback);
            return nextTimer;
        },
        clearTimeout: (id) => timers.delete(id),
    };
    const flushMicrotasks = () => microtasks.splice(0).forEach((callback) => callback());
    const flushAnimationFrames = () => {
        const callbacks = [...animationFrames.values()];
        animationFrames.clear();
        callbacks.forEach((callback) => callback());
    };
    const flushTimers = () => {
        const callbacks = [...timers.values()];
        timers.clear();
        callbacks.forEach((callback) => callback());
    };

    scheduleInitialMobilePopoverClose({
        root,
        mediaQuery: { matches: true },
        editor,
        environment,
    });
    assert.equal(editorHandlers.size, 2);
    flushMicrotasks();
    open = true;
    editorHandlers.get('component:selected')();
    assert.equal(closeClicks, 0);
    assert.equal(animationFrames.size, 1);
    flushAnimationFrames();
    assert.equal(closeClicks, 1);
    assert.equal(open, false);
    assert.equal(editorHandlers.size, 2);

    open = true;
    editorHandlers.get('canvas:frame:load')();
    flushAnimationFrames();
    assert.equal(closeClicks, 2);
    assert.equal(open, false);
    assert.equal(editorHandlers.size, 0);
    assert.equal(timers.size, 0);

    open = true;
    flushMicrotasks();
    flushAnimationFrames();
    flushTimers();
    assert.equal(closeClicks, 2);
    assert.equal(open, true);

    const cancelled = scheduleInitialMobilePopoverClose({
        root,
        mediaQuery: { matches: true },
        editor,
        environment,
    });
    flushMicrotasks();
    assert.equal(animationFrames.size, 1);
    cancelled();
    assert.equal(animationFrames.size, 0);
    assert.equal(timers.size, 0);
    assert.equal(editorHandlers.size, 0);
    flushAnimationFrames();
    assert.equal(closeClicks, 2);

    open = false;
    scheduleInitialMobilePopoverClose({
        root,
        mediaQuery: { matches: true },
        editor,
        environment,
    });
    flushMicrotasks();
    flushAnimationFrames();
    assert.equal(editorHandlers.size, 2);
    open = true;
    flushTimers();
    assert.equal(animationFrames.size, 1);
    flushAnimationFrames();
    assert.equal(closeClicks, 3);
    assert.equal(open, false);
    assert.equal(editorHandlers.size, 0);

    const source = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8'));
    const startBuilderIndex = source.indexOf('const startBuilder = async');
    const startBuilderSource = source.slice(
        startBuilderIndex,
        source.indexOf("workspace.querySelectorAll('[data-marketing-format]')", startBuilderIndex),
    );
    assert.ok(startBuilderSource.indexOf("save('qr-binding-sync')") >= 0);
    assert.ok(
        startBuilderSource.indexOf('scheduleInitialMobilePopoverClose({')
            > startBuilderSource.indexOf("save('qr-binding-sync')"),
    );
});

test('official horizontal Joomla lockups stay byte-identical in the public brand directory', async () => {
    const [{ createHash }, { readFile }] = await Promise.all([
        import('node:crypto'),
        import('node:fs/promises'),
    ]);
    const expected = {
        'logo-horizontal.png': 'FFE44DAE1A8404167C124164398206165A12B07C3BA2A44BB0C8D1BEC553CA26',
        'logo-horizontal-darkbg.png': 'D64F15D1D6A7B1972FAC3F9F5A0A9C02B6B5924D6BADB2ACE29041934BC9469A',
    };

    for (const [file, hash] of Object.entries(expected)) {
        const bytes = await readFile(new URL(`../../public/rt-brand/img/${file}`, import.meta.url));
        assert.equal(createHash('sha256').update(bytes).digest('hex').toUpperCase(), hash);
    }
});

test('marketing editor trusts the server supplied brand image manifest without duplicating paths in JavaScript', async () => {
    const source = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8'));
    const editorSource = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../app/Livewire/Admin/Marketing/CreativeEditor.php', import.meta.url), 'utf8'));

    assert.match(source, /Array\.isArray\(config\.brandImageUrls\)/);
    assert.match(editorSource, /MarketingBrandAssets::manifest\(\)/);
    assert.match(editorSource, /'brandImageUrls'/);
    assert.doesNotMatch(source, /wagenmeister-team-gleis\.jpeg/);
});

test('route placeholders are replaced with encoded public ids', () => {
    assert.equal(
        replaceEndpointToken('/marketing/motive/id/varianten/__FORMAT__', '__FORMAT__', 'story'),
        '/marketing/motive/id/varianten/story',
    );
    assert.equal(
        replaceEndpointToken('/marketing/medien/__ASSET__', '__ASSET__', 'a/b'),
        '/marketing/medien/a%2Fb',
    );
});

test('shared form bracket names serialize into the backend shared_content contract', () => {
    const fields = [
        ['title', ' Wagenmeister Kampagne '],
        ['shared_content[kicker]', ' Komm ins Team '],
        ['shared_content[facts][0][value]', '60+'],
        ['shared_content[facts][0][label]', 'Wagenmeister'],
        ['shared_content[tasks][]', 'Technische Untersuchung'],
        ['shared_content[tasks][]', '  '],
        ['shared_content[profile][]', 'Qualifikation als Wagenmeister'],
        ['shared_content[benefits][]', 'Unbefristete Perspektive'],
        ['shared_content[benefits][]', 'Fachliche Weiterentwicklung'],
        ['shared_content[cta_url]', 'https://www.rail-time.de/de/karriere'],
    ];
    const data = {
        entries: () => fields[Symbol.iterator](),
        get: (name) => fields.find(([field]) => field === name)?.[1] ?? null,
    };

    assert.deepEqual(serializeSharedData(data), {
        title: 'Wagenmeister Kampagne',
        shared_content: {
            kicker: 'Komm ins Team',
            facts: [{ value: '60+', label: 'Wagenmeister' }],
            tasks: ['Technische Untersuchung'],
            profile: ['Qualifikation als Wagenmeister'],
            benefits: ['Unbefristete Perspektive', 'Fachliche Weiterentwicklung'],
            cta_url: 'https://www.rail-time.de/de/karriere',
        },
    });
});

test('variant refresh accepts server maps and drops unknown formats', () => {
    assert.deepEqual(normalizeVariantPayload({
        variants: {
            story: { builder_data: { pages: [] }, content_hash: 'story-hash', version: 2 },
            post: { builder_data: { pages: [1] }, content_hash: 'post-hash', version: 3 },
            web: { builder_data: { pages: [2] }, content_hash: 'web-hash', version: 4 },
            print: { builder_data: {}, content_hash: 'ignored', version: 1 },
        },
    }), {
        story: { builderData: { pages: [] }, html: '', css: '', contentHash: 'story-hash', version: 2 },
        post: { builderData: { pages: [1] }, html: '', css: '', contentHash: 'post-hash', version: 3 },
        web: { builderData: { pages: [2] }, html: '', css: '', contentHash: 'web-hash', version: 4 },
    });
});

test('marketing redesign replaces config only from one complete server-authoritative response', () => {
    const config = {
        status: 'approved',
        sharedContent: { headline: 'Alt' },
        variants: {
            story: { contentHash: 'a'.repeat(64) },
            post: { contentHash: 'b'.repeat(64) },
            web: { contentHash: 'c'.repeat(64) },
        },
    };
    assert.deepEqual(marketingRedesignExpectedHashes(config.variants), {
        story: 'a'.repeat(64),
        post: 'b'.repeat(64),
        web: 'c'.repeat(64),
    });

    const payload = {
        creative: { status: 'draft', shared_content: { headline: 'Neu' } },
        variants: Object.fromEntries(['story', 'post', 'web'].map((format, index) => [format, {
            builder_data: { pages: [{ component: `<main>${format}</main>` }] },
            css: `.format-${format}{display:block}`,
            content_hash: String(index + 4).repeat(64),
            version: index + 7,
        }])),
    };
    const applied = applyAuthoritativeMarketingRedesignResponse(config, payload);

    assert.equal(applied.status, 'draft');
    assert.equal(config.status, 'draft');
    assert.equal(config.sharedContent.headline, 'Neu');
    assert.deepEqual(Object.keys(config.variants), ['story', 'post', 'web']);
    assert.equal(config.variants.web.version, 9);

    const beforeIncomplete = structuredClone(config);
    assert.equal(applyAuthoritativeMarketingRedesignResponse(config, {
        creative: { status: 'draft' },
        variants: { story: payload.variants.story, post: payload.variants.post },
    }), null);
    assert.deepEqual(config, beforeIncomplete);
    assert.equal(marketingRedesignExpectedHashes({ story: config.variants.story }), null);
});

test('marketing redesign reports hash conflicts as stale and storage failures separately', () => {
    assert.equal(marketingRedesignFailureStatus({
        status: 422,
        payload: { errors: { 'expected_hashes.story': ['veraltet'] } },
    }), 'stale_context');
    assert.equal(marketingRedesignFailureStatus({ status: 409 }), 'stale_context');
    assert.equal(marketingRedesignFailureStatus(new TypeError('network failed')), 'storage_error');
    assert.equal(marketingRedesignFailureStatus({ status: 503 }), 'storage_error');
    assert.equal(marketingRedesignFailureStatus(new TypeError('network failed'), { requestStarted: true }), 'reload_required');
    assert.equal(marketingRedesignFailureStatus({ status: 503 }, { requestStarted: true }), 'reload_required');
    assert.equal(marketingRedesignFailureStatus({ status: 422, payload: { errors: { preset: ['invalid'] } } }), 'rejected');
});

test('a saved CSS response survives format reloads and remains the next save fallback', () => {
    const variant = {
        builderData: { pages: [{ component: '<p>Story</p>' }], styles: [] },
        html: '<p>Alt</p>',
        css: '.headline{color:#111}',
        contentHash: 'old-hash',
        version: 1,
    };
    const submitted = {
        project: { pages: [{ component: '<p>Story</p>' }], styles: [] },
        html: '<p onclick="bad()">Nicht bereinigt</p>',
        css: '.headline{color:#e4002b}',
    };

    applySavedVariant(variant, {
        builder_data: submitted.project,
        html: '<p>Serverbereinigt</p>',
        css: '.headline{color:#c90025}',
        content_hash: 'new-hash',
        version: 2,
    }, submitted);

    assert.equal(variant.html, '<p>Serverbereinigt</p>');
    assert.equal(variant.css, '.headline{color:#c90025}');

    let parsedCss = '';
    const reloaded = projectForVariant(variant, (css) => {
        parsedCss = css;
        return [{ selectors: ['.headline'], style: { color: '#c90025' } }];
    });
    assert.equal(parsedCss, '.headline{color:#c90025}');
    assert.equal(reloaded.pages[0].component, '<p>Serverbereinigt</p>');
    assert.equal(reloaded.styles[0].style.color, '#c90025');

    applySavedVariant(variant, {}, {
        project: reloaded,
        html: variant.html,
        css: variant.css,
    });
    assert.equal(variant.css, '.headline{color:#c90025}');
    assert.equal(variant.html, '<p>Serverbereinigt</p>');
});

test('canonical marketing source rehydrates the active V2 frame without losing stable container ids', () => {
    const variant = {
        builderData: {
            pages: [{
                id: 'story-page',
                component: '<main>Ignored parallel source</main>',
                frames: [{
                    id: 'story-frame',
                    component: {
                        type: 'wrapper',
                        id: 'story-wrapper',
                        components: '<main id="old">Alt</main>',
                    },
                }],
            }],
            styles: [{ selectors: ['old'], style: { background: 'none' } }],
            railtime: { template: 'job_wagenmeister', format: 'story', schema: 4 },
        },
        html: '<main id="canonical">Neu</main>',
        css: '.canonical{background:radial-gradient(circle,#fff,transparent),none}',
    };
    const before = structuredClone(variant.builderData);
    let parsedCss = '';
    const projected = projectForVariant(variant, (css) => {
        parsedCss = css;
        return [{ selectors: ['canonical'], style: { background: 'radial-gradient(circle,#fff,transparent),none' } }];
    });

    assert.deepEqual(variant.builderData, before);
    assert.equal(projected.pages[0].id, 'story-page');
    assert.equal(projected.pages[0].frames[0].id, 'story-frame');
    assert.equal(projected.pages[0].frames[0].component.id, 'story-wrapper');
    assert.equal(projected.pages[0].frames[0].component.components, variant.html);
    assert.equal(Object.hasOwn(projected.pages[0], 'component'), false);
    assert.equal(parsedCss, variant.css);
    assert.equal(projected.styles[0].selectors[0], 'canonical');
    assert.deepEqual(projected.railtime, variant.builderData.railtime);

    const withoutCanonicalSource = structuredClone(variant);
    delete withoutCanonicalSource.html;
    assert.throws(
        () => projectForVariant(withoutCanonicalSource),
        /keine kanonische HTML-Quelle/,
    );
});

test('a server-authoritative variant save publishes its fresh assistant snapshot before completing', async () => {
    const variant = {
        builderData: { pages: [] },
        html: '<p>Alt</p>',
        css: '.headline{color:#111}',
        contentHash: 'a'.repeat(64),
        version: 4,
    };
    let releasePublish;
    let publishedSnapshot = null;
    let completed = false;
    const publishGate = new Promise((resolve) => { releasePublish = resolve; });

    const saving = applySavedVariantAndPublishAssistantContext(
        variant,
        {
            builder_data: { pages: [{ component: '<p>Neu</p>' }] },
            html: '<p>Serverbereinigt</p>',
            css: '.headline{color:#c90025}',
            content_hash: 'b'.repeat(64),
            version: 5,
        },
        {
            project: { pages: [] },
            html: '<p>Fallback</p>',
            css: '.headline{color:#e4002b}',
        },
        async () => {
            publishedSnapshot = {
                contentHash: variant.contentHash,
                version: variant.version,
            };
            await publishGate;
        },
    ).then((result) => {
        completed = true;
        return result;
    });

    await Promise.resolve();
    assert.deepEqual(publishedSnapshot, {
        contentHash: 'b'.repeat(64),
        version: 5,
    });
    assert.equal(completed, false);

    releasePublish();
    assert.equal(await saving, variant);
    assert.equal(completed, true);
});

test('a story render response cannot overwrite post status after a format switch', async () => {
    const storyRequest = { requestId: 4, format: 'story' };
    assert.equal(renderRequestIsCurrent({
        ...storyRequest,
        activeRequestId: 4,
        currentFormat: 'story',
    }), true);

    const postState = { activeRequestId: 5, currentFormat: 'post' };
    let visibleStatus = 'Noch kein Export für dieses Format erstellt.';
    if (renderRequestIsCurrent({ ...storyRequest, ...postState })) {
        visibleStatus = `${resolveArtboard(storyRequest.format).label}-PNG ist bereit.`;
    }

    assert.equal(visibleStatus, 'Noch kein Export für dieses Format erstellt.');
    assert.equal(renderRequestIsCurrent({
        requestId: 5,
        activeRequestId: 5,
        format: 'post',
        currentFormat: 'post',
    }), true);

    const source = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8'));
    assert.match(source, /json:\s*\{ format: exportFormat \}/);
    assert.match(source, /resolveArtboard\(format\)\.label/);
    assert.match(source, /renderAbortController\?\.abort\(\)/);
    assert.match(source, /renderTimers\.forEach/);
});

test('an explicitly stale completed render never exposes a fallback download link', () => {
    const fallback = '/administrator/marketing/render/render-id/download';

    assert.equal(completedRenderDownloadUrl({ download_url: null }, fallback), null);
    assert.equal(completedRenderDownloadUrl({ download_url: '' }, fallback), null);
    assert.equal(completedRenderDownloadUrl({ download_url: '   ' }, fallback), null);
    assert.equal(completedRenderDownloadUrl({ download_url: '/fresh/render.png' }, fallback), '/fresh/render.png');
    assert.equal(completedRenderDownloadUrl({}, fallback), fallback);
});

test('RailTime block set uses backend bindings and a local scan-ready QR image', async () => {
    const blocks = await createMarketingBlocks(
        '/rt-brand/img/logo-horizontal.png',
        '/rt-brand/img/logo-horizontal-darkbg.png',
        'https://www.rail-time.de/de/karriere',
    );
    const definitions = Object.fromEntries(blocks.map((block) => [block.id, block.definition.content]));

    assert.equal(blocks.length, 12);
    assert.match(definitions['rt-marketing-logo-light'], /src="\/rt-brand\/img\/logo-horizontal\.png"/);
    assert.match(definitions['rt-marketing-logo-light'], /data-rt-logo-surface="light"/);
    assert.match(definitions['rt-marketing-logo-dark'], /src="\/rt-brand\/img\/logo-horizontal-darkbg\.png"/);
    assert.match(definitions['rt-marketing-logo-dark'], /data-rt-logo-surface="dark"/);
    assert.match(definitions['rt-marketing-logo-light'], /alt="RT Rail Time GmbH"/);
    assert.doesNotMatch(definitions['rt-marketing-logo-light'], /<span[^>]*>\s*RAILTIME/i);
    assert.doesNotMatch(definitions['rt-marketing-logo-dark'], /<span[^>]*>\s*RAILTIME/i);
    assert.match(definitions['rt-marketing-headline'], /data-rt-binding="title"/);
    assert.match(definitions['rt-marketing-facts'], /data-rt-binding-facts="facts"/);
    assert.match(definitions['rt-marketing-tasks'], /data-rt-binding-list="tasks"/);
    assert.match(definitions['rt-marketing-contact'], /data-rt-binding="contact_phone"/);
    assert.match(definitions['rt-marketing-contact'], /data-rt-binding="contact_email"/);
    assert.match(definitions['rt-marketing-cta'], /data-rt-binding-href="cta_url"/);

    const qr = definitions['rt-marketing-qr'];
    assert.match(qr, /^<img /);
    assert.match(qr, /src="data:image\/png;base64,iVBOR/);
    assert.match(qr, /alt="QR-Code zur Bewerbung"/);
    assert.doesNotMatch(qr, /google|quickchart|api\./i);
});

test('the initial block set stays loadable with an empty CTA and can later receive a scan-ready QR', async () => {
    const { PNG } = await import('pngjs');

    for (const emptyCta of ['', '   ']) {
        const blocks = await createMarketingBlocks(
            '/rt-brand/img/logo-horizontal.png',
            '/rt-brand/img/logo-horizontal-darkbg.png',
            emptyCta,
        );
        const qr = blocks.find((block) => block.id === 'rt-marketing-qr')?.definition.content || '';
        const source = qr.match(/src="([^"]+)"/)?.[1] || '';
        const neutralQr = PNG.sync.read(Buffer.from(source.split(',', 2)[1], 'base64'));

        assert.match(qr, /data-rt-qr-value=""/);
        assert.match(qr, /alt="Kein QR-Code: Zieladresse fehlt"/);
        assert.deepEqual([neutralQr.width, neutralQr.height, neutralQr.data[3]], [1, 1, 0]);
    }

    const attributes = {
        'data-rt-qr-value': '',
        src: 'data:image/png;base64,neutral',
        alt: 'Kein QR-Code: Zieladresse fehlt',
    };
    const component = {
        getAttributes: () => attributes,
        addAttributes(next) {
            Object.assign(attributes, next);
        },
    };
    const editor = {
        DomComponents: {
            getWrapper: () => ({
                find: (selector) => selector === '[data-rt-qr-binding="cta_url"]' ? [component] : [],
            }),
        },
    };

    assert.equal(await syncQrCode(editor, 'https://www.rail-time.de/de/karriere'), true);
    assert.match(attributes.src, /^data:image\/png;base64,iVBOR/);
    assert.equal(attributes.alt, 'QR-Code zur Bewerbung');
});

test('an existing QR image is neutralized for an empty CTA and regenerated locally afterwards', async () => {
    const attributes = {
        'data-rt-qr-value': 'https://www.rail-time.de/de/alt',
        src: 'data:image/png;base64,old',
    };
    const component = {
        getAttributes: () => attributes,
        addAttributes(next) {
            Object.assign(attributes, next);
        },
    };
    const editor = {
        DomComponents: {
            getWrapper: () => ({
                find: (selector) => selector === '[data-rt-qr-binding="cta_url"]' ? [component] : [],
            }),
        },
    };

    assert.equal(await syncQrCode(editor, ''), true);
    assert.equal(attributes['data-rt-qr-value'], '');
    assert.match(attributes.src, /^data:image\/png;base64,/);
    assert.notEqual(attributes.src, 'data:image/png;base64,old');
    const { PNG } = await import('pngjs');
    const neutralQr = PNG.sync.read(Buffer.from(attributes.src.split(',', 2)[1], 'base64'));
    assert.deepEqual([neutralQr.width, neutralQr.height, neutralQr.data[3]], [1, 1, 0]);
    assert.equal(attributes.alt, 'Kein QR-Code: Zieladresse fehlt');
    assert.equal(await syncQrCode(editor, ''), false);

    assert.equal(await syncQrCode(editor, 'https://www.rail-time.de/de/karriere'), true);
    assert.equal(attributes['data-rt-qr-value'], 'https://www.rail-time.de/de/karriere');
    assert.match(attributes.src, /^data:image\/png;base64,iVBOR/);
    assert.equal(attributes.alt, 'QR-Code zur Bewerbung');
    assert.equal(await syncQrCode(editor, 'https://www.rail-time.de/de/karriere'), false);
});

test('adapter explicitly disables Joomla web defaults and fallback projects', async () => {
    const source = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8'));
    const editorSource = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../app/Livewire/Admin/Marketing/CreativeEditor.php', import.meta.url), 'utf8'));
    const appSource = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8'));
    const cssSource = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/css/marketing-studio.css', import.meta.url), 'utf8'));
    const coreSource = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/lmz-editor-core.js', import.meta.url), 'utf8'));
    const mailSource = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/mail-builder.js', import.meta.url), 'utf8'));

    assert.match(source, /useStudioWebDefaults:\s*false/);
    assert.match(source, /allowFallbackProject:\s*false/);
    assert.match(source, /document\.addEventListener\('livewire:navigating', destroyMarketingStudio\)/);
    assert.match(source, /instance\?\.destroy\?\.\(\)/);
    assert.match(source, /frame\.dataset\.readOnly = readOnly \? 'true' : 'false'/);
    assert.match(source, /createLmzEditorChrome\(\{/);
    assert.match(source, /showUrlInput:\s*false/);
    assert.match(source, /navigationCoordinator\?\.register\?\.\(navigationController\)/);
    assert.match(mailSource, /showUrlInput:\s*false/);
    assert.match(mailSource, /mode:\s*MAIL_EDITOR_MODE,\s*layout:\s*'elementor'/);
    assert.match(coreSource, /import '\.\/lmz-editor-assistant\.js';/);
    assert.match(source, /media:\s*\{\s*assets:\s*config\.assets \|\| \[\]/);
    assert.match(source, /request\.expected_hashes = Object\.fromEntries/);
    assert.match(editorSource, /'redesign'\s*=>\s*route\('admin\.marketing\.creatives\.redesign'/);
    assert.match(source, /persistSharedContent\(\{ preserveAssistantContext: true \}\)/);
    assert.match(source, /requestJson\(config\.endpoints\.redesign,[\s\S]+?method: 'POST',[\s\S]+?expected_hashes: expectedHashes/);
    assert.match(source, /applyAuthoritativeMarketingRedesignResponse\(config, payload\)[\s\S]+?startBuilder\(currentFormat, \{ preserveAssistantContext: true \}\)[\s\S]+?publishPageBuilderAssistantContext\(\)/);
    assert.match(source, /sharedContentDirty \|\| instance\?\.hasUnsavedChanges/);
    assert.match(source, /getBuilder:\s*\(\) => combinedDraft/);
    assert.match(source, /addEventListener\('input', refreshSharedContentDirty/);
    assert.match(source, /const requestSnapshot = JSON\.stringify\(request\)/);
    assert.match(source, /while \(sharedContentDirty \|\| instance\?\.hasUnsavedChanges\(\)\)/);
    assert.match(source, /workspace\.inert = true/);
    assert.match(source, /\[data-marketing-export\][\s\S]+?const saved = await persistSharedContent\(\)/);
    assert.match(source, /Entwurf wird gespeichert/);
    assert.match(source, /config\.logoLightUrl,\s*config\.logoDarkUrl,/);
    assert.doesNotMatch(source, /config\.logoUrl\b/);
    assert.match(source, /\[data-marketing-artboard-label\]/);
    assert.match(source, /\[data-marketing-scale-label\]/);
    assert.match(source, /marketing-editor:viewport-change/);
    assert.match(source, /assets:\s*{\s*onLoad:\s*async \(\) => config\.assets \|\| \[\]/);
    assert.match(source, /assetManager:\s*{\s*upload:\s*false,\s*showUrlInput:\s*false,\s*dropzone:\s*false\s*}/);
    assert.doesNotMatch(source, /\bonUpload\s*:/);
    assert.doesNotMatch(source, /assetUpload/);
    assert.doesNotMatch(source, /marketingAssetLibrary/);
    assert.doesNotMatch(appSource, /import '\.\/marketing-studio';/);
    assert.match(appSource, /from '\.\/mail-builder';/);
    assert.doesNotMatch(appSource, /Alpine\.data\('marketingAssetLibrary'/);
    assert.match(cssSource, /\[data-lmz-action='upload'\]\s*{\s*display:\s*none\s*!important/);
    assert.match(editorSource, /'logoLightUrl'\s*=>\s*asset\('rt-brand\/img\/logo-horizontal\.png'\)/);
    assert.match(editorSource, /'logoDarkUrl'\s*=>\s*asset\('rt-brand\/img\/logo-horizontal-darkbg\.png'\)/);
    assert.match(editorSource, /MarketingFileSourceService/);
    assert.doesNotMatch(editorSource, /MarketingAsset/);
    assert.doesNotMatch(editorSource, /assetUpload/);
});

function coreWithDom(markup, callback) {
    const previous = {
        window: globalThis.window,
        document: globalThis.document,
        CustomEvent: globalThis.CustomEvent,
        DOMParser: globalThis.DOMParser,
        requestAnimationFrame: globalThis.requestAnimationFrame,
        cancelAnimationFrame: globalThis.cancelAnimationFrame,
        __rtLmzCaptureAnimatedFrame: globalThis.__rtLmzCaptureAnimatedFrame,
    };
    const { window, document } = parseHTML(markup);
    globalThis.window = window;
    globalThis.document = document;
    globalThis.CustomEvent = window.CustomEvent;
    globalThis.DOMParser = window.DOMParser;
    globalThis.requestAnimationFrame = (callback_) => setTimeout(callback_, 0);
    globalThis.cancelAnimationFrame = clearTimeout;

    return Promise.resolve(callback({ window, document })).finally(() => {
        Object.entries(previous).forEach(([key, value]) => {
            if (value === undefined) delete globalThis[key];
            else globalThis[key] = value;
        });
    });
}

function coreFakeComponent(element, initial = {}) {
    const state = {
        type: initial.type || (element.tagName?.toLowerCase() === 'img' ? 'image' : 'default'),
        tagName: initial.tagName || element.tagName?.toLowerCase(),
        src: initial.src || '',
        attributes: { ...(initial.attributes || {}) },
        style: { ...(initial.style || {}) },
    };

    return {
        state,
        get(name) { return state[name]; },
        getAttributes() { return { ...state.attributes }; },
        getStyle() { return { ...state.style }; },
        getEl() { return element; },
        components(value) {
            if (value !== undefined) state.children = value;
            return state.children || [];
        },
        parent() { return initial.parent || null; },
        addAttributes(attributes) {
            Object.assign(state.attributes, attributes);
            if (attributes.src) state.src = attributes.src;
        },
        removeAttributes(names) {
            String(names).split(/\s+/).filter(Boolean).forEach((name) => delete state.attributes[name]);
        },
        set(name, value) { state[name] = value; },
        addStyle(styles) { Object.assign(state.style, styles); },
    };
}

function coreFakeEditor(root, selected, vendorSelection = null) {
    const handlers = new Map();
    const on = (name, callback) => {
        const callbacks = handlers.get(name) || [];
        callbacks.push(callback);
        handlers.set(name, callbacks);
    };
    if (vendorSelection) on('component:selected', vendorSelection);
    const tools = root.querySelector('[data-tools]');
    const toolbar = root.querySelector('[data-toolbar]');

    return {
        on,
        off(name, callback) {
            handlers.set(name, (handlers.get(name) || []).filter((item) => item !== callback));
        },
        emit(name, ...args) { (handlers.get(name) || []).forEach((callback) => callback(...args)); },
        getSelected: () => selected,
        getHtml: () => '<img src="https://evil.example/tracker.gif">',
        getCss: () => '',
        select() {},
        runCommand() {},
        Commands: { isActive: () => false },
        AssetManager: { setTarget() {}, close() {} },
        Canvas: {
            getToolsEl: () => tools,
            getToolbarEl: () => toolbar,
            getElementPos: () => ({ left: 120, top: 80, width: 240, height: 140, zoom: 0.5 }),
            getElementOffsets: () => ({
                marginTop: 10,
                marginRight: 6,
                marginBottom: 10,
                marginLeft: 6,
                paddingTop: 8,
                paddingRight: 5,
                paddingBottom: 8,
                paddingLeft: 5,
            }),
            getWindow: () => root.ownerDocument.defaultView,
            getDocument: () => root.ownerDocument,
        },
    };
}

test('shared LMZ capabilities keep mail GIF preview separate from persistent marketing motion', () => {
    const mail = normalizeLmzCapabilities('mail', { animation: true, classes: true });
    const marketing = normalizeLmzCapabilities('marketing');

    assert.equal(resolveLmzEditorMode(), LMZ_EDITOR_MODES.website);
    assert.equal(resolveLmzEditorMode('website'), LMZ_EDITOR_MODES.website);
    assert.equal(resolveLmzEditorMode('mail'), LMZ_EDITOR_MODES.mail);
    assert.equal(resolveLmzEditorMode('marketing'), LMZ_EDITOR_MODES.marketing);
    assert.throws(() => resolveLmzEditorMode('unsafe'), /Unbekannter LMZ-Editormodus/);
    assert.equal(resolveLmzEditorMode(''), LMZ_EDITOR_MODES.website);
    assert.equal(resolveLmzEditorMode(null), LMZ_EDITOR_MODES.website);
    assert.equal(LMZ_EDITOR_MODES.website.contentStrategy, 'full-document');
    assert.equal(LMZ_EDITOR_MODES.website.label, 'Website Page');
    assert.equal(LMZ_EDITOR_MODES.website.styleStrategy, 'stylesheet');
    assert.equal(LMZ_EDITOR_MODES.website.fidelityStrategy, 'source-preserving');
    assert.equal(LMZ_EDITOR_MODES.website.blockPrefix, 'rt-website-');
    assert.equal(LMZ_EDITOR_MODES.marketing.blockPrefix, 'rt-marketing-');
    assert.equal(LMZ_EDITOR_MODES.marketing.label, 'Motive');
    assert.equal(LMZ_EDITOR_MODES.mail.contentModel, 'email');
    assert.equal(LMZ_EDITOR_MODES.mail.label, 'E-Mail Template');
    assert.equal(LMZ_EDITOR_MODES.mail.styleStrategy, 'inline');
    assert.equal(LMZ_EDITOR_MODES.mail.fidelityStrategy, 'compiler-required');
    assert.equal(LMZ_EDITOR_MODES.mail.blockPrefix, 'rt-mail-');
    assert.equal(mail.animation, false);
    assert.equal(mail.imageReplace, 'tokens-only');
    assert.equal(mail.classes, false);
    assert.equal(mail.gifControls, true);
    assert.equal(mail.mediaInsert, false);
    assert.equal(marketing.animation, true);
    assert.equal(marketing.imageReplace, true);
});

test('fidelity session preserves untouched canonical channels and adopts only changed Grapes projections', async () => {
    const source = {
        html: '<section data-layout="hero">\n  <h1>Original source</h1>\n</section>',
        css: '.hero { color: #ec0033; }\n@media (max-width: 600px) { .hero { color: white; } }',
    };
    const baseline = {
        html: '<section data-layout="hero"><h1>Original source</h1></section>',
        css: '.hero{color:#ec0033}@media (max-width:600px){.hero{color:white}}',
    };
    let current = { ...baseline };
    const sourceProject = {
        pages: [{ id: 'story', frames: [{ id: 'frame-source', component: { id: 'hero-source' } }] }],
        styles: [{ selectors: ['hero'], style: { 'background-image': 'radial-gradient(circle,#fff,transparent),none' } }],
        railtime: { template: 'job_wagenmeister', format: 'story', schema: 4 },
    };
    const project = {
        pages: [{ id: 'story', frames: [{ id: 'frame-projected', component: { id: 'hero-projected' } }] }],
        styles: [{ selectors: ['hero'], style: { 'background-image': 'none' } }],
    };
    const session = createPageBuilderFidelitySession({
        mode: 'marketing',
        source,
        sourceProject,
        readProjection: () => current,
    });
    const loaded = session.captureProjection({ ...baseline, project });

    assert.equal(loaded.project, sourceProject);
    const untouched = session.capture({ ...baseline, project });
    assert.equal(untouched.mode, 'marketing');
    assert.equal(untouched.html, source.html);
    assert.equal(untouched.css, source.css);
    assert.equal(untouched.serializable, true);
    assert.deepEqual(untouched.report.changedChannels, []);
    assert.deepEqual(untouched.report.preservedChannels, ['html', 'css', 'project']);

    current = { ...baseline, html: '<section data-layout="hero"><h1>Bearbeitet</h1></section>' };
    const edited = session.serialize({ ...current, project });
    assert.equal(edited.html, current.html);
    assert.equal(edited.css, source.css);
    assert.equal(edited.project, sourceProject);
    assert.deepEqual(edited.report.changedChannels, ['html']);
    assert.equal(edited.report.channels.html.decision, 'editor-projection');
    assert.equal(edited.report.channels.css.decision, 'canonical-source');
    assert.equal(edited.report.channels.project.decision, 'canonical-source');

    const changedProject = structuredClone(project);
    changedProject.pages[0].frames[0].component.id = 'hero-edited';
    const withProjectEdit = session.serialize({ ...current, project: changedProject });
    assert.deepEqual(withProjectEdit.project, {
        ...changedProject,
        railtime: sourceProject.railtime,
    });
    assert.equal(withProjectEdit.project.railtime.template, 'job_wagenmeister');
    assert.deepEqual(withProjectEdit.report.changedChannels, ['html', 'project']);

    const serverProject = structuredClone(changedProject);
    serverProject.railtime = { mode: 'marketing', codec_version: 2 };
    const committed = session.acknowledgeServer({
        source: { html: current.html, css: source.css },
        project: serverProject,
        projection: { ...current, project: changedProject },
    });
    assert.equal(committed.revision, 1);
    assert.equal(committed.compiled, false);
    assert.equal(committed.serverAcknowledged, true);
    assert.equal(committed.project, serverProject);
    assert.equal(committed.html, current.html);
    assert.equal(committed.css, source.css);
    const stable = session.capture();
    assert.equal(stable.html, current.html);
    assert.equal(stable.css, source.css);
    assert.equal(stable.project, serverProject);
    assert.deepEqual(stable.report.changedChannels, []);
});

test('mail fidelity session fails closed without a compiler and commits only explicit compiler output', async () => {
    const source = {
        html: '<table role="presentation">\n<tr><td>Original</td></tr>\n</table>',
        css: '.mail-copy { color: #111827; }',
    };
    const projection = {
        html: '<table role="presentation"><tbody><tr><td>Original</td></tr></tbody></table>',
        css: '.mail-copy{color:#111827}',
    };
    const withoutCompiler = createPageBuilderFidelitySession({ mode: 'mail', source, projection });

    assert.equal(withoutCompiler.capture().serializable, false);
    assert.equal(withoutCompiler.report().committable, false);
    assert.throws(() => withoutCompiler.serialize(), /expliziten Compiler/);
    await assert.rejects(() => withoutCompiler.commit(), /expliziten Compiler/);

    let compilerInput = null;
    const withCompiler = createPageBuilderFidelitySession({
        mode: 'mail',
        source,
        projection,
        compiler: async (input) => {
            compilerInput = input;
            return {
                html: `<!-- compiled -->${input.html}`,
                css: `/* compiled */${input.css}`,
            };
        },
    });
    await assert.rejects(
        () => withCompiler.commit({ source, projection }),
        /acknowledgeServer/,
    );
    const committed = await withCompiler.commit({
        html: projection.html,
        css: '.mail-copy{color:#ec0033}',
    });

    assert.equal(compilerInput.html, source.html);
    assert.equal(compilerInput.css, '.mail-copy{color:#ec0033}');
    assert.deepEqual(compilerInput.report.changedChannels, ['css']);
    assert.equal(committed.compiled, true);
    assert.equal(committed.serializable, true);
    assert.match(committed.html, /^<!-- compiled -->/);
    assert.match(committed.css, /^\/\* compiled \*\//);
});

test('assistant block filtering follows the website profile instead of a marketing fallback', () => coreWithDom(`
    <div id="root"><div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div>
`, async ({ document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('section'));
    const editor = coreFakeEditor(root, selected);
    editor.BlockManager = {
        getAll: () => ({
            models: [
                { id: 'rt-website-hero' },
                { id: 'rt-marketing-hero' },
                { id: 'rt-mail-copy' },
            ],
        }),
    };
    const adapter = createLmzAssistantAdapter({
        root,
        instance: { editor, hasUnsavedChanges: () => false },
        chrome: { mediaState: () => ({ warnings: [] }) },
        mode: 'website',
        availableBlockIds: ['rt-website-footer', 'rt-marketing-footer'],
    });

    const context = await adapter.getContext();
    assert.equal(context.mode, 'website');
    assert.deepEqual(context.available_block_ids, ['rt-website-footer', 'rt-website-hero']);
    assert.equal(context.capabilities.includes('replace_image'), true);
    assert.equal(context.capabilities.includes('animation'), true);
    adapter.destroy();
}));

test('shared LMZ shell exposes the resolved mail profile and removes unsafe class controls', () => coreWithDom(`
    <div id="root">
        <div class="lmz-builder__topbar">
            <div class="lmz-builder__actions"><button data-lmz-action="assets">Medien</button></div>
            <div class="lmz-builder__panel-actions lmz-builder__panel-actions--left"></div>
            <button data-lmz-panel-toggle="right:classes" data-lmz-panel-group="right">Klassen</button>
        </div>
        <div class="lmz-builder__viewport">
            <section data-lmz-popover-panel="right:classes"><div data-lmz-mount="classes"></div></section>
            <div data-tools><div data-toolbar></div></div>
        </div>
    </div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const editor = coreFakeEditor(root, coreFakeComponent(document.createElement('p')));
    const classesToggle = root.querySelector('[data-lmz-panel-toggle="right:classes"]');
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: LMZ_EDITOR_MODES.mail });
    const indicator = root.querySelector('[data-rt-lmz-mode-indicator="mail"]');

    assert.equal(chrome.mode, LMZ_EDITOR_MODES.mail);
    assert.equal(root.dataset.rtLmzMode, 'mail');
    assert.equal(root.dataset.rtLmzContentModel, 'email');
    assert.equal(root.dataset.rtLmzContentStrategy, 'mail-document');
    assert.equal(root.dataset.rtLmzStyleStrategy, 'inline');
    assert.equal(root.dataset.rtLmzFidelityStrategy, 'compiler-required');
    assert.equal(root.dataset.rtLmzBlockPrefix, 'rt-mail-');
    assert.equal(indicator.getAttribute('role'), 'status');
    assert.match(indicator.getAttribute('aria-label'), /Aktiver Editormodus: E-Mail Template/);
    assert.match(indicator.textContent, /Mailclient-sichere Bausteine/);
    assert.equal(classesToggle.hidden, true);
    assert.equal(chrome.openPanel('classes'), false);
    assert.equal(root.querySelector('[data-rt-lmz-panel-search="classes"]'), null);

    chrome.destroy();
    assert.equal(root.querySelector('[data-rt-lmz-mode-indicator]'), null);
    assert.equal(root.dataset.rtLmzFidelityStrategy, undefined);
    assert.equal(root.dataset.rtLmzBlockPrefix, undefined);
    assert.equal(classesToggle.hidden, false);
}));

test('shared panel experience filters nested layers and inspector controls without mutating editor data', () => coreWithDom(`
    <div id="root">
        <div class="lmz-builder__topbar">
            <div class="lmz-builder__actions"><button data-lmz-action="assets">Medien</button></div>
            <div class="lmz-builder__panel-actions lmz-builder__panel-actions--left">
                <button data-lmz-panel-toggle="left:layers" data-lmz-panel-group="left" aria-expanded="false"><span class="lmz-builder__action-label">Ebenen</span></button>
            </div>
            <div class="lmz-builder__panel-actions lmz-builder__panel-actions--right">
                <button data-lmz-panel-toggle="right:styles" data-lmz-panel-group="right" aria-expanded="false"><span class="lmz-builder__action-label">Stile</span></button>
                <button data-lmz-panel-toggle="right:traits" data-lmz-panel-group="right" aria-expanded="false"><span class="lmz-builder__action-label">Eigenschaften</span></button>
                <button data-lmz-panel-toggle="right:classes" data-lmz-panel-group="right" aria-expanded="false"><span class="lmz-builder__action-label">Klassen</span></button>
            </div>
        </div>
        <div class="lmz-builder__viewport">
            <main class="lmz-builder__main"><div data-tools><div data-toolbar></div></div></main>
            <aside class="lmz-builder__popover is-open" data-lmz-popover="left">
                <section class="lmz-builder__popover-panel is-active" data-lmz-popover-panel="left:layers">
                    <header class="lmz-builder__popover-head"><div class="lmz-builder__popover-title"><span class="lmz-builder__popover-icon"></span><strong>Ebenen</strong></div></header>
                    <div class="lmz-builder__popover-body"><div class="lmz-builder__mount" data-lmz-mount="layers">
                        <div class="lmzbjs-layers">
                            <div class="lmzbjs-layer" data-layer="hero"><div class="lmzbjs-layer-item"><span class="lmzbjs-layer-name">Hero</span></div><div class="lmzbjs-layer-children"><div class="lmzbjs-layer" data-layer="cta"><div class="lmzbjs-layer-item"><span class="lmzbjs-layer-name">Bewerben CTA</span></div></div></div></div>
                            <div class="lmzbjs-layer" data-layer="footer"><div class="lmzbjs-layer-item"><span class="lmzbjs-layer-name">Footer</span></div></div>
                        </div>
                    </div></div>
                </section>
            </aside>
            <aside class="lmz-builder__popover is-open" data-lmz-popover="right">
                <section class="lmz-builder__popover-panel is-active" data-lmz-popover-panel="right:styles">
                    <header class="lmz-builder__popover-head"><div class="lmz-builder__popover-title"><span class="lmz-builder__popover-icon"></span><strong>Stile</strong></div></header>
                    <div class="lmz-builder__popover-body"><div class="lmz-builder__mount" data-lmz-mount="styles"><div class="lmzbjs-sm-sector lmzbjs-sm-open"><div class="lmzbjs-sm-sector-title">Typografie</div><div class="lmzbjs-sm-properties"><div class="lmzbjs-sm-property">Schriftgröße</div><div class="lmzbjs-sm-property">Zeilenhöhe</div></div></div></div></div>
                </section>
                <section class="lmz-builder__popover-panel" data-lmz-popover-panel="right:traits" hidden>
                    <header class="lmz-builder__popover-head"><div class="lmz-builder__popover-title"><span class="lmz-builder__popover-icon"></span><strong>Eigenschaften</strong></div></header>
                    <div class="lmz-builder__popover-body"><div class="lmz-builder__mount" data-lmz-mount="traits"><div class="lmzbjs-trait-category lmzbjs-open"><div class="lmzbjs-title">Inhalt</div><div class="lmzbjs-trt-traits"><div class="lmzbjs-trt-trait">Linkziel</div><div class="lmzbjs-trt-trait">Titel</div></div></div></div></div>
                </section>
                <section class="lmz-builder__popover-panel" data-lmz-popover-panel="right:classes" hidden>
                    <header class="lmz-builder__popover-head"><div class="lmz-builder__popover-title"><span class="lmz-builder__popover-icon"></span><strong>Klassen</strong></div></header>
                    <div class="lmz-builder__popover-body"><div class="lmz-builder__mount" data-lmz-mount="classes"><div class="lmzbjs-clm-tags-c"><span class="lmzbjs-clm-tag">hero-dark</span><span class="lmzbjs-clm-tag">spacing-wide</span></div></div></div>
                </section>
            </aside>
        </div>
    </div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    document.defaultView.MutationObserver = undefined;
    const selected = coreFakeComponent(document.createElement('section'), { tagName: 'section' });
    selected.state.name = 'Hero-Sektion';
    selected.state.traits = [{ name: 'title' }];
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'marketing', layout: 'elementor' });
    const searches = root.querySelectorAll('[data-rt-lmz-panel-search]');

    assert.equal(searches.length, 4);
    assert.match(root.querySelector('[data-lmz-popover-panel="right:styles"] .rt-lmz-panel-subtitle').textContent, /Typografie/);
    assert.equal(root.querySelector('[data-lmz-popover-panel="right:styles"] [data-rt-lmz-panel-context]').textContent, 'Hero-Sektion');
    assert.equal(root.querySelector('[data-lmz-popover-panel="left:layers"] [data-rt-lmz-panel-count]').textContent, '3 Ebenen');
    assert.equal(root.querySelector('.lmzbjs-trait-category > .lmzbjs-title').getAttribute('aria-expanded'), 'true');

    const layerSearch = root.querySelector('[data-rt-lmz-panel-search="layers"]');
    layerSearch.value = 'cta';
    layerSearch.dispatchEvent(new document.defaultView.Event('input', { bubbles: true }));
    assert.equal(root.querySelector('[data-layer="cta"]').classList.contains('rt-lmz-panel-filter-match'), true);
    assert.equal(root.querySelector('[data-layer="hero"]').classList.contains('rt-lmz-panel-filter-ancestor'), true);
    assert.equal(root.querySelector('[data-layer="footer"]').classList.contains('rt-lmz-panel-filtered-out'), true);
    assert.equal(root.querySelector('[data-lmz-popover-panel="left:layers"] [data-rt-lmz-panel-count]').textContent, '1 Treffer');

    let rowClicks = 0;
    const ctaRow = root.querySelector('[data-layer="cta"] > .lmzbjs-layer-item');
    ctaRow.addEventListener('click', () => { rowClicks += 1; });
    const enter = new document.defaultView.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(enter, 'key', { value: 'Enter' });
    ctaRow.dispatchEvent(enter);
    assert.equal(ctaRow.getAttribute('role'), 'button');
    assert.equal(rowClicks, 1);

    const escape = new document.defaultView.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(escape, 'key', { value: 'Escape' });
    layerSearch.dispatchEvent(escape);
    assert.equal(escape.defaultPrevented, true);
    assert.equal(layerSearch.value, '');
    assert.equal(root.querySelector('[data-layer="footer"]').classList.contains('rt-lmz-panel-filtered-out'), false);

    chrome.destroy();
    assert.equal(root.querySelector('[data-rt-lmz-panel-search]'), null);
    assert.equal(root.querySelector('[data-lmz-mount="layers"]').parentElement.className, 'lmz-builder__popover-body');
    assert.deepEqual(
        [...root.querySelector('[data-lmz-popover-panel="left:layers"] .lmz-builder__popover-body').children]
            .map((element) => element.dataset.lmzMount || element.className),
        ['layers'],
    );
}));

test('mail chrome separates vendor panels into accessible left navigation and right inspector docks', () => coreWithDom(`
    <div id="root">
        <div class="lmz-builder__topbar">
            <div class="lmz-builder__actions"><button data-lmz-action="save">Save</button></div>
            <div class="lmz-builder__panel-actions lmz-builder__panel-actions--left">
                <button data-lmz-panel-toggle="left:blocks" data-lmz-panel-group="left" aria-haspopup="dialog" aria-expanded="false"><span class="lmz-builder__action-label">Bausteine</span></button>
                <button data-lmz-panel-toggle="left:layers" data-lmz-panel-group="left" aria-haspopup="dialog" aria-expanded="false"><span class="lmz-builder__action-label">Ebenen</span></button>
            </div>
            <div class="lmz-builder__panel-actions lmz-builder__panel-actions--right">
                <button data-lmz-panel-toggle="right:styles" data-lmz-panel-group="right" aria-haspopup="dialog" aria-expanded="false"><span class="lmz-builder__action-label">Stile</span></button>
                <button data-lmz-panel-toggle="right:traits" data-lmz-panel-group="right" aria-haspopup="dialog" aria-expanded="false"><span class="lmz-builder__action-label">Eigenschaften</span></button>
                <button data-lmz-panel-toggle="right:classes" data-lmz-panel-group="right" aria-haspopup="dialog" aria-expanded="false"><span class="lmz-builder__action-label">Klassen</span></button>
            </div>
            <div class="lmz-builder__meta"><button data-lmz-action="selection">Auswahl</button><div data-lmz-status>Status</div></div>
        </div>
        <div class="lmz-builder__viewport">
            <main class="lmz-builder__main"><div data-tools><div data-toolbar></div></div></main>
            <aside class="lmz-builder__popover" data-lmz-popover="left" hidden>
                <section data-lmz-popover-panel="left:blocks" hidden><button data-lmz-panel-close="left">Schliessen</button><div data-lmz-mount="blocks"></div></section>
                <section data-lmz-popover-panel="left:layers" hidden><button data-lmz-panel-close="left">Schliessen</button><div data-lmz-mount="layers"></div></section>
            </aside>
            <aside class="lmz-builder__popover" data-lmz-popover="right" hidden>
                <section data-lmz-popover-panel="right:styles" hidden><button data-lmz-panel-close="right">Schliessen</button><div data-lmz-mount="styles"></div></section>
                <section data-lmz-popover-panel="right:traits" hidden><button data-lmz-panel-close="right">Schliessen</button><div data-lmz-mount="traits"></div></section>
                <section data-lmz-popover-panel="right:classes" hidden><button data-lmz-panel-close="right">Schliessen</button><div data-lmz-mount="classes"></div></section>
            </aside>
        </div>
    </div>
`, async ({ document }) => {
    const root = document.querySelector('#root');
    let compactViewport = false;
    document.defaultView.matchMedia = () => ({ get matches() { return compactViewport; } });
    const state = { left: null, right: null };
    const render = (group) => {
        root.querySelectorAll(`[data-lmz-panel-group="${group}"]`).forEach((toggle) => {
            const active = toggle.dataset.lmzPanelToggle === state[group];
            toggle.classList.toggle('is-active', active);
            toggle.setAttribute('aria-expanded', active ? 'true' : 'false');
        });
        root.querySelectorAll(`[data-lmz-popover-panel^="${group}:"]`).forEach((panel) => {
            const active = panel.dataset.lmzPopoverPanel === state[group];
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
        const popover = root.querySelector(`[data-lmz-popover="${group}"]`);
        popover.classList.toggle('is-open', Boolean(state[group]));
        popover.hidden = !state[group];
    };
    root.querySelectorAll('[data-lmz-panel-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const group = toggle.dataset.lmzPanelGroup;
            state[group] = state[group] === toggle.dataset.lmzPanelToggle ? null : toggle.dataset.lmzPanelToggle;
            render(group);
        });
    });
    root.querySelectorAll('[data-lmz-panel-close]').forEach((close) => {
        close.addEventListener('click', () => {
            state[close.dataset.lmzPanelClose] = null;
            render(close.dataset.lmzPanelClose);
        });
    });

    const selected = coreFakeComponent(document.createElement('p'));
    selected.state.traits = [{ name: 'title' }];
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail', layout: 'elementor' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    const navigation = root.querySelector('[data-rt-lmz-control-dock][data-rt-lmz-side="left"]');
    const inspector = root.querySelector('[data-rt-lmz-control-dock][data-rt-lmz-side="right"]');
    const main = root.querySelector('.lmz-builder__main');
    const navigationTabs = [...navigation.querySelectorAll('[role="tab"]')];
    const inspectorTabs = [...inspector.querySelectorAll('[role="tab"]')];
    assert.equal(chrome.layout, 'elementor');
    assert.equal(root.dataset.rtLmzLayout, 'elementor');
    assert.equal(navigation.parentElement, root.querySelector('.lmz-builder__viewport'));
    assert.equal(navigation.nextElementSibling, main);
    assert.equal(main.nextElementSibling, inspector);
    assert.equal(navigation.getAttribute('aria-label'), 'Editor-Navigation');
    assert.equal(inspector.getAttribute('aria-label'), 'Editor-Einstellungen');
    assert.equal(root.querySelector('[data-rt-lmz-mode-indicator]').parentElement.className, 'rt-lmz-control-dock__header');
    assert.deepEqual(navigationTabs.map((button) => button.textContent), ['Bausteine', 'Ebenen']);
    assert.deepEqual(inspectorTabs.map((button) => button.textContent), ['Eigenschaften', 'Stile', 'Klassen']);
    assert.equal(navigation.querySelector('.rt-lmz-control-dock__panels [data-lmz-popover="left"]') !== null, true);
    assert.equal(navigation.querySelector('[data-lmz-popover="right"]'), null);
    assert.equal(inspector.querySelector('.rt-lmz-control-dock__panels [data-lmz-popover="right"]') !== null, true);
    assert.equal(inspector.querySelector('[data-lmz-popover="left"]'), null);
    assert.equal(inspector.querySelector('.rt-lmz-control-dock__footer .lmz-builder__meta') !== null, true);
    assert.equal(root.querySelector('[data-lmz-panel-toggle="left:blocks"]').getAttribute('aria-selected'), 'true');
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:traits"]').getAttribute('aria-selected'), 'true');
    assert.equal(root.querySelector('[data-lmz-popover-panel="right:traits"]').getAttribute('role'), 'tabpanel');
    assert.equal(root.querySelectorAll('[data-lmz-popover].is-open').length, 2);

    root.querySelector('[data-lmz-panel-toggle="left:layers"]').click();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(root.querySelector('[data-lmz-panel-toggle="left:layers"]').getAttribute('aria-selected'), 'true');
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:traits"]').getAttribute('aria-selected'), 'true');
    assert.equal(root.querySelectorAll('[data-lmz-popover].is-open').length, 2);

    const layers = root.querySelector('[data-lmz-panel-toggle="left:layers"]');
    const blocks = root.querySelector('[data-lmz-panel-toggle="left:blocks"]');
    const arrowRight = new document.defaultView.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(arrowRight, 'key', { value: 'ArrowRight' });
    layers.dispatchEvent(arrowRight);
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(blocks.getAttribute('aria-selected'), 'true');
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:traits"]').getAttribute('aria-selected'), 'true');

    compactViewport = true;
    root.querySelector('[data-lmz-panel-toggle="right:styles"]').click();
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(blocks.getAttribute('aria-selected'), 'false');
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:styles"]').getAttribute('aria-selected'), 'true');
    assert.equal(root.querySelectorAll('[data-lmz-popover].is-open').length, 1);

    chrome.destroy();
    assert.equal(root.querySelectorAll('[data-rt-lmz-control-dock]').length, 0);
    assert.equal(root.hasAttribute('data-rt-lmz-layout'), false);
    assert.equal(root.querySelector('.lmz-builder__topbar > .lmz-builder__panel-actions--left') !== null, true);
    assert.equal(root.querySelector('.lmz-builder__topbar > .lmz-builder__panel-actions--right') !== null, true);
    assert.equal(root.querySelector('.lmz-builder__topbar > .lmz-builder__meta') !== null, true);
    assert.equal(root.querySelector('.lmz-builder__viewport > [data-lmz-popover="left"]') !== null, true);
    assert.equal(root.querySelector('.lmz-builder__viewport > [data-lmz-popover="right"]') !== null, true);
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:traits"] .lmz-builder__action-label').textContent, 'Eigenschaften');
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:traits"]').getAttribute('role'), null);
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:traits"]').getAttribute('aria-haspopup'), 'dialog');
}));

test('shared LMZ media inventory exposes missing sources without loading external previews', () => {
    const state = collectUsedMedia({
        html: '<img src="/administrator/files/7/preview"><img src="https://evil.example/tracker.gif">',
        assets: [{ src: '/administrator/files/7/preview', name: 'Lok.jpg', type: 'image' }],
        baseUrl: 'https://railtime.test/',
    });

    assert.equal(state.used.length, 2);
    assert.equal(state.used[0].allowed, true);
    assert.equal(state.used[1].allowed, false);
    assert.equal(state.warnings.length, 1);
});

test('mail media drawer follows the active preview theme while keeping every token discoverable', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-media-theme">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('div'));
    const editor = coreFakeEditor(root, selected);
    editor.getHtml = () => '<img data-rt-mail-preview-token="LOGO_SRC">';
    let theme = 'light';
    const light = 'data:image/png;base64,bGlnaHQ=';
    const dark = 'data:image/png;base64,ZGFyaw==';
    const chrome = createLmzEditorChrome({
        instance: { editor },
        root,
        mode: 'mail',
        media: {
            tokenMedia: () => [{ token: 'LOGO_SRC', label: 'RailTime Firmenlogo', src: theme === 'dark' ? dark : light }],
        },
    });

    chrome.openMedia({ initialTab: 'used' });
    assert.equal(root.querySelector('.rt-lmz-media-item img')?.getAttribute('src'), light);
    theme = 'dark';
    chrome.refresh();
    assert.equal(root.querySelector('.rt-lmz-media-item img')?.getAttribute('src'), dark);
    chrome.destroy();
}));

test('shared LMZ spacing geometry applies zoom once and converts drag deltas back to CSS pixels', () => {
    const geometry = calculateSpacingOverlayGeometry({
        position: { left: 100, top: 60, width: 200, height: 120, zoom: 0.5 },
        offsets: {
            // GrapesJS already reports zoom-scaled offsets: CSS 20/10/16/8
            // becomes 10/5/8/4 at 50%.
            marginTop: 10,
            marginRight: 5,
            marginBottom: 10,
            marginLeft: 5,
            paddingTop: 8,
            paddingRight: 4,
            paddingBottom: 8,
            paddingLeft: 4,
        },
    });

    assert.equal(geometry.spacing.margin.top, 10);
    assert.equal(geometry.margin.top.top, 50);
    assert.equal(geometry.spacing.padding.left, 4);
    assert.equal(spacingCssSnapshot({ marginTop: 10, paddingLeft: 4 }, 0.5).margin.top, 20);
    assert.equal(spacingCssSnapshot({ marginBottom: -10 }, 0.5).margin.bottom, -20);
    assert.equal(spacingCssSnapshot({ marginTop: 10, paddingLeft: 4 }, 0.5).padding.left, 8);
    assert.equal(calculateSpacingDragValue({ startValue: 12, deltaX: 10, zoom: 0.5, side: 'right', type: 'margin' }), 32);
    assert.equal(calculateSpacingDragValue({ startValue: 2, deltaY: -10, zoom: 0.5, side: 'bottom', type: 'margin' }), -18);
    assert.equal(calculateSpacingDragValue({ startValue: 4, deltaY: -10, zoom: 0.5, side: 'bottom', type: 'padding' }), 0);
});

test('shared spacing overlay stays inactive on every fixed mail signature geometry node', () => coreWithDom(`
    <div id="root"><div data-tools></div></div>
`, ({ window, document }) => {
    const fixedClasses = [
        'rt-sign-stage',
        'rt-sign-train-layer',
        'rt-sign-train-frame',
        'rt-sign-train-slot',
        'rt-sign-content-frame',
    ];

    fixedClasses.forEach((className) => {
        const element = document.createElement(className.includes('frame') ? 'table' : 'div');
        const selected = coreFakeComponent(element, { attributes: { class: className } });
        const editor = coreFakeEditor(document.querySelector('#root'), selected);
        assert.equal(isFixedMailSignatureGeometry(selected), true, className);
        const controller = createSpacingOverlayController({
            editor,
            root: document.querySelector('#root'),
            environment: {
                document,
                window,
                requestAnimationFrame: (callback) => { callback(); return 1; },
                cancelAnimationFrame() {},
            },
        });
        assert.equal(document.querySelector('.rt-lmz-spacing-overlay')?.hidden, true, className);
        controller.destroy();
    });

    assert.equal(isFixedMailSignatureGeometry(coreFakeComponent(document.createElement('p'))), false);
}));

test('spacing overlay uses the canvas tools layer without doubling the positioned selection offset', () => coreWithDom(`
    <div id="root"><div id="canvas"><div id="tools-layer" class="lmzbjs-cv-canvas__tools"><div id="tools" data-tools></div></div></div></div>
`, ({ window, document }) => {
    const root = document.querySelector('#root');
    const canvas = document.querySelector('#canvas');
    const toolsLayer = document.querySelector('#tools-layer');
    const tools = document.querySelector('#tools');
    const box = (left, top, width, height) => ({
        left, top, width, height, right: left + width, bottom: top + height,
    });
    const cases = [
        {
            label: 'marketing post root at 45 percent',
            tag: 'section',
            canvasRect: box(372, 475, 1200, 720),
            toolsLayerRect: box(-189.5, -17, 1800, 1400),
            selectionRect: box(561.5, 494, 483, 483),
            position: { left: 751, top: 511, width: 483, height: 483, zoom: 0.45 },
        },
        {
            label: 'marketing image child at 45 percent',
            tag: 'img',
            canvasRect: box(372, 475, 1200, 720),
            toolsLayerRect: box(-189.5, -17, 1800, 1400),
            selectionRect: box(720.25, 530.5, 216, 144),
            position: { left: 909.75, top: 547.5, width: 216, height: 144, zoom: 0.45 },
        },
        {
            label: 'mail selection without centered marketing frame',
            tag: 'div',
            canvasRect: box(28, 100, 1200, 700),
            toolsLayerRect: box(28, 100, 1200, 700),
            selectionRect: box(128, 180, 240, 140),
            position: { left: 100, top: 80, width: 240, height: 140, zoom: 0.5 },
        },
    ];

    cases.forEach((fixture) => {
        const element = document.createElement(fixture.tag);
        if (fixture.tag === 'img') element.setAttribute('src', '/marketing/train.jpg');
        const selected = coreFakeComponent(element, fixture.tag === 'img'
            ? { type: 'image', attributes: { src: '/marketing/train.jpg' } }
            : {});
        canvas.getBoundingClientRect = () => fixture.canvasRect;
        toolsLayer.getBoundingClientRect = () => fixture.toolsLayerRect;
        tools.getBoundingClientRect = () => fixture.selectionRect;
        const editor = coreFakeEditor(root, selected);
        editor.Canvas.getElement = () => canvas;
        editor.Canvas.getToolsEl = () => tools;
        editor.Canvas.getElementPos = () => fixture.position;
        editor.Canvas.getElementOffsets = () => ({
            marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0,
            paddingTop: 0, paddingRight: 0, paddingBottom: 0, paddingLeft: 0,
            borderTopWidth: 0, borderRightWidth: 0, borderBottomWidth: 0, borderLeftWidth: 0,
        });
        const controller = createSpacingOverlayController({
            editor,
            root,
            environment: {
                document,
                window,
                requestAnimationFrame: (callback) => { callback(); return 1; },
                cancelAnimationFrame() {},
            },
        });
        const overlay = toolsLayer.querySelector('.rt-lmz-spacing-overlay');
        const visualRect = (side) => {
            const handle = overlay.querySelector(`[data-type="padding"][data-side="${side}"]`);
            const surface = handle.querySelector('.rt-lmz-spacing-overlay__surface');
            const handleRect = box(
                fixture.toolsLayerRect.left + Number.parseFloat(handle.style.left),
                fixture.toolsLayerRect.top + Number.parseFloat(handle.style.top),
                Number.parseFloat(handle.style.width),
                Number.parseFloat(handle.style.height),
            );
            surface.getBoundingClientRect = () => box(
                handleRect.left + Number.parseFloat(surface.style.left),
                handleRect.top + Number.parseFloat(surface.style.top),
                Number.parseFloat(surface.style.width),
                Number.parseFloat(surface.style.height),
            );
            const surfaceRect = surface.getBoundingClientRect();
            assert.ok(handleRect.left <= surfaceRect.left, `${fixture.label}: ${side} hit target starts before its surface`);
            assert.ok(handleRect.top <= surfaceRect.top, `${fixture.label}: ${side} hit target starts before its surface`);
            assert.ok(handleRect.right >= surfaceRect.right, `${fixture.label}: ${side} hit target ends after its surface`);
            assert.ok(handleRect.bottom >= surfaceRect.bottom, `${fixture.label}: ${side} hit target ends after its surface`);
            return surfaceRect;
        };
        const top = visualRect('top');
        const right = visualRect('right');
        const bottom = visualRect('bottom');
        const left = visualRect('left');

        assert.equal(overlay.parentElement, toolsLayer, `${fixture.label}: overlay belongs to the canvas-origin tools layer`);
        assert.equal(tools.querySelector('.rt-lmz-spacing-overlay'), null, `${fixture.label}: selection offset is not applied twice`);
        assert.deepEqual(
            { left: top.left, top: top.top, right: top.right },
            { left: fixture.selectionRect.left, top: fixture.selectionRect.top, right: fixture.selectionRect.right },
            `${fixture.label}: top surface follows the selected box`,
        );
        assert.equal(right.right, fixture.selectionRect.right, `${fixture.label}: right surface follows the selected box`);
        assert.deepEqual(
            { left: bottom.left, right: bottom.right, bottom: bottom.bottom },
            { left: fixture.selectionRect.left, right: fixture.selectionRect.right, bottom: fixture.selectionRect.bottom },
            `${fixture.label}: bottom surface follows the selected box`,
        );
        assert.equal(left.left, fixture.selectionRect.left, `${fixture.label}: left surface follows the selected box`);
        assert.deepEqual(editor.Canvas.getElementPos(element), fixture.position, `${fixture.label}: GrapesJS zoomed geometry stays unchanged`);
        controller.destroy();
    });
}));

test('spacing overlay observes canvas and selection resizing and releases every observer target', () => coreWithDom(`
    <div id="root"><div id="tools-layer"><div id="tools"></div></div><iframe id="frame"></iframe></div>
`, ({ window, document }) => {
    const selectedElement = document.createElement('div');
    const selected = coreFakeComponent(selectedElement);
    const editor = coreFakeEditor(document.querySelector('#root'), selected);
    const frame = document.querySelector('#frame');
    const tools = document.querySelector('#tools');
    const observed = [];
    let disconnected = 0;
    let observerCallback = null;
    const frames = [];
    class FakeResizeObserver {
        constructor(callback) { observerCallback = callback; }
        observe(target) { observed.push(target); }
        disconnect() { disconnected += 1; }
    }
    editor.Canvas.getToolsEl = () => tools;
    editor.Canvas.getFrameEl = () => frame;
    editor.Canvas.getDocument = () => document;
    let positions = 0;
    editor.Canvas.getElementPos = () => {
        positions += 1;
        return { left: 10, top: 20, width: 100, height: 80, zoom: 1 };
    };
    editor.Canvas.getElementOffsets = () => ({
        marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0,
        paddingTop: 8, paddingRight: 8, paddingBottom: 8, paddingLeft: 8,
        borderTopWidth: 0, borderRightWidth: 0, borderBottomWidth: 0, borderLeftWidth: 0,
    });

    const controller = createSpacingOverlayController({
        editor,
        root: document.querySelector('#root'),
        environment: {
            document,
            window,
            ResizeObserver: FakeResizeObserver,
            requestAnimationFrame: (callback) => { frames.push(callback); return frames.length; },
            cancelAnimationFrame() {},
        },
    });
    frames.shift()?.();

    assert.ok(observed.includes(document.querySelector('#tools-layer')));
    assert.ok(observed.includes(frame));
    assert.ok(observed.includes(document.documentElement));
    assert.ok(observed.includes(document.body));
    assert.ok(observed.includes(selectedElement));
    const beforeResize = positions;
    observerCallback();
    frames.shift()?.();
    assert.ok(positions > beforeResize);
    controller.destroy();
    assert.ok(disconnected >= 1);
}));

test('scoped FilePool GIF metadata survives opaque admin URLs and is cleared by a static replacement', () => coreWithDom('<img id="target">', ({ document }) => {
    const element = document.querySelector('#target');
    const selected = coreFakeComponent(element, { attributes: {} });
    const gif = {
        src: '/administrator/marketing/dateien/42?v=abcdef',
        name: 'zug-animation.gif',
        type: 'image',
        mime_type: 'image/gif',
    };
    const png = {
        src: '/administrator/marketing/dateien/43?v=fedcba',
        name: 'zug-standbild.png',
        type: 'image',
        mime_type: 'image/png',
    };
    const editor = coreFakeEditor(document.body, selected);
    const selection = createImageAssetSelection({ editor, target: selected, assets: [gif, png] });

    selection.select(gif, false);
    assert.equal(selected.state.attributes['data-mime-type'], 'image/gif');
    assert.equal(selected.state.attributes['data-rt-animated-media'], 'gif');
    assert.equal(componentAnimationContext(selected).animated, true);

    selection.select(png, false);
    assert.equal(selected.state.attributes['data-mime-type'], 'image/png');
    assert.equal(Object.hasOwn(selected.state.attributes, 'data-rt-animated-media'), false);
    assert.equal(componentAnimationContext(selected).animated, false);
}));

test('mail content images and editable brand tokens use only scoped replacement assets', () => coreWithDom('<img id="content"><img id="token">', ({ document }) => {
    const contentImage = coreFakeComponent(document.querySelector('#content'), {
        type: 'image',
        attributes: { src: 'data:image/png;base64,b2xk' },
    });
    const tokenImage = coreFakeComponent(document.querySelector('#token'), {
        type: 'image',
        attributes: {
            src: 'about:blank',
            'data-rt-mail-preview-token': 'LOGO_SRC',
        },
    });
    const allowed = {
        src: 'data:image/gif;base64,bmV1',
        name: 'Freigegebenes Mailbild.gif',
        type: 'image',
        mime_type: 'image/gif',
    };
    const editor = coreFakeEditor(document.body, contentImage);
    const selection = createImageAssetSelection({
        editor,
        target: contentImage,
        assets: [allowed],
        mode: 'mail',
    });

    assert.equal(selection.select(allowed, false), allowed.src);
    assert.equal(contentImage.state.src, allowed.src);
    assert.equal(contentImage.state.attributes['data-rt-animated-media'], 'gif');
    const tokenSelection = createImageAssetSelection({ editor, target: tokenImage, assets: [allowed], mode: 'mail' });
    assert.equal(tokenSelection.select(allowed, false), allowed.src);
    assert.equal(tokenImage.state.src, allowed.src);
    assert.equal(tokenImage.state.attributes['data-rt-mail-preview-token'], 'LOGO_SRC');
    assert.throws(
        () => selection.select({ src: 'https://evil.example/frei.png' }, false),
        /freigegebenen Dateibibliothek/,
    );
}));

test('mail content images keep alt size and GIF tools without advertising replacement when no mail assets exist', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-mail-image">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Medien</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('img'), {
        type: 'image',
        attributes: {
            src: 'data:image/gif;base64,bWFpbA==',
            alt: 'Inhaltsbild',
            'data-mime-type': 'image/gif',
        },
    });
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({
        instance: { editor },
        root,
        mode: 'mail',
        capabilities: { imageReplace: 'tokens-only', gifControls: true },
        media: { assets: [], tokenMedia: [] },
    });

    root.querySelector('.rt-lmz-inline-edit-trigger').click();
    const actions = [...root.querySelectorAll('[data-rt-lmz-inline-action]')]
        .map((item) => item.dataset.rtLmzInlineAction);
    assert.equal(actions.includes('traits'), true);
    assert.equal(actions.includes('styles'), true);
    assert.equal(actions.includes('media'), true);
    assert.equal(actions.includes('animation'), true);
    assert.equal(actions.includes('gif-playback'), true);
    assert.equal(actions.includes('gif-restart'), true);
    assert.equal(actions.includes('replace'), false);

    chrome.destroy();
}));

test('shared mail image inspector exposes honest GIF metadata and keeps real images visible in layers', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-mail-image-inspector">
    <div id="root">
        <div class="lmz-builder__topbar"><button data-lmz-action="assets">Medien</button><button data-lmz-panel-toggle="right:traits" data-lmz-panel-group="right">Eigenschaften</button></div>
        <div class="lmz-builder__viewport">
            <section data-lmz-popover-panel="right:traits"><div><div data-lmz-mount="traits"></div></div></section>
            <div data-tools><div data-toolbar></div></div>
        </div>
        <img id="logo" src="/mail/logo.gif" alt="RailTime">
    </div></div></div>
`, async ({ document }) => {
    const root = document.querySelector('#root');
    const wrapper = coreFakeComponent(document.createElement('div'));
    const logo = coreFakeComponent(document.querySelector('#logo'), {
        parent: wrapper,
        type: 'image',
        attributes: {
            src: '/mail/logo.gif',
            alt: 'RailTime',
            'data-rt-mail-preview-token': 'LOGO_SRC',
        },
    });
    const trainCarrier = coreFakeComponent(document.createElement('span'), {
        parent: wrapper,
        attributes: { class: 'rt-sign-train-layer' },
    });
    const train = coreFakeComponent(document.createElement('img'), {
        parent: trainCarrier,
        type: 'image',
        attributes: {
            src: '/mail/train.gif',
            alt: '',
            'data-rt-mail-preview-token': 'TRAIN_SRC',
        },
    });
    const protectedIcon = coreFakeComponent(document.createElement('img'), {
        parent: wrapper,
        type: 'image',
        attributes: {
            src: '/mail/phone.gif',
            alt: 'Telefon',
            'data-rt-mail-preview-token': 'ICON_PHONE_SRC',
        },
    });
    trainCarrier.components([train]);
    wrapper.components([logo, trainCarrier, protectedIcon]);
    const editor = coreFakeEditor(root, logo);
    let selected = logo;
    editor.getSelected = () => selected;
    editor.getWrapper = () => wrapper;
    const chrome = createLmzEditorChrome({
        instance: { editor },
        root,
        mode: 'mail',
        capabilities: { gifControls: true },
        media: {
            // Entspricht bewusst der realen Produktionsdefinition: Token,
            // Label und Quelle; fehlende Datei-Metadaten muessen ehrlich als
            // nicht verfuegbar erscheinen.
            tokenMedia: [
                { token: 'LOGO_SRC', label: 'RailTime Firmenlogo', src: '/mail/logo.gif' },
                { token: 'ICON_PHONE_SRC', label: 'Telefon-Icon', src: '/mail/phone.gif' },
            ],
            assets: [],
            baseUrl: 'https://railtime.test/',
        },
    });

    const inspector = root.querySelector('.rt-lmz-image-properties');
    assert.equal(inspector.hidden, false);
    assert.equal(inspector.querySelector('.rt-lmz-image-properties__header strong').textContent, 'Bild');
    assert.equal(inspector.querySelector('[data-rt-lmz-image-kind]').textContent, 'Firmenlogo · GIF');
    assert.equal(inspector.querySelector('[data-rt-lmz-image-source-label]').textContent, 'Vorschauquelle');
    assert.equal(inspector.querySelector('[name="source"]').readOnly, true);
    assert.equal(inspector.querySelector('[name="source"]').getAttribute('aria-readonly'), 'true');
    assert.match(inspector.querySelector('[data-rt-lmz-image-source-hint]').textContent, /nicht als neue Dokumentquelle gespeichert/);
    assert.equal(inspector.querySelector('[data-rt-lmz-image-format]').textContent, 'GIF-Animation');
    assert.equal(inspector.querySelector('[data-rt-lmz-image-mime]').textContent, 'image/gif');
    assert.equal(inspector.querySelector('[data-rt-lmz-image-dimensions]').textContent, 'Nicht verfügbar');
    assert.equal(inspector.querySelector('[data-rt-lmz-image-ratio]').textContent, 'Nicht verfügbar');
    assert.equal(inspector.querySelector('[data-rt-lmz-image-bytes]').textContent, 'Nicht verfügbar');
    assert.equal(inspector.querySelector('[data-rt-lmz-image-fallback]').textContent, 'Nicht in den Medienmetadaten hinterlegt');
    assert.equal(inspector.querySelector('[name="preserveRatio"]').checked, true);
    assert.equal(inspector.querySelector('[name="preserveRatio"]').disabled, true);
    assert.equal(inspector.querySelector('[data-rt-lmz-image-ratio-control]').hidden, false);
    assert.equal(inspector.querySelector('[data-rt-lmz-image-gif]').hidden, false);
    assert.match(inspector.querySelector('[data-rt-lmz-image-gif] small').textContent, /Nur die Editorvorschau/);
    assert.deepEqual(
        [...inspector.querySelectorAll('.rt-lmz-image-properties__gif-actions button')].map((button) => button.textContent),
        ['Abspielen', 'Pausieren', 'Neu starten'],
    );
    assert.equal(logo.state['custom-name'], undefined);
    assert.equal(train.state['custom-name'], undefined);

    const logoLayer = document.createElement('div');
    logoLayer.className = 'lmzbjs-layer';
    logoLayer.innerHTML = '<div class="lmzbjs-layer-item"><span class="lmzbjs-layer-name">Logo</span></div>';
    editor.emit('layer:render', { component: logo, el: logoLayer });
    assert.equal(logoLayer.querySelector('.lmzbjs-layer-name').textContent, 'Bild');
    assert.equal(logoLayer.querySelector('.lmzbjs-layer-name').dataset.rtLmzImageDetail, 'Firmenlogo');

    const carrierLayer = document.createElement('div');
    carrierLayer.className = 'lmzbjs-layer';
    carrierLayer.innerHTML = '<div class="lmzbjs-layer-item">Technischer Zug-Carrier</div><div class="lmzbjs-layer-children"></div>';
    editor.emit('layer:render', { component: trainCarrier, el: carrierLayer });
    assert.equal(carrierLayer.classList.contains('rt-lmz-layer--internal-media-structure'), true);
    assert.equal(trainCarrier.state.open, undefined);

    const trainLayer = document.createElement('div');
    trainLayer.className = 'lmzbjs-layer';
    trainLayer.innerHTML = '<div class="lmzbjs-layer-item"><span class="lmzbjs-layer-name">Train</span></div>';
    editor.emit('layer:render', { component: train, el: trainLayer });
    assert.equal(trainLayer.classList.contains('rt-lmz-layer--internal-media-structure'), false);
    assert.equal(trainLayer.querySelector('.lmzbjs-layer-name').textContent, 'Bild');
    assert.equal(trainLayer.querySelector('.lmzbjs-layer-name').dataset.rtLmzImageDetail, 'Zuganimation');

    selected = protectedIcon;
    editor.emit('component:selected', protectedIcon);
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(inspector.hidden, false);
    assert.equal(root.querySelector('[data-lmz-panel-toggle="right:traits"]').hidden, false);
    assert.equal(inspector.querySelector('[name="source"]').readOnly, true);
    assert.equal(inspector.querySelector('.rt-lmz-image-properties__apply').disabled, true);
    assert.match(inspector.querySelector('[data-rt-lmz-image-message]').textContent, /System-Slot verwaltet/);

    chrome.destroy();
}));

test('pausing a normal GIF never replaces its persisted source when image properties are applied', () => coreWithDom(`
    <div id="root">
        <div class="lmz-builder__topbar"><button data-lmz-action="assets">Medien</button></div>
        <div class="lmz-builder__viewport">
            <section data-lmz-popover-panel="right:traits"><div><div data-lmz-mount="traits"></div></div></section>
            <div data-tools><div data-toolbar></div></div>
        </div>
        <img id="gif">
    </div>
`, async ({ document }) => {
    const source = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    const still = 'data:image/png;base64,c3RhdGlj';
    const root = document.querySelector('#root');
    const element = document.querySelector('#gif');
    element.setAttribute('src', source);
    const selected = coreFakeComponent(element, {
        type: 'image',
        attributes: { src: source, alt: 'Animation', 'data-mime-type': 'image/gif' },
    });
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail' });
    globalThis.__rtLmzCaptureAnimatedFrame = async () => still;
    const inspector = root.querySelector('.rt-lmz-image-properties');

    inspector.querySelector('[data-rt-lmz-image-gif-pause]').click();
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(element.getAttribute('src'), still);

    chrome.refresh();
    assert.equal(inspector.querySelector('[name="source"]').value, source);
    inspector.querySelector('[name="width"]').value = '480';
    inspector.querySelector('form').dispatchEvent(new document.defaultView.Event('submit', { bubbles: true, cancelable: true }));

    assert.equal(selected.state.src, source);
    assert.equal(selected.state.attributes.src, source);
    assert.equal(selected.state.attributes.width, '480');
    assert.equal(selected.state.style.height, 'auto');
    chrome.destroy();
}));

test('native GrapesJS asset entry points use only the scoped drawer and reject protected logo or QR targets', () => {
    const commandMap = new Map([['open-assets', { run: () => 'native-dialog' }]]);
    const openings = [];
    let assetTarget = null;
    const imageElement = { tagName: 'IMG' };
    const normalImage = coreFakeComponent(imageElement, { attributes: {}, type: 'image' });
    const logoImage = coreFakeComponent(imageElement, {
        attributes: { 'data-rt-brand-lockup': 'official' },
        type: 'image',
    });
    const qrImage = coreFakeComponent(imageElement, {
        attributes: { 'data-rt-qr-binding': 'cta_url' },
        type: 'image',
    });
    let selected = normalImage;
    const editor = {
        getSelected: () => selected,
        Commands: {
            add: (name, command) => commandMap.set(name, command),
            get: (name) => commandMap.get(name),
            remove: (name) => commandMap.delete(name),
        },
        AssetManager: {
            getTarget: () => assetTarget,
            setTarget: (target) => { assetTarget = target; },
            open: () => 'native-dialog',
        },
    };
    const originalAssetOpen = editor.AssetManager.open;
    const detach = installScopedAssetAccess({
        editor,
        mode: 'marketing',
        mediaDrawer: {
            open: (options) => openings.push(options),
            close() {},
        },
    });
    const command = commandMap.get('open-assets');

    assert.equal(command.run(editor, null, { target: logoImage }), false);
    assert.equal(command.run(editor, null, { target: qrImage }), false);
    assert.equal(openings.length, 0);
    assert.equal(editor.AssetManager.open({ target: logoImage }), false);
    assert.equal(openings.length, 0);

    assert.equal(command.run(editor, null, { target: normalImage }), true);
    assert.equal(openings.length, 1);
    assert.equal(openings[0].replaceTarget, normalImage);
    assert.equal(openings[0].initialTab, 'library');
    assert.notEqual(editor.AssetManager.open, originalAssetOpen);

    const styleSelections = [];
    assert.equal(editor.AssetManager.open({ select: (asset, complete) => styleSelections.push([asset.getSrc(), complete]) }), true);
    assert.equal(openings.length, 2);
    assert.equal(openings[1].replaceTarget, undefined);
    assert.equal(typeof openings[1].selectAsset, 'function');
    assert.equal(openings[1].initialTab, 'library');

    selected = logoImage;
    assert.equal(editor.AssetManager.open({ select: () => {} }), false);
    assert.equal(openings.length, 2);

    detach();
    assert.equal(editor.AssetManager.open, originalAssetOpen);
    assert.equal(commandMap.get('open-assets').run(), 'native-dialog');
});

test('background media callbacks receive only scoped FilePool assets and never free URLs', () => {
    const allowed = { src: '/administrator/marketing/dateien/42?v=abc', name: 'Jobmotiv.gif', mime_type: 'image/gif' };
    const calls = [];
    const session = createScopedAssetCallbackSelection({
        assets: [allowed],
        baseUrl: 'https://railtime.test/',
        select: (asset, complete) => calls.push([asset.getSrc(), asset.get('name'), complete]),
    });

    assert.equal(session.select(allowed, true), allowed.src);
    assert.deepEqual(calls, [[allowed.src, allowed.name, true]]);
    assert.throws(
        () => session.select({ src: 'https://evil.example/pixel.png' }, true),
        /freigegebenen Dateibibliothek/,
    );
});

test('both page-builder domains disable native external canvas drops', async () => {
    const [{ readFile }, mailModule] = await Promise.all([
        import('node:fs/promises'),
        import('../../resources/js/mail-builder.js'),
    ]);
    const marketingSource = await readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8');

    assert.equal(mailModule.MAIL_GJS_OPTIONS.canvas.allowExternalDrop, false);
    assert.match(marketingSource, /canvas:\s*\{\s*styles:\s*\[\],\s*scripts:\s*\[\],\s*allowExternalDrop:\s*false\s*}/);
});

test('rich-text paste keeps text while stripping pasted HTML and media', () => {
    let prevented = false;
    let inserted = null;
    const handled = handleScopedRtePaste({
        ev: {
            preventDefault: () => { prevented = true; },
            clipboardData: {
                getData: (type) => (type === 'text/plain' ? 'Neue <Stelle>\nhttps://rail-time.de' : '<img src="https://evil.example/pixel.png"><b>Neue Stelle</b>'),
            },
        },
        rte: { insertHTML: (value) => { inserted = value; } },
    });

    assert.equal(handled, true);
    assert.equal(prevented, true);
    assert.equal(inserted, 'Neue &lt;Stelle&gt;<br>https://rail-time.de');
    assert.doesNotMatch(inserted, /<img|evil\.example/i);
});

test('shared LMZ animation allowlists reject executable and unbounded values', () => {
    assert.deepEqual(sanitizeAnimationStyles({
        'animation-duration': '850ms',
        'animation-delay': 'javascript:alert(1)',
        'animation-name': 'evil',
        'animation-iteration-count': 'infinite',
    }), {
        'animation-duration': '850ms',
        'animation-iteration-count': 'infinite',
    });
    assert.deepEqual(sanitizeMotionSettings({
        motion: 'fade-up',
        duration: 0.8,
        delay: 99,
        scale: 0.92,
        ease: 'power3.out',
        once: true,
    }), {
        motion: 'fade-up',
        duration: 0.8,
        scale: 0.92,
        ease: 'power3.out',
        once: true,
    });
});

test('shared LMZ motion writes only the server-side allowlisted data contract', () => coreWithDom('<img id="target">', ({ document }) => {
    const component = coreFakeComponent(document.querySelector('#target'));
    applyMotionSettings(component, {
        motion: 'reveal',
        duration: 1.2,
        delay: 0.2,
        distance: 48,
        scale: 0.9,
        once: true,
    });
    assert.deepEqual(component.state.attributes, {
        'data-lmz-motion': 'reveal',
        'data-lmz-duration': '1.2',
        'data-lmz-delay': '0.2',
        'data-lmz-distance': '48',
        'data-lmz-scale': '0.9',
        'data-lmz-once': 'true',
    });
}));

test('mail logo preview restarts the regular GIF image without mutating persisted model data', () => coreWithDom(
    '<img id="logo" data-rt-mail-preview-token="LOGO_SRC" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==">',
    async ({ document }) => {
        const element = document.querySelector('#logo');
        const component = coreFakeComponent(element, {
            src: 'data:image/png;base64,neutral-model-pixel',
            attributes: { 'data-rt-mail-preview-token': 'LOGO_SRC' },
        });
        const before = structuredClone(component.state);
        const capturedSources = [];
        globalThis.__rtLmzCaptureAnimatedFrame = async ({ source }) => {
            capturedSources.push(source);
            return 'data:image/png;base64,c3RhdGlj';
        };

        assert.equal(animatedPreviewIsPlaying(component), true);
        assert.equal(setAnimatedPreviewPlayback(component, false), true);
        await new Promise((resolve) => setTimeout(resolve, 5));
        assert.equal(animatedPreviewIsPlaying(component), false);
        assert.match(element.getAttribute('src'), /^data:image\/png/);
        assert.deepEqual(component.state, before);

        hydrateMailCanvasAssets({ Canvas: { getDocument: () => document } }, 'dark', {
            dark: { logo: '/mail/dark-logo.gif' },
        });
        await new Promise((resolve) => setTimeout(resolve, 5));
        assert.equal(animatedPreviewIsPlaying(component), false);
        assert.match(element.getAttribute('src'), /^data:image\/png/);
        assert.equal(capturedSources.at(-1), '/mail/dark-logo.gif');

        assert.equal(setAnimatedPreviewPlayback(component, true), true);
        await new Promise((resolve) => setTimeout(resolve, 5));
        assert.equal(animatedPreviewIsPlaying(component), true);
        assert.match(element.getAttribute('src'), /dark-logo\.gif/);
        assert.deepEqual(component.state, before);

        assert.equal(restartAnimatedPreview(component, { nonce: 7 }), true);
        await new Promise((resolve) => setTimeout(resolve, 5));
        assert.deepEqual(component.state, before);
        assert.match(element.getAttribute('src'), /dark-logo\.gif/);
        delete globalThis.__rtLmzCaptureAnimatedFrame;
    },
));

test('mail inline animation segment exposes playback and restart without marketing motion fields', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-mail-gif">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const element = document.createElement('td');
    element.style.backgroundImage = 'url("data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==")';
    const selected = coreFakeComponent(element, {
        attributes: { 'data-rt-mail-preview-train': 'TRAIN_SRC' },
        style: { 'background-image': 'url("{{TRAIN_SRC}}")' },
    });
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail' });
    const before = structuredClone(selected.state);

    root.querySelector('.rt-lmz-inline-edit-trigger').click();
    const animationItem = root.querySelector('[data-rt-lmz-inline-action="animation"]');
    assert.ok(animationItem);
    animationItem.click();
    const drawer = root.querySelector('.rt-lmz-animation-drawer');
    assert.equal(drawer.hidden, false);
    assert.equal(drawer.querySelector('[data-rt-lmz-motion-fields]').hidden, true);
    assert.equal(drawer.querySelector('.rt-lmz-animation-drawer__apply').hidden, true);
    assert.equal(drawer.querySelector('[data-rt-lmz-gif-playback]').hidden, false);
    assert.equal(drawer.querySelector('[data-rt-lmz-gif-restart]').hidden, false);

    drawer.querySelector('[data-rt-lmz-gif-playback]').click();
    assert.equal(animatedPreviewIsPlaying(selected), false);
    assert.deepEqual(selected.state, before);
    chrome.destroy();
}));

test('inline edit menu attaches when GrapesJS creates its selection toolbar after editor boot', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-late-toolbar">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools></div></div></div></div></div>
`, async ({ document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('p'));
    const editor = coreFakeEditor(root, selected);
    editor.Canvas.getToolbarEl = () => root.querySelector('[data-toolbar]');
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail' });

    assert.equal(root.querySelector('.rt-lmz-inline-edit-trigger'), null);
    const toolbar = document.createElement('div');
    toolbar.dataset.toolbar = '';
    root.querySelector('[data-tools]').appendChild(toolbar);
    await new Promise((resolve) => setTimeout(resolve, 5));

    const trigger = root.querySelector('.rt-lmz-inline-edit-trigger');
    assert.ok(trigger);
    assert.equal(trigger.getAttribute('aria-label'), 'Bearbeiten');
    assert.equal(trigger.getAttribute('aria-haspopup'), 'menu');
    chrome.destroy();
}));

test('shared LMZ uses the visible dynamic toolbar for editing, menu anchoring and structure guards', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-dynamic-toolbar">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar class="lmzbjs-toolbar"></div></div></div></div></div></div>
`, async ({ document }) => {
    const root = document.querySelector('#root');
    root.getBoundingClientRect = () => ({ left: 20, top: 10, width: 500, height: 400, right: 520, bottom: 410 });
    const selected = coreFakeComponent(document.createElement('img'), {
        type: 'image',
        attributes: { 'data-rt-brand-lockup': 'official' },
    });
    const editor = coreFakeEditor(root, selected);
    const staleToolbar = root.querySelector('[data-toolbar]');
    staleToolbar.getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0, right: 0, bottom: 0 });
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'marketing' });
    const dynamicToolbar = document.createElement('div');
    try {
        const menu = root.querySelector('.rt-lmz-inline-menu');
        menu.getBoundingClientRect = () => ({ left: 0, top: 0, width: 260, height: 180, right: 260, bottom: 180 });
        dynamicToolbar.className = 'lmzbjs-toolbar';
        dynamicToolbar.getBoundingClientRect = () => ({ left: 480, top: 350, width: 32, height: 40, right: 512, bottom: 390 });
        dynamicToolbar.innerHTML = '<button data-command="tlb-move">Move</button><button data-command="tlb-delete">Delete</button>';
        root.querySelector('[data-tools]').appendChild(dynamicToolbar);
        await new Promise((resolve) => setTimeout(resolve, 5));

        const trigger = dynamicToolbar.querySelector('.rt-lmz-inline-edit-trigger');
        assert.ok(trigger);
        assert.equal(dynamicToolbar.querySelector('[data-command="tlb-move"]').hidden, true);
        assert.equal(dynamicToolbar.querySelector('[data-command="tlb-delete"]').hidden, true);
        assert.equal(dynamicToolbar.querySelector('[data-command="tlb-delete"]').getAttribute('aria-disabled'), 'true');
        let triggerFocusCount = 0;
        trigger.focus = () => { triggerFocusCount += 1; };
        Object.defineProperty(document, 'activeElement', { configurable: true, get: () => trigger });
        trigger.focus();
        trigger.click();
        assert.equal(menu.style.left, '232px');
        assert.equal(menu.style.top, '154px');

        let fullscreenEscapeCount = 0;
        document.body.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') fullscreenEscapeCount += 1;
        });
        const escape = new document.defaultView.Event('keydown', { bubbles: true, cancelable: true });
        Object.defineProperty(escape, 'key', { value: 'Escape' });
        menu.dispatchEvent(escape);
        assert.equal(escape.defaultPrevented, true);
        assert.equal(fullscreenEscapeCount, 0);
        assert.equal(menu.hidden, true);
        assert.equal(triggerFocusCount, 2);
    } finally {
        chrome.destroy();
    }
    assert.equal(dynamicToolbar.querySelector('.rt-lmz-inline-edit-trigger'), null);
}));

test('shared LMZ keeps fixed icon action labels visually hidden without hiding normal action labels', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-action-labels">
    <div id="root"><div class="lmz-builder__topbar">
        <button class="lmz-builder__action is-icon-only" data-lmz-action="undo"><span class="lmz-builder__action-icon"></span></button>
        <button class="lmz-builder__action" data-lmz-action="save"><span class="lmz-builder__action-label">Alt</span></button>
    </div><div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('p'));
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail' });

    const undoLabel = root.querySelector('[data-lmz-action="undo"] .rt-lmz-toolbar-action__label');
    const saveLabel = root.querySelector('[data-lmz-action="save"] .rt-lmz-toolbar-action__label');
    assert.equal(undoLabel.textContent, 'Rückgängig');
    assert.equal(undoLabel.classList.contains('sr-only'), true);
    assert.equal(saveLabel.textContent, 'Speichern');
    assert.equal(saveLabel.classList.contains('sr-only'), false);

    chrome.destroy();
}));

test('inline menu groups accessible icon actions and points Umpositionieren to the visible drag handle', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-inline-groups">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar class="lmzbjs-toolbar">
        <button data-command="tlb-move">Move</button><button data-command="tlb-clone">Clone</button><button data-command="tlb-delete">Delete</button>
    </div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const toolbar = root.querySelector('.lmzbjs-toolbar');
    toolbar.getBoundingClientRect = () => ({ left: 40, top: 40, width: 180, height: 40, right: 220, bottom: 80 });
    root.getBoundingClientRect = () => ({ left: 0, top: 0, width: 640, height: 480, right: 640, bottom: 480 });
    const selected = coreFakeComponent(document.createElement('img'), {
        type: 'image',
        src: '/files/train.png',
        attributes: { src: '/files/train.png' },
    });
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'marketing' });
    const trigger = toolbar.querySelector('.rt-lmz-inline-edit-trigger');

    trigger.focus();
    trigger.click();
    const groups = [...root.querySelectorAll('[data-rt-lmz-inline-group]')];
    assert.deepEqual(groups.map((group) => group.dataset.rtLmzInlineGroup), ['assistant', 'edit', 'structure']);
    assert.deepEqual(groups.map((group) => group.getAttribute('aria-label')), ['Assist', 'Bearbeiten', 'Struktur']);
    groups.forEach((group) => assert.ok(group.querySelector('.rt-lmz-inline-menu__group-header small').textContent));
    root.querySelectorAll('[data-rt-lmz-inline-action]').forEach((action) => {
        assert.ok(action.querySelector('svg[aria-hidden="true"]'));
        assert.equal(action.getAttribute('aria-label'), action.querySelector('.rt-lmz-inline-menu__action-label').textContent);
    });

    const moveHandle = toolbar.querySelector('[data-command="tlb-move"]');
    let moveFocused = false;
    moveHandle.focus = () => { moveFocused = true; };
    root.querySelector('[data-rt-lmz-inline-action="move"]').click();
    assert.equal(moveFocused, true);
    assert.equal(moveHandle.classList.contains('rt-lmz-move-ready'), true);
    assert.equal(moveHandle.dataset.rtLmzMoveReady, 'true');
    assert.equal(moveHandle.getAttribute('aria-label'), 'Element ziehen und umpositionieren');
    assert.match(moveHandle.getAttribute('title'), /Griff ziehen/);

    chrome.destroy();
}));

test('shared LMZ shell styles real layer rows, grouped inline actions and responsive editor controls', async () => {
    const { readFile } = await import('node:fs/promises');
    const css = await readFile(new URL('../../resources/css/lmz-editor-shell.css', import.meta.url), 'utf8');

    assert.match(css, /\.lmzbjs-layer\.lmzbjs-selected\s*>\s*\.lmzbjs-layer-item/);
    assert.match(css, /\.lmzbjs-layer\.rt-lmz-layer--internal-media-structure\s*>\s*:is\(\.lmzbjs-layer-item, \.lmzbjs-layer-title\)/);
    assert.match(css, /\.lmzbjs-layer\.rt-lmz-layer--internal-media-structure\s*>\s*\.lmzbjs-layer-children\s*\{[\s\S]*?display:\s*block\s*!important/);
    assert.match(css, /\.lmzbjs-layer-name\[data-rt-lmz-image-detail\]::after/);
    assert.match(css, /\.rt-lmz-image-properties__metadata\s*\{/);
    assert.match(css, /\.rt-lmz-image-properties__gif-actions\s*\{/);
    assert.match(css, /\.rt-lmz-inline-menu__group\s*\{/);
    assert.match(css, /\.rt-lmz-inline-menu__group-header\s*\{/);
    assert.match(css, /\.rt-lmz-inline-menu__icon\s*\{[\s\S]*?width:\s*1\.125rem;/);
    assert.match(css, /\.rt-lmz-inline-menu__action-label\s*\{/);
    assert.match(css, /\.lmzbjs-field:not\(\.lmzbjs-field-checkbox\):focus-within/);
    assert.match(css, /\.lmzbjs-field \.lmzbjs-sel-arrow\s*\{[\s\S]*?z-index:\s*2;/);
    assert.match(css, /@media \(max-width: 639\.98px\)[\s\S]*?\.lmzbjs-trt-trait textarea[\s\S]*?font-size:\s*1rem;/);
    assert.match(css, /data-page-builder-shell-toolbar[\s\S]*?margin-inline-end:\s*24\.5rem;/);
    assert.match(css, /data-rt-lmz-layout='elementor'[\s\S]*?data-rt-lmz-mode='mail'[\s\S]*?data-lmz-action='save'[\s\S]*?display:\s*none\s*!important/);
    assert.match(css, /grid-template-columns:\s*clamp\(16rem, 18vw, 19rem\)\s*minmax\(0, 1fr\)\s*clamp\(18rem, 22vw, 24rem\)/);
    assert.match(css, /\.rt-lmz-panel-tools\s*\{/);
    assert.match(css, /\.rt-lmz-panel-search__clear\s*\{[\s\S]*?width:\s*2\.75rem;[\s\S]*?height:\s*2\.75rem;/);
    assert.match(css, /\.rt-lmz-panel-scroll\s*\{[\s\S]*?overflow:\s*auto;/);
    assert.match(css, /data-rt-lmz-panel-kind='classes'[\s\S]*?\.lmzbjs-clm-tag-close\s*\{[\s\S]*?width:\s*2\.75rem;/);
    assert.match(css, /rt-lmz-control-dock--navigation[\s\S]*?grid-column:\s*1;[\s\S]*?rt-lmz-control-dock--inspector[\s\S]*?grid-column:\s*3;/);
    assert.match(css, /data-rt-lmz-has-context-actions='false'[\s\S]*?rt-lmz-control-dock--inspector\s*\{\s*display:\s*none;/);
});

test('shared LMZ closes vendor auto-styles after selection but preserves explicit style intent', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-a">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets"></button>
    <button id="styles" data-lmz-panel-group="right" data-lmz-panel-toggle="right:styles" aria-expanded="false">Styles</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, async ({ document }) => {
    const root = document.querySelector('#root');
    const styles = document.querySelector('#styles');
    styles.addEventListener('click', () => styles.setAttribute('aria-expanded', styles.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'));
    const selected = coreFakeComponent(document.createElement('div'));
    const editor = coreFakeEditor(root, selected, () => {
        if (styles.getAttribute('aria-expanded') !== 'true') styles.setAttribute('aria-expanded', 'true');
    });
    const chrome = createLmzEditorChrome({ instance: { editor }, root, media: { baseUrl: 'https://railtime.test/' } });
    assert.equal(chrome.mode, LMZ_EDITOR_MODES.website);
    assert.equal(root.dataset.rtLmzMode, 'website');

    editor.emit('component:selected', selected);
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(styles.getAttribute('aria-expanded'), 'false');

    styles.dispatchEvent(new document.defaultView.Event('pointerdown', { bubbles: true }));
    styles.click();
    assert.equal(styles.getAttribute('aria-expanded'), 'true');
    editor.emit('component:selected', selected);
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(styles.getAttribute('aria-expanded'), 'true');
    chrome.destroy();
}));

test('archived chrome is genuinely read-only and never exposes mutating inline actions', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-readonly">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Medien</button></div>
    <div class="lmz-builder__viewport"><div data-lmz-mount="layers"></div><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('div'));
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({
        instance: { editor },
        root,
        mode: 'marketing',
        capabilities: { writable: false, media: true, traits: true, styles: true, spacing: true },
    });

    assert.equal(root.dataset.rtLmzReadOnly, 'true');
    assert.equal(root.querySelector('[data-lmz-mount="layers"]').inert, true);
    assert.equal(selected.state.layerable, false);
    assert.equal(selected.state.stylable, false);
    root.querySelector('.rt-lmz-inline-edit-trigger').click();
    const actions = [...root.querySelectorAll('[data-rt-lmz-inline-action]')]
        .map((item) => item.dataset.rtLmzInlineAction);
    assert.deepEqual(actions, ['assistant']);

    chrome.destroy();
    assert.equal(root.dataset.rtLmzReadOnly, undefined);
    assert.equal(root.querySelector('[data-lmz-mount="layers"]').inert, false);
}));

test('official lockups and QR structures expose only read-only Assist help and no structure mutation', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-protected">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Medien</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar><button data-command="tlb-move">Move</button><button data-command="tlb-clone">Clone</button><button data-command="tlb-delete">Delete</button></div></div></div></div></div></div>
`, async ({ window, document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('img'), {
        type: 'image',
        attributes: { 'data-rt-brand-lockup': 'official', src: '/rt-brand/img/logo-horizontal.png' },
    });
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'marketing' });
    const adapter = createLmzAssistantAdapter({
        root,
        instance: { editor, hasUnsavedChanges: () => false },
        chrome,
        mode: 'marketing',
        fingerprint: async () => 'a'.repeat(64),
    });
    const before = structuredClone(selected.state);
    const eventOrder = [];
    let assistantDetail = null;
    window.addEventListener('railtime-pagebuilder-context-changed', () => eventOrder.push('context'));
    window.addEventListener('railtime-assistant-open', (event) => {
        eventOrder.push('assistant');
        assistantDetail = event.detail;
    });

    editor.emit('component:selected', selected);
    assert.equal(enforceProtectedComponentModels(editor), 1);
    assert.equal(selected.state.layerable, false);
    assert.equal(selected.state.stylable, false);
    assert.equal(selected.state.resizable, false);
    assert.equal(root.dataset.rtLmzProtectedSelection, 'true');
    assert.equal(root.querySelector('[data-command="tlb-delete"]').hidden, true);
    root.querySelector('.rt-lmz-inline-edit-trigger').click();
    const actions = [...root.querySelectorAll('[data-rt-lmz-inline-action]')]
        .map((item) => item.dataset.rtLmzInlineAction);
    assert.equal(actions.includes('delete'), false);
    assert.equal(actions.includes('duplicate'), false);
    assert.equal(actions.includes('move'), false);
    assert.equal(actions.includes('styles'), false);
    assert.equal(actions.includes('spacing'), false);
    assert.equal(actions.includes('assistant'), true);
    assert.equal((await adapter.getContext()).selection.protected, true);
    assert.equal(adapter.editText('Darf nicht verändert werden'), false);
    eventOrder.length = 0;
    root.querySelector('[data-rt-lmz-inline-action="assistant"]').click();
    assert.deepEqual(eventOrder, ['context', 'assistant']);
    assert.deepEqual(assistantDetail, { source: 'page-builder', mode: 'marketing', read_only: true });
    assert.deepEqual(selected.state, before);

    adapter.destroy();
    chrome.destroy();
}));

test('protected mail carriers remain visible as locked layers without hiding editable content children', () => {
    const carrier = coreFakeComponent({ tagName: 'TD' }, {
        tagName: 'td',
        attributes: { 'data-rt-mail-preview-train': 'TRAIN_SRC' },
    });
    const content = coreFakeComponent({ tagName: 'P' }, {
        tagName: 'p',
        parent: carrier,
    });
    carrier.components([content]);
    const editor = {
        getWrapper: () => carrier,
        getSelected: () => carrier,
    };

    assert.equal(enforceProtectedComponentModels(editor), 1);
    assert.equal(carrier.state.layerable, true);
    assert.equal(carrier.state.draggable, false);
    assert.equal(carrier.state.removable, false);
    assert.equal(carrier.state.copyable, false);
    assert.deepEqual(carrier.state.stylable, ['background-position']);
    assert.equal(content.state.layerable, undefined);
    assert.equal(content.state.stylable, undefined);
    assert.equal(isProtectedEditorStructure(carrier), true);
    assert.equal(isProtectedEditorStructure(content), false);
});

test('structure actions protect neutral parents around lockup, QR and train descendants without locking parent styles', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-subtree">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar class="lmzbjs-toolbar">
        <button data-command="tlb-move">Move</button><button data-command="tlb-clone">Clone</button><button data-command="tlb-delete">Delete</button>
    </div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    let selected = null;
    const editor = coreFakeEditor(root, null);
    editor.getSelected = () => selected;
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'marketing' });
    const markers = [
        { 'data-rt-brand-lockup': 'official' },
        { 'data-rt-qr-binding': 'career' },
        { 'data-rt-mail-preview-train': 'true' },
    ];

    markers.forEach((attributes) => {
        const parent = coreFakeComponent(document.createElement('section'));
        const child = coreFakeComponent(document.createElement('div'), { attributes, parent });
        parent.state.children = [child];
        selected = parent;
        editor.emit('component:selected', parent);

        assert.equal(isProtectedEditorStructure(parent), false);
        assert.equal(isProtectedEditorStructureTree(parent), true);
        assert.equal(root.dataset.rtLmzProtectedSelection, 'true');
        assert.equal(root.querySelector('[data-command="tlb-move"]').hidden, true);
        root.querySelector('.rt-lmz-inline-edit-trigger').click();
        const actions = [...root.querySelectorAll('[data-rt-lmz-inline-action]')]
            .map((action) => action.dataset.rtLmzInlineAction);
        assert.equal(actions.includes('move'), false);
        assert.equal(actions.includes('duplicate'), false);
        assert.equal(actions.includes('delete'), false);
        assert.equal(actions.includes('traits'), true);
        assert.equal(actions.includes('styles'), true);
    });

    const move = root.querySelector('[data-command="tlb-move"]');
    const moveEvent = new document.defaultView.Event('click', { bubbles: true, cancelable: true });
    move.dispatchEvent(moveEvent);
    assert.equal(moveEvent.defaultPrevented, true);

    chrome.destroy();
}));

test('shared LMZ media drawer blocks external previews and wires animation apply and teardown', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-b">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const imageElement = document.createElement('img');
    const selected = coreFakeComponent(imageElement, {
        src: '/files/train.gif',
        attributes: { src: '/files/train.gif' },
    });
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({
        instance: { editor },
        root,
        mode: 'marketing',
        media: {
            assets: [{ src: '/files/train.gif', name: 'Train.gif', type: 'image' }],
            baseUrl: 'https://railtime.test/',
        },
    });
    let fullscreenEscapeCount = 0;
    document.body.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') fullscreenEscapeCount += 1;
    });
    const escape = (target) => {
        const event = new document.defaultView.Event('keydown', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'key', { value: 'Escape' });
        target.dispatchEvent(event);
        assert.equal(event.defaultPrevented, true);
        assert.equal(fullscreenEscapeCount, 0);
    };

    chrome.openMedia({ initialTab: 'used' });
    assert.equal([...root.querySelectorAll('.rt-lmz-media-item img')].some((img) => img.src.includes('evil.example')), false);
    assert.match(root.querySelector('.rt-lmz-media-drawer__warning').textContent, /evil\.example/);
    const mediaDrawer = root.querySelector('.rt-lmz-media-drawer');
    escape(mediaDrawer);
    assert.equal(mediaDrawer.hidden, true);

    const trigger = root.querySelector('.rt-lmz-inline-edit-trigger');
    let triggerFocusCount = 0;
    trigger.focus = () => { triggerFocusCount += 1; };
    Object.defineProperty(document, 'activeElement', { configurable: true, get: () => trigger });
    trigger.focus();
    trigger.click();
    const inlineMenu = root.querySelector('.rt-lmz-inline-menu');
    escape(inlineMenu);
    assert.equal(inlineMenu.hidden, true);
    assert.equal(triggerFocusCount, 2);
    trigger.click();
    root.querySelector('[data-rt-lmz-inline-action="animation"]').click();
    const drawer = root.querySelector('.rt-lmz-animation-drawer');
    assert.equal(drawer.hidden, false);
    const form = drawer.querySelector('form');
    [...form.querySelectorAll('[name="motion"] option')].forEach((option) => {
        option.selected = option.value === 'fade-up';
    });
    form.querySelector('[name="duration"]').value = '0.8';
    form.dispatchEvent(new document.defaultView.Event('submit', { bubbles: true, cancelable: true }));
    assert.equal(selected.state.attributes['data-lmz-duration'], '0.8');

    escape(drawer);
    assert.equal(drawer.hidden, true);
    chrome.openAnimation(selected);
    root.dispatchEvent(new document.defaultView.Event('pointerdown', { bubbles: true }));
    assert.equal(drawer.hidden, true);

    chrome.destroy();
    assert.equal(root.querySelector('.rt-lmz-animation-drawer'), null);
    assert.equal(root.querySelector('.rt-lmz-media-drawer'), null);
}));

test('vendor popover Escape closes only its group, restores trigger focus and never reaches fullscreen', () => coreWithDom(`
    <section data-rt-fullscreen-modal>
    <div id="root" class="lmz-builder">
        <button id="styles-toggle" data-lmz-panel-group="right" data-lmz-panel-toggle="right:styles" aria-expanded="true">Styles</button>
        <aside data-lmz-popover="right" class="is-open">
            <section data-lmz-popover-panel="right:styles" class="is-active">
                <button id="styles-close" data-lmz-panel-close="right">Schliessen</button>
            </section>
        </aside>
        <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div>
    </div>
    </section>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const popover = root.querySelector('[data-lmz-popover]');
    const panel = root.querySelector('[data-lmz-popover-panel]');
    const close = document.querySelector('#styles-close');
    const toggle = document.querySelector('#styles-toggle');
    let fullscreenEscapeRuns = 0;
    let closeClicks = 0;
    let toggleFocus = 0;
    document.body.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') fullscreenEscapeRuns += 1;
    });
    close.addEventListener('click', () => {
        closeClicks += 1;
        popover.classList.remove('is-open');
        popover.hidden = true;
        panel.classList.remove('is-active');
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    });
    toggle.focus = () => { toggleFocus += 1; };
    const editor = coreFakeEditor(root, coreFakeComponent(document.createElement('p')));
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail' });
    const event = new document.defaultView.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'key', { value: 'Escape' });

    close.dispatchEvent(event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(fullscreenEscapeRuns, 0);
    assert.equal(closeClicks, 1);
    assert.equal(popover.hidden, true);
    assert.equal(toggleFocus, 1);
    chrome.destroy();
}));

test('iframe selection changes cancel every target-bound inline surface before stale actions can run', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-targets">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Media</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const first = coreFakeComponent(document.createElement('img'), {
        type: 'image', src: '/files/first.png', attributes: { src: '/files/first.png' },
    });
    const second = coreFakeComponent(document.createElement('div'));
    let selected = first;
    let assetTarget = null;
    const editor = coreFakeEditor(root, first);
    editor.getSelected = () => selected;
    editor.AssetManager.setTarget = (target) => { assetTarget = target; };
    const chrome = createLmzEditorChrome({
        instance: { editor },
        root,
        mode: 'marketing',
        media: {
            assets: [{ src: '/files/second.png', name: 'Second.png', type: 'image' }],
            baseUrl: 'https://railtime.test/',
        },
    });
    const menu = root.querySelector('.rt-lmz-inline-menu');
    const mediaDrawer = root.querySelector('.rt-lmz-media-drawer');
    const animationDrawer = root.querySelector('.rt-lmz-animation-drawer');

    root.querySelector('.rt-lmz-inline-edit-trigger').click();
    editor.emit('component:selected', first);
    assert.equal(menu.hidden, false);
    selected = second;
    editor.emit('component:selected', second);
    assert.equal(menu.hidden, true);

    selected = first;
    chrome.openMedia({ replaceTarget: first, initialTab: 'library' });
    assert.equal(mediaDrawer.hidden, false);
    assert.equal(assetTarget, first);
    editor.emit('component:deselected', first);
    assert.equal(mediaDrawer.hidden, true);
    assert.equal(assetTarget, null);

    chrome.openAnimation(first);
    assert.equal(animationDrawer.hidden, false);
    selected = second;
    editor.emit('component:selected', second);
    assert.equal(animationDrawer.hidden, true);
    const before = structuredClone(first.state.style);
    animationDrawer.querySelector('form').dispatchEvent(new document.defaultView.Event('submit', { bubbles: true, cancelable: true }));
    assert.deepEqual(first.state.style, before);

    chrome.destroy();
}));

test('same-origin canvas Tab boundaries return to outer composite controls and detach on destroy', () => coreWithDom(`
    <section data-rt-fullscreen-modal>
        <button data-page-builder-assist>Assist</button>
        <div id="root"><div class="lmz-builder__viewport">
            <button id="before-frame">Vor Canvas</button><iframe id="canvas-frame"></iframe><button id="after-frame">Nach Canvas</button>
            <div data-tools><div data-toolbar></div></div>
        </div></div>
    </section>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const frame = document.querySelector('#canvas-frame');
    const frameDom = parseHTML('<body><button id="first">Erstes</button><a id="last" href="#">Letztes</a></body>');
    const editor = coreFakeEditor(root, coreFakeComponent(document.createElement('p')));
    editor.Canvas.getDocument = () => frameDom.document;
    editor.Canvas.getFrameEl = () => frame;
    let beforeFocus = 0;
    let afterFocus = 0;
    document.querySelector('#before-frame').focus = () => { beforeFocus += 1; };
    document.querySelector('#after-frame').focus = () => { afterFocus += 1; };
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail' });
    const tab = (target, shiftKey = false) => {
        const event = new frameDom.window.Event('keydown', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'key', { value: 'Tab' });
        Object.defineProperty(event, 'shiftKey', { value: shiftKey });
        target.dispatchEvent(event);
        return event;
    };

    assert.equal(tab(frameDom.document.querySelector('#first'), true).defaultPrevented, true);
    assert.equal(beforeFocus, 1);
    assert.equal(tab(frameDom.document.querySelector('#last')).defaultPrevented, true);
    assert.equal(afterFocus, 1);

    chrome.destroy();
    assert.equal(tab(frameDom.document.querySelector('#last')).defaultPrevented, false);
    assert.equal(afterFocus, 1);
}));

test('spacing overlay keyboard controls expose values, commit with Enter and cancel with Escape', () => coreWithDom(`
    <div id="root"><div data-tools></div></div>
`, async ({ window, document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('div'));
    const editor = coreFakeEditor(root, selected);
    const changes = [];
    selected.addStyle = (style, options) => { changes.push({ style, options }); };
    const controller = createSpacingOverlayController({
        editor,
        root,
        environment: {
            document,
            window,
            requestAnimationFrame: (callback) => { callback(); return 1; },
            cancelAnimationFrame() {},
        },
    });
    const handle = root.querySelector('[data-type="padding"][data-side="top"]');
    const key = (value, shiftKey = false) => {
        const event = new window.Event('keydown', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'key', { value });
        Object.defineProperty(event, 'shiftKey', { value: shiftKey });
        handle.dispatchEvent(event);
        return event;
    };

    assert.equal(handle.getAttribute('role'), 'spinbutton');
    assert.equal(handle.getAttribute('aria-valuemin'), '0');
    assert.equal(handle.getAttribute('aria-valuenow'), '16');
    assert.equal(key('ArrowUp').defaultPrevented, true);
    key('ArrowRight', true);
    assert.equal(handle.getAttribute('aria-valuenow'), '27');
    assert.deepEqual(changes.at(-1).options, { partial: true });
    key('Enter');
    assert.equal(changes.at(-1).style['padding-top'], '27px');
    assert.equal(changes.at(-1).options, undefined);

    key('ArrowDown');
    assert.equal(handle.getAttribute('aria-valuenow'), '15');
    key('Escape');
    assert.equal(changes.at(-1).style['padding-top'], '16px');
    assert.equal(handle.getAttribute('aria-valuenow'), '16');
    controller.destroy();
}));

test('shared LMZ dirty close saves once before approving fullscreen close', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-c"><div id="root"></div></div></div>
`, async ({ window, document }) => {
    let saves = 0;
    let approvals = 0;
    window.addEventListener('page-builder-shell:close-approved', () => { approvals += 1; });
    const controller = createPageBuilderLifecycleController({
        root: document.querySelector('#root'),
        getBuilder: () => ({
            hasUnsavedChanges: () => true,
            save: async () => { saves += 1; return true; },
        }),
        environment: { window },
    });
    const event = new window.CustomEvent('page-builder-shell:before-close', {
        cancelable: true,
        detail: { id: 'shell-c' },
    });

    assert.equal(window.dispatchEvent(event), false);
    assert.equal(event.defaultPrevented, true);
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(saves, 1);
    assert.equal(approvals, 1);
    controller.destroy();
}));

test('assistant opens the matching preview-first shell after Alpine teleports the editor away from it', () => coreWithDom(`
    <div id="shell" data-page-builder-shell>
        <div data-page-builder-closed-preview><button type="button" data-page-builder-open>Vollbildeditor öffnen</button></div>
        <template id="teleport-origin"></template>
    </div>
    <section id="teleported-modal" data-rt-fullscreen-modal>
        <div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-teleport">
            <div data-page-builder-workspace data-page-builder-editor-active="false">
                <div id="root"><div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div>
            </div>
        </div>
    </section>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const teleported = document.querySelector('#teleported-modal');
    document.querySelector('#teleport-origin')._x_teleport = teleported;
    assert.equal(root.closest('[data-page-builder-shell]'), null);
    const editor = coreFakeEditor(root, coreFakeComponent(document.createElement('p')));
    const trigger = document.querySelector('[data-page-builder-open]');
    let clicks = 0;
    trigger.addEventListener('click', () => { clicks += 1; });
    const adapter = createLmzAssistantAdapter({
        root,
        instance: { editor, hasUnsavedChanges: () => false },
        chrome: { mediaState: () => ({ warnings: [] }) },
        mode: 'mail',
    });

    assert.equal(adapter.openFullscreen(), true);
    assert.equal(clicks, 1);
    adapter.destroy();
}));

test('shared LMZ navigation participant flushes a dirty draft and fails closed on storage errors', async () => {
    let saves = 0;
    let failures = 0;
    const successful = createPageBuilderNavigationController({
        getBuilder: () => ({
            hasUnsavedChanges: () => true,
            save: async (reason) => { saves += 1; assert.equal(reason, 'manual'); return true; },
        }),
    });
    assert.equal(successful.hasPendingWork(), true);
    assert.equal(await successful.flush(), true);
    assert.equal(saves, 1);

    const failing = createPageBuilderNavigationController({
        getBuilder: () => ({ hasUnsavedChanges: () => true, save: async () => false }),
        onFlushError: () => { failures += 1; },
    });
    await assert.rejects(() => failing.flush(), /vor dem Seitenwechsel/);
    failing.onFlushError(new Error('storage'));
    assert.equal(failures, 1);
});

test('shared LMZ assistant keeps persisted version separate from local revision', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-d">
    <div data-page-builder-workspace data-page-builder-editor-active="true"><div id="root"><div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div>
    </div></div>
`, async ({ window, document }) => {
    const root = document.querySelector('#root');
    const image = document.createElement('img');
    const selected = coreFakeComponent(image, {
        src: '/administrator/marketing/dateien/42?v=abc',
        attributes: {
            src: '/administrator/marketing/dateien/42?v=abc',
            'data-rt-block': 'hero',
        },
    });
    const editor = coreFakeEditor(root, selected);
    const instance = { editor, hasUnsavedChanges: () => true, save: async () => true };
    const redesignPresets = [];
    let registered = null;
    let unregistered = null;
    window.addEventListener('railtime-pagebuilder-adapter-register', (event) => { registered = event.detail.adapter; });
    window.addEventListener('railtime-pagebuilder-adapter-unregister', (event) => { unregistered = event.detail.adapter; });
    const adapter = createLmzAssistantAdapter({
        root,
        instance,
        chrome: {
            mediaState: () => ({ warnings: [] }),
            openPanel: () => true,
            restartGif: () => true,
        },
        routeName: 'admin.marketing.creatives.editor',
        mode: 'marketing',
        resourceId: '11111111-1111-4111-8111-111111111111',
        formatOrKind: () => 'story',
        persistedHash: () => 'a'.repeat(64),
        persistedVersion: () => 7,
        assets: [{ src: '/administrator/marketing/dateien/42?v=abc', type: 'image' }],
        availableBlockIds: ['rt-marketing-hero'],
        redesignDocument: async (preset) => {
            redesignPresets.push(preset);
            return { status: 'applied' };
        },
    });

    assert.equal(registered, adapter);
    const before = await adapter.getContext();
    assert.equal(before.persisted_version, 7);
    assert.equal(before.client_revision, 0);
    assert.equal(before.selection.image_file_id, 42);
    assert.equal(before.unsaved, true);
    assert.equal(before.capabilities.includes('redesign_document'), true);
    assert.deepEqual(await adapter.redesignDocument('railtime_modern'), { status: 'applied' });
    assert.equal(await adapter.redesignDocument('untrusted'), false);
    assert.deepEqual(redesignPresets, ['railtime_modern']);

    editor.emit('update');
    const after = await adapter.getContext();
    assert.equal(after.persisted_version, 7);
    assert.equal(after.client_revision, 1);
    assert.equal(adapter.setAnimation('duration', 0.8), true);
    assert.equal(selected.state.attributes['data-lmz-duration'], '0.8');
    adapter.destroy({ keepRegistered: true });
    assert.equal(unregistered, null);
    adapter.destroy();
    assert.equal(unregistered, adapter);
}));

test('assistant selection fingerprint cannot drift to a component selected while WebCrypto is pending', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-race">
    <div data-page-builder-workspace data-page-builder-editor-active="true"><div id="root"><div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div>
    </div></div>
`, async ({ document }) => {
    const root = document.querySelector('#root');
    const first = coreFakeComponent(document.createElement('p'), { attributes: { 'data-rt-block': 'first' } });
    const second = coreFakeComponent(document.createElement('p'), { attributes: { 'data-rt-block': 'second' } });
    let selected = first;
    const editor = coreFakeEditor(root, first);
    editor.getSelected = () => selected;
    let resolveFirst;
    let fingerprintCalls = 0;
    const fingerprint = async () => {
        fingerprintCalls += 1;
        if (fingerprintCalls === 1) await new Promise((resolve) => { resolveFirst = resolve; });
        return (fingerprintCalls === 1 ? 'a' : 'b').repeat(64);
    };
    const adapter = createLmzAssistantAdapter({
        root,
        instance: { editor, hasUnsavedChanges: () => false },
        chrome: { mediaState: () => ({ warnings: [] }) },
        routeName: 'admin.marketing.creatives.editor',
        mode: 'marketing',
        resourceId: '11111111-1111-4111-8111-111111111111',
        formatOrKind: () => 'story',
        persistedHash: () => 'c'.repeat(64),
        persistedVersion: () => 2,
        fingerprint,
    });

    const pendingContext = adapter.getContext();
    selected = second;
    editor.emit('component:selected', second);
    resolveFirst();
    const context = await pendingContext;

    assert.equal(context.selection.block_id, 'second');
    assert.equal(context.selection.fingerprint, 'b'.repeat(64));
    assert.equal(adapter.editText('Nur das verifizierte Segment'), true);
    assert.equal(second.state.children[0].content, 'Nur das verifizierte Segment');
    assert.equal(first.state.children, undefined);

    selected = first;
    editor.emit('component:selected', first);
    assert.equal(adapter.editText('Darf nicht auf A landen'), false);
    assert.equal(first.state.children, undefined);
    adapter.destroy();
}));
