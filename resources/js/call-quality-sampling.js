const SAMPLE_MS = 3000;
const MAX_INTERVAL_MS = 7000;

function delta(current, previous, field) {
    const after = current?.[field], before = previous?.[field];
    return Number.isFinite(after) && Number.isFinite(before) && after >= before ? after - before : null;
}

function per(amount, count, scale = 1) {
    return Number.isFinite(amount) && count > 0 ? scale * amount / count : null;
}

function milliseconds(seconds) {
    return Number.isFinite(seconds) ? seconds * 1000 : null;
}

function dimensions(width, height) {
    return width > 0 && height > 0 ? `${width} × ${height}` : null;
}

// Keep only numeric measurements and opaque IDs used to detect a changed RTP source.
// In particular, never retain ICE addresses, SDP, audio samples or a raw stats report.
function mediaCounters(report, incoming, kind) {
    const counters = new Map();
    for (const row of report.values()) {
        if (row.type !== (incoming ? 'inbound-rtp' : 'outbound-rtp') || row.kind !== kind || row.active === false) continue;
        if (/\/(rtx|ulpfec|flexfec)/i.test(report.get(row.codecId)?.mimeType || '')
            || (kind === 'video' && /\/red/i.test(report.get(row.codecId)?.mimeType || ''))) continue;
        const feedback = incoming ? row : report.get(row.remoteId);
        const transport = report.get(row.transportId);
        const pair = report.get(transport?.selectedCandidatePairId);
        const key = JSON.stringify([row.id, row.ssrc, row.codecId]);
        counters.set(key, {
            timestamp: row.timestamp, bytes: incoming ? row.bytesReceived : row.bytesSent,
            frames: incoming ? row.framesDecoded : row.framesEncoded,
            width: row.frameWidth, height: row.frameHeight, fps: row.framesPerSecond,
            received: feedback?.packetsReceived, lost: feedback?.packetsLost,
            feedbackTime: feedback?.timestamp, fractionLost: feedback?.fractionLost, jitter: feedback?.jitter,
            dropped: row.framesDropped, frozen: row.totalFreezesDuration, decode: row.totalDecodeTime,
            cpu: row.qualityLimitationDurations?.cpu, bandwidth: row.qualityLimitationDurations?.bandwidth,
            rtt: pair?.currentRoundTripTime ?? feedback?.roundTripTime,
            availableOutgoingBitrate: incoming ? undefined : pair?.availableOutgoingBitrate,
            concealed: row.concealedSamples, samples: row.totalSamplesReceived,
            bufferDelay: row.jitterBufferDelay, bufferCount: row.jitterBufferEmittedCount,
        });
    }
    return counters;
}

export const videoCounters = (report, incoming) => mediaCounters(report, incoming, 'video');

function measureLayer(row, previous) {
    const ms = delta(row, previous, 'timestamp');
    const reset = ['bytes', 'frames', 'dropped', 'frozen', 'decode', 'cpu', 'bandwidth', 'samples',
        'concealed', 'bufferCount', 'bufferDelay'].some(field => row[field] < previous?.[field]);
    const intervalMs = ms > 0 && ms <= MAX_INTERVAL_MS && !reset ? ms : null;
    const base = intervalMs ? previous : null;
    const bytes = delta(row, base, 'bytes');
    const frames = delta(row, base, 'frames');
    const dropped = delta(row, base, 'dropped');
    const received = delta(row, base, 'received');
    const lost = delta(row, base, 'lost');
    const feedbackMs = delta(row, base, 'feedbackTime');
    const freshFeedback = feedbackMs > 0 && feedbackMs <= MAX_INTERVAL_MS;
    let loss = null;
    if (freshFeedback && received !== null && lost !== null) {
        loss = per(lost, received + lost, 100);
    } else if (freshFeedback && row.received === undefined && Number.isFinite(row.fractionLost)) {
        loss = 100 * row.fractionLost;
    }
    const active = bytes > 0 || frames > 0 || dropped > 0 || lost > 0 || delta(row, base, 'frozen') > 0;
    const dropRatio = dropped !== null && frames !== null ? per(dropped, frames + dropped) : null;
    return {
        timestamp: row.timestamp, intervalMs: active ? intervalMs : null, active,
        width: row.width, height: row.height, fps: per(frames, intervalMs, 1000) ?? row.fps ?? null,
        kbps: per(bytes, intervalMs, 8), loss, jitter: milliseconds(row.jitter),
        lossDiscontinuous: Boolean(base && row.lost !== undefined && base.lost !== undefined
            && (lost === null || !freshFeedback)),
        frozen: delta(row, base, 'frozen'), dropRatio,
        cpuFraction: per(delta(row, base, 'cpu'), intervalMs, 1000),
        bandwidthFraction: per(delta(row, base, 'bandwidth'), intervalMs, 1000),
        decodeMs: per(delta(row, base, 'decode'), frames, 1000),
        rttMs: milliseconds(row.rtt), availableOutgoingKbps: per(row.availableOutgoingBitrate, 1000),
        concealmentRatio: per(delta(row, base, 'concealed'), delta(row, base, 'samples')),
        bufferMs: per(delta(row, base, 'bufferDelay'), delta(row, base, 'bufferCount'), 1000),
    };
}

