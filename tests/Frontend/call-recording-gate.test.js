import test from 'node:test';
import assert from 'node:assert/strict';

let alpineInit;
let callRoomFactory;

globalThis.window = {
    innerWidth: 1024,
    Alpine: {
        data(name, factory) {
            if (name === 'callRoom') {
                callRoomFactory = factory;
            }
        },
    },
    RTSound: {
        startRingback() {},
        stopRingback() {},
    },
    dispatchEvent() {},
    setTimeout,
    clearTimeout,
};

globalThis.document = {
    addEventListener(name, listener) {
        if (name === 'alpine:init') {
            alpineInit = listener;
        }
    },
};

await import('../../resources/js/calls.js?recording-gate-tests');
alpineInit();

const labels = {
    connecting: 'connecting',
    reconnecting: 'reconnecting',
    connectionFailed: 'connection failed',
    ended: 'ended',
    recordingAcknowledgementRequired: 'recording acknowledgement required',
    recordingAcknowledgementFailed: 'recording acknowledgement failed',
    recordingActive: 'recording active',
    recordingFailed: 'recording failed',
    recordingPreparing: 'recording preparing',
};

function createComponent(overrides = {}) {
    return callRoomFactory({
        canPublish: true,
        startWithVideo: false,
        waiting: [],
        tokenUrl: '/calls/room/token',
        recordingAcknowledgementUrl: '/calls/room/recording/acknowledge',
        recordingPolicyVersion: '2026-08-01',
        csrf: 'test-token',
        labels,
        ...overrides,
    });
}

function installFakeTimers() {
    const originalSetTimeout = window.setTimeout;
    const originalClearTimeout = window.clearTimeout;
    const timers = new Map();
    let nextId = 1;

    window.setTimeout = (callback, delay) => {
        const id = nextId++;
        timers.set(id, { callback, delay });

        return id;
    };
    window.clearTimeout = (id) => timers.delete(id);

    return {
        get size() {
            return timers.size;
        },
        nextDelay() {
            return timers.values().next().value?.delay;
        },
        async runNext() {
            const entry = timers.entries().next().value;
            assert.ok(entry, 'expected a scheduled recording poll');
            const [id, timer] = entry;
            timers.delete(id);
            await timer.callback();
        },
        restore() {
            window.setTimeout = originalSetTimeout;
            window.clearTimeout = originalClearTimeout;
        },
    };
}

test('teardown aborts a pending session and prevents a late getUserMedia fallback', async () => {
    const originalFetch = globalThis.fetch;
    const originalNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const originalWarn = console.warn;
    let sessionSignal;
    let rejectLiveKitEnable;
    let getUserMediaCalls = 0;

    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            mediaDevices: {
                async getUserMedia() {
                    getUserMediaCalls += 1;

                    return { getTracks: () => [] };
                },
            },
        },
    });

    try {
        console.warn = () => {};
        globalThis.fetch = (_url, options) => new Promise((_resolve, reject) => {
            sessionSignal = options.signal;
            options.signal.addEventListener('abort', () => {
                reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
            }, { once: true });
        });

        const pendingSessionComponent = createComponent();
        const pendingConnect = pendingSessionComponent.connect();
        await Promise.resolve();
        pendingSessionComponent.destroy();
        await pendingConnect;

        assert.equal(sessionSignal.aborted, true);
        assert.equal(pendingSessionComponent.failed, false);

        const activeRoom = {
            disconnect() {},
            localParticipant: {
                setMicrophoneEnabled() {
                    return new Promise((_resolve, reject) => {
                        rejectLiveKitEnable = reject;
                    });
                },
                async publishTrack() {},
            },
        };
        const mediaComponent = createComponent();
        mediaComponent.room = activeRoom;
        mediaComponent.canPublish = true;

        const pendingMedia = mediaComponent.enableTrack('microphone');
        await Promise.resolve();
        mediaComponent.destroy();
        rejectLiveKitEnable(new Error('late LiveKit failure'));

        await assert.rejects(pendingMedia, { name: 'AbortError' });
        assert.equal(getUserMediaCalls, 0);
    } finally {
        console.warn = originalWarn;
        globalThis.fetch = originalFetch;

        if (originalNavigator) {
            Object.defineProperty(globalThis, 'navigator', originalNavigator);
        } else {
            delete globalThis.navigator;
        }
    }
});

