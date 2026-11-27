import { classifyDownload, classifyUpload } from './call-quality-sampling.js';

const sampleTimeoutMs = 7_000;
const recoveryDelayMs = 45_000;
const noTracks = new Set();

function incomingReason(row) {
    if (row.loss >= 5) return 'network';
    if (Number.isFinite(row.decodeMs) && row.fps > 0 && row.decodeMs >= 800 / row.fps) return 'decoder';
    return 'receiver';
}

// One instance per call. Input rows carry LiveKit publication.trackSid as "sid".
// The caller supplies actual applied caps, never a desired/assumed actuator state.
export function createCallQualityPolicy() {
    const histories = new Map();
    const attempts = new Map();
    const changes = { upload: [], download: [] };
    let lastEvaluation = null;

    function reset() {
        histories.clear();
        attempts.clear();
        changes.upload = [];
        changes.download = [];
        lastEvaluation = null;
    }

    function record(row, context, states, audioImpaired) {
        const outgoing = row.local;
        const camera = row.source === 'camera';
        const direction = outgoing ? 'upload' : 'download';
        if (row.kind !== 'video' || !row.sid || (!camera && row.source !== 'screen_share')) return;
        if (outgoing && (!context.upload || (camera && !context.cameraAuto))) return;
        // A display source originally limited to 8 fps (or less) has no pilot limit to restore.
        if (outgoing && !camera && (!Number.isFinite(context.shareFrameRate) ||
            (context.shareFrameRate <= 8 && !context.shareLimited))) return;
        if (!outgoing && (!context.download || !context.receiveVisibleSids.has(row.sid))) return;

        const targetFps = camera ? (context.cameraProfile === 'saver' ? 15 : 25) : context.shareFrameRate;
        const limited = outgoing ? (camera ? targetFps === 15 : context.shareLimited) : context.receiveCapSids.has(row.sid);
        const key = direction + ':' + row.sid;
        const old = histories.get(key);
        const history = { row, key, direction, limited, firstSeen: old?.firstSeen ?? context.now,
            samples: 1, pressure: 0, stable: 0 };
        states.set(key, history);
        if (!old || !row.observationKey || old.row.observationKey !== row.observationKey ||
            old.limited !== limited || !Number.isFinite(row.timestamp) || !Number.isFinite(old.row.timestamp) ||
            row.timestamp <= old.row.timestamp || row.timestamp - old.row.timestamp > sampleTimeoutMs ||
            !(row.intervalMs > 0 && row.intervalMs <= sampleTimeoutMs)) return;

        history.samples = Math.min(3, old.samples + 1);
        if (history.samples < 3) return;
        const state = outgoing ? classifyUpload(row, targetFps) : classifyDownload(row);
        if (state === 'pressure') history.pressure = old.pressure + 1;
        if (state === 'stable' && (outgoing || !audioImpaired)) history.stable = old.stable + 1;
    }

    function propose(target, limited, reason, pressureSid, context) {
        const now = context.now;
        const direction = target.direction;
        const recent = changes[direction].filter(time => now - time < 60_000);
        changes[direction] = recent;
        // At most two changes per minute and time to observe each action before another.
        if (recent.length >= 2 || (recent.length && now - recent.at(-1) < 9_000)) return null;

        const previous = attempts.get(target.key);
        if (previous?.limited === limited && now - previous.time < recoveryDelayMs) return null;
        if (!limited && now - (previous?.time ?? target.firstSeen) < recoveryDelayMs) return null;
        attempts.set(target.key, { time: now, limited, pressureSid });
        recent.push(now);
        return { direction, source: target.row.source, sid: target.row.sid, limited, reason, pressureSid };
    }

    function decideUpload(active, context) {
        const senders = active.filter(entry => entry.direction === 'upload');
        // Prefer a camera reduction over slowing the text share. Recovery is lower priority.
        senders.sort((a, b) => Number(b.row.source === 'camera') - Number(a.row.source === 'camera'));
        for (const entry of senders) {
            if (!entry.limited && entry.pressure >= 3) {
                const reason = entry.row.cpuFraction >= .5 ? 'cpu' : 'bandwidth';
                const action = propose(entry, true, reason, entry.row.sid, context);
                if (action) return action;
            }
        }
        if (senders.some(entry => entry.pressure > 0)) return null;
        for (const entry of senders) {
            if (entry.limited && entry.stable >= 10) {
                const action = propose(entry, false, 'recovered', entry.row.sid, context);
                if (action) return action;
            }
        }
        return null;
    }

    function decideDownload(states, context) {
        const visible = [...states.values()].filter(entry => entry.direction === 'download');
        // Actuator ranks eligible gallery cameras; focused cameras and shares are never capped.
        const cameras = [...context.receiveEligibleSids]
            .map(sid => states.get('download:' + sid))
            .filter(entry => entry?.row.source === 'camera' && entry.samples >= 3);
        for (const trigger of visible) {
            if (trigger.pressure < 3) continue;
            const target = context.receiveEligibleSids.has(trigger.row.sid)
                ? cameras.find(camera => camera === trigger && !camera.limited)
                : cameras.find(camera => !camera.limited && (camera.row.fps > 0 || camera.row.kbps > 0));
            if (!target) continue;
            const action = propose(target, true, incomingReason(trigger.row), trigger.row.sid, context);
            if (action) return action;
        }
        if (visible.some(entry => entry.pressure > 0)) return null;
        for (const camera of cameras) {
            if (!camera.limited || camera.stable < 10) continue;
            const triggerSid = attempts.get(camera.key)?.pressureSid;
            if (triggerSid && context.receiveVisibleSids.has(triggerSid)) {
                const trigger = states.get('download:' + triggerSid);
                if (!trigger || trigger.stable < 10) continue;
            }
            const action = propose(camera, false, 'recovered', triggerSid ?? camera.row.sid, context);
            if (action) return action;
        }
        return null;
    }

    return {
        reset,
        evaluate(rows, options) {
            const context = { upload: true, download: true, cameraAuto: true, cameraProfile: 'auto',
                shareFrameRate: 12, shareLimited: false, receiveEligibleSids: noTracks, receiveCapSids: noTracks, ...options };
            context.receiveVisibleSids ??= context.receiveEligibleSids;
            if (!Number.isFinite(context.now)) return [];
            if (lastEvaluation !== null) {
                const elapsed = context.now - lastEvaluation;
                if (elapsed === 0) return [];
                if (elapsed < 0 || elapsed > sampleTimeoutMs) reset();
            }
            lastEvaluation = context.now;
            const audioImpaired = rows.some(row => !row.local && row.kind === 'audio' &&
                row.intervalMs > 0 && row.intervalMs <= sampleTimeoutMs && row.concealmentRatio > .02);
            const states = new Map();
            for (const row of rows) record(row, context, states, audioImpaired);
            histories.clear();
            for (const [key, state] of states) histories.set(key, state);
            for (const key of attempts.keys()) {
                const stillCapped = key.startsWith('download:') && context.receiveCapSids.has(key.slice('download:'.length));
                if (!states.has(key) && !stillCapped) attempts.delete(key);
            }
            return [decideUpload([...states.values()], context), decideDownload(states, context)].filter(Boolean);
        },
    };
}
