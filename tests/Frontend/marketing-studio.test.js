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
    createImageAssetSelection,
    createScopedAssetCallbackSelection,
    installScopedAssetAccess,
    createLmzAssistantAdapter,
    createLmzEditorChrome,
    createPageBuilderLifecycleController,
    createPageBuilderNavigationController,
    normalizeLmzCapabilities,
    restartAnimatedPreview,
    sanitizeAnimationStyles,
    sanitizeMotionSettings,
    setAnimatedPreviewPlayback,
    spacingCssSnapshot,
} from '../../resources/js/lmz-editor-core.js';

import {
    applySavedVariant,
    calculateArtboardGeometry,
    closeInitialMobilePopovers,
    completedRenderDownloadUrl,
    createArtboardViewportController,
    createFixedArtboardPanController,
    createStudioBootGuard,
    MARKETING_ARTBOARDS,
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
        story: { builderData: { pages: [] }, css: '', contentHash: 'story-hash', version: 2 },
        post: { builderData: { pages: [1] }, css: '', contentHash: 'post-hash', version: 3 },
        web: { builderData: { pages: [2] }, css: '', contentHash: 'web-hash', version: 4 },
    });
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
    assert.equal(reloaded.styles[0].style.color, '#c90025');

    applySavedVariant(variant, {}, {
        project: reloaded,
        html: variant.html,
        css: variant.css,
    });
    assert.equal(variant.css, '.headline{color:#c90025}');
    assert.equal(variant.html, '<p>Serverbereinigt</p>');
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
    assert.match(coreSource, /import '\.\/lmz-editor-assistant\.js';/);
    assert.match(source, /media:\s*\{\s*assets:\s*config\.assets \|\| \[\]/);
    assert.match(source, /request\.expected_hashes = Object\.fromEntries/);
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
    assert.match(appSource, /import '\.\/marketing-studio';/);
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
    const mail = normalizeLmzCapabilities('mail', { imageReplace: 'tokens-only', animation: true });
    const marketing = normalizeLmzCapabilities('marketing');

    assert.equal(mail.animation, false);
    assert.equal(mail.imageReplace, 'tokens-only');
    assert.equal(mail.gifControls, true);
    assert.equal(mail.mediaInsert, false);
    assert.equal(marketing.animation, true);
    assert.equal(marketing.imageReplace, true);
});

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
    assert.equal(spacingCssSnapshot({ marginTop: 10, paddingLeft: 4 }, 0.5).padding.left, 8);
    assert.equal(calculateSpacingDragValue({ startValue: 12, deltaX: 10, zoom: 0.5, side: 'right', type: 'margin' }), 32);
    assert.equal(calculateSpacingDragValue({ startValue: 4, deltaY: -10, zoom: 0.5, side: 'bottom', type: 'padding' }), 0);
});

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

