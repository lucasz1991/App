/* RailTime Sound-Repertoire: dezente UI-Toene per Web Audio API (keine
   Audio-Dateien noetig). Stellt window.RTSound bereit:

     RTSound.play('success' | 'message' | 'chat' | 'error' | 'warning' | 'info')
         Spielt den Ton fuer ein EREIGNIS. Welche Klangsignatur dabei erklingt,
         entscheidet die Zuordnung (siehe unten) — Standard ist die
         gleichnamige Signatur.
     RTSound.preview('bell')     Spielt eine SIGNATUR direkt (Demo im Auswahlfeld),
                                 ohne Drossel und unabhaengig vom An/Aus-Schalter.
     RTSound.setMap({...})       Zuordnung Ereignis -> Signatur setzen.
     RTSound.signatures          Verfuegbare Signaturen (Auswahlliste).
     RTSound.events              Zuordenbare Ereignisse.
     RTSound.setEnabled(bool) / RTSound.toggle()

   Die Zuordnung kommt serverseitig aus <meta name="rt-sound-map"> und vereint
   die Admin-Standards mit den persoenlichen Einstellungen des Nutzers
   (app/Support/Sound/SoundLibrary.php ist die fuehrende Quelle der Schluessel).

   Der An/Aus-Zustand lebt in window.__rtSoundEnabled (funktioniert auch bei
   blockiertem Storage) und wird best-effort in localStorage ('rt-sound')
   persistiert, Standard: an. Verwendung: rt-toast.js spielt pro Toast-Typ
   automatisch den passenden Ton, app.js meldet Chat-/Nachrichteneingaenge
   und Validierungsfehler. */
