/**
 * Meldungen der Anwendung auf SweetAlert2.
 *
 * Loest den handgeschriebenen Toast aus public/js/rt-toast.js ab. Der bisherige
 * Vertrag bleibt erhalten, weil ihn rund 70 Stellen voraussetzen:
 *
 * - drei Ereignisnamen (swal:toast, swal:alert, showAlert) mit identischer
 *   Nutzlast, inklusive der Kurzform ['Text', 'type'],
 * - passender Ton ueber rt-sounds.js, abschaltbar per `sound: false`,
 * - Dopplungsschutz: dasselbe Ereignis innerhalb 500 ms erscheint einmal,
 * - `redirectTo` leitet nach Ablauf der Anzeige weiter.
 *
 * SweetAlert2 zeigt immer nur EINE Meldung — ein zweites fire() ersetzt die
 * laufende. Die frühere Loesung stapelte dagegen beliebig viele. Deshalb laufen
 * die Meldungen hier durch eine Warteschlange.
 *
 * Wichtig dabei: die Warteschlange darf unter keinen Umstaenden haengen
 * bleiben, sonst verstummt die gesamte Anwendung. `didClose` allein genuegt
 * als Freigabe nicht — es entfaellt, wenn Livewire bei wire:navigate den Body
 * austauscht (der Container haengt am alten Body), wenn ein Hintergrundtab die
 * Schliess-Animation anhaelt oder wenn jemand Swal.fire() direkt aufruft.
 * Freigegeben wird deshalb ueber `release()`, das aus vier Richtungen
 * erreichbar ist: didClose, Zeitwaechter, Seitenwechsel und Sichtbarkeit.
 */

import Swal from 'sweetalert2/dist/sweetalert2.js';
import 'sweetalert2/dist/sweetalert2.min.css';

const DEFAULT_TIMER = 4000;
// Stehen weitere Meldungen an, wird schneller durchgeschaltet — sonst waere
// die fuenfte erst nach 20 Sekunden zu sehen.
const BACKLOG_TIMER = 2000;
const DUPLICATE_WINDOW_MS = 500;
const MAX_QUEUE = 12;
// Sicherheitsabstand des Zeitwaechters auf die Anzeigedauer.
const WATCHDOG_BUFFER_MS = 1500;

const TITLES = {
    success: 'Erfolg',
    warning: 'Warnung',
    error: 'Fehler',
    info: 'Hinweis',
};

const ICONS = {
    success: 'success',
    warning: 'warning',
    error: 'error',
    info: 'info',
};

const recentlyShown = new Map();
const queue = [];

let isShowing = false;
let watchdogId = null;

/**
 * Die Nutzlast kommt aus Livewire (Objekt), aus Alpine (Array mit Objekt)
 * oder in der Kurzform ['Text', 'type'].
 */
function normalizeDetail(detail) {
    if (Array.isArray(detail)) {
        if (typeof detail[0] === 'string') {
            return { text: detail[0], type: detail[1] || 'info' };
        }

        return detail[0] || {};
    }

    if (detail && typeof detail[0] === 'string') {
        return { text: detail[0], type: detail[1] || 'info' };
    }

    return detail || {};
}

function isDuplicate(detail) {
    const signature = JSON.stringify([
        detail.type || 'info',
        detail.title || '',
        detail.text || '',
        detail.redirectTo || '',
    ]);
    const now = Date.now();
    const lastShownAt = recentlyShown.get(signature) || 0;

    recentlyShown.set(signature, now);

    recentlyShown.forEach((shownAt, key) => {
        if (now - shownAt > 2000) {
            recentlyShown.delete(key);
        }
    });

    return now - lastShownAt < DUPLICATE_WINDOW_MS;
}

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    showCloseButton: true,
    timerProgressBar: true,
    customClass: {
        popup: 'rt-swal-toast',
        title: 'rt-swal-toast__title',
        htmlContainer: 'rt-swal-toast__message',
        timerProgressBar: 'rt-swal-toast__progress',
        closeButton: 'rt-swal-toast__close',
    },
    didOpen: (popup) => {
        // Nur auf Zeigergeraeten anhalten. Auf Touch erzeugt ein Tipp ein
        // mouseenter ohne folgendes mouseleave — die Meldung bliebe stehen und
        // mit ihr die ganze Warteschlange.
        if (window.matchMedia('(hover: hover)').matches) {
            popup.addEventListener('mouseenter', Swal.stopTimer);
            popup.addEventListener('mouseleave', Swal.resumeTimer);
        }
    },
});

function clearWatchdog() {
    if (watchdogId !== null) {
        window.clearTimeout(watchdogId);
        watchdogId = null;
    }
}

