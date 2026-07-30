/*
 * Bildschirmtastatur im Layout beruecksichtigen.
 *
 * Viewport-Seiten (Messenger, Anruf) sperren den Dokument-Scroll. In einer
 * installierten App kann die virtuelle Tastatur deshalb den unteren Bereich
 * verdecken, obwohl das Eingabefeld korrekt fokussiert wurde. Die tatsaechlich
 * sichtbare Hoehe aus visualViewport wird als CSS-Inset bereitgestellt.
 */

const KEYBOARD_MIN_INSET = 120;

let bound = false;
let frame = null;

function applyInset(pixels) {
    const root = document.documentElement;
    const inset = Math.max(0, Math.round(pixels));

    root.style.setProperty('--rt-keyboard-inset', `${inset}px`);

    if (inset >= KEYBOARD_MIN_INSET) {
        root.setAttribute('data-rt-keyboard', 'open');
    } else {
        root.removeAttribute('data-rt-keyboard');
    }
}

/**
 * Android kann den Layout-Viewport bereits selbst verkleinern. Dann sind
 * innerHeight und visualViewport.height gleich und es darf nichts ein zweites
 * Mal abgezogen werden. Auf iOS entspricht die verbleibende Differenz der
 * verdeckten Tastaturflaeche.
 */
export function keyboardInsetFrom({ innerHeight, viewportHeight, offsetTop = 0 }) {
    const covered = Number(innerHeight) - Number(viewportHeight) - Number(offsetTop);

    if (! Number.isFinite(covered) || covered < KEYBOARD_MIN_INSET) {
        return 0;
    }

    return Math.round(covered);
}

function measure() {
    const viewport = window.visualViewport;

    if (! viewport) {
        return;
    }

    applyInset(keyboardInsetFrom({
        innerHeight: window.innerHeight,
        viewportHeight: viewport.height,
        offsetTop: viewport.offsetTop,
    }));
}

function scheduleMeasure() {
    window.cancelAnimationFrame(frame);
    frame = window.requestAnimationFrame(measure);
}

function revealFocusedControl() {
    const active = document.activeElement;

    if (! (active instanceof HTMLElement)) {
        return;
    }

    if (! active.matches('input, textarea, select, [contenteditable="true"]')) {
        return;
    }

    window.setTimeout(() => {
        if (document.activeElement === active && active.isConnected) {
            active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }, 180);
}

export function initKeyboardViewport() {
    if (bound || typeof window === 'undefined') {
        return;
    }

    bound = true;
    applyInset(0);

    const viewport = window.visualViewport;

    if (viewport) {
        viewport.addEventListener('resize', scheduleMeasure);
        viewport.addEventListener('scroll', scheduleMeasure);
    }

    window.addEventListener('orientationchange', scheduleMeasure);

    document.addEventListener('focusin', (event) => {
        if (! (event.target instanceof HTMLElement)) {
            return;
        }

        if (event.target.matches('input, textarea, select, [contenteditable="true"]')) {
            scheduleMeasure();
            window.setTimeout(scheduleMeasure, 300);
            revealFocusedControl();
        }
    });

    document.addEventListener('focusout', () => {
        window.setTimeout(scheduleMeasure, 120);
    });

    scheduleMeasure();
}
