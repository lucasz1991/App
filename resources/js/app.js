import './bootstrap';
// Manuelles Livewire-Bundling (offizieller Livewire-3-Weg fuer eigene
// Alpine-Plugins/Stores): Livewire + Alpine aus dem Livewire-ESM-Bundle
// importieren, alles registrieren, DANN Livewire.start(). Die Layouts
// nutzen dafuer @livewireScriptConfig statt @livewireScripts.
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import collapse from '@alpinejs/collapse';
import mask from '@alpinejs/mask';
import resize from '@alpinejs/resize';
import intersect from '@alpinejs/intersect';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import Swiper from 'swiper';
import 'swiper/css';
// GSAP-Setup (window.gsap/ScrollTrigger + deklarative data-anim-Reveals)
import './gsap';

const loadAdminDashboardECharts = () => import('./admin-dashboard-echarts');

// ---------------------------------------------------------------
// Echtzeit (Laravel Reverb, Pusher-Protokoll). Nur aktiv, wenn ein
// Reverb-Key konfiguriert ist — ohne laufenden Reverb-Server faellt
// die App auf das 60s-Polling des Posteingangs zurueck.
// ---------------------------------------------------------------
window.Pusher = Pusher;

if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

// ACHTUNG: persist, sort und anchor bringt Livewires Alpine-Bundle bereits
// selbst mit — eine erneute Registrierung wirft "Cannot redefine property"
// in Livewire.start() und killt das restliche Modul (Sidebar-Init etc.).
Alpine.plugin(collapse);
Alpine.plugin(mask);
Alpine.plugin(resize);
Alpine.plugin(intersect);

// Zentraler Theme-Store: liest die Einstellung beim Start aus localStorage
// und schreibt sie beim Umschalten zurueck. Ueberlebt Reloads UND
// wire:navigate-Seitenwechsel (der Store lebt im Speicher weiter, Alpine
// bindet nach jeder Navigation neu).
Alpine.store('theme', {
    dark: localStorage.getItem('rt-theme') === 'true',

    toggle() {
        this.dark = !this.dark;
        localStorage.setItem('rt-theme', this.dark ? 'true' : 'false');
        rtApplyTheme();
    },
});

// Zentraler Sound-Store: spiegelt die RTSound-Einstellung (rt-sounds.js)
// fuer den Topbar-Schalter. RTSound ist die fuehrende Quelle und kuemmert
// sich um die Best-Effort-Persistenz — so koennen Icon-Zustand und echtes
// Abspielverhalten auch bei blockiertem Storage nie auseinanderlaufen.
// Beim Einschalten gibt ein kurzer Bestaetigungston hoerbares Feedback.
Alpine.store('sound', {
    enabled: window.RTSound ? window.RTSound.enabled : true,

    toggle() {
        this.enabled = window.RTSound ? window.RTSound.toggle() : !this.enabled;
        if (this.enabled) {
            window.RTSound?.play('success');
        }
    },
});

// Theme auf <html>/<body> anwenden. Noetig nach jeder wire:navigate-
// Navigation, weil Livewire dabei das <html>-Element (inkl. dark-Klasse)
// durch die serverseitig gerenderte Version ersetzt — dokumentiertes
// Livewire-Muster: im 'livewire:navigated'-Event erneut anwenden.
function rtApplyTheme() {
    const dark = Alpine.store('theme').dark;
    document.documentElement.classList.toggle('dark', dark);
    if (document.body) {
        document.body.setAttribute('data-mode', dark ? 'dark' : 'light');
    }
}

document.addEventListener('livewire:navigated', rtApplyTheme);

