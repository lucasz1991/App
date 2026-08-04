/**
 * Video-Erfassung fuer die Wagenliste (Prototyp).
 *
 * Ein Vollbild-Overlay UEBER dem Ausfuellformular: Kamera und Mikrofon an,
 * am Zug entlanggehen — Anschriften und Tafeln werden fortlaufend gelesen
 * (Shape-Detection-API `TextDetector`, wo verfuegbar), erkannte Werte
 * fuellen den aktuellen Wagen automatisch. Jede Erkennung quittiert ein
 * Ping plus kurzer Ansage ("Wagennummer erkannt", "Neuer Wagen").
 * Ergaenzend nimmt die Spracheingabe (SpeechRecognition) diktierte Angaben
 * an derselben Stelle entgegen.
 *
 * Aufnahmen (Video, Fotos, Tonspur) werden clientseitig kompakt kodiert
 * (WebM mit moderater Bitrate, JPEG 0.72) und über den Medien-Endpunkt an
 * das File-Modell gehaengt — privater Speicher, Zuordnung ueber die
 * Entwurfs-ID der Wagenliste.
 *
 * Die Komponente liegt im Alpine-Scope des Wagenlisten-Editors und
 * nutzt dessen Zustand (wagons, mobileWagon, addWagon, …) ueber die
 * Scope-Kette — ueberall defensiv, der Editor bleibt ohne sie voll
 * bedienbar.
 */

const UIC_DIGIT_PATTERN = /(?:\d[\s./-]?){11,12}/g;

export function splitUicDigits(digits) {
    const clean = String(digits || '').replace(/\D/g, '');

    if (clean.length < 11) {
        return null;
    }

    return {
        number12: clean.slice(0, 2),
        number34: clean.slice(2, 4),
        number58: clean.slice(4, 8),
        number911: clean.slice(8, 11),
        checkDigit: clean.length >= 12 ? clean.slice(11, 12) : '',
    };
}

/**
 * Anschriften eines Wagens aus erkannten Textzeilen ziehen (reine Funktion).
 * Erkannt werden: 12-stellige UIC-Wagennummer, Gattung (z. B. Habbiins),
 * Laenge ueber Puffer ("15,74 m"), Gewichte ("23,5 t") und
 * Hoechstgeschwindigkeit ("120 km/h").
 */
export function parseWagonMarkings(rawText) {
    const text = String(rawText || '');
    const result = {
        wagonDigits: null,
        category: null,
        lengthMeters: null,
        weights: [],
        maxSpeed: null,
    };

    if (!text.trim()) {
        return result;
    }

    for (const match of text.matchAll(UIC_DIGIT_PATTERN)) {
        const digits = match[0].replace(/\D/g, '');

        if (digits.length >= 11) {
            result.wagonDigits = digits.slice(0, 12);
            break;
        }
    }

    // Gattungszeichen: Grossbuchstabe + Kleinbuchstabenfolge (Sgns, Eanos,
    // Habbiins). Bewusst konservativ, um Ortsnamen nicht mitzunehmen —
    // Woerter mit mehr als einem Grossbuchstaben oder Umlauten fallen raus.
    const categoryMatch = text.match(/(?:^|[\s:])([A-Z][a-z]{3,9})(?:$|[\s\d])/m);
    if (categoryMatch) {
        result.category = categoryMatch[1];
    }

    const lengthMatch = text.match(/(\d{1,2}[.,]\d{1,2})\s*m\b/i);
    if (lengthMatch) {
        result.lengthMeters = lengthMatch[1].replace('.', ',');
    }

    for (const match of text.matchAll(/(\d{1,3}[.,]\d)\s*t\b/gi)) {
        result.weights.push(match[1].replace('.', ','));
    }

    const speedMatch = text.match(/(\d{2,3})\s*km\s*\/?\s*h/i);
    if (speedMatch) {
        result.maxSpeed = speedMatch[1];
    }

    return result;
}

