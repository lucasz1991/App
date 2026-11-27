import { createLocalVideoTrack, Track } from 'livekit-client';
import { cameraCaptureOptions } from './call-media-quality.js';

const abortCamera = () => new DOMException('Camera setup changed.', 'AbortError');

export function supportsNativeCameraBlur(track, platform = globalThis) {
    return Boolean(platform.MediaStreamTrackProcessor && platform.MediaStreamTrackGenerator
        && track.getCapabilities?.().backgroundBlur?.includes(true));
}

// Publish a gated output. If an OS setting changes, even before the
// configurationchange notification arrives, subsequent frames are dropped.
export async function nativeCameraBlur(track, onFrame, onFailure, platform = globalThis) {
    if (!supportsNativeCameraBlur(track, platform)) throw new Error('Native blur unavailable');
    await track.applyConstraints({ ...track.getConstraints(), backgroundBlur: { exact: true } });
    const active = () => track.getSettings().backgroundBlur === true;
    if (!active()) throw new Error('Camera did not enable native blur');
    const input = new platform.MediaStreamTrackProcessor({ track });
    const output = new platform.MediaStreamTrackGenerator({ kind: 'video' });
    const controller = new AbortController();
    let warmed = false;
    const check = () => { if (!active()) onFailure(); };
    track.addEventListener('configurationchange', check);
    track.addEventListener('ended', onFailure);
    input.readable.pipeThrough(new TransformStream({
        transform(frame, sink) {
            if (!active() || controller.signal.aborted) {
                frame.close();
                onFailure();
            } else if (!warmed) {
                warmed = true;
                frame.close();
            } else {
                sink.enqueue(frame);
                onFrame();
            }
        },
    })).pipeTo(output.writable, { signal: controller.signal })
        .then(() => { if (!controller.signal.aborted) onFailure(); }, () => { if (!controller.signal.aborted) onFailure(); });
    return {
        track: output,
        stop() {
            track.removeEventListener('configurationchange', check);
            track.removeEventListener('ended', onFailure);
            controller.abort();
            output.stop();
        },
    };
}

