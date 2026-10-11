// Presentation only. Native Livewire/Alpine tabs own selection, focus and loading.
// Keep GSAP and DOM references outside Alpine's reactive proxy.
const instances = new WeakMap();

const interactiveSelector = 'a, button, input, select, textarea, iframe, object, embed, [contenteditable]';
const textProperties = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'direction'];

function computedProperties(element, properties) {
    const computed = window.getComputedStyle?.(element);
    return computed ? Object.fromEntries(properties.map(property => [property, computed[property]])) : {};
}

function visualClone(child) {
    if (!child.matches('i, svg, span') || child.classList.contains('sr-only')) return null;
    const clone = child.cloneNode(true);
    clone.querySelectorAll(interactiveSelector).forEach(element => element.remove());
    [clone, ...clone.querySelectorAll('*')].forEach(element => {
        [...element.attributes].forEach(attribute => {
            if (/^(?:x-|wire:|@|on|aria-)/i.test(attribute.name)
                || ['id', 'name', 'tabindex', 'contenteditable'].includes(attribute.name)) {
                element.removeAttribute(attribute.name);
            }
        });
    });
    // Icon font selectors may be scoped to native buttons. Retain those metrics
    // in the inert copy, but never copy color (the red clip owns white ink).
    Object.assign(clone.style, computedProperties(child, textProperties));
    return clone;
}

