import test from 'node:test';
import assert from 'node:assert/strict';
import { createCallQualityPolicy } from '../../resources/js/call-quality-policy.js';

const camera = { sid: 'localCamera', kind: 'video', source: 'camera', local: true,
    fps: 25, kbps: 900, cpuFraction: 0, bandwidthFraction: 0 };
const peer = { sid: 'peerCamera', kind: 'video', source: 'camera', local: false,
    fps: 24, kbps: 640, dropRatio: 0, loss: 0, decodeMs: 1, frozen: 0 };
const congested = { fps: 12, bandwidthFraction: .65 };
const stalled = { dropRatio: .12, frozen: .4, loss: 7 };

function scenario() {
    const policy = createCallQualityPolicy();
    let now = 0;
    return {
        policy,
        step(rows, options = {}, gap = 3000) {
            now += gap;
            const samples = rows.map(row => ({ observationKey: row.sid, intervalMs: gap, timestamp: now, ...row }));
            return policy.evaluate(samples, {
                now, receiveEligibleSids: new Set(['peerCamera']), receiveCapSids: new Set(), ...options,
            });
        },
        run(count, rows, options = {}) {
            return Array.from({ length: count }, () => this.step(rows, options)).flat();
        },
    };
}

test('sender downgrade requires two warm-up samples and three consecutive limited low-FPS intervals', () => {
    const rig = scenario();
    const heavy = [{ ...camera, cpuFraction: .6, fps: 15 }];
    assert.deepEqual(rig.run(4, heavy), []);
    assert.deepEqual(rig.step(heavy), [{
        direction: 'upload', source: 'camera', sid: 'localCamera', limited: true, reason: 'cpu', pressureSid: 'localCamera',
    }]);
    assert.deepEqual(rig.run(10, heavy), []); // declined actuator is not retried every poll
});

test('camera recovers after 45 seconds and ten clean samples; manual saver wins', () => {
    const rig = scenario();
    const heavy = [{ ...camera, ...congested }];
    assert.equal(rig.run(5, heavy)[0].reason, 'bandwidth');
    const capped = { cameraProfile: 'saver' };
    const healthy = [{ ...camera, fps: 15 }];
    assert.deepEqual(rig.run(14, healthy, capped), []);
    assert.equal(rig.step(healthy, capped)[0].limited, false);
    const manual = scenario();
    assert.deepEqual(manual.run(22, healthy, { cameraProfile: 'saver', cameraAuto: false }), []);
});

test('changing SSRC, missing intervals, pauses and new sessions interrupt consecutive evidence', () => {
    const rig = scenario();
    const heavy = [{ ...camera, ...congested }];
    assert.deepEqual(rig.run(4, heavy), []);
    const newLayer = [{ ...heavy[0], observationKey: 'second-layer' }];
    assert.deepEqual(rig.run(4, newLayer), []);
    assert.deepEqual(rig.step([{ ...newLayer[0], intervalMs: null }]), []);
    assert.deepEqual(rig.run(3, newLayer), []);
    assert.deepEqual(rig.step(newLayer, {}, 9000), []);
    assert.deepEqual(rig.run(3, newLayer), []);
    rig.policy.reset();
    assert.deepEqual(rig.run(4, newLayer), []);
    assert.equal(rig.step(newLayer)[0].limited, true);
});

test('a missing track or unknown pressure signal breaks the streak', () => {
    const rig = scenario();
    const heavy = [{ ...camera, ...congested }];
    assert.deepEqual(rig.run(4, heavy), []);
    assert.deepEqual(rig.step([]), []);
    assert.deepEqual(rig.run(4, heavy), []);
    assert.deepEqual(rig.step([{ ...heavy[0], cpuFraction: null, bandwidthFraction: null }]), []);
    assert.deepEqual(rig.run(2, heavy), []);
    assert.equal(rig.step(heavy)[0].sid, camera.sid);
});

test('text screen share FPS alone never triggers; original low-rate share cannot be promoted', () => {
    const rig = scenario();
    const share = { ...camera, sid: 'slides', source: 'screen_share', fps: 1 };
    assert.deepEqual(rig.run(8, [share]), []);
    assert.equal(rig.run(3, [{ ...share, bandwidthFraction: .7 }])[0].source, 'screen_share');
    assert.deepEqual(rig.run(16, [{ ...share, fps: 8 }], { shareFrameRate: 8, shareLimited: false }), []);
    const limited = { shareFrameRate: 8, shareLimited: true };
    assert.equal(rig.run(16, [{ ...share, fps: 8 }], limited)[0].limited, false);
});

