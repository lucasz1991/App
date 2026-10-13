import { aiIntakeRecorder } from './ai-intake-recorder.js';

/** One compact composer shared by intake capture and intake-scoped discussion. */
export function aiIntakeComposer(wire, options = {}, environment = {}) {
    const urls = environment.URL ?? globalThis.URL;
    let state;
    let destroyed = false;
    let generation = 0;
    let inputGeneration = 0;
    let hidden = null;
    let pendingPreviewStart = 0;
    const target = environment.events ?? globalThis.window;
    const recorderWire = {
        upload(name, file, success, failure) {
            state.previewAudio(file);
            wire.upload(name, file, success, failure);
        },
        cancelUpload(name) { wire.cancelUpload?.(name); },
    };
    const native = aiIntakeRecorder(recorderWire, environment);
    const nativeInit = native.init;
    const nativeStart = native.start;
    const nativeCancel = native.cancel;
    const nativeDestroy = native.destroy;
    state = {
        ...native,
        busy: false,
        uploadActive: false,
        uploadProgress: 0,
        uploadError: '',
        audioPreview: '',
        filePreviews: [],
        previewName: '',
        wireKey: '',
        getFiles() { return wire.uploads ?? []; },
        attachmentCount() { return this.getFiles().length + (wire.audioUpload ? 1 : 0); },
        contextKey() {
            return options.mode === 'assistant'
                ? [wire.intakeId, wire.intakeRevision, wire.sourceRevision, wire.settingsRevision, wire.overviewFingerprint].join(':')
                : String(wire.captureOpen);
        },
        init() {
            nativeInit.call(this);
            this.wireKey = this.contextKey();
            this.$watch?.('wireKey', () => this.resetContext());
            hidden = () => this.pause();
            target?.addEventListener('ai-intake-panel-hidden', hidden);
            this.resize();
        },
        syncContext() {
            const next = this.contextKey();
            if (next !== this.wireKey) {
                this.wireKey = next;
                this.resetContext();
            }
        },
        async bindContext() {
            if (options.mode !== 'assistant') return true;
            const version = generation;
            const inputVersion = inputGeneration;
            const key = this.contextKey();
            try {
                await wire.beginContextUpload();
                return !destroyed && version === generation && inputVersion === inputGeneration && key === this.contextKey() && !wire.contextChanged;
            } catch {
                this.uploadError = 'Der Eingang wurde geändert. Bitte den aktuellen Stand laden.';
                return false;
            }
        },
        async chooseFiles() {
            if (this.busy || this.uploadActive || this.attachmentCount() >= (options.maxFiles ?? 3) || this.recording || this.requesting) return;
            if (await this.bindContext()) this.$refs?.files?.click();
        },
        filesSelected(event) {
            const files = [...(event.target.files ?? [])];
            pendingPreviewStart = this.filePreviews.length;
            this.filePreviews.push(...files.map(file => {
                const kind = file.type.startsWith('image/') ? 'image' : (/\.(wav|mp3|ogg|webm|m4a|mp4|aac|flac)$/i.test(file.name) ? 'audio' : 'file');
                return { name: file.name, kind, url: kind === 'file' ? '' : urls.createObjectURL(file) };
            }));
            this.uploadError = '';
        },
        previewAudio(file) {
            this.clearAudioPreview();
            if (file) {
                this.audioPreview = urls.createObjectURL(file);
                this.previewName = file.name;
            }
        },
        beginUpload() {
            this.uploadActive = true;
            this.uploadProgress = 0;
            this.uploadError = '';
        },
        progressUpload(detail) { if (this.uploadActive) this.uploadProgress = Math.max(0, Math.min(100, Number(detail?.progress) || 0)); },
        finishUpload() { if (!this.uploadActive) return; this.uploadActive = false; this.uploadProgress = 100; },
        failUpload() {
            if (!this.uploadActive) return;
            this.uploadActive = false;
            this.uploadError = 'Die Datei konnte nicht vollständig hochgeladen werden. Bitte erneut auswählen.';
        },
        cancelUpload() { this.uploadActive = false; this.uploadProgress = 0; },
        cancelFileUpload() {
            wire.cancelUpload?.('uploads');
            wire.cancelUpload?.('audioUpload');
            this.cancelUpload();
            this.filePreviews.splice(pendingPreviewStart).forEach(file => { if (file.url) urls.revokeObjectURL(file.url); });
            if (!wire.audioUpload) this.clearAudioPreview();
        },
        async start() {
            if (this.busy || this.uploadActive || wire.audioUpload || this.attachmentCount() >= (options.maxFiles ?? 3)) return;
            if (await this.bindContext()) await nativeStart.call(this);
        },
        async toggleRecording() { this.recording ? this.stop() : await this.start(); },
        cancelRecording() { nativeCancel.call(this); this.clearAudioPreview(); },
        async removeFile(index) {
            if (this.busy || this.uploadActive) return;
            const count = this.getFiles().length;
            await wire.removeUpload(index);
            if (!destroyed && this.getFiles().length < count) {
                const item = this.filePreviews[index];
                if (item?.url) urls.revokeObjectURL(item.url);
                this.filePreviews.splice(index, 1);
            }
        },
        async removeAudio() {
            nativeCancel.call(this);
            await wire.removeAudio();
            if (!destroyed && !wire.audioUpload) this.clearAudioPreview();
        },
        canSubmit() {
            return !destroyed && !this.busy && !this.uploadActive && !this.uploading && !this.recording && !this.requesting
                && !wire.contextChanged && (!!this.$refs?.prompt?.value?.trim() || this.getFiles().length > 0 || !!wire.audioUpload);
        },
        async submit() {
            if (!this.canSubmit()) return;
            this.busy = true;
            const version = generation;
            try { await wire[options.action ?? 'submitPrompt'](); }
            finally { if (!destroyed && version === generation) { this.busy = false; this.resize(); } }
        },
        enter(event) {
            if (event.isComposing || event.keyCode === 229 || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
            event.preventDefault();
            return this.submit();
        },
        resize() {
            const field = this.$refs?.prompt;
            if (field) { field.style.height = 'auto'; field.style.height = `${Math.min(160, Math.max(40, field.scrollHeight))}px`; }
        },
        clearFilePreviews() {
            this.filePreviews.forEach(file => { if (file.url) urls.revokeObjectURL(file.url); });
            this.filePreviews = [];
        },
        clearAudioPreview() {
            if (this.audioPreview) urls.revokeObjectURL(this.audioPreview);
            this.audioPreview = '';
            this.previewName = '';
            this.fileName = '';
        },
        submitted(componentId) {
            if (String(componentId) !== String(options.componentId)) return;
            this.clearFilePreviews();
            this.clearAudioPreview();
            if (this.$refs?.files) this.$refs.files.value = '';
            if (this.$refs?.audio) this.$refs.audio.value = '';
        },
        resetContext() {
            ++generation;
            nativeCancel.call(this);
            wire.cancelUpload?.('uploads');
            this.cancelUpload();
            this.clearFilePreviews();
            this.clearAudioPreview();
            this.busy = false;
            this.uploadError = '';
        },
        cancel() { this.resetContext(); },
        pause() {
            ++inputGeneration;
            const pendingAudio = this.uploading;
            nativeCancel.call(this);
            if (this.uploadActive) this.cancelFileUpload();
            if (pendingAudio && !wire.audioUpload) this.clearAudioPreview();
        },
        destroy() {
            destroyed = true;
            nativeDestroy.call(this);
            this.clearFilePreviews();
            this.clearAudioPreview();
            target?.removeEventListener('ai-intake-panel-hidden', hidden);
        },
    };
    return state;
}
