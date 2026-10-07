export function aiIntakeRecorder(wire, environment = {}) {
    const media = environment.mediaDevices ?? globalThis.navigator?.mediaDevices;
    const Recorder = environment.MediaRecorder ?? globalThis.MediaRecorder;
    const target = environment.events ?? globalThis.window;
    const timers = environment.timers ?? globalThis;
    let generation = 0;
    let stream = null;
    let recorder = null;
    let timer = null;
    let chunks = [];
    let uploadAllowed = false;
    let destroyed = false;
    let leave = null;

    return {
        recording: false,
        requesting: false,
        uploading: false,
        seconds: 0,
        error: '',
        fileName: '',
        init() {
            leave = () => this.cancel();
            target?.addEventListener('pagehide', leave);
            target?.addEventListener('livewire:navigating', leave);
        },
        async start() {
            if (this.recording || this.requesting || this.uploading || destroyed) return;
            this.error = '';
            if (!media?.getUserMedia || !Recorder) {
                this.error = 'Die Aufnahme wird hier nicht unterstützt. Laden Sie eine Audiodatei hoch.';
                return;
            }
            const version = ++generation;
            this.requesting = true;
            try {
                const granted = await media.getUserMedia({ audio: true });
                if (destroyed || version !== generation) {
                    granted.getTracks().forEach(track => track.stop());
                    return;
                }
                stream = granted;
                const mime = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus']
                    .find(value => Recorder.isTypeSupported?.(value));
                recorder = new Recorder(stream, mime ? { mimeType: mime } : undefined);
                chunks = [];
                this.seconds = 0;
                uploadAllowed = true;
                recorder.ondataavailable = event => {
                    if (event.data?.size) chunks.push(event.data);
                    if (chunks.reduce((total, chunk) => total + chunk.size, 0) > 8 * 1024 * 1024) {
                        this.error = 'Die Aufnahme ist zu groß. Bitte kürzer aufnehmen.';
                        this.cancel();
                    }
                };
                recorder.onstop = () => this.finish(version);
                recorder.onerror = () => {
                    this.error = 'Die Aufnahme konnte nicht abgeschlossen werden.';
                    this.cancel();
                };
                recorder.start(1000);
                this.recording = true;
                timer = timers.setInterval(() => {
                    this.seconds++;
                    if (this.seconds >= 120) this.stop();
                }, 1000);
            } catch {
                this.error = 'Das Mikrofon ist nicht verfügbar. Prüfen Sie die Freigabe oder laden Sie Audio hoch.';
                this.cancel();
            } finally {
                if (version === generation) this.requesting = false;
            }
        },
        stop() {
            if (recorder?.state === 'recording') recorder.stop();
            this.recording = false;
            timers.clearInterval(timer);
            stream?.getTracks().forEach(track => track.stop());
        },
        finish(version) {
            this.stop();
            if (!uploadAllowed || destroyed || version !== generation) {
                chunks = [];
                return;
            }
            const mime = recorder?.mimeType || chunks[0]?.type || 'audio/webm';
            const blob = new Blob(chunks, { type: mime });
            chunks = [];
            if (!blob.size || blob.size > 8 * 1024 * 1024) {
                this.error = 'Die Aufnahme ist leer oder zu groß.';
                return;
            }
            const extension = mime.includes('mp4') ? 'm4a' : (mime.includes('ogg') ? 'ogg' : 'webm');
            const file = new File([blob], `Sprachanfrage.${extension}`, { type: mime });
            this.uploading = true;
            wire.upload('audioUpload', file, () => {
                if (destroyed || version !== generation) return;
                this.uploading = false;
                this.fileName = file.name;
            }, () => {
                if (destroyed || version !== generation) return;
                this.uploading = false;
                this.error = 'Die Aufnahme konnte nicht hochgeladen werden. Bitte erneut versuchen.';
            });
        },
        cancel() {
            ++generation;
            uploadAllowed = false;
            if (this.uploading) wire.cancelUpload?.('audioUpload');
            this.stop();
            chunks = [];
            this.requesting = false;
            this.uploading = false;
        },
        destroy() {
            destroyed = true;
            this.cancel();
            target?.removeEventListener('pagehide', leave);
            target?.removeEventListener('livewire:navigating', leave);
        },
    };
}