test('a reconnecting room reschedules recording polling instead of losing the gate', async () => {
    const timers = installFakeTimers();

    try {
        const component = createComponent();
        component.room = {};
        component.connected = false;
        component.connectionGeneration = 4;
        component.requestSession = async () => {
            throw new Error('polling must wait until the room reconnects');
        };

        component.startRecordingPolling();
        assert.equal(timers.nextDelay(), 1000);

        await timers.runNext();

        assert.equal(timers.size, 1);
        assert.equal(timers.nextDelay(), 1000);
        assert.equal(component.failed, false);
    } finally {
        timers.restore();
    }
});

test('ACTIVE recording stops polling only after publish permission is confirmed by the SFU', async () => {
    const timers = installFakeTimers();

    try {
        const component = createComponent();
        component.room = {};
        component.connected = true;
        component.connectionGeneration = 7;
        component.recordingEnabled = true;
        component.recordingStatus = 'requested';
        component.canPublish = false;
        let sfuMayPublish = false;
        let enabledDevices = 0;

        component.requestSession = async () => ({
            ok: true,
            async json() {
                return {
                    recording: {
                        enabled: true,
                        status: 'active',
                        canPublish: sfuMayPublish,
                    },
                };
            },
        });
        component.enableDevices = async () => {
            enabledDevices += 1;
        };

        component.startRecordingPolling();
        await timers.runNext();

        assert.equal(component.recordingStatus, 'active');
        assert.equal(component.canPublish, false);
        assert.equal(enabledDevices, 0);
        assert.equal(timers.size, 1);
        assert.equal(timers.nextDelay(), 2000);

        sfuMayPublish = true;
        await timers.runNext();

        assert.equal(component.canPublish, true);
        assert.equal(enabledDevices, 1);
        assert.equal(timers.size, 0);
    } finally {
        timers.restore();
    }
});

test('HTTP 428 keeps media controls disabled until recording acknowledgement', async () => {
    const component = createComponent();
    let microphoneChanges = 0;
    let cameraChanges = 0;
    let screenShareChanges = 0;

    component.requestSession = async () => ({
        ok: false,
        status: 428,
        async json() {
            return {
                recording: {
                    requiresAcknowledgement: true,
                    policyVersion: '2026-08-01-v2',
                },
            };
        },
    });

    await component.connect();

    assert.equal(component.recordingEnabled, true);
    assert.equal(component.recordingStatus, 'acknowledgement_required');
    assert.equal(component.recordingRequiresAcknowledgement, true);
    assert.equal(component.recordingPolicyVersion, '2026-08-01-v2');
    assert.equal(component.canPublish, false);

    component.room = {
        localParticipant: {
            async setMicrophoneEnabled() { microphoneChanges += 1; },
            async setCameraEnabled() { cameraChanges += 1; },
            async setScreenShareEnabled() { screenShareChanges += 1; },
        },
    };

    await component.toggleMic();
    await component.toggleCamera();
    await component.toggleScreenShare();

    assert.deepEqual(
        { microphoneChanges, cameraChanges, screenShareChanges },
        { microphoneChanges: 0, cameraChanges: 0, screenShareChanges: 0 },
    );
});

test('initial HTTP 503 preserves the unavailable recording state for the failure UI', async () => {
    const originalError = console.error;
    const component = createComponent();

    try {
        console.error = () => {};
        component.requestSession = async () => ({
            ok: false,
            status: 503,
            async json() {
                return {
                    message: 'recording infrastructure unavailable',
                    recording: {
                        enabled: true,
                        status: 'unavailable',
                        canPublish: false,
                    },
                };
            },
        });

        await component.connect();

        assert.equal(component.recordingEnabled, true);
        assert.equal(component.recordingStatus, 'unavailable');
        assert.equal(component.canPublish, false);
        assert.equal(component.failed, true);
        assert.equal(component.statusLabel, 'recording infrastructure unavailable');
        assert.equal(component.recordingStatusLabel, labels.recordingFailed);
    } finally {
        console.error = originalError;
    }
});

