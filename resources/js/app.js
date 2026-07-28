// ZUERST importieren: das Modul installiert seine Fehler-Listener direkt beim
// Import, also bevor die folgenden Module ausgewertet werden. Ein Fehler in
// einem dieser Module wird dadurch bereits erfasst (siehe alpine-watchdog.js).
import './alpine-watchdog';
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
// GSAP-Setup (window.gsap/ScrollTrigger + deklarative data-anim-Reveals)
import './gsap';
// Vengeance-Motion (zeigergefuehrter Karten-Glow, Optik in app.css)
import './vengeance-motion';
import { wagonListPrototype } from './wagon-list-prototype';
import { numberInput } from './number-input';
import {
    registerRailtimePwaInstall,
    registerRailtimePushSettings,
    setupRailtimePwa,
} from './pwa';
import { createNotificationSeenCache } from './notification-seen-cache';
import { createNotificationPresentationContext } from './notification-presentation';
import { incomingNotificationSound } from './realtime-notification-sound';
import { sidebarScrollBehavior, sidebarScrollTarget } from './sidebar-scroll';
import { railtimeTabs } from './tabs';

const loadAdminDashboardECharts = () => import('./admin-dashboard-echarts');
const rtSeenNotifications = createNotificationSeenCache(window);
const rtNotificationContext = createNotificationPresentationContext(window);
let rtForegroundPushHandler = null;

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'railtime:push-context-request') {
            if (document.visibilityState !== 'visible') {
                return;
            }

            const contextPort = event.ports?.[0];
            const snapshot = rtNotificationContext.publish();

            contextPort?.postMessage({
                type: 'railtime:push-context-ack',
                notification_id: event.data.notification_id,
                focused: snapshot.focused,
                active_chat_id: snapshot.activeChatId,
            });
            contextPort?.close();

            return;
        }

        if (event.data?.type !== 'railtime:push-received') {
            return;
        }

        if (
            !rtForegroundPushHandler
            || document.visibilityState !== 'visible'
        ) {
            return;
        }

        try {
            rtForegroundPushHandler(event.data);

            const acknowledgementPort = event.ports?.[0];
            acknowledgementPort?.postMessage({
                type: 'railtime:push-received-ack',
                notification_id: event.data.notification_id,
            });
            acknowledgementPort?.close();
        } catch (_) {
            // Ohne erfolgreiche Verarbeitung und ACK zeigt der Service Worker den OS-Push.
        }
    });
}

// ---------------------------------------------------------------
// Echtzeit (Laravel Reverb, Pusher-Protokoll). Nur aktiv, wenn ein
// Reverb-Key konfiguriert ist — ohne laufenden Reverb-Server faellt
// die App auf das 60s-Polling des Posteingangs zurueck.
// ---------------------------------------------------------------
window.Pusher = Pusher;

