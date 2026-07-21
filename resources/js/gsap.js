// ---------------------------------------------------------------
// GSAP-Reveals fuer RailTime (Blade + Livewire + Alpine + Vite).
//
// Jede per wire:navigate geladene DOM-Generation erhaelt genau einen
// matchMedia-Kontext. Oberhalb des Falzes sichtbare Elemente starten sofort;
// weiter unten liegende Bereiche werden einmalig durch ScrollTrigger gezeigt.
// Beim naechsten Seitenwechsel wird der alte Kontext vollstaendig verworfen.
// ---------------------------------------------------------------
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

window.gsap = gsap;
window.ScrollTrigger = ScrollTrigger;

const REVEAL_PRESETS = {
    fade: { autoAlpha: 0 },
    'fade-up': { autoAlpha: 0, y: 22 },
    zoom: { autoAlpha: 0, scale: 0.96 },
    left: { autoAlpha: 0, x: -26 },
    right: { autoAlpha: 0, x: 26 },
};

let activePageRoot = null;
let activeMedia = null;
let firstFrame = null;
let secondFrame = null;

function pageRoot() {
    return document.querySelector('.page-content') || document.body;
}

function markPending(elements) {
    elements.forEach((element) => {
        element.dataset.animPending = '1';
        delete element.dataset.animDone;
    });
}

function markComplete(elements) {
    elements.forEach((element) => {
        delete element.dataset.animPending;
        element.dataset.animDone = '1';
    });
}

function showImmediately(elements) {
    if (!elements.length) return;

    gsap.set(elements, {
        autoAlpha: 1,
        x: 0,
        y: 0,
        scale: 1,
        clearProps: 'transform,opacity,visibility',
    });
    markComplete(elements);
}

function cleanupReveals() {
    if (firstFrame !== null) window.cancelAnimationFrame(firstFrame);
    if (secondFrame !== null) window.cancelAnimationFrame(secondFrame);
    firstFrame = null;
    secondFrame = null;

    activeMedia?.revert();
    activeMedia = null;
}

function isInitiallyVisible(element) {
    const rect = element.getBoundingClientRect();

    return rect.bottom > 0 && rect.top <= window.innerHeight * 0.9;
}

function isAlreadyAboveViewport(element) {
    return element.getBoundingClientRect().bottom <= 0;
}

function createRevealTween(elements, fromVars, trigger, options = {}) {
    if (!elements.length) return;

    if (isAlreadyAboveViewport(trigger)) {
        showImmediately(elements);
        return;
    }

    markPending(elements);

    const toVars = {
        autoAlpha: 1,
        x: 0,
        y: 0,
        scale: 1,
        duration: options.duration ?? 0.58,
        delay: options.delay ?? 0,
        ease: 'power2.out',
        stagger: options.stagger,
        overwrite: 'auto',
        immediateRender: true,
        clearProps: 'transform,opacity,visibility',
        onComplete: () => markComplete(elements),
    };

    if (!isInitiallyVisible(trigger)) {
        toVars.scrollTrigger = {
            trigger,
            start: 'clamp(top 90%)',
            once: true,
            invalidateOnRefresh: true,
            toggleActions: 'play none none none',
        };
    }

    gsap.fromTo(elements, fromVars, toVars);
}

function setupReveals(root) {
    const singleTargets = Array.from(root.querySelectorAll('[data-anim]'));
    const staggerContainers = Array.from(root.querySelectorAll('[data-anim-stagger]'));

    if (!singleTargets.length && !staggerContainers.length) {
        ScrollTrigger.refresh();
        return;
    }

    activeMedia = gsap.matchMedia();
    activeMedia.add(
        {
            reduceMotion: '(prefers-reduced-motion: reduce)',
            animateMotion: '(prefers-reduced-motion: no-preference)',
        },
        ({ conditions }) => {
            const staggerChildren = staggerContainers.flatMap((container) => Array.from(container.children));
            const allTargets = [...singleTargets, ...staggerChildren];

            if (conditions.reduceMotion) {
                showImmediately(allTargets);
                return;
            }

            singleTargets.forEach((element) => {
                const preset = REVEAL_PRESETS[element.dataset.anim] || REVEAL_PRESETS['fade-up'];
                const delay = Math.max(0, Math.min(0.5, Number.parseFloat(element.dataset.animDelay || '0') || 0));

                createRevealTween([element], preset, element, { delay });
            });

            staggerContainers.forEach((container) => {
                createRevealTween(
                    Array.from(container.children),
                    { autoAlpha: 0, y: 18 },
                    container,
                    { duration: 0.52, stagger: 0.075 },
                );
            });

            return () => {
                allTargets.forEach((element) => delete element.dataset.animPending);
            };
        },
        root,
    );

    ScrollTrigger.refresh();

    if (document.fonts?.ready) {
        document.fonts.ready.then(() => {
            if (activePageRoot === root && root.isConnected) ScrollTrigger.refresh();
        });
    }
}

function bootReveals() {
    const root = pageRoot();

    // DOMContentLoaded und Livewires initiales navigated koennen direkt
    // nacheinander feuern. Dieselbe Seite darf dadurch nicht doppelt starten.
    if (!root || root === activePageRoot) return;

    cleanupReveals();
    activePageRoot = root;

    // Zwei Frames lassen Livewire/Alpine, Fonts und das Layout zuerst ihre
    // endgueltigen Positionen setzen. So laufen Above-the-fold-Fades sichtbar.
    firstFrame = window.requestAnimationFrame(() => {
        secondFrame = window.requestAnimationFrame(() => {
            firstFrame = null;
            secondFrame = null;

            if (root === activePageRoot && root.isConnected) setupReveals(root);
        });
    });
}

if (document.readyState !== 'loading') {
    bootReveals();
} else {
    document.addEventListener('DOMContentLoaded', bootReveals, { once: true });
}

document.addEventListener('livewire:navigated', bootReveals);

export { gsap, ScrollTrigger };
