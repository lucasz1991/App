import {
    createNavigationParticleSphere,
    resolveMinimumLoaderPlaybackDelay,
} from './navigation-particle-loader';

// ---------------------------------------------------------------
// Seitenwechsel-Overlay fuer wire:navigate: ein kompakter, frei schwebender
// RailTime-Orb ohne Text oder Dialogflaeche. Choreografie: Er BEGINNT mit dem
// fertig geformten RT-Monogramm, loest es zur Kugel auf und formt es beim
// Verlassen zurueck. Die Mindestlaufzeit kommt aus dem Loader-Modul und ist
// exakt auf diese Phasen abgestimmt: Endet die Navigation noch waehrend der
// RT-Haltephase, blendet der Loader sofort aus dem Monogramm aus; mitten in
// der Aufloesung wird erst der Zyklus beendet. Nach dem body-Swap wird das
// Overlay bei Bedarf neu angehaengt.
// ---------------------------------------------------------------
function installNavigationOverlay() {
    let overlay = null;
    let announcement = null;
    let particleSphere = null;
    let failsafeTimer = null;
    let minimumPlaybackTimer = null;
    let outroTimer = null;
    let announcementTimer = null;
    let active = false;
    let contentEntrancePending = false;
    let visibleBeforeSwap = false;
    let transitionToken = 0;
    let visibleStartedAt = null;

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
        window.clearTimeout(failsafeTimer);
        window.clearTimeout(minimumPlaybackTimer);
        window.clearTimeout(outroTimer);
        window.clearTimeout(announcementTimer);
        failsafeTimer = null;
        minimumPlaybackTimer = null;
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
        visibleStartedAt = null;
        visibleBeforeSwap = false;
        removeOverlayClones();
    }

    function start() {
        contentEntrancePending = true;

        // Livewire kann fuer denselben Besuch mehr als ein navigate-Signal
        // liefern (z. B. wenn ein vorgeschalteter Autosave den Besuch kurz
        // anhaelt und anschliessend fortsetzt). Der Loader repraesentiert den
        // gesamten Seitenwechsel und ist deshalb bewusst idempotent. Ein
        // Zaehler wuerde bei zwei Startsignalen, aber nur einem Abschluss,
        // bis zur Notbremse sichtbar bleiben.
        if (active) {
            window.clearTimeout(failsafeTimer);
            failsafeTimer = window.setTimeout(done, FAILSAFE_MS);

            return;
        }

        transitionToken += 1;
        active = true;
        clearTransitionTimers();
        hideImmediately();

        const o = ensureOverlay();
        o.classList.remove('is-leaving', 'is-leaving--quick', 'is-leaving--logo');
        o.setAttribute('aria-hidden', 'false');
        o.classList.add('is-visible');
        syncAnnouncement({ announce: true });
        visibleStartedAt = window.performance.now();
        particleSphere?.start();
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

    function beginOutro(token) {
        if (token !== transitionToken) {
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

    function done(options = {}) {
        const animate = options?.animate === true;
        const token = transitionToken;
        const wasVisible = visibleBeforeSwap || overlay?.classList.contains('is-visible');

        active = false;
        clearTransitionTimers();
        removeOverlayClones();

        if (!animate || !wasVisible) {
            transitionToken += 1;
            hideImmediately();

            return;
        }

        // onSwap setzt den Loader normalerweise schon in den neuen Body. Der
        // Fallback muss jetzt greifen (nicht erst beim Outro), damit auch die
        // Mindestlaufzeit in Browser-/Livewire-Pfaden ohne onSwap sichtbar ist.
        const o = ensureOverlay();
        o.setAttribute('aria-hidden', 'false');
        o.classList.add('is-visible');

        // Bei extrem schnellen Antworten kann die per Timeout angestossene
        // erneute Live-Ansage noch ausstehen. Der sichtbare Mindestlauf darf
        // deshalb nie mit einem leeren Statusknoten weiterlaufen.
        syncAnnouncement();

        const minimumDelay = resolveMinimumLoaderPlaybackDelay(
            visibleStartedAt,
            window.performance.now(),
            {
                reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                forcedColors: window.matchMedia('(forced-colors: active)').matches,
            }
        );

        if (minimumDelay > 0) {
            minimumPlaybackTimer = window.setTimeout(function () {
                minimumPlaybackTimer = null;
                beginOutro(token);
            }, minimumDelay);

            return;
        }

        beginOutro(token);
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
        // programmatische/abweichende Livewire-Pfade, ohne denselben aktiven
        // Seitenwechsel ein zweites Mal zu starten.
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
        if (!active) {
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
}

export { installNavigationOverlay };
