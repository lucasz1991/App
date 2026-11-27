import test from 'node:test';
import { callQualityControls } from '../../resources/js/call-media-quality.js';
import assert from 'node:assert/strict';
import { callCameraPreview, nativeCameraBlur, supportsNativeCameraBlur } from '../../resources/js/call-camera-preview.js';

function camera() {
    const output = Object.assign(new EventTarget(), { enabled: true });
    return {
        mediaStreamTrack: output,
        stopped: false,
        stop() { this.stopped = true; },
        detach() {},
    };
}

function setup(capture, platform) {
    const config = { labels: { effectFailed: 'Effect paused' } };
    return Object.assign(callCameraPreview(config, value => value, capture, platform), {
        $refs: {}, startWithVideo: false, canPublish: true, isActiveRoom: () => true, toast() {},
        deviceErrorLabel() { return 'Camera denied'; },
    });
}

test('camera denial requires an explicit switch to audio only', async () => {
    const state = setup(async () => { throw new Error('denied'); });
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
    const state = setup(() => { requested(); return pending; });
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

test('preview publishes only native blurred output and pauses when the camera loses blur', async () => {
    const input = camera();
    let enabled = false;
    Object.assign(input.mediaStreamTrack, {
        getCapabilities: () => ({ backgroundBlur: [false, true] }),
        getConstraints: () => ({}),
        getSettings: () => ({ backgroundBlur: enabled }),
        async applyConstraints(options) { enabled = options.backgroundBlur.exact; },
    });
    const platform = {
        MediaStreamTrackProcessor: class {
            readable = new ReadableStream({ start(controller) {
                for (let i = 0; i < 3; i++) controller.enqueue({ close() {} });
            } });
        },
        MediaStreamTrackGenerator: class extends EventTarget {
            writable = new WritableStream();
            stop() {}
        },
    };
    const state = setup(async () => input, platform);
    await state.openCameraPreview();
    state.cameraMode = 'blur';
    await state.refreshCameraPreview();
    assert.equal(state.cameraNativeBlurAvailable, true);
    assert.equal(state.cameraPreviewReady, true);
    assert.equal(await state.commitCameraPreview(), true);
    const sent = [];
    const room = { localParticipant: { async publishTrack(track) { sent.push(track); return { track }; } } };
    state.room = room;
    await state.publishCameraSelection(room, 1);
    assert.equal(sent.length, 1);
    assert.ok(sent[0] instanceof platform.MediaStreamTrackGenerator);
    enabled = false;
    input.mediaStreamTrack.dispatchEvent(new Event('configurationchange'));
    assert.equal(sent[0].enabled, false);
    assert.equal(input.stopped, true);
    assert.equal(state.cameraPreviewMessage, 'Effect paused');
    assert.equal(state.cameraNeedsSetup, true);
    await assert.rejects(state.publishCameraSelection(room, 1), { name: 'AbortError' });
    state.disposeCameraPreview();
});

test('unsupported native blur stops capture and requires a new camera selection', async () => {
    const raw = camera();
    const state = setup(async () => raw, {});
    await state.openCameraPreview();
    state.cameraMode = 'blur';
    await state.refreshCameraPreview();
    assert.equal(state.cameraNativeBlurAvailable, false);
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

async function activeCamera(mode) {
    const captures = [], swaps = [];
    let captureGate, swapGate, failBlur = false;
    const platform = {
        MediaStreamTrackProcessor: class {
            readable = new ReadableStream({ start(controller) {
                for (let i = 0; i < 3; i++) controller.enqueue({ close() {} });
            } });
        },
        MediaStreamTrackGenerator: class extends EventTarget {
            writable = new WritableStream();
            stop() {}
        },
    };
    const capture = async options => {
        const local = camera();
        let blurred = false;
        Object.assign(local.mediaStreamTrack, {
            getSettings: () => ({ deviceId: 'front-camera', backgroundBlur: blurred }),
            getCapabilities: () => ({ backgroundBlur: [false, true] }),
            getConstraints: () => ({}),
            async applyConstraints() { blurred = !failBlur; },
        });
        captures.push({ local, options });
        if (captureGate) await captureGate;
        return local;
    };
    let publication;
    const room = { localParticipant: {
        getTrackPublication: () => publication,
        async unpublishTrack(track) {
            assert.equal(publication.track, track);
            publication = undefined;
        },
        async publishTrack(output) {
            assert.equal(publication, undefined, 'The existing camera must be replaced in place');
            publication = { isMuted: false, track: {
                mediaStreamTrack: output,
                stop() { this.mediaStreamTrack.enabled = false; },
                async replaceTrack(candidate) {
                    swaps.push(candidate);
                    this.mediaStreamTrack = candidate;
                    if (swapGate) await swapGate;
                },
            } };
            return publication;
        },
    } };
    const state = Object.assign(setup(capture, platform), callQualityControls(value => value, {}), {
        connectionGeneration: 1, canControlMedia: true, syncLocalMediaState() {},
        isActiveRoom(candidate, generation) { return candidate === this.room && generation === this.connectionGeneration; },
    });
    await state.openCameraPreview();
    state.cameraMode = mode;
    await state.refreshCameraPreview();
    assert.equal(await state.commitCameraPreview(), true);
    state.room = room;
    await state.publishCameraSelection(room, 1);
    Object.assign(state, { cameraOn: true, connected: true });
    return {
        state, publication, captures, swaps, platform,
        delayCapture(promise) { captureGate = promise; },
        delaySwap(promise) { swapGate = promise; },
        rejectBlur() { failBlur = true; },
    };
}

for (const mode of ['original', 'blur']) {
    test(`active ${mode} camera switches profile while preserving mode, device and publication`, async () => {
        const { state, publication, captures, swaps, platform } = await activeCamera(mode);
        try {
            assert.equal(await state.applyCameraProfile('saver'), true);
            assert.equal(state.cameraProfile, 'auto');
            assert.equal(state.effectiveCameraProfile, 'saver');
            assert.deepEqual(captures[1].options, {
                resolution: { width: 640, height: 360, frameRate: 15 }, deviceId: { exact: 'front-camera' },
            });
            assert.equal(captures[0].local.stopped, true);
            assert.equal(captures[1].local.stopped, false);
            assert.equal(publication.track.mediaStreamTrack, swaps[0]);
            if (mode === 'blur') {
                assert.ok(swaps[0] instanceof platform.MediaStreamTrackGenerator);
                assert.notEqual(swaps[0], captures[1].local.mediaStreamTrack);
            } else {
                assert.equal(swaps[0], captures[1].local.mediaStreamTrack);
            }
            assert.equal(await state.changeCameraProfile('auto'), true);
            assert.equal(captures[2].options.resolution.height, 720);
            assert.equal(swaps.length, 2);
        } finally {
            state.disposeCameraPreview();
        }
    });
}

test('failed blur verification preserves the current protected sender', async () => {
    const { state, publication, swaps, captures, rejectBlur } = await activeCamera('blur');
    const oldOutput = publication.track.mediaStreamTrack;
    try {
        rejectBlur();
        assert.equal(await state.applyCameraProfile('saver'), false);
        assert.equal(publication.track.mediaStreamTrack, oldOutput);
        assert.equal(swaps.length, 0);
        assert.equal(captures[0].local.stopped, false);
        assert.equal(captures[1].local.stopped, true);
        assert.equal(state.effectiveCameraProfile, 'auto');
    } finally {
        state.disposeCameraPreview();
    }
});

test('a late profile capture after disconnect is discarded even if the room is reused', async () => {
    const { state, swaps, captures, delayCapture } = await activeCamera('original');
    let resume;
    delayCapture(new Promise(done => { resume = done; }));
    const pending = state.applyCameraProfile('saver');
    assert.equal(captures.length, 2);
    state.connectionGeneration++;
    state.disposeCameraPreview();
    resume();
    assert.equal(await pending, false);
    assert.equal(captures[1].local.stopped, true);
    assert.equal(swaps.length, 0);
    assert.equal(state.effectiveCameraProfile, 'auto');
});

for (const stage of ['capture', 'swap']) {
    test(`turning the camera off during profile ${stage} prevents a late camera restart`, async () => {
        const { state, swaps, captures, delayCapture, delaySwap } = await activeCamera('original');
        let resume;
        const gate = new Promise(resolve => { resume = resolve; });
        if (stage === 'capture') delayCapture(gate);
        else delaySwap(gate);
        const pending = state.applyCameraProfile('saver');
        await new Promise(resolve => setImmediate(resolve));
        await state.stopConfiguredCamera(state.room);
        assert.equal(state.cameraOn, false);
        assert.equal(state.room.localParticipant.getTrackPublication(), undefined);
        if (stage === 'swap') assert.equal(swaps[0].enabled, false);
        resume();
        assert.equal(await pending, false);
        assert.equal(state.effectiveCameraProfile, 'auto');
        assert.equal(swaps.length, stage === 'capture' ? 0 : 1);
        assert.ok(captures.every(({ local }) => local.stopped));
        assert.equal(state.room.localParticipant.getTrackPublication(), undefined);
    });
}

test('revoking permission during sender replacement immediately stops both captures', async () => {
    const { state, swaps, captures, delaySwap } = await activeCamera('original');
    let resume;
    delaySwap(new Promise(done => { resume = done; }));
    const pending = state.applyCameraProfile('saver');
    await new Promise(done => setImmediate(done));
    assert.equal(swaps.length, 1);
    state.canPublish = false;
    state.canControlMedia = false;
    state.disposeCameraPreview();
    assert.equal(captures[0].local.stopped, true);
    assert.equal(captures[1].local.stopped, true);
    assert.equal(swaps[0].enabled, false);
    resume();
    assert.equal(await pending, false);
    assert.equal(state.effectiveCameraProfile, 'auto');
});
