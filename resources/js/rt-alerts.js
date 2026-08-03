/**
 * Zentrale Meldungen der Anwendung im SweetAlert2-Design.
 *
 * SweetAlert2 selbst verwaltet immer nur eine globale Instanz. Ein weiteres
 * `Swal.fire()` entfernt deshalb den vorherigen Toast. RailTime rendert seine
 * kurzen Meldungen in einem eigenen Stapel mit SweetAlert2-kompatiblem Markup:
 * Die Bibliothek liefert weiterhin Geometrie und Keyframes der Symbole, waehrend
 * jede Meldung einen unabhaengigen Timer besitzt und gleichzeitig sichtbar ist.
 *
 * Der bestehende Vertrag bleibt erhalten:
 *
 * - `swal:toast`, `swal:alert` und `showAlert` akzeptieren dieselbe Nutzlast,
 * - Kurzform `['Text', 'type']`, Sound und 500-ms-Dopplungsschutz,
 * - `redirectTo` leitet nach der vollstaendigen Anzeigedauer weiter,
 * - `window.Swal` bleibt fuer echte Dialoge verfuegbar.
 */

import Swal from 'sweetalert2/dist/sweetalert2.js';
import 'sweetalert2/dist/sweetalert2.min.css';

const DEFAULT_TIMER = 4000;
const DUPLICATE_WINDOW_MS = 500;
const MAX_VISIBLE_TOASTS = 12;
const STACK_ID = 'rt-swal-stack';
const STACK_MOVE_DURATION_MS = 260;
const HIDE_FALLBACK_MS = 180;

const TITLES = {
    success: 'Erfolg',
    warning: 'Warnung',
    error: 'Fehler',
    info: 'Hinweis',
};

const ICON_CONTENT = {
    warning: '!',
    info: 'i',
};

const recentlyShown = new Map();
const toastRecords = new Set();
const recordsByItem = new WeakMap();

let toastSequence = 0;

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

function normalizeType(type) {
    return Object.hasOwn(TITLES, type) ? type : 'info';
}