export function wagonVideoCapture(config = {}) {
    return {
        captureOpen: false,
        cameraError: '',
        cameraReady: false,
        recording: false,
        recordSeconds: 0,
        recordTimer: null,
        captureStream: null,
        captureRecorder: null,
        captureChunks: [],
        textDetector: null,
        scanTimer: null,
        scanBusy: false,
        recognitionLog: [],
        uploadCount: 0,
        uploadBusy: false,
        voiceActive: false,
        voiceRecognition: null,
        voiceTranscript: '',
        supportsTextDetection: typeof window !== 'undefined' && 'TextDetector' in window,
        supportsVoice: typeof window !== 'undefined'
            && Boolean(window.SpeechRecognition || window.webkitSpeechRecognition),

        /**
         * Zustand des umgebenden Wagenlisten-Editors. In Alpine sehen nur
         * DOM-Ausdruecke die Eltern-Scope-Kette — Methoden dieses Moduls
         * muessen den Editor explizit aufloesen.
         */
        editorScope() {
            // Nicht ueber closest(): der Vollbild-Editor ist an den <body>
            // teleportiert, im echten DOM liegt dieses Overlay also
            // AUSSERHALB der Komponentenwurzel. Pro Seite existiert genau
            // eine Wagenlisten-Komponente.
            const doc = this.$el?.ownerDocument || document;
            const root = doc.querySelector('[data-wagon-list-prototype]');
            if (!root) return null;

            try {
                return window.Alpine?.$data?.(root) || root._x_dataStack?.[0] || null;
            } catch (_) {
                return root._x_dataStack?.[0] || null;
            }
        },

        async openCapture() {
            this.captureOpen = true;
            this.cameraError = '';
            this.recognitionLog = [];
            // Der Editor prueft diese Klasse in handleEscape(): solange die
            // Video-Erfassung offen ist, gehoert Escape ihr.
            document.body?.classList.add('rt-wagon-capture-open');

            if (!navigator.mediaDevices?.getUserMedia) {
                this.cameraError = config.unsupportedText || 'Kamera wird nicht unterstützt.';
                return;
            }

            try {
                this.captureStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                    },
                    audio: true,
                });
            } catch (_) {
                this.cameraError = config.cameraBlockedText
                    || 'Kamera oder Mikrofon sind blockiert. Bitte über das Schloss-Symbol erlauben.';
                return;
            }

            if (!this.captureOpen) {
                this.releaseCaptureStream();
                return;
            }

            const video = this.$refs?.captureVideo;
            if (video) {
                video.srcObject = this.captureStream;
                video.play?.().catch(() => {});
            }

            this.cameraReady = true;
            this.startTextScanning();
        },

        closeCapture() {
            if (this.recording) {
                this.stopRecording();
            }

            this.stopTextScanning();
            this.stopVoice();
            this.releaseCaptureStream();
            this.cameraReady = false;
            this.captureOpen = false;
            document.body?.classList.remove('rt-wagon-capture-open');
        },

        releaseCaptureStream() {
            this.captureStream?.getTracks?.().forEach((track) => track.stop());
            this.captureStream = null;

            const video = this.$refs?.captureVideo;
            if (video) {
                video.srcObject = null;
            }
        },

        toggleRecording() {
            if (this.recording) {
                this.stopRecording();
                return;
            }

            if (!this.captureStream || typeof MediaRecorder === 'undefined') {
                return;
            }

            const preferredMime = [
                'video/webm;codecs=vp9,opus',
                'video/webm;codecs=vp8,opus',
                'video/webm',
                'video/mp4',
            ].find((mime) => MediaRecorder.isTypeSupported?.(mime));

            try {
                // Moderate Bitraten = kompakte Dateien direkt bei der
                // Aufnahme — bessere "Kompression" gibt es clientseitig nicht.
                this.captureRecorder = new MediaRecorder(this.captureStream, {
                    ...(preferredMime ? { mimeType: preferredMime } : {}),
                    videoBitsPerSecond: 2_000_000,
                    audioBitsPerSecond: 64_000,
                });
            } catch (_) {
                return;
            }

            this.captureChunks = [];
            this.captureRecorder.addEventListener('dataavailable', (event) => {
                if (event.data?.size > 0) {
                    this.captureChunks.push(event.data);
                }
            });
            this.captureRecorder.addEventListener('stop', () => this.finishRecording(), { once: true });
            this.captureRecorder.start(1000);
            this.recording = true;
            this.recordSeconds = 0;
            this.recordTimer = window.setInterval(() => {
                this.recordSeconds += 1;
            }, 1000);
        },

        stopRecording() {
            window.clearInterval(this.recordTimer);
            this.recordTimer = null;
            this.recording = false;
            this.captureRecorder?.state === 'recording' && this.captureRecorder.stop();
        },

        finishRecording() {
            if (this.captureChunks.length === 0) {
                return;
            }

            const mime = this.captureRecorder?.mimeType || 'video/webm';
            const blob = new Blob(this.captureChunks, { type: mime });
            this.captureChunks = [];
            this.uploadBlob(blob, 'video', mime.includes('mp4') ? 'mp4' : 'webm');
        },

        capturePhoto() {
            const video = this.$refs?.captureVideo;
            if (!video || !video.videoWidth) {
                return;
            }

            const canvas = document.createElement('canvas');
            canvas.width = Math.min(1600, video.videoWidth);
            canvas.height = Math.round(canvas.width * (video.videoHeight / video.videoWidth));
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(
                (blob) => blob && this.uploadBlob(blob, 'image', 'jpg'),
                'image/jpeg',
                0.72,
            );
            this.ping();
        },

        async uploadBlob(blob, kind, extension) {
            if (!config.uploadUrl || !blob) {
                return;
            }

            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const body = new FormData();
            body.append('draft_id', String(this.editorScope()?.activeDraftId || ''));
            body.append('kind', kind);
            body.append('file', new File(
                [blob],
                `wagenliste-${kind}-${Date.now()}.${extension}`,
                { type: blob.type },
            ));

            this.uploadBusy = true;

            try {
                const response = await fetch(config.uploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                    credentials: 'same-origin',
                    body,
                });

                if (!response.ok) {
                    throw new Error('upload failed');
                }

                this.uploadCount += 1;
                this.pushLog(config.savedLabel || 'Gespeichert', kind);
            } catch (_) {
                this.editorScope()?.notify?.(config.uploadErrorText || 'Aufnahme konnte nicht gespeichert werden.', 'error', 4200);
            } finally {
                this.uploadBusy = false;
            }
        },

        startTextScanning() {
            if (!this.supportsTextDetection || this.scanTimer) {
                return;
            }

            try {
                this.textDetector = new window.TextDetector();
            } catch (_) {
                this.supportsTextDetection = false;
                return;
            }

            this.scanTimer = window.setInterval(() => this.scanFrame(), 900);
        },

        stopTextScanning() {
            window.clearInterval(this.scanTimer);
            this.scanTimer = null;
            this.textDetector = null;
        },

        async scanFrame() {
            const video = this.$refs?.captureVideo;
            if (this.scanBusy || !this.textDetector || !video?.videoWidth) {
                return;
            }

            this.scanBusy = true;

            try {
                const detected = await this.textDetector.detect(video);
                const text = detected.map((entry) => entry.rawValue).join('\n');

                if (text.trim()) {
                    this.applyRecognition(parseWagonMarkings(text), 'camera');
                }
            } catch (_) {
                // Einzelne Fehlversuche sind normal (unscharfe Frames).
            } finally {
                this.scanBusy = false;
            }
        },

        /**
         * Erkannte Werte in den AKTUELLEN Wagen uebernehmen — niemals
         * ueberschreiben, nur leere Felder fuellen. Eine neue, vollstaendige
         * Wagennummer, die von der aktuellen abweicht, beginnt den naechsten
         * Wagen ("Neuer Wagen"-Ansage).
         */
        applyRecognition(parsed, source = 'camera') {
            const editor = this.editorScope();
            const wagons = editor?.wagons;
            const index = Number(editor?.mobileWagon) || 0;
            const wagon = wagons?.[index];

            if (!editor || !wagon || !parsed) {
                return;
            }

            const applied = [];

            if (parsed.wagonDigits) {
                const split = splitUicDigits(parsed.wagonDigits);
                const currentDigits = `${wagon.number12}${wagon.number34}${wagon.number58}${wagon.number911}`.replace(/\D/g, '');

                if (split && currentDigits.length >= 11 && !parsed.wagonDigits.startsWith(currentDigits)) {
                    // Anderer Wagen im Bild: naechsten Wagen aufmachen.
                    if (typeof editor.addWagon === 'function' && editor.visibleCount < 40) {
                        editor.addWagon();
                        this.announce(config.newWagonText || 'Neuer Wagen');
                        this.ping();
                        this.applyRecognition(parsed, source);
                        return;
                    }
                } else if (split && currentDigits.length < 11) {
                    wagon.number12 = split.number12;
                    wagon.number34 = split.number34;
                    wagon.number58 = split.number58;
                    wagon.number911 = split.number911;
                    if (split.checkDigit) {
                        wagon.checkDigit = split.checkDigit;
                    }
                    applied.push(config.wagonNumberLabel || 'Wagennummer');
                }
            }

            if (parsed.category && !wagon.category) {
                wagon.category = parsed.category;
                applied.push(config.categoryLabel || 'Gattung');
            }

            if (parsed.lengthMeters && !wagon.length) {
                wagon.length = parsed.lengthMeters;
                applied.push(config.lengthLabel || 'Länge');
            }

            if (parsed.weights.length > 0 && !wagon.wagonWeight) {
                wagon.wagonWeight = parsed.weights[0];
                applied.push(config.weightLabel || 'Eigengewicht');
            }

            if (parsed.weights.length > 1 && !wagon.loadWeight) {
                wagon.loadWeight = parsed.weights[1];
                applied.push(config.loadWeightLabel || 'Ladegewicht');
            }

            if (parsed.maxSpeed && !wagon.maxSpeed) {
                wagon.maxSpeed = parsed.maxSpeed;
                applied.push(config.speedLabel || 'Höchstgeschwindigkeit');
            }

            if (applied.length === 0) {
                return;
            }

            applied.forEach((label) => this.pushLog(label, source));
            this.ping();
            this.announce(`${applied.join(', ')} ${config.recognizedText || 'erkannt'}`);
            editor.schedulePersist?.();
        },

        pushLog(label, source) {
            this.recognitionLog = [
                { label, source, at: Date.now() },
                ...this.recognitionLog,
            ].slice(0, 24);
        },

        ping() {
            try {
                window.RTSound?.play?.(config.pingSound || 'success');
            } catch (_) {
                // Ton ist Komfort.
            }
        },

        announce(text) {
            if (!('speechSynthesis' in window) || !text) {
                return;
            }

            try {
                const utterance = new SpeechSynthesisUtterance(String(text));
                utterance.lang = config.speechLang || 'de-DE';
                utterance.rate = 1.15;
                utterance.volume = 0.9;
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(utterance);
            } catch (_) {
                // Ansage ist Komfort.
            }
        },

        toggleVoice() {
            if (this.voiceActive) {
                this.stopVoice();
                return;
            }

            const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!Recognition) {
                return;
            }

            this.voiceRecognition = new Recognition();
            this.voiceRecognition.lang = config.speechLang || 'de-DE';
            this.voiceRecognition.continuous = true;
            this.voiceRecognition.interimResults = false;
            this.voiceRecognition.addEventListener('result', (event) => {
                const transcript = Array.from(event.results)
                    .slice(event.resultIndex)
                    .map((result) => result[0]?.transcript || '')
                    .join(' ')
                    .trim();

                if (!transcript) {
                    return;
                }

                this.voiceTranscript = transcript;
                this.applyRecognition(parseWagonMarkings(transcript), 'voice');
            });
            this.voiceRecognition.addEventListener('end', () => {
                if (this.voiceActive) {
                    // Kontinuierlich weiterhoeren, bis bewusst gestoppt wird.
                    try {
                        this.voiceRecognition?.start();
                    } catch (_) {
                        this.voiceActive = false;
                    }
                }
            });

            try {
                this.voiceRecognition.start();
                this.voiceActive = true;
            } catch (_) {
                this.voiceRecognition = null;
            }
        },

        stopVoice() {
            this.voiceActive = false;
            try {
                this.voiceRecognition?.stop();
            } catch (_) {
                // bereits beendet
            }
            this.voiceRecognition = null;
            this.voiceTranscript = '';
        },

        formatRecordTime() {
            const minutes = Math.floor(this.recordSeconds / 60);
            const seconds = String(this.recordSeconds % 60).padStart(2, '0');

            return `${minutes}:${seconds}`;
        },

        destroy() {
            this.closeCapture();
        },
    };
}
