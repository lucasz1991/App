import test from 'node:test';
import assert from 'node:assert/strict';
import { Room, RoomEvent, Track, ConnectionState, DisconnectReason, ParticipantKind } from 'livekit-client';
import { reactive, toRaw } from '@vue/reactivity';
import { loadCallRoomFactory } from './fixtures/call-room-factory.js';

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};

class FakeRoom {
    static nextConnect = null;
    constructor() {
        this.handlers = new Map();
        this.remoteParticipants = new Map();
        this.disconnects = 0;
        this.connectResult = FakeRoom.nextConnect;
        FakeRoom.nextConnect = null;
        const publications = new Map();
        const set = (source, enabled) => {
            if (enabled) publications.set(source, { source, isMuted: false, track: { detach() {} } });
            else publications.delete(source);
        };
        this.localParticipant = {
            identity: 'self', trackPublications: publications,
            get isMicrophoneEnabled() { return publications.has(Track.Source.Microphone); },
            get isCameraEnabled() { return publications.has(Track.Source.Camera); },
            get isScreenShareEnabled() { return publications.has(Track.Source.ScreenShare); },
            async setMicrophoneEnabled(enabled) { set(Track.Source.Microphone, enabled); },
            async setCameraEnabled(enabled) { set(Track.Source.Camera, enabled); },
            async setScreenShareEnabled(enabled) { set(Track.Source.ScreenShare, enabled); },
        };
    }
    on(event, callback) { this.handlers.set(event, callback); return this; }
    emit(event, ...args) { return this.handlers.get(event)?.(...args); }
    async connect() { await this.connectResult; }
    async disconnect() { this.disconnects += 1; this.emit(RoomEvent.Disconnected, DisconnectReason.CLIENT_INITIATED); }
}

async function fixture({ startWithVideo = false, navigator: navigatorLike, reactiveState = false } = {}) {
    const windowLike = {
        innerWidth: 390, setTimeout, clearTimeout,
        RTSound: { startRingback() {}, stopRingback() {} },
        Alpine: { raw: toRaw },
    };
    const factory = await loadCallRoomFactory({
        Room: FakeRoom, window: windowLike,
        navigator: navigatorLike || { mediaDevices: { getDisplayMedia() {} } },
        console: { error() {}, warn() {} },
    });
    const state = factory({
        selfIdentity: 'self', canPublish: true, startWithVideo, waiting: [],
        labels: { connecting: 'connecting', reconnecting: 'reconnecting', ended: 'ended', connectionFailed: 'failed' },
    });
    const component = reactiveState ? reactive(state) : state;
    component.$refs = {};
    component.$nextTick = callback => callback();
    component.renderAllParticipants = () => {};
    component.toast = () => {};
    component.requestSession = async () => ({ ok: true, async json() { return { wsUrl: 'fixture', token: 'fixture' }; } });
    return component;
}

test('reactive SDK room ownership accepts its proxy and rejects a different native room', async () => {
    const component = await fixture({ reactiveState: true });
    const room = new Room();
    component.room = room;
    assert.notEqual(component.room, room, 'the installed Alpine reactivity engine wraps the real SDK Room');
    assert.equal(toRaw(component.room), room);
    assert.equal(component.isActiveRoom(room), true);
    assert.equal(component.isActiveRoom(component.room), true);
    assert.equal(component.isActiveRoom(new Room()), false);
});

test('reactive controller stays connected after its own native connection resolves', async () => {
    const component = await fixture({ reactiveState: true });
    await component.connect();
    const room = toRaw(component.room);
    assert.equal(component.connected, true);
    assert.equal(room.disconnects, 0, 'a proxy must not enter the stale-room disconnect branch');
    assert.equal(component.micOn, true);
    assert.equal(component.canControlMedia, true);
    await component.disconnect();
    assert.equal(room.disconnects, 1);
    assert.equal(component.leaving, false);
    assert.equal(component.disconnectPromise, null);
});

