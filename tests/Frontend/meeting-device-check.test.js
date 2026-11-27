import test from 'node:test';
import assert from 'node:assert/strict';
import { loadCallRoomFactory } from './fixtures/call-room-factory.js';
import { createMeetingMicrophoneCheck } from '../../resources/js/meeting-microphone-check.js';

test('meeting preview only connects after a confirmed device selection', async () => {
    const factory = await loadCallRoomFactory({ window: {} });
    const call = factory({ prejoin: true, canPublish: true, labels: {} });
    const events = [];
    Object.assign(call, {
        $refs: {}, $nextTick: fn => fn(),
        watchViewportShape() {}, observeSelfPreviewStage() {}, queueSelfPreviewGeometry() {}, listMicrophones() {},
        openCameraPreview() { events.push('preview'); },
        async commitCameraPreview() { return false; },
        async connect() { events.push('connect'); },
    });
    await call.init();
    await call.acceptDeviceSettings();
    assert.deepEqual(events, ['preview']);
    assert.equal(call.prejoinOpen, true);
    call.commitCameraPreview = async () => true;
    await call.acceptDeviceSettings();
    assert.equal(call.prejoinOpen, false);
    assert.deepEqual(events, ['preview', 'connect']);
});

test('cancelling settings during a call returns focus without leaving or connecting again', async () => {
    const factory = await loadCallRoomFactory({ window: {} });
    const call = factory({ labels: {} });
    let focus = 0;
    const trigger = { isConnected: true, focus() { focus++; } };
    Object.assign(call, {
        prejoinOpen: true, configuringDuringCall: true, deviceSettingsTrigger: trigger,
        $nextTick: callback => callback(), closeCameraPreview() {},
        $wire: { leaveCall() { assert.fail('live call was closed'); } },
    });
    await call.cancelDeviceSettings();
    assert.equal(call.prejoinOpen, false);
    assert.equal(focus, 1);
});

test('a late microphone permission grant after cancelling the preview stops every opened track', async () => {
    let resolveCapture;
    let stopped = 0;
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {
        mediaDevices: { getUserMedia(options) {
            assert.deepEqual(options, { video: false, audio: { deviceId: { exact: 'desk-mic' } } });
            return new Promise(resolve => { resolveCapture = resolve; });
        } },
    } });
    try {
        const preview = { ...createMeetingMicrophoneCheck(), prejoinOpen: true, microphoneId: 'desk-mic' };
        const pending = preview.testMicrophone();
        preview.prejoinOpen = false;
        preview.stopMicrophoneTest();
        resolveCapture({ getTracks: () => [{ stop() { stopped++; } }] });
        await pending;
        assert.equal(stopped, 1);
        assert.equal(preview.microphoneTesting, false);
    } finally {
        if (previous) Object.defineProperty(globalThis, 'navigator', previous);
        else delete globalThis.navigator;
    }
});