if (import.meta.env.VITE_REVERB_APP_KEY) {
    const configuredHost = String(import.meta.env.VITE_REVERB_HOST || '').trim();
    const browserHost = window.location.hostname;
    const loopbackHosts = new Set(['localhost', '127.0.0.1', '::1', '[::1]']);

    // `localhost` ist fuer Laravel auf dem Server korrekt, zeigt im Browser
    // eines Mitarbeiters aber auf dessen eigenen Rechner. Bei Zugriff ueber
    // LAN/Domain deshalb automatisch denselben Host wie die geoeffnete App
    // verwenden. So bleibt lokale Entwicklung unveraendert und entfernte
    // Clients landen nicht unbemerkt im langsameren Polling-Fallback.
    const reverbHost = loopbackHosts.has(configuredHost) && !loopbackHosts.has(browserHost)
        ? browserHost
        : (configuredHost || browserHost);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: reverbHost,
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

// Shell-Zustand liegt absichtlich ausserhalb des austauschbaren <body>.
// Livewire 3 behaelt globale Alpine-Stores bei wire:navigate im Speicher;
// dadurch bindet der neue Body noch im selben Navigationszyklus wieder an
// denselben Sidebar-Zustand und die persistierte Navigation klappt nicht
// sichtbar ein und wieder aus.
Alpine.store('shell', {
    desktopSidebarExpanded: document.body?.getAttribute('data-sidebar-expanded') === 'true',

    setDesktopSidebarExpanded(expanded) {
        this.desktopSidebarExpanded = Boolean(expanded);
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
// Seitenwechsel-Overlay fuer wire:navigate: ein kompakter, frei schwebender
// RailTime-Orb ohne Text oder Dialogflaeche. Wird erst nach kurzer Verzoegerung
// gezeigt (kein Flackern bei vorab geladenen Seiten) und nach dem
// body-Swap bei Bedarf neu angehaengt.
// ---------------------------------------------------------------
(function () {
    let overlay = null;
    let showTimer = null;
    let failsafeTimer = null;
    let active = false;
    let contentEntrancePending = false;

    // Notbremse. Grund (verifiziert in vendor/livewire/livewire/dist/
    // livewire.esm.js, performFetch): Livewire holt die neue Seite mit
    // fetch(...).then(...).then(...) OHNE .catch(). Bricht der Request ab
    // (Netzwerkabbruch, Server nicht erreichbar, Verbindung zurueckgesetzt),
    // wird der Callback nie aufgerufen -> es feuert weder 'livewire:navigating'
    // noch 'livewire:navigated'. Ebenso bei einer abgebrochenen Navigation:
    // 'livewire:navigate' ist cancelable, ein preventDefault stoppt den
    // Seitenwechsel nach unserem start(). Ohne diese Grenze bliebe das Overlay
    // dauerhaft sichtbar — wegen pointer-events:none sogar bedienbar dahinter.
    const FAILSAFE_MS = 10000;

    function ensureOverlay() {
        // Livewire tauscht bei wire:navigate den <body> aus -> ggf. neu anhaengen.
        if (overlay && document.body.contains(overlay)) {
            return overlay;
        }
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'rt-nav-overlay';
            overlay.className = 'rt-nav-overlay';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('aria-label', 'RailTime lädt die nächste Seite');

            // Partikelkranz und Funken deterministisch erzeugen: --i steuert
            // Startwinkel/Verzoegerung, --s die Groesse. Bewusst ohne
            // Math.random — gleiches Bild bei jedem Seitenwechsel.
            const particles = Array.from({ length: 8 }, (_, i) => (
                `<span class="rt-nav-loader__particle" style="--i:${i};--s:${(0.7 + (i % 3) * 0.24).toFixed(2)}"></span>`
            )).join('');
            const sparks = Array.from({ length: 3 }, (_, i) => (
                `<span class="rt-nav-loader__spark" style="--i:${i}"></span>`
            )).join('');

            overlay.innerHTML = `
                <span class="rt-nav-loader" aria-hidden="true">
                    <span class="rt-nav-loader__particles">${particles}</span>
                    <span class="rt-nav-loader__orb">
                        <span class="rt-nav-loader__fluid rt-nav-loader__fluid--drift"></span>
                        <span class="rt-nav-loader__fluid rt-nav-loader__fluid--counter"></span>
                        <span class="rt-nav-loader__sheen"></span>
                        ${sparks}
                        <span class="rt-nav-loader__core"></span>
                        <span class="rt-nav-loader__gloss"></span>
                    </span>
                </span>
            `;
        }
        if (document.body) {
            document.body.appendChild(overlay);
        }
        return overlay;
    }

    function start() {
        active = true;
        contentEntrancePending = true;
        window.clearTimeout(showTimer);
        window.clearTimeout(failsafeTimer);
        showTimer = window.setTimeout(function () {
            if (!active) return;
            const o = ensureOverlay();
            o.setAttribute('aria-hidden', 'false');
            o.classList.add('is-visible');

            if (window.gsap && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                const loader = o.querySelector('.rt-nav-loader');
                window.gsap.killTweensOf(loader);
                window.gsap.fromTo(loader, {
                    autoAlpha: 0,
                    scale: 0.82,
                    y: 8,
                }, {
                    autoAlpha: 1,
                    scale: 1,
                    y: 0,
                    duration: 0.42,
                    ease: 'power3.out',
                    overwrite: 'auto',
                    clearProps: 'opacity,visibility,transform',
                });
            }
        }, 120);
        failsafeTimer = window.setTimeout(done, FAILSAFE_MS);
    }

    // Einblendeanimation fuer die fertig geladene Seite: nur nach einer
    // tatsaechlichen wire:navigate-Navigation (Flag), nie beim ersten
    // Seitenaufbau. Bewusst NUR Opacity, kein Transform: .page-content
    // enthaelt position:fixed-Kinder (Shell-Ambient) — ein Transform wuerde
    // sie zum Containing Block umhaengen und vom Viewport loesen.
    function playContentEntrance() {
        if (!contentEntrancePending) return;
        contentEntrancePending = false;

        if (!window.gsap || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const content = document.querySelector('#main-content .page-content');
        if (!content) return;

        window.gsap.fromTo(content, { autoAlpha: 0 }, {
            autoAlpha: 1,
            duration: 0.34,
            ease: 'power1.out',
            overwrite: 'auto',
            clearProps: 'opacity,visibility',
        });
    }

    function done() {
        active = false;
        window.clearTimeout(showTimer);
        window.clearTimeout(failsafeTimer);

        // WICHTIG: nicht nur den Knoten aus der Closure aufraeumen. Livewire
        // legt fuer den Zurueck-Button eine HTML-Kopie der Seite im
        // History-State ab. War das Overlay dabei im DOM, bringt die
        // wiederhergestellte Seite einen KLON mit derselben id mit — den diese
        // Closure nicht kennt und der sonst dauerhaft sichtbar bliebe.
        document.querySelectorAll('#rt-nav-overlay').forEach(function (node) {
            const loader = node.querySelector('.rt-nav-loader');
            if (loader) {
                window.gsap?.killTweensOf(loader);
            }
            node.classList.remove('is-visible');
            node.setAttribute('aria-hidden', 'true');

            if (node !== overlay) {
                node.remove();
            }
        });
    }

    document.addEventListener('livewire:navigate', start);
    document.addEventListener('livewire:navigating', function () {
        start();

        // Direkt nach diesem Event sichert Livewire die HTML-Kopie der Seite.
        // Deshalb das Overlay vorher aus dem DOM nehmen — so kann es gar nicht
        // erst als Klon in die History wandern. Dauert der Seitenwechsel
        // laenger als 120 ms, haengt start() es an den neuen body an.
        if (overlay) {
            overlay.remove();
        }
    });

    // Alle Wege, auf denen eine Navigation endet ODER scheitert. done() ist
    // idempotent, mehrfaches Aufraeumen ist deshalb unschaedlich.
    document.addEventListener('livewire:navigated', function () {
        done();
        playContentEntrance();
    });
    window.addEventListener('popstate', done);
    window.addEventListener('pageshow', done);
    window.addEventListener('offline', done);
    // Der fehlgeschlagene Navigations-Fetch (siehe oben) landet als
    // unbehandelte Promise-Ablehnung — das praeziseste Signal fuer "die
    // Navigation kommt nicht mehr zurueck".
    window.addEventListener('unhandledrejection', done);
    window.addEventListener('error', done);

    // Damit andere Module (z. B. der Alpine-Watchdog) das Overlay vor einem
    // Reload sicher entfernen koennen.
    window.RTNavOverlay = { hide: done };
})();

// ---------------------------------------------------------------
// Abgelaufene Sitzung bei Livewire-Anfragen: ohne Rueckfrage erneuern.
//
// Standardverhalten von Livewire bei einer 419-Antwort ist
// confirm("This page has expired.\nWould you like to refresh the page?")
// (handlePageExpiry in vendor/livewire/livewire/dist/livewire.esm.js). Der
// fail-Hook laeuft nachweislich VOR dieser Abfrage: ruft er preventDefault(),
// kehrt Livewire sofort zurueck — kein Dialog und kein Fehler-Modal. Genau das
// nutzen wir, um die Seite selbst neu zu laden.
//
// Ein Reload ist hier korrekt (anders als auf der 419-Fehlerseite): das
// aktuelle Dokument wurde per GET geladen, es wird also kein POST wiederholt.
// ---------------------------------------------------------------
Livewire.hook('request', ({ fail }) => {
    fail(({ status, preventDefault }) => {
        if (status !== 419) {
            return;
        }

        preventDefault();

        try {
            // Kurzzeit-Schutz gegen eine Reload-Schleife. Absichtlich mit
            // Zeitstempel statt Einmal-Marker: ein spaeterer, echter Ablauf
            // muss weiterhin automatisch behandelt werden.
            const key = 'rt-419-recovered-at';
            const last = Number(window.sessionStorage.getItem(key) || 0);

            if (last && (Date.now() - last) < 10000) {
                return;
            }

            window.sessionStorage.setItem(key, String(Date.now()));
        } catch (_) {
            // Ohne sessionStorage trotzdem versuchen.
        }

        // Ein haengendes Seitenwechsel-Overlay wuerde den Reload ueberdauern.
        try {
            window.RTNavOverlay?.hide();
        } catch (_) {
            // Overlay ist optional.
        }

        window.location.reload();
    });
});

window.Alpine = Alpine;

registerRailtimePushSettings(Alpine);
registerRailtimePwaInstall(Alpine);
setupRailtimePwa();

Alpine.data('wagonListPrototype', wagonListPrototype);
Alpine.data('rtNumberInput', numberInput);
Alpine.data('rtSidebarNavigation', sidebarNavigation);
Alpine.data('railtimeTabs', railtimeTabs);

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

        if (!window.Echo || !config.chatId) {
            return;
        }

        this.channel = window.Echo.private(`chat.${config.chatId}`)
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
        window.clearTimeout(this.typingTimer);
        window.clearInterval(this.recordingTimer);
        if (this.recorder?.state === 'recording') {
            this.recordingIntent = 'cancel';
            this.recorder.stop();
        }
        this.stopRecordingTracks();

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

Alpine.data('chatTranscriptScroll', () => ({
    messageObserver: null,

    init() {
        this.scrollToLatest();
        this.messageObserver = new MutationObserver(() => this.scrollToLatest(true));
        this.messageObserver.observe(this.$el, { childList: true, subtree: true });
    },

    scrollToLatest(smooth = false) {
        requestAnimationFrame(() => {
            if (! this.$el?.isConnected) {
                return;
            }

            this.$el.scrollTo({
                top: this.$el.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto',
            });
        });
    },

    destroy() {
        this.messageObserver?.disconnect();
        this.messageObserver = null;
    },
}));

// Livewire aktualisiert den sichtbaren Verlauf alle zwei Sekunden. Wird die
// Chat-Wurzel dabei neu initialisiert, darf der bereits verkleinerte
// VisualViewport nicht zur neuen "stabilen" Hoehe werden. Sonst verliert das
// mobile Layout mitten in der Eingabe nach genau einem Poll den Tastaturmodus.
const rtChatViewportState = {
    stableHeight: 0,
    stableWidth: 0,
    keyboardOpen: false,
};

document.addEventListener('livewire:navigating', () => {
    rtChatViewportState.stableHeight = 0;
    rtChatViewportState.stableWidth = 0;
    rtChatViewportState.keyboardOpen = false;
});

Alpine.data('chatPaneNavigation', (initialHasSelection = false) => ({
    mobilePane: initialHasSelection ? 'chat' : 'list',
    listCollapsed: localStorage.getItem('rt-chat-list-collapsed') === 'true',
    touchStartX: null,
    touchStartY: null,
    viewportHandler: null,
    viewportFrame: null,
    focusHandler: null,
    stableViewportHeight: 0,
    stableViewportWidth: 0,
    keyboardOpen: false,
    visualViewportHeight: Math.max(0, window.visualViewport?.height ?? window.innerHeight),
    visualViewportTop: Math.max(0, window.visualViewport?.offsetTop ?? 0),
    topbarInset: 70,

    init() {
        this.viewportHandler = () => this.queueVisualViewportSync();
        this.focusHandler = () => this.queueVisualViewportSync();

        const visualViewport = window.visualViewport;
        const viewportHeight = Math.max(0, visualViewport?.height ?? window.innerHeight);
        const viewportWidth = Math.max(0, visualViewport?.width ?? window.innerWidth);
        const viewportTop = Math.max(0, visualViewport?.offsetTop ?? 0);
        this.visualViewportHeight = viewportHeight;
        this.visualViewportTop = viewportTop;
        this.topbarInset = this.resolveVisibleTopbarInset(viewportTop, viewportHeight);

        this.stableViewportHeight = Math.max(
            viewportHeight,
            rtChatViewportState.stableHeight,
        );
        this.stableViewportWidth = Math.max(
            viewportWidth,
            rtChatViewportState.stableWidth,
        );

        const keyboardThreshold = Math.max(104, this.stableViewportHeight * 0.16);
        this.keyboardOpen = rtChatViewportState.keyboardOpen
            && (this.stableViewportHeight - viewportHeight) > keyboardThreshold;
        this.$root.dataset.keyboardOpen = this.keyboardOpen ? 'true' : 'false';

        window.visualViewport?.addEventListener('resize', this.viewportHandler, { passive: true });
        window.visualViewport?.addEventListener('scroll', this.viewportHandler, { passive: true });
        window.addEventListener('resize', this.viewportHandler, { passive: true });
        this.$root.addEventListener('focusin', this.focusHandler);
        this.$root.addEventListener('focusout', this.focusHandler);

        this.viewportHandler();
    },

    queueVisualViewportSync() {
        if (this.viewportFrame !== null) {
            window.cancelAnimationFrame(this.viewportFrame);
        }

        this.viewportFrame = window.requestAnimationFrame(() => {
            this.viewportFrame = null;

            if (!this.$root?.isConnected) {
                return;
            }

            const visualViewport = window.visualViewport;
            const viewportHeight = Math.max(0, visualViewport?.height ?? window.innerHeight);
            const viewportWidth = Math.max(0, visualViewport?.width ?? window.innerWidth);
            const viewportTop = Math.max(0, visualViewport?.offsetTop ?? 0);
            const activeElement = document.activeElement;
            const editableFocused = activeElement instanceof Element
                && this.$root.contains(activeElement)
                && activeElement.matches('input, textarea, [contenteditable="true"]');

            // Bei einer Drehung entspricht die vorherige Breite ungefaehr der
            // neuen unbelasteten Hoehe. So bleibt die Tastaturerkennung auch
            // dann korrekt, wenn Android gleichzeitig VisualViewport,
            // innerHeight und Layout-Viewport verkleinert.
            const previousStableWidth = this.stableViewportWidth || viewportWidth;
            const previousStableHeight = this.stableViewportHeight || viewportHeight;
            const widthChanged = Math.abs(viewportWidth - previousStableWidth)
                > Math.max(48, previousStableWidth * 0.18);
            const orientationChanged = widthChanged
                && (previousStableHeight >= previousStableWidth) !== (viewportHeight >= viewportWidth);

            if (orientationChanged) {
                this.stableViewportHeight = Math.max(viewportHeight, previousStableWidth);
                this.stableViewportWidth = viewportWidth;
            }

            // Im Ruhezustand ist der sichtbare Viewport unsere stabile
            // Referenz. Beim Fokus steht dieser Wert bereits fest, bevor die
            // Tastatur den VisualViewport verkleinert (auch auf Android, wo
            // window.innerHeight parallel schrumpfen kann).
            const keyboardThreshold = Math.max(104, this.stableViewportHeight * 0.16);
            const viewportRecovered = viewportHeight >= this.stableViewportHeight - keyboardThreshold;

            if (!editableFocused && (!this.keyboardOpen || viewportRecovered)) {
                this.stableViewportHeight = viewportHeight;
                this.stableViewportWidth = viewportWidth;
            }

            const keyboardDelta = this.stableViewportHeight - viewportHeight;
            const nextKeyboardOpen = (editableFocused || this.keyboardOpen)
                && keyboardDelta > keyboardThreshold;

            this.visualViewportHeight = viewportHeight;
            this.visualViewportTop = viewportTop;
            this.topbarInset = this.resolveVisibleTopbarInset(viewportTop, viewportHeight);
            this.$root.style.setProperty('--rt-chat-visual-height', `${Math.round(viewportHeight)}px`);
            this.$root.style.setProperty('--rt-chat-visual-top', `${Math.round(viewportTop)}px`);
            this.$root.style.setProperty('--rt-chat-topbar-inset', `${Math.round(this.topbarInset)}px`);

            if (nextKeyboardOpen !== this.keyboardOpen) {
                this.keyboardOpen = nextKeyboardOpen;
                this.$root.dataset.keyboardOpen = nextKeyboardOpen ? 'true' : 'false';
            }

            if (!nextKeyboardOpen) {
                this.stableViewportWidth = viewportWidth;
            }

            rtChatViewportState.stableHeight = this.stableViewportHeight;
            rtChatViewportState.stableWidth = this.stableViewportWidth;
            rtChatViewportState.keyboardOpen = nextKeyboardOpen;
        });
    },

    resolveVisibleTopbarInset(viewportTop, viewportHeight) {
        if (window.innerWidth >= 768) {
            return 0;
        }

        const topbar = document.querySelector('[data-rt-shell-topbar]');

        if (!(topbar instanceof HTMLElement)) {
            return 0;
        }

        const styles = window.getComputedStyle(topbar);

        if (
            styles.display === 'none'
            || styles.visibility === 'hidden'
            || Number.parseFloat(styles.opacity || '1') <= 0
        ) {
            return 0;
        }

        const rect = topbar.getBoundingClientRect();
        const viewportBottom = viewportTop + viewportHeight;
        const visibleTop = Math.max(rect.top, viewportTop);
        const visibleBottom = Math.min(rect.bottom, viewportBottom);

        return Math.max(0, Math.min(rect.height, visibleBottom - visibleTop));
    },

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

    destroy() {
        window.visualViewport?.removeEventListener('resize', this.viewportHandler);
        window.visualViewport?.removeEventListener('scroll', this.viewportHandler);
        window.removeEventListener('resize', this.viewportHandler);
        this.$root?.removeEventListener('focusin', this.focusHandler);
        this.$root?.removeEventListener('focusout', this.focusHandler);

        if (this.viewportFrame !== null) {
            window.cancelAnimationFrame(this.viewportFrame);
            this.viewportFrame = null;
        }

        this.$root?.style.removeProperty('--rt-chat-visual-height');
        this.$root?.style.removeProperty('--rt-chat-visual-top');
        this.$root?.style.removeProperty('--rt-chat-topbar-inset');
        this.$root?.removeAttribute('data-keyboard-open');
        this.viewportHandler = null;
        this.focusHandler = null;
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

function rtChatIdFromNotification(payload) {
    const explicit = Number(payload.chatId || payload.chat_id || 0);

    if (explicit > 0) {
        return explicit;
    }

    try {
        return Number(new URL(payload.url, window.location.href).searchParams.get('chat') || 0);
    } catch (_) {
        return 0;
    }
}

function rtHandleIncomingNotification(payload, source = 'echo') {
    const category = payload.category === 'chat' ? 'chat' : 'messages';
    const notificationId = String(payload.notification_id || '').trim();
    const chatId = category === 'chat' ? rtChatIdFromNotification(payload) : 0;
    const normalizedPayload = { ...payload, category, chatId };
    const chatIsAlreadyVisible = chatId > 0 && rtNotificationContext.isChatVisible(chatId);
    const mayToast = !chatIsAlreadyVisible && rtNotificationContext.shouldPresent(
        normalizedPayload,
        { forceLocal: source === 'service-worker' },
    );

    if (mayToast && rtSeenNotifications.take(notificationId)) {
        window.dispatchEvent(new CustomEvent('swal:toast', {
            detail: {
                type: 'info',
                title: payload.title,
                text: payload.body || '',
                sound: incomingNotificationSound(category),
            },
        }));
    } else if (chatIsAlreadyVisible) {
        // Der sichtbare Verlauf ist bereits die Benachrichtigung. Die stabile
        // ID wird tabuebergreifend verbraucht, damit ein spaeter eintreffender
        // Web Push nicht doch noch einen doppelten Hinweis erzeugt.
        rtSeenNotifications.take(notificationId);
    }

    if (category === 'chat' && rtNotificationContext.isLocalChatVisible(chatId)) {
        Livewire.dispatch('chat:refresh', { chatId });
    }

    Livewire.dispatch('inbox:refresh');
}

rtForegroundPushHandler = (payload) => {
    rtHandleIncomingNotification(payload, 'service-worker');
};

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

            rtHandleIncomingNotification({
                notification_id: event.notification_id || `message:${event.id}`,
                category: 'messages',
                title,
                body: text,
            });
        })
        .listen('.chat.message.received', (event) => {
            const title = lang.newChatMessage || 'Neue Chatnachricht';
            const text = event.from ? `${lang.from || 'Von'}: ${event.from}` : '';

            rtHandleIncomingNotification({
                notification_id: event.notification_id || `chat-message:${event.messageId}`,
                category: 'chat',
                chatId: Number(event.chatId),
                title,
                body: text,
            });
        })
        // Videoanrufe: Das Klingel-Overlay (Livewire IncomingCallOverlay)
        // uebernimmt Anzeige, Ton und Cross-Tab-Dedup – hier wird das Event
        // nur als Window-Event weitergereicht.
        .listen('.call.invited', (event) => {
            window.dispatchEvent(new CustomEvent('rt:call-invited', { detail: event }));
        })
        .listen('.call.missed', (event) => {
            window.dispatchEvent(new CustomEvent('rt:call-missed', { detail: event }));

            window.dispatchEvent(new CustomEvent('swal:toast', {
                detail: { text: lang.missedCall || 'Verpasster Anruf', type: 'info', sound: false },
            }));
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

    const notifications = Array.isArray(event.detail?.notifications)
        ? event.detail.notifications
        : [];

    if (notifications.length > 0) {
        notifications.forEach((notification) => {
            rtHandleIncomingNotification(notification, 'polling');
        });

        return;
    }

    const source = event.detail?.source || 'both';

    if (!rtNotificationContext.shouldPresent({
        category: source === 'chat' ? 'chat' : 'messages',
    })) {
        return;
    }

    window.RTSound?.play(incomingNotificationSound(source));
});

let sidebarCollapseTimer = null;
let sidebarExpandTimer = null;
let sidebarSwipeStart = null;
const DESKTOP_SIDEBAR_EXPAND_DELAY = 750;
const DESKTOP_SIDEBAR_COLLAPSE_DELAY = 1500;

function initMetisMenu(sideMenu) {
    if (!window.MetisMenu || !sideMenu) {
        return null;
    }

    // MetisMenu animiert andernfalls den bereits serverseitig offenen Pfad
    // waehrend seiner Initialisierung. Bei einem schnellen body swap kann
    // dieser Zustand als "transitioning" haengen bleiben und weitere Klicks
    // blockieren. Erst neutral initialisieren, dann den aktiven Pfad setzen.
    sideMenu.querySelectorAll('li.mm-active').forEach((item) => item.classList.remove('mm-active'));
    sideMenu.querySelectorAll('ul').forEach((submenu) => {
        submenu.classList.remove('mm-show', 'mm-collapsing');
        submenu.classList.add('mm-collapse');
        submenu.style.removeProperty('height');
    });

    const metisMenu = new window.MetisMenu(sideMenu, {
        toggle: true,
    });

    initActiveMenu(sideMenu);

    return metisMenu;
}

function sidebarNavigation() {
    return {
        metisMenu: null,
        shownHandler: null,
        bootFrame: null,

        init() {
            this.$nextTick(() => {
                if (!this.$root.isConnected) {
                    return;
                }

                // Der Server liefert den aktiven Pfad bereits mit. Vor der
                // Plugin-Initialisierung normalisieren, damit MetisMenu nicht
                // direkt danach durch eine zweite Zustandsrunde blockiert wird.
                initActiveMenu(this.$root);
                this.metisMenu = initMetisMenu(this.$root);

                if (!this.metisMenu) {
                    // Klassische Vendor-Skripte koennen nach einem
                    // wire:navigate-Bodytausch einen Frame spaeter bereit sein.
                    this.bootFrame = window.requestAnimationFrame(() => {
                        this.bootFrame = null;

                        if (this.$root.isConnected) {
                            this.metisMenu = initMetisMenu(this.$root);
                            this.bindShownHandler();
                        }
                    });
                } else {
                    this.bindShownHandler();
                }

                initMenuItemScroll(this.$root);
            });
        },

        bindShownHandler() {
            if (!this.metisMenu || this.shownHandler) {
                return;
            }

            this.shownHandler = (event) => {
                const openedSubmenu = event.detail?.shownElement;

                window.requestAnimationFrame(() => {
                    if (openedSubmenu?.isConnected) {
                        scrollSidebarBlockIntoView(openedSubmenu);
                    }
                });
            };

            this.$root.addEventListener('shown.metisMenu', this.shownHandler);
            window.__webreachMetisMenu = this.metisMenu;
        },

        destroy() {
            if (this.bootFrame !== null) {
                window.cancelAnimationFrame(this.bootFrame);
                this.bootFrame = null;
            }

            if (this.shownHandler) {
                this.$root.removeEventListener('shown.metisMenu', this.shownHandler);
                this.shownHandler = null;
            }

            if (this.metisMenu && typeof this.metisMenu.dispose === 'function') {
                this.metisMenu.dispose();
            }

            if (window.__webreachMetisMenu === this.metisMenu) {
                window.__webreachMetisMenu = null;
            }

            this.metisMenu = null;
        },
    };
}

function clearSidebarCollapseTimer() {
    if (sidebarCollapseTimer) {
        window.clearTimeout(sidebarCollapseTimer);
        sidebarCollapseTimer = null;
    }
}

function clearSidebarExpandTimer() {
    if (sidebarExpandTimer) {
        window.clearTimeout(sidebarExpandTimer);
        sidebarExpandTimer = null;
    }
}

function isDesktopHoverSidebar() {
    return window.innerWidth >= 1024 && Boolean(document.querySelector('.vertical-menu'));
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

    const nextExpanded = Boolean(expanded);

    Alpine.store('shell').setDesktopSidebarExpanded(nextExpanded);
    document.body.setAttribute('data-sidebar-expanded', nextExpanded ? 'true' : 'false');
    syncSidebarToggleState();
}

function captureDesktopSidebarState() {
    if (!document.body || !isDesktopHoverSidebar()) {
        return;
    }

    Alpine.store('shell').setDesktopSidebarExpanded(
        document.body.getAttribute('data-sidebar-expanded') === 'true'
    );
}

function restoreDesktopSidebarState() {
    if (!document.body || !isDesktopHoverSidebar()) {
        return;
    }

    // Direkte Zuweisung als synchroner Fallback fuer den kurzen Moment,
    // bevor Alpine das x-bind des neuen <body> ausgewertet hat.
    document.body.setAttribute(
        'data-sidebar-expanded',
        Alpine.store('shell').desktopSidebarExpanded ? 'true' : 'false'
    );
    syncSidebarToggleState();
}

function setMobileSidebarOpen(open) {
    document.body.classList.toggle('sidebar-enable', open);
    syncSidebarToggleState();
}

function initMobileSidebarSwipe() {
    if (window.__rtMobileSidebarSwipeBound === true) {
        return;
    }

    window.__rtMobileSidebarSwipeBound = true;

    document.addEventListener('touchstart', (event) => {
        if (window.innerWidth >= 1024 || event.touches.length !== 1) {
            sidebarSwipeStart = null;
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('input, textarea, select, button, [contenteditable="true"], [role="dialog"], [data-no-sidebar-swipe]')) {
            sidebarSwipeStart = null;
            return;
        }

        const touch = event.touches[0];
        const sidebarOpen = document.body.classList.contains('sidebar-enable');
        const insideSidebar = Boolean(target.closest('#app-sidebar'));
        const startsAtOpeningEdge = touch.clientX <= Math.max(24, window.innerWidth * 0.065);

        if ((!sidebarOpen && startsAtOpeningEdge) || (sidebarOpen && insideSidebar)) {
            sidebarSwipeStart = {
                x: touch.clientX,
                y: touch.clientY,
                sidebarOpen,
            };
            return;
        }

        sidebarSwipeStart = null;
    }, { passive: true });

    document.addEventListener('touchend', (event) => {
        if (!sidebarSwipeStart || event.changedTouches.length !== 1 || window.innerWidth >= 1024) {
            sidebarSwipeStart = null;
            return;
        }

        const touch = event.changedTouches[0];
        const deltaX = touch.clientX - sidebarSwipeStart.x;
        const deltaY = touch.clientY - sidebarSwipeStart.y;
        const threshold = Math.max(64, Math.min(110, window.innerWidth * 0.2));
        const isHorizontal = Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.25;

        if (isHorizontal && !sidebarSwipeStart.sidebarOpen && deltaX > 0) {
            setMobileSidebarOpen(true);
            initMenuItemScroll();
        } else if (isHorizontal && sidebarSwipeStart.sidebarOpen && deltaX < 0) {
            setMobileSidebarOpen(false);
        }

        sidebarSwipeStart = null;
    }, { passive: true });

    document.addEventListener('touchcancel', () => {
        sidebarSwipeStart = null;
    }, { passive: true });
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
    clearSidebarExpandTimer();

    sidebarCollapseTimer = window.setTimeout(() => {
        sidebarCollapseTimer = null;

        if (!isSidebarHoveredOrFocused()) {
            setDesktopSidebarExpanded(false);
        }
    }, DESKTOP_SIDEBAR_COLLAPSE_DELAY);
}

function scheduleDesktopSidebarExpand() {
    clearSidebarCollapseTimer();
    clearSidebarExpandTimer();

    if (document.body.getAttribute('data-sidebar-expanded') === 'true') {
        return;
    }

    sidebarExpandTimer = window.setTimeout(() => {
        sidebarExpandTimer = null;

        if (isSidebarHoveredOrFocused()) {
            setDesktopSidebarExpanded(true);
        }
    }, DESKTOP_SIDEBAR_EXPAND_DELAY);
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
        document.body.setAttribute('data-sidebar-expanded', isExpanded ? 'true' : 'false');

        if (!isExpanded && isSidebarHoveredOrFocused()) {
            scheduleDesktopSidebarExpand();
        }

        syncSidebarToggleState();
        return;
    }

    clearSidebarCollapseTimer();
    clearSidebarExpandTimer();
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
                clearSidebarExpandTimer();
                setDesktopSidebarExpanded(document.body.getAttribute('data-sidebar-expanded') !== 'true');
                return;
            }

            setMobileSidebarOpen(!document.body.classList.contains('sidebar-enable'));
            initMenuItemScroll();
        });
    });
}