function normalizeTimer(timer) {
    const parsedTimer = Number(timer);

    return Number.isFinite(parsedTimer) && parsedTimer > 0 ? parsedTimer : DEFAULT_TIMER;
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

function playSound(detail) {
    if (detail.sound === false || !window.RTSound) {
        return;
    }

    const type = normalizeType(detail.type);

    try {
        window.RTSound.play(typeof detail.sound === 'string' ? detail.sound : type);
    } catch (error) {
        console.warn('[rt-alerts] Ton konnte nicht abgespielt werden', error);
    }
}

function createElement(tagName, className, text = null) {
    const element = document.createElement(tagName);
    element.className = className;

    if (text !== null) {
        element.textContent = text;
    }

    return element;
}

/** Markup aus SweetAlert2 11.x; die Bibliotheks-CSS behaelt die Geometrie. */
function createIcon(type) {
    const icon = createElement('div', `swal2-icon swal2-${type}`);
    icon.style.display = 'flex';
    icon.setAttribute('aria-hidden', 'true');

    if (type === 'success') {
        icon.append(
            createElement('div', 'swal2-success-circular-line-left'),
            createElement('span', 'swal2-success-line-tip'),
            createElement('span', 'swal2-success-line-long'),
            createElement('div', 'swal2-success-ring'),
            createElement('div', 'swal2-success-fix'),
            createElement('div', 'swal2-success-circular-line-right'),
        );
    } else if (type === 'error') {
        const mark = createElement('span', 'swal2-x-mark');
        mark.append(
            createElement('span', 'swal2-x-mark-line-left'),
            createElement('span', 'swal2-x-mark-line-right'),
        );
        icon.append(mark);
    } else {
        icon.append(createElement('div', 'swal2-icon-content', ICON_CONTENT[type] || 'i'));
    }

    return icon;
}

function createToastMarkup(detail, type) {
    toastSequence += 1;

    const titleId = `rt-swal-title-${toastSequence}`;
    const messageId = `rt-swal-message-${toastSequence}`;
    const item = createElement('div', 'rt-swal-stack__item');
    const popup = createElement(
        'div',
        `swal2-popup swal2-toast swal2-icon-${type} rt-swal-toast`,
    );
    const closeButton = createElement(
        'button',
        'swal2-close rt-swal-toast__close',
        '\u00d7',
    );
    const icon = createIcon(type);
    const title = createElement(
        'h2',
        'swal2-title rt-swal-toast__title',
        detail.title || TITLES[type],
    );
    const message = createElement(
        'div',
        'swal2-html-container rt-swal-toast__message',
        detail.text || '',
    );
    const progressContainer = createElement('div', 'swal2-timer-progress-bar-container');
    const progressBar = createElement(
        'div',
        'swal2-timer-progress-bar rt-swal-toast__progress',
    );

    popup.style.display = 'grid';
    popup.style.setProperty('opacity', '0', 'important');
    popup.setAttribute('role', 'alert');
    popup.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    popup.setAttribute('aria-atomic', 'true');
    popup.setAttribute('aria-labelledby', titleId);
    popup.tabIndex = -1;

    closeButton.type = 'button';
    closeButton.style.display = 'flex';
    closeButton.setAttribute('aria-label', 'Meldung schliessen');

    title.id = titleId;
    title.style.display = 'block';

    if (detail.text) {
        message.id = messageId;
        message.style.display = 'block';
        popup.setAttribute('aria-describedby', messageId);
    } else {
        message.style.display = 'none';
    }

    progressContainer.style.display = 'block';
    progressBar.style.width = '100%';
    progressContainer.append(progressBar);
    popup.append(closeButton, icon, title, message, progressContainer);
    item.append(popup);

    return { item, popup, closeButton, icon, progressBar };
}

function ensureStack() {
    let stack = document.getElementById(STACK_ID);

    if (stack?.isConnected) {
        return stack;
    }

    stack = createElement('div', 'rt-swal-stack');
    stack.id = STACK_ID;
    stack.setAttribute('aria-label', 'Benachrichtigungen');
    stack.setAttribute('data-rt-swal-stack', '');
    document.body.append(stack);

    return stack;
}

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

function capturePositions(items) {
    return new Map(items.map((item) => [item, item.getBoundingClientRect().top]));
}

/**
 * FLIP auf dem Wrapper: Der SweetAlert-Popup darf seinen eigenen Transform fuer
 * Ein-/Ausblendung behalten, waehrend bestehende Meldungen sichtbar nach unten
 * beziehungsweise nach oben gleiten.
 */
function animateStackReflow(previousPositions, items) {
    if (prefersReducedMotion()) {
        return;
    }

    items.forEach((item) => {
        const previousTop = previousPositions.get(item);

        if (previousTop === undefined) {
            return;
        }

        item.getAnimations?.().forEach((animation) => animation.cancel());

        const delta = previousTop - item.getBoundingClientRect().top;

        if (Math.abs(delta) < 0.5 || typeof item.animate !== 'function') {
            return;
        }

        item.animate(
            [
                { transform: `translate3d(0, ${delta}px, 0)` },
                { transform: 'translate3d(0, 0, 0)' },
            ],
            {
                duration: STACK_MOVE_DURATION_MS,
                easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
            },
        );
    });
}

function now() {
    return window.performance?.now?.() ?? Date.now();
}

function clearRunningTimer(record) {
    if (record.timeoutId !== null) {
        window.clearTimeout(record.timeoutId);
        record.timeoutId = null;
    }

    if (record.progressStartId !== null) {
        window.clearTimeout(record.progressStartId);
        record.progressStartId = null;
    }
}

function startTimer(record) {
    if (record.closed || record.pauseReasons.size > 0) {
        return;
    }

    clearRunningTimer(record);
    record.startedAt = now();
    record.timerVersion += 1;

    const version = record.timerVersion;
    const remaining = Math.max(0, record.remaining);
    const remainingPercent = (remaining / record.timer) * 100;

    record.progressBar.style.transition = 'none';
    record.progressBar.style.width = `${remainingPercent}%`;

    record.timeoutId = window.setTimeout(() => {
        record.timeoutId = null;
        record.remaining = 0;
        dismissToast(record);

        if (record.redirectTo) {
            window.location.assign(record.redirectTo);
        }
    }, remaining);

    // SweetAlert2 startet seinen Fortschrittsbalken ebenfalls zehn Millisekunden
    // nach dem Rendern. Der Versionszaehler verhindert veraltete Hover-Callbacks.
    record.progressStartId = window.setTimeout(() => {
        record.progressStartId = null;

        if (record.closed || record.pauseReasons.size > 0 || record.timerVersion !== version) {
            return;
        }

        record.progressBar.style.transition = `width ${remaining / 1000}s linear`;
        record.progressBar.style.width = '0%';
    }, 10);
}

function pauseToast(record, reason) {
    if (record.closed || record.pauseReasons.has(reason)) {
        return;
    }

    record.pauseReasons.add(reason);

    if (record.timeoutId === null) {
        return;
    }

    record.remaining = Math.max(0, record.remaining - (now() - record.startedAt));
    record.timerVersion += 1;
    clearRunningTimer(record);
    record.progressBar.style.transition = 'none';
    record.progressBar.style.width = `${(record.remaining / record.timer) * 100}%`;
}

function resumeToast(record, reason) {
    if (record.closed || !record.pauseReasons.has(reason)) {
        return;
    }

    record.pauseReasons.delete(reason);

    if (record.pauseReasons.size === 0) {
        startTimer(record);
    }
}

function removeToastElement(record) {
    if (record.removed) {
        return;
    }

    record.removed = true;

    if (record.removeFallbackId !== null) {
        window.clearTimeout(record.removeFallbackId);
        record.removeFallbackId = null;
    }

    record.popup.removeEventListener('animationend', record.onHideAnimationEnd);

    const stack = record.item.parentElement;
    const remainingItems = stack
        ? [...stack.children].filter((item) => item !== record.item)
        : [];
    const previousPositions = capturePositions(remainingItems);

    record.item.remove();
    toastRecords.delete(record);
    animateStackReflow(previousPositions, remainingItems);

    if (stack && stack.children.length === 0) {
        stack.remove();
    }
}

function dismissToast(record, { immediate = false } = {}) {
    if (record.closed) {
        if (immediate) {
            removeToastElement(record);
        }

        return;
    }

    record.closed = true;
    record.timerVersion += 1;
    clearRunningTimer(record);

    if (immediate || prefersReducedMotion()) {
        removeToastElement(record);

        return;
    }

    record.popup.classList.remove('swal2-show');
    record.popup.classList.add('swal2-hide');
    record.popup.addEventListener('animationend', record.onHideAnimationEnd);
    record.removeFallbackId = window.setTimeout(
        () => removeToastElement(record),
        HIDE_FALLBACK_MS,
    );
}

function evictOldestToast(stack) {
    while (stack.children.length >= MAX_VISIBLE_TOASTS) {
        const oldestItem = stack.lastElementChild;
        const oldestRecord = oldestItem ? recordsByItem.get(oldestItem) : null;

        if (!oldestRecord) {
            oldestItem?.remove();
            continue;
        }

        dismissToast(oldestRecord, { immediate: true });
    }
}

function insertToast(record) {
    const stack = ensureStack();
    evictOldestToast(stack);

    const existingItems = [...stack.children];
    const previousPositions = capturePositions(existingItems);

    // Der neueste Toast steht immer oben; alle vorhandenen werden per FLIP
    // sichtbar um seine Hoehe plus Abstand nach unten geschoben.
    stack.prepend(record.item);
    animateStackReflow(previousPositions, existingItems);

    window.setTimeout(() => {
        if (record.closed) {
            return;
        }

        record.popup.classList.add('swal2-show');
        record.icon.classList.add('swal2-icon-show');
        record.popup.style.removeProperty('opacity');
    }, 10);
}

function showToast(rawDetail) {
    const detail = normalizeDetail(rawDetail);

    if (isDuplicate(detail)) {
        return null;
    }

    playSound(detail);

    const type = normalizeType(detail.type);
    const timer = normalizeTimer(detail.timer);
    const markup = createToastMarkup(detail, type);
    const record = {
        ...markup,
        timer,
        remaining: timer,
        redirectTo: detail.redirectTo || null,
        startedAt: 0,
        timeoutId: null,
        progressStartId: null,
        removeFallbackId: null,
        timerVersion: 0,
        pauseReasons: new Set(),
        closed: false,
        removed: false,
        onHideAnimationEnd: null,
    };

    record.onHideAnimationEnd = (event) => {
        if (event.target === record.popup) {
            removeToastElement(record);
        }
    };

    record.closeButton.addEventListener('click', () => dismissToast(record));

    if (window.matchMedia?.('(hover: hover)').matches) {
        record.popup.addEventListener('mouseenter', () => pauseToast(record, 'hover'));
        record.popup.addEventListener('mouseleave', () => resumeToast(record, 'hover'));
    }

    toastRecords.add(record);
    recordsByItem.set(record.item, record);

    try {
        insertToast(record);
        startTimer(record);
    } catch (error) {
        console.error('[rt-alerts] Meldung konnte nicht angezeigt werden', error);
        dismissToast(record, { immediate: true });

        return null;
    }

    return record.item;
}

function pauseAllToasts() {
    toastRecords.forEach((record) => pauseToast(record, 'visibility'));
}

function resumeAllToasts() {
    toastRecords.forEach((record) => resumeToast(record, 'visibility'));
}

function clearToasts() {
    [...toastRecords].forEach((record) => dismissToast(record, { immediate: true }));
    document.getElementById(STACK_ID)?.remove();
}

/** Alte Listener und laufende Timer bei HMR sauber entsorgen. */
if (window.__rtAlertsAbortController) {
    window.__rtAlertsAbortController.abort();
}

window.__rtAlertStackRuntime?.destroy?.();

const controller = new AbortController();
window.__rtAlertsAbortController = controller;

const listenerOptions = { signal: controller.signal };

['swal:toast', 'swal:alert', 'showAlert'].forEach((eventName) => {
    window.addEventListener(eventName, (event) => showToast(event.detail), listenerOptions);
});

// Der Body des alten Dokuments verschwindet bei wire:navigate. Vorher werden
// alle unabhaengigen Timer beendet; der naechste Toast erzeugt den Host neu.
document.addEventListener('livewire:navigating', clearToasts, listenerOptions);

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        pauseAllToasts();
    } else {
        resumeAllToasts();
    }
}, listenerOptions);

window.__rtAlertStackRuntime = {
    destroy: clearToasts,
    pauseAll: pauseAllToasts,
    resumeAll: resumeAllToasts,
};

// window.Swal bleibt fuer echte SweetAlert2-Dialoge und Legacy-Stellen bestehen.
window.Swal = Swal;
window.RTAlert = {
    show: showToast,
    clear: clearToasts,
    swal: Swal,
};

export { clearToasts, showToast, Swal };
