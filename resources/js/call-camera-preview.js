import { createLocalVideoTrack, Track } from 'livekit-client';
import { cameraCaptureOptions } from './call-media-quality.js';

const abortCamera = () => new DOMException('Camera setup changed.', 'AbortError');

// Keep SDK tracks, processors and timers outside Alpine's reactive proxies.
export function callCameraPreview(config, rawRoom, capture = createLocalVideoTrack, loadEffects = url => import(/* @vite-ignore */ url)) {
    let revision = 0;
    let preview = null;
    let prepared = null;
    let sending = null;
    let releaseTimer;
    let pausePromise;
    let preference = null;
    let effects;
    const loading = new Set();

    function release(item) {
        if (!item || item.stopped) return;
        item.stopped = true;
        clearInterval(item.watchdog);
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

    async function captureSelection(component, mode, deviceId, current) {
        const options = cameraCaptureOptions(component.cameraProfile);
        if (deviceId) options.deviceId = { exact: deviceId };
        const local = await capture(options);
        const item = { local, output: null, stopped: false };
        loading.add(item);
        try {
            if (!current()) throw abortCamera();
            const raw = local.mediaStreamTrack;
            if (mode === 'original') {
                item.output = raw;
                return item;
            }
            if (!config.cameraAssets) throw new Error('Effects unavailable');
            const base = config.cameraAssets;
            const sdk = await effects;
            if (!current()) throw abortCamera();
            if (!sdk?.supportsBackgroundProcessors()) throw new Error('Unsupported processor');
            let lastFrame = 0;
            let frames = 0;
            const processor = sdk.BackgroundProcessor({
                ...(mode === 'blur'
                    ? { mode: 'background-blur', blurRadius: 16 }
                    : { mode: 'virtual-background', imagePath: config.cameraBackground }),
                assetPaths: { modelAssetPath: base + '/selfie_segmenter.tflite', tasksVisionFileSet: base + '/wasm' },
                onFrameProcessed() { lastFrame = Date.now(); frames++; },
            });
            await local.setProcessor(processor);
            if (!current()) { local.stop(); throw abortCamera(); }
            const readyBy = Date.now() + 6000;
            // The library can emit an initialization frame. Publish only an
            // already-running processor output, never the capture track itself.
            while (frames < 2 && Date.now() < readyBy) {
                if (!current()) throw abortCamera();
                await new Promise(resolve => setTimeout(resolve, 40));
            }
            item.output = processor.processedTrack;
            if (!current() || frames < 2 || !item.output || item.output === raw) throw abortCamera();
            item.fail = () => {
                if (item.stopped) return;
                const active = item === sending;
                release(item);
                if (item === preview || item === prepared || active) {
                    component.cameraPreviewReady = false;
                    component.cameraPreviewMessage = config.labels.effectFailed;
                    if (active) {
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
        cameraEffectsAvailable: false,
        cameraPreviewReady: false,
        cameraPreviewBusy: false,
        cameraPreviewMessage: '',
        cameraSelectionCommitted: false,
        cameraNeedsSetup: false,

        async openCameraPreview() {
            if (config.cameraAssets) {
                effects ??= loadEffects(config.cameraAssets + '/processor.js').catch(() => null);
                void effects.then(sdk => {
                    if (!this.disposed) this.cameraEffectsAvailable = Boolean(sdk?.supportsBackgroundProcessors());
                }).catch(() => { this.cameraEffectsAvailable = false; });
            }
            this.cameraMode = preference?.mode || (this.startWithVideo ? 'original' : 'off');
            this.cameraDeviceId = preference?.deviceId || '';
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
                const devices = await navigator.mediaDevices.enumerateDevices().catch(() => []);
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
            if (!current() || !preference || preference.mode === 'off') throw abortCamera();
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
            await stopSending(this, room?.localParticipant);
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
