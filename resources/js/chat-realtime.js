import { Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import {
    acquireMicrophoneStream,
    holdMicrophoneStream,
    releaseMicrophoneStream,
} from './microphone-stream';

// Echtzeit-Kanal, Tippanzeige und Sprachaufnahme eines Chats. Die beiden
// geteilten Laufzeitobjekte (Praesenzkontext und Live-Standort) erzeugt
// app.js einmal und reicht sie hier hinein.
export function createChatRealtime({ rtNotificationContext, rtLiveLocation }) {
    return (config) => ({
        channel: null,
        typingLabel: '',
        typingTimer: null,
        recorder: null,
        recording: false,
        recordingSeconds: 0,
        recordingLabel: '',
        recordingTimer: null,
        recordingIntent: null,
        sendingVoice: false,
        viewOnce: false,
        chunks: [],
        // Chatwechsel zerstoert die Instanz, waehrend startRecording() noch auf
        // die Freigabe-Abfrage wartet. Ohne dieses Lebenszeichen liefe die
        // Fortsetzung auf der toten Instanz weiter: Recorder und Interval ohne
        // jede Abraeum-Chance, Mikrofon dauerhaft heiss.
        destroyed: false,
        // Pegelmessung waehrend der Aufnahme: Grundlage fuer eine Wellenform,
        // die zur Nachricht passt statt eines Einheitsmusters.
        waveformContext: null,
        waveformTimer: null,
        waveformSamples: [],

        init() {
            this.recordingLabel = '0:00';

            if (!window.Echo || !config.chatId) {
                return;
            }

            this.channel = window.Echo.private(`chat.${config.chatId}`)
                .listen('.call.timeline.changed', (event) => {
                    if (rtNotificationContext.isLocalChatVisible(Number(event.chatId))) {
                        Livewire.dispatchTo('chat-box', 'chat:timeline-refresh');
                    }
                    Livewire.dispatch('inbox:refresh');
                })
                .listen('.chat.message.sent', (event) => {
                    if (rtNotificationContext.isLocalChatVisible(Number(event.chatId))) {
                        Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
                    }
                    Livewire.dispatch('inbox:refresh');
                })
                .listen('.chat.message.deleted', (event) => {
                    if (rtNotificationContext.isLocalChatVisible(Number(event.chatId))) {
                        Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
                    }
                    Livewire.dispatch('inbox:refresh');
                })
                .listen('.chat.message.reaction', (event) => {
                    if (rtNotificationContext.isLocalChatVisible(Number(event.chatId))) {
                        Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
                    }
                })
                .listen('.chat.live-location.changed', (event) => {
                    // The broadcast is deliberately only a refresh signal. Exact
                    // coordinates are fetched through the authorized Livewire
                    // message response and never copied out of this event.
                    const signal = {
                        chatId: Number(event.chatId || event.chat_id || 0),
                        messageId: Number(event.messageId || event.message_id || 0),
                        shareId: String(
                            event.liveLocationId
                            || event.live_location_id
                            || event.shareId
                            || event.share_id
                            || '',
                        ),
                        status: String(event.status || ''),
                    };

                    rtLiveLocation.handleRealtimeSignal(signal);
                    window.dispatchEvent(new CustomEvent('railtime:live-location-changed', {
                        detail: signal,
                    }));

                    if (signal.chatId > 0 && rtNotificationContext.isLocalChatVisible(signal.chatId)) {
                        Livewire.dispatch('chat:refresh', { chatId: signal.chatId });
                    }
                })
                .listen('.chat.read', (event) => {
                    if (rtNotificationContext.isLocalChatVisible(Number(event.chatId))) {
                        Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
                    }
                })
                .listenForWhisper('typing', (event) => {
                    if (Number(event.userId) === Number(config.userId)) {
                        return;
                    }

                    window.clearTimeout(this.typingTimer);
                    this.typingLabel = `${event.userName} ${config.typingText}`;
                    this.typingTimer = window.setTimeout(() => {
                        this.typingLabel = '';
                    }, 1800);
                });
        },

        destroy() {
            this.destroyed = true;
            window.clearTimeout(this.typingTimer);
            window.clearInterval(this.recordingTimer);
            this.stopWaveformCapture();
            if (this.recorder?.state === 'recording') {
                this.recordingIntent = 'cancel';
                this.recorder.stop();
            }
            // NICHT sofort stoppen: destroy() laeuft bei jedem Chatwechsel. Wuerde
            // das Mikrofon hier geschlossen, fragte Firefox im naechsten Chat
            // erneut nach der Freigabe – exakt das urspruengliche Problem.
            holdMicrophoneStream();

            if (window.Echo && config.chatId) {
                window.Echo.leave(`chat.${config.chatId}`);
            }
        },

        sendTyping() {
            this.channel?.whisper('typing', {
                userId: Number(config.userId),
                userName: config.userName,
            });
        },

        async startRecording() {
            if (this.recording || this.sendingVoice) {
                return;
            }

            if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                window.dispatchEvent(new CustomEvent('swal:toast', {
                    detail: { type: 'error', text: config.unsupportedText || 'Sprachaufnahme wird von diesem Browser nicht unterstützt.' },
                }));
                return;
            }

            try {
                // Der Halter liefert einen noch offenen Stream ohne erneute
                // Browser-Abfrage – auch nach einem Chatwechsel. Das ist der
                // eigentliche Fix gegen das wiederholte Nachfragen.
                const stream = await acquireMicrophoneStream();

                // Waehrend der Freigabe-Abfrage in einen anderen Chat gewechselt?
                // Dann keinen Recorder mehr starten – aber den frisch erteilten
                // Stream HALTEN, damit die Aufnahme im neuen Chat ohne erneute
                // Abfrage klappt.
                if (this.destroyed) {
                    holdMicrophoneStream();
                    return;
                }

                const preferredMime = [
                    'audio/webm;codecs=opus',
                    'audio/ogg;codecs=opus',
                    'audio/mp4',
                ].find((mime) => MediaRecorder.isTypeSupported(mime));

                this.chunks = [];
                this.recorder = preferredMime
                    ? new MediaRecorder(stream, { mimeType: preferredMime })
                    : new MediaRecorder(stream);

                this.startWaveformCapture(stream);

                this.recorder.addEventListener('dataavailable', (event) => {
                    if (event.data.size > 0) {
                        this.chunks.push(event.data);
                    }
                });

                this.recorder.addEventListener('stop', () => this.finishRecording(), { once: true });
                this.recorder.start(250);
                this.recording = true;
                this.recordingIntent = null;
                this.viewOnce = false;
                this.recordingSeconds = 0;
                this.updateRecordingLabel();
                this.recordingTimer = window.setInterval(() => {
                    this.recordingSeconds += 1;
                    this.updateRecordingLabel();

                    if (this.recordingSeconds >= 300) {
                        this.sendRecording();
                    }
                }, 1000);
            } catch (error) {
                releaseMicrophoneStream();

                // Kein Einrichtungsdialog mehr: getUserMedia loest die Browser-
                // Abfrage direkt aus. Landet man hier mit NotAllowed, ist die
                // Freigabe im Browser BLOCKIERT — dann hilft nur die Anleitung
                // zum Schloss-Symbol.
                const blocked = error?.name === 'NotAllowedError' || error?.name === 'SecurityError';

                window.dispatchEvent(new CustomEvent('swal:toast', {
                    detail: {
                        type: 'error',
                        text: blocked
                            ? (config.microphoneBlockedText || 'Das Mikrofon ist im Browser blockiert. Bitte über das Schloss-Symbol in der Adresszeile erlauben.')
                            : (config.microphoneErrorText || 'Das Mikrofon konnte nicht verwendet werden.'),
                    },
                }));
            }
        },

        cancelRecording() {
            if (!this.recording) {
                this.resetVoiceRecorder();
                return;
            }

            this.recordingIntent = 'cancel';
            this.recorder?.stop();
        },

        sendRecording() {
            if (!this.recording || this.sendingVoice) {
                return;
            }

            this.recordingIntent = 'send';
            this.sendingVoice = true;
            this.recorder?.stop();
        },

        toggleViewOnce() {
            if (this.recording && !this.sendingVoice) {
                this.viewOnce = !this.viewOnce;
            }
        },

        finishRecording() {
            window.clearInterval(this.recordingTimer);
            this.recording = false;
            // Nicht sofort schliessen: sonst fragt Firefox bei der naechsten
            // Sprachnachricht erneut nach der Freigabe.
            holdMicrophoneStream();

            // Pegel VOR dem Aufraeumen einsammeln — reset leert die Messwerte.
            this.stopWaveformCapture();
            const waveform = this.condensedWaveform();

            const shouldSend = this.recordingIntent === 'send';

            if (!shouldSend) {
                this.resetVoiceRecorder();
                return;
            }

            if (this.chunks.length === 0) {
                this.voiceUploadFailed();
                return;
            }

            const recordedDuration = Math.max(1, Math.round(this.recordingSeconds));
            const mime = this.recorder?.mimeType || this.chunks[0]?.type || 'audio/webm';
            const extension = mime.includes('ogg') ? 'ogg' : (mime.includes('mp4') ? 'm4a' : 'webm');
            const file = new File(
                [new Blob(this.chunks, { type: mime })],
                `sprachnachricht-${Date.now()}.${extension}`,
                { type: mime }
            );

            this.$wire.upload(
                'voiceUpload',
                file,
                () => {
                    this.$wire.call('sendVoice', this.viewOnce, recordedDuration, waveform)
                        .then(() => this.resetVoiceRecorder())
                        .catch(() => this.voiceUploadFailed());
                },
                () => this.voiceUploadFailed()
            );
            this.chunks = [];
        },

        voiceUploadFailed() {
            this.resetVoiceRecorder();
            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'error', text: config.uploadErrorText || 'Die Sprachnachricht konnte nicht gesendet werden.' },
            }));
        },

        resetVoiceRecorder() {
            window.clearInterval(this.recordingTimer);
            this.stopWaveformCapture();
            holdMicrophoneStream();
            this.recording = false;
            this.sendingVoice = false;
            this.recordingIntent = null;
            this.recordingSeconds = 0;
            this.recordingLabel = '0:00';
            this.chunks = [];
            this.recorder = null;
            this.viewOnce = false;
            this.waveformSamples = [];
        },

        updateRecordingLabel() {
            const minutes = Math.floor(this.recordingSeconds / 60);
            const seconds = String(this.recordingSeconds % 60).padStart(2, '0');
            this.recordingLabel = `${minutes}:${seconds}`;
        },

        /**
         * Pegel der laufenden Aufnahme mitschreiben (alle 150 ms ein Spitzenwert).
         *
         * Ein AnalyserNode liest den Stream nur mit — MediaRecorder und die
         * spaetere Wiedergabe bleiben unberuehrt. Scheitert WebAudio, faellt die
         * Wellenform stumm aus: Sie ist Komfort, keine Voraussetzung.
         */
        startWaveformCapture(stream) {
            this.stopWaveformCapture();
            this.waveformSamples = [];

            try {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;

                if (! AudioContextClass) {
                    return;
                }

                this.waveformContext = new AudioContextClass();
                const analyser = this.waveformContext.createAnalyser();
                analyser.fftSize = 512;
                this.waveformContext.createMediaStreamSource(stream).connect(analyser);

                const data = new Uint8Array(analyser.fftSize);

                this.waveformTimer = window.setInterval(() => {
                    analyser.getByteTimeDomainData(data);

                    let peak = 0;

                    for (let i = 0; i < data.length; i += 1) {
                        peak = Math.max(peak, Math.abs(data[i] - 128));
                    }

                    this.waveformSamples.push(Math.min(100, Math.round((peak / 128) * 100)));
                }, 150);
            } catch (_) {
                this.stopWaveformCapture();
            }
        },

        stopWaveformCapture() {
            window.clearInterval(this.waveformTimer);
            this.waveformTimer = null;
            this.waveformContext?.close().catch(() => {});
            this.waveformContext = null;
        },

        /**
         * Rohpegel auf eine feste Balkenzahl eindampfen und auf die eigene
         * Aussteuerung normieren (lauteste Stelle = 100). Ohne Normierung saehe
         * eine leise Nachricht aus wie Stille.
         */
        condensedWaveform(buckets = 64) {
            const samples = this.waveformSamples;

            if (samples.length === 0) {
                return [];
            }

            const bucketCount = Math.min(buckets, samples.length);
            const condensed = [];

            for (let i = 0; i < bucketCount; i += 1) {
                const start = Math.floor((i * samples.length) / bucketCount);
                const end = Math.max(start + 1, Math.floor(((i + 1) * samples.length) / bucketCount));
                let sum = 0;

                for (let j = start; j < end; j += 1) {
                    sum += samples[j];
                }

                condensed.push(sum / (end - start));
            }

            const loudest = Math.max(...condensed);

            if (loudest <= 0) {
                return [];
            }

            return condensed.map((value) => Math.max(2, Math.round((value / loudest) * 100)));
        },
    });
}
