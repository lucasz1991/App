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
let revealGeneration = 0;

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
    revealGeneration += 1;

    if (firstFrame !== null) window.cancelAnimationFrame(firstFrame);
    if (secondFrame !== null) window.cancelAnimationFrame(secondFrame);
    firstFrame = null;
    secondFrame = null;

    activeMedia?.revert();
    activeMedia = null;
}

function dashboardSegmentItems(segment) {
    const items = [
        ...segment.querySelectorAll('[data-dashboard-item]'),
        ...segment.querySelectorAll('[data-dashboard-items] > *'),
    ];

    return Array.from(new Set(items)).filter((item) => (
        item !== segment && !item.hasAttribute('data-dashboard-segment')
    ));
}

function dashboardAnimationTargets(segments) {
    return Array.from(new Set(segments.flatMap((segment) => [
        segment,
        ...dashboardSegmentItems(segment),
    ])));
}

function prepareDashboardSegment(segment) {
    const items = dashboardSegmentItems(segment);
    const targets = [segment, ...items];

    markPending(targets);
    gsap.set(segment, {
        autoAlpha: 0,
        y: 28,
        scale: 0.992,
        transformOrigin: '50% 0%',
    });

    if (items.length) {
        gsap.set(items, {
            autoAlpha: 0,
            y: 16,
            scale: 0.988,
            transformOrigin: '50% 50%',
        });
    }

    return { segment, items, targets };
}

function appendDashboardSegment(timeline, prepared, position = 0) {
    const { segment, items, targets } = prepared;
    const label = `segment-${segment.dataset.dashboardSegment || Math.random().toString(36).slice(2)}`;

    timeline.addLabel(label, position);
    timeline.to(segment, {
        autoAlpha: 1,
        y: 0,
        scale: 1,
        duration: 0.72,
        ease: 'expo.out',
        overwrite: 'auto',
        clearProps: 'transform,opacity,visibility',
    }, label);

    if (items.length) {
        timeline.to(items, {
            autoAlpha: 1,
            y: 0,
            scale: 1,
            duration: 0.54,
            ease: 'power3.out',
            stagger: { each: 0.05, from: 'start' },
            overwrite: 'auto',
            clearProps: 'transform,opacity,visibility',
        }, `${label}+=0.12`);
    }

    timeline.call(() => markComplete(targets));
}

function setupDashboardSegments(segments) {
    const introSegments = [];

    segments.forEach((segment) => {
        const targets = [segment, ...dashboardSegmentItems(segment)];

        if (isAlreadyAboveViewport(segment)) {
            showImmediately(targets);
            return;
        }

        const prepared = prepareDashboardSegment(segment);

        if (isInitiallyVisible(segment)) {
            introSegments.push(prepared);
            return;
        }

        const timeline = gsap.timeline({
            scrollTrigger: {
                trigger: segment,
                start: 'clamp(top 88%)',
                once: true,
                invalidateOnRefresh: true,
                toggleActions: 'play none none none',
            },
        });
        appendDashboardSegment(timeline, prepared);
    });

    if (!introSegments.length) return;

    const introTimeline = gsap.timeline({ delay: 0.08 });
    introSegments.forEach((prepared, index) => {
        // Staerkere Ueberlappung laesst die Segmente als eine fliessende
        // Kaskade statt als getrennte Bloecke einlaufen.
        appendDashboardSegment(introTimeline, prepared, index === 0 ? 0 : '>-0.26');
    });
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
        duration: options.duration ?? 0.6,
        delay: options.delay ?? 0,
        ease: 'power3.out',
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
    const dashboardSegments = Array.from(root.querySelectorAll('[data-admin-dashboard] [data-dashboard-segment]'));
    const singleTargets = Array.from(root.querySelectorAll('[data-anim]'))
        .filter((element) => !element.closest('[data-dashboard-segment]'));
    const staggerContainers = Array.from(root.querySelectorAll('[data-anim-stagger]'))
        .filter((element) => !element.closest('[data-dashboard-segment]'));

    if (!singleTargets.length && !staggerContainers.length && !dashboardSegments.length) {
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
            const dashboardTargets = dashboardAnimationTargets(dashboardSegments);
            const allTargets = [...singleTargets, ...staggerChildren, ...dashboardTargets];

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

            setupDashboardSegments(dashboardSegments);

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
    const generation = revealGeneration;

    // Zwei Frames lassen Livewire/Alpine, Fonts und das Layout zuerst ihre
    // endgueltigen Positionen setzen. So laufen Above-the-fold-Fades sichtbar.
    firstFrame = window.requestAnimationFrame(() => {
        secondFrame = window.requestAnimationFrame(() => {
            firstFrame = null;
            secondFrame = null;

            if (
                generation === revealGeneration
                && root === activePageRoot
                && root.isConnected
            ) {
                setupReveals(root);
            }
        });
    });
}

if (document.readyState !== 'loading') {
    bootReveals();
} else {
    document.addEventListener('DOMContentLoaded', bootReveals, { once: true });
}

document.addEventListener('livewire:navigated', bootReveals);
document.addEventListener('livewire:navigating', () => {
    cleanupReveals();
    activePageRoot = null;
});

export { gsap, ScrollTrigger };
