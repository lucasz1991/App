@props([
    'label' => null, 'role' => null,
    'openState' => 'open', 'modeState' => 'mode', 'cornerState' => 'corner',
    'enabledState' => 'wide', 'layoutAction' => '',
    'dragLabel' => 'Fensterposition',
])

@once
<style data-rt-floating-window-geometry>
:root { --rt-floating-mini-width: 442px; --rt-floating-mini-height: 510px; }
@media (min-width: 761px) {
    .rt-floating-window[data-floating-mode='mini'] {
        width: min(var(--rt-floating-mini-width), calc(100vw - 16px));
        height: min(var(--rt-floating-mini-height), calc(100dvh - var(--rt-floating-top, 70px) - 16px - var(--comm-bottom, var(--rt-assistant-bottom, 0px))));
    }
}
</style>
<script data-rt-floating-window-controller>
(() => {
// The component owns all window presentation; feature controllers keep their sessions.
window.rtFloatingWindow = (bindings, panel) => {
    // Resolve the owning Alpine scope on use, including after a Livewire morph.
    const state = typeof bindings.opened === 'string' ? {
        opened: () => Alpine.evaluate(panel, bindings.opened),
        mode: () => Alpine.evaluate(panel, bindings.mode),
        corner: () => Alpine.evaluate(panel, bindings.corner),
        enabled: () => Alpine.evaluate(panel, bindings.enabled),
        setMode: value => Alpine.evaluate(panel, bindings.mode+' = rtWindowValue; '+bindings.layout, {scope:{rtWindowValue:value}}),
        setCorner: value => Alpine.evaluate(panel, bindings.corner+' = rtWindowValue', {scope:{rtWindowValue:value}}),
    } : bindings;
    let animation = null, timer = null, revision = 0, pointer = null, lastLauncherRect = null;
    let settled = null, pending = null, previousLayout = '', previousOpen = false, disposed = false;
    let media = null, reduceMotion = null;
    const easing = 'cubic-bezier(.22,.8,.25,1)';
    const rectFrame = r => ({left: r.left+'px', top: r.top+'px', width: r.width+'px', height: r.height+'px'});
    const sample = () => ({rect: panel.getBoundingClientRect(), opacity: Number(getComputedStyle(panel).opacity)});
    const measureLauncher = launcher => {
        let rect = launcher?.getBoundingClientRect();
        if (rect?.width && rect?.height) return rect;

        const changed = [];
        try {
            for (let element = launcher; element && element !== document.body.parentElement; element = element.parentElement) {
                if (getComputedStyle(element).display !== 'none') continue;
                changed.push({
                    element,
                    display: element.style.getPropertyValue('display'),
                    priority: element.style.getPropertyPriority('display'),
                });
                element.style.setProperty('display', element === launcher ? 'inline-flex' : 'block', 'important');
            }
            rect = launcher?.getBoundingClientRect();
        } finally {
            changed.reverse().forEach(({element, display, priority}) => {
                if (display) element.style.setProperty('display', display, priority);
                else element.style.removeProperty('display');
            });
        }
        return rect;
    };
    const launcherRect = () => {
        if (!panel.id) return null;
        const launchers = Array.from(document.querySelectorAll('[data-rt-window-launcher-for],button[aria-controls],a[aria-controls],[role="button"][aria-controls]'));
        const launcher = launchers.find(element => element.getAttribute('data-rt-window-launcher-for') === panel.id)
            || launchers.find(element => element.getAttribute('aria-controls')?.split(/\s+/).includes(panel.id));
        const rect = measureLauncher(launcher);
        if (rect?.width && rect?.height) {
            lastLauncherRect = {left:rect.left, top:rect.top, width:rect.width, height:rect.height};
        }
        return lastLauncherRect;
    };
    return {
        windowVisible: false, windowMotion: 'idle', windowDragging: false, windowDockTarget: null,
        initWindow() {
            media = window.matchMedia('(prefers-reduced-motion: reduce)');
            reduceMotion = () => this.resetWindow();
            media.addEventListener?.('change', reduceMotion);
            window.addEventListener('resize', launcherRect);
            window.addEventListener('railtime-launcher-side', launcherRect);
            this.windowVisible = Boolean(state.opened());
            previousOpen = this.windowVisible;
            previousLayout = state.mode()+':'+state.corner();
            // One watcher coalesces opening + mode changes into one transition.
            this.$watch(() => [state.opened(), state.mode(), state.corner()].join(':'), () => {
                const opened = Boolean(state.opened()), layout = state.mode()+':'+state.corner();
                const visibilityChanged = opened !== previousOpen, layoutChanged = layout !== previousLayout;
                previousOpen = opened; previousLayout = layout;
                if (visibilityChanged) this.revealWindow(opened);
                else if (opened && this.windowVisible && layoutChanged) this.layoutWindow();
            });
            this.$nextTick(() => {
                launcherRect();
                if (this.windowVisible) settled = sample();
            });
        },
        destroyWindow() {
            disposed = true; this.finishWindowMotion(); this.windowCancelDrag();
            media?.removeEventListener?.('change', reduceMotion);
            window.removeEventListener('resize', launcherRect);
            window.removeEventListener('railtime-launcher-side', launcherRect);
        },
        canMoveWindow() { return Boolean(state.opened() && state.enabled() && state.mode() === 'mini' && window.innerWidth > 760); },
        windowDockStyle() {
            const c = this.windowDockTarget || state.corner();
            const top = parseFloat(getComputedStyle(panel).getPropertyValue('--rt-floating-top')) || 70;
            return 'width:'+panel.offsetWidth+'px;height:'+panel.offsetHeight+'px;'
                +(c.startsWith('top') ? 'top:'+(top+8)+'px' : 'bottom:8px')+';'
                +(c.endsWith('left') ? 'left:8px' : 'right:8px');
        },
        setWindowMotion(value) { this.windowMotion = value; panel.setAttribute('data-motion', value); },
        finishWindowMotion() {
            revision++;
            if (timer !== null) clearTimeout(timer);
            timer = null;
            animation?.cancel(); animation = null;
            this.setWindowMotion('idle');
            panel.removeAttribute('data-rt-window-motion-driver');
            panel.style.removeProperty('visibility');
        },
        playWindow(frames, duration, complete) {
            const version = revision;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { complete(); return; }
            if (typeof panel.animate !== 'function') {
                timer = setTimeout(() => { if (!disposed && version === revision) complete(); }, duration+40);
                return;
            }
            panel.setAttribute('data-rt-window-motion-driver', 'waapi');
            const current = panel.animate(frames, {duration, easing, fill:'both'});
            animation = current;
            current.onfinish = () => {
                if (disposed || version !== revision || animation !== current) return;
                animation = null; complete(); current.cancel();
                panel.removeAttribute('data-rt-window-motion-driver');
            };
        },
        revealWindow(opening) {
            const interrupted = this.windowVisible && this.windowMotion !== 'idle';
            // Sample the rendered box BEFORE cancelling, including a partial resize.
            const from = interrupted || !opening ? sample() : null;
            const launcher = launcherRect();
            pending = null;
            this.finishWindowMotion(); this.windowCancelDrag();
            if (!opening && !this.windowVisible) return;
            if (opening && !interrupted) panel.style.visibility = 'hidden';
            this.windowVisible = true;
            this.setWindowMotion(opening ? 'preparing' : 'closing');
            const version = revision;
            queueMicrotask(() => {
                if (disposed || version !== revision) return;
                const upper = state.corner().startsWith('top');
                panel.style.setProperty('--rt-floating-reveal-y', upper ? '-8px' : '8px');
                const collapsed = {transform:'translateY('+(upper ? -8 : 8)+'px)', opacity:0};
                const expanded = {transform:'none', opacity:1};
                const target = panel.getBoundingClientRect();
                const start = from || (opening && launcher ? {rect:launcher, opacity:1} : null);
                const end = !opening && launcher ? launcher : (opening ? target : from?.rect);
                const useGeometry = Boolean((opening && (launcher || interrupted)) || (!opening && launcher));
                // Real box geometry makes opening/closing continuous with resize and docking.
                // Interrupted transitions continue from their currently rendered frame.
                const frames = useGeometry && start && end
                    ? [{...rectFrame(start.rect), transform:'none', opacity:start.opacity},
                        {...rectFrame(end), transform:'none', opacity:opening ? 1 : (launcher ? 1 : 0)}]
                    : from
                        ? [{...rectFrame(from.rect), transform:'none', opacity:from.opacity},
                            {...rectFrame(from.rect), ...(opening ? expanded : collapsed)}]
                        : [opening ? collapsed : expanded, opening ? expanded : collapsed];
                this.setWindowMotion(opening ? 'opening' : 'closing');
                this.playWindow(frames, 300, () => {
                    this.setWindowMotion('idle'); this.windowVisible = opening;
                    if (opening) settled = {rect:target, opacity:1};
                });
                panel.style.removeProperty('visibility');
            });
        },
        resizeWindow() {
            if (!state.enabled() || window.innerWidth <= 760) return;
            pending = sample();
            state.setMode(state.mode() === 'mini' ? 'fullscreen' : 'mini');
        },
        layoutWindow() {
            const from = pending || (animation ? sample() : settled);
            pending = null; this.finishWindowMotion();
            const version = revision;
            // Alpine's nextTick yields to a timer and can paint the target size
            // before the animation starts. Bindings have flushed before this
            // watcher; finish setup in this microtask, before the next paint.
            queueMicrotask(() => {
                if (disposed || version !== revision || !state.opened()) return;
                const to = panel.getBoundingClientRect();
                if (!from?.rect.width || !to.width) { settled = {rect:to, opacity:1}; return; }
                this.setWindowMotion('resizing');
                // Real box geometry avoids stretching text and controls.
                this.playWindow([{...rectFrame(from.rect), transform:'none', opacity:from.opacity}, {...rectFrame(to), transform:'none', opacity:1}], 300, () => {
                    this.setWindowMotion('idle'); settled = {rect:to, opacity:1};
                });
            });
        },
        windowDragStart(event) {
            if (!this.canMoveWindow() || event.isPrimary === false || event.button !== 0) return;
            const control = event.target.closest('button,a,input,textarea,select,[role="tab"],[role="dialog"],[contenteditable="true"],[data-no-drag]');
            if (control && control !== panel) return;
            if (Array.from(document.querySelectorAll('dialog[open],[role="dialog"][aria-modal="true"]')).some(d => d !== panel && d.getClientRects().length && getComputedStyle(d).visibility !== 'hidden')) return;
            pointer = {id:event.pointerId, x:event.clientX, y:event.clientY, rect:null};
        },
        windowDragMove(event) {
            if (!pointer || pointer.id !== event.pointerId) return;
            if (!this.canMoveWindow() || event.isPrimary === false) { this.windowCancelDrag(); return; }
            const dx = event.clientX-pointer.x, dy = event.clientY-pointer.y;
            if (!pointer.rect) {
                if (Math.hypot(dx,dy) < 6) return;
                pointer.rect = panel.getBoundingClientRect();
                this.finishWindowMotion(); this.windowDragging = true;
                panel.setPointerCapture?.(event.pointerId);
            }
            event.preventDefault();
            const top = parseFloat(getComputedStyle(panel).getPropertyValue('--rt-floating-top')) || 70;
            const x = Math.max(8,Math.min(window.innerWidth-pointer.rect.width-8,pointer.rect.left+dx));
            const y = Math.max(top+8,Math.min(window.innerHeight-pointer.rect.height-8,pointer.rect.top+dy));
            Object.assign(panel.style,{left:x+'px',top:y+'px',right:'auto',bottom:'auto'});
            this.windowDockTarget = (y+pointer.rect.height/2 < (top+window.innerHeight)/2 ? 'top' : 'bottom')+'-'+(x+pointer.rect.width/2 < window.innerWidth/2 ? 'left' : 'right');
        },
        windowDragEnd(event) {
            if (!pointer || pointer.id !== event.pointerId) return;
            const corner = this.windowDockTarget;
            pending = pointer.rect ? sample() : null;
            this.windowCancelDrag();
            if (corner) {
                if (corner === state.corner()) this.layoutWindow();
                else state.setCorner(corner);
            }
        },
        windowCancelDrag() {
            const old = pointer; pointer = null;
            this.windowDragging = false; this.windowDockTarget = null;
            for (const name of ['left','top','right','bottom']) panel.style.removeProperty(name);
            if (old && panel.hasPointerCapture?.(old.id)) panel.releasePointerCapture(old.id);
        },
        windowDockKey(event) {
            if (!this.canMoveWindow() || !event.altKey || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)) return;
            event.preventDefault(); pending = sample();
            const [v,h] = state.corner().split('-');
            state.setCorner((event.key === 'ArrowUp' ? 'top' : event.key === 'ArrowDown' ? 'bottom' : v)+'-'+(event.key === 'ArrowLeft' ? 'left' : event.key === 'ArrowRight' ? 'right' : h));
        },
        resetWindow() {
            this.windowCancelDrag(); this.finishWindowMotion(); this.windowVisible = Boolean(state.opened());
            this.$nextTick(() => { if (this.windowVisible) settled = sample(); });
        },
    };
};
const registerRailTimeWindow = () => {
    Alpine.directive('rt-window', (panel, {expression}, {evaluate, cleanup}) => {
        const controls = Alpine.reactive(window.rtFloatingWindow(evaluate(expression), panel));
        // Add a local reactive scope without an x-data root: existing feature
        // refs still belong to their original owner and cannot be overwritten
        // by another window's controls.
        const releaseScope = Alpine.addScopeToNode(panel, controls);
        const data = Alpine.$data(panel);
        controls.initWindow.call(data);
        cleanup(() => { controls.destroyWindow.call(data); releaseScope(); });
    }).before('bind');
};
if (window.Alpine) registerRailTimeWindow();
else document.addEventListener('alpine:init', registerRailTimeWindow, {once:true});
})();
</script>
@endonce