// ---------------------------------------------------------------
// Seitenwechsel-Overlay fuer wire:navigate: legt eine weiche, leicht
// unscharfe Ebene mit RailTime-Spinner ueber den Inhalt, damit ein
// Seitenwechsel sichtbar "laedt". Wird erst nach kurzer Verzoegerung
// gezeigt (kein Flackern bei vorab geladenen Seiten) und nach dem
// body-Swap bei Bedarf neu angehaengt.
// ---------------------------------------------------------------
(function () {
    let overlay = null;
    let showTimer = null;
    let active = false;

    function ensureOverlay() {
        // Livewire tauscht bei wire:navigate den <body> aus -> ggf. neu anhaengen.
        if (overlay && document.body.contains(overlay)) {
            return overlay;
        }
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'rt-nav-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.innerHTML = '<div class="rt-nav-spinner"></div>';
            overlay.style.cssText = [
                'position:fixed', 'inset:0', 'z-index:190',
                'display:flex', 'align-items:center', 'justify-content:center',
                'opacity:0', 'pointer-events:none',
                'backdrop-filter:blur(2px)', '-webkit-backdrop-filter:blur(2px)',
                'transition:opacity .2s ease',
            ].join(';');
        }
        document.body.appendChild(overlay);
        return overlay;
    }

    function start() {
        active = true;
        clearTimeout(showTimer);
        showTimer = setTimeout(function () {
            if (!active) return;
            const o = ensureOverlay();
            const dark = document.documentElement.classList.contains('dark');
            o.style.background = dark ? 'rgba(11,17,32,.55)' : 'rgba(243,246,250,.5)';
            o.style.opacity = '1';
        }, 120);
    }

    function done() {
        active = false;
        clearTimeout(showTimer);
        if (overlay) {
            overlay.style.opacity = '0';
        }
    }

    document.addEventListener('livewire:navigate', start);
    document.addEventListener('livewire:navigating', start);
    document.addEventListener('livewire:navigated', done);
})();

window.Alpine = Alpine;

