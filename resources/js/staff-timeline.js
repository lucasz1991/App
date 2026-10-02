// Fit a whole number of days beside the sticky employee column, including on phones.
export function timelineDayWidth(available, minimum, count) {
    const days = Math.max(1, count);
    const visible = Math.min(days, Math.max(1, Math.floor(available / minimum)));
    return Math.max(1, available / visible);
}

export function timelineDayOffset(offset, width, direction, maximum) {
    return Math.max(0, Math.min(maximum, (Math.round(offset / width) + direction) * width));
}

export function staffTimeline() {
    return {
        canScrollLeft: false,
        canScrollRight: false,
        observer: null,
        resizeFrame: null,
        mirroredScrollbarLeft: 0,
        init() {
            this.observer = new ResizeObserver(() => this.queueMeasure());
            this.observer.observe(this.$refs.timelineBody);
            this.observer.observe(this.$refs.timelineGrid);
            this.$nextTick(() => this.measure());
        },
        destroy() {
            this.observer?.disconnect();
            if (this.resizeFrame !== null) cancelAnimationFrame(this.resizeFrame);
        },
        queueMeasure() {
            if (this.resizeFrame !== null) return;
            this.resizeFrame = requestAnimationFrame(() => {
                this.resizeFrame = null;
                this.measure();
            });
        },
        measure() {
            const body = this.$refs.timelineBody;
            const style = getComputedStyle(this.$el);
            const available = body.clientWidth - parseFloat(style.getPropertyValue('--timeline-name-width'));
            if (available <= 0) return;
            const width = timelineDayWidth(available,
                parseFloat(style.getPropertyValue('--timeline-day-min-width')) || 190,
                parseInt(style.getPropertyValue('--timeline-days'), 10) || 1);
            this.$el.style.setProperty('--timeline-day-width', `${width}px`);
            this.$el.style.setProperty('--timeline-gutter', `${body.offsetWidth - body.clientWidth}px`);
            this.syncHorizontal(body);
        },
        syncHorizontal(source) {
            const { timelineBody: body, timelineHeader: header, timelineScrollbar: scrollbar } = this.$refs;
            if (source === scrollbar) {
                // Ignore our mirrored scroll event; otherwise it cancels an in-flight smooth scroll.
                if (Math.abs(scrollbar.scrollLeft - this.mirroredScrollbarLeft) < 1) return;
                body.scrollLeft = scrollbar.scrollLeft;
            }
            const offset = body.scrollLeft;
            header.scrollLeft = offset;
            this.mirroredScrollbarLeft = offset;
            if (Math.abs(scrollbar.scrollLeft - offset) >= 1) scrollbar.scrollLeft = offset;
            this.canScrollLeft = offset > 1;
            this.canScrollRight = offset < body.scrollWidth - body.clientWidth - 1;
        },
        scrollDay(direction) {
            const body = this.$refs.timelineBody;
            const width = parseFloat(getComputedStyle(this.$el).getPropertyValue('--timeline-day-width'));
            if (!width) return;
            body.scrollTo({
                left: timelineDayOffset(body.scrollLeft, width, direction, body.scrollWidth - body.clientWidth),
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
            });
        },
    };
}
