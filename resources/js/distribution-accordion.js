const dayKey = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);

function readJson(value, fallback) {
    if (typeof value !== 'string' || value.length > 8192) return fallback;
    try { return JSON.parse(value); } catch { return fallback; }
}

// Presentation only: collapsing a day never clears a native shift selection,
// starts a request, or moves the timeline. One observer follows server metadata.
export function distributionPanel(initial = {}) {
    let observer = null;
    let alive = false;
    let previousContext = null;
    let previousSelection = null;
    return {
        distributionTab: initial.distributionTab === 'orders' ? 'orders' : 'shifts',
        distributionMode: 'list',
        openDay: null,
        days: [],
        init() {
            alive = true;
            this.refreshDays();
            if (typeof MutationObserver !== 'undefined') {
                observer = new MutationObserver(() => { if (alive) this.refreshDays(); });
                observer.observe(this.$el, { attributes: true, attributeFilter: [
                    'data-distribution-days', 'data-distribution-context',
                    'data-distribution-selected', 'data-distribution-preferred-day',
                ] });
            }
        },
        refreshDays() {
            const data = this.$el.dataset;
            const rawDays = readJson(data.distributionDays, []);
            this.days = Array.isArray(rawDays) ? [...new Set(rawDays.filter(dayKey))].slice(0, 25) : [];
            const selection = readJson(data.distributionSelected, null);
            const selectedDay = Number.isSafeInteger(selection?.id) && selection.id > 0
                && dayKey(selection?.date) && this.days.includes(selection.date) ? selection.date : null;
            const selectionKey = selectedDay
                ? `${selection.id}:${selectedDay}` : null;
            const context = data.distributionContext ?? '';
            const preferred = this.days.includes(data.distributionPreferredDay) ? data.distributionPreferredDay : this.days[0] ?? null;
            if (previousContext !== context) {
                this.openDay = selectedDay ?? preferred;
            } else if (selectionKey && selectionKey !== previousSelection) {
                this.openDay = selectedDay;
            } else if (this.openDay !== null && !this.days.includes(this.openDay)) {
                this.openDay = preferred;
            }
            previousContext = context;
            previousSelection = selectionKey;
        },
        toggleDay(date) {
            if (this.days.includes(date)) this.openDay = this.openDay === date ? null : date;
        },
        revealDay(date) {
            if (this.days.includes(date)) this.openDay = date;
        },
        navigateDays(event) {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            const buttons = [...this.$el.querySelectorAll('[data-distribution-day-toggle]')];
            const index = buttons.indexOf(event.target);
            if (index < 0 || !buttons.length) return;
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1
                : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
            event.preventDefault();
            buttons[next].focus();
        },
        destroy() {
            alive = false;
            observer?.disconnect();
            observer = null;
        },
    };
}