<section
    x-rt-window="{
        opened: @js($openState), mode: @js($modeState), corner: @js($cornerState), enabled: @js($enabledState), layout: @js($layoutAction)
    }"
    :hidden="!windowVisible" x-cloak
    :data-floating-mode="({{ $enabledState }}) ? {{ $modeState }} : 'custom'"
    :data-motion="windowMotion" :data-dragging="windowDragging.toString()" :data-floating-open="({{ $openState }}).toString()"
    x-on:pointermove.window="windowDragMove($event)" x-on:pointerup.window="windowDragEnd($event)"
    x-on:keydown.escape="if (windowDragging) { windowCancelDrag(); $event.preventDefault(); $event.stopPropagation(); }"
    x-on:pointercancel.window="windowCancelDrag()" x-on:blur.window="windowCancelDrag()"
    x-on:lostpointercapture.self="if (windowDragging) windowCancelDrag()"
    x-on:resize.window="resetWindow()" x-on:livewire:navigating.document="resetWindow()"
    x-on:visibilitychange.document="resetWindow()"
    @if(filled($label)) aria-label="{{ $label }}" @endif
    @if(filled($role)) role="{{ $role }}" @endif
    {{ $attributes->class('rt-floating-window') }}
>
    @isset($header)
        <header x-on:pointerdown="windowDragStart($event)" x-on:keydown="windowDockKey($event)" :tabindex="canMoveWindow() ? 0 : -1"
            role="group" aria-label="{{ $dragLabel }}" title="Ziehen oder mit Alt und Pfeiltasten in eine Ecke setzen"
            {{ $header->attributes->class('rt-floating-window__header') }}>
            {{ $header }}
        </header>
    @endisset
    {{ $slot }}
    <template x-teleport="body">
        <div class="rt-floating-dock-target" x-show="windowDragging && windowDockTarget" x-transition.opacity.duration.100ms
            :style="windowDockStyle()" aria-hidden="true" style="display:none"></div>
    </template>
</section>
