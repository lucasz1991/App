// ZUERST importieren: das Modul installiert seine Fehler-Listener direkt beim
// Import, also bevor die folgenden Module ausgewertet werden. Ein Fehler in
// einem dieser Module wird dadurch bereits erfasst (siehe alpine-watchdog.js).
import './alpine-watchdog';
import './bootstrap';
// Meldungen (swal:toast/swal:alert/showAlert) auf SweetAlert2. Frueh
// importieren, damit auch Meldungen aus dem Seitenaufbau ankommen.
import './rt-alerts';
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
import {
    acquireMicrophoneStream,
    holdMicrophoneStream,
    releaseMicrophoneStream,
} from './microphone-stream';
import { numberInput } from './number-input';
import { dateField } from './date-field';
import { createWagonListMotion } from './wagon-list-motion';
import {
    registerRailtimePwaInstall,
    registerRailtimePushSettings,
    setupRailtimePwa,
} from './pwa';
import { createNotificationSeenCache } from './notification-seen-cache';
import { createNotificationPresentationContext } from './notification-presentation';
import { incomingNotificationSound } from './realtime-notification-sound';
import {
    MOBILE_SIDEBAR_BREAKPOINT,
    MOBILE_SIDEBAR_SWIPE_EXCLUSION_SELECTOR,
    resolveMobileSidebarSwipe,
} from './mobile-sidebar-swipe';
import { sidebarScrollBehavior, sidebarScrollTarget } from './sidebar-scroll';
import { railtimeTabs } from './tabs';
import { initMobileFormFocusRecovery } from './mobile-form-focus';
import { initKeyboardViewport } from './keyboard-viewport';
import { welcomeIntro } from './welcome-intro';
import { railtimeChatbot } from './chatbot';
import { railtimeAssistantPet3d } from './assistant-pet-3d';
import { createNavigationParticleSphere } from './navigation-particle-loader';
import { chatMessageActions } from './chat-message-actions';

