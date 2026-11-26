export const chatAudioPlayer = (config = {}) => ({
    messageId: Number(config.messageId || 0),
    sourceUrl: config.sourceUrl || '',
    viewOnce: Boolean(config.viewOnce),
    consumed: Boolean(config.consumed),
    loading: false,
    playing: false,
    playbackBlocked: false,
    currentTime: 0,
    duration: Math.max(0, Number(config.durationHint || 0)),
    progressFrame: null,
    // Bei der Aufnahme erfasste Pegel (0-100). Leer bei Bestandsnachrichten
    // aus der Zeit vor der Pegelmessung.
    recordedWaveform: Array.isArray(config.waveform)
        ? config.waveform.map((value) => Math.max(0, Math.min(100, Number(value) || 0)))
        : [],

    get waveform() {
        // Feine Aufloesung: viele schmale Balken (44-64), laengere Nachricht
        // = mehr Balken. Die Breite je Balken ergibt sich aus flex 1 1 —
        // mehr Balken auf gleicher Flaeche heisst automatisch duenner.
        const barCount = this.duration > 0
            ? Math.max(44, Math.min(64, Math.round(32 + (this.duration / 2))))
            : 52;

        // Echte Pegel auf die Balkenzahl bringen; Hoehe 4-30 px.
        if (this.recordedWaveform.length >= 4) {
            return Array.from({ length: barCount }, (_, index) => {
                const position = (index * (this.recordedWaveform.length - 1)) / Math.max(1, barCount - 1);
                const value = this.recordedWaveform[Math.round(position)] ?? 0;

                return 4 + Math.round((value / 100) * 26);
            });
        }

        // Bestandsnachrichten ohne Pegel: deterministisch aus der Nachrichts-
        // nummer gewuerfelt — jede Nachricht sieht anders aus, dieselbe aber
        // bei jedem Rendern gleich.
        let seed = (this.messageId || 1) * 2654435761 % 4294967296;

        return Array.from({ length: barCount }, () => {
            seed = (seed * 1103515245 + 12345) % 2147483648;

            return 5 + (seed % 23);
        });
    },

    get progress() {
        return this.duration > 0 ? Math.min(100, (this.currentTime / this.duration) * 100) : 0;
    },

    get formattedTime() {
        const format = (seconds) => {
            const whole = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
            return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
        };

        const elapsed = format(this.currentTime);
        return this.duration > 0 && Number.isFinite(this.duration)
            ? `${elapsed} / ${format(this.duration)}`
            : elapsed;
    },

    toggle() {
        if (this.consumed || this.loading) {
            return;
        }

        if (!this.sourceUrl) {
            this.loading = true;
            this.$wire.call('requestVoicePlayback', this.messageId)
                .catch(() => {
                    this.loading = false;
                });
            return;
        }

        if (this.$refs.audio.paused) {
            if (this.duration > 0 && this.currentTime >= this.duration - 0.05) {
                this.$refs.audio.currentTime = 0;
                this.currentTime = 0;
            }

            this.play();
            return;
        }

        this.$refs.audio.pause();
    },

    acceptSource(detail) {
        if (Number(detail?.messageId) !== this.messageId) {
            return;
        }

        this.sourceUrl = detail.url || '';
        this.viewOnce = Boolean(detail.viewOnce);
        this.loading = false;
        this.playbackBlocked = false;
        this.$nextTick(() => {
            this.$refs.audio.load();
            this.play();
        });
    },

    play() {
        const playback = this.$refs.audio?.play();

        if (!playback?.catch) {
            return;
        }

        playback.catch(() => {
            // Safari kann die Benutzeraktivierung verlieren, waehrend Livewire
            // den Einmal-Token anfordert. Die Quelle bleibt deshalb erhalten:
            // der naechste direkte Klick startet dann ohne einen zweiten Request.
            this.playing = false;
            this.loading = false;
            this.playbackBlocked = true;
        });
    },

    markConsumed(detail) {
        if (Number(detail?.messageId) !== this.messageId) {
            return;
        }

        this.loading = false;
        this.playing = false;
        this.stopProgressAnimation();
        this.consumed = true;
        this.sourceUrl = '';
    },

    metadataLoaded() {
        const mediaDuration = this.$refs.audio.duration;

        if (Number.isFinite(mediaDuration) && mediaDuration > 0) {
            this.duration = mediaDuration;
        }
    },

    timeUpdated() {
        this.currentTime = this.$refs.audio.currentTime || 0;
    },

    playbackStarted() {
        this.loading = false;
        this.playbackBlocked = false;
        this.playing = true;
        this.startProgressAnimation();
    },

    playbackPaused() {
        this.playing = false;
        this.stopProgressAnimation();
    },

    startProgressAnimation() {
        this.stopProgressAnimation();

        const syncProgress = () => {
            if (!this.$refs.audio || this.$refs.audio.paused || this.$refs.audio.ended) {
                this.stopProgressAnimation();
                return;
            }

            this.currentTime = Math.min(
                this.duration || this.$refs.audio.currentTime,
                this.$refs.audio.currentTime || 0
            );
            this.progressFrame = window.requestAnimationFrame(syncProgress);
        };

        this.progressFrame = window.requestAnimationFrame(syncProgress);
    },

    stopProgressAnimation() {
        if (this.progressFrame !== null) {
            window.cancelAnimationFrame(this.progressFrame);
            this.progressFrame = null;
        }
    },

    seek(value) {
        if (this.consumed || !this.sourceUrl) {
            return;
        }

        const nextTime = Math.max(0, Math.min(Number(value) || 0, this.duration || 0));
        this.$refs.audio.currentTime = nextTime;
        this.currentTime = nextTime;
    },

    ended() {
        this.playing = false;
        this.stopProgressAnimation();
        this.currentTime = this.duration;

        if (this.viewOnce) {
            this.consumed = true;
            this.sourceUrl = '';
            this.$refs.audio.removeAttribute('src');
            this.$refs.audio.load();
            this.$wire.call('finishVoicePlayback', this.messageId);
            return;
        }
    },

    destroy() {
        this.stopProgressAnimation();
        this.$refs.audio?.pause();
    },
});
