// Fit a whole number of days beside the sticky employee column, including on phones.
export function timelineDayWidth(available, minimum, count) {
    const days = Math.max(1, count);
    const visible = Math.min(days, Math.max(1, Math.floor(available / minimum)));
    return Math.max(1, available / visible);
}

export function timelineDayOffset(offset, width, direction, maximum) {
    return Math.max(0, Math.min(maximum, (Math.round(offset / width) + direction) * width));
}

// Readable badges span the complete duty; their strip keeps short duties precise.
export function timelineEventLanes(events, dayWidth) {
    const lanes = [];
    const results = new Map();
    const width = Math.max(1, dayWidth);
    const ordered = events.map((event, index) => ({ ...event, index }))
        .sort((a, b) => a.start - b.start || b.duration - a.duration);
    for (const event of ordered) {
        const start = Math.max(0, Math.min(width, event.start * width / 100));
        const end = Math.max(start, Math.min(width, (event.start + event.duration) * width / 100));
        const labelWidth = Math.min(Math.max(end - start, event.labelWidth || 0), Math.max(0, width - 8));
        const half = labelWidth / 2;
        const center = Math.max(half + 4, Math.min(width - half - 4, (start + end) / 2));
        const occupiedStart = Math.min(start, center - half);
        const occupiedEnd = Math.max(end, center + half);
        let lane = Math.max(0, event.lane || 0);
        while (lanes[lane]?.some(([from, until]) => occupiedStart < until + 4 && occupiedEnd + 4 > from)) lane++;
        (lanes[lane] ||= []).push([occupiedStart, occupiedEnd]);
        results.set(event.index, { lane, labelOffset: center - start, badgeWidth: labelWidth });
    }
    return events.map((_, index) => results.get(index));
}

export function staffTimeline() {
    return {
        canScrollLeft: false,
        canScrollRight: false,
        observer: null,
        contentObserver: null,
        resizeFrame: null,
        mirroredScrollbarLeft: 0,
        init() {
            this.observer = new ResizeObserver(() => this.queueMeasure());
            this.observer.observe(this.$refs.timelineBody);
            this.observer.observe(this.$refs.timelineGrid);
            this.contentObserver = new MutationObserver(() => this.queueMeasure());
            this.contentObserver.observe(this.$refs.timelineGrid, { childList: true, subtree: true });
            this.$nextTick(() => this.measure());
        },
        destroy() {
            this.observer?.disconnect();
            this.contentObserver?.disconnect();
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
            this.measureEventLabels(width * (parseInt(style.getPropertyValue('--timeline-days'), 10) || 1));
            this.syncHorizontal(body);
        },
        measureEventLabels(periodWidth) {
            for (const track of this.$refs.timelineGrid.querySelectorAll('.rt-personnel-timeline-track')) {
                const events = [...track.querySelectorAll('.rt-personnel-timeline-event')];
                if (!events.length) continue;
                const positions = timelineEventLanes(events.map(event => ({
                    start: parseFloat(event.dataset.timeStart) || 0,
                    duration: parseFloat(event.dataset.timeWidth) || 0,
                    lane: parseInt(event.dataset.timeLane, 10) || 0,
                    labelWidth: event.querySelector('.rt-personnel-timeline-time-text')
                        ? event.querySelector('.rt-personnel-timeline-time-text').getBoundingClientRect().width + 16
                        : 0,
                })), periodWidth);
                positions.forEach((position, index) => {
                    events[index].style.setProperty('--event-lane', position.lane);
                    events[index].style.setProperty('--time-label-offset', `${position.labelOffset}px`);
                    events[index].style.setProperty('--time-badge-width', `${position.badgeWidth}px`);
                });
                const rowLaneCount = parseInt(track.dataset.timelineLanes, 10) || 1;
                track.style.setProperty('--timeline-lanes', Math.max(rowLaneCount, Math.max(...positions.map(position => position.lane)) + 1));
            }
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