Alpine.data('chatRealtime', (config) => ({
    channel: null,
    typingLabel: '',
    typingTimer: null,
    recorder: null,
    recording: false,
    recordingSeconds: 0,
    recordingLabel: '',
    recordingTimer: null,
    recordingStream: null,
    recordingIntent: null,
    sendingVoice: false,
    viewOnce: false,
    chunks: [],

    init() {
        this.recordingLabel = '0:00';

        // Aktiven Chat global markieren: der User-Channel-Listener und der
        // Polling-Fallback unterdruecken damit den Nachrichtenton fuer den
        // gerade sichtbaren Chat (die Nachricht erscheint dort ohnehin sofort).
        window.__rtOpenChatId = config.chatId ? Number(config.chatId) : null;

        if (!window.Echo || !config.chatId) {
            return;
        }

        this.channel = window.Echo.private(`chat.${config.chatId}`)
            .listen('.chat.message.sent', (event) => {
                Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
                Livewire.dispatch('inbox:refresh');
            })
            .listen('.chat.message.deleted', (event) => {
                Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
                Livewire.dispatch('inbox:refresh');
            })
            .listen('.chat.read', (event) => {
                Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
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
        window.clearTimeout(this.typingTimer);
        window.clearInterval(this.recordingTimer);
        if (this.recorder?.state === 'recording') {
            this.recordingIntent = 'cancel';
            this.recorder.stop();
        }
        this.stopRecordingTracks();

        // Marker nur zuruecksetzen, wenn nicht schon ein neuer Chat-Wechsel
        // (init der Folge-Instanz) ihn ueberschrieben hat.
        if (window.__rtOpenChatId === (config.chatId ? Number(config.chatId) : null)) {
            window.__rtOpenChatId = null;
        }

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
            this.recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const preferredMime = [
                'audio/webm;codecs=opus',
                'audio/ogg;codecs=opus',
                'audio/mp4',
            ].find((mime) => MediaRecorder.isTypeSupported(mime));

            this.chunks = [];
            this.recorder = preferredMime
                ? new MediaRecorder(this.recordingStream, { mimeType: preferredMime })
                : new MediaRecorder(this.recordingStream);

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
            this.stopRecordingTracks();
            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'error', text: config.microphoneErrorText || 'Das Mikrofon konnte nicht verwendet werden.' },
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
        this.stopRecordingTracks();

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
                this.$wire.call('sendVoice', this.viewOnce, recordedDuration)
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
        this.stopRecordingTracks();
        this.recording = false;
        this.sendingVoice = false;
        this.recordingIntent = null;
        this.recordingSeconds = 0;
        this.recordingLabel = '0:00';
        this.chunks = [];
        this.recorder = null;
        this.viewOnce = false;
    },

    stopRecordingTracks() {
        this.recordingStream?.getTracks().forEach((track) => track.stop());
        this.recordingStream = null;
    },

    updateRecordingLabel() {
        const minutes = Math.floor(this.recordingSeconds / 60);
        const seconds = String(this.recordingSeconds % 60).padStart(2, '0');
        this.recordingLabel = `${minutes}:${seconds}`;
    },
}));

Alpine.data('chatAudioPlayer', (config = {}) => ({
    messageId: Number(config.messageId || 0),
    sourceUrl: config.sourceUrl || '',
    viewOnce: Boolean(config.viewOnce),
    consumed: Boolean(config.consumed),
    loading: false,
    playing: false,
    currentTime: 0,
    duration: Math.max(0, Number(config.durationHint || 0)),
    progressFrame: null,
    waveformPattern: [8, 15, 11, 20, 13, 24, 17, 10, 22, 14, 26, 18, 12, 21, 9, 17, 25, 14, 20, 11, 23, 16, 10, 19, 13, 22, 15, 9, 18, 25, 12, 20, 15, 27, 11, 18, 23, 14, 21, 9, 17, 24, 13, 19, 26, 12, 18, 10],

    get waveform() {
        const barCount = this.duration > 0
            ? Math.max(20, Math.min(this.waveformPattern.length, Math.round(20 + (this.duration / 4))))
            : 28;

        return this.waveformPattern.slice(0, barCount);
    },

    get progress() {
        return this.duration > 0 ? Math.min(100, (this.currentTime / this.duration) * 100) : 0;
    },

    get formattedTime() {
        const value = this.playing || this.currentTime > 0 ? this.currentTime : this.duration;
        const safeValue = Number.isFinite(value) ? Math.max(0, value) : 0;
        const minutes = Math.floor(safeValue / 60);
        const seconds = String(Math.floor(safeValue % 60)).padStart(2, '0');

        return `${minutes}:${seconds}`;
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

            this.$refs.audio.play().catch(() => {
                this.playing = false;
            });
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
        this.$nextTick(() => {
            this.$refs.audio.load();
            this.$refs.audio.play().catch(() => {
                this.playing = false;
            });
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
    },
}));

Alpine.data('chatPaneNavigation', (initialHasSelection = false) => ({
    mobilePane: initialHasSelection ? 'chat' : 'list',
    listCollapsed: localStorage.getItem('rt-chat-list-collapsed') === 'true',
    touchStartX: null,
    touchStartY: null,

    showList() {
        this.mobilePane = 'list';
    },

    showChat() {
        this.mobilePane = 'chat';
    },

    resumeLastChat() {
        if (this.$root.dataset.hasSelectedChat === 'true') {
            this.showChat();
        }
    },

    toggleList() {
        this.listCollapsed = !this.listCollapsed;

        try {
            localStorage.setItem('rt-chat-list-collapsed', this.listCollapsed ? 'true' : 'false');
        } catch (_) {
            // Die Navigation funktioniert auch, wenn der Browser Storage sperrt.
        }
    },

    touchStart(event) {
        if (window.innerWidth >= 768 || event.touches.length !== 1) {
            this.cancelSwipe();
            return;
        }

        if (event.target.closest('input, textarea, select, button, audio, video, [role="dialog"], [data-no-chat-swipe]')) {
            this.cancelSwipe();
            return;
        }

        this.touchStartX = event.touches[0].clientX;
        this.touchStartY = event.touches[0].clientY;
    },

    touchEnd(event) {
        if (window.innerWidth >= 768 || this.touchStartX === null || event.changedTouches.length !== 1) {
            this.cancelSwipe();
            return;
        }

        const deltaX = event.changedTouches[0].clientX - this.touchStartX;
        const deltaY = event.changedTouches[0].clientY - this.touchStartY;
        const threshold = Math.max(72, Math.min(140, window.innerWidth * 0.22));

        if (Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.25) {
            if (deltaX > 0 && this.mobilePane === 'chat') {
                this.showList();
            } else if (deltaX < 0 && this.mobilePane === 'list') {
                this.resumeLastChat();
            }
        }

        this.cancelSwipe();
    },

    cancelSwipe() {
        this.touchStartX = null;
        this.touchStartY = null;
    },
}));

Alpine.data('adminDashboardCharts', (config = {}) => ({
    charts: [],
    kpiMotion: null,
    kpiObserver: null,
    counterTween: null,
    progressTween: null,
    themeObserver: null,
    resizeObserver: null,
    renderTimer: null,
    renderRequest: null,
    chartsRendered: false,

    init() {
        this.$nextTick(() => {
            this.observeKpis();
            window.requestAnimationFrame(() => this.renderCharts(true));
        });

        this.themeObserver = new MutationObserver(() => {
            window.clearTimeout(this.renderTimer);
            this.renderTimer = window.setTimeout(() => this.renderCharts(!this.chartsRendered), 80);
        });
        this.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    },

    destroy() {
        this.renderRequest = null;
        window.clearTimeout(this.renderTimer);
        this.themeObserver?.disconnect();
        this.kpiObserver?.disconnect();
        this.resizeObserver?.disconnect();
        this.counterTween?.kill();
        this.progressTween?.kill();
        this.kpiMotion?.revert();
        this.destroyCharts();
    },

    observeKpis() {
        const kpiGrid = this.$root.querySelector('[data-dashboard-kpis]');

        if (!kpiGrid) return;

        const start = () => {
            this.kpiObserver?.disconnect();
            this.kpiObserver = null;
            window.requestAnimationFrame(() => {
                if (this.$root.isConnected) this.animateCounters(kpiGrid);
            });
        };

        if (
            typeof IntersectionObserver === 'undefined'
            || window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            start();
            return;
        }

        this.kpiObserver = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) start();
            },
            { threshold: 0.18, rootMargin: '0px 0px -6% 0px' },
        );
        this.kpiObserver.observe(kpiGrid);
    },

    animateCounters(kpiGrid) {
        const formatter = new Intl.NumberFormat(document.documentElement.lang || 'de-DE');
        const counters = Array.from(kpiGrid.querySelectorAll('[data-dashboard-count]'))
            .map((element) => ({
                element,
                target: Number(element.dataset.dashboardCount),
                rendered: null,
            }))
            .filter((counter) => Number.isFinite(counter.target));
        const progress = kpiGrid.querySelector('[data-dashboard-progress]');
        const progressTarget = Math.min(100, Math.max(0, Number(progress?.dataset.dashboardProgress || 0))) / 100;

        const renderFinalState = () => {
            counters.forEach((counter) => {
                counter.element.textContent = formatter.format(counter.target);
            });

            if (progress) {
                if (window.gsap) {
                    window.gsap.set(progress, { scaleX: progressTarget, transformOrigin: 'left center' });
                } else {
                    progress.style.transform = `scaleX(${progressTarget})`;
                    progress.style.transformOrigin = 'left center';
                }
            }
        };

        this.counterTween?.kill();
        this.progressTween?.kill();
        this.kpiMotion?.revert();

        if (!window.gsap) {
            renderFinalState();
            return;
        }

        this.kpiMotion = window.gsap.matchMedia();
        this.kpiMotion.add(
            {
                reduceMotion: '(prefers-reduced-motion: reduce)',
                animateMotion: '(prefers-reduced-motion: no-preference)',
            },
            ({ conditions }) => {
                if (conditions.reduceMotion) {
                    renderFinalState();
                    return;
                }

                counters.forEach((counter) => {
                    counter.rendered = 0;
                    counter.element.textContent = formatter.format(0);
                });

                const state = { progress: 0 };

                this.counterTween = window.gsap.to(state, {
                    progress: 1,
                    duration: 0.9,
                    ease: 'power3.out',
                    overwrite: true,
                    onUpdate: () => {
                        counters.forEach((counter) => {
                            const nextValue = Math.round(counter.target * state.progress);
                            if (nextValue === counter.rendered) return;

                            counter.rendered = nextValue;
                            counter.element.textContent = formatter.format(nextValue);
                        });
                    },
                    onComplete: renderFinalState,
                });

                if (progress) {
                    this.progressTween = window.gsap.fromTo(
                        progress,
                        { scaleX: 0, transformOrigin: 'left center' },
                        {
                            scaleX: progressTarget,
                            duration: 1.05,
                            ease: 'power3.out',
                            overwrite: 'auto',
                        },
                    );
                }
            },
            this.$root,
        );
    },

    destroyCharts() {
        this.resizeObserver?.disconnect();
        this.charts.forEach((chart) => chart.dispose());
        this.charts = [];
    },

    async renderCharts(animate = !this.chartsRendered) {
        const request = Symbol('admin-dashboard-chart-render');
        this.renderRequest = request;

        const { renderAdminDashboardCharts } = await loadAdminDashboardECharts();

        if (this.renderRequest !== request || !this.$root.isConnected) return;

        this.destroyCharts();

        const rendered = renderAdminDashboardCharts({
            refs: {
                growthChart: this.$refs.growthChart,
                statusChart: this.$refs.statusChart,
                activityChart: this.$refs.activityChart,
            },
            config,
            dark: document.documentElement.classList.contains('dark'),
            animate,
        });

        this.charts = rendered.charts;
        this.resizeObserver = rendered.resizeObserver;
        this.chartsRendered = true;
    },
}));

