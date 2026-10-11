const DAY_MS = 86400000;

function civilDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(0);
    date.setUTCFullYear(year, month - 1, day);
    date.setUTCHours(0, 0, 0, 0);
    return year >= 1 && year <= 9999 && date.getUTCFullYear() === year && date.getUTCMonth() === month - 1
        && date.getUTCDate() === day ? date : null;
}

export function timelineDetailCalendar(seed) {
    const first = civilDate(seed?.gridStart);
    if (!first || ![35, 42].includes(seed?.count) || !/^\d{4}-\d{2}$/.test(seed?.month || '')) return [];
    return Array.from({ length: seed.count }, (_, index) => {
        const day = new Date(first.getTime() + index * DAY_MS);
        const date = day.toISOString().slice(0, 10);
        const selected = Boolean(seed.last && date >= seed.first && date <= seed.last);
        return { date, number: day.getUTCDate(), outside: date.slice(0, 7) !== seed.month,
            selected, start: selected && date === seed.first, end: selected && date === seed.last };
    });
}

// One presentation-only owner. All facts came from this authorized timeline render;
// opening the native popover sends no request and can never reserve/assign a seat.
export function timelineEventDetails(ownerId) {
    let activeAnchor = null;
    let mutationObserver = null;
    let scope = null;
    return {
        detail: null,
        detailTab: 'period',
        detailVisible: false,
        disposed: false,
        get detailCalendar() { return timelineDetailCalendar(this.detail?.calendar); },
        get detailTabPrefix() { return `timeline-shared-detail-${ownerId}-${this.detail?.kind === 'shift' ? 'shift' : 'absence'}`; },
        eventPanelId() { return `rt-dropdown-timeline-event-${ownerId}`; },
        restoreDetailAnchor(anchor) {
            if (!anchor?.isConnected) return;
            anchor.setAttribute('aria-expanded', 'false');
            anchor.setAttribute('aria-haspopup', 'dialog');
            anchor.setAttribute('aria-controls', `${this.eventPanelId()}-content`);
        },
        init() {
            scope = this.$el.closest('.rt-staff-timeline-layout');
            if (typeof MutationObserver !== 'function' || !scope) return;
            mutationObserver = new MutationObserver(records => {
                if (!this.detailVisible) return;
                // A confirmed server morph or removed trigger must not leave a stale popup.
                // Ignore the shared popover's own tab/calendar mutations.
                if (!activeAnchor?.isConnected || records.some(record => record.target.closest?.('.rt-personnel-timeline')
                    && (record.type === 'childList' || record.attributeName === 'data-timeline-detail'))) this.clearDetail();
            });
            mutationObserver.observe(scope, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-timeline-detail'] });
        },
        openEvent(event) {
            const anchor = event?.detail?.anchor;
            if (this.disposed || !anchor?.isConnected || !scope?.contains(anchor)
                || anchor.closest('[data-timeline-id]')?.dataset.timelineId !== ownerId) return;
            if (this.detailVisible && activeAnchor === anchor) { this.clearDetail(); return; }
            let detail;
            try { detail = JSON.parse(anchor.dataset.timelineDetail); } catch { return; }
            if (!detail || !['shift', 'absence'].includes(detail.kind) || typeof detail.title !== 'string'
                || typeof detail.person?.name !== 'string' || typeof detail.person?.avatar !== 'string'
                || !detail.start || !detail.end || !detail.calendar || !detail.map
                || !Array.isArray(detail.metadata) || !Array.isArray(detail.workload)
                || [detail.shiftId, detail.absenceId].some(id => id !== null && (!Number.isInteger(id) || id <= 0))) return;
            const previousAnchor = activeAnchor;
            activeAnchor = anchor;
            this.detail = detail;
            this.detailTab = 'period';
            this.detailVisible = true;
            this.$dispatch('rt-anchor-dropdown-open', { id: this.eventPanelId(), anchor });
            // The native external primitive cleans its old trigger; ordinary badges
            // retain their permanent accessible link to the shared dialog afterwards.
            if (previousAnchor !== anchor) this.restoreDetailAnchor(previousAnchor);
            this.$nextTick(() => {
                if (!this.disposed && this.detailVisible && activeAnchor === anchor) {
                    this.$dispatch('rt-anchor-dropdown-focus', { id: this.eventPanelId() });
                }
            });
        },
        detailClosed(event) {
            if (event?.detail?.id !== this.eventPanelId()) return;
            this.detailVisible = false;
            this.restoreDetailAnchor(activeAnchor);
            activeAnchor = null;
        },
        clearDetail(event = null) {
            if (event && event.detail?.timelineId !== ownerId) return;
            if (this.detailVisible) this.$dispatch('rt-anchor-dropdown-close', { id: this.eventPanelId() });
            this.detailVisible = false;
            this.restoreDetailAnchor(activeAnchor);
            activeAnchor = null;
        },
        destroy() {
            this.clearDetail();
            this.disposed = true;
            mutationObserver?.disconnect();
            mutationObserver = null;
            scope = null;
        },
    };
}
