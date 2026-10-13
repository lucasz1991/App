// Position only the readable time label; the true-duration segments stay unchanged.
export function timelineFocusPreviewPosition({ overlayLeft, overlayWidth, cellLeft, cellRight, viewportLeft, viewportRight, startPercent, widthPercent, textWidth }) {
    const values = [overlayLeft, overlayWidth, cellLeft, cellRight, viewportLeft, viewportRight, startPercent, widthPercent, textWidth];
    if (!values.every(Number.isFinite) || overlayWidth <= 0 || cellRight <= cellLeft || viewportRight <= viewportLeft
        || startPercent < 0 || widthPercent <= 0 || startPercent + widthPercent > 100.001 || textWidth < 0) return null;
    const left = Math.max(viewportLeft, overlayLeft), right = Math.min(viewportRight, overlayLeft + overlayWidth);
    const cellStart = Math.max(left, cellLeft), cellEnd = Math.min(right, cellRight);
    if (cellEnd <= cellStart) return null;
    const minimum = Math.max(110, textWidth + 16);
    const useCell = cellEnd - cellStart >= minimum;
    const areaLeft = useCell ? cellStart : left, areaRight = useCell ? cellEnd : right;
    const badgeWidth = Math.min(areaRight - areaLeft, Math.max(minimum, overlayWidth * widthPercent / 100));
    const center = overlayLeft + (startPercent + widthPercent / 2) * overlayWidth / 100;
    const clampedCenter = Math.max(areaLeft + badgeWidth / 2, Math.min(areaRight - badgeWidth / 2, center));
    return { offset: clampedCenter - overlayLeft, badgeWidth };
}