// ---------------------------------------------------------------
// Fehlerton bei Validierungsfehlern: Livewire fuehrt den Fehler-Bag im
// Snapshot-memo mit. Nach jedem Commit mit echtem Action-Aufruf (Button/
// Submit, kein reines wire:model-Sync und kein Event-Dispatch) wird der
// Fehler-Bag mit dem Stand vor dem Request verglichen. Der Ton spielt nur,
// wenn ein Fehler-Key hinzukommt oder sich seine Messages aendern — nicht,
// wenn alte Fehler unveraendert weitergereicht werden (z.B. Modal schliessen
// nach fehlgeschlagenem Save) oder der Bag nur schrumpft (partielles
// resetValidation).
// Bekannte Grenze: Die Signatur unterscheidet nicht zwischen "Fehler
// unveraendert weitergereicht" und "identische Fehler neu erhoben" — ein
// unveraendert wiederholter fehlschlagender Submit bleibt daher lautlos,
// solange der persistierte Fehler-Bag der neuen Signatur gleicht. Bewusst
// akzeptiert: Die Inline-Fehler bleiben sichtbar, der erste Fehlschlag war
// hoerbar, und jede Teil-Korrektur mit anderem Fehlerbild toent wieder.
// ---------------------------------------------------------------
function rtErrorBag(snapshot) {
    try {
        const parsed = typeof snapshot === 'string' ? JSON.parse(snapshot) : snapshot;
        const errors = parsed?.memo?.errors;

        if (!errors || typeof errors !== 'object') {
            return {};
        }

        const bag = {};
        Object.keys(errors).forEach((key) => {
            bag[key] = JSON.stringify(errors[key]);
        });

        return bag;
    } catch (_) {
        return {};
    }
}