/** Einziger Weg, die Warteschlange wieder freizugeben. */
function release() {
    clearWatchdog();
    isShowing = false;
    showNext();
}

/**
 * Faellt `didClose` aus, entriegelt der Waechter. Haelt der Nutzer die Meldung
 * mit dem Zeiger offen, wird er neu gestellt statt abzuschneiden.
 */
function armWatchdog(timer) {
    clearWatchdog();

    watchdogId = window.setTimeout(() => {
        watchdogId = null;

        if (Swal.isVisible()) {
            armWatchdog(timer);

            return;
        }

        release();
    }, timer + WATCHDOG_BUFFER_MS);
}

function playSound(detail) {
    if (detail.sound === false || !window.RTSound) {
        return;
    }

    const type = detail.type || 'info';

    try {
        window.RTSound.play(typeof detail.sound === 'string' ? detail.sound : type);
    } catch (error) {
        console.warn('[rt-alerts] Ton konnte nicht abgespielt werden', error);
    }
}

function showNext() {
    const detail = queue.shift();

    if (!detail) {
        isShowing = false;

        return;
    }

    isShowing = true;

    const type = detail.type || 'info';
    const timer = detail.timer || (queue.length > 0 ? BACKLOG_TIMER : DEFAULT_TIMER);

    try {
        toast.fire({
            icon: ICONS[type] || ICONS.info,
            title: detail.title || TITLES[type] || TITLES.info,
            text: detail.text || '',
            timer,
            didClose: () => release(),
        });

        armWatchdog(timer);
    } catch (error) {
        // Auch ein Fehler beim Anzeigen darf die Warteschlange nicht stilllegen.
        console.error('[rt-alerts] Meldung konnte nicht angezeigt werden', error);
        release();

        return;
    }

    // Die Weiterleitung haengt an der Anzeigedauer dieser Meldung, nicht am
    // Zeitpunkt des Ereignisses — sonst liefe sie waehrend der Wartezeit ab.
    if (detail.redirectTo) {
        window.setTimeout(() => window.location.assign(detail.redirectTo), timer);
    }
}

function showToast(rawDetail) {
    const detail = normalizeDetail(rawDetail);

    if (isDuplicate(detail)) {
        return;
    }

    // Der Ton gehoert zum Ereignis, nicht zur Anzeige: so klingt es wie zuvor
    // sofort, auch wenn die Meldung noch kurz wartet.
    playSound(detail);

    if (queue.length >= MAX_QUEUE) {
        console.warn('[rt-alerts] Warteschlange voll, Meldung verworfen:', detail);

        return;
    }

    queue.push(detail);

    if (!isShowing) {
        showNext();
    }
}

/**
 * Das Modul wird im Vite-Bundle nur einmal ausgewertet. Beim Hot-Reload in der
 * Entwicklung passiert das erneut — der AbortController verhindert, dass ein
 * einzelnes Ereignis danach mehrere Meldungen erzeugt.
 */
if (window.__rtAlertsAbortController) {
    window.__rtAlertsAbortController.abort();
}

const controller = new AbortController();
window.__rtAlertsAbortController = controller;

const listenerOptions = { signal: controller.signal };

['swal:toast', 'swal:alert', 'showAlert'].forEach((eventName) => {
    window.addEventListener(eventName, (event) => showToast(event.detail), listenerOptions);
});

// Livewire tauscht bei wire:navigate den Body aus. Die offene Meldung haengt am
// alten Body, ihr didClose kaeme nie an. Vor dem Wechsel schliessen und den
// Zustand zuruecksetzen; nach dem Wechsel weiterlaufen lassen.
document.addEventListener('livewire:navigating', () => {
    clearWatchdog();
    isShowing = false;

    try {
        Swal.close();
    } catch (error) {
        console.warn('[rt-alerts] Meldung konnte vor dem Seitenwechsel nicht geschlossen werden', error);
    }
}, listenerOptions);

document.addEventListener('livewire:navigated', () => {
    if (!isShowing) {
        showNext();
    }
}, listenerOptions);

// Im Hintergrundtab haelt der Browser die Schliess-Animation an, sodass
// didClose ausbleibt. Beim Zurueckkehren aufraeumen.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && isShowing && !Swal.isVisible()) {
        release();
    }
}, listenerOptions);

// window.Swal wird von resources/js/wagon-list-prototype.js erwartet; RTAlert
// ist der bevorzugte Weg, weil er durch die Warteschlange laeuft.
window.Swal = Swal;
window.RTAlert = {
    show: showToast,
    swal: Swal,
};

export { showToast, Swal };
