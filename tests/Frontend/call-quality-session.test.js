import test from 'node:test';
import assert from 'node:assert/strict';
import { callQualitySession } from '../../resources/js/call-quality-session.js';

function fixture(upload, download = 'off') {
    let time = 0;
    let deliver;
    const calls = { starts: 0, stops: 0, profiles: [] };
    const publication = { trackSid: 'camera-1', track: {}, isMuted: false };
    const room = {
        localParticipant: { getTrackPublication: () => publication },
        remoteParticipants: new Map(),
    };
    const component = {
        ...callQualitySession(x => x, { upload, download }, (_room, publish) => {
            deliver = publish;
            calls.starts++;
            return () => { calls.stops++; };
        }, () => time),
        room, connected: true, cameraOn: true, canControlMedia: true,
        cameraProfile: 'auto', effectiveCameraProfile: 'auto', panelOpen: false,
        isActiveRoom(active) { return this.room === active; },
        async applyCameraProfile(profile) {
            calls.profiles.push(profile);
            this.effectiveCameraProfile = profile;
            return true;
        },
    };
    component.startQualitySession();

    async function next(overrides = {}) {
        time += 3000;
        await deliver([{
            local: true, source: 'camera', kind: 'video', sid: 'camera-1', observationKey: 'stream',
            timestamp: time, intervalMs: 3000, kbps: 400, fps: 10, cpuFraction: .8, bandwidthFraction: 0,
            ...overrides,
        }]);
    }
    return { component, calls, next, publication };
}

test('automatic camera reduction and recovery run with panel closed and preserve the selected profile', async () => {
    const { component, calls, next } = fixture('active');
    for (let n = 0; n < 6; n++) await next();
    assert.deepEqual(calls.profiles, ['saver']);
    assert.equal(component.cameraProfile, 'auto');
    assert.equal(component.qualityDecisions[0].reason, 'cpu');
    assert.equal(component.qualityDecisions[0].observe, false);
    assert.deepEqual(component.qualityRows, []);
    for (let n = 0; n < 17; n++) await next({ fps: 15, cpuFraction: 0 });
    assert.deepEqual(calls.profiles, ['saver', 'auto']);
    component.endQualitySession();
    await next();
    assert.deepEqual(component.qualityDecisions, []);
    assert.equal(calls.stops, 1);
});

test('observe records a proposed decision while leaving the published camera alone', async () => {
    const { component, calls, next } = fixture('observe');
    for (let n = 0; n < 5; n++) await next();
    assert.equal(component.qualityDecisions[0].observe, true);
    assert.equal(component.effectiveCameraProfile, 'auto');
    assert.deepEqual(calls.profiles, []);
});

test('diagnostic-only sampling stops when panel closes and rate average restarts for a new stream', async () => {
    const { component, calls, next } = fixture('off');
    assert.equal(calls.starts, 0);
    component.showQualityMeasurements(true);
    await next({ kbps: 200 });
    await next({ kbps: 500, intervalMs: 1500 });
    assert.equal(component.qualityRows[0].meanKbps, 300);
    await next({ observationKey: 'replacement' });
    assert.equal(component.qualityRows[0].meanKbps, null);
    component.showQualityMeasurements(false);
    assert.equal(calls.stops, 1);
    await next();
    assert.deepEqual(component.qualityRows, []);
    assert.deepEqual(component.qualityDecisions, []);
});

test('a replaced publication, a muted track and a receive-only rollout cannot change outgoing video', async () => {
    for (const mode of ['changed', 'muted', 'receive-only']) {
        const { component, calls, next, publication } = fixture(mode === 'receive-only' ? 'off' : 'active', 'observe');
        if (mode === 'changed') publication.trackSid = 'new-camera';
        if (mode === 'muted') publication.isMuted = true;
        for (let n = 0; n < 6; n++) await next();
        assert.deepEqual(calls.profiles, [], mode);
        assert.deepEqual(component.qualityDecisions, [], mode);
    }
});
