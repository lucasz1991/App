import { observeMediaQuality } from './call-quality-sampling.js';
import { createCallQualityPolicy } from './call-quality-policy.js';
import { createReceiveQualityController } from './call-receive-quality.js';
import { createShareQualityController } from './call-share-quality.js';

function rolloutMode(value) {
    return value === 'active' || value === 'observe' ? value : 'off';
}

// SDK instances, previous stats and subscription ownership belong to this one room instance.
export function callQualitySession(raw, settings = {}, sample = observeMediaQuality, now = () => performance.now()) {
    const uploadMode = rolloutMode(settings.upload);
    const downloadMode = rolloutMode(settings.download);
    const policy = createCallQualityPolicy();
    const receive = createReceiveQualityController();
    const share = createShareQualityController();
    let room = null;
    let stopSampling = null;
    let removeVisibilityListener = null;
    let generation = 0;
    let panelVisible = false;
    let applying = false;
    const recent = new Map();

    function stop(component) {
        generation++;
        stopSampling?.();
        stopSampling = null;
        applying = false;
        policy.reset();
        recent.clear();
        if (room) receive.reset(room);
        component.qualityRows = [];
    }

    function current(component, active, token) {
        return room === active && token === generation && component.isActiveRoom(active)
            && component.connected && !component.leaving && !component.disposed && !globalThis.document?.hidden;
    }

    async function onSample(component, active, token, rows) {
        if (!current(component, active, token)) return;
        // At most ten valid intervals per live publication. Reset on a new SSRC or a stale sample.
        const present = new Set();
        for (const row of rows) {
            present.add(row.sid);
            let history = recent.get(row.sid);
            if (!history || history.key !== row.observationKey || !(row.intervalMs > 0 && row.intervalMs <= 7000)
                || !Number.isFinite(row.kbps) || !(row.timestamp > history.timestamp && row.timestamp - history.timestamp <= 7000)) {
                history = { key: row.observationKey, rates: [] };
                recent.set(row.sid, history);
            }
            history.timestamp = row.timestamp;
            if (row.intervalMs > 0 && row.intervalMs <= 7000 && Number.isFinite(row.kbps)) {
                history.rates.push({ rate: row.kbps, duration: row.intervalMs });
                if (history.rates.length > 10) history.rates.shift();
            }
        }
        for (const sid of recent.keys()) if (!present.has(sid)) recent.delete(sid);
        if (panelVisible) {
            component.qualityRows = rows.map(row => {
                const rates = recent.get(row.sid).rates;
                const duration = rates.reduce((sum, entry) => sum + entry.duration, 0);
                const meanKbps = rates.length > 1 ? rates.reduce((sum, entry) => sum + entry.rate * entry.duration, 0) / duration : null;
                return { ...row, meanKbps, windowSeconds: Math.round(duration / 1000) };
            });
        }
        if (applying || component.audioOnly || (uploadMode === 'off' && downloadMode === 'off')) return;
        const down = downloadMode === 'off'
            ? { eligibleSids: new Set(), cappedSids: new Set(), visibleSids: new Set() } : receive.sync(active);
        const canSend = uploadMode !== 'off' && component.canControlMedia && !component.prejoinOpen
            && !component.cameraBusy && !component.screenShareBusy && !component.profileBusy;
        const actions = policy.evaluate(rows, {
            now: now(),
            upload: canSend,
            download: downloadMode !== 'off',
            cameraAuto: component.cameraOn && component.cameraProfile === 'auto',
            cameraProfile: component.effectiveCameraProfile,
            shareFrameRate: component.screenSharing ? share.currentFrameRate(active) : null,
            shareLimited: component.screenSharing && share.isLimited(active),
            receiveEligibleSids: down.eligibleSids, receiveCapSids: down.cappedSids, receiveVisibleSids: down.visibleSids,
        });
        if (!actions.length) return;
        applying = true;
        try {
            for (const action of actions) {
                if (!current(component, active, token) || component.audioOnly) break;
                const sending = action.direction === 'upload';
                const observe = (sending ? uploadMode : downloadMode) === 'observe';
                let changed = false;
                if (!observe) {
                    if (sending) {
                        if (!component.canControlMedia || component.prejoinOpen || component.profileBusy ||
                            component.cameraBusy || component.screenShareBusy) continue;
                        const pub = active.localParticipant.getTrackPublication(action.source);
                        if (!pub?.track || pub.isMuted || pub.trackSid !== action.sid) continue;
                        changed = action.source === 'screen_share'
                            ? await share.apply(active, action.sid, action.limited)
                            : await component.applyCameraProfile(action.limited ? 'saver' : 'auto');
                    } else {
                        changed = action.limited ? receive.reduce(active, action.sid) : receive.recover(active, action.sid);
                    }
                }
                if ((observe || changed) && current(component, active, token)) {
                    const { direction, limited, source, reason } = action;
                    const entry = { direction, limited, source, reason, observe, at: Date.now() };
                    component.qualityDecisions = [entry, ...component.qualityDecisions].slice(0, 6);
                }
            }
        } finally {
            if (generation === token) applying = false;
        }
    }

    function refresh(component) {
        const wanted = component.connected && !component.leaving && !component.disposed && !globalThis.document?.hidden
            && (panelVisible || (!component.audioOnly && (uploadMode !== 'off' || downloadMode !== 'off')));
        if (!room || !wanted) {
            if (stopSampling) stop(component);
            return;
        }
        if (stopSampling) return;
        const token = ++generation;
        const active = room;
        stopSampling = sample(active, rows => onSample(component, active, token, rows).catch(() => {
            // getStats and SDK controls can race with a participant leaving; start fresh next interval.
            if (token === generation) policy.reset();
        }));
    }

    return {
        qualityRows: [],
        qualityDecisions: [],
        startQualitySession() {
            const active = raw(this.room);
            if (!active) return;
            if (room !== active) {
                this.endQualitySession();
                room = active;
                const doc = globalThis.document;
                const listener = () => refresh(this);
                doc?.addEventListener?.('visibilitychange', listener);
                removeVisibilityListener = () => doc?.removeEventListener?.('visibilitychange', listener);
            }
            panelVisible = this.panelOpen && this.panelTab === 'quality';
            refresh(this);
        },

        showQualityMeasurements(visible) {
            panelVisible = visible;
            if (!panelVisible) this.qualityRows = [];
            refresh(this);
        },

        syncQualityView() {
            if (stopSampling && downloadMode === 'active' && !this.audioOnly) receive.sync(room);
        },

        suspendQualitySession() {
            stop(this);
        },

        endQualitySession() {
            stop(this);
            if (room) void share.reset(room).catch(() => {});
            // Allow the detached room and any unacknowledged subscription resets to be collected.
            receive.sync(null);
            room = null;
            panelVisible = false;
            removeVisibilityListener?.();
            removeVisibilityListener = null;
            this.qualityDecisions = [];
        },
    };
}