test('poll HTTP 503 preserves the failed recording state while ending the media room', async () => {
    const originalError = console.error;
    const timers = installFakeTimers();
    let disconnects = 0;

    try {
        console.error = () => {};
        const component = createComponent();
        component.room = {
            disconnect() {
                disconnects += 1;
            },
        };
        component.connected = true;
        component.connectionGeneration = 9;
        component.recordingEnabled = true;
        component.recordingStatus = 'requested';
        component.canPublish = false;
        component.requestSession = async () => ({
            ok: false,
            status: 503,
            async json() {
                return {
                    message: 'recording failed',
                    recording: {
                        enabled: true,
                        status: 'failed',
                        canPublish: false,
                    },
                };
            },
        });

        component.startRecordingPolling();
        await timers.runNext();

        assert.equal(component.recordingEnabled, true);
        assert.equal(component.recordingStatus, 'failed');
        assert.equal(component.canPublish, false);
        assert.equal(component.failed, true);
        assert.equal(component.statusLabel, 'recording failed');
        assert.equal(component.recordingStatusLabel, labels.recordingFailed);
        assert.equal(component.room, null);
        assert.equal(disconnects, 1);
        assert.equal(timers.size, 0);
    } finally {
        console.error = originalError;
        timers.restore();
    }
});

test('final LiveKit disconnect cancels polling and fetch state before invalidating the room', () => {
    const timers = installFakeTimers();
    const handlers = new Map();
    let aborts = 0;

    try {
        const component = createComponent();
        const room = {
            localParticipant: {},
            on(event, handler) {
                handlers.set(event, handler);

                return this;
            },
        };
        component.room = room;
        component.connected = true;
        component.everConnected = true;
        component.connectionGeneration = 12;
        component.sessionAbortController = {
            abort() {
                aborts += 1;
            },
        };

        component.bindRoomEvents();
        component.startRecordingPolling();
        assert.equal(timers.size, 1);

        const disconnected = handlers.get('disconnected');
        assert.equal(typeof disconnected, 'function');
        disconnected('clientInitiated');

        assert.equal(timers.size, 0);
        assert.equal(component.recordingPollTimer, null);
        assert.equal(aborts, 1);
        assert.equal(component.sessionAbortController, null);
        assert.equal(component.connectionGeneration, 13);
        assert.equal(component.room, null);
        assert.equal(component.connected, false);
        assert.equal(component.statusLabel, labels.ended);
    } finally {
        timers.restore();
    }
});

test('ACTIVE without SFU publish permission ignores an expired egress deadline and fails after five bounded retries', async () => {
    const originalError = console.error;
    const timers = installFakeTimers();
    let requests = 0;
    let disconnects = 0;

    try {
        console.error = () => {};
        const component = createComponent();
        component.room = {
            disconnect() {
                disconnects += 1;
            },
        };
        component.connected = true;
        component.connectionGeneration = 15;
        component.recordingEnabled = true;
        component.recordingStatus = 'requested';
        component.recordingStartDeadlineAt = new Date(Date.now() - 60_000).toISOString();
        component.canPublish = false;
        component.requestSession = async () => {
            requests += 1;

            return {
                ok: true,
                async json() {
                    return {
                        recording: {
                            enabled: true,
                            status: 'active',
                            canPublish: false,
                            startDeadlineAt: component.recordingStartDeadlineAt,
                        },
                    };
                },
            };
        };

        component.startRecordingPolling();

        for (let attempt = 1; attempt < 5; attempt += 1) {
            await timers.runNext();

            assert.equal(component.recordingUnlockAttempts, attempt);
            assert.equal(component.recordingStatus, 'active');
            assert.equal(component.failed, false);
            assert.notEqual(component.room, null);
            assert.equal(timers.size, 1);
            assert.equal(timers.nextDelay(), 2000);
        }

        await timers.runNext();

        assert.equal(requests, 5);
        assert.equal(component.recordingUnlockAttempts, 5);
        assert.equal(component.recordingStatus, 'failed');
        assert.equal(component.failed, true);
        assert.equal(component.room, null);
        assert.equal(disconnects, 1);
        assert.equal(timers.size, 0);
    } finally {
        console.error = originalError;
        timers.restore();
    }
});
