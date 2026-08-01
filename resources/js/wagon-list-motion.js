// ---------------------------------------------------------------
// Bewegung fuer den Wagenlisten-Editor (GSAP).
//
// Der Editor ist ein Vollbild-Modal ohne Seitenwechsel: es gibt keinen
// ScrollTrigger-Kontext und keine wire:navigate-Generation, an der man
// aufraeumen koennte. Deshalb besitzt dieses Modul einen eigenen, sehr
// kleinen Lebenszyklus — jede Animation laeuft in genau einem gsap.context(),
// der beim Schliessen des Editors verworfen wird.
//
// Aufgerufen wird ausschliesslich defensiv aus wagon-list-prototype.js
// (window.RailTimeWagonMotion?.…). Faellt das Modul aus, bleibt der Editor
// vollstaendig bedienbar — Bewegung ist Zugabe, nie Voraussetzung.
//
// prefers-reduced-motion wird bei JEDEM Aufruf geprueft, nicht einmalig beim
// Start: die Einstellung kann sich waehrend einer Sitzung aendern, und ein
// Editor bleibt lange offen. In diesem Fall werden Zielzustaende sofort
// gesetzt, damit nichts unsichtbar zurueckbleibt.
// ---------------------------------------------------------------
import gsap from 'gsap';

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

const prefersReducedMotion = () => typeof window?.matchMedia === 'function'
    && window.matchMedia(REDUCED_MOTION_QUERY).matches;

const elements = (target) => {
    if (!target) return [];
    if (typeof target === 'string') return Array.from(document.querySelectorAll(target));
    if (target instanceof Element) return [target];

    return Array.from(target);
};

export const createWagonListMotion = () => {
    let context = null;
    const counters = new WeakMap();

    const ensureContext = () => {
        if (!context) context = gsap.context(() => {});

        return context;
    };

    const run = (fn) => {
        try {
            ensureContext().add(fn);
        } catch (_) {
            // Eine fehlgeschlagene Animation darf die Eingabe nie blockieren.
        }
    };

    return {
        /** Aufraeumen beim Schliessen des Editors. */
        dispose() {
            try {
                context?.revert();
            } catch (_) {
                // ignorieren
            }
            context = null;
        },

        /** Kopfzeile, Schrittleiste und Fusszeile beim Oeffnen hereinholen. */
        editorOpened(root) {
            const header = elements(root?.querySelector?.('[data-wagon-motion="header"]'));
            const footer = elements(root?.querySelector?.('[data-wagon-motion="footer"]'));

            if (prefersReducedMotion()) {
                gsap.set([...header, ...footer], { clearProps: 'all' });
                return;
            }

            run(() => {
                if (header.length) {
                    gsap.from(header, { autoAlpha: 0, y: -10, duration: 0.32, ease: 'power2.out' });
                }
                if (footer.length) {
                    gsap.from(footer, { autoAlpha: 0, y: 14, duration: 0.34, ease: 'power2.out', delay: 0.05 });
                }
            });
        },

        /**
         * Inhalt eines Schrittes gestaffelt hereinholen. Animiert werden die
         * direkten Kinder der Scrollflaeche — nicht die Flaeche selbst, sonst
         * springt die Scrollposition.
         */
        stepEntered(panel) {
            const scroller = panel?.querySelector?.('[data-wagon-motion="panel"]') || panel;
            const blocks = elements(scroller?.children);
            if (!blocks.length) return;

            if (prefersReducedMotion()) {
                gsap.set(blocks, { clearProps: 'all' });
                return;
            }

            run(() => {
                gsap.killTweensOf(blocks);
                gsap.fromTo(
                    blocks,
                    { autoAlpha: 0, y: 16 },
                    {
                        autoAlpha: 1,
                        y: 0,
                        duration: 0.38,
                        ease: 'power2.out',
                        stagger: 0.045,
                        overwrite: 'auto',
                        clearProps: 'transform,opacity,visibility',
                    },
                );
            });
        },

        /** Fortschrittsbalken weich auf den neuen Anteil ziehen (0…1). */
        progressTo(bar, ratio) {
            if (!bar) return;

            const value = Math.max(0, Math.min(1, Number(ratio) || 0));

            if (prefersReducedMotion()) {
                gsap.set(bar, { scaleX: value });
                return;
            }

            run(() => {
                gsap.to(bar, { scaleX: value, duration: 0.45, ease: 'power3.out', overwrite: true });
            });
        },

        /** Liste gestaffelt zeigen — im Pruefschritt fuer die Wagenkarten. */
        listReveal(container, selector = '[data-wagon-motion-item]') {
            const items = elements(container?.querySelectorAll?.(selector));
            if (!items.length) return;

            if (prefersReducedMotion()) {
                gsap.set(items, { clearProps: 'all' });
                return;
            }

            run(() => {
                gsap.fromTo(
                    items,
                    { autoAlpha: 0, y: 12 },
                    {
                        autoAlpha: 1,
                        y: 0,
                        duration: 0.34,
                        ease: 'power2.out',
                        stagger: 0.035,
                        overwrite: 'auto',
                        clearProps: 'transform,opacity,visibility',
                    },
                );
            });
        },

        /** Kurzer Impuls, z. B. wenn ein Wagen hinzugekommen ist. */
        pop(target) {
            const nodes = elements(target);
            if (!nodes.length || prefersReducedMotion()) return;

            run(() => {
                gsap.fromTo(
                    nodes,
                    { scale: 0.86 },
                    { scale: 1, duration: 0.42, ease: 'back.out(2.4)', clearProps: 'transform' },
                );
            });
        },

        /**
         * Zahl auf den neuen Wert zaehlen. Der zuletzt angezeigte Wert wird je
         * Element gemerkt, damit auch die Rueckwaertsrichtung stimmt.
         */
        countTo(element, value, format) {
            if (!element) return;

            const target = Number(value) || 0;
            const from = counters.get(element) ?? 0;
            counters.set(element, target);

            const write = (current) => {
                element.textContent = typeof format === 'function' ? format(current) : String(Math.round(current));
            };

            if (prefersReducedMotion() || from === target) {
                write(target);
                return;
            }

            run(() => {
                const state = { value: from };
                gsap.to(state, {
                    value: target,
                    duration: 0.5,
                    ease: 'power2.out',
                    overwrite: true,
                    onUpdate: () => write(state.value),
                    onComplete: () => write(target),
                });
            });
        },
    };
};
