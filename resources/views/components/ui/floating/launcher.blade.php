@props(['type' => 'button'])

@once
<style data-rt-floating-launcher-geometry>
:root { --rt-floating-launcher-lift: 16px; }
</style>
<script data-rt-floating-launcher-controller>
window.rtInitializeFloatingLauncherGroup = function(doc = document, win = window) {
    const html = doc?.documentElement;
    if (!html || html.dataset.rtLauncherGroupReady === 'true') return;
    html.dataset.rtLauncherGroupReady = 'true';
    let drag = null, suppressClickUntil = 0;
    const normalize = value => value === 'left' ? 'left' : 'right';
    const apply = (side, persist = false) => {
        const value = normalize(side);
        html.dataset.rtLauncherSide = value;
        if (persist) {
            try { win.localStorage?.setItem('railtime-floating-launcher-side', value); } catch {}
        }
        win.dispatchEvent?.(new CustomEvent('railtime-launcher-side', { detail: { side: value } }));
    };
    let initial = 'right';
    try { initial = normalize(win.localStorage?.getItem('railtime-floating-launcher-side')); } catch {}
    apply(initial);
    doc.addEventListener('pointerdown', event => {
        const launcher = event.target.closest?.('[data-rt-floating-launcher]');
        if (!launcher || event.isPrimary === false || event.button !== 0) return;
        drag = { launcher, id: event.pointerId, x: event.clientX, y: event.clientY, moved: false };
    }, true);
    doc.addEventListener('pointermove', event => {
        if (!drag || drag.id !== event.pointerId) return;
        if (!drag.moved && Math.hypot(event.clientX - drag.x, event.clientY - drag.y) < 8) return;
        drag.moved = true;
        drag.launcher.setPointerCapture?.(event.pointerId);
        apply(event.clientX < win.innerWidth / 2 ? 'left' : 'right');
        event.preventDefault();
    }, { capture: true, passive: false });
    const finish = event => {
        if (!drag || drag.id !== event.pointerId) return;
        const current = drag; drag = null;
        if (current.launcher.hasPointerCapture?.(event.pointerId)) current.launcher.releasePointerCapture(event.pointerId);
        if (!current.moved) return;
        apply(event.clientX < win.innerWidth / 2 ? 'left' : 'right', true);
        suppressClickUntil = performance.now() + 450;
        event.preventDefault();
    };
    doc.addEventListener('pointerup', finish, true);
    doc.addEventListener('pointercancel', event => { if (drag?.id === event.pointerId) drag = null; }, true);
    doc.addEventListener('click', event => {
        if (performance.now() <= suppressClickUntil && event.target.closest?.('[data-rt-floating-launcher]')) {
            event.preventDefault(); event.stopImmediatePropagation();
        }
    }, true);
    doc.addEventListener('keydown', event => {
        if (!event.altKey || !['ArrowLeft', 'ArrowRight'].includes(event.key) || !event.target.closest?.('[data-rt-floating-launcher]')) return;
        event.preventDefault();
        apply(event.key === 'ArrowLeft' ? 'left' : 'right', true);
    });
}

</script>
@endonce

<button
    type="{{ $type }}"
    x-init="rtInitializeFloatingLauncherGroup()"
    data-rt-floating-launcher
    {{ $attributes->class('rt-floating-launcher') }}
>
    {{ $slot }}
</button>
