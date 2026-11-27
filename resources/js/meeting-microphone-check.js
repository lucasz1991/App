// Raw MediaStream and Web Audio objects stay outside Alpine's reactive proxy.
export function createMeetingMicrophoneCheck() {
    let stream = null;
    let context = null;
    let timer = null;
    let generation = 0;

    function release() {
        generation++;
        clearInterval(timer);
        timer = null;
        stream?.getTracks().forEach(track => track.stop());
        stream = null;
        context?.close().catch(() => {});
        context = null;
    }

    return {
        microphoneId: '',
        microphones: [],
        microphoneTesting: false,
        microphoneOpening: false,
        microphoneLevel: 0,
        microphoneTestError: '',
        startWithMicrophone: true,

        async listMicrophones() {
            const devices = await navigator.mediaDevices?.enumerateDevices?.().catch(() => []);
            if (this.disposed || !this.prejoinOpen) return;
            this.microphones = (devices || []).filter(device => device.kind === 'audioinput' && device.deviceId);
        },

        stopMicrophoneTest() {
            release();
            this.microphoneOpening = false;
            this.microphoneTesting = false;
            this.microphoneLevel = 0;
        },

        async testMicrophone() {
            this.stopMicrophoneTest();
            if (!this.prejoinOpen || this.disposed) return;
            const current = generation;
            this.microphoneOpening = true;
            this.microphoneTestError = '';
            try {
                if (!navigator.mediaDevices?.getUserMedia) throw new DOMException('', 'NotAllowedError');
                const input = this.microphoneId ? { deviceId: { exact: this.microphoneId } } : true;
                const granted = await navigator.mediaDevices.getUserMedia({ audio: input, video: false });
                if (current !== generation || !this.prejoinOpen || this.disposed) {
                    granted.getTracks().forEach(track => track.stop());
                    return;
                }
                stream = granted;
                const Audio = window.AudioContext || window.webkitAudioContext;
                if (!Audio) throw new Error('Web Audio unavailable');
                context = new Audio();
                const analyser = context.createAnalyser();
                analyser.fftSize = 512;
                context.createMediaStreamSource(stream).connect(analyser);
                await context.resume();
                if (current !== generation) return;
                this.microphoneTesting = true;
                this.listMicrophones();
                const samples = new Uint8Array(analyser.fftSize);
                // Measure locally. Connecting an input to the speakers causes feedback.
                timer = setInterval(() => {
                    analyser.getByteTimeDomainData(samples);
                    let sum = 0;
                    for (const sample of samples) sum += ((sample - 128) / 128) ** 2;
                    this.microphoneLevel = Math.min(100, Math.round(Math.sqrt(sum / samples.length) * 320));
                }, 100);
            } catch (error) {
                if (current !== generation) return;
                this.stopMicrophoneTest();
                this.microphoneTestError = this.deviceErrorLabel(error, 'microphone');
            } finally {
                if (current === generation) this.microphoneOpening = false;
            }
        },
    };
}
