import { callQualitySession } from './call-quality-session.js';
export { observeMediaQuality, videoCounters, intervalQuality } from './call-quality-sampling.js';

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

// Keep the transport itself outside Alpine's proxy while updating the local view state.
export function callQualityControls(raw, labels, pilot) {
    return {
        ...callQualitySession(raw, pilot),
        cameraProfile: 'auto',
        effectiveCameraProfile: 'auto',
        cameraProfileChanging: false,
        audioOnly: false,
        profileBusy: false,
        connectionQuality: 'unknown',
        pausedVideoSids: new Set(),

        changeCameraProfile(profile) {
            return this.applyCameraProfile(profile, { manual: true });
        },

        async applyCameraProfile(profile, { manual = false } = {}) {
            const room = raw(this.room);
            const generation = this.connectionGeneration;
            if (!room || !this.canControlMedia || this.profileBusy || this.cameraBusy || this.audioOnly
                || this.prejoinOpen || !['auto', 'saver'].includes(profile)
                || (!manual && (!this.cameraOn || (profile === 'auto' && this.cameraProfile === 'saver')))) return false;
            if (profile === this.effectiveCameraProfile) {
                if (manual) this.cameraProfile = profile;
                return true;
            }
            this.profileBusy = true;
            this.cameraProfileChanging = true;
            try {
                const publication = room.localParticipant.getTrackPublication('camera');
                if (publication?.track && !publication.isMuted) {
                    if (!this.cameraOn || publication.track.getProcessor?.()) return false;
                    await this.replaceCameraProfile(room, generation, publication, profile);
                } else if (!manual) {
                    return false;
                } else if (publication?.track && publication.isMuted) {
                    // A later explicit unmute must use the selected capture profile.
                    if (this.cameraSelectionCommitted) await this.stopConfiguredCamera(room);
                    else await room.localParticipant.unpublishTrack(publication.track, true);
                }
                if (!this.isActiveRoom(room, generation) || !this.canControlMedia) return false;
                this.effectiveCameraProfile = profile;
                if (manual) this.cameraProfile = profile;
                return true;
            } catch (error) {
                if (manual && this.isActiveRoom(room, generation) && error?.name !== 'AbortError') {
                    this.toast(labels.profileFailed, 'warning');
                }
                return false;
            } finally {
                if (this.isActiveRoom(room, generation)) {
                    this.syncLocalMediaState(room);
                    this.profileBusy = false;
                    this.cameraProfileChanging = false;
                }
            }
        },

        protectedCameraOn() {
            return this.cameraOn && Boolean(raw(this.room)?.localParticipant.getTrackPublication?.('camera')?.track?.getProcessor?.());
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
                this.suspendQualitySession();
                this.audioOnly = enabled;
                this.syncIncomingVideo(room);
                this.startQualitySession();
            } catch {
                if (this.isActiveRoom(room)) this.toast(labels.profileFailed, 'warning');
            } finally {
                if (this.isActiveRoom(room)) {
                    this.syncLocalMediaState(room);
                    this.profileBusy = false;
                }
            }
        },

        resetQualityState() {
            this.endQualitySession();
            this.effectiveCameraProfile = this.cameraProfile;
            this.connectionQuality = 'unknown';
            this.cameraProfileChanging = false;
            this.audioOnly = false;
            this.profileBusy = false;
            this.pausedVideoSids.clear();
        },

        formatQuality(value, unit, digits = 0) {
            return Number.isFinite(value) ? value.toLocaleString(document.documentElement.lang || undefined, { maximumFractionDigits: digits }) + ' ' + unit : '—';
        },
    };
}