const loadAdminDashboardECharts = () => import('./admin-dashboard-echarts');
const loadAdminDashboardMotion = () => import('./admin-dashboard-motion');
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
    let announcement = null;
    let particleSphere = null;
    let showTimer = null;
    let failsafeTimer = null;
    let outroTimer = null;
    let announcementTimer = null;
    let active = false;
    let contentEntrancePending = false;
    let visibleBeforeSwap = false;
    let transitionToken = 0;
    let pendingNavigations = 0;

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

    function syncAnnouncement({ announce = false } = {}) {
        if (!announcement) {
            return;
        }

        const language = String(document.documentElement.lang || 'de').toLowerCase();
        const message = language.startsWith('de')
            ? 'RailTime lädt die nächste Seite'
            : 'RailTime is loading the next page';

        window.clearTimeout(announcementTimer);
        announcementTimer = null;

        if (!announce) {
            announcement.textContent = message;

            return;
        }

        // Eine echte Textmutation macht die wiederholte Statusansage fuer
        // Screenreader verlaesslicher als nur aria-hidden umzuschalten.
        announcement.textContent = '';
        announcementTimer = window.setTimeout(function () {
            announcementTimer = null;

            if (active && announcement) {
                announcement.textContent = message;
            }
        }, 0);
    }

    function ensureOverlay() {
        // Livewire tauscht bei wire:navigate den <body> aus -> ggf. neu anhaengen.
        if (overlay && document.body.contains(overlay)) {
            syncAnnouncement();

            return overlay;
        }

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'rt-nav-overlay';
            overlay.className = 'rt-nav-overlay';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('aria-live', 'polite');
            overlay.setAttribute('aria-atomic', 'true');

            const loader = document.createElement('span');
            loader.className = 'rt-nav-loader';
            loader.setAttribute('aria-hidden', 'true');

            const canvas = document.createElement('canvas');
            canvas.className = 'rt-nav-loader__canvas';
            canvas.dataset.rtNavParticles = '';
            canvas.setAttribute('aria-hidden', 'true');

            const fallback = document.createElement('span');
            fallback.className = 'rt-nav-loader__fallback';
            fallback.setAttribute('aria-hidden', 'true');

            announcement = document.createElement('span');
            announcement.className = 'sr-only';
            syncAnnouncement();

            loader.append(canvas, fallback);
            overlay.append(loader, announcement);

            particleSphere = createNavigationParticleSphere(canvas);
            loader.classList.toggle('has-particle-canvas', particleSphere.available);
        }

        if (document.body) {
            document.body.appendChild(overlay);
        }

        return overlay;
    }

    function clearTransitionTimers() {
        window.clearTimeout(showTimer);
        window.clearTimeout(failsafeTimer);
        window.clearTimeout(outroTimer);
        window.clearTimeout(announcementTimer);
        showTimer = null;
        failsafeTimer = null;
        outroTimer = null;
        announcementTimer = null;
    }

    function removeOverlayClones() {
        document.querySelectorAll('#rt-nav-overlay').forEach(function (node) {
            if (node !== overlay) {
                node.remove();
            }
        });
    }

    function hideImmediately() {
        if (overlay) {
            overlay.classList.remove('is-visible', 'is-leaving', 'is-leaving--quick', 'is-leaving--logo');
            overlay.setAttribute('aria-hidden', 'true');
        }

        particleSphere?.stop();
        visibleBeforeSwap = false;
        removeOverlayClones();
    }

    function start() {
        pendingNavigations += 1;
        contentEntrancePending = true;

        if (active) {
            window.clearTimeout(failsafeTimer);
            failsafeTimer = window.setTimeout(done, FAILSAFE_MS);

            return;
        }

        transitionToken += 1;
        active = true;
        clearTransitionTimers();
        hideImmediately();

        const token = transitionToken;
        showTimer = window.setTimeout(function () {
            if (!active || token !== transitionToken) return;

            const o = ensureOverlay();
            o.classList.remove('is-leaving', 'is-leaving--quick', 'is-leaving--logo');
            o.setAttribute('aria-hidden', 'false');
            o.classList.add('is-visible');
            syncAnnouncement({ announce: true });
            particleSphere?.start();
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

    function finishOutro(token) {
        if (token !== transitionToken) {
            return;
        }

        window.clearTimeout(outroTimer);
        outroTimer = null;
        hideImmediately();
    }

    function done(options = {}) {
        const animate = options?.animate === true;
        const token = transitionToken;
        const wasVisible = visibleBeforeSwap || overlay?.classList.contains('is-visible');

        pendingNavigations = 0;
        active = false;
        clearTransitionTimers();
        removeOverlayClones();

        if (!animate || !wasVisible) {
            transitionToken += 1;
            hideImmediately();

            return;
        }

        // livewire:navigating entfernt den Knoten vor dem History-Snapshot.
        // onSwap setzt ihn normalerweise bereits in den neuen Body; dieser
        // Fallback deckt Browser-/Livewire-Versionen ohne Callback ab.
        const o = ensureOverlay();
        o.setAttribute('aria-hidden', 'false');
        o.classList.add('is-visible');

        const outro = particleSphere?.leave() || { mode: 'fallback', duration: 160 };

        if (outro.duration <= 0) {
            finishOutro(token);

            return;
        }

        o.classList.add('is-leaving');
        o.classList.toggle('is-leaving--quick', outro.mode !== 'logo');
        o.classList.toggle('is-leaving--logo', outro.mode === 'logo');
        visibleBeforeSwap = false;
        outroTimer = window.setTimeout(function () {
            finishOutro(token);
        }, outro.duration + 32);
    }

    document.addEventListener('livewire:navigate', function (event) {
        // Ein frueher Listener (z. B. Autosave) darf den Besuch aufschieben.
        // In diesem Fall gibt es noch keinen Request und damit auch nichts,
        // wofuer die Ladeanimation erscheinen sollte.
        if (event.defaultPrevented) {
            return;
        }

        start();
    });
    document.addEventListener('livewire:navigating', function (event) {
        // Normalerweise lief livewire:navigate bereits. Der Fallback schuetzt
        // programmatische/abweichende Livewire-Pfade, ohne eine Navigation
        // doppelt in pendingNavigations zu zaehlen.
        if (!active) {
            start();
        }

        const token = transitionToken;
        visibleBeforeSwap = Boolean(overlay?.classList.contains('is-visible'));

        // Direkt nach diesem Event sichert Livewire die HTML-Kopie der Seite.
        // Deshalb das Overlay vorher aus dem DOM nehmen — so kann es gar nicht
        // erst als Klon in die History wandern. Livewires onSwap-Callback setzt
        // denselben Canvas danach in den neuen Body, noch vor navigated. So kann
        // die Partikelkugel ueber dem fertigen Inhalt sauber auslaufen.
        if (overlay) {
            overlay.remove();
        }

        if (visibleBeforeSwap && typeof event.detail?.onSwap === 'function') {
            event.detail.onSwap(function () {
                if (token !== transitionToken || !active || !overlay || !document.body) {
                    return;
                }

                document.body.appendChild(overlay);
                syncAnnouncement();
            });
        }
    });

    // Alle Wege, auf denen eine Navigation endet ODER scheitert. done() ist
    // idempotent, mehrfaches Aufraeumen ist deshalb unschaedlich.
    document.addEventListener('livewire:navigated', function () {
        if (pendingNavigations > 0) {
            pendingNavigations -= 1;
        }

        // Livewire kann mehrere Navigate-Fetches parallel abschliessen. Die
        // fruehere Antwort darf den Loader der noch offenen Navigation nicht
        // ausblenden; das Outro gehoert ausschliesslich zum letzten Abschluss.
        if (pendingNavigations > 0 || !active) {
            return;
        }

        done({ animate: true });
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

// Bewegung des Wagenlisten-Editors. Global, weil die Alpine-Komponente sie
// bewusst nur defensiv anspricht — ohne dieses Objekt bleibt der Editor
// vollstaendig bedienbar, nur eben ohne Uebergaenge.
window.RailTimeWagonMotion = createWagonListMotion();

registerRailtimePushSettings(Alpine);
registerRailtimePwaInstall(Alpine);
setupRailtimePwa();

Alpine.data('wagonListPrototype', wagonListPrototype);
Alpine.data('rtNumberInput', numberInput);
Alpine.data('rtDateField', dateField);
Alpine.data('rtSidebarNavigation', sidebarNavigation);
Alpine.data('railtimeTabs', railtimeTabs);
Alpine.data('welcomeIntro', welcomeIntro);
Alpine.data('railtimeChatbot', railtimeChatbot);
Alpine.data('railtimeAssistantPet3d', railtimeAssistantPet3d);
Alpine.data('chatMessageActions', chatMessageActions);

initMobileFormFocusRecovery();
initKeyboardViewport();

Alpine.data('chatRealtime', (config) => ({
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
}));

Alpine.data('chatAudioPlayer', (config = {}) => ({
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
}));

Alpine.data('chatTranscriptScroll', () => ({
    messageObserver: null,
    scrollHandler: null,
    stickToBottom: true,
    animatedRows: new WeakSet(),
    knownMessageKeys: new Set(),
    prependSnapshot: null,
    highlightTimer: null,

    init() {
        this.$el.querySelectorAll('[data-chat-message-row]').forEach((row) => {
            this.animatedRows.add(row);
            const key = row.getAttribute('wire:key');
            if (key) {
                this.knownMessageKeys.add(key);
            }
        });
        this.scrollHandler = () => {
            const bottomGap = this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight;
            this.stickToBottom = bottomGap <= 96;
        };
        this.$el.addEventListener('scroll', this.scrollHandler, { passive: true });
        this.scrollToLatest();
        this.messageObserver = new MutationObserver((mutations) => {
            const newRows = [];

            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof Element)) {
                        return;
                    }

                    if (node.matches('[data-chat-message-row]')) {
                        newRows.push(node);
                    }
                    node.querySelectorAll?.('[data-chat-message-row]').forEach((row) => newRows.push(row));
                });
            });

            const freshRows = [...new Set(newRows)].filter((row) => {
                const key = row.getAttribute('wire:key');

                if ((key && this.knownMessageKeys.has(key)) || this.animatedRows.has(row)) {
                    return false;
                }

                if (key) {
                    this.knownMessageKeys.add(key);
                }
                this.animatedRows.add(row);

                return true;
            });

            if (!freshRows.length) {
                return;
            }

            this.animateRows(freshRows);

            if (this.stickToBottom) {
                this.scrollToLatest(true);
            }
        });
        this.messageObserver.observe(this.$el, { childList: true, subtree: true });
    },

    animateRows(rows) {
        if (!rows.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        window.gsap?.fromTo(
            rows,
            { autoAlpha: 0, y: 8, scale: 0.985 },
            {
                autoAlpha: 1,
                y: 0,
                scale: 1,
                duration: 0.3,
                stagger: 0.035,
                ease: 'power2.out',
                clearProps: 'transform,opacity,visibility',
                overwrite: 'auto',
            }
        );
    },

    scrollToLatest(smooth = false) {
        this.stickToBottom = true;

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

    rememberPrependPosition() {
        this.prependSnapshot = {
            height: this.$el.scrollHeight,
            top: this.$el.scrollTop,
        };
    },

    restorePrependPosition() {
        if (!this.prependSnapshot || !this.$el?.isConnected) {
            return;
        }

        const addedHeight = this.$el.scrollHeight - this.prependSnapshot.height;
        this.$el.scrollTop = this.prependSnapshot.top + Math.max(0, addedHeight);
        this.prependSnapshot = null;
    },

    scrollToMessage(messageId) {
        const numericId = Number(messageId || 0);
        if (!numericId) {
            return;
        }

        const row = this.$el.querySelector(`[data-chat-message-id="${numericId}"]`);
        if (!row) {
            return;
        }

        row.scrollIntoView({
            block: 'center',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        row.classList.remove('is-reply-target');
        void row.offsetWidth;
        row.classList.add('is-reply-target');
        window.clearTimeout(this.highlightTimer);
        this.highlightTimer = window.setTimeout(() => row.classList.remove('is-reply-target'), 1800);
    },

    destroy() {
        this.messageObserver?.disconnect();
        this.messageObserver = null;
        this.$el?.removeEventListener('scroll', this.scrollHandler);
        this.scrollHandler = null;
        window.clearTimeout(this.highlightTimer);
        this.highlightTimer = null;
        window.gsap?.killTweensOf(this.$el.querySelectorAll('[data-chat-message-row]'));
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

// iOS kann beim Drehen, beim Resume einer installierten PWA und waehrend
// kurzer Tastatur-Uebergaenge fuer einen Frame 0/NaN im VisualViewport
// liefern. Solche Zwischenwerte duerfen die fixed Chatflaeche nicht auf 0px
// zusammenziehen. Der Layout-Viewport ist in diesem Moment der beste
// belastbare Fallback.
function readChatViewport() {
    const visualViewport = window.visualViewport;
    const layoutHeight = Number(window.innerHeight || document.documentElement?.clientHeight || 0);
    const layoutWidth = Number(window.innerWidth || document.documentElement?.clientWidth || 0);
    const visualHeight = Number(visualViewport?.height);
    const visualWidth = Number(visualViewport?.width);
    const visualTop = Number(visualViewport?.offsetTop);

    return {
        height: Number.isFinite(visualHeight) && visualHeight > 0
            ? visualHeight
            : (Number.isFinite(layoutHeight) && layoutHeight > 0 ? layoutHeight : 1),
        width: Number.isFinite(visualWidth) && visualWidth > 0
            ? visualWidth
            : (Number.isFinite(layoutWidth) && layoutWidth > 0 ? layoutWidth : 1),
        top: Number.isFinite(visualTop) ? Math.max(0, visualTop) : 0,
    };
}

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
    visualViewportHeight: readChatViewport().height,
    visualViewportTop: readChatViewport().top,
    topbarInset: 70,

    init() {
        this.viewportHandler = () => this.queueVisualViewportSync();
        this.focusHandler = () => this.queueVisualViewportSync();

        const viewport = readChatViewport();
        const viewportHeight = viewport.height;
        const viewportWidth = viewport.width;
        const viewportTop = viewport.top;
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
        window.addEventListener('orientationchange', this.viewportHandler, { passive: true });
        window.addEventListener('pageshow', this.viewportHandler, { passive: true });
        document.addEventListener('visibilitychange', this.viewportHandler, { passive: true });
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

            const viewport = readChatViewport();
            const viewportHeight = viewport.height;
            const viewportWidth = viewport.width;
            const viewportTop = viewport.top;
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
        window.removeEventListener('orientationchange', this.viewportHandler);
        window.removeEventListener('pageshow', this.viewportHandler);
        document.removeEventListener('visibilitychange', this.viewportHandler);
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
    progressTweens: [],
    themeObserver: null,
    resizeObserver: null,
    renderTimer: null,
    renderRequest: null,
    chartsRendered: false,
    motion: null,
    motionRequest: null,

    init() {
        this.$nextTick(() => {
            this.observeKpis();
            this.setupMotion();
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
        this.motionRequest = null;
        window.clearTimeout(this.renderTimer);
        this.themeObserver?.disconnect();
        this.kpiObserver?.disconnect();
        this.resizeObserver?.disconnect();
        this.counterTween?.kill();
        this.progressTweens.forEach((tween) => tween.kill());
        this.progressTweens = [];
        this.kpiMotion?.revert();
        this.motion?.destroy();
        this.motion = null;
        this.destroyCharts();
    },

    // DrawSVG-, MotionPath- und Zaehleranimationen liegen in einem eigenen
    // Lazy-Chunk. Er wird nur auf dieser Seite geladen und beim Verlassen
    // (Livewire-Navigation) wieder vollstaendig zurueckgenommen.
    async setupMotion() {
        const request = Symbol('admin-dashboard-motion');
        this.motionRequest = request;

        const { createAdminDashboardMotion } = await loadAdminDashboardMotion();

        if (this.motionRequest !== request || !this.$root.isConnected) return;

        this.motion?.destroy();
        this.motion = createAdminDashboardMotion(this.$root);
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
        const meters = Array.from(kpiGrid.querySelectorAll('[data-dashboard-progress]'))
            .map((element) => ({
                element,
                ratio: Math.min(100, Math.max(0, Number(element.dataset.dashboardProgress || 0))) / 100,
            }));

        const renderFinalState = () => {
            counters.forEach((counter) => {
                counter.element.textContent = formatter.format(counter.target);
            });

            meters.forEach(({ element, ratio }) => {
                if (window.gsap) {
                    window.gsap.set(element, { scaleX: ratio, transformOrigin: 'left center' });
                } else {
                    element.style.transform = `scaleX(${ratio})`;
                    element.style.transformOrigin = 'left center';
                }
            });
        };

        this.counterTween?.kill();
        this.progressTweens.forEach((tween) => tween.kill());
        this.progressTweens = [];
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

                this.progressTweens = meters.map(({ element, ratio }, index) => window.gsap.fromTo(
                    element,
                    { scaleX: 0, transformOrigin: 'left center' },
                    {
                        scaleX: ratio,
                        duration: 1.05,
                        delay: index * 0.08,
                        ease: 'power3.out',
                        overwrite: 'auto',
                    },
                ));
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
    // Anruf-Pushes duerfen NICHT als generischer "messages"-Toast enden:
    // Das Klingeln uebernimmt das Reverb-Overlay. Der Fehler hier laesst das
    // ACK aus, damit der Service Worker die echte OS-Benachrichtigung zeigt —
    // der Weck-Fallback, falls Reverb gerade nicht verbunden ist.
    if (String(payload?.category || '') === 'calls') {
        throw new Error('calls-push wird als OS-Benachrichtigung angezeigt');
    }

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
    const sidebar = document.getElementById('app-sidebar');
    const canOpen = window.innerWidth < MOBILE_SIDEBAR_BREAKPOINT
        && sidebar?.isConnected === true;

    document.body.classList.toggle('sidebar-enable', Boolean(open) && canOpen);
    syncSidebarToggleState();
}

/*
 * Zurueck-Navigation ueber einen selbst gefuehrten Seitenverlauf.
 *
 * Die fruehere Loesung traversierte die Browser-Historie mit history.go(-1)
 * und kaempfte per popstate-Schleife gegen die Folgen: Livewire stellt bei
 * popstate eingefrorene HTML-Snapshots samt altem Alpine-Zustand wieder her —
 * offene Menues, tote Listener, veralteter Komponentenzustand. Deshalb wird
 * der Verlauf der besuchten Seiten jetzt selbst gefuehrt (sessionStorage:
 * pro Tab, ueberlebt Reloads) und "Zurueck" ist ein regulaerer
 * Livewire.navigate()-Besuch der Vorseite: frischer Serverzustand, frisches
 * Alpine, keine Snapshot-Wiederherstellung.
 */
const RT_BACK_STACK_KEY = 'railtime:back-stack';
const RT_BACK_STACK_LIMIT = 50;
let rtBackNavigationTarget = null;

function readBackStack() {
    try {
        const parsed = JSON.parse(window.sessionStorage.getItem(RT_BACK_STACK_KEY) || '[]');

        return Array.isArray(parsed) ? parsed.filter((entry) => typeof entry === 'string') : [];
    } catch (_) {
        return [];
    }
}

function writeBackStack(stack) {
    try {
        window.sessionStorage.setItem(
            RT_BACK_STACK_KEY,
            JSON.stringify(stack.slice(-RT_BACK_STACK_LIMIT)),
        );
    } catch (_) {
        // Privater Modus: Zurueck faellt dann auf den Fallback-Link zurueck.
    }
}

function rtCurrentPageUrl() {
    return window.location.pathname + window.location.search;
}

function recordPageVisit() {
    const current = rtCurrentPageUrl();

    // Nach einem programmatischen Zurueck-Sprung liegt die Zielseite bereits
    // als Spitze im Verlauf — erneutes Aufsetzen ergaebe eine Schleife
    // (zurueck, zurueck fuehrte wieder VORWAERTS).
    if (rtBackNavigationTarget === current) {
        rtBackNavigationTarget = null;

        return;
    }

    rtBackNavigationTarget = null;

    const stack = readBackStack();

    if (stack[stack.length - 1] !== current) {
        stack.push(current);
        writeBackStack(stack);
    }
}

function closeTransientNavigationUi() {
    clearSidebarCollapseTimer();
    clearSidebarExpandTimer();
    setDesktopSidebarExpanded(false);
    setMobileSidebarOpen(false);

    document.documentElement.classList.remove('rt-topbar-search-open');

    // Alpine-Komponenten in teleportierten Ebenen koennen nicht verlaesslich
    // ueber einen gemeinsamen DOM-Elternknoten geschlossen werden. Ein
    // Fensterereignis erreicht dagegen Sidebar, Suche, Dropdowns und Modale
    // auch dann, wenn Livewire den eigentlichen Seiten-Body gerade ersetzt.
    window.dispatchEvent(new CustomEvent('rt-navigation:prepare'));
    window.dispatchEvent(new CustomEvent('rt-topbar-search-close', {
        detail: { restoreFocus: false },
    }));
    window.dispatchEvent(new CustomEvent('close'));
}

function backToPreviousPage(fallbackUrl = '/') {
    closeTransientNavigationUi();

    const stack = readBackStack();
    const current = rtCurrentPageUrl();

    // Spitze = aktuelle Seite; darunter liegt das Ziel.
    if (stack[stack.length - 1] === current) {
        stack.pop();
    }

    const target = stack.length > 0
        ? stack[stack.length - 1]
        : String(fallbackUrl || '/');

    // Der Verlauf soll nach dem Sprung wieder mit der Zielseite als Spitze
    // enden — auch wenn er leer war und der Fallback greift.
    if (stack.length === 0) {
        stack.push(target);
    }

    writeBackStack(stack);
    rtBackNavigationTarget = target;

    // Regulaerer SPA-Besuch statt history.go(-1): Livewire mountet die
    // Vorseite frisch, nichts wird aus einem Snapshot wiederbelebt.
    if (window.Livewire?.navigate) {
        window.Livewire.navigate(target);
    } else {
        window.location.assign(target);
    }
}

// Jede angekommene Seite in den Verlauf aufnehmen: livewire:navigated feuert
// bei SPA-Wechseln UND beim ersten Laden; der DOMContentLoaded-Fallback deckt
// Seiten ab, auf denen Livewire nicht startet. Doppelte Eintraege verhindert
// der Spitzen-Vergleich in recordPageVisit().
document.addEventListener('livewire:navigated', recordPageVisit);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', recordPageVisit, { once: true });
} else {
    recordPageVisit();
}

window.RTNavigation = {
    backToPreviousPage,
    closeTransientUi: closeTransientNavigationUi,
};

function initMobileSidebarSwipe() {
    if (window.__rtMobileSidebarSwipeBound === true) {
        return;
    }

    window.__rtMobileSidebarSwipeBound = true;

    document.addEventListener('touchstart', (event) => {
        if (
            window.innerWidth >= MOBILE_SIDEBAR_BREAKPOINT
            || event.touches.length !== 1
            || document.getElementById('app-sidebar')?.isConnected !== true
        ) {
            sidebarSwipeStart = null;
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest(MOBILE_SIDEBAR_SWIPE_EXCLUSION_SELECTOR)) {
            sidebarSwipeStart = null;
            return;
        }

        const touch = event.touches[0];
        const sidebarOpen = document.body.classList.contains('sidebar-enable');

        sidebarSwipeStart = {
            x: touch.clientX,
            y: touch.clientY,
            sidebarOpen,
        };
    }, { passive: true });

    document.addEventListener('touchend', (event) => {
        if (
            !sidebarSwipeStart
            || event.changedTouches.length !== 1
            || window.innerWidth >= MOBILE_SIDEBAR_BREAKPOINT
            || document.getElementById('app-sidebar')?.isConnected !== true
        ) {
            sidebarSwipeStart = null;
            return;
        }

        const touch = event.changedTouches[0];
        const action = resolveMobileSidebarSwipe({
            startX: sidebarSwipeStart.x,
            startY: sidebarSwipeStart.y,
            endX: touch.clientX,
            endY: touch.clientY,
            sidebarOpen: sidebarSwipeStart.sidebarOpen,
            viewportWidth: window.innerWidth,
        });

        // Nur eine bestaetigte horizontale Geste unterdrueckt den synthetischen
        // Folgeklick. Normales Tippen und vertikales Scrollen bleiben nativ.
        if (action && event.cancelable) {
            event.preventDefault();
        }

        if (action === 'open') {
            setMobileSidebarOpen(true);
            initMenuItemScroll();
        } else if (action === 'close') {
            setMobileSidebarOpen(false);
        }

        sidebarSwipeStart = null;
    }, { passive: false });

    document.addEventListener('touchcancel', () => {
        sidebarSwipeStart = null;
    }, { passive: true });

    document.addEventListener('livewire:navigating', () => {
        sidebarSwipeStart = null;
    });
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
    sidebarSwipeStart = null;

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

                // Auf Touchgeraeten darf ein Backdrop-Tipp erst beim Click
                // schliessen. So bleibt der Ausgangszustand fuer eine echte
                // Rechts-nach-links-Geste bis touchend erhalten.
                if (event.pointerType === 'touch' && document.body.classList.contains('sidebar-enable')) {
                    return;
                }

                if (!target || !target.closest('.vertical-menu, .vertical-menu-btn')) {
                    setMobileSidebarOpen(false);
                }
            },
            true
        );

        document.addEventListener(
            'click',
            (event) => {
                if (isDesktopHoverSidebar() || !document.body.classList.contains('sidebar-enable')) {
                    return;
                }

                const target = event.target instanceof Element ? event.target : null;

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