test('a throttled receiver only reduces its competing camera; a healthy third party stays untouched', () => {
    const rigB = scenario();
    const rigC = scenario();
    const videoSid = 'focusedPresentation';
    const visible = new Set([videoSid, peer.sid]);
    const opts = { receiveVisibleSids: visible };
    const presentation = { ...peer, sid: videoSid, source: 'screen_share' };
    const rowsB = [camera, peer, { ...presentation, ...stalled }];
    const rowsC = [camera, peer, presentation];
    const decisions = rigB.run(5, rowsB, opts);
    assert.deepEqual(decisions, [{
        direction: 'download', source: 'camera', sid: peer.sid, limited: true, reason: 'network', pressureSid: videoSid,
    }]);
    assert.deepEqual(rigC.run(5, rowsC, opts), []);
    const capped = { ...opts, receiveCapSids: new Set([peer.sid]) };
    assert.deepEqual(rigB.run(17, rowsB, capped), []);
    assert.deepEqual(rigB.run(9, rowsC, capped), []);
    assert.equal(rigB.step(rowsC, capped)[0].limited, false);
});

test('one blocked gallery camera does not reduce healthy peers, hidden tracks, or the sender', () => {
    const rig = scenario();
    const healthy = { ...peer, sid: 'first-in-gallery' };
    const hidden = { ...peer, ...stalled, sid: 'hidden' };
    const candidates = new Set([healthy.sid, peer.sid]);
    const rows = [camera, healthy, { ...peer, ...stalled, loss: 0, decodeMs: 40 }, hidden];
    const [action] = rig.run(5, rows, { receiveEligibleSids: candidates });
    assert.equal(action.sid, peer.sid);
    assert.equal(action.reason, 'decoder');
    assert.deepEqual(scenario().run(6, [camera, healthy, hidden], { receiveEligibleSids: candidates }), []);
    assert.deepEqual(scenario().run(6, rows, { download: false, receiveEligibleSids: candidates }), []);
});

test('receiver recovery uses receiver evidence even when upload is still overloaded', () => {
    const rig = scenario();
    assert.equal(rig.run(5, [{ ...peer, ...stalled }])[0].limited, true);
    const capped = { receiveCapSids: new Set([peer.sid]), upload: false };
    const badAudio = { kind: 'audio', sid: 'mic', local: false, concealmentRatio: .1 };
    assert.deepEqual(rig.run(18, [peer, badAudio, { ...camera, ...congested }], capped), []);
    assert.deepEqual(rig.run(9, [peer, { ...camera, ...congested }], capped), []);
    assert.equal(rig.step([peer, { ...camera, ...congested }], capped)[0].direction, 'download');
});

test('a receive-only impairment and a send-only impairment can each opt into independent pilots', () => {
    const rows = [{ ...camera, ...congested }, { ...peer, ...stalled }];
    assert.deepEqual(scenario().run(5, rows, { upload: false }).map(action => action.direction), ['download']);
    assert.deepEqual(scenario().run(5, rows, { download: false }).map(action => action.direction), ['upload']);
    assert.deepEqual(scenario().run(5, rows, { upload: false, download: false }), []);
});

test('simultaneous gallery pressure changes one camera at a time and at most twice in a rolling minute', () => {
    const rig = scenario();
    const receiveEligibleSids = new Set(['one', 'two', 'three']);
    const receiveCapSids = new Set();
    const rows = [...receiveEligibleSids].map(sid => ({ ...peer, ...stalled, sid }));
    const changes = [];
    for (let sample = 1; sample <= 25; sample++) {
        const actions = rig.step(rows, { receiveEligibleSids, receiveCapSids });
        assert.ok(actions.length <= 1);
        for (const action of actions) {
            assert.equal(action.limited, true);
            changes.push([sample * 3000, action.sid]);
            receiveCapSids.add(action.sid);
        }
    }
    assert.deepEqual(changes, [[15000, 'one'], [24000, 'two'], [75000, 'three']]);
});
