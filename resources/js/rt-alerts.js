/**
 * Meldungen der Anwendung auf SweetAlert2.
 *
 * Loest den handgeschriebenen Toast aus public/js/rt-toast.js ab. Die
 * bisherigen Eigenschaften bleiben erhalten, weil der Rest der Anwendung sie
 * voraussetzt:
 *
 * - drei Ereignisnamen (swal:toast, swal:alert, showAlert) mit identischer
 *   Nutzlast, inklusive der Kurzform ['Text', 'type'],
 * - passender Ton ueber rt-sounds.js, abschaltbar per `sound: false`,
 * - Dopplungsschutz: dasselbe Ereignis innerhalb 500 ms erscheint einmal,
 * - `redirectTo` leitet nach Ablauf der Anzeige weiter,
 * - erneutes Auswerten bei wire:navigate erzeugt keine doppelten Listener.
 */

import Swal from 'sweetalert2/dist/sweetalert2.js';
import 'sweetalert2/dist/sweetalert2.min.css';

const DEFAULT_TIMER = 4000;
const DUPLICATE_WINDOW_MS = 500;

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
    // Auf dem Telefon steht der Toast oben mittig und nutzt die volle Breite;
    // die Klassen liegen in resources/css/app.css.
    customClass: {
        popup: 'rt-swal-toast',
        title: 'rt-swal-toast__title',
        htmlContainer: 'rt-swal-toast__message',
        timerProgressBar: 'rt-swal-toast__progress',
        closeButton: 'rt-swal-toast__close',
    },
    didOpen: (popup) => {
        popup.addEventListener('mouseenter', Swal.stopTimer);
        popup.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

/**
 * SweetAlert2 zeigt immer nur eine Meldung: ein zweites fire() ersetzt die
 * laufende. Die frühere Eigenbau-Loesung stapelte dagegen. Damit bei mehreren
 * Meldungen kurz hintereinander keine verloren geht, laufen sie hier durch
 * eine Warteschlange und erscheinen nacheinander.
 */
const queue = [];
const MAX_QUEUE = 5;
let isShowing = false;

function showNext() {
    const detail = queue.shift();

    if (!detail) {
        isShowing = false;

        return;
    }

    isShowing = true;

    const type = detail.type || 'info';
    const timer = detail.timer || DEFAULT_TIMER;

    // Passender Ton (rt-sounds.js). `sound` erlaubt einen abweichenden Ton
    // (z. B. 'message' bei Chat-Meldungen) oder false zum Unterdruecken.
    // Er erklingt erst, wenn die Meldung wirklich sichtbar wird.
    if (detail.sound !== false && window.RTSound) {
        window.RTSound.play(typeof detail.sound === 'string' ? detail.sound : type);
    }

    toast.fire({
        icon: ICONS[type] || ICONS.info,
        title: detail.title || TITLES[type] || TITLES.info,
        text: detail.text || '',
        timer,
        didClose: () => showNext(),
    });

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

    // Deckel gegen eine Flut aus fehlerhaften Schleifen: die frühesten
    // Meldungen bleiben, spaetere werden verworfen.
    if (queue.length >= MAX_QUEUE) {
        return;
    }

    queue.push(detail);

    if (!isShowing) {
        showNext();
    }
}

/**
 * Bei wire:navigate wird das Bundle nicht neu ausgewertet, ein Hot-Reload in
 * der Entwicklung dagegen schon. Der AbortController verhindert, dass ein
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

// Fuer Fenster, die ausserhalb des Bundles arbeiten (z. B. Debug-Konsole).
window.RTAlert = {
    show: showToast,
    swal: Swal,
};

export { showToast, Swal };
