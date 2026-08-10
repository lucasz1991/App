import test from 'node:test';
import assert from 'node:assert/strict';

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

    assert.match(source, /useStudioWebDefaults:\s*false/);
    assert.match(source, /allowFallbackProject:\s*false/);
    assert.match(source, /document\.addEventListener\('livewire:navigating', destroyMarketingStudio\)/);
    assert.match(source, /instance\?\.destroy\?\.\(\)/);
    assert.match(source, /frame\.dataset\.readOnly = readOnly \? 'true' : 'false'/);
    assert.match(source, /createLmzEditorChrome\(\{/);
    assert.match(source, /media:\s*\{\s*assets:\s*config\.assets \|\| \[\]/);
    assert.match(source, /request\.expected_hashes = Object\.fromEntries/);
    assert.match(source, /config\.logoLightUrl,\s*config\.logoDarkUrl,/);
    assert.doesNotMatch(source, /config\.logoUrl\b/);
    assert.match(source, /\[data-marketing-artboard-label\]/);
    assert.match(source, /\[data-marketing-scale-label\]/);
    assert.match(source, /marketing-editor:viewport-change/);
    assert.match(source, /assets:\s*{\s*onLoad:\s*async \(\) => config\.assets \|\| \[\]/);
    assert.match(source, /assetManager:\s*{\s*upload:\s*false\s*}/);
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