test('reactive controller processes current native callbacks and still ignores retired room callbacks', async () => {
    const component = await fixture({ reactiveState: true });
    const room = new FakeRoom();
    component.room = room;
    component.connected = true;
    component.participantMayPublish = false;
    component.canPublish = false;
    component.bindRoomEvents(room);
    room.localParticipant.permissions = { canPublish: true };
    await room.emit(RoomEvent.ParticipantPermissionsChanged, {}, room.localParticipant);
    assert.equal(component.canControlMedia, true);
    await room.localParticipant.setMicrophoneEnabled(true);
    room.emit(RoomEvent.LocalTrackPublished);
    assert.equal(component.micOn, true);
    const replacement = new FakeRoom();
    component.room = replacement;
    component.connectionGeneration += 1;
    room.emit(RoomEvent.Disconnected, DisconnectReason.ROOM_DELETED);
    assert.equal(toRaw(component.room), replacement);
    assert.equal(component.connected, true);
});

test('media actions are inert during connecting, leaving, terminal, failure and recording acknowledgement', async () => {
    const component = await fixture();
    component.room = new FakeRoom();
    component.connected = true;
    let actions = 0;
    component.enableTrack = async () => { actions += 1; };
    component.room.localParticipant.setScreenShareEnabled = async () => { actions += 1; };

    for (const [property, value] of [['connected', false], ['connectionBusy', true], ['leaving', true],
        ['terminal', true], ['failed', true], ['recordingRequiresAcknowledgement', true], ['canPublish', false]]) {
        const prior = component[property];
        component[property] = value;
        assert.equal(component.canControlMedia, false, property);
        await component.toggleMic();
        await component.toggleCamera();
        await component.toggleScreenShare();
        component[property] = prior;
    }
    assert.equal(actions, 0);
    assert.equal(component.canControlMedia, true);
});

test('closed or revoked token responses finish the session without a futile retry state', async () => {
    for (const status of [403, 410]) {
        const component = await fixture();
        component.requestSession = async () => ({
            ok: false, status,
            async json() { return { message: 'closed or removed' }; },
        });
        await component.connect();
        assert.equal(component.terminal, true);
        assert.equal(component.failed, false);
        assert.equal(component.room, null);
        assert.equal(component.canControlMedia, false);
        assert.equal(component.connectionBusy, false);
        assert.equal(component.statusLabel, 'closed or removed');
    }
});

test('a retry resets old media and enables mic and camera in the replacement room', async () => {
    const component = await fixture({ startWithVideo: true });
    const oldRoom = new FakeRoom();
    component.room = oldRoom;
    component.connected = true;
    component.micOn = component.cameraOn = component.screenSharing = component.selfVideoVisible = true;
    await component.retry();
    assert.notEqual(component.room, oldRoom);
    assert.equal(oldRoom.disconnects, 1);
    assert.equal(component.micOn, true);
    assert.equal(component.cameraOn, true);
    assert.equal(component.room.localParticipant.isMicrophoneEnabled, true);
    assert.equal(component.room.localParticipant.isCameraEnabled, true);
    assert.equal(component.screenSharing, false);
    assert.equal(component.selfVideoVisible, false);
    assert.equal(component.canControlMedia, true);
});

test('callbacks from a superseded room cannot clear the replacement or its generation', async () => {
    const component = await fixture();
    const oldRoom = new FakeRoom();
    component.room = oldRoom;
    component.bindRoomEvents();
    const replacement = new FakeRoom();
    component.room = replacement;
    component.connectionGeneration = 4;
    component.connected = true;
    oldRoom.emit(RoomEvent.AudioPlaybackStatusChanged);
    oldRoom.emit(RoomEvent.ConnectionStateChanged, ConnectionState.Reconnecting);
    oldRoom.emit(RoomEvent.Disconnected, DisconnectReason.ROOM_DELETED);
    assert.equal(component.room, replacement);
    assert.equal(component.connectionGeneration, 4);
    assert.equal(component.connected, true);
    assert.equal(component.audioBlocked, false);
});