test('mail TRAIN_SRC preview restarts a background GIF without mutating persisted model data', () => coreWithDom(
    '<table><tr><td id="train"></td></tr></table>',
    async ({ document }) => {
        const element = document.querySelector('#train');
        const data = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
        const layeredBackground = `linear-gradient(rgba(247,246,243,.15),rgba(247,246,243,.15)),url("${data}")`;
        element.style.setProperty('background-image', layeredBackground, 'important');
        element.style.backgroundPosition = 'center center, left bottom';
        element.style.backgroundSize = '100% 100%, 86% auto';
        const component = coreFakeComponent(element, {
            attributes: { 'data-rt-mail-preview-train': 'TRAIN_SRC' },
            style: { 'background-image': 'url("{{TRAIN_SRC}}")' },
        });
        const before = structuredClone(component.state);

        assert.equal(animatedPreviewIsPlaying(component), true);
        assert.equal(setAnimatedPreviewPlayback(component, false), true);
        assert.equal(animatedPreviewIsPlaying(component), false);
        assert.match(element.style.backgroundImage, /^linear-gradient\(.+\),\s*none$/);
        assert.equal(element.style.backgroundPosition, 'center center, left bottom');
        assert.equal(element.style.backgroundSize, '100% 100%, 86% auto');
        assert.deepEqual(component.state, before);
        assert.equal(setAnimatedPreviewPlayback(component, true), true);
        await new Promise((resolve) => setTimeout(resolve, 5));
        assert.equal(animatedPreviewIsPlaying(component), true);
        assert.match(element.style.backgroundImage, /^linear-gradient\(.+\),\s*url\(/);
        assert.match(element.style.backgroundImage, /data:image\/gif/);
        assert.equal(element.style.backgroundPosition, 'center center, left bottom');
        assert.equal(element.style.backgroundSize, '100% 100%, 86% auto');
        assert.deepEqual(component.state, before);

        assert.equal(restartAnimatedPreview(component, { nonce: 7 }), true);
        await new Promise((resolve) => setTimeout(resolve, 5));
        assert.deepEqual(component.state, before);
        assert.match(element.style.backgroundImage, /^linear-gradient\(.+\),\s*url\(/);
        assert.match(element.style.backgroundImage, /data:image\/gif/);
        assert.equal(element.style.backgroundPosition, 'center center, left bottom');
        assert.equal(element.style.backgroundSize, '100% 100%, 86% auto');
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
    const before = structuredClone(selected.state);
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'mail' });

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
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar></div></div></div></div></div></div>
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
    root.querySelector('.rt-lmz-inline-edit-trigger').click();
    const actions = [...root.querySelectorAll('[data-rt-lmz-inline-action]')]
        .map((item) => item.dataset.rtLmzInlineAction);
    assert.deepEqual(actions, []);

    chrome.destroy();
    assert.equal(root.dataset.rtLmzReadOnly, undefined);
}));

test('official lockups and QR structures expose no native or RailTime structure mutation', () => coreWithDom(`
    <div data-page-builder-shell><div data-page-builder-fullscreen-root data-page-builder-shell-id="shell-protected">
    <div id="root"><div class="lmz-builder__topbar"><button data-lmz-action="assets">Medien</button></div>
    <div class="lmz-builder__viewport"><div data-tools><div data-toolbar><button data-command="tlb-move">Move</button><button data-command="tlb-clone">Clone</button><button data-command="tlb-delete">Delete</button></div></div></div></div></div></div>
`, ({ document }) => {
    const root = document.querySelector('#root');
    const selected = coreFakeComponent(document.createElement('img'), {
        type: 'image',
        attributes: { 'data-rt-brand-lockup': 'official', src: '/rt-brand/img/logo-horizontal.png' },
    });
    const editor = coreFakeEditor(root, selected);
    const chrome = createLmzEditorChrome({ instance: { editor }, root, mode: 'marketing' });

    editor.emit('component:selected', selected);
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

    chrome.openMedia({ initialTab: 'used' });
    assert.equal([...root.querySelectorAll('.rt-lmz-media-item img')].some((img) => img.src.includes('evil.example')), false);
    assert.match(root.querySelector('.rt-lmz-media-drawer__warning').textContent, /evil\.example/);

    root.querySelector('.rt-lmz-inline-edit-trigger').click();
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

    const escapeEvent = new document.defaultView.Event('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(escapeEvent, 'key', { value: 'Escape' });
    drawer.dispatchEvent(escapeEvent);
    assert.equal(drawer.hidden, true);
    chrome.openAnimation(selected);
    root.dispatchEvent(new document.defaultView.Event('pointerdown', { bubbles: true }));
    assert.equal(drawer.hidden, true);

    chrome.destroy();
    assert.equal(root.querySelector('.rt-lmz-animation-drawer'), null);
    assert.equal(root.querySelector('.rt-lmz-media-drawer'), null);
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
    });

    assert.equal(registered, adapter);
    const before = await adapter.getContext();
    assert.equal(before.persisted_version, 7);
    assert.equal(before.client_revision, 0);
    assert.equal(before.selection.image_file_id, 42);
    assert.equal(before.unsaved, true);

    editor.emit('update');
    const after = await adapter.getContext();
    assert.equal(after.persisted_version, 7);
    assert.equal(after.client_revision, 1);
    assert.equal(adapter.setAnimation('duration', 0.8), true);
    assert.equal(selected.state.attributes['data-lmz-duration'], '0.8');
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