(function () {
    'use strict';

    var STORAGE_KEY = 'rt-sound';
    var MIN_INTERVAL_MS = 220; // kurzer Schutz gegen technisch doppelte Events

    // Der AudioContext ueberlebt wire:navigate-Neuauswertungen dieses Scripts.
    function getContext() {
        if (window.__rtSoundContext) {
            return window.__rtSoundContext;
        }

        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            return null;
        }

        window.__rtSoundContext = new Ctx();
        return window.__rtSoundContext;
    }

    function readStoredEnabled() {
        try {
            return localStorage.getItem(STORAGE_KEY) !== 'false';
        } catch (_) {
            return true;
        }
    }

    function persistEnabled(value) {
        try {
            localStorage.setItem(STORAGE_KEY, value ? 'true' : 'false');
        } catch (_) {
            // Persistenz ist Best-Effort — der In-Memory-Zustand bleibt fuehrend,
            // damit Sound auch bei blockiertem Storage abschaltbar ist.
        }
    }

    if (window.__rtSoundEnabled === undefined) {
        window.__rtSoundEnabled = readStoredEnabled();
    }

    // Einzelnen Ton (Oszillator + Lautstaerke-Huellkurve) einplanen.
    // options: { type, from, to, at, duration, peak, lowpass, pan, attack }
    function scheduleTone(ctx, options) {
        var start = ctx.currentTime + (options.at || 0);
        var duration = options.duration || 0.15;
        var peak = options.peak || 0.1;
        var attack = Math.min(options.attack || 0.012, duration * 0.45);

        var oscillator = ctx.createOscillator();
        oscillator.type = options.type || 'sine';
        oscillator.frequency.setValueAtTime(options.from, start);
        if (options.to && options.to !== options.from) {
            oscillator.frequency.exponentialRampToValueAtTime(options.to, start + duration);
        }

        var gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(peak, start + attack);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);

        var node = oscillator;
        if (options.lowpass) {
            var filter = ctx.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.setValueAtTime(options.lowpass, start);
            node.connect(filter);
            node = filter;
        }

        // Dezente Stereobreite laesst die mehrschichtigen Messenger-Toene
        // luftiger wirken. Aeltere Safari-Versionen fallen sauber auf Mono
        // zurueck, wenn StereoPannerNode nicht verfuegbar ist.
        if (typeof ctx.createStereoPanner === 'function' && options.pan) {
            var panner = ctx.createStereoPanner();
            panner.pan.setValueAtTime(options.pan, start);
            node.connect(panner);
            node = panner;
        }

        node.connect(gain);
        gain.connect(ctx.destination);

        oscillator.start(start);
        oscillator.stop(start + duration + 0.05);
    }

    // Das Repertoire: pro Name eine kurze, klar unterscheidbare Klangsignatur.
    // Jede Signatur ist frei jedem Ereignis zuordenbar.
    var SIGNATURES = {
        // --- Die urspruenglichen sechs -------------------------------------
        // Speichern/Erfolg: freundlicher Zweiklang aufwaerts (E5 -> B5).
        success: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 659, at: 0, duration: 0.16, peak: 0.14 });
            scheduleTone(ctx, { type: 'sine', from: 988, at: 0.09, duration: 0.22, peak: 0.12 });
        },
        // Interne Nachricht: warmer, kurzer Dreiklang mit weichem Grundkoerper.
        message: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 294, to: 330, at: 0, duration: 0.28, peak: 0.045, lowpass: 900, pan: -0.12 });
            scheduleTone(ctx, { type: 'triangle', from: 587, to: 659, at: 0, duration: 0.2, peak: 0.075, lowpass: 2600, pan: -0.22 });
            scheduleTone(ctx, { type: 'sine', from: 880, to: 988, at: 0.065, duration: 0.22, peak: 0.065, pan: 0.2 });
            scheduleTone(ctx, { type: 'sine', from: 1175, to: 1319, at: 0.135, duration: 0.24, peak: 0.04, pan: 0.1 });
        },
        // Chat: heller Glas-Doppelimpuls mit feinem Oberton-Schimmer.
        chat: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 494, to: 554, at: 0, duration: 0.2, peak: 0.038, lowpass: 1200, pan: -0.08 });
            scheduleTone(ctx, { type: 'triangle', from: 740, to: 988, at: 0, duration: 0.17, peak: 0.07, lowpass: 3200, pan: -0.24, attack: 0.008 });
            scheduleTone(ctx, { type: 'sine', from: 1480, to: 1319, at: 0.018, duration: 0.14, peak: 0.03, pan: 0.2, attack: 0.006 });
            scheduleTone(ctx, { type: 'triangle', from: 988, to: 1319, at: 0.115, duration: 0.21, peak: 0.075, lowpass: 3400, pan: 0.24, attack: 0.008 });
            scheduleTone(ctx, { type: 'sine', from: 1976, to: 1760, at: 0.135, duration: 0.15, peak: 0.022, pan: -0.14, attack: 0.006 });
        },
        // Validierungs-/Fehlerfall: gedaempfter Doppelton abwaerts.
        error: function (ctx) {
            scheduleTone(ctx, { type: 'sawtooth', from: 220, at: 0, duration: 0.13, peak: 0.11, lowpass: 720 });
            scheduleTone(ctx, { type: 'sawtooth', from: 174, at: 0.15, duration: 0.2, peak: 0.11, lowpass: 640 });
        },
        // Warnung: einzelner mittlerer Hinweiston.
        warning: function (ctx) {
            scheduleTone(ctx, { type: 'triangle', from: 392, at: 0, duration: 0.2, peak: 0.1 });
        },
        // Neutraler Hinweis: sehr kurzer, leiser Tick.
        info: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 880, at: 0, duration: 0.08, peak: 0.06 });
        },

        // --- Zusaetzliche, frei waehlbare Signaturen -----------------------
        // Glocke: langer, weich ausklingender Anschlag mit Quint-Oberton.
        bell: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 1046, at: 0, duration: 0.75, peak: 0.085, attack: 0.006 });
            scheduleTone(ctx, { type: 'sine', from: 1568, at: 0.005, duration: 0.5, peak: 0.03, attack: 0.006, pan: 0.12 });
            scheduleTone(ctx, { type: 'sine', from: 523, at: 0.01, duration: 0.9, peak: 0.035, lowpass: 1400, pan: -0.12 });
        },
        // Ping: einzelner, klarer Impuls — sehr kurz, gut fuer haeufige Ereignisse.
        ping: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 1320, to: 1760, at: 0, duration: 0.12, peak: 0.09, attack: 0.005 });
        },
        // Marimba: holziger Dreiklang aufwaerts, freundlich und unaufdringlich.
        marimba: function (ctx) {
            scheduleTone(ctx, { type: 'triangle', from: 523, at: 0, duration: 0.18, peak: 0.09, lowpass: 2200 });
            scheduleTone(ctx, { type: 'triangle', from: 659, at: 0.075, duration: 0.18, peak: 0.08, lowpass: 2200 });
            scheduleTone(ctx, { type: 'triangle', from: 784, at: 0.15, duration: 0.24, peak: 0.075, lowpass: 2400 });
        },
        // Puls: zwei tiefe, satte Impulse — spuerbar, ohne schrill zu sein.
        pulse: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 196, at: 0, duration: 0.14, peak: 0.13, lowpass: 600 });
            scheduleTone(ctx, { type: 'sine', from: 196, at: 0.17, duration: 0.14, peak: 0.11, lowpass: 600 });
        },
        // Doppelklick: zwei sehr kurze, trockene Ticks.
        doubleclick: function (ctx) {
            scheduleTone(ctx, { type: 'square', from: 1200, at: 0, duration: 0.035, peak: 0.05, lowpass: 2600, attack: 0.003 });
            scheduleTone(ctx, { type: 'square', from: 1200, at: 0.085, duration: 0.035, peak: 0.05, lowpass: 2600, attack: 0.003 });
        },
        // Glas: heller, glaesern schimmernder Akkord.
        glass: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 1568, at: 0, duration: 0.3, peak: 0.05, attack: 0.005, pan: -0.18 });
            scheduleTone(ctx, { type: 'sine', from: 2093, at: 0.03, duration: 0.34, peak: 0.035, attack: 0.005, pan: 0.18 });
            scheduleTone(ctx, { type: 'sine', from: 2637, at: 0.06, duration: 0.28, peak: 0.02, attack: 0.005 });
        },
        // Aufsteigend: kurzer Sweep nach oben — gut fuer "fertig"/"gesendet".
        sweep: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 440, to: 1320, at: 0, duration: 0.26, peak: 0.085, attack: 0.01 });
        },
        // Absteigend: kurzer Sweep nach unten — gut fuer "verworfen"/"abgebrochen".
        descend: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 1046, to: 392, at: 0, duration: 0.28, peak: 0.085, attack: 0.01 });
        },
        // Stumm: bewusst kein Ton, damit ein Ereignis einzeln abgeschaltet
        // werden kann, ohne den globalen Schalter zu benutzen.
        silent: function () {}
    };

    // Ereignis -> Signatur. Leer bedeutet: gleichnamige Signatur verwenden.
    var EVENT_SIGNATURES = window.__rtSoundMap || {};

    function readMapFromDocument() {
        try {
            var meta = document.querySelector('meta[name="rt-sound-map"]');
            if (!meta) {
                return null;
            }

            var parsed = JSON.parse(meta.getAttribute('content') || '{}');

            return (parsed && typeof parsed === 'object') ? parsed : null;
        } catch (_) {
            return null;
        }
    }

    function applyMap(map) {
        if (!map || typeof map !== 'object') {
            return;
        }

        Object.keys(map).forEach(function (event) {
            var signature = map[event];
            if (typeof signature === 'string' && SIGNATURES[signature]) {
                EVENT_SIGNATURES[event] = signature;
            }
        });

        window.__rtSoundMap = EVENT_SIGNATURES;
    }

    applyMap(readMapFromDocument());

    // Nach jedem wire:navigate liefert der Server ggf. eine neue Zuordnung mit.
    document.addEventListener('livewire:navigated', function () {
        applyMap(readMapFromDocument());
    });

    // Sofortige Uebernahme nach dem Speichern der Ton-Einstellungen
    // (Livewire dispatcht 'rt-sounds:map' mit der neuen wirksamen Zuordnung).
    window.addEventListener('rt-sounds:map', function (event) {
        applyMap(event && event.detail ? event.detail.map : null);
    });

    function signatureFor(event) {
        var name = EVENT_SIGNATURES[event] || event;

        return SIGNATURES[name] ? name : (SIGNATURES[event] ? event : null);
    }

    var lastPlayedAt = window.__rtSoundLastPlayedAt || {};
    window.__rtSoundLastPlayedAt = lastPlayedAt;

    function startSignature(throttleKey, signature, ctx) {
        // Erst hier stempeln: verworfene Toene sollen die Drossel nicht belegen.
        if (throttleKey) {
            lastPlayedAt[throttleKey] = Date.now();
        }

        try {
            signature(ctx);
        } catch (_) {
            // Ein fehlgeschlagener Ton darf die App nie beeintraechtigen.
        }
    }

    /**
     * Gemeinsamer Abspielweg. throttleKey=null umgeht die Drossel (Demo).
     */
    function playSignature(name, throttleKey) {
        var signature = SIGNATURES[name];
        if (!signature) {
            return;
        }

        if (throttleKey && (Date.now() - (lastPlayedAt[throttleKey] || 0) < MIN_INTERVAL_MS)) {
            return;
        }

        var ctx = getContext();
        if (!ctx) {
            return;
        }

        // 'running' ist der einzige abspielbereite Zustand ('suspended' vor der
        // ersten Nutzergeste, 'interrupted' auf iOS nach Anruf/Siri). resume()
        // anstossen und den Ton nur spielen, wenn der Context prompt aufwacht —
        // ein bei Autoplay-Sperre haengendes resume()-Promise loest erst bei
        // einer spaeteren Geste auf, und dann waere der Ton kontextlos; solche
        // Toene verfallen still.
        if (ctx.state !== 'running') {
            var requestedAt = Date.now();
            ctx.resume().then(function () {
                if (Date.now() - requestedAt > 250) {
                    return;
                }
                if (throttleKey && (Date.now() - (lastPlayedAt[throttleKey] || 0) < MIN_INTERVAL_MS)) {
                    return;
                }
                startSignature(throttleKey, signature, ctx);
            }).catch(function () {});

            return;
        }

        startSignature(throttleKey, signature, ctx);
    }

    var api = {
        get enabled() {
            return window.__rtSoundEnabled;
        },

        setEnabled: function (value) {
            window.__rtSoundEnabled = Boolean(value);
            persistEnabled(window.__rtSoundEnabled);
        },

        toggle: function () {
            api.setEnabled(!window.__rtSoundEnabled);
            return window.__rtSoundEnabled;
        },

        /** Auswahlliste fuer die Einstellungen. */
        signatures: Object.keys(SIGNATURES),

        /** Rueckwaertskompatibel: frueher die Preset-Namen. */
        names: Object.keys(SIGNATURES),

        get events() {
            return Object.keys(EVENT_SIGNATURES);
        },

        setMap: function (map) {
            applyMap(map);
        },

        /** Aktuell zugeordnete Signatur eines Ereignisses. */
        signatureOf: function (event) {
            return signatureFor(event);
        },

        /** Ereignis abspielen (respektiert An/Aus und Drossel). */
        play: function (event) {
            if (!window.__rtSoundEnabled) {
                return;
            }

            var name = signatureFor(event);
            if (!name) {
                return;
            }

            // Gedrosselt wird pro EREIGNIS, nicht pro Signatur: zwei
            // verschiedene Ereignisse mit derselben Signatur sollen sich nicht
            // gegenseitig verschlucken.
            playSignature(name, event);
        },

        /**
         * Signatur zur Vorschau abspielen — ohne Drossel und bewusst auch bei
         * abgeschaltetem Ton, weil die Vorschau eine direkte Nutzeraktion im
         * Auswahlfeld ist.
         */
        preview: function (name) {
            playSignature(SIGNATURES[name] ? name : 'info', null);
        }
    };

    window.RTSound = api;

    // Den Context bei der ersten Interaktion anlegen/aufwecken, damit auch
    // spaetere programmgesteuerte Toene (z.B. eintreffende Chatnachricht ohne
    // Klick) abgespielt werden duerfen. Einmal pro Seitenleben registrieren
    // (window-Flag statt AbortController: die signal-Option ignorieren aeltere
    // Browser stillschweigend, und die Listener wuerden bei jeder
    // wire:navigate-Neuauswertung akkumulieren; unlock haelt keinerlei
    // per-Auswertung-Zustand, die Erstregistrierung bleibt daher gueltig).
    if (!window.__rtSoundUnlockBound) {
        window.__rtSoundUnlockBound = true;

        var unlock = function () {
            var ctx = getContext();
            if (ctx && ctx.state !== 'running') {
                ctx.resume().catch(function () {});
            }
        };

        ['pointerdown', 'keydown', 'touchstart'].forEach(function (eventName) {
            window.addEventListener(eventName, unlock, { passive: true, capture: true });
        });
    }
})();
