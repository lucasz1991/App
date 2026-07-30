/*
 * Bildschirmtastatur im Layout beruecksichtigen.
 *
 * Das Problem, das dieses Modul loest:
 * Viewport-Seiten (Messenger, Anruf) setzen html/body auf `height: 100dvh` mit
 * `overflow: hidden`. Die Einheit dvh kennt die Bildschirmtastatur NICHT — sie
 * meint immer die volle Flaeche. In der installierten App (kein Browser-Chrome,
 * das sonst etwas abfedert) bleibt die Seite deshalb bildschirmhoch, waehrend
 * die Tastatur die untere Haelfte ueberdeckt: Das Eingabefeld liegt dahinter,
 * und weil die Seite nicht scrollen darf, kommt es nie zum Vorschein. Fuer den
 * Nutzer sieht das aus, als wuerde sich die Tastatur gar nicht oeffnen.
 *
 * Loesung: Die tatsaechlich sichtbare Hoehe liefert `window.visualViewport`.
 * Daraus wird die verdeckte Hoehe berechnet und als CSS-Variable
 * `--rt-keyboard-inset` gesetzt; das Layout rechnet sie von seiner Hoehe ab.
 * Zusaetzlich traegt `<html>` das Merkmal `data-rt-keyboard="open"`, damit die
 * Gestaltung bei offener Tastatur nachjustieren kann.
 *
 * Ergaenzend steht `interactive-widget=resizes-content` im Viewport-Meta: Damit
 * verkleinert Chrome den Layout-Viewport von sich aus. Beides zusammen deckt
 * Android und iOS ab — das Meta allein hilft iOS nicht, dieses Modul allein
 * kaeme auf Android einen Frame zu spaet.
 */

const KEYBOARD_MIN_INSET = 120; // Darunter ist es Adressleiste/Rundung, keine Tastatur.

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
 * Verdeckte Hoehe aus den Viewport-Massen berechnen.
 *
 * Selbstkorrigierend gegenueber `interactive-widget=resizes-content`: Wo der
 * Browser den Layout-Viewport bereits selbst verkleinert (Android/Chrome),
 * schrumpft innerHeight mit — die Differenz geht gegen null und es wird NICHT
 * ein zweites Mal abgezogen. Wo er das nicht tut (iOS), bleibt innerHeight
 * voll und die Differenz ist genau die Tastaturhoehe.
 *
 * offsetTop gehoert abgezogen: Er faellt an, wenn die Seite hochgeschoben
 * wurde, und waere sonst faelschlich Teil der "verdeckten" Hoehe.
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

/**
 * Das fokussierte Feld in Sicht holen.
 *
 * Auf Viewport-Seiten scrollt der Browser nicht selbst — das Dokument hat
 * keinen Scrollbereich. Nach dem Verkleinern muss das Feld deshalb aktiv in
 * seinen scrollbaren Vorfahren geholt werden.
 */
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

    // Nach dem Fokussieren zweimal messen: Die Tastatur faehrt animiert ein,
    // die erste Messung faellt sonst noch in die alte Groesse.
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
