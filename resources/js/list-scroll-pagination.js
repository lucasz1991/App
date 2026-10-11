// One deliberate extra edge gesture, not the tail of arriving at the edge.
// Keep this controller pagination-only: the Livewire owner still runs queries.
export function createScrollStepGate() {
    let direction = 0;
    let steps = 0;
    let edgeDistance = 0;
    let blockedBurst = false;
    let lastEvent = -Infinity;
    let quietUntil = -Infinity;
    return {
        count() { return steps; },
        // A morph/cancel must not turn an arriving burst's inertia into a new
        // gesture. Keep its clock; only a real rest starts another wheel burst.
        reset() { direction = 0; steps = 0; edgeDistance = 0; blockedBurst = true; },
        coolDown(now) { this.reset(); quietUntil = now + 700; },
        observe(delta, now, eligible, discrete = false) {
            const previous = lastEvent;
            lastEvent = now;
            const nextDirection = Math.sign(delta);
            if (!eligible || !Number.isFinite(delta) || !nextDirection) {
                direction = 0; steps = 0; edgeDistance = 0; blockedBurst = true;
                return false;
            }
            if (now < quietUntil) { blockedBurst = true; edgeDistance = 0; return false; }
            if (discrete) {
                if (Math.abs(delta) < 64) return false;
            } else {
                if (now - previous >= 180) { edgeDistance = 0; blockedBurst = false; }
                if (blockedBurst) return false;
                if (nextDirection !== direction) { direction = nextDirection; edgeDistance = 0; }
                // A strong mouse impulse is immediate. Fine trackpad packets
                // can form one clear gesture, but never accumulate across rests
                // or out of the burst which merely scrolled to the boundary.
                edgeDistance += Math.abs(delta);
                if (Math.abs(delta) < 40 && edgeDistance < 100) return false;
            }
            steps = 1;
            this.coolDown(now);
            return true;
        },
    };
}

export function normalizedWheelDelta(event, height = 800) {
    if (event.ctrlKey || event.metaKey || event.shiftKey
        || Math.abs(event.deltaX || 0) >= Math.abs(event.deltaY || 0)) return 0;
    const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? height : 1;
    return Number(event.deltaY || 0) * unit;
}

export function listBoundary(direction, body, port, documentPort = false, viewportTop = 0, viewportBottom = 0) {
    if (documentPort) {
        const rect = body.getBoundingClientRect();
        return direction > 0 ? rect.bottom <= viewportBottom + 2 : rect.top >= viewportTop - 2;
    }
    const max = Math.max(0, port.scrollHeight - port.clientHeight);
    const rect = body.getBoundingClientRect();
    const view = port.getBoundingClientRect();
    return direction > 0
        ? port.scrollTop >= max - 2 && rect.bottom <= view.bottom + 2
        : port.scrollTop <= 2 && rect.top >= view.top - 2;
}

