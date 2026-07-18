// ---------------------------------------------------------------
// GSAP-Setup fuer RailTime (Blade + Livewire + Alpine + Vite).
//
// - Stellt gsap & ScrollTrigger global bereit (window.gsap / window.ScrollTrigger).
// - Deklarative Reveal-Animationen ueber data-Attribute (opt-in, kein Zwang):
//     data-anim="fade-up|fade|zoom|left|right"   Einblenden beim Scrollen
//     data-anim-delay="0.2"                        Verzoegerung (Sekunden)
//     data-anim-stagger  (am Container)            Kinder gestaffelt einblenden
// - Respektiert prefers-reduced-motion (via gsap.matchMedia): dann sofort
//   sichtbar, keine Bewegung.
// - Re-initialisiert nach jeder Livewire-Navigation (wire:navigate) und
//   aktualisiert ScrollTrigger, da das DOM getauscht wird.
// ---------------------------------------------------------------
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

window.gsap = gsap;
window.ScrollTrigger = ScrollTrigger;

const REVEAL_PRESETS = {
    fade: { autoAlpha: 0 },
    'fade-up': { autoAlpha: 0, y: 24 },
    zoom: { autoAlpha: 0, scale: 0.94 },
    left: { autoAlpha: 0, x: -28 },
    right: { autoAlpha: 0, x: 28 },
};

let mm = null;

function markRevealed(el) {
    el.dataset.animDone = '1';
}

function initReveals(root = document) {
    // Bereits initialisierte Elemente ueberspringen (verhindert Doppelbindung).
    const targets = root.querySelectorAll('[data-anim]:not([data-anim-done]), [data-anim-stagger]:not([data-anim-done])');
    if (!targets.length) return;

    // gsap.matchMedia: bei reduzierter Bewegung nur sichtbar schalten.
    mm = mm || gsap.matchMedia();

    targets.forEach((el) => markRevealed(el));

    mm.add(
        {
            reduce: '(prefers-reduced-motion: reduce)',
            animate: '(prefers-reduced-motion: no-preference)',
        },
        (context) => {
            const { reduce } = context.conditions;

            root.querySelectorAll('[data-anim][data-anim-done]').forEach((el) => {
                const preset = REVEAL_PRESETS[el.dataset.anim] || REVEAL_PRESETS['fade-up'];
                const delay = parseFloat(el.dataset.animDelay || '0') || 0;

                if (reduce) {
                    gsap.set(el, { autoAlpha: 1, clearProps: 'transform' });
                    return;
                }

                gsap.from(el, {
                    ...preset,
                    delay,
                    duration: 0.6,
                    ease: 'power2.out',
                    scrollTrigger: {
                        trigger: el,
                        start: 'top 88%',
                        once: true,
                    },
                });
            });

            root.querySelectorAll('[data-anim-stagger][data-anim-done]').forEach((container) => {
                const children = container.children;
                if (!children.length) return;

                if (reduce) {
                    gsap.set(children, { autoAlpha: 1, clearProps: 'transform' });
                    return;
                }

                gsap.from(children, {
                    autoAlpha: 0,
                    y: 20,
                    duration: 0.5,
                    ease: 'power2.out',
                    stagger: 0.08,
                    scrollTrigger: {
                        trigger: container,
                        start: 'top 85%',
                        once: true,
                    },
                });
            });
        }
    );
}

function boot() {
    initReveals();
    ScrollTrigger.refresh();
}

if (document.readyState !== 'loading') {
    boot();
} else {
    document.addEventListener('DOMContentLoaded', boot);
}

// Livewire tauscht bei wire:navigate das DOM aus -> neu binden + refreshen.
document.addEventListener('livewire:navigated', () => {
    initReveals();
    ScrollTrigger.refresh();
});

export { gsap, ScrollTrigger };