function initActiveMenu(sideMenu = document.getElementById('side-menu')) {
    if (!sideMenu) {
        return;
    }

    const pageUrl = window.location.href.split(/[?#]/)[0];
    const menuItems = Array.from(sideMenu.querySelectorAll('a'));
    // Gruppen-Trigger verwenden href="#". Der Browser loest dieses Attribut
    // zur aktuellen Seiten-URL auf; dadurch galten sie bisher faelschlich als
    // exakter Treffer und konnten neben dem echten Link aktiv bleiben.
    const navigableItems = menuItems.filter((item) =>
        item.matches('[data-rt-sidebar-link]')
        && item.getAttribute('href')
        && item.getAttribute('href') !== '#'
    );
    const nestedLists = sideMenu.querySelectorAll('ul');
    // Livewires wire:current wird vor unserem navigated-Handler aktualisiert
    // und kennt auch Unterpfade. Das ist fuer die via @persist erhaltene
    // Sidebar die verlaessliche Quelle, wenn der Server-Link selbst nicht
    // exakt der aktuellen Detail-URL entspricht.
    const currentMatches = navigableItems.filter((item) => item.hasAttribute('data-current'));

    menuItems.forEach((item) => {
        item.classList.remove('active');
        item.removeAttribute('aria-current');
    });
    sideMenu.querySelectorAll('[data-rt-sidebar-group]').forEach((trigger) => {
        trigger.setAttribute('aria-expanded', 'false');
    });
    sideMenu.querySelectorAll('li.mm-active').forEach((item) => item.classList.remove('mm-active'));
    nestedLists.forEach((list) => {
        list.classList.remove('mm-show');
    });

    const exactMatches = navigableItems.filter((item) => item.href.split(/[?#]/)[0] === pageUrl);
    const fallbackMatches = navigableItems.filter((item) => item.dataset.menuActive === 'true');
    const activeItems = exactMatches.length > 0
        ? exactMatches
        : (currentMatches.length > 0 ? currentMatches : fallbackMatches);

    activeItems.forEach((item) => {
        item.classList.add('active');
        item.setAttribute('aria-current', 'page');

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

    sideMenu.querySelectorAll('[data-rt-sidebar-group]').forEach((trigger) => {
        trigger.setAttribute(
            'aria-expanded',
            trigger.closest('li')?.classList.contains('mm-active') ? 'true' : 'false'
        );
    });
}

function scrollSidebarBlockIntoView(element) {
    const sidebar = element?.closest('.vertical-menu');
    const scroller = sidebar?.querySelector('.simplebar-content-wrapper');
    const block = element?.matches('li')
        ? element
        : element?.closest('li');

    if (!scroller || !block) {
        return;
    }

    const scrollerRect = scroller.getBoundingClientRect();
    const blockRect = block.getBoundingClientRect();
    const top = sidebarScrollTarget({
        scrollTop: scroller.scrollTop,
        clientHeight: scroller.clientHeight,
        scrollHeight: scroller.scrollHeight,
        containerTop: scrollerRect.top,
        containerBottom: scrollerRect.bottom,
        blockTop: blockRect.top,
        blockBottom: blockRect.bottom,
    });

    if (top === null) {
        return;
    }

    const behavior = sidebarScrollBehavior(
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
    );

    if (typeof scroller.scrollTo === 'function') {
        scroller.scrollTo({ top, behavior });
        return;
    }

    // Alter Browser, gleicher lokaler Scrollcontainer.
    scroller.scrollTop = top;
}

function initMenuItemScroll(sideMenu = document.getElementById('side-menu')) {
    window.setTimeout(() => {
        if (!sideMenu?.isConnected) {
            return;
        }

        const activeItem = sideMenu.querySelector('.mm-active .active');

        if (!activeItem) {
            return;
        }

        const containingSubmenu = activeItem.closest('ul.mm-collapse');
        const relevantBlock = containingSubmenu?.closest('li') || activeItem.closest('li');

        scrollSidebarBlockIntoView(relevantBlock);
    }, 150);
}

function initFeather() {
    if (window.feather && typeof window.feather.replace === 'function') {
        window.feather.replace();
    }
}

function initSidebarInteractions() {
    document.querySelectorAll('.vertical-menu, .topbar-brand').forEach((element) => {
        if (element.dataset.webreachSidebarHoverBound === '1') {
            return;
        }

        element.dataset.webreachSidebarHoverBound = '1';

        element.addEventListener('mouseenter', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            scheduleDesktopSidebarExpand();
        });

        element.addEventListener('mouseleave', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            clearSidebarExpandTimer();
            scheduleDesktopSidebarCollapse();
        });

        element.addEventListener('focusin', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            clearSidebarCollapseTimer();
            clearSidebarExpandTimer();
            setDesktopSidebarExpanded(true);
        });

        element.addEventListener('focusout', () => {
            if (!isDesktopHoverSidebar()) {
                return;
            }

            scheduleDesktopSidebarCollapse();
        });
    });

    // document/window ueberleben den body-Tausch von wire:navigate. Der
    // globale Marker verhindert, dass jeder Seitenwechsel weitere identische
    // Listener stapelt; die Element-Listener oben werden je DOM-Generation
    // dagegen bewusst neu gebunden.
    if (window.__rtSidebarGlobalInteractionsBound !== true) {
        window.__rtSidebarGlobalInteractionsBound = true;

        document.addEventListener(
            'pointerdown',
            (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (isDesktopHoverSidebar()) {
                    if (target?.closest('.vertical-menu, .topbar-brand')) {
                        clearSidebarCollapseTimer();
                        clearSidebarExpandTimer();
                        setDesktopSidebarExpanded(true);
                    } else {
                        clearSidebarCollapseTimer();
                        clearSidebarExpandTimer();
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
            clearSidebarExpandTimer();
            setDesktopSidebarExpanded(false);
            setMobileSidebarOpen(false);
        });

        window.addEventListener('resize', syncSidebarInteractionMode);
    }

    syncSidebarInteractionMode();
}

function initAdminLayout() {
    syncSidebarInteractionMode();
    initLeftMenuCollapse();
    initMobileSidebarSwipe();
    initSidebarInteractions();
    initActiveMenu();
    initMenuItemScroll();
    initFeather();
}

let sidebarLayoutFrame = null;

function queueAdminLayoutInit() {
    if (sidebarLayoutFrame !== null) {
        window.cancelAnimationFrame(sidebarLayoutFrame);
    }

    sidebarLayoutFrame = window.requestAnimationFrame(() => {
        sidebarLayoutFrame = null;
        initAdminLayout();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', queueAdminLayoutInit, { once: true });
} else {
    queueAdminLayoutInit();
}

// Livewire 3 ersetzt bei wire:navigate den Body; die Sidebar selbst wird
// dabei via @persist weiterverwendet. Der Store stellt den
// Breitenzustand synchron am neuen Body wieder her; danach aktualisiert der
// Frame nur noch aktive Route, globale Shell-Interaktionen und Scrollposition.
document.addEventListener('livewire:navigate', captureDesktopSidebarState);
document.addEventListener('livewire:navigating', captureDesktopSidebarState);
document.addEventListener('livewire:navigated', restoreDesktopSidebarState);
document.addEventListener('livewire:navigated', queueAdminLayoutInit);
