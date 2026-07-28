// ---------------------------------------------------------------
// Vengeance-Motion: zeigergefuehrtes Akzentlicht fuer den globalen Content
// und bewusst markierte interaktive Karten.
//
// Setzt --rt-glow-x/--rt-glow-y/--rt-glow-o per Pointer-Delegation auf
// Karten (Selektorliste unten); die eigentliche Optik lebt in app.css
// (::after-Radialgradient). Delegation auf document ueberlebt den
// <body>-Tausch von wire:navigate ohne Rebind. Auf Touchgeraeten und bei
// prefers-reduced-motion passiert nichts — der Grundzustand bleibt ruhig.
// ---------------------------------------------------------------
import gsap from 'gsap';

const GLOW_SELECTOR = '[data-rt-glow]';
const AMBIENT_SELECTOR = '[data-rt-shell-ambient]';

const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const clampPercent = gsap.utils.clamp(0, 100);

let pendingFrame = null;
let lastPointerEvent = null;
let glowingCard = null;
let ambient = null;
let ambientPointer = null;
let ambientX = null;
let ambientY = null;
let ambientOpacity = null;

function clearGlow(card) {
    if (!card) return;
    card.style.setProperty('--rt-glow-o', '0');
}

function resolveAmbient() {
    const nextAmbient = document.querySelector(AMBIENT_SELECTOR);
    const nextPointer = nextAmbient?.querySelector('[data-rt-shell-pointer]') || null;

    if (nextAmbient === ambient && nextPointer === ambientPointer) {
        return;
    }

    ambient = nextAmbient;
    ambientPointer = nextPointer;
    ambientX = null;
    ambientY = null;
    ambientOpacity = null;

    if (!ambientPointer) return;

    gsap.set(ambientPointer, { xPercent: -50, yPercent: -50, opacity: 0 });
    ambientX = gsap.quickTo(ambientPointer, 'x', { duration: 0.72, ease: 'power3.out' });
    ambientY = gsap.quickTo(ambientPointer, 'y', { duration: 0.72, ease: 'power3.out' });
    ambientOpacity = gsap.quickTo(ambientPointer, 'opacity', { duration: 0.32, ease: 'power2.out' });
}

function clearAmbient() {
    if (ambientPointer?.isConnected) {
        gsap.set(ambientPointer, { opacity: 0 });
    }

    ambient = null;
    ambientPointer = null;
    ambientX = null;
    ambientY = null;
    ambientOpacity = null;
}

function resetGlow() {
    if (pendingFrame !== null) {
        window.cancelAnimationFrame(pendingFrame);
        pendingFrame = null;
    }

    if (glowingCard) {
        clearGlow(glowingCard);
        glowingCard.style.removeProperty('--rt-glow-x');
        glowingCard.style.removeProperty('--rt-glow-y');
        glowingCard.style.removeProperty('--rt-glow-o');
    }

    glowingCard = null;
    lastPointerEvent = null;
    clearAmbient();
}

function applyAmbient(event) {
    resolveAmbient();

    const target = event.target instanceof Element ? event.target : null;
    if (!ambient || !ambientPointer || !target?.closest('#main-content')) {
        ambientOpacity?.(0);
        return;
    }

    const rect = ambient.getBoundingClientRect();
    if (!rect.width || !rect.height) return;

    ambientX?.(gsap.utils.clamp(0, rect.width, event.clientX - rect.left));
    ambientY?.(gsap.utils.clamp(0, rect.height, event.clientY - rect.top));
    ambientOpacity?.(0.82);
}

function applyGlow() {
    pendingFrame = null;

    const event = lastPointerEvent;
    if (!event) return;

    applyAmbient(event);

    const target = event.target instanceof Element ? event.target : null;
    const card = target ? target.closest(GLOW_SELECTOR) : null;

    if (card !== glowingCard) {
        clearGlow(glowingCard);
        glowingCard = card;
    }

    if (!card) return;

    const rect = card.getBoundingClientRect();
    if (!rect.width || !rect.height) return;

    const x = clampPercent(((event.clientX - rect.left) / rect.width) * 100);
    const y = clampPercent(((event.clientY - rect.top) / rect.height) * 100);

    card.style.setProperty('--rt-glow-x', `${x.toFixed(1)}%`);
    card.style.setProperty('--rt-glow-y', `${y.toFixed(1)}%`);
    card.style.setProperty('--rt-glow-o', '1');
}

document.addEventListener('pointermove', (event) => {
    if (!finePointer.matches || reducedMotion.matches || event.pointerType === 'touch') {
        resetGlow();
        return;
    }

    lastPointerEvent = event;
    if (pendingFrame === null) {
        pendingFrame = window.requestAnimationFrame(applyGlow);
    }
}, { passive: true });

document.addEventListener('pointerleave', () => {
    resetGlow();
}, { passive: true });

finePointer.addEventListener('change', resetGlow);
reducedMotion.addEventListener('change', resetGlow);

// Beim Seitenwechsel (Body-Swap) haengt der alte Kartenverweis im Nichts —
// Referenz loesen, damit kein Detached-DOM gehalten wird.
document.addEventListener('livewire:navigating', resetGlow);
