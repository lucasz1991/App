/**
 * wire:confirm -> RailTime-Bestaetigungsdialog (x-ui.confirmation-dialog).
 *
 * Livewire 3 setzt fuer jedes Element mit wire:confirm eine Funktion
 * el.__livewire_confirm(action, instead), die der Wildcard-Handler
 * (wire:click, wire:submit, ...) vor der eigentlichen Aktion aufruft. Das
 * Original nutzt das browsernative, synchrone confirm(). Dieses Modul ersetzt
 * die Funktion ueber den oeffentlichen Hook "directive.init", damit alle
 * Blade-Aufrufe unveraendert bleiben koennen:
 *
 *   - Abbrechen bleibt "keine Aktion": instead() (stopImmediatePropagation)
 *     laeuft sofort und synchron, die Aktion erst nach "Weiter".
 *   - wire:confirm.prompt (Eingabe eines Kontrollworts) bleibt nativ, weil der
 *     Dialog kein Eingabefeld hat.
 *   - Ist kein initialisierter Dialog auf der Seite, faellt das Element auf
 *     das native Verhalten zurueck. Es geht also nie eine Bestaetigung
 *     verloren.
 *
 * Optionale Attribute am Element: data-confirm-title, data-confirm-label und
 * data-confirm-variant ("destructive" | "default").
 */

const DESTRUCTIVE_WORDS = /(l(?:ö|oe)sch|entfern|storn|widerruf|ablehn|trenn|absag|beend|zur(?:ü|ue)ckn|delete|remove|revoke|disconnect)/i;

/**
 * Bereitet den Text so auf wie Livewires eigene wire:confirm-Direktive.
 */
export function confirmMessage(expression) {
    const message = String(expression ?? '').replaceAll('\\n', '\n');

    return message === '' ? 'Are you sure?' : message;
}

export function confirmVariant(el, message) {
    const explicit = el?.dataset?.confirmVariant;

    if (explicit === 'destructive' || explicit === 'default') {
        return explicit;
    }

    return DESTRUCTIVE_WORDS.test(message) ? 'destructive' : 'default';
}

export function confirmationDialogReady(doc) {
    const dialog = doc?.querySelector?.('[data-rt-confirmation-dialog]');

    // _x_dataStack existiert erst, wenn Alpine den Dialog initialisiert hat.
    return Boolean(dialog && dialog._x_dataStack);
}

/**
 * Liefert den Ersatz fuer el.__livewire_confirm.
 */
export function createConfirmHandler({ el, message, fallback, isDialogReady, CustomEventClass }) {
    return (action, instead) => {
        if (!isDialogReady()) {
            fallback(action, instead);

            return;
        }

        // Wie beim nativen "Abbrechen": weitere Listener desselben Ereignisses
        // sofort stoppen. Die Aktion laeuft erst nach der Bestaetigung.
        instead();

        const detail = {
            message,
            variant: confirmVariant(el, message),
            action: () => {
                action();
            },
        };

        if (el?.dataset?.confirmTitle) {
            detail.title = el.dataset.confirmTitle;
        }

        if (el?.dataset?.confirmLabel) {
            detail.confirmLabel = el.dataset.confirmLabel;
        }

        // Am Element ausloesen (bubbles), damit der Dialog den Fokus danach
        // an den ausloesenden Knopf zurueckgeben kann.
        el.dispatchEvent(new CustomEventClass('rt-confirm', { bubbles: true, detail }));
    };
}

/**
 * Natives Verhalten aus Livewires wire:confirm (ohne .prompt).
 */
export function nativeConfirm(message, confirmFn) {
    return (action, instead) => {
        if (confirmFn(message)) {
            action();
        } else {
            instead();
        }
    };
}

export function installLivewireConfirm(Livewire, {
    doc = typeof document !== 'undefined' ? document : null,
    confirmFn = (message) => window.confirm(message),
    CustomEventClass = typeof CustomEvent !== 'undefined' ? CustomEvent : null,
} = {}) {
    Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive?.value !== 'confirm' || directive.modifiers?.includes('prompt')) {
            return;
        }

        const message = confirmMessage(directive.expression);
        const original = el.__livewire_confirm;
        const fallback = typeof original === 'function' ? original : nativeConfirm(message, confirmFn);

        el.__livewire_confirm = createConfirmHandler({
            el,
            message,
            fallback,
            isDialogReady: () => confirmationDialogReady(doc),
            CustomEventClass,
        });
    });
}

/**
 * Bruecke fuer serverseitig geoeffnete Bestaetigungen (Livewire-Property).
 *
 * Beispiel (chat-box): requestDeleteMessage() setzt showDeleteMessageModal auf
 * true; die Bruecke oeffnet dann den Standarddialog. "Weiter" ruft die
 * Bestaetigungsmethode auf, "Abbrechen" setzt die Property zurueck. Wird die
 * Property serverseitig wieder false (z. B. Chatwechsel), schliesst der
 * Dialog. Titel, Text und Knopfbeschriftung kommen als data-confirm-*
 * Attribute aus Blade und werden erst beim Oeffnen gelesen, damit sie dem
 * gerade gerenderten Stand entsprechen.
 */
export function confirmationBridge(property, method, variant = 'destructive') {
    const key = `${property}:${method}`;

    return {
        init() {
            this.$watch(`$wire.${property}`, (open) => {
                if (open) {
                    this.ask();
                } else {
                    window.dispatchEvent(new CustomEvent('rt-confirm-dismiss', { detail: { key } }));
                }
            });

            if (this.$wire?.[property]) {
                this.ask();
            }
        },

        ask() {
            const opener = document.activeElement;

            this.$nextTick(() => {
                const data = this.$el.dataset;
                const target = opener instanceof HTMLElement && opener !== document.body && document.contains(opener)
                    ? opener
                    : window;

                target.dispatchEvent(new CustomEvent('rt-confirm', {
                    bubbles: true,
                    detail: {
                        key,
                        title: data.confirmTitle || undefined,
                        message: data.confirmMessage || '',
                        variant,
                        confirmLabel: data.confirmLabel || undefined,
                        action: () => this.$wire.call(method),
                        cancel: () => {
                            try {
                                if (this.$wire.get(property)) {
                                    this.$wire.set(property, false);
                                }
                            } catch (_) {
                                // Komponente bereits entfernt (Navigation): nichts zuruecksetzen.
                            }
                        },
                    },
                }));
            });
        },
    };
}