test('participant presentation follows the exact transport roster across joins, leaves and reconnects', async () => {
    const component = await fixture();
    const room = new FakeRoom();
    component.room = room;
    component.connected = true;
    component.bindRoomEvents();
    const refresh = () => component.syncConnectedRoster([room.localParticipant, ...room.remoteParticipants.values()]);
    room.remoteParticipants.set('recorder', { identity: 'recorder', kind: ParticipantKind.EGRESS });
    refresh();
    assert.equal(component.participantCount, 1);
    component.waiting = [{ id: 1, identity: 'remote' }, { id: 2, identity: 'invited' }];
    assert.equal(component.isParticipantConnected('self'), true);
    assert.equal(component.isParticipantConnected('remote'), false);
    room.remoteParticipants.set('remote', { identity: 'remote' });
    refresh();
    assert.equal(component.participantCount, 2);
    assert.equal(component.openInvitations.length, 1);
    assert.equal(component.openInvitations[0].identity, 'invited');
    assert.equal(component.isParticipantConnected('recorder'), false);
    assert.equal(component.isParticipantConnected('remote'), true);
    for (const identity of [null, undefined, '', ' ', 1, ' remote ', 'other']) {
        assert.equal(component.isParticipantConnected(identity), false);
    }
    room.emit(RoomEvent.ConnectionStateChanged, ConnectionState.Reconnecting);
    assert.equal(component.isParticipantConnected('self'), false);
    assert.equal(component.isParticipantConnected('remote'), false);
    room.emit(RoomEvent.ConnectionStateChanged, ConnectionState.Connected);
    assert.equal(component.isParticipantConnected('remote'), true);
    room.remoteParticipants.delete('remote');
    room.remoteParticipants.set('replacement', { identity: 'replacement' });
    refresh();
    assert.equal(component.participantCount, 2);
    assert.equal(component.isParticipantConnected('replacement'), true);
    assert.equal(component.isParticipantConnected('remote'), false);
    await component.disconnect();
    assert.equal(component.isParticipantConnected('self'), false);
});

test('a late connecting room disconnects itself and leaves the fresh connection intact', async () => {
    const component = await fixture();
    const oldConnect = deferred();
    FakeRoom.nextConnect = oldConnect.promise;
    const pending = component.connect();
    while (!component.room) await Promise.resolve();
    const oldRoom = component.room;
    await component.disconnect();
    await component.connect();
    const replacement = component.room;
    oldConnect.resolve();
    await pending;
    assert.equal(component.room, replacement);
    assert.equal(replacement.disconnects, 0);
    assert.equal(component.connected, true);
    assert.ok(oldRoom.disconnects >= 1);
});

test('a rejected fallback publication stops the user-provided raw media stream', async () => {
    let stops = 0;
    const track = { stop() { stops += 1; } };
    const component = await fixture({ navigator: { mediaDevices: {
        async getUserMedia() { return { getAudioTracks: () => [track], getTracks: () => [track] }; },
    } } });
    component.room = new FakeRoom();
    component.room.localParticipant.setMicrophoneEnabled = async () => { throw new Error('native failed'); };
    component.room.localParticipant.publishTrack = async () => { throw new Error('publish failed'); };
    await assert.rejects(component.enableTrack('microphone'), /publish failed/);
    assert.equal(stops, 1);
});

test('media resolving after teardown is stopped without publishing or restoring active flags', async () => {
    const media = deferred();
    let opened = false;
    let stops = 0;
    let publishes = 0;
    const track = { stop() { stops += 1; } };
    const component = await fixture({ navigator: { mediaDevices: {
        getUserMedia() { opened = true; return media.promise; },
    } } });
    component.room = new FakeRoom();
    component.connected = true;
    component.room.localParticipant.setMicrophoneEnabled = async () => { throw new Error('native failed'); };
    component.room.localParticipant.publishTrack = async () => { publishes += 1; };
    const pending = component.enableTrack('microphone');
    while (!opened) await Promise.resolve();
    await component.disconnect();
    media.resolve({ getAudioTracks: () => [track], getTracks: () => [track] });
    await assert.rejects(pending, { name: 'AbortError' });
    assert.equal(stops, 1);
    assert.equal(publishes, 0);
    assert.equal(component.micOn, false);
    assert.equal(component.connected, false);
    assert.equal(component.terminal, true);
});

