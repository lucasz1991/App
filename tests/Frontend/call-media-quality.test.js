import test from 'node:test';
import assert from 'node:assert/strict';
import { cameraCaptureOptions, cameraConstraints, callQualityControls, intervalQuality, videoCounters, shareCaptureOptions, sharePublishOptions } from '../../resources/js/call-media-quality.js';

test('camera and screen have independent capture goals and leave codec negotiation to the SDK', () => {
    assert.deepEqual(cameraCaptureOptions('auto').resolution, { width: 1280, height: 720, frameRate: 25 });
    assert.deepEqual(cameraCaptureOptions('saver').resolution, { width: 640, height: 360, frameRate: 15 });
    assert.equal(cameraConstraints('saver').frameRate.max, 15);
    assert.equal(shareCaptureOptions.contentHint, 'text');
    assert.deepEqual(shareCaptureOptions.resolution, { width: 1920, height: 1080, frameRate: 12 });
    assert.equal(sharePublishOptions.degradationPreference, 'maintain-resolution');
    assert.equal(sharePublishOptions.videoCodec, undefined);
});

test('receive metrics use counter deltas, omit unsupported readings, and exclude repair streams and IP addresses', () => {
    const report = (time, bytes, received, lost, freeze) => new Map([
        ['p', { id: 'p', type: 'inbound-rtp', kind: 'video', timestamp: time, bytesReceived: bytes,
            packetsReceived: received, packetsLost: lost, totalFreezesDuration: freeze, jitter: .012,
            frameWidth: 1280, frameHeight: 720, framesPerSecond: 24 }],
        ['r', { id: 'r', type: 'inbound-rtp', kind: 'video', codecId: 'repair', bytesReceived: 90000 }],
        ['repair', { id: 'repair', type: 'codec', mimeType: 'video/rtx' }],
        ['network', { type: 'local-candidate', address: '192.0.2.12' }],
    ]);
    const a = videoCounters(report(1000, 10000, 1000, 10, 1), true);
    const b = videoCounters(report(3000, 110000, 1097, 13, 1.5), true);
    assert.equal(b.size, 1);
    assert.deepEqual(intervalQuality(b, a), { resolution: '1280 × 720', fps: 24, kbps: 400, loss: 3, jitter: 12, frozen: .5 });
    assert.equal(intervalQuality(b, null).kbps, null);
    assert.equal(intervalQuality(a, b).kbps, null);
});

test('audio only stops outgoing capture and restores only video subscriptions it actually paused', async () => {
    const publication = (trackSid, kind, isDesired) => ({ trackSid, kind, isDesired,
        setSubscribed(value) { this.isDesired = value; } });
    const arrived = publication('new', 'video', true);
    const tracks = new Map([
        ['camera', publication('camera', 'video', true)], ['hidden', publication('hidden', 'video', false)],
        ['mic', publication('mic', 'audio', true)],
    ]);
    const actions = [];
    const room = { localParticipant: {
        setCameraEnabled: value => actions.push(['camera', value]),
        setScreenShareEnabled: value => actions.push(['screen', value]),
    }, remoteParticipants: new Map([['u', { trackPublications: tracks }]]) };
    const component = {
        ...callQualityControls(value => value, {}), room, cameraOn: true, screenSharing: true, connected: true,
        isActiveRoom: r => r === room, syncLocalMediaState() {},
    };
    await component.setAudioOnly(true);
    assert.deepEqual(actions, [['screen', false], ['camera', false]]);
    assert.deepEqual([...tracks.values()].map(p => p.isDesired), [false, false, true]);
    tracks.set('new', arrived);
    component.syncIncomingVideo();
    assert.equal(arrived.isDesired, false);
    await component.setAudioOnly(false);
    assert.deepEqual([...tracks.values()].map(p => p.isDesired), [true, false, true, true]);
    assert.equal(actions.length, 2, 'exiting audio only never publishes private media automatically');
});

test('adaptive camera limits obey the manual preference and never activate an off camera', async () => {
    const swaps = [];
    const publication = { track: { getProcessor: () => undefined }, isMuted: false };
    const room = { localParticipant: { getTrackPublication: () => publication } };
    const component = {
        ...callQualityControls(value => value, {}), room, canControlMedia: true, cameraOn: true,
        isActiveRoom: candidate => candidate === room, syncLocalMediaState() {},
        replaceCameraProfile(_room, _generation, _publication, profile) { swaps.push(profile); },
    };
    assert.equal(await component.applyCameraProfile('saver'), true);
    assert.equal(component.cameraProfile, 'auto');
    assert.equal(component.effectiveCameraProfile, 'saver');
    assert.equal(await component.changeCameraProfile('saver'), true);
    assert.equal(await component.applyCameraProfile('auto'), false);
    publication.track.getProcessor = () => ({});
    assert.equal(await component.changeCameraProfile('auto'), false);
    assert.equal(component.cameraProfile, 'saver');
    publication.track = null;
    component.cameraOn = false;
    assert.equal(await component.applyCameraProfile('auto'), false);
    assert.equal(await component.changeCameraProfile('auto'), true);
    assert.deepEqual(swaps, ['saver']);
});
