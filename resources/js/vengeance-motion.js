// ---------------------------------------------------------------
// Vengeance-Motion: zeigergefuehrtes Akzentlicht fuer Dashboard-Karten.
//
// Setzt --rt-glow-x/--rt-glow-y/--rt-glow-o per Pointer-Delegation auf
// Karten (Selektorliste unten); die eigentliche Optik lebt in app.css
// (::after-Radialgradient). Delegation auf document ueberlebt den
// <body>-Tausch von wire:navigate ohne Rebind. Auf Touchgeraeten und bei
// prefers-reduced-motion passiert nichts — der Grundzustand bleibt ruhig.
// ---------------------------------------------------------------
import gsap from 'gsap';

const GLOW_SELECTOR = [
    '.rt-admin-panel',
    '.rt-admin-live-card',
    '.rt-admin-operations-card',
    '.rt-admin-quick-link',
    '.rt-operational-stat',
    '[data-rt-glow]',
].join(', ');

const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const clampPercent = gsap.utils.clamp(0, 100);

let pendingFrame = null;
let lastPointerEvent = null;
let glowingCard = null;

function clearGlow(card) {
    if (!card) return;
    card.style.setProperty('--rt-glow-o', '0');
}

function applyGlow() {
    pendingFrame = null;

    const event = lastPointerEvent;
    if (!event) return;

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
    if (!finePointer.matches || reducedMotion.matches) return;

    lastPointerEvent = event;
    if (pendingFrame === null) {
        pendingFrame = window.requestAnimationFrame(applyGlow);
    }
}, { passive: true });

document.addEventListener('pointerleave', () => {
    clearGlow(glowingCard);
    glowingCard = null;
}, { passive: true });

// Beim Seitenwechsel (Body-Swap) haengt der alte Kartenverweis im Nichts —
// Referenz loesen, damit kein Detached-DOM gehalten wird.
document.addEventListener('livewire:navigating', () => {
    glowingCard = null;
    lastPointerEvent = null;
});
