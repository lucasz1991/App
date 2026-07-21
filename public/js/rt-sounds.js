/* RailTime Sound-Repertoire: dezente UI-Toene per Web Audio API (keine
   Audio-Dateien noetig). Stellt window.RTSound bereit:
     RTSound.play('success' | 'message' | 'error' | 'warning' | 'info')
     RTSound.setEnabled(true|false) / RTSound.toggle()
   Einstellung wird in localStorage ('rt-sound') gemerkt, Standard: an.
   Verwendung: rt-toast.js spielt pro Toast-Typ automatisch den passenden
   Ton, app.js meldet Chat-/Nachrichteneingaenge und Validierungsfehler. */
(function () {
    'use strict';

    var STORAGE_KEY = 'rt-sound';
    var MIN_INTERVAL_MS = 350; // gleicher Ton fruehestens alle 350ms (Doppel-Events)

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

    function readEnabled() {
        try {
            return localStorage.getItem(STORAGE_KEY) !== 'false';
        } catch (_) {
            return true;
        }
    }

    function writeEnabled(value) {
        try {
            localStorage.setItem(STORAGE_KEY, value ? 'true' : 'false');
        } catch (_) {
            // Sound funktioniert auch ohne persistente Einstellung.
        }
    }

    // Einzelnen Ton (Oszillator + Lautstaerke-Huellkurve) einplanen.
    // options: { type, from, to, at, duration, peak, lowpass }
    function scheduleTone(ctx, options) {
        var start = ctx.currentTime + (options.at || 0);
        var duration = options.duration || 0.15;
        var peak = options.peak || 0.1;

        var oscillator = ctx.createOscillator();
        oscillator.type = options.type || 'sine';
        oscillator.frequency.setValueAtTime(options.from, start);
        if (options.to && options.to !== options.from) {
            oscillator.frequency.exponentialRampToValueAtTime(options.to, start + duration);
        }

        var gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(peak, start + 0.012);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);

        var node = oscillator;
        if (options.lowpass) {
            var filter = ctx.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.setValueAtTime(options.lowpass, start);
            node.connect(filter);
            node = filter;
        }

        node.connect(gain);
        gain.connect(ctx.destination);

        oscillator.start(start);
        oscillator.stop(start + duration + 0.05);
    }

    // Das Repertoire: pro Name eine kurze, klar unterscheidbare Klangsignatur.
    var PRESETS = {
        // Speichern/Erfolg: freundlicher Zweiklang aufwaerts (E5 -> B5).
        success: function (ctx) {
            scheduleTone(ctx, { type: 'sine', from: 659, at: 0, duration: 0.16, peak: 0.14 });
            scheduleTone(ctx, { type: 'sine', from: 988, at: 0.09, duration: 0.22, peak: 0.12 });
        },
        // Neue Chat-/Posteingangsnachricht: weiches "Plopp" mit Aufwaertsglide.
        message: function (ctx) {
            scheduleTone(ctx, { type: 'triangle', from: 540, to: 810, at: 0, duration: 0.18, peak: 0.13 });
            scheduleTone(ctx, { type: 'sine', from: 1080, at: 0.05, duration: 0.1, peak: 0.05 });
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
        }
    };

    var lastPlayedAt = window.__rtSoundLastPlayedAt || {};
    window.__rtSoundLastPlayedAt = lastPlayedAt;

    var api = {
        get enabled() {
            return readEnabled();
        },

        setEnabled: function (value) {
            writeEnabled(Boolean(value));
        },

        toggle: function () {
            var next = !readEnabled();
            writeEnabled(next);
            return next;
        },

        names: Object.keys(PRESETS),

        play: function (name) {
            if (!readEnabled()) {
                return;
            }

            var preset = PRESETS[name];
            if (!preset) {
                return;
            }

            var now = Date.now();
            if (now - (lastPlayedAt[name] || 0) < MIN_INTERVAL_MS) {
                return;
            }

            var ctx = getContext();
            if (!ctx) {
                return;
            }

            // Autoplay-Policy: ohne vorherige Nutzer-Interaktion bleibt der
            // Context "suspended" — dann den Ton still verwerfen statt ihn
            // spaeter unpassend nachzuholen.
            if (ctx.state === 'suspended') {
                ctx.resume().catch(function () {});
                if (ctx.state === 'suspended') {
                    return;
                }
            }

            lastPlayedAt[name] = now;

            try {
                preset(ctx);
            } catch (_) {
                // Ein fehlgeschlagener Ton darf die App nie beeintraechtigen.
            }
        }
    };

    window.RTSound = api;

    // Den Context bei der ersten Interaktion aufwecken, damit auch spaetere
    // programmgesteuerte Toene (z.B. eintreffende Chatnachricht ohne Klick)
    // abgespielt werden duerfen. Listener-Neuregistrierung bei wire:navigate
    // wie in rt-toast.js ueber AbortController entkoppeln.
    if (window.__rtSoundAbortController) {
        window.__rtSoundAbortController.abort();
    }

    var listenerController = new AbortController();
    window.__rtSoundAbortController = listenerController;

    function unlock() {
        var ctx = getContext();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume().catch(function () {});
        }
    }

    ['pointerdown', 'keydown', 'touchstart'].forEach(function (eventName) {
        window.addEventListener(eventName, unlock, {
            signal: listenerController.signal,
            passive: true,
            capture: true
        });
    });
})();
