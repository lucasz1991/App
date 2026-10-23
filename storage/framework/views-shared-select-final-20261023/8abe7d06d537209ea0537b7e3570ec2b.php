<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'label' => null, 'role' => null,
    'openState' => 'open', 'modeState' => 'mode', 'cornerState' => 'corner',
    'enabledState' => 'wide', 'layoutAction' => '',
    'sessionKey' => null, 'sessionRestore' => '', 'sessionEnabledState' => 'true',
    'closeAction' => '', 'coordinationState' => 'true',
    'dragLabel' => 'Fensterposition',
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'label' => null, 'role' => null,
    'openState' => 'open', 'modeState' => 'mode', 'cornerState' => 'corner',
    'enabledState' => 'wide', 'layoutAction' => '',
    'sessionKey' => null, 'sessionRestore' => '', 'sessionEnabledState' => 'true',
    'closeAction' => '', 'coordinationState' => 'true',
    'dragLabel' => 'Fensterposition',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if (! $__env->hasRenderedOnce('c806dc8f-24fe-41e5-a3cc-6dca21ebbdcf')): $__env->markAsRenderedOnce('c806dc8f-24fe-41e5-a3cc-6dca21ebbdcf'); ?>
<style data-rt-floating-window-geometry>
:root { --rt-floating-mini-width: 442px; --rt-floating-mini-height: 510px; --rt-floating-window-inset: 8px; }
.rt-floating-window__header:has(> .rt-window-tabs) { flex-wrap: wrap; row-gap: 4px; }
.rt-floating-window__header:has(> .rt-window-tabs) > .rt-floating-window__header-spacer { flex: 1 1 0; min-width: 0; }
.rt-floating-window__header:has(> .rt-window-tabs) > .rt-floating-window__actions { margin-left: auto; }
.rt-floating-window__header > .rt-floating-window__actions { display: flex; flex: none; align-items: center; gap: 2px; }
.rt-floating-window__header > .rt-floating-window__actions > button,
.rt-floating-window__header > .rt-floating-window__actions .rt-floating-window__action,
.rt-floating-window__header > .rt-floating-window__actions > details > summary {
    display: inline-flex; flex: none; align-items: center; justify-content: center; padding: 0;
    width: var(--rt-floating-action-size, 40px); min-width: var(--rt-floating-action-size, 40px);
    height: var(--rt-floating-action-size, 40px); min-height: var(--rt-floating-action-size, 40px);
    border: 0; border-radius: var(--rt-floating-action-radius, 10px); background: transparent;
    color: #fff; font-size: 14px; line-height: 1;
}
.rt-floating-window__header > .rt-floating-window__actions > button:hover:not(:disabled),
.rt-floating-window__header > .rt-floating-window__actions .rt-floating-window__action:hover:not(:disabled),
.rt-floating-window__header > .rt-floating-window__actions .rt-floating-window__action[aria-expanded='true'],
.rt-floating-window__header > .rt-floating-window__actions > button[aria-expanded='true'] { background: rgb(255 255 255 / 15%); color: #fff; }
.rt-floating-window__header .rt-ai-assist__header-dropdown:first-child .rt-floating-window__action { background: transparent; }
@media (min-width: 761px) {
    .rt-floating-window:is([data-floating-mode='mini'], [data-floating-mode='fullscreen']) {
        --rt-floating-action-size: 34px; --rt-floating-action-radius: 8px;
    }
    .rt-floating-window:is([data-floating-mode='mini'], [data-floating-mode='fullscreen']) > .rt-floating-window__header {
        min-height: 56px; gap: 8px; padding: 6px 10px;
    }
    .rt-floating-window[data-floating-mode='mini'] {
        width: min(var(--rt-floating-mini-width), calc(100vw - 16px));
        height: min(var(--rt-floating-mini-height), calc(100dvh - var(--rt-floating-top, 70px) - 16px - var(--comm-bottom, var(--rt-assistant-bottom, 0px))));
    }
}
@media (max-width: 760px) {
    .rt-floating-window[data-floating-mode] {
        border: 0; border-radius: 0; box-shadow: none;
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
        sessionEnabled: () => Alpine.evaluate(panel, bindings.sessionEnabled),
        restore: value => Alpine.evaluate(panel, bindings.sessionRestore, {scope:{rtWindowSession:value}}),
        close: bindings.close ? () => Alpine.evaluate(panel, bindings.close) : null,
        coordinated: () => Alpine.evaluate(panel, bindings.coordinated),
    } : bindings;
    let animation = null, timer = null, revision = 0, pointer = null, lastLauncherRect = null;
    let settled = null, pending = null, previousLayout = '', previousOpen = false, disposed = false;
    let media = null, reduceMotion = null;
    let sessionStore = null, sessionKey = null, sessionMode = 'mini';
    let sessionWide = window.innerWidth > 760;
    const modes = ['mini', 'fullscreen'], corners = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
    const coordinatesWindows = () => !disposed && panel.id && state.close && state.coordinated?.() !== false;
    const announceWindow = () => {
        if (!coordinatesWindows() || !state.opened() || !modes.includes(state.mode())) return;
        window.dispatchEvent(new CustomEvent('rt-floating-window-activated', {detail:{windowId:panel.id, mode:state.mode()}}));
    };
    const receiveWindow = event => {
        const peer = event.detail;
        if (!coordinatesWindows() || !state.opened() || !peer?.windowId || peer.windowId === panel.id || !modes.includes(peer.mode)) return;
        if (peer.mode === 'fullscreen' || state.mode() === 'fullscreen') state.close();
    };
    const sessionEnabled = () => Boolean(bindings.sessionKey && state.sessionEnabled?.() !== false);
    const readSession = () => {
        if (!sessionEnabled()) return null;
        try {
            sessionStore = window.sessionStorage;
            const user = document.querySelector('meta[name="rt-user-id"]')?.content || 'current';
            sessionKey = 'rt-floating-window:v1:'+encodeURIComponent(user)+':'+bindings.sessionKey;
            const saved = JSON.parse(sessionStore.getItem(sessionKey) || 'null');
            return saved?.version === 1 && typeof saved.open === 'boolean' && modes.includes(saved.mode) && corners.includes(saved.corner) ? saved : null;
        } catch { return null; }
    };
    const saveSession = () => {
        if (disposed || !sessionEnabled() || !sessionStore || !sessionKey) return;
        const mode = state.mode(), corner = state.corner();
        if (!modes.includes(mode) || !corners.includes(corner)) return;
        // Mobile fullscreen is required by the viewport, not a new desktop preference.
        if (window.innerWidth > 760 && sessionWide) sessionMode = mode;
        try { sessionStore.setItem(sessionKey, JSON.stringify({version:1, open:Boolean(state.opened()), mode:sessionMode, corner})); } catch {}
    };
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
            sessionMode = window.innerWidth > 760 && modes.includes(state.mode()) ? state.mode() : 'mini';
            const saved = readSession();
            if (saved) { sessionMode = saved.mode; state.restore(saved); }
            window.addEventListener('pagehide', saveSession);
            window.addEventListener('rt-floating-window-activated', receiveWindow);
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
                saveSession();
                if (visibilityChanged) this.revealWindow(opened);
                else if (opened && this.windowVisible && layoutChanged) this.layoutWindow();
            });
            this.$watch(() => [state.opened(), state.mode()].join(':'), announceWindow);
            this.$nextTick(() => {
                if (state.mode() === 'fullscreen') announceWindow();
                launcherRect();
                if (this.windowVisible) settled = sample();
            });
        },
        destroyWindow() {
            disposed = true; this.finishWindowMotion(); this.windowCancelDrag();
            media?.removeEventListener?.('change', reduceMotion);
            window.removeEventListener('resize', launcherRect);
            window.removeEventListener('railtime-launcher-side', launcherRect);
            window.removeEventListener('pagehide', saveSession);
            window.removeEventListener('rt-floating-window-activated', receiveWindow);
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
            if (sessionEnabled()) {
                const wide = window.innerWidth > 760;
                if (wide && !sessionWide) state.setMode(sessionMode);
                sessionWide = wide;
            }
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
<?php endif; ?>

<section
    x-rt-window="{
        opened: <?php echo \Illuminate\Support\Js::from($openState)->toHtml() ?>, mode: <?php echo \Illuminate\Support\Js::from($modeState)->toHtml() ?>, corner: <?php echo \Illuminate\Support\Js::from($cornerState)->toHtml() ?>, enabled: <?php echo \Illuminate\Support\Js::from($enabledState)->toHtml() ?>, layout: <?php echo \Illuminate\Support\Js::from($layoutAction)->toHtml() ?>,
        sessionKey: <?php echo \Illuminate\Support\Js::from($sessionKey)->toHtml() ?>, sessionRestore: <?php echo \Illuminate\Support\Js::from($sessionRestore)->toHtml() ?>, sessionEnabled: <?php echo \Illuminate\Support\Js::from($sessionEnabledState)->toHtml() ?>,
        close: <?php echo \Illuminate\Support\Js::from($closeAction)->toHtml() ?>, coordinated: <?php echo \Illuminate\Support\Js::from($coordinationState)->toHtml() ?>
    }"
    :hidden="!windowVisible" x-cloak
    :data-floating-mode="(<?php echo e($enabledState); ?>) ? <?php echo e($modeState); ?> : 'custom'"
    :data-motion="windowMotion" :data-dragging="windowDragging.toString()" :data-floating-open="(<?php echo e($openState); ?>).toString()"
    x-on:pointermove.window="windowDragMove($event)" x-on:pointerup.window="windowDragEnd($event)"
    x-on:keydown.escape="if (windowDragging) { windowCancelDrag(); $event.preventDefault(); $event.stopPropagation(); }"
    x-on:pointercancel.window="windowCancelDrag()" x-on:blur.window="windowCancelDrag()"
    x-on:lostpointercapture.self="if (windowDragging) windowCancelDrag()"
    x-on:resize.window="resetWindow()" x-on:livewire:navigating.document="resetWindow()"
    x-on:visibilitychange.document="resetWindow()"
    <?php if(filled($label)): ?> aria-label="<?php echo e($label); ?>" <?php endif; ?>
    <?php if(filled($role)): ?> role="<?php echo e($role); ?>" <?php endif; ?>
    <?php echo e($attributes->class('rt-floating-window')); ?>

>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($header)): ?>
        <header x-on:pointerdown="windowDragStart($event)" x-on:keydown="windowDockKey($event)" :tabindex="canMoveWindow() ? 0 : -1"
            role="group" aria-label="<?php echo e($dragLabel); ?>" title="Ziehen oder mit Alt und Pfeiltasten in eine Ecke setzen"
            <?php echo e($header->attributes->class('rt-floating-window__header')); ?>>
            <?php echo e($header); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($actions)): ?>
                <span class="rt-floating-window__header-spacer" aria-hidden="true"></span>
                <div <?php echo e($actions->attributes->class('rt-floating-window__actions')); ?>><?php echo e($actions); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </header>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo e($slot); ?>

    <template x-teleport="body">
        <div class="rt-floating-dock-target" x-show="windowDragging && windowDockTarget" x-transition.opacity.duration.100ms
            :style="windowDockStyle()" aria-hidden="true" style="display:none"></div>
    </template>
</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/floating/window.blade.php ENDPATH**/ ?>