import test from 'node:test';
import assert from 'node:assert/strict';
import { callCameraPreview, nativeCameraBlur, supportsNativeCameraBlur } from '../../resources/js/call-camera-preview.js';

function camera() {
    const output = Object.assign(new EventTarget(), { enabled: true });
    return {
        mediaStreamTrack: output,
        stopped: false,
        stop() { this.stopped = true; },
        detach() {},
        async setProcessor(processor) {
            processor.options.onFrameProcessed();
            processor.options.onFrameProcessed();
        },
    };
}

function setup(capture, loadEffects) {
    const config = { cameraAssets: '/effects', cameraBackground: '/brand.jpg', labels: { effectFailed: 'Effect paused' } };
    return Object.assign(callCameraPreview(config, value => value, capture, loadEffects), {
        $refs: {}, startWithVideo: false, canPublish: true, isActiveRoom: () => true, toast() {},
        deviceErrorLabel() { return 'Camera denied'; },
    });
}

test('camera denial requires an explicit switch to audio only', async () => {
    const state = setup(async () => { throw new Error('denied'); }, async () => null);
    state.startWithVideo = true;
    await state.openCameraPreview();
    assert.equal(state.cameraPreviewMessage, 'Camera denied');
    assert.equal(await state.commitCameraPreview(), false);
    state.cameraMode = 'off';
    await state.refreshCameraPreview();
    assert.equal(await state.commitCameraPreview(), true);
    assert.equal(state.startWithVideo, false);
    assert.equal(state.cameraNeedsSetup, true);
});

test('closing a camera prompt stops a later-granted capture', async () => {
    let grant, requested;
    const pending = new Promise(resolve => { grant = resolve; });
    const started = new Promise(resolve => { requested = resolve; });
    const state = setup(() => { requested(); return pending; }, async () => null);
    state.startWithVideo = true;
    const operation = state.openCameraPreview();
    await started;
    state.closeCameraPreview();
    const acquired = camera();
    grant(acquired);
    await operation;
    assert.equal(acquired.stopped, true);
    assert.equal(state.cameraPreviewReady, false);
});

test('only warmed processed media is published; processor failure turns off the camera', async () => {
    const input = camera();
    const processed = Object.assign(new EventTarget(), { enabled: true });
    const state = setup(async () => input, async () => ({
        supportsBackgroundProcessors: () => true,
        BackgroundProcessor: options => ({ options, processedTrack: processed }),
    }));
    await state.openCameraPreview();
    state.cameraMode = 'blur';
    await state.refreshCameraPreview();
    assert.equal(state.cameraPreviewReady, true);
    assert.equal(await state.commitCameraPreview(), true);
    const sent = [];
    const room = { localParticipant: { async publishTrack(track) { sent.push(track); return { track }; } } };
    state.room = room;
    await state.publishCameraSelection(room, 1);
    assert.deepEqual(sent, [processed]);
    processed.dispatchEvent(new Event('ended'));
    assert.equal(processed.enabled, false);
    assert.equal(input.stopped, true);
    assert.equal(state.cameraPreviewMessage, 'Effect paused');
    assert.equal(state.cameraNeedsSetup, true);
    await assert.rejects(state.publishCameraSelection(room, 1), { name: 'AbortError' });
    state.disposeCameraPreview();
});

test('processor initialization failure releases capture and keeps original unpublished', async () => {
    const raw = camera();
    const state = setup(async () => raw, async () => ({ supportsBackgroundProcessors: () => false }));
    await state.openCameraPreview();
    state.cameraMode = 'brand';
    await state.refreshCameraPreview();
    assert.equal(state.cameraEffectsAvailable, false);
    assert.equal(state.cameraPreviewReady, false);
    assert.equal(raw.stopped, true);
    assert.equal(await state.commitCameraPreview(), false);
});

test('native blur requires a real device capability, exact constraint and verified settings', async () => {
    const platform = { MediaStreamTrackProcessor: class {}, MediaStreamTrackGenerator: class {} };
    let requested;
    const source = Object.assign(new EventTarget(), {
        getCapabilities: () => ({ backgroundBlur: [true, false] }),
        getSettings: () => ({ backgroundBlur: false }),
        getConstraints: () => ({ width: 640 }),
        applyConstraints: async value => { requested = value; },
    });
    assert.equal(supportsNativeCameraBlur(source, platform), true);
    assert.equal(supportsNativeCameraBlur(source, {}), false);
    await assert.rejects(nativeCameraBlur(source, () => {}, () => {}, platform), /did not enable/);
    assert.deepEqual(requested, { width: 640, backgroundBlur: { exact: true } });
    source.getCapabilities = () => ({ backgroundBlur: [false] });
    assert.equal(supportsNativeCameraBlur(source, platform), false);
});

test('native output discards the first frame and each frame after loss of blur', async () => {
    let controller, active = true, failed = 0;
    const delivered = [], discarded = [];
    const platform = {
        MediaStreamTrackProcessor: class {
            readable = new ReadableStream({ start(ctl) { controller = ctl; } });
        },
        MediaStreamTrackGenerator: class {
            writable = new WritableStream({ write(frame) { delivered.push(frame.id); } });
            stop() {}
        },
    };
    const source = Object.assign(new EventTarget(), {
        getCapabilities: () => ({ backgroundBlur: [true] }),
        getSettings: () => ({ backgroundBlur: active }),
        getConstraints: () => ({}),
        applyConstraints: async () => {},
    });
    const native = await nativeCameraBlur(source, () => {}, () => { failed++; }, platform);
    const enqueue = async id => {
        controller.enqueue({ id, close() { discarded.push(id); } });
        await new Promise(done => setTimeout(done, 0));
    };
    await enqueue('initial');
    await enqueue('protected');
    active = false;
    await enqueue('unprotected');
    assert.deepEqual(delivered, ['protected']);
    assert.deepEqual(discarded, ['initial', 'unprotected']);
    assert.equal(failed, 1);
    native.stop();
});