// Keep SDK tracks, processors and timers outside Alpine's reactive proxies.
export function callCameraPreview(config, rawRoom, capture = createLocalVideoTrack, platform = globalThis) {
    let revision = 0;
    let preview = null;
    let prepared = null;
    let sending = null;
    let releaseTimer;
    let pausePromise;
    let preference = null;
    const loading = new Set();

    function release(item) {
        if (!item || item.stopped) return;
        item.stopped = true;
        clearInterval(item.watchdog);
        item.native?.stop();
        item.output?.removeEventListener('ended', item.fail);
        if (item.output) item.output.enabled = false;
        item.local.detach();
        item.local.stop();
    }

    async function stopSending(component, participant) {
        const track = participant?.getTrackPublication?.(Track.Source.Camera)?.track;
        if (track?.mediaStreamTrack) track.mediaStreamTrack.enabled = false;
        release(sending);
        sending = null;
        component.cameraOn = false;
        if (track) {
            track.stop();
            await participant.unpublishTrack(track);
        }
    }

    async function captureSelection(component, mode, deviceId, current, profile = component.effectiveCameraProfile) {
        const options = cameraCaptureOptions(profile);
        if (deviceId) options.deviceId = { exact: deviceId };
        const local = await capture(options);
        const item = { local, output: null, stopped: false };
        loading.add(item);
        try {
            if (!current()) throw abortCamera();
            const raw = local.mediaStreamTrack;
            component.cameraNativeBlurAvailable = config.cameraEffectsEnabled !== false && supportsNativeCameraBlur(raw, platform);
            if (mode === 'original') {
                item.output = raw;
                return item;
            }
            if (mode !== 'blur' || !component.cameraNativeBlurAvailable) throw new Error('Native blur unavailable');
            let lastFrame = 0;
            let frames = 0;
            item.native = await nativeCameraBlur(raw, () => {
                lastFrame = Date.now();
                frames++;
            }, () => {
                if (item.fail) item.fail();
                else release(item);
            }, platform);
            item.output = item.native.track;
            if (!current() || item.stopped) {
                item.native?.stop();
                local.stop();
                throw abortCamera();
            }
            const readyBy = Date.now() + 6000;
            // Wait for protected frames before enabling the preview or publication.
            while (!item.stopped && frames < 2 && Date.now() < readyBy) {
                if (!current()) throw abortCamera();
                await new Promise(resolve => setTimeout(resolve, 40));
            }
            if (!current() || item.stopped || frames < 2 || !item.output || item.output === raw) throw abortCamera();
            item.fail = () => {
                if (item.stopped) return;
                const active = item === sending;
                release(item);
                if (item === preview || item === prepared || active) {
                    component.cameraPreviewReady = false;
                    component.cameraPreviewMessage = config.labels.effectFailed;
                    if (active) {
                        component.startWithVideo = false;
                        component.cameraNeedsSetup = true;
                        void stopSending(component, rawRoom(component.room)?.localParticipant).catch(() => {});
                        component.toast(config.labels.effectFailed, 'warning');
                    }
                }
            };
            item.output.addEventListener('ended', item.fail);
            item.watchdog = setInterval(() => {
                if (Date.now() - lastFrame > 3500) item.fail();
            }, 1000);
            return item;
        } catch (error) {
            release(item);
            throw error;
        } finally {
            loading.delete(item);
        }
    }

    return {
        cameraMode: 'original',
        cameraDeviceId: '',
        cameraDevices: [],
        cameraNativeBlurAvailable: false,
        cameraPreviewReady: false,
        cameraPreviewBusy: false,
        cameraPreviewMessage: '',
        cameraSelectionCommitted: false,
        cameraNeedsSetup: false,

        async openCameraPreview() {
            this.cameraMode = preference?.mode || (this.startWithVideo ? 'original' : 'off');
            this.cameraDeviceId = preference?.deviceId || '';
            if (this.connected) {
                this.startWithVideo = false;
                this.cameraNeedsSetup = true;
            }
            pausePromise = this.connected ? stopSending(this, rawRoom(this.room)?.localParticipant) : Promise.resolve();
            return this.refreshCameraPreview();
        },

        async refreshCameraPreview() {
            const request = ++revision;
            const current = () => request === revision && !this.disposed && !this.terminal;
            for (const item of loading) release(item);
            release(preview);
            preview = null;
            this.cameraPreviewReady = false;
            this.cameraPreviewMessage = '';
            this.cameraPreviewBusy = true;
            const el = this.$refs.cameraPreviewVideo;
            if (el) el.srcObject = null;
            const mode = this.cameraMode;
            try {
                await pausePromise;
                if (!current()) return;
                if (mode === 'off') { this.cameraPreviewReady = true; return; }
                const item = await captureSelection(this, mode, this.cameraDeviceId, current);
                if (!current()) { release(item); return; }
                preview = item;
                if (el) {
                    el.srcObject = new MediaStream([item.output]);
                    await el.play();
                }
                if (!current()) return;
                this.cameraPreviewReady = true;
                const devices = await navigator.mediaDevices?.enumerateDevices?.().catch(() => []) || [];
                if (current()) this.cameraDevices = devices.filter(device => device.kind === 'videoinput');
            } catch (error) {
                if (!current()) return;
                release(preview);
                preview = null;
                this.cameraPreviewReady = false;
                this.cameraPreviewMessage = mode === 'original' ? this.deviceErrorLabel(error, 'camera') : config.labels.effectFailed;
            } finally {
                if (current()) this.cameraPreviewBusy = false;
            }
        },

        closeCameraPreview() {
            revision++;
            for (const item of loading) release(item);
            release(preview);
            preview = null;
            this.cameraPreviewReady = false;
            this.cameraPreviewBusy = false;
            if (this.$refs?.cameraPreviewVideo) this.$refs.cameraPreviewVideo.srcObject = null;
        },

        async commitCameraPreview() {
            const off = this.cameraMode === 'off';
            if (!this.cameraPreviewReady || (!off && (!preview || preview.stopped))) return false;
            preference = { mode: this.cameraMode, deviceId: this.cameraDeviceId };
            this.cameraSelectionCommitted = true;
            this.cameraNeedsSetup = off;
            this.startWithVideo = !off;
            release(prepared);
            prepared = preview;
            preview = null;
            this.closeCameraPreview();
            clearTimeout(releaseTimer);
            if (off) return true;
            if (this.connected) {
                try {
                    await this.enableTrack('camera');
                    this.syncLocalMediaState();
                } catch {
                    this.cameraPreviewMessage = config.labels.cameraSetupFailed;
                    return false;
                }
            } else {
                releaseTimer = setTimeout(() => { release(prepared); prepared = null; }, 30000);
            }
            return true;
        },

        async publishCameraSelection(room, connection) {
            const request = revision;
            const current = () => request === revision && this.isActiveRoom(room, connection) && this.canPublish && !this.leaving && !this.failed;
            if (!current() || this.cameraNeedsSetup || !preference || preference.mode === 'off') throw abortCamera();
            clearTimeout(releaseTimer);
            const item = prepared && !prepared.stopped ? prepared : await captureSelection(this, preference.mode, preference.deviceId, current);
            prepared = null;
            try {
                if (!current() || item.stopped) throw abortCamera();
                sending = item;
                const publication = await room.localParticipant.publishTrack(item.output, { source: Track.Source.Camera });
                if (!current() || item.stopped) {
                    await room.localParticipant.unpublishTrack(publication.track);
                    throw abortCamera();
                }
            } catch (error) {
                if (item === sending) sending = null;
                release(item);
                throw error;
            }
        },

        async stopConfiguredCamera(room) {
            revision++;
            for (const item of loading) release(item);
            await stopSending(this, room?.localParticipant);
        },

        async replaceCameraProfile(room, connection, publication, profile) {
            const participant = room.localParticipant;
            const track = publication.track;
            const previous = sending;
            const previousOutput = track.mediaStreamTrack;
            const request = revision;
            const current = () => request === revision && this.isActiveRoom(room, connection)
                && this.canControlMedia && this.cameraOn && !this.prejoinOpen && !this.audioOnly
                && !publication.isMuted && participant.getTrackPublication(Track.Source.Camera)?.track === track
                && sending === previous && !previous?.stopped;
            if (!current() || track.getProcessor?.()
                || (this.cameraSelectionCommitted && previous?.output !== previousOutput)) throw abortCamera();
            const mode = preference?.mode || 'original';
            const deviceId = previous?.local.mediaStreamTrack.getSettings?.().deviceId
                || preference?.deviceId || previousOutput.getSettings?.().deviceId || '';
            const next = await captureSelection(this, mode, deviceId, current, profile);
            // Keep the candidate under lifecycle control until the SDK finishes replacing
            // the sender. Closing the room or losing permission stops it even mid-swap.
            loading.add(next);
            try {
                if (!current() || next.stopped) throw abortCamera();
                await track.replaceTrack(next.output, { userProvidedTrack: true });
                if (!current() || next.stopped) throw abortCamera();
                sending = next;
                preference = { mode, deviceId };
                this.cameraSelectionCommitted = true;
                if (previous) release(previous);
                else previousOutput.stop();
            } catch (error) {
                // The SDK may reject after changing the sender. Never restart a
                // replacement from raw capture or bring back a superseded camera.
                if (track.mediaStreamTrack === next.output || track.sender?.track === next.output) {
                    if (current()) {
                        this.cameraNeedsSetup = true;
                        this.startWithVideo = false;
                        await stopSending(this, participant);
                    }
                    if (previous && sending === previous) {
                        release(previous);
                        sending = null;
                    } else if (!previous) {
                        previousOutput.stop();
                    }
                }
                throw error;
            } finally {
                loading.delete(next);
                if (sending !== next) release(next);
            }
        },

        disposeCameraPreview() {
            this.closeCameraPreview();
            clearTimeout(releaseTimer);
            release(prepared);
            release(sending);
            prepared = null;
            sending = null;
        },
    };
}