function createInstance(marker, variant) {
    const menu = marker.parentElement;
    const gsap = window.gsap;
    if (!menu || typeof gsap?.to !== 'function' || typeof MutationObserver !== 'function') return null;

    const optionSelector = variant === 'main' ? '[data-profile-group]' : '[data-panel-tab]';
    const activeAttribute = variant === 'main' ? 'aria-current' : 'aria-selected';
    const activeValue = variant === 'main' ? 'page' : 'true';
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const state = { x: 0, y: 0, width: 1, height: 1 };
    let baseWidth = 1;
    let baseHeight = 1;
    let frame = null;
    let immediateFrame = false;
    let tween = null;
    let revision = 0;
    let destroyed = false;
    let ready = false;
    let roster = '';
    let inkSignature = '';
    let destination = null;
    let ink = null;
    let observedButtons = [];

    if (variant === 'sub') {
        ink = marker.ownerDocument.createElement('span');
        ink.className = 'employee-profile__selection-ink';
        ink.setAttribute('aria-hidden', 'true');
        marker.replaceChildren(ink);
    }

    function stopTween() {
        revision++;
        tween?.kill();
        tween = null;
    }

    function render() {
        if (destroyed) return;
        const width = Math.max(1, state.width);
        const height = Math.max(1, state.height);
        marker.style.transform = `translate(${state.x}px, ${state.y}px) scale(${width / baseWidth}, ${height / baseHeight})`;
        if (ink) {
            // The red surface moves; white copies stay in menu coordinates.
            // Inverse scale keeps the original label and icon size unchanged.
            ink.style.transform = `scale(${baseWidth / width}, ${baseHeight / height}) translate(${-state.x}px, ${-state.y}px)`;
        }
    }

    function hide() {
        stopTween();
        ready = false;
        destination = null;
        marker.style.opacity = '0';
        menu.removeAttribute('data-profile-motion-ready');
    }

    function visible(button) {
        if (!button || button.isConnected === false) return false;
        const bounds = button.getBoundingClientRect();
        return bounds.width > 0 && bounds.height > 0;
    }

    function optionBounds(button, parent) {
        const bounds = button.getBoundingClientRect();
        return {
            x: bounds.left - parent.left - (menu.clientLeft || 0) + (menu.scrollLeft || 0),
            y: bounds.top - parent.top - (menu.clientTop || 0) + (menu.scrollTop || 0),
            width: bounds.width,
            height: bounds.height,
        };
    }

    function updateInk(buttons, parent) {
        if (!ink) return;
        const visuals = buttons.map(button => [...button.children].map(visualClone).filter(Boolean));
        const signature = visuals.map(children => children.map(child => child.outerHTML).join('')).join('\n');
        if (signature !== inkSignature || ink.children.length !== buttons.length) {
            inkSignature = signature;
            ink.replaceChildren(...visuals.map(children => {
                const item = marker.ownerDocument.createElement('span');
                item.className = 'employee-profile__selection-ink-item';
                item.setAttribute('aria-hidden', 'true');
                item.append(...children);
                return item;
            }));
        }
        buttons.forEach((button, index) => {
            const bounds = optionBounds(button, parent);
            Object.assign(ink.children[index].style, {
                ...computedProperties(button, [
                    ...textProperties, 'textAlign', 'columnGap', 'rowGap',
                    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
                ]),
                left: bounds.x + 'px', top: bounds.y + 'px',
                width: bounds.width + 'px', height: bounds.height + 'px',
            });
        });
    }

    const resizeObserver = typeof ResizeObserver === 'function'
        ? new ResizeObserver(() => schedule(true)) : null;

    function refresh(immediate) {
        if (destroyed || !marker.isConnected || marker.parentElement !== menu) return;
        const options = [...menu.querySelectorAll(optionSelector)]
            .filter(button => !marker.contains(button));
        const buttons = options.filter(visible);
        const active = buttons.find(button => button.getAttribute(activeAttribute) === activeValue);
        const nextRoster = options.map(button => button.getAttribute(optionSelector.slice(1, -1)) || button.id).join('\n');
        const rosterChanged = nextRoster !== roster;
        roster = nextRoster;
        if (resizeObserver && (options.length !== observedButtons.length
            || options.some((button, index) => button !== observedButtons[index]))) {
            resizeObserver.disconnect();
            resizeObserver.observe(menu);
            options.forEach(button => resizeObserver.observe(button));
            observedButtons = options;
        }
        if (!active) { hide(); return; }

        const parent = menu.getBoundingClientRect();
        const bounds = optionBounds(active, parent);
        const next = variant === 'main' ? {
            x: bounds.x + 12, y: bounds.y + bounds.height - 2,
            width: Math.max(1, bounds.width - 24), height: 2,
        } : bounds;
        updateInk(buttons, parent);
        const changed = !destination || Object.keys(next).some(key => Math.abs(next[key] - destination[key]) > 0.1);
        if (!changed && ready && !immediate) {
            menu.setAttribute('data-profile-motion-ready', '');
            return;
        }

        stopTween();
        const shouldAnimate = ready && changed && !immediate && !rosterChanged && !reduced?.matches;
        destination = next;
        // Set a real rounded-rectangle base only at selection/layout boundaries.
        // Animation frames change transforms, never CSS width or height.
        baseWidth = next.width;
        baseHeight = next.height;
        marker.style.width = baseWidth + 'px';
        marker.style.height = baseHeight + 'px';
        marker.style.opacity = '1';
        menu.setAttribute('data-profile-motion-ready', '');
        if (!shouldAnimate) {
            Object.assign(state, next);
            render();
            ready = true;
            return;
        }

        render();
        const ownRevision = revision;
        tween = gsap.to(state, {
            ...next, duration: 0.24, ease: 'power3.out', overwrite: true,
            onUpdate: () => { if (!destroyed && revision === ownRevision) render(); },
            onComplete: () => { if (!destroyed && revision === ownRevision) tween = null; },
        });
    }

    function schedule(immediate = false) {
        if (destroyed) return;
        immediateFrame ||= immediate;
        if (frame !== null) return;
        frame = requestAnimationFrame(() => {
            frame = null;
            const instant = immediateFrame;
            immediateFrame = false;
            refresh(instant);
        });
    }

    const observer = new MutationObserver(records => {
        // Ignore generated ink and our own ready commit to avoid feedback loops.
        const relevant = records.some(record => {
            if (record.target === marker || marker.contains(record.target)) return false;
            if (record.attributeName === 'data-profile-motion-ready') {
                return ready && !menu.hasAttribute('data-profile-motion-ready');
            }
            return true;
        });
        if (relevant) schedule();
    });
    observer.observe(menu, {
        subtree: true, childList: true, characterData: true, attributes: true,
        attributeFilter: ['aria-current', 'aria-selected', 'disabled', 'hidden', 'class', 'data-profile-motion-ready'],
    });
    const onGeometry = () => schedule(true);
    const onReduced = () => schedule(true);
    menu.addEventListener('scroll', onGeometry, { passive: true });
    window.addEventListener('resize', onGeometry);
    if (reduced?.addEventListener) reduced.addEventListener('change', onReduced);
    else reduced?.addListener?.(onReduced);
    schedule(true);

    return {
        destroy() {
            if (destroyed) return;
            destroyed = true;
            stopTween();
            if (frame !== null) cancelAnimationFrame(frame);
            frame = null;
            observer.disconnect();
            resizeObserver?.disconnect();
            menu.removeEventListener('scroll', onGeometry);
            window.removeEventListener('resize', onGeometry);
            if (reduced?.removeEventListener) reduced.removeEventListener('change', onReduced);
            else reduced?.removeListener?.(onReduced);
            menu.removeAttribute('data-profile-motion-ready');
            ['width', 'height', 'transform', 'opacity'].forEach(property => marker.style.removeProperty(property));
            ink?.remove();
        },
    };
}

export function profileTabMotion({ variant = 'sub' } = {}) {
    return {
        init() {
            instances.get(this.$el)?.destroy();
            const instance = createInstance(this.$el, variant === 'main' ? 'main' : 'sub');
            if (instance) instances.set(this.$el, instance);
        },
        destroy() {
            instances.get(this.$el)?.destroy();
            instances.delete(this.$el);
        },
    };
}
