import test from 'node:test';
import assert from 'node:assert/strict';
import { createShareQualityController } from '../../resources/js/call-share-quality.js';

function screenShare(sid = 'SH_1', frameRate = 12, negotiated = 12) {
    const track = {
        constraints: { width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate },
        calls: [], kind: 'video', readyState: 'live', enabled: true, contentHint: 'text',
        getConstraints() { return structuredClone(this.constraints); },
        getSettings() { return { frameRate: this.constraints.frameRate?.max ?? negotiated }; },
        getCapabilities() { return { frameRate: { max: 30 } }; },
        async applyConstraints(next) {
            this.calls.push(structuredClone(next));
            this.constraints = structuredClone(next);
        },
    };
    const publication = { trackSid: sid, isMuted: false, track: { mediaStreamTrack: track } };
    const participant = { publication, getTrackPublication(source) {
        assert.equal(source, 'screen_share');
        return this.publication;
    } };
    return { room: { localParticipant: participant }, participant, track, publication, sid };
}

test('reduces the current screen to 8 fps and restores its own field, preserving user detail settings', async () => {
    const c = createShareQualityController();
    const { room, sid, track } = screenShare();
    assert.equal(c.currentFrameRate(room), 12);
    assert.equal(await c.apply(room, sid, true), true);
    assert.equal(c.currentFrameRate(room), 8);
    assert.equal(c.isLimited(room), true);
    assert.equal(track.contentHint, 'text');
    assert.deepEqual(track.constraints.height, { ideal: 1080 });
    track.constraints.width = { ideal: 1440 };
    assert.equal(await c.apply(room, sid, false), true);
    assert.deepEqual(track.constraints, { width: { ideal: 1440 }, height: { ideal: 1080 }, frameRate: 12 });
    assert.equal(c.currentFrameRate(room), 12);
    assert.equal(c.isLimited(room), false);
});

test('honors lower original constraints, device limits, and exact or mandatory capture preferences', async () => {
    for (const [constraint, rate] of [[5, 5], [{ max: 6 }, 6], [12, 7], [{ exact: 10 }, 10], [{ min: 9 }, 10]]) {
        const c = createShareQualityController();
        const { room, sid, track } = screenShare('SH', constraint, rate);
        assert.equal(await c.apply(room, sid, true), false);
        assert.equal(await c.apply(room, sid, false), false);
        assert.equal(track.calls.length, 0);
    }
    const c = createShareQualityController();
    const { room, sid, track } = screenShare('SH', { min: 4, ideal: 10, max: 10 }, 10);
    assert.equal(await c.apply(room, sid, true), true);
    assert.deepEqual(track.constraints.frameRate, { min: 4, max: 8 });
    await c.reset(room);
    assert.equal(c.currentFrameRate(room), 10);
    assert.deepEqual(track.constraints.frameRate, { min: 4, ideal: 10, max: 10 });
});

test('never restores over an independent user fps choice or applies an old decision to a new SID', async () => {
    const c = createShareQualityController();
    const { room, sid, track, participant } = screenShare();
    await c.apply(room, sid, true);
    track.constraints.frameRate = { max: 5 };
    assert.equal(c.isLimited(room), false);
    assert.equal(await c.reset(room), false);
    assert.deepEqual(track.constraints.frameRate, { max: 5 });
    const newer = screenShare('SH_NEW');
    participant.publication = newer.publication;
    assert.equal(await c.apply(room, sid, true), false);
    assert.equal(newer.track.calls.length, 0);
});

test('reset cancels queued reduction and safely restores an already running constraint write', async () => {
    const c = createShareQualityController();
    const { room, sid, track } = screenShare();
    const skipped = c.apply(room, sid, true);
    const reset = c.reset(room);
    assert.equal(await skipped, false);
    assert.equal(await reset, false);
    assert.equal(track.calls.length, 0);
    let unblock, started;
    const barrier = new Promise(resolve => { unblock = resolve; });
    const writing = new Promise(resolve => { started = resolve; });
    const apply = track.applyConstraints.bind(track);
    track.applyConstraints = async next => {
        started();
        await barrier;
        return apply(next);
    };
    const attempt = c.apply(room, sid, true);
    await writing;
    const rollback = c.reset(room);
    unblock();
    assert.equal(await attempt, false);
    assert.equal(await rollback, true);
    assert.equal(track.constraints.frameRate, 12);
});

test('a stopped share and a replacement with the same SID are never resumed or modified by a stale action', async () => {
    const c = createShareQualityController();
    const { room, sid, track, participant } = screenShare();
    let resume;
    const gate = new Promise(resolve => { resume = resolve; });
    const apply = track.applyConstraints.bind(track);
    track.applyConstraints = async next => { await gate; await apply(next); };
    const attempt = c.apply(room, sid, true);
    await Promise.resolve();
    track.readyState = 'ended';
    const newer = screenShare(sid);
    participant.publication = newer.publication;
    resume();
    assert.equal(await attempt, false);
    assert.equal(c.isLimited(room), false);
    assert.equal(await c.reset(room), false);
    assert.equal(newer.track.calls.length, 0);
    participant.publication = null;
    assert.equal(c.currentFrameRate(room), null);
    assert.equal(await c.apply(room, sid, false), false);
});

test('unsupported or failing display constraints leave the active screen untouched', async () => {
    const c = createShareQualityController();
    const { room, sid, track } = screenShare();
    track.applyConstraints = () => { throw new Error('OverconstrainedError'); };
    assert.equal(await c.apply(room, sid, true), false);
    assert.equal(c.isLimited(room), false);
    delete track.applyConstraints;
    assert.equal(await c.apply(room, sid, true), false);
    assert.equal(track.readyState, 'live');
});