function measureTrack(current, previous) {
    const layers = [...current].map(([key,row]) => ({ key, ...measureLayer(row, previous?.get(key)) }));
    const active = layers.filter(row => row.active);
    const sorted = (active.length ? active : layers).sort((a,b) => b.width*b.height - a.width*a.height);
    const selected = sorted[0] || {};
    const rates = layers.map(layer => layer.kbps).filter(Number.isFinite);
    return {
        ...selected, resolution: dimensions(selected.width, selected.height),
        kbps: rates.length ? rates.reduce((sum, value) => sum + value, 0) : null,
    };
}

// Existing consumers can request just the six panel measurements.
export function intervalQuality(current, previous) {
    const metrics = measureTrack(current, previous);
    const fields = ['resolution', 'fps', 'kbps', 'loss', 'jitter', 'frozen'];
    return Object.fromEntries(fields.map(key => [key, metrics[key] ?? null]));
}

export function classifyUpload(row, targetFps) {
    if (!row?.local || row.kind !== 'video' || !(row.intervalMs > 0)
        || !Number.isFinite(row.fps) || !(targetFps > 0)) return 'unknown';
    if (row.fps < targetFps * .8 && (row.cpuFraction >= .5 || row.bandwidthFraction >= .5)) return 'pressure';
    if (row.fps >= targetFps * .9 && Number.isFinite(row.cpuFraction) && row.cpuFraction < .1
        && Number.isFinite(row.bandwidthFraction) && row.bandwidthFraction < .1) return 'stable';
    return 'unknown';
}

export function classifyDownload(row) {
    if (!row || row.local || row.kind !== 'video' || !(row.intervalMs > 0)) return 'unknown';
    if (row.frozen > 0 && (row.dropRatio > .05 || row.loss >= 5)) return 'pressure';
    if (row.frozen === 0 && !row.lossDiscontinuous && Number.isFinite(row.dropRatio) && row.dropRatio < .01
        && !(row.loss >= 5)) return 'stable';
    return 'unknown';
}

export function observeMediaQuality(room, publish) {
    let previous = new Map();
    let sequence = 0;
    let cancelled = false;
    let timer = null;

    async function sample() {
        const next = new Map();
        const tracks = [];
        for (const participant of [room.localParticipant, ...room.remoteParticipants.values()]) {
            const local = participant === room.localParticipant;
            for (const publication of participant.trackPublications.values()) {
                const {track, kind} = publication;
                if (kind !== 'video' && (local || kind !== 'audio')) continue;
                const observable = () => track && publication.track === track && !publication.isMuted
                    && (local || (publication.isDesired !== false && publication.isEnabled !== false && track.streamState !== 'paused'));
                if (!observable()) continue;
                const sourceTrack = track.mediaStreamTrack;
                tracks.push((async () => {
                    const report = await track.getRTCStatsReport?.();
                    if (!report || cancelled || !observable() || sourceTrack !== track.mediaStreamTrack) return null;
                    const counters = mediaCounters(report, !local, kind);
                    if (!counters.size) return null;
                    const prior = previous.get(track);
                    const {key, ...metrics} = measureTrack(counters, sourceTrack === prior?.sourceTrack ? prior?.counters : null);
                    const same = prior && key === prior.key && metrics.intervalMs > 0;
                    const observationKey = same ? prior.observationKey : ++sequence;
                    // A layer replacement has its own history even when the track SID is unchanged.
                    if (!same) metrics.intervalMs = null;
                    next.set(track, {key, observationKey, counters, sourceTrack});
                    const capture = local ? track.getSourceTrackSettings?.() : null;
                    return {
                        sid: publication.trackSid, source: publication.source, kind, local, name: participant.name || participant.identity,
                        capture: dimensions(capture?.width, capture?.height), observationKey, ...metrics,
                    };
                })());
            }
        }
        const rows = await Promise.allSettled(tracks);
        if (cancelled) return;
        previous = next;
        try {
            publish(rows.filter(result => result.status === 'fulfilled' && result.value).map(result => result.value));
        } finally {
            if (!cancelled) timer = setTimeout(sample, SAMPLE_MS);
        }
    }
    sample();
    return () => { cancelled = true; clearTimeout(timer); previous.clear(); };
}
