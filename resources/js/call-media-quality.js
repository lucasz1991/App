// Let the SDK negotiate codecs, simulcast layers and actual send bitrate.
export function cameraCaptureOptions(profile) {
    const resolution = profile === 'saver'
        ? { width: 640, height: 360, frameRate: 15 }
        : { width: 1280, height: 720, frameRate: 25 };
    return { resolution };
}

export function cameraConstraints(profile) {
    const { width, height, frameRate } = cameraCaptureOptions(profile).resolution;
    return { width: { ideal: width }, height: { ideal: height }, frameRate: { ideal: frameRate, max: frameRate } };
}

export const shareCaptureOptions = {
    resolution: { width: 1920, height: 1080, frameRate: 12 },
    contentHint: 'text',
};

export const sharePublishOptions = {
    screenShareEncoding: { maxBitrate: 2_500_000, maxFramerate: 12 },
    degradationPreference: 'maintain-resolution',
};

function difference(current, previous, field) {
    if (!Number.isFinite(current?.[field]) || !Number.isFinite(previous?.[field])) return null;
    const change = current[field] - previous[field];
    return change >= 0 ? change : null;
}

function dimensions(width, height) {
    return width > 0 && height > 0 ? `${width} × ${height}` : null;
}

// Only counters from a single sender/receiver are kept between two samples.
// RTC reports also contain network addresses; never put the raw report in UI state.
export function videoCounters(report, incoming) {
    const result = new Map();
    for (const row of report.values()) {
        if (row.type !== (incoming ? 'inbound-rtp' : 'outbound-rtp') || row.kind !== 'video') continue;
        if (/\/(rtx|red|ulpfec|flexfec)/i.test(report.get(row.codecId)?.mimeType || '')) continue;
        const feedback = incoming ? row : report.get(row.remoteId);
        result.set(row.id, {
            timestamp: row.timestamp, bytes: incoming ? row.bytesReceived : row.bytesSent,
            frames: incoming ? row.framesDecoded : row.framesEncoded, fps: row.framesPerSecond,
            width: row.frameWidth, height: row.frameHeight,
            received: feedback?.packetsReceived, lost: feedback?.packetsLost,
            fractionLost: feedback?.fractionLost, jitter: feedback?.jitter,
            frozen: row.totalFreezesDuration,
        });
    }
    return result;
}

export function intervalQuality(current, previous) {
    const layers = [...current].map(([id, row]) => {
        const prev = previous?.get(id);
        const ms = difference(row, prev, 'timestamp');
        const bytes = difference(row, prev, 'bytes');
        const frames = difference(row, prev, 'frames');
        const received = difference(row, prev, 'received');
        const lost = difference(row, prev, 'lost');
        return {
            width: row.width, height: row.height,
            kbps: ms && bytes !== null ? 8 * bytes / ms : null,
            fps: row.fps ?? (ms && frames !== null ? frames * 1000 / ms : null),
            loss: lost !== null && received !== null && received + lost > 0
                ? 100 * lost / (received + lost) : Number.isFinite(row.fractionLost) ? row.fractionLost * 100 : null,
            jitter: Number.isFinite(row.jitter) ? row.jitter * 1000 : null,
            frozen: difference(row, prev, 'frozen'),
        };
    });
    // The highest active simulcast layer describes resolution and frame rate.
    const active = layers.filter(layer => layer.kbps === null || layer.kbps > 0);
    const visible = (active.length ? active : layers).sort((a,b) => b.width*b.height - a.width*a.height)[0] || {};
    const rates = layers.map(layer => layer.kbps).filter(Number.isFinite);
    return {
        resolution: dimensions(visible.width, visible.height), fps: visible.fps ?? null,
        kbps: rates.length ? rates.reduce((sum, value) => sum + value, 0) : null,
        loss: visible.loss ?? null, jitter: visible.jitter ?? null, frozen: visible.frozen ?? null,
    };
}

export function observeMediaQuality(room, publish) {
    let previous = new Map();
    let cancelled = false;
    let timer = null;
    async function sample() {
        const next = new Map();
        const tracks = [];
        for (const participant of [room.localParticipant, ...room.remoteParticipants.values()]) {
            const local = participant === room.localParticipant;
            for (const publication of participant.trackPublications.values()) {
                if (publication.kind !== 'video' || publication.isMuted || !publication.track) continue;
                const track = publication.track;
                tracks.push((async () => {
                    const report = await track.getRTCStatsReport?.();
                    if (!report) return null;
                    const counters = videoCounters(report, !local);
                    next.set(track, counters);
                    const capture = local ? track.getSourceTrackSettings?.() : null;
                    return {
                        sid: publication.trackSid, source: publication.source, local, name: participant.name || participant.identity,
                        capture: dimensions(capture?.width, capture?.height),
                        ...intervalQuality(counters, previous.get(track)),
                    };
                })());
            }
        }
        const rows = await Promise.allSettled(tracks);
        if (cancelled) return;
        publish(rows.filter(r => r.status === 'fulfilled' && r.value).map(r => r.value));
        previous = next;
        timer = setTimeout(sample, 3000);
    }
    sample();
    return () => { cancelled = true; clearTimeout(timer); previous.clear(); };
}

