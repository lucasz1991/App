const EDITABLE_CONTROL_SELECTOR = [
    'input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="checkbox"]):not([type="radio"])',
    'textarea',
    'select',
    '[contenteditable="true"]',
].join(', ');

function editableControlFromTarget(target) {
    if (!(target instanceof Element)) {
        return null;
    }

    const directControl = target.closest(EDITABLE_CONTROL_SELECTOR);
    if (directControl) {
        return directControl;
    }

    const label = target.closest('label');
    if (!(label instanceof HTMLLabelElement)) {
        return null;
    }

    return label.control ?? label.querySelector(EDITABLE_CONTROL_SELECTOR);
}

function controlAcceptsNativeFocus(control) {
    if (!(control instanceof HTMLElement)) {
        return false;
    }

    if (
        control.matches(':disabled')
        || control.getAttribute('aria-disabled') === 'true'
        || control.closest('[inert]')
    ) {
        return false;
    }

    if (
        (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement)
        && control.readOnly
    ) {
        return false;
    }

    return true;
}

/**
 * iOS-Web-Apps verlieren nach Livewire-Morphs gelegentlich den nativen
 * Label-/Input-Fokus. Dieser Fallback holt ihn zurueck — aber erst, wenn der
 * native Weg nachweislich versagt hat.
 *
 * WARUM 'click' UND NICHT 'pointerup':
 * iOS oeffnet die Bildschirmtastatur nur, wenn der Fokus als unmittelbare
 * Folge der Beruehrung entsteht. Fokussiert ein Skript schon bei 'pointerup'
 * — also VOR dem nativen Fokus —, dann ist das Feld beim eigentlichen Tap
 * bereits fokussiert, iOS sieht keinen Fokuswechsel mehr und laesst die
 * Tastatur weg. Sichtbares Ergebnis: Das Feld ist markiert, der Cursor
 * blinkt, aber es erscheint keine Tastatur — und zwar bei JEDEM Feld.
 *
 * 'click' laeuft NACH dem nativen Fokus, aber noch innerhalb derselben
 * vertrauenswuerdigen Nutzeraktion. Hat der Browser selbst fokussiert, greift
 * die Waechterbedingung unten und wir ruehren nichts an. Nur wenn der native
 * Weg wirklich ausgefallen ist, fokussieren wir — und weil wir dabei in der
 * Geste sind, oeffnet iOS die Tastatur.
 */
export function initMobileFormFocusRecovery() {
    if (window.__rtMobileFormFocusRecoveryBound === true) {
        return;
    }

    window.__rtMobileFormFocusRecoveryBound = true;

    document.addEventListener(
        'click',
        (event) => {
            // Nur echte Nutzereingaben: Ein synthetischer Klick aus Code
            // brauchte diese Rettung nicht und duerfte keinen Fokus stehlen.
            if (!event.isTrusted) {
                return;
            }

            const control = editableControlFromTarget(event.target);

            // Der Normalfall: Der Browser hat laengst selbst fokussiert.
            // Hier auszusteigen ist der Kern des Ganzen — jedes zusaetzliche
            // focus() waere genau der Griff, der die Tastatur verhindert.
            if (
                !controlAcceptsNativeFocus(control)
                || document.activeElement === control
            ) {
                return;
            }

            try {
                control.focus({ preventScroll: true });
            } catch (_) {
                control.focus();
            }
        },
        true,
    );
}

