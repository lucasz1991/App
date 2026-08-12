import test from 'node:test';
import assert from 'node:assert/strict';

import {
    FILE_PREVIEW_MAX_ZOOM,
    FILE_PREVIEW_MIN_ZOOM,
    clampPreviewZoom,
    constrainPreviewPan,
    filePreview,
    previewFitZoom,
    zoomPanAroundPoint,
} from '../../resources/js/file-preview.js';

test('manual preview zoom is bounded to 25-400 percent while fit may go smaller', () => {
    assert.equal(clampPreviewZoom(-10), FILE_PREVIEW_MIN_ZOOM);
    assert.equal(clampPreviewZoom(0.75), 0.75);
    assert.equal(clampPreviewZoom(99), FILE_PREVIEW_MAX_ZOOM);
    assert.equal(previewFitZoom({
        naturalWidth: 8000,
        naturalHeight: 4000,
        stageWidth: 800,
        stageHeight: 600,
        inset: 0,
    }), 0.1);
});

test('pointer-centered zoom keeps the image point below the cursor stable', () => {
    const result = zoomPanAroundPoint({
        zoom: 1,
        nextZoom: 2,
        panX: 0,
        panY: 0,
        clientX: 75,
        clientY: 25,
        stageRect: { left: 0, top: 0, width: 100, height: 100 },
    });

    assert.deepEqual(result, { panX: -25, panY: 25 });
});

test('pan constraints keep media edges on the stage and center smaller media', () => {
    assert.deepEqual(constrainPreviewPan({
        panX: 999,
        panY: -999,
        zoom: 1,
        naturalWidth: 1000,
        naturalHeight: 800,
        stageWidth: 500,
        stageHeight: 400,
    }), { panX: 250, panY: -200 });

    assert.deepEqual(constrainPreviewPan({
        panX: 90,
        panY: -40,
        zoom: 0.5,
        naturalWidth: 400,
        naturalHeight: 200,
        stageWidth: 500,
        stageHeight: 400,
    }), { panX: 0, panY: 0 });
});

function previewHarness() {
    const classes = new Set();
    const paused = [];
    const focused = [];
    const stage = {
        getBoundingClientRect: () => ({ left: 0, top: 0, width: 500, height: 400 }),
    };
    const image = {
        naturalWidth: 1000,
        naturalHeight: 500,
        complete: true,
    };
    const heading = { focus: () => focused.push('heading') };
    const media = [
        { pause: () => paused.push('video') },
        { pause: () => paused.push('audio') },
    ];
    const dialog = {
        querySelector(selector) {
            if (selector === '[data-file-preview-image-stage]') return stage;
            if (selector === '[data-file-preview-image]') return image;
            if (selector === '[data-file-preview-heading]') return heading;

            return null;
        },
        querySelectorAll: () => media,
    };
    const trigger = {
        isConnected: true,
        closest: () => null,
        focus: () => focused.push('trigger'),
    };
    const document = {
        activeElement: trigger,
        documentElement: {
            classList: {
                add: (value) => classes.add(value),
                remove: (value) => classes.delete(value),
            },
        },
        querySelector: (selector) => selector === '[data-file-preview-dialog]' ? dialog : null,
    };
    let closeCalls = 0;
    let watcher = null;
    const component = filePreview({
        open: false,
        environment: {
            document,
            window: {
                innerWidth: 1200,
                requestAnimationFrame(callback) { callback(); return 1; },
                cancelAnimationFrame() {},
            },
        },
    });
    component.$watch = (_property, callback) => { watcher = callback; };
    component.$nextTick = (callback) => callback();
    component.$wire = { close: () => { closeCalls += 1; } };
    component.init();

    return {
        component,
        classes,
        focused,
        paused,
        open: () => {
            component.open = true;
            watcher(true);
        },
        closeCalls: () => closeCalls,
    };
}

test('viewer fits images, exposes actual size and resets and pauses media on close', () => {
    const harness = previewHarness();
    const preview = harness.component;

    harness.open();
    assert.equal(harness.classes.has('rt-file-preview-open'), true);
    assert.deepEqual(harness.focused, ['heading']);

    preview.activateFile('42-v3', 'image');
    preview.fitImage();
    assert.equal(preview.zoom, 0.436);
    assert.equal(preview.zoomLabel(), '44 %');

    preview.actualSize();
    assert.equal(preview.zoom, 1);
    preview.panX = 60;
    preview.panY = 20;
    preview.closePreview();

    assert.equal(preview.open, false);
    assert.equal(harness.closeCalls(), 1);
    assert.equal(harness.classes.has('rt-file-preview-open'), true, 'watcher owns final close cleanup');
    assert.deepEqual(harness.paused, ['video', 'audio']);

    // Entangle/Livewire synchronisiert den finalen Zustand und stellt Fokus
    // sowie Transform genau einmal wieder her.
    preview.syncOpen(false);
    assert.equal(harness.classes.has('rt-file-preview-open'), false);
    assert.equal(preview.zoom, 1);
    assert.equal(preview.panX, 0);
    assert.deepEqual(harness.focused, ['heading', 'trigger']);
});

test('double tap toggles between fitted and original image size', () => {
    const { component: preview } = previewHarness();
    preview.activateFile('9-v1', 'image');
    preview.fitImage();
    assert.equal(preview.zoom, 0.436);

    preview.handleTouchTap({ timeStamp: 100, clientX: 220, clientY: 180 });
    preview.handleTouchTap({ timeStamp: 260, clientX: 224, clientY: 182 });
    assert.equal(preview.zoom, 1);

    preview.handleTouchTap({ timeStamp: 700, clientX: 220, clientY: 180 });
    preview.handleTouchTap({ timeStamp: 890, clientX: 220, clientY: 180 });
    assert.equal(preview.zoom, preview.fitZoom);
});