// Keep the transport itself outside Alpine's proxy while updating the local view state.
export function callQualityControls(raw, labels) {
    return {
        cameraProfile: 'auto',
        audioOnly: false,
        profileBusy: false,
        connectionQuality: 'unknown',
        pausedVideoSids: new Set(),
        qualityRows: [],
        stopQualitySampling: null,

        async changeCameraProfile(profile) {
            const room = raw(this.room);
            if (!room || !this.connected || this.profileBusy || this.cameraBusy || this.audioOnly
                || !['auto', 'saver'].includes(profile) || profile === this.cameraProfile) return;
            this.profileBusy = true;
            try {
                const publication = room.localParticipant.getTrackPublication('camera');
                if (publication?.track && !publication.isMuted) {
                    if (this.cameraSelectionCommitted || publication.track.getProcessor?.()) return;
                    const deviceId = publication.track.getSourceTrackSettings?.().deviceId;
                    await publication.track.restartTrack({ ...cameraCaptureOptions(profile), ...(deviceId ? { deviceId } : {}) });
                } else if (publication?.track) {
                    // Unmute otherwise reuses the capture constraints of the old publication.
                    await room.localParticipant.unpublishTrack(publication.track, true);
                }
                if (this.isActiveRoom(room)) this.cameraProfile = profile;
            } catch {
                if (this.isActiveRoom(room)) this.toast(labels.profileFailed, 'warning');
            } finally {
                if (this.isActiveRoom(room)) this.profileBusy = false;
            }
        },

        protectedCameraOn() {
            return this.cameraOn && (this.cameraSelectionCommitted || Boolean(raw(this.room)?.localParticipant.getTrackPublication?.('camera')?.track?.getProcessor?.()));
        },

        syncIncomingVideo(room = raw(this.room)) {
            for (const participant of room?.remoteParticipants?.values() || []) {
                for (const pub of participant.trackPublications.values()) {
                    if (pub.kind !== 'video') continue;
                    if (this.audioOnly && pub.isDesired) {
                        this.pausedVideoSids.add(pub.trackSid);
                        pub.setSubscribed(false);
                    } else if (!this.audioOnly && this.pausedVideoSids.delete(pub.trackSid)) {
                        pub.setSubscribed(true);
                    }
                }
            }
            if (!this.audioOnly) this.pausedVideoSids.clear();
        },

        async setAudioOnly(enabled) {
            const room = raw(this.room);
            if (!room || !this.connected || this.profileBusy || this.cameraBusy || this.screenShareBusy || this.audioOnly === enabled) return;
            this.profileBusy = true;
            try {
                if (enabled && this.screenSharing) await room.localParticipant.setScreenShareEnabled(false);
                if (!this.isActiveRoom(room)) return;
                if (enabled && this.cameraOn) {
                    if (this.cameraSelectionCommitted) await this.stopConfiguredCamera(room);
                    else await room.localParticipant.setCameraEnabled(false);
                }
                if (!this.isActiveRoom(room)) return;
                this.audioOnly = enabled;
                this.syncIncomingVideo(room);
            } catch {
                if (this.isActiveRoom(room)) this.toast(labels.profileFailed, 'warning');
            } finally {
                if (this.isActiveRoom(room)) {
                    this.syncLocalMediaState(room);
                    this.profileBusy = false;
                }
            }
        },

        showQualityMeasurements(visible) {
            if (visible && this.room) {
                if (this.stopQualitySampling) return;
                this.stopQualitySampling = observeMediaQuality(raw(this.room), rows => { this.qualityRows = rows; });
            } else if (this.stopQualitySampling) {
                this.stopQualitySampling();
                this.stopQualitySampling = null;
                this.qualityRows = [];
            }
        },

        resetQualityState() {
            this.showQualityMeasurements(false);
            this.connectionQuality = 'unknown';
            this.audioOnly = false;
            this.profileBusy = false;
            this.pausedVideoSids.clear();
        },

        formatQuality(value, unit, digits = 0) {
            return Number.isFinite(value) ? value.toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: digits }) + ' ' + unit : '—';
        },
    };
}
