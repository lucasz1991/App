const detailError = 'Die Leistungsdetails konnten nicht geladen werden. Bitte versuche es erneut.';
const controls = 'a, button, input, select, textarea, label, [role="button"], [data-table-row-ignore]';

function serviceId(value) {
    if ((typeof value !== 'number' && typeof value !== 'string') || String(value).trim() === '') return null;
    const id = Number(value);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

function callsMatch(calls, id) {
    return Array.isArray(calls) && calls.length > 0
        && calls.every(call => call.method === 'openServiceDetails' && serviceId(call.params?.[0]) === id);
}

export function serviceDetailPanel() {
    return {
        panelVisible: false,
        loading: false,
        error: '',
        requestedServiceId: null,
        pendingServiceId: null,
        requestVersion: 0,
        inFlight: false,
        requestPromise: null,
        _destroyed: false,
        _listeners: [],
        _cancelRequest: null,
        _focusFrame: null,

        init() {
            const scope = this.$el ?? this.$root;
            const doc = scope?.ownerDocument ?? globalThis.document;
            const win = doc?.defaultView ?? globalThis.window;
            const listen = (target, name, callback, options) => {
                if (!target?.addEventListener) return;
                target.addEventListener(name, callback, options);
                this._listeners.push(() => target.removeEventListener(name, callback, options));
            };

            for (const name of ['click', 'dblclick', 'keydown']) {
                listen(scope, name, event => this.captureServiceSelection(event), true);
            }
            listen(win, 'operations-service-detail-request', event => {
                if (event.detail?.ownerId && String(event.detail.ownerId) !== String(this.$wire.$id)) return;
                this.openServiceDetail(event.detail?.id);
            });
            listen(win, 'rt-navigation:prepare', () => this.closeServiceDetail());
            listen(win, 'pagehide', () => this.closeServiceDetail());
            listen(doc, 'livewire:navigating', () => this.closeServiceDetail());
            this.$watch('panelVisible', visible => {
                if (!visible) this.closeServiceDetail();
            });

            // A server-rendered selection needs no second request. The native
            // panel is already mounted; its content may finish Alpine setup next tick.
            const id = serviceId(this.$wire.selectedServiceId);
            if (id) {
                this.requestedServiceId = id;
                this.panelVisible = true;
                this.loading = !this.hasRenderedDetails(id);
                if (this.loading) {
                    const version = this.requestVersion;
                    this.afterMorph().then(async () => {
                        if (!this.isCurrent(version)) return;
                        this.loading = false;
                        if (!this.hasRenderedDetails(id)) {
                            this.error = detailError;
                            return;
                        }
                        await this.focusLoadedDetails(id, version);
                    });
                }
            }
        },

        captureServiceSelection(event) {
            const isSpace = event.type === 'keydown' && event.key === ' ';
            if (this._destroyed || (event.type === 'keydown' && (event.repeat || (event.key !== 'Enter' && !isSpace)))) return;
            const scope = this.$el ?? this.$root;
            const target = event.target?.nodeType === 1 ? event.target : event.target?.parentElement;
            if (!target?.closest || !scope?.contains(target)) return;

            // Space belongs to the row only when its shared table explicitly
            // opts into single-click details. Native buttons and previews keep
            // their own Space activation, rather than being captured here.
            if (isSpace && (target.closest(controls) || target.closest('[wire\\:click]')
                || target.closest('[data-table-row-click]')?.getAttribute('data-table-row-click') !== 'details')) return;

            const action = target.closest('[wire\\:click]');
            const actionMatch = action?.getAttribute('wire:click')?.match(/^\s*openServiceDetails\(\s*(\d+)\s*\)\s*$/);
            let id = null;
            if (actionMatch && scope.contains(action) && !action.disabled) {
                id = serviceId(actionMatch[1]);
            } else {
                if (target.closest(controls)) return;
                const row = target.closest('[wire\\:key]');
                const match = row?.getAttribute('wire:key')?.match(/^service-overview-row-(\d+)$/);
                if (match && scope.contains(row)) id = serviceId(match[1]);
            }
            if (!id) return;

            // Run before the shared table's delayed selection or wire:click,
            // otherwise two owners would send the same detail request.
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            this.openServiceDetail(id);
        },

        openServiceDetail(rawId) {
            const id = serviceId(rawId);
            if (!id || this._destroyed) return;
            if (this.panelVisible && this.requestedServiceId === id && !this.error) return this.requestPromise;

            this.requestVersion += 1;
            this.cancelFocusFrame();
            this.requestedServiceId = id;
            this.pendingServiceId = id;
            this.error = '';
            this.loading = true;
            this.panelVisible = true;

            if (!this.inFlight) this.requestPromise = this.loadPendingDetails();
            return this.requestPromise;
        },

        closeServiceDetail() {
            if (this.panelVisible || this.loading || this.pendingServiceId !== null || this.error) this.requestVersion += 1;
            this.panelVisible = false;
            this.cancelFocusFrame();
            this.pendingServiceId = null;
            this.loading = false;
            this.error = '';
            // selectedServiceId and every business state remain server-owned.
        },

        retryServiceDetail() {
            if (this.error && this.requestedServiceId) return this.openServiceDetail(this.requestedServiceId);
        },

        isCurrent(version) {
            return !this._destroyed && this.panelVisible && version === this.requestVersion;
        },

        hasRenderedDetails(id) {
            return Boolean(this.renderedDetails(id));
        },

        renderedDetails(id) {
            if (serviceId(this.$wire.selectedServiceId) !== id) return null;
            const scope = this.$el ?? this.$root;
            const marker = `[data-service-detail-loaded="${id}"]`;
            const local = scope?.querySelector(marker);
            if (local) return local;
            const doc = scope?.ownerDocument ?? globalThis.document;
            const host = Array.from(doc?.querySelectorAll('[data-service-detail-panel-owner]') ?? [])
                .find(host => host.getAttribute('data-service-detail-panel-owner') === String(this.$wire.$id)
                    && host.querySelector(marker));
            return host?.querySelector(marker) ?? null;
        },

        async focusLoadedDetails(id, version) {
            // The loading header becomes hidden after the ready content is
            // revealed. Transfer its focus only after Alpine applies x-show.
            await this.afterMorph();
            if (!this.isCurrent(version) || this.loading || this.error) return;
            // Alpine's x-show schedules its actual display change in a frame,
            // while nextTick alone can finish before that queued frame runs.
            await this.afterVisibilityFrame();
            if (!this.isCurrent(version) || this.loading || this.error) return;
            const detail = this.renderedDetails(id);
            if (!detail || detail.contains(detail.ownerDocument?.activeElement)) return;
            const close = detail.querySelector('[data-dialog-close]');
            if (close?.isConnected && !close.disabled && close.getClientRects().length > 0) close.focus({ preventScroll: true });
        },

        afterVisibilityFrame() {
            const scope = this.$el ?? this.$root;
            const win = scope?.ownerDocument?.defaultView ?? globalThis.window;
            if (typeof win?.requestAnimationFrame !== 'function') return Promise.resolve();
            return new Promise(resolve => {
                let frameId;
                this._focusFrame = { resolve, cancel: () => win.cancelAnimationFrame?.(frameId) };
                frameId = win.requestAnimationFrame(() => {
                    if (this._focusFrame?.resolve === resolve) this._focusFrame = null;
                    resolve();
                });
            });
        },

        cancelFocusFrame() {
            const frame = this._focusFrame;
            this._focusFrame = null;
            frame?.cancel();
            frame?.resolve();
        },

        afterMorph() {
            return new Promise(resolve => this.$nextTick(resolve));
        },

        async loadPendingDetails() {
            this.inFlight = true;
            try {
                // Coalesce queued clicks, and serialize the authoritative calls:
                // an older response must never morph over a newer selection.
                while (!this._destroyed && this.panelVisible && this.pendingServiceId !== null) {
                    const id = this.pendingServiceId;
                    const version = this.requestVersion;
                    this.pendingServiceId = null;
                    try {
                        await this.requestServiceDetails(id);
                        await this.afterMorph();
                        if (!this.isCurrent(version)) continue;
                        if (!this.hasRenderedDetails(id)) throw new Error('Service detail response did not render the requested selection');
                        this.loading = false;
                        await this.focusLoadedDetails(id, version);
                    } catch {
                        if (this.isCurrent(version)) {
                            this.loading = false;
                            this.error = detailError;
                        }
                    }
                }
            } finally {
                this.inFlight = false;
            }
        },

        requestServiceDetails(id) {
            const cleanups = [];
            let cancel;
            return new Promise((resolve, reject) => {
                cancel = () => reject(new Error('Service detail controller destroyed'));
                this._cancelRequest = cancel;
                // Livewire 3 method promises resolve only on success. Match the
                // component commit so HTTP failures also settle our loader.
                if (typeof this.$wire.$hook === 'function') {
                    cleanups.push(this.$wire.$hook('commit', ({ commit, fail }) => {
                        if (commit?.calls?.some(call => call.method === 'openServiceDetails' && serviceId(call.params?.[0]) === id)) {
                            fail(() => reject(new Error('Service detail request failed')));
                        }
                    }));
                    cleanups.push(this.$wire.$hook('request', ({ payload, fail }) => {
                        let components;
                        try {
                            components = (typeof payload === 'string' ? JSON.parse(payload) : payload)?.components;
                            if (!Array.isArray(components) || components.length !== 1) return;
                            const snapshot = typeof components[0].snapshot === 'string'
                                ? JSON.parse(components[0].snapshot) : components[0].snapshot;
                            if (snapshot?.memo?.id !== this.$wire.$id || !callsMatch(components[0].calls, id)) return;
                        } catch { return; }
                        fail(({ status, preventDefault }) => {
                            if (status !== 419) preventDefault();
                        });
                    }));
                }
                Promise.resolve(this.$wire.openServiceDetails(id)).then(resolve, reject);
            }).finally(() => {
                cleanups.forEach(cleanup => { if (typeof cleanup === 'function') cleanup(); });
                if (this._cancelRequest === cancel) this._cancelRequest = null;
            });
        },

        destroy() {
            if (this._destroyed) return;
            this.closeServiceDetail();
            this._destroyed = true;
            this._listeners.splice(0).forEach(remove => remove());
            this._cancelRequest?.();
        },
    };
}