Livewire.hook('commit', ({ component, commit, succeed }) => {
    const hasUserCall = (commit?.calls || []).some(
        (call) => call?.method && call.method !== '__dispatch' && call.method !== '$refresh',
    );

    if (!hasUserCall) {
        return;
    }

    const previousErrors = rtErrorBag(component?.snapshot ?? component?.snapshotEncoded);

    succeed(({ snapshot }) => {
        const nextErrors = rtErrorBag(snapshot);
        const grew = Object.keys(nextErrors).some(
            (key) => previousErrors[key] === undefined || previousErrors[key] !== nextErrors[key],
        );

        if (grew) {
            window.RTSound?.play('error');
        }
    });
});

Livewire.start();

rtApplyTheme();

// ---------------------------------------------------------------
// Live-Benachrichtigungen: privaten User-Channel abonnieren.
// Bei neuer Nachricht: Toast anzeigen + Posteingang aktualisieren.
// Das Abo ueberlebt wire:navigate (Modul laeuft nur einmal).
// ---------------------------------------------------------------
(function () {
    const userId = document.querySelector('meta[name="rt-user-id"]')?.content;
    if (!window.Echo || !userId) {
        return;
    }

    const lang = window.rtLang || {};

    window.Echo.private(`App.Models.User.${userId}`)
        .listen('.message.received', (event) => {
            const title = lang.newMessage || 'Neue Nachricht';
            const from = event.from ? `${lang.from || 'Von'}: ${event.from}` : '';
            const text = [from, event.subject].filter(Boolean).join(' — ');

            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'info', title, text, sound: 'message' },
            }));

            Livewire.dispatch('inbox:refresh');
        })
        .listen('.chat.message.received', (event) => {
            const title = lang.newChatMessage || 'Neue Chatnachricht';
            const text = event.from ? `${lang.from || 'Von'}: ${event.from}` : '';

            // Der aktiv sichtbare Chat zeigt die Nachricht sofort selbst an —
            // dafuer keinen Ton spielen (der Toast bleibt als dezenter Hinweis).
            const isOpenChat = Number(event.chatId) === window.__rtOpenChatId
                && document.hasFocus()
                && !document.hidden;

            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { type: 'info', title, text, sound: isOpenChat ? false : 'message' },
            }));

            Livewire.dispatch('chat:refresh', { chatId: Number(event.chatId) });
            Livewire.dispatch('inbox:refresh');
        });
})();