export function listScrollPagination() {
    return {
        gate: null,
        pending: false,
        loadingDirection: 1,
        loadingStyle: '',
        destroyed: false,
        signature: '',
        touch: null,
        wheelListener: null,
        startListener: null,
        endListener: null,
        cancelListener: null,
        resetListener: null,
        observer: null,
        positionListener: null,
        init() {
            this.gate = createScrollStepGate();
            this.wheelListener = event => this.handleWheel(event);
            this.startListener = event => this.beginTouch(event);
            this.endListener = event => this.endTouch(event);
            this.cancelListener = () => { this.touch = null; this.gate.reset(); };
            this.resetListener = () => { this.touch = null; this.gate.reset(); };
            this.$el.addEventListener('wheel', this.wheelListener, { passive: false });
            this.$el.addEventListener('touchstart', this.startListener, { passive: true });
            this.$el.addEventListener('touchend', this.endListener, { passive: true });
            this.$el.addEventListener('touchcancel', this.cancelListener, { passive: true });
            document.addEventListener('livewire:navigating', this.resetListener);
            this.positionListener = () => { if (this.pending) this.updateLoadingPosition(); };
            window.addEventListener('resize', this.positionListener);
            window.addEventListener('scroll', this.positionListener, true);
            this.observer = new MutationObserver(() => { this.touch = null; this.gate.reset(); });
            this.observer.observe(this.$el, { childList: true, subtree: true, characterData: true,
                attributes: true, attributeFilter: ['data-list-page', 'data-list-page-name',
                    'data-list-has-next', 'data-list-has-previous', 'data-list-pagination-busy'] });
        },
        destroy() {
            this.destroyed = true;
            this.$el.removeEventListener('wheel', this.wheelListener);
            this.$el.removeEventListener('touchstart', this.startListener);
            this.$el.removeEventListener('touchend', this.endListener);
            this.$el.removeEventListener('touchcancel', this.cancelListener);
            document.removeEventListener('livewire:navigating', this.resetListener);
            window.removeEventListener('resize', this.positionListener);
            window.removeEventListener('scroll', this.positionListener, true);
            this.observer?.disconnect();
            this.gate?.reset();
            this.touch = null;
        },
        context(target) {
            const body = this.$el.querySelector('.rt-table-body');
            if (!body || !(target instanceof Element) || !body.contains(target)
                || target.closest('input, textarea, select, [contenteditable], [role=dialog], [data-rt-dropdown-root]')) return null;
            // Do not hijack nested scrolling cards/previews or nested tables.
            if (target.closest('[data-list-scroll-pagination]') !== this.$el) return null;
            for (let node = target; node && node !== body; node = node.parentElement) {
                if (/auto|scroll/.test(getComputedStyle(node).overflowY) && node.scrollHeight > node.clientHeight + 2) return null;
            }
            for (let node = body; node && node !== document.body; node = node.parentElement) {
                if (/auto|scroll/.test(getComputedStyle(node).overflowY)) return { body, port: node, documentPort: false };
            }
            return { body, port: document.scrollingElement, documentPort: true };
        },
        metadata() {
            const data = this.$el.dataset;
            const page = Number(data.listPage);
            const nextSignature = `${data.listPageName}:${page}`;
            if (nextSignature !== this.signature) {
                this.gate.reset();
                this.signature = nextSignature;
            }
            return { page, name: data.listPageName, next: data.listHasNext === 'true', previous: data.listHasPrevious === 'true' };
        },
        atBoundary(direction, context) {
            const top = document.querySelector('.rt-shell-topbar')?.getBoundingClientRect().bottom || 0;
            return listBoundary(direction, context.body, context.port, context.documentPort, top, window.innerHeight);
        },
        handleWheel(event) {
            const context = this.context(event.target);
            const delta = normalizedWheelDelta(event, context?.port.clientHeight);
            if (!context || !delta) { this.gate.reset(); return; }
            const meta = this.metadata();
            const direction = Math.sign(delta);
            const eligible = !this.pending && !this.$el.hasAttribute('data-list-pagination-busy')
                && (direction > 0 ? meta.next : meta.previous) && this.atBoundary(direction, context);
            // Preserve ordinary scrolling, zoom, horizontal scroll and outer
            // page scroll on the first/last page. Only contain edge gestures.
            if (eligible && event.cancelable) event.preventDefault();
            const complete = this.gate.observe(delta, performance.now(), eligible);
            this.$el.dataset.listScrollSteps = String(complete ? 1 : this.gate.count());
            if (complete) void this.changePage(direction, meta);
        },
        beginTouch(event) {
            this.touch = null;
            if (event.touches.length !== 1 || !this.context(event.target)) { this.gate.reset(); return; }
            const point = event.touches[0];
            const context = this.context(event.target);
            this.touch = { x: point.clientX, y: point.clientY, target: event.target,
                up: this.atBoundary(-1, context), down: this.atBoundary(1, context) };
        },
        endTouch(event) {
            const start = this.touch;
            this.touch = null;
            if (!start || event.touches.length || event.changedTouches.length !== 1) return;
            const point = event.changedTouches[0];
            const delta = start.y - point.clientY;
            const context = this.context(start.target);
            const meta = this.metadata();
            const direction = Math.sign(delta);
            const eligible = Boolean(context) && Math.abs(delta) > Math.abs(start.x - point.clientX) * 1.5
                && !this.pending && !this.$el.hasAttribute('data-list-pagination-busy')
                && (direction > 0 ? meta.next && start.down : meta.previous && start.up)
                && this.atBoundary(direction, context);
            const complete = this.gate.observe(delta, performance.now(), eligible, true);
            this.$el.dataset.listScrollSteps = String(complete ? 1 : this.gate.count());
            if (complete) void this.changePage(direction, meta);
        },
        updateLoadingPosition() {
            const body = this.$el.querySelector('.rt-table-body');
            const context = this.context(body);
            if (!context) return;
            const rect = body.getBoundingClientRect();
            const view = context.documentPort ? { top: 0, bottom: window.innerHeight } : context.port.getBoundingClientRect();
            const header = document.querySelector('.rt-shell-topbar')?.getBoundingClientRect().bottom || 0;
            const top = Math.max(rect.top, view.top, header);
            const bottom = Math.min(rect.bottom, view.bottom, window.innerHeight);
            const left = Math.max(12, rect.left);
            const right = Math.min(window.innerWidth - 12, rect.right);
            const y = bottom - top < 48 ? (top + bottom) / 2 : this.loadingDirection > 0 ? bottom - 24 : top + 24;
            this.loadingStyle = `left:${(left + right) / 2}px;top:${y}px;max-width:${Math.max(0, Math.min(320, right - left - 16))}px`;
        },
        async changePage(direction, meta) {
            if (this.pending || !this.$el.closest('[wire\\:id]') || !Number.isInteger(meta.page) || !meta.name) return;
            this.loadingDirection = direction;
            if (typeof window !== 'undefined') this.updateLoadingPosition();
            this.pending = true;
            const page = meta.page + direction;
            try {
                await this.$wire.call('gotoPage', page, meta.name);
                await this.$nextTick();
                if (this.destroyed || !this.$el.isConnected || Number(this.$el.dataset.listPage) !== page) return;
                const context = this.context(this.$el.querySelector('.rt-table-body'));
                if (!context) return;
                if (context.documentPort) {
                    const rect = context.body.getBoundingClientRect();
                    const top = document.querySelector('.rt-shell-topbar')?.getBoundingClientRect().bottom || 0;
                    const position = context.port.scrollTop + (direction > 0 ? rect.top - top : rect.bottom - window.innerHeight);
                    window.scrollTo({ top: Math.max(0, position), behavior: 'instant' });
                } else {
                    context.port.scrollTo({ top: direction > 0 ? 0 : context.port.scrollHeight, behavior: 'instant' });
                }
            } catch {
                // Native Livewire transport reports failures. Never retry or
                // reposition a failed/stale page change behind the user's back.
            } finally {
                this.pending = false;
                this.gate.coolDown(performance.now());
                this.$el.dataset.listScrollSteps = '0';
            }
        },
    };
}
