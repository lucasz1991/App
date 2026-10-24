{{-- One physical rail; feature-owned Alpine teleports retain their own state. --}}
@once
<style data-rt-floating-launcher-geometry>
[data-rt-floating-launcher-group] {
    --rt-floating-safe-left: env(safe-area-inset-left, 0px);
    --rt-floating-safe-right: env(safe-area-inset-right, 0px);
    --rt-floating-safe-bottom: env(safe-area-inset-bottom, 0px);
    position: fixed; left: var(--rt-floating-launcher-x, auto); right: max(var(--rt-floating-window-inset, 8px), var(--rt-floating-safe-right));
    bottom: var(--rt-floating-launcher-bottom, max(16px, env(safe-area-inset-bottom, 0px)));
    z-index: 26; display: flex; flex-direction: column; align-items: flex-end;
    gap: 12px; width: max-content; max-width: calc(100vw - 2 * var(--rt-floating-window-inset, 8px)); padding: 0;
    pointer-events: none; transition: left 220ms cubic-bezier(.22,.8,.25,1);
}
[data-rt-floating-launcher-group][data-rt-launcher-positioned] { right: auto; }
[data-rt-floating-launcher-group][data-rt-launcher-layout='row'] { flex-direction: row; }
[data-rt-floating-launcher-item] { flex: none; pointer-events: auto; }
[data-rt-floating-launcher-item='assist'] { order: 1; }
[data-rt-floating-launcher-item='communication'] { order: 2; }
[data-rt-floating-launcher-group] :is(.rt-comm-launcher, .rt-ai-assist__stage, .rt-chatbot__pet-stage) {
    position: relative !important; inset: auto !important; margin: 0 !important;
    transform: none !important; transition: opacity 160ms ease;
}
body:has(.rt-floating-window[data-floating-open='true']:is([data-floating-mode='fullscreen'], [data-mode='fullscreen'], [data-window-mode='fullscreen'])) [data-rt-floating-launcher-group] { visibility: hidden; pointer-events: none; }
@media (prefers-reduced-motion: reduce) {
    [data-rt-floating-launcher-group] { transition: none; }
}
</style>
<script data-rt-floating-launcher-controller>
window.rtResolveFloatingLauncherBottom = function({height, viewportBottom, preferredBottom, minTop, stackHeight, left, right, obstacles, gap = 12}) {
    let bottom = Math.max(preferredBottom, height - viewportBottom + gap);
    for (let pass = 0; pass <= obstacles.length; pass++) {
        const railBottom = height - bottom, railTop = railBottom - stackHeight;
        let next = bottom;
        for (const rect of obstacles) {
            if (rect.right <= left - gap || rect.left >= right + gap || rect.bottom <= railTop - gap || rect.top >= railBottom + gap) continue;
            next = Math.max(next, height - rect.top + gap);
        }
        if (next === bottom) break;
        bottom = next;
    }
    return Math.max(height - viewportBottom + gap, Math.min(bottom, height - minTop - stackHeight));
};
// Resolve against the native box, never against a previous avoidance offset.
window.rtResolveFloatingWindowBottom = function({height, viewportBottom, left, right, top, bottom, obstacles, gap = 12}) {
    let lift = 0, reserve = 0;
    for (let pass = 0; pass <= obstacles.length; pass++) {
        let next = lift;
        for (const rect of obstacles) {
            if (rect.top >= viewportBottom || rect.bottom <= 0 || rect.right <= left - gap || rect.left >= right + gap
                || rect.bottom <= top - lift - gap || rect.top >= bottom - lift + gap) continue;
            next = Math.max(next, bottom - rect.top + gap);
        }
        if (next === lift) break;
        lift = next;
        reserve = height - bottom + lift;
    }
    return Math.max(0, reserve);
};
window.rtInitializeFloatingLauncherGroup = function(doc = document, win = window) {
    const html = doc?.documentElement;
    if (!html) return null;
    if (html._rtLauncherGroup) return html._rtLauncherGroup;
    html.dataset.rtLauncherGroupReady = 'true';
    let drag = null, suppressClickUntil = 0, frame = null, destroyed = false;
    const launchers = new Set(), cleanups = [], watched = new Set(), windows = new Set(), ownStyles = new WeakMap();
    const obstacleSelector = '[data-table-pagination], [data-orders-pagination], [data-service-pagination], .rt-service-pagination, .rt-pagination, [data-area-menu], [data-floating-launcher-obstacle]';
    const group = () => doc.querySelector('[data-rt-floating-launcher-group]');
    const listen = (target, name, listener, options) => {
        target?.addEventListener(name, listener, options);
        cleanups.push(() => target?.removeEventListener(name, listener, options));
    };
    const visibleRect = element => {
        const raw = element.getBoundingClientRect();
        if (!raw.width || !raw.height) return null;
        const rect = {left: raw.left, right: raw.right, top: raw.top, bottom: raw.bottom};
        for (let node = element; node && node !== html; node = node.parentElement) {
            const style = win.getComputedStyle(node);
            if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return null;
            if (node === element) continue;
            const clip = node.getBoundingClientRect();
            if (/(auto|scroll|hidden|clip)/.test(style.overflowX)) {
                rect.left = Math.max(rect.left, clip.left); rect.right = Math.min(rect.right, clip.right);
            }
            if (/(auto|scroll|hidden|clip)/.test(style.overflowY)) {
                rect.top = Math.max(rect.top, clip.top); rect.bottom = Math.min(rect.bottom, clip.bottom);
            }
        }
        return rect.right > rect.left && rect.bottom > rect.top ? rect : null;
    };
    const queueLayout = () => {
        if (destroyed || frame !== null || !win.requestAnimationFrame) return;
        frame = win.requestAnimationFrame(() => { frame = null; measureLayout(); });
    };
    const resizeObserver = win.ResizeObserver ? new win.ResizeObserver(queueLayout) : null;
    const measureLayout = () => {
        if (destroyed) return;
        // Navigation replaces the layout target while Communication persists.
        // Always resolve the current rail, never keep the detached old one.
        const rail = group();
        if (!rail) return;
        const viewport = win.visualViewport, top = Math.max(0, viewport?.offsetTop || 0);
        const viewportHeight = viewport?.height || win.innerHeight;
        const viewportBottom = Math.min(win.innerHeight, top + viewportHeight);
        const bar = doc.querySelector('[data-rt-shell-topbar]')?.getBoundingClientRect();
        const minTop = Math.max(top + 12, (bar?.bottom || 0) + 12);
        const observed = new Set([rail, ...launchers]), obstacles = [], panels = [];
        for (const element of doc.querySelectorAll(obstacleSelector)) {
            if (element.matches('[data-area-menu]') && win.getComputedStyle(element).position !== 'fixed') continue;
            if (element.closest('.rt-floating-window')) continue;
            observed.add(element);
            const rect = visibleRect(element);
            if (rect && rect.bottom > top && rect.top < viewportBottom) obstacles.push(rect);
        }
        const currentWindows = new Set(doc.querySelectorAll('.rt-floating-window'));
        const clearWindow = panel => {
            if (panel.style.getPropertyValue('--rt-floating-window-obstacle-bottom')) panel.style.removeProperty('--rt-floating-window-obstacle-bottom');
            ownStyles.set(panel, panel.getAttribute('style'));
        };
        for (const panel of windows) if (!currentWindows.has(panel)) { clearWindow(panel); windows.delete(panel); }
        for (const panel of currentWindows) {
            windows.add(panel);
            const mode = panel.getAttribute('data-floating-mode');
            if (panel.getAttribute('data-floating-open') !== 'true' || !['mini', 'fullscreen'].includes(mode)) { clearWindow(panel); continue; }
            observed.add(panel);
            if (['opening', 'closing', 'resizing'].includes(panel.getAttribute('data-motion'))) continue;
            const previous = panel.style.getPropertyValue('--rt-floating-window-obstacle-bottom');
            // Read the native viewport/keyboard box before applying our own reserve.
            if (previous) panel.style.removeProperty('--rt-floating-window-obstacle-bottom');
            const rect = panel.getBoundingClientRect();
            const reserve = rect.width && rect.height ? window.rtResolveFloatingWindowBottom({height: win.innerHeight, viewportBottom,
                left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, obstacles}) : 0;
            panel.style.setProperty('--rt-floating-window-obstacle-bottom', `${Math.round(reserve * 100) / 100}px`);
            ownStyles.set(panel, panel.getAttribute('style'));
        }
        for (const panel of currentWindows) {
            if (panel.getAttribute('data-floating-mode') !== 'mini' || panel.getAttribute('data-floating-open') !== 'true') continue;
            const rect = visibleRect(panel);
            if (rect) panels.push(rect);
        }
        for (const element of watched) if (!observed.has(element)) { resizeObserver?.unobserve(element); watched.delete(element); }
        for (const element of observed) if (!watched.has(element)) { resizeObserver?.observe(element); watched.add(element); }
        if (![...launchers].some(button => button.isConnected && rail.contains(button) && visibleRect(button))) return;
        const railStyle = win.getComputedStyle(rail);
        const edgeInset = Math.max(0, parseFloat(railStyle.getPropertyValue?.('--rt-floating-window-inset')) || 8);
        const edgeLeft = Math.max(edgeInset, parseFloat(railStyle.getPropertyValue?.('--rt-floating-safe-left')) || 0);
        const edgeRight = Math.max(edgeInset, parseFloat(railStyle.getPropertyValue?.('--rt-floating-safe-right')) || 0);
        const edgeBottom = Math.max(16, parseFloat(railStyle.getPropertyValue?.('--rt-floating-safe-bottom')) || 0);
        const preferredBottom = win.innerHeight - viewportBottom + edgeBottom;
        const choosePosition = (width, height) => {
            const preferredX = html.dataset.rtLauncherSide === 'left' ? edgeLeft : win.innerWidth - edgeRight - width;
            const candidates = [preferredX, ...panels.flatMap(rect => [rect.left - width - 12, rect.right + 12])];
            const choices = candidates.filter(x => x >= edgeLeft && x + width <= win.innerWidth - edgeRight).map(x => {
                const bottom = window.rtResolveFloatingLauncherBottom({height: win.innerHeight, viewportBottom, preferredBottom,
                    minTop, stackHeight: height, left: x, right: x + width, obstacles: [...obstacles, ...panels]});
                const y = win.innerHeight - bottom - height, lower = y + height;
                const collisions = [...obstacles, ...panels].filter(rect => rect.right > x - 12 && rect.left < x + width + 12 && rect.bottom > y - 12 && rect.top < lower + 12).length;
                return {x, bottom, fits: y >= minTop && !collisions,
                    score: collisions * 100000 + Math.max(0, minTop - y) * 1000 + Math.abs(bottom - preferredBottom) * 100 + Math.abs(x - preferredX)};
            });
            return choices.sort((a, b) => a.score - b.score)[0] || {x: edgeLeft, bottom: Math.max(12, win.innerHeight - viewportBottom + 12), fits: false};
        };
        rail.dataset.rtLauncherLayout = 'stack';
        let rect = rail.getBoundingClientRect(), position = choosePosition(rect.width, rect.height);
        if (!position.fits && [...launchers].filter(button => rail.contains(button) && visibleRect(button)).length > 1) {
            rail.dataset.rtLauncherLayout = 'row';
            rect = rail.getBoundingClientRect();
            position = choosePosition(rect.width, rect.height);
        }
        const values = {'--rt-floating-launcher-bottom': `${Math.round(position.bottom * 100) / 100}px`, '--rt-floating-launcher-x': `${Math.round(position.x * 100) / 100}px`};
        for (const [key, value] of Object.entries(values)) if (rail.style.getPropertyValue(key) !== value) rail.style.setProperty(key, value);
        ownStyles.set(rail, rail.getAttribute('style'));
        if (!rail.hasAttribute('data-rt-launcher-positioned')) rail.setAttribute('data-rt-launcher-positioned', '');
    };
    const observer = win.MutationObserver ? new win.MutationObserver(records => {
        if (records.some(record => record.type !== 'attributes' || record.attributeName !== 'style'
            || (record.target !== html && ownStyles.get(record.target) !== record.target.getAttribute('style')))) queueLayout();
    }) : null;
    observer?.observe(doc.body, {subtree: true, childList: true, attributes: true,
        attributeFilter: ['class', 'style', 'hidden', 'data-open', 'data-communication-open', 'data-window-mode', 'data-mode', 'data-corner', 'data-floating-mode', 'data-floating-open', 'data-motion']});
    listen(win, 'resize', queueLayout);
    listen(win.visualViewport, 'resize', queueLayout);
    listen(win.visualViewport, 'scroll', queueLayout);
    listen(doc, 'scroll', queueLayout, {capture: true, passive: true});
    listen(doc, 'transitionend', event => {
        if (['left', 'right', 'opacity', 'visibility'].includes(event.propertyName)
            && event.target.closest?.('[data-rt-floating-launcher-group]')) queueLayout();
    }, true);
    listen(doc, 'livewire:navigated', queueLayout);
    listen(win, 'rt-floating-launcher-layout', queueLayout);
    const normalize = value => value === 'left' ? 'left' : 'right';
    const apply = (side, persist = false) => {
        const value = normalize(side);
        html.dataset.rtLauncherSide = value;
        if (persist) { try { win.localStorage?.setItem('railtime-floating-launcher-side', value); } catch {} }
        win.dispatchEvent?.(new CustomEvent('railtime-launcher-side', {detail: {side: value}}));
        queueLayout();
    };
    let initial = 'right';
    try { initial = normalize(win.localStorage?.getItem('railtime-floating-launcher-side')); } catch {}
    apply(initial);
    listen(doc, 'pointerdown', event => {
        const launcher = event.target.closest?.('[data-rt-floating-launcher]');
        if (!launcher || event.isPrimary === false || event.button !== 0) return;
        drag = {launcher, id: event.pointerId, x: event.clientX, y: event.clientY, moved: false};
    }, true);
    listen(doc, 'pointermove', event => {
        if (!drag || drag.id !== event.pointerId) return;
        if (!drag.moved && Math.hypot(event.clientX - drag.x, event.clientY - drag.y) < 8) return;
        drag.moved = true;
        drag.launcher.setPointerCapture?.(event.pointerId);
        apply(event.clientX < win.innerWidth / 2 ? 'left' : 'right');
        event.preventDefault();
    }, {capture: true, passive: false});
    const finish = event => {
        if (!drag || drag.id !== event.pointerId) return;
        const current = drag; drag = null;
        if (current.launcher.hasPointerCapture?.(event.pointerId)) current.launcher.releasePointerCapture(event.pointerId);
        if (!current.moved) return;
        apply(event.clientX < win.innerWidth / 2 ? 'left' : 'right', true);
        suppressClickUntil = performance.now() + 450;
        event.preventDefault();
    };
    listen(doc, 'pointerup', finish, true);
    listen(doc, 'pointercancel', event => { if (drag?.id === event.pointerId) drag = null; }, true);
    listen(doc, 'click', event => {
        if (performance.now() <= suppressClickUntil && event.target.closest?.('[data-rt-floating-launcher]')) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    listen(doc, 'keydown', event => {
        if (!event.altKey || !['ArrowLeft', 'ArrowRight'].includes(event.key) || !event.target.closest?.('[data-rt-floating-launcher]')) return;
        event.preventDefault(); apply(event.key === 'ArrowLeft' ? 'left' : 'right', true);
    });
    const api = {
        layout: measureLayout,
        register(button) {
            launchers.add(button); measureLayout(); queueLayout();
            return () => { launchers.delete(button); if (launchers.size) queueLayout(); else api.destroy(); };
        },
        destroy() {
            if (destroyed) return;
            destroyed = true; observer?.disconnect(); resizeObserver?.disconnect();
            if (frame !== null) win.cancelAnimationFrame?.(frame);
            cleanups.splice(0).forEach(cleanup => cleanup());
            for (const panel of windows) panel.style.removeProperty('--rt-floating-window-obstacle-bottom');
            windows.clear();
            const rail = group();
            rail?.style.removeProperty('--rt-floating-launcher-bottom'); rail?.style.removeProperty('--rt-floating-launcher-x');
            rail?.removeAttribute('data-rt-launcher-positioned');
            if (html._rtLauncherGroup === api) { delete html._rtLauncherGroup; delete html.dataset.rtLauncherGroupReady; }
        },
    };
    html._rtLauncherGroup = api;
    return api;
};
(() => {
    const register = () => {
        if (window.Alpine._rtLauncherDirectiveReady) return;
        window.Alpine._rtLauncherDirectiveReady = true;
        window.Alpine.directive('rt-launcher', (button, _, {cleanup}) => { cleanup(window.rtInitializeFloatingLauncherGroup().register(button)); }).before('bind');
    };
    if (window.Alpine) register(); else document.addEventListener('alpine:init', register, {once: true});
})();
</script>
@endonce
<div data-rt-floating-launcher-group role="group" aria-label="Chat und Assistent"></div>