// ---------------------------------------------------------------
// Weitere Sound-Ausloeser (Modul laeuft nur einmal, Listener ueberleben
// wire:navigate):
// - 'saved': Jetstream-Profilformulare melden Erfolg ohne Toast.
// - 'rt:inbox-increased': HeaderInbox meldet neue Nachrichten ueber das
//   60s-Polling. Nur relevant, wenn kein Echo/Reverb laeuft — mit Echtzeit-
//   Verbindung klingelt bereits der Toast des User-Channels.
// ---------------------------------------------------------------
window.addEventListener('saved', () => {
    window.RTSound?.play('success');
});

window.addEventListener('rt:inbox-increased', (event) => {
    // Nur der tatsaechlich verbundene Echtzeit-Kanal ersetzt den Polling-Ton —
    // ein konfigurierter, aber nicht erreichbarer Reverb-Server darf die
    // Benachrichtigung nicht verschlucken.
    if (window.Echo?.connector?.pusher?.connection?.state === 'connected') {
        return;
    }

    // Reiner Chat-Anstieg, waehrend die Chat-Seite sichtbar im Fokus ist:
    // Der 5s-pollTick zeigt die Nachricht dort gerade selbst an — der 60s-
    // Posteingangs-Poll darf dann nicht nachtraeglich klingeln.
    const source = event.detail?.source || 'both';
    if (
        source === 'chat'
        && window.__rtOpenChatId != null
        && document.hasFocus()
        && !document.hidden
    ) {
        return;
    }

    window.RTSound?.play('message');
});