test('a pending native media enable cannot revive the destroyed component', async () => {
    const component = await fixture({ startWithVideo: true });
    const room = new FakeRoom();
    const pendingEnable = deferred();
    const originalEnable = room.localParticipant.setMicrophoneEnabled;
    let started = false;
    room.localParticipant.setMicrophoneEnabled = async (enabled) => {
        if (enabled) { started = true; await pendingEnable.promise; }
        await originalEnable(enabled);
    };
    component.room = room;
    component.connected = true;
    const pending = component.enableDevices(false);
    while (!started) await Promise.resolve();
    component.destroy();
    pendingEnable.resolve();
    await pending;
    assert.equal(room.localParticipant.isMicrophoneEnabled, false);
    assert.equal(room.localParticipant.isCameraEnabled, false);
    assert.equal(component.micOn, false);
    assert.equal(component.cameraOn, false);
    assert.equal(component.deviceSetupNeeded, false);
});

test('rapid duplicate media toggles serialize and failed disable retains the live state', async () => {
    const component = await fixture();
    component.room = new FakeRoom();
    component.connected = true;
    const pendingEnable = deferred();
    let enables = 0;
    component.enableTrack = async () => {
        enables += 1;
        await pendingEnable.promise;
        await component.room.localParticipant.setMicrophoneEnabled(true);
    };
    const pending = component.toggleMic();
    await component.toggleMic();
    assert.equal(enables, 1);
    assert.equal(component.micBusy, true);
    pendingEnable.resolve();
    await pending;
    assert.equal(component.micOn, true);
    assert.equal(component.micBusy, false);
    component.room.localParticipant.setMicrophoneEnabled = async () => { throw new Error('disable failed'); };
    await component.toggleMic();
    assert.equal(component.micOn, true);
});

test('automatic media enable after recording unlock also locks a duplicate toggle', async () => {
    const component = await fixture();
    component.room = new FakeRoom();
    component.connected = true;
    const pendingEnable = deferred();
    const originalEnable = component.room.localParticipant.setMicrophoneEnabled;
    let enables = 0;
    component.room.localParticipant.setMicrophoneEnabled = async enabled => {
        enables += 1;
        await pendingEnable.promise;
        await originalEnable(enabled);
    };
    const pending = component.enableDevices(false);
    assert.equal(component.micBusy, true);
    await component.toggleMic();
    assert.equal(enables, 1);
    pendingEnable.resolve();
    await pending;
    assert.equal(component.micBusy, false);
    assert.equal(component.micOn, true);
});

test('browser screen-sharing stop updates the control and failed stop preserves live sharing', async () => {
    const component = await fixture();
    const room = new FakeRoom();
    component.room = room;
    component.connected = true;
    component.bindRoomEvents();
    await component.toggleScreenShare();
    assert.equal(component.screenSharing, true);
    room.localParticipant.trackPublications.delete(Track.Source.ScreenShare);
    room.emit(RoomEvent.LocalTrackUnpublished);
    assert.equal(component.screenSharing, false);
    await component.toggleScreenShare();
    room.localParticipant.setScreenShareEnabled = async () => { throw new Error('stop failed'); };
    await component.toggleScreenShare();
    assert.equal(component.screenSharing, true);
});

test('screen sharing support is a passive feature check and unsupported actions are inert', async () => {
    const component = await fixture({ navigator: { mediaDevices: {} } });
    component.room = new FakeRoom();
    component.connected = true;
    let starts = 0;
    component.room.localParticipant.setScreenShareEnabled = async () => { starts += 1; };
    assert.equal(component.screenShareSupported, false);
    await component.toggleScreenShare();
    assert.equal(starts, 0);
});

test('reconnecting keeps the room retryable and only terminal provider reasons produce ended UI', async () => {
    for (const reason of [DisconnectReason.CLIENT_INITIATED, DisconnectReason.PARTICIPANT_REMOVED,
        DisconnectReason.ROOM_DELETED, DisconnectReason.ROOM_CLOSED, DisconnectReason.DUPLICATE_IDENTITY, DisconnectReason.UNKNOWN_REASON]) {
        const component = await fixture();
        const room = new FakeRoom();
        component.room = room;
        component.connected = true;
        component.bindRoomEvents();
        room.emit(RoomEvent.ConnectionStateChanged, ConnectionState.Reconnecting);
        assert.equal(component.connected, false);
        assert.equal(component.terminal, false);
        room.emit(RoomEvent.ConnectionStateChanged, ConnectionState.Connected);
        assert.equal(component.canControlMedia, true);
        room.emit(RoomEvent.Disconnected, reason);
        assert.equal(component.room, null);
        const ended = reason !== DisconnectReason.UNKNOWN_REASON;
        assert.equal(component.terminal, ended);
        assert.equal(component.failed, !ended);
        assert.equal(component.statusLabel, ended ? 'ended' : 'failed');
    }
});

