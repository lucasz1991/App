import test from 'node:test';
import assert from 'node:assert/strict';
import { Track, VideoQuality } from 'livekit-client';
import { createReceiveQualityController } from '../../resources/js/call-receive-quality.js';

const { HIGH, MEDIUM, LOW } = VideoQuality;

function remoteVideo(sid, { source = Track.Source.Camera, width = 640, height = 360 } = {}) {
    const classes = new Set();
    const tile = { classList: { contains: cls => classes.has(cls) } };
    const doc = { defaultView: { innerWidth: 1440, innerHeight: 900 } };
    const element = {
        ownerDocument: doc, isConnected: true, tagName: 'VIDEO', videoWidth: width, videoHeight: height,
        closest: () => tile,
        getBoundingClientRect: () => ({ left: 0, top: 0, right: width, bottom: height }),
    };
    const publication = {
        kind: Track.Kind.Video, source, trackSid: sid, videoQuality: HIGH, isSubscribed: true, isDesired: true,
        track: { attachedElements: [element] }, changes: [],
        setVideoQuality(q) { this.changes.push(q); this.videoQuality = q; },
    };
    return { publication, element, classes };
}

function roomFor(...tracks) {
    const remoteParticipants = new Map();
    for (const { publication } of tracks) {
        remoteParticipants.set(publication.trackSid, { trackPublications: new Map([[publication.trackSid, publication]]) });
    }
    return { remoteParticipants };
}

test('only the affected subscriber limits its exact thumbnail while a text presentation remains uncapped', () => {
    const share = remoteVideo('plan', { source: Track.Source.ScreenShare });
    const camera = remoteVideo('camera');
    const other = remoteVideo('other');
    const unaffected = remoteVideo('camera');
    const room = roomFor(share, camera, other);
    const controller = createReceiveQualityController();
    const otherController = createReceiveQualityController();
    const otherRoom = roomFor(remoteVideo('plan', { source: Track.Source.ScreenShare }), unaffected);
    assert.deepEqual([...controller.sync(room).visibleSids].sort(), ['camera', 'other', 'plan']);
    assert.equal(controller.reduce(room, 'plan'), false);
    assert.equal(controller.reduce(room, 'camera'), true);
    assert.deepEqual(camera.publication.changes, [LOW]);
    assert.deepEqual([...controller.sync(room).cappedSids], ['camera']);
    assert.equal(controller.reduce(room, 'camera'), false);
    assert.deepEqual([...otherController.sync(otherRoom).cappedSids], []);
    assert.deepEqual(share.publication.changes, []);
    assert.deepEqual(other.publication.changes, []);
    assert.deepEqual(unaffected.publication.changes, []);
    assert.equal(controller.recover(room, 'camera'), true);
    assert.deepEqual(camera.publication.changes, [LOW, HIGH]);
});

test('focus, active speaker and visible layout govern ownership, also after resize', () => {
    const main = remoteVideo('large', { width: 1280, height: 720 });
    const side = remoteVideo('side', { width: 960, height: 540 });
    const room = roomFor(main, side);
    const controller = createReceiveQualityController();
    assert.deepEqual([...controller.sync(room).eligibleSids], ['side']);
    assert.equal(controller.reduce(room, 'side'), true);
    assert.deepEqual(side.publication.changes, [MEDIUM]);
    side.classes.add('rt-call-tile--speaking');
    assert.deepEqual([...controller.sync(room).eligibleSids], ['large']);
    assert.deepEqual(side.publication.changes, [MEDIUM, HIGH]);
    side.classes.clear();
    main.element.getBoundingClientRect = () => ({ left: 0, top: 0, right: 300, bottom: 150 });
    assert.deepEqual([...controller.sync(room).eligibleSids], ['large']);
    main.element.ownerDocument.pictureInPictureElement = main.element;
    assert.equal(controller.reduce(room, 'large'), false);
    main.element.isConnected = false;
    assert.deepEqual([...controller.sync(room).visibleSids], ['side']);
    assert.deepEqual([...controller.sync(room).eligibleSids], []);
});

test('hidden, detached, muted, undesired and already-low cameras are never raised as a side effect', () => {
    const fixtures = ['main','hidden','detached','muted','unsubscribed','low'].map(sid => remoteVideo(sid));
    const [main, hidden, detached, muted, unsubscribed, low] = fixtures;
    main.publication.source = Track.Source.ScreenShare;
    hidden.element.getBoundingClientRect = () => ({ left: 2000, top: 0, right: 2100, bottom: 100 });
    detached.publication.track.attachedElements = [];
    muted.publication.isMuted = true;
    unsubscribed.publication.isDesired = false;
    low.publication.videoQuality = LOW;
    const room = roomFor(...fixtures);
    const controller = createReceiveQualityController();
    assert.deepEqual([...controller.sync(room).visibleSids].sort(), ['low', 'main']);
    assert.deepEqual([...controller.sync(room).eligibleSids], []);
    controller.reset(room);
    assert.ok(fixtures.every(({ publication }) => publication.changes.length === 0));
});

test('only controller-owned caps restore; deferred unsubscribe resets wait for a desired subscription', () => {
    const camera = remoteVideo('side');
    const room = roomFor(remoteVideo('plan', { source: Track.Source.ScreenShare }), camera);
    const controller = createReceiveQualityController();
    const pub = camera.publication;
    pub.videoQuality = MEDIUM;
    assert.equal(controller.reduce(room, 'side'), true);
    pub.isDesired = false;
    controller.reset(room);
    assert.deepEqual(pub.changes, [LOW]);
    assert.deepEqual([...controller.sync(room).cappedSids], []);
    pub.isDesired = true;
    controller.sync(room);
    assert.deepEqual(pub.changes, [LOW, MEDIUM]);
    assert.equal(controller.reduce(room, 'side'), true);
    pub.videoQuality = HIGH;
    assert.deepEqual([...controller.sync(room).cappedSids], []);
    controller.reset(room);
    assert.deepEqual(pub.changes, [LOW, MEDIUM, LOW]);
});

test('a replacement track or publication never inherits stale pilot ownership', () => {
    const camera = remoteVideo('side');
    const room = roomFor(remoteVideo('plan', { source: Track.Source.ScreenShare }), camera);
    const controller = createReceiveQualityController();
    assert.equal(controller.reduce(room, 'side'), true);
    camera.publication.track = { attachedElements: [camera.element] };
    assert.deepEqual([...controller.sync(room).cappedSids], []);
    assert.deepEqual(camera.publication.changes, [LOW, HIGH]);
    controller.reduce(room, 'side');
    const replacement = remoteVideo('side');
    room.remoteParticipants.get('side').trackPublications.set('side', replacement.publication);
    assert.deepEqual([...controller.sync(room).cappedSids], []);
    assert.equal(controller.recover(room, 'side'), false);
    assert.deepEqual(replacement.publication.changes, []);
    controller.reduce(room, 'side');
    controller.sync(roomFor(remoteVideo('new')));
    assert.deepEqual(replacement.publication.changes, [LOW, HIGH]);
});