window.Swiper = Swiper;
let sidebarCollapseTimer = null;

function initMetisMenu() {
    if (!window.MetisMenu) {
        return;
    }

    const sideMenu = document.getElementById('side-menu');
    if (!sideMenu) {
        return;
    }

    if (window.__webreachMetisMenu && typeof window.__webreachMetisMenu.dispose === 'function') {
        window.__webreachMetisMenu.dispose();
    }

    window.__webreachMetisMenu = new window.MetisMenu('#side-menu');
}

function clearSidebarCollapseTimer() {
    if (sidebarCollapseTimer) {
        window.clearTimeout(sidebarCollapseTimer);
        sidebarCollapseTimer = null;
    }
}

function isDesktopHoverSidebar() {
    return window.innerWidth >= 1140 && Boolean(document.querySelector('.vertical-menu'));
}

function isSidebarHoveredOrFocused() {
    const activeElement = document.activeElement;
    const hoverInsideSidebar = document.querySelector('.vertical-menu:hover, .topbar-brand:hover');
    const focusInsideSidebar = activeElement?.closest('.vertical-menu, .topbar-brand');

    return Boolean(hoverInsideSidebar || focusInsideSidebar);
}

function setDesktopSidebarExpanded(expanded) {
    if (document.body.getAttribute('data-sidebar-collapsible') !== 'true') {
        return;
    }

    document.body.setAttribute('data-sidebar-expanded', expanded ? 'true' : 'false');
    syncSidebarToggleState();
}

function setMobileSidebarOpen(open) {
    document.body.classList.toggle('sidebar-enable', open);
    syncSidebarToggleState();
}

function syncSidebarToggleState() {
    const expanded = isDesktopHoverSidebar()
        ? document.body.getAttribute('data-sidebar-expanded') === 'true'
        : document.body.classList.contains('sidebar-enable');

    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    });
}

function scheduleDesktopSidebarCollapse() {
    clearSidebarCollapseTimer();

    sidebarCollapseTimer = window.setTimeout(() => {
        const activeElement = document.activeElement;
        const focusInsideSidebar = activeElement?.closest('.vertical-menu, .topbar-brand');
        const hoverInsideSidebar = document.querySelector('.vertical-menu:hover, .topbar-brand:hover');

        if (!focusInsideSidebar && !hoverInsideSidebar) {
            setDesktopSidebarExpanded(false);
        }
    }, 90);
}

function syncSidebarInteractionMode() {
    const hasSidebar = Boolean(document.querySelector('.vertical-menu'));
    if (!hasSidebar) {
        return;
    }

    const desktopMode = isDesktopHoverSidebar();
    document.body.setAttribute('data-sidebar-collapsible', desktopMode ? 'true' : 'false');

    if (desktopMode) {
        setMobileSidebarOpen(false);

        const isExpanded = document.body.getAttribute('data-sidebar-expanded') === 'true';
        const shouldStayExpanded = isExpanded || isSidebarHoveredOrFocused();

        document.body.setAttribute('data-sidebar-expanded', shouldStayExpanded ? 'true' : 'false');
        syncSidebarToggleState();
        return;
    }

    clearSidebarCollapseTimer();
    document.body.setAttribute('data-sidebar-expanded', 'false');
    syncSidebarToggleState();
}

function initLeftMenuCollapse() {
    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        if (button.dataset.webreachBound === '1') {
            return;
        }

        button.dataset.webreachBound = '1';

        button.addEventListener('click', (event) => {
            event.preventDefault();

            if (isDesktopHoverSidebar()) {
                clearSidebarCollapseTimer();
                setDesktopSidebarExpanded(document.body.getAttribute('data-sidebar-expanded') !== 'true');
                return;
            }

            setMobileSidebarOpen(!document.body.classList.contains('sidebar-enable'));
            initMenuItemScroll();
        });
    });
}