// Tailwind's scale(2) doubles a long badge sideways. Keep the ripple's travel
// equal in pixels on both axes, while animating only the separate border layer.
export function timelineProposalPingScale(width, height, spread = 5) {
    if (![width, height, spread].every(Number.isFinite) || width <= 0 || height <= 0 || spread < 0) return null;
    return { x: 1 + 2 * spread / width, y: 1 + 2 * spread / height };
}

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
        focusPreviewAnchor: null,
        focusPreviewStyle: null,
        hoverCache: new Map(),
        disposed: false,
        proposalObserver: null,
        proposalMutation: null,
        proposalResize: null,
        observedProposals: new Set(),
        proposalShells: new Map(),
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
                if (typeof ResizeObserver === 'function') {
                    this.proposalResize = new ResizeObserver(entries => {
                        for (const entry of entries) {
                            const scale = timelineProposalPingScale(entry.contentRect.width, entry.contentRect.height);
                            if (!scale) continue;
                            entry.target.style.setProperty('--proposal-ping-x', scale.x);
                            entry.target.style.setProperty('--proposal-ping-y', scale.y);
                        }
                    });
                }
                this.observeProposals();
                this.proposalMutation = new MutationObserver(() => { this.observeProposals(); this.refreshFocusPreview(); });
                this.proposalMutation.observe(this.$root, { childList: true, subtree: true });
                this.visibilityListener = () => { this.$root.dataset.motionPaused = String(document.hidden); };
                document.addEventListener('visibilitychange', this.visibilityListener);
                this.visibilityListener();
            });
        },
        observeProposals() {
            for (const node of this.observedProposals) {
                if (!node.isConnected) {
                    this.proposalObserver.unobserve(node);
                    const shell = this.proposalShells.get(node);
                    if (shell) this.proposalResize?.unobserve(shell);
                    this.proposalShells.delete(node);
                    this.observedProposals.delete(node);
                }
            }
            for (const node of this.$root.querySelectorAll('[data-timeline-proposal]')) {
                if (!this.observedProposals.has(node)) {
                    this.observedProposals.add(node);
                    this.proposalObserver.observe(node);
                    const shell = node.closest('.rt-timeline-proposal-shell');
                    if (shell) { this.proposalShells.set(node, shell); this.proposalResize?.observe(shell); }
                }
            }
        },
        destroy() {
            this.disposed = true;
            this.pending = null;
            this.clearHover();
            this.proposalObserver?.disconnect();
            this.proposalMutation?.disconnect();
            this.proposalResize?.disconnect();
            this.proposalShells.clear();
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
            const focusAnchor = event.target.closest('.rt-timeline-focus-target');
            if (focusAnchor?.classList?.contains('rt-timeline-focus-target')) {
                if (this.disposed || event.pointerType === 'touch' || this.plannerVisible) return;
                if (focusAnchor !== this.focusPreviewAnchor) {
                    this.clearHover();
                    this.focusPreviewAnchor = focusAnchor;
                }
                this.refreshFocusPreview();
                return;
            }
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
            if (this.focusPreviewAnchor && !this.focusPreviewAnchor.contains(event.relatedTarget)) {
                const retained = event.type === 'pointerout' ? this.focusPreviewAnchor.matches(':focus-visible')
                    : event.type === 'focusout' && this.focusPreviewAnchor.matches(':hover');
                if (!retained) this.clearFocusPreview();
            }
            if (this.hoverAnchor && !this.hoverAnchor.contains(event.relatedTarget)) this.clearHover();
        },
        refreshFocusPreview() {
            const anchor = this.focusPreviewAnchor;
            if (!anchor) return;
            const timeline = anchor.closest('.rt-personnel-timeline');
            if (this.disposed || !anchor.isConnected || anchor.disabled || timeline?.dataset.contentLoading === 'true') return this.clearFocusPreview();
            const overlay = anchor.closest('.rt-personnel-timeline-track')?.querySelector('.rt-timeline-focus');
            const preview = overlay?.querySelector('.rt-timeline-focus-preview');
            const text = preview?.querySelector('.rt-personnel-timeline-time-text');
            const body = anchor.closest('.rt-personnel-timeline-body');
            if (!timeline || !overlay || !preview || !text || !body) return this.clearFocusPreview();
            const bodyRect = body.getBoundingClientRect(), overlayRect = overlay.getBoundingClientRect(), cellRect = anchor.getBoundingClientRect();
            const nameWidth = parseFloat(getComputedStyle(timeline).getPropertyValue('--timeline-name-width'));
            const position = timelineFocusPreviewPosition({
                overlayLeft: overlayRect.left, overlayWidth: overlayRect.width,
                cellLeft: cellRect.left, cellRight: cellRect.right,
                viewportLeft: bodyRect.left + body.clientLeft + nameWidth,
                viewportRight: bodyRect.left + body.clientLeft + body.clientWidth,
                startPercent: Number(overlay.dataset.timeStart), widthPercent: Number(overlay.dataset.timeWidth),
                textWidth: text.getBoundingClientRect().width,
            });
            if (!position) return this.clearFocusPreview();
            // The label and its sibling duration clip share the parent's
            // geometry. Moving only the label would leave the ink clipped to
            // the original, potentially offscreen badge position.
            if (this.focusPreviewStyle?.node !== overlay) {
                this.clearFocusPreviewStyle();
                this.focusPreviewStyle = { node: overlay, values: new Map() };
            }
            for (const [property, value] of [['--time-label-offset', `${position.offset}px`], ['--time-badge-width', `${position.badgeWidth}px`]]) {
                const previous = this.focusPreviewStyle.values.get(property);
                const current = overlay.style.getPropertyValue(property);
                // A Livewire morph can install newer server styles on the same node.
                const baseline = previous && current === previous.painted ? previous
                    : { value: current, priority: overlay.style.getPropertyPriority(property) };
                if (current !== value) overlay.style.setProperty(property, value);
                this.focusPreviewStyle.values.set(property, { ...baseline, painted: value });
            }
        },
        clearFocusPreviewStyle() {
            const record = this.focusPreviewStyle;
            if (!record) return;
            for (const [property, previous] of record.values) {
                if (record.node.style.getPropertyValue(property) !== previous.painted) continue;
                if (previous.value) record.node.style.setProperty(property, previous.value, previous.priority);
                else record.node.style.removeProperty(property);
            }
            this.focusPreviewStyle = null;
        },
        clearFocusPreview() {
            this.focusPreviewAnchor = null;
            this.clearFocusPreviewStyle();
        },
        clearHover() {
            this.clearFocusPreview();
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
            // Do not dirty an already-closed child during optimistic navigation:
            // its empty commit would compete with the parent's reactive date update.
            if (this.$wire.assignmentOpen === true) this.$wire.assignmentOpen = false;
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
                    const generation = this.requestVersion;
                    try {
                        if (job.kind === 'hover') {
                            const value = await this.request('previewCell', [job.user, job.date]);
                            // Filter/navigation invalidation can happen while the
                            // request is unresolved. Never refill the cleared
                            // cache (or repaint) with that obsolete generation.
                            if (this.disposed || generation !== this.requestVersion) continue;
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
                        if (job.kind === 'hover' && !this.disposed && generation === this.requestVersion) this.paintHover(job.anchor, { state: 'error', label: 'Prüfung nicht verfügbar', detail: 'Per Klick erneut versuchen.' });
                        else if (job.version === this.requestVersion && this.plannerVisible) {
                            this.plannerLoading = false;
                            this.plannerError = 'Die Auswahl konnte nicht geladen werden. Bitte schließen und erneut versuchen.';
                        }
                    }
                }
            } finally {
                this.inFlight = false;
                if (!this.plannerVisible && !this.disposed && this.$wire.assignmentOpen === true) this.$wire.assignmentOpen = false;
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
