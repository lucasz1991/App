import { mapHighlight } from './map-highlight.js';

/** Local day-strip navigation. Actual dates and duties remain owned by Livewire. */
export function workdayOverview({ preview = false } = {}) {
    let mutationObserver;
    let resizeObserver;
    let frame;
    let disposed = false;
    return {
        ...mapHighlight(),
        previewLabel: '',
        init() {
            this.$nextTick(() => {
                if (disposed) return;
                this.alignDays();
                mutationObserver = new MutationObserver(records => {
                    if (records.some(record => record.type === 'childList' || record.attributeName === 'data-selected-date')) this.queueAlign();
                });
                mutationObserver.observe(this.$el, { attributes: true, attributeFilter: ['data-selected-date'], childList: true, subtree: true });
                resizeObserver = new ResizeObserver(() => this.queueAlign());
                resizeObserver.observe(this.$refs.days);
            });
        },
        queueAlign() {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => { if (!disposed) this.alignDays(); });
        },
        dayButtons() { return [...this.$refs.days.querySelectorAll('[data-date]')]; },
        alignDays() {
            const days = this.dayButtons();
            const selected = days.findIndex(day => day.getAttribute('aria-pressed') === 'true');
            if (selected < 0) return;
            days.forEach((day, index) => { day.tabIndex = index === selected ? 0 : -1; });
            // Align the containing week, never scroll the page vertically.
            const first = days[Math.floor(selected / 7) * 7];
            const strip = this.$refs.days;
            strip.scrollTo({ left: first.offsetLeft, behavior: 'instant' });
        },
        dayKeydown(event) {
            const days = this.dayButtons();
            const index = days.indexOf(event.target);
            if (index < 0 || event.altKey || event.ctrlKey || event.metaKey) return;
            const target = { ArrowLeft: Math.max(0, index - 1), ArrowRight: Math.min(days.length - 1, index + 1), Home: 0, End: days.length - 1 }[event.key];
            if (target === undefined) return;
            event.preventDefault();
            days.forEach((day, i) => { day.tabIndex = i === target ? 0 : -1; });
            days[target].focus({ preventScroll: true });
            const strip = this.$refs.days;
            const left = days[target].offsetLeft;
            const right = left + days[target].offsetWidth;
            if (left < strip.scrollLeft) strip.scrollTo({ left, behavior: 'instant' });
            else if (right > strip.scrollLeft + strip.clientWidth) strip.scrollTo({ left: right - strip.clientWidth, behavior: 'instant' });
        },
        previewSelect(key) {
            if (!preview || !this.dayButtons().some(day => day.dataset.date === key)) return;
            this.dayButtons().forEach(day => {
                const selected = day.dataset.date === key;
                day.setAttribute('aria-pressed', String(selected));
                day.dataset.selected = String(selected);
            });
            this.previewLabel = new Intl.DateTimeFormat('de-DE', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${key}T12:00:00Z`));
            this.alignDays();
        },
        previewMove(offset) {
            if (!preview) return;
            const days = this.dayButtons();
            const index = days.findIndex(day => day.getAttribute('aria-pressed') === 'true');
            this.previewSelect(days[Math.min(days.length - 1, Math.max(0, index + offset))].dataset.date);
        },
        destroy() {
            disposed = true;
            cancelAnimationFrame(frame);
            mutationObserver?.disconnect();
            resizeObserver?.disconnect();
            this.mapReset();
        },
    };
}