function initActiveMenu() {
    const pageUrl = window.location.href.split(/[?#]/)[0];
    const menuItems = Array.from(document.querySelectorAll('#sidebar-menu a'));
    const nestedLists = document.querySelectorAll('#sidebar-menu ul');

    menuItems.forEach((item) => item.classList.remove('active'));
    document.querySelectorAll('#sidebar-menu li.mm-active').forEach((item) => item.classList.remove('mm-active'));
    nestedLists.forEach((list) => {
        if (list.id !== 'side-menu') {
            list.classList.remove('mm-show');
        }
    });

    const exactMatches = menuItems.filter((item) => item.href === pageUrl);
    const fallbackMatches = menuItems.filter((item) => item.dataset.menuActive === 'true');
    const activeItems = exactMatches.length > 0 ? exactMatches : fallbackMatches;

    activeItems.forEach((item) => {
        item.classList.add('active');

        let currentLi = item.closest('li');
        while (currentLi) {
            currentLi.classList.add('mm-active');

            const parentUl = currentLi.parentElement;
            if (parentUl && parentUl.tagName === 'UL' && parentUl.id !== 'side-menu') {
                parentUl.classList.add('mm-show');
            }

            currentLi = parentUl ? parentUl.closest('li') : null;
        }
    });
}

function initMenuItemScroll() {
    setTimeout(() => {
        const sidebarMenu = document.getElementById('side-menu');
        const activeItem = sidebarMenu?.querySelector('.mm-active .active');

        if (!activeItem || activeItem.offsetTop <= 300) {
            return;
        }

        const verticalMenu = document.querySelector('.vertical-menu');
        const scroller = verticalMenu?.querySelector('.simplebar-content-wrapper');

        if (scroller) {
            scroller.scrollTop = activeItem.offsetTop;
        }
    }, 150);
}

function initFeather() {
    if (window.feather && typeof window.feather.replace === 'function') {
        window.feather.replace();
    }
}

function initSidebarInteractions() {
    if (document.body.dataset.webreachSidebarInteractionsBound !== '1') {
        document.body.dataset.webreachSidebarInteractionsBound = '1';

        document.querySelectorAll('.vertical-menu, .topbar-brand').forEach((element) => {
            if (element.dataset.webreachSidebarHoverBound === '1') {
                return;
            }

            element.dataset.webreachSidebarHoverBound = '1';

            element.addEventListener('mouseenter', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                clearSidebarCollapseTimer();
                setDesktopSidebarExpanded(true);
            });

            element.addEventListener('mouseleave', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                scheduleDesktopSidebarCollapse();
            });

            element.addEventListener('focusin', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                clearSidebarCollapseTimer();
                setDesktopSidebarExpanded(true);
            });

            element.addEventListener('focusout', () => {
                if (!isDesktopHoverSidebar()) {
                    return;
                }

                scheduleDesktopSidebarCollapse();
            });
        });

        document.addEventListener(
            'pointerdown',
            (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (isDesktopHoverSidebar()) {
                    if (!target || !target.closest('.vertical-menu, .topbar-brand')) {
                        clearSidebarCollapseTimer();
                        setDesktopSidebarExpanded(false);
                    }

                    return;
                }

                if (!target || !target.closest('.vertical-menu, .vertical-menu-btn')) {
                    setMobileSidebarOpen(false);
                }
            },
            true
        );

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            clearSidebarCollapseTimer();
            setDesktopSidebarExpanded(false);
            setMobileSidebarOpen(false);
        });

        window.addEventListener('resize', syncSidebarInteractionMode);
    }

    syncSidebarInteractionMode();
}

function initAdminLayout() {
    syncSidebarInteractionMode();
    initMetisMenu();
    initLeftMenuCollapse();
    initSidebarInteractions();
    initActiveMenu();
    initMenuItemScroll();
    initFeather();
}

document.addEventListener('DOMContentLoaded', initAdminLayout);
document.addEventListener('livewire:load', initAdminLayout);
document.addEventListener('livewire:navigated', initAdminLayout);
