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
 * Label-/Input-Fokus zwischen pointerdown und click. Der Fallback greift
 * ausschliesslich bei einem echten Touch-/Stift-pointerup und fokussiert noch
 * innerhalb derselben vertrauenswuerdigen Nutzeraktion. Er verhindert kein
 * Default-Verhalten und laesst Date-/Datums-Picker weiterhin nativ arbeiten.
 */
export function initMobileFormFocusRecovery() {
    if (window.__rtMobileFormFocusRecoveryBound === true) {
        return;
    }

    window.__rtMobileFormFocusRecoveryBound = true;

    document.addEventListener(
        'pointerup',
        (event) => {
            if (!['touch', 'pen'].includes(event.pointerType)) {
                return;
            }

            const control = editableControlFromTarget(event.target);
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

