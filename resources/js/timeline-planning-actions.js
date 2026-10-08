// One controller per timeline, not one Alpine component or request per day cell.
export function timelinePlanning(ownerId = null) {
    return {
        plannerVisible: false,
        plannerLoading: false,
        plannerReady: false,
        plannerError: '',
        suggestionsLoading: false,
        suggestionsError: '',
        get suggestionsEnabled() {
            return this.suggestionsWire()?.showSuggestions === true;
        },
        suggestionsWire() {
            // The control is teleported into another Livewire component's header.
            // Resolve its own timeline explicitly instead of the destination's $wire.
            return ownerId ? globalThis.Livewire?.find?.(ownerId) : this.$wire;
        },
        requestVersion: 0,
        pending: null,
        inFlight: false,
        hoverTimer: null,
        hoverAnchor: null,
        hoverCache: new Map(),
        disposed: false,
        proposalObserver: null,
        proposalMutation: null,
        observedProposals: new Set(),
        visibilityListener: null,

        init() {
            this.$watch('$wire.assignmentOpen', (open) => {
                if (!open && !this.plannerLoading && this.plannerVisible) this.closePlanner();
            });
            this.$nextTick(() => {
                if (this.disposed || typeof IntersectionObserver !== 'function') return;
                this.proposalObserver = new IntersectionObserver((entries) => {
                    for (const entry of entries) entry.target.dataset.inView = String(entry.isIntersecting);
                }, { root: this.$root.querySelector('.rt-personnel-timeline-body') });
                this.observeProposals();
                this.proposalMutation = new MutationObserver(() => this.observeProposals());
                this.proposalMutation.observe(this.$root, { childList: true, subtree: true });
                this.visibilityListener = () => { this.$root.dataset.motionPaused = String(document.hidden); };
                document.addEventListener('visibilitychange', this.visibilityListener);
                this.visibilityListener();
            });
        },
        observeProposals() {
            for (const node of this.observedProposals) {
                if (!node.isConnected) { this.proposalObserver.unobserve(node); this.observedProposals.delete(node); }
            }
            for (const node of this.$root.querySelectorAll('[data-timeline-proposal]')) {
                if (!this.observedProposals.has(node)) { this.observedProposals.add(node); this.proposalObserver.observe(node); }
            }
        },
        destroy() {
            this.disposed = true;
            this.pending = null;
            this.clearHover();
            this.proposalObserver?.disconnect();
            this.proposalMutation?.disconnect();
            document.removeEventListener('visibilitychange', this.visibilityListener);
        },
        panelId() { return `rt-dropdown-timeline-planner-${this.$wire.$id}`; },
        async changeSuggestions(event) {
            if (this.disposed) return;
            // A checkbox changes before its change event. Keep the confirmed state
            // visible until the matching Livewire render, including failed requests.
            const input = event?.target;
            const wire = this.suggestionsWire();
            if (input) input.checked = wire?.showSuggestions === true;
            if (this.suggestionsLoading) return;
            this.suggestionsLoading = true;
            this.suggestionsError = '';
            try {
                if (!wire) throw new Error('Timeline component unavailable');
                await this.request('toggleSuggestions', [], wire);
                await new Promise((resolve) => this.$nextTick(resolve));
            } catch {
                if (!this.disposed) this.suggestionsError = 'Vorschläge konnten nicht aktualisiert werden. Bitte erneut schalten.';
            } finally {
                if (!this.disposed) {
                    this.suggestionsLoading = false;
                    if (input?.isConnected) input.checked = wire?.showSuggestions === true;
                }
            }
        },
        hoverCell(event) {
            const anchor = event.target.closest('[data-timeline-cell-action]');
            if (!anchor || event.pointerType === 'touch' || this.plannerVisible) return;
            if (anchor === this.hoverAnchor) return;
            this.clearHover();
            this.hoverAnchor = anchor;
            this.hoverTimer = setTimeout(() => {
                if (!anchor.isConnected || this.hoverAnchor !== anchor) return;
                const key = `${anchor.dataset.user}:${anchor.dataset.date}`;
                const cached = this.hoverCache.get(key);
                if (cached && Date.now() - cached.at < 15000) return this.paintHover(anchor, cached.value);
                this.paintHover(anchor, { state: 'checking', label: 'Eignung prüfen …', detail: '' });
                this.pending = { kind: 'hover', anchor, key, user: Number(anchor.dataset.user), date: anchor.dataset.date };
                void this.drain();
            }, 280);
        },
        leaveCell(event) {
            if (this.hoverAnchor && !this.hoverAnchor.contains(event.relatedTarget)) this.clearHover();
        },
        clearHover() {
            clearTimeout(this.hoverTimer);
            if (this.hoverAnchor) {
                delete this.hoverAnchor.dataset.fit;
                delete this.hoverAnchor.dataset.fitLabel;
                this.hoverAnchor.removeAttribute('title');
                const statusText = this.hoverAnchor.querySelector?.('[data-timeline-cell-status-text]');
                if (statusText) statusText.textContent = '';
            }
            this.hoverAnchor = null;
            if (this.pending?.kind === 'hover') this.pending = null;
        },
        paintHover(anchor, value) {
            if (anchor !== this.hoverAnchor || this.disposed) return;
            anchor.dataset.fit = value.state;
            anchor.dataset.fitLabel = value.label;
            const detail = [value.label, value.detail].filter(Boolean).join(' · ');
            anchor.title = detail;
            const statusText = anchor.querySelector?.('[data-timeline-cell-status-text]');
            if (statusText) statusText.textContent = detail;
            const statusIcon = anchor.querySelector?.('[data-timeline-cell-status-icon]');
            if (statusIcon) {
                const icons = { checking: 'fa-spinner-third', suitable: 'fa-check-circle', blocked: 'fa-ban', empty: 'fa-minus-circle', error: 'fa-exclamation-circle' };
                statusIcon.className = `far ${icons[value.state] || 'fa-question-circle'}`;
            }
        },
        openPlanner(event) {
            const anchor = event.target.closest('[data-timeline-cell-action], [data-timeline-proposal]');
            if (!anchor) return;
            this.clearHover();
            this.plannerVisible = true;
            this.plannerLoading = true;
            this.plannerReady = false;
            this.plannerError = '';
            const version = ++this.requestVersion;
            this.pending = { kind: 'open', anchor, version, user: Number(anchor.dataset.user), date: anchor.dataset.date,
                shift: Number(anchor.dataset.shift), revision: Number(anchor.dataset.revision), focus: anchor.dataset.focus === 'true' };
            this.$dispatch('rt-anchor-dropdown-open', { id: this.panelId(), anchor });
            void this.drain();
        },
        closePlanner() {
            this.plannerVisible = false;
            this.plannerLoading = false;
            this.plannerReady = false;
            this.requestVersion++;
            this.pending = null;
            this.$wire.assignmentOpen = false;
            this.$dispatch('rt-anchor-dropdown-close', { id: this.panelId() });
        },
        invalidate() {
            this.hoverCache.clear();
            this.clearHover();
            this.closePlanner();
        },
        async drain() {
            if (this.inFlight) return;
            this.inFlight = true;
            try {
                while (this.pending && !this.disposed) {
                    const job = this.pending;
                    this.pending = null;
                    try {
                        if (job.kind === 'hover') {
                            const value = await this.request('previewCell', [job.user, job.date]);
                            if (this.hoverCache.size >= 40) this.hoverCache.delete(this.hoverCache.keys().next().value);
                            this.hoverCache.set(job.key, { at: Date.now(), value });
                            this.paintHover(job.anchor, value);
                        } else {
                            await this.request(job.focus ? 'openFocusCandidate' : (job.shift ? 'openSuggestion' : 'openCell'), job.shift ? [job.shift, job.user, job.revision] : [job.user, job.date]);
                            // Livewire resolves method returns before its queued DOM morph.
                            // Keep old/empty results hidden until the new markup is applied.
                            await new Promise((resolve) => this.$nextTick(resolve));
                            if (job.version === this.requestVersion && this.plannerVisible && !this.disposed) {
                                this.plannerReady = true;
                                this.plannerLoading = false;
                                this.$nextTick(() => this.$dispatch('rt-anchor-dropdown-focus', { id: this.panelId() }));
                            }
                        }
                    } catch {
                        if (job.kind === 'hover') this.paintHover(job.anchor, { state: 'error', label: 'Prüfung nicht verfügbar', detail: 'Per Klick erneut versuchen.' });
                        else if (job.version === this.requestVersion && this.plannerVisible) {
                            this.plannerLoading = false;
                            this.plannerError = 'Die Auswahl konnte nicht geladen werden. Bitte schließen und erneut versuchen.';
                        }
                    }
                }
            } finally {
                this.inFlight = false;
                if (!this.plannerVisible && !this.disposed) this.$wire.assignmentOpen = false;
            }
        },
        request(method, args, wire = this.$wire) {
            const cleanups = [];
            return new Promise((resolve, reject) => {
                // Livewire 3 method promises alone do not reject failed HTTP commits.
                if (typeof wire.$hook === 'function') {
                    cleanups.push(wire.$hook('commit', ({ commit, fail }) => {
                        if (commit.calls.some((call) => call.method === method)) fail(() => reject(new Error('Timeline request failed')));
                    }));
                }
                Promise.resolve(wire[method](...args)).then(resolve, reject);
            }).finally(() => cleanups.forEach((cleanup) => cleanup()));
        },
    };
}