test('mobile panel opening focuses close and closing returns focus to its live trigger', async () => {
    const component = await fixture();
    let closeFocus = 0;
    let triggerFocus = 0;
    component.$refs.panelClose = { focus() { closeFocus += 1; } };
    const trigger = { isConnected: true, focus() { triggerFocus += 1; } };
    component.togglePanel('chat', trigger);
    assert.equal(component.panelTab, 'chat');
    assert.equal(closeFocus, 1);
    component.closePanel();
    assert.equal(component.panelOpen, false);
    assert.equal(triggerFocus, 1);
    component.closePanel();
    assert.equal(triggerFocus, 1);
});

test('recording acknowledgement waits for delayed teardown before reconnecting', async () => {
    const component = await fixture();
    component.room = new FakeRoom();
    component.recordingPolicyVersion = 'policy';
    component.recordingRequiresAcknowledgement = true;
    const teardown = deferred();
    component.room.disconnect = () => teardown.promise;
    const leaving = component.disconnect();
    const originalFetch = globalThis.fetch;
    let requests = 0;
    let connects = 0;
    component.connect = async () => { connects += 1; };
    try {
        globalThis.fetch = async () => { requests += 1; return { ok: true }; };
        const acknowledgement = component.acknowledgeRecording();
        while (!requests) await Promise.resolve();
        await Promise.resolve();
        assert.equal(component.leaving, true);
        assert.equal(connects, 0);
        teardown.resolve();
        await leaving;
        await acknowledgement;
        assert.equal(connects, 1);
        assert.equal(component.recordingRequiresAcknowledgement, false);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('live local speaker and viewer permission changes update controls without opening devices', async () => {
    const component = await fixture();
    component.room = new FakeRoom();
    component.connected = true;
    component.participantMayPublish = false;
    component.canPublish = false;
    component.bindRoomEvents();
    let enables = 0;
    component.enableDevices = async () => { enables += 1; };
    const local = component.room.localParticipant;
    local.permissions = { canPublish: true };
    await component.room.emit(RoomEvent.ParticipantPermissionsChanged, {}, local);
    assert.equal(component.participantMayPublish, true);
    assert.equal(component.canControlMedia, true);
    local.permissions = { canPublish: false };
    await component.room.emit(RoomEvent.ParticipantPermissionsChanged, {}, local);
    assert.equal(component.participantMayPublish, false);
    assert.equal(component.canControlMedia, false);
    await component.room.emit(RoomEvent.ParticipantPermissionsChanged, {}, { permissions: { canPublish: true } });
    assert.equal(component.canControlMedia, false);
    assert.equal(enables, 0);
});

test('SFU permission changes preserve the recording gate and active role upgrade requires a fresh snapshot', async () => {
    const component = await fixture();
    const room = new FakeRoom();
    component.room = room;
    component.connected = true;
    component.recordingEnabled = true;
    component.recordingStatus = 'requested';
    component.recordingCanPublish = false;
    component.canPublish = false;
    component.bindRoomEvents();
    const local = room.localParticipant;
    const originalFetch = globalThis.fetch;
    let requests = 0;
    let enables = 0;
    component.enableDevices = async () => { enables += 1; };
    try {
        globalThis.fetch = async () => {
            requests += 1;
            return { ok: true, async json() { return { role: 'speaker', recording: { enabled: true, status: 'active', canPublish: true } }; } };
        };
        local.permissions = { canPublish: false };
        await room.emit(RoomEvent.ParticipantPermissionsChanged, {}, local);
        assert.equal(component.participantMayPublish, true, 'temporary recording lock does not demote the speaker');
        local.permissions = { canPublish: true };
        await room.emit(RoomEvent.ParticipantPermissionsChanged, {}, local);
        assert.equal(component.canPublish, false);
        assert.equal(requests, 0, 'preparing recording keeps its existing watcher');
        component.applyRecordingSession({ enabled: true, status: 'active', canPublish: false });
        await room.emit(RoomEvent.ParticipantPermissionsChanged, {}, local);
        assert.equal(requests, 1);
        assert.equal(component.canControlMedia, true);
        local.permissions = { canPublish: false };
        await room.emit(RoomEvent.ParticipantPermissionsChanged, {}, local);
        assert.equal(component.canControlMedia, false, 'current SFU deny wins over a positive server snapshot');
        assert.equal(enables, 0);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('call viewport follows visible keyboard height and offset, with coalescing and complete cleanup', async () => {
    const windowEvents = new Map();
    const viewportEvents = new Map();
    const queryEvents = new Map();
    const frames = new Map();
    let nextFrame = 1;
    let geometryUpdates = 0;
    const viewport = {
        height: 844, offsetTop: 0,
        addEventListener(event, callback) { viewportEvents.set(event, callback); },
        removeEventListener(event) { viewportEvents.delete(event); },
    };
    const query = {
        matches: true,
        addEventListener(event, callback) { queryEvents.set(event, callback); },
        removeEventListener(event) { queryEvents.delete(event); },
    };
    const windowLike = {
        innerWidth: 390, innerHeight: 844, visualViewport: viewport,
        addEventListener(event, callback) { windowEvents.set(event, callback); },
        removeEventListener(event) { windowEvents.delete(event); },
        matchMedia() { return query; },
        setTimeout, clearTimeout,
    };
    const factory = await loadCallRoomFactory({
        Room: FakeRoom, window: windowLike, navigator: { mediaDevices: {} },
        requestAnimationFrame(callback) { const id = nextFrame++; frames.set(id, callback); return id; },
        cancelAnimationFrame(id) { frames.delete(id); },
    });
    const component = factory({ canPublish: false, labels: { connecting: 'connecting', ended: 'ended' } });
    component.$nextTick = callback => callback();
    component.queueSelfPreviewGeometry = () => { geometryUpdates += 1; };
    component.watchViewportShape();
    assert.equal(component.callViewportHeight, 844);
    assert.equal(component.selfPreviewPortrait, true);
    viewport.height = 504;
    viewport.offsetTop = 120;
    viewportEvents.get('resize')();
    viewportEvents.get('scroll')();
    assert.equal(frames.size, 1);
    const apply = frames.values().next().value;
    frames.clear();
    apply();
    assert.match(component.callViewportStyle, /--rt-call-visual-height: 504px/);
    assert.match(component.callViewportStyle, /--rt-call-visual-top: 120px/);
    assert.equal(geometryUpdates, 1);
    viewport.height = Number.NaN;
    viewport.offsetTop = Number.POSITIVE_INFINITY;
    windowLike.innerHeight = 612;
    windowEvents.get('orientationchange')();
    const fallback = frames.values().next().value;
    frames.clear();
    fallback();
    assert.equal(component.callViewportHeight, 612);
    assert.equal(component.callViewportTop, 0);
    viewportEvents.get('resize')();
    const queued = frames.values().next().value;
    component.destroy();
    assert.equal(frames.size, 0);
    assert.equal(windowEvents.size, 0);
    assert.equal(viewportEvents.size, 0);
    assert.equal(queryEvents.size, 0);
    viewport.height = 200;
    queued();
    assert.equal(component.callViewportHeight, 612);
});

test('call viewport degrades to innerHeight or CSS dvh without VisualViewport or matchMedia', async () => {
    const events = new Map();
    const frames = [];
    const windowLike = {
        innerWidth: 320, innerHeight: Number.NaN,
        addEventListener(event, callback) { events.set(event, callback); },
        removeEventListener(event) { events.delete(event); },
        setTimeout, clearTimeout,
    };
    const factory = await loadCallRoomFactory({
        Room: FakeRoom, window: windowLike, navigator: {},
        requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
        cancelAnimationFrame() {},
    });
    const component = factory({ canPublish: false, labels: { connecting: 'connecting', ended: 'ended' } });
    component.queueSelfPreviewGeometry = () => {};
    component.watchViewportShape();
    assert.match(component.callViewportStyle, /--rt-call-visual-height: 100dvh/);
    assert.match(component.callViewportStyle, /--rt-call-visual-top: 0px/);
    windowLike.innerHeight = 430;
    events.get('resize')();
    frames.shift()();
    assert.equal(component.callViewportHeight, 430);
    component.destroy();
    assert.equal(events.size, 0);
});
