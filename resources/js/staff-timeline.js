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

// Keep GSAP instances outside Alpine's reactive proxy and scoped to one timeline.
const personnelMotions = new WeakMap();
const contentReveals = new WeakMap();

function restoreRevealTarget(target) {
    const opacity = target.dataset.timelineRevealOpacity;
    if (opacity) target.style.setProperty('opacity', opacity, target.dataset.timelineRevealPriority || '');
    else target.style.removeProperty('opacity');
    // `translate` composes with any transform; restore only what the entrance owned.
    const translate = target.dataset.timelineRevealTranslate;
    if (translate) target.style.setProperty('translate', translate);
    else target.style.removeProperty('translate');
    delete target.dataset.timelineRevealing;
    delete target.dataset.timelineRevealOpacity;
    delete target.dataset.timelineRevealPriority;
    delete target.dataset.timelineRevealTranslate;
}

// Entrance timing: first all employees of the page together, then the shifts row by row
// (top to bottom) and within a row from left to right, each gliding in from the right.
export const TIMELINE_REVEAL = Object.freeze({
    people: { duration: .28 },
    shifts: { start: .28, row: .07, item: .055, duration: .5, offset: 18 },
    rows: 48,
});

export function timelineRevealDelays(shifts) {
    const rows = new Map();
    shifts.forEach((target, index) => {
        const track = target.closest?.('.rt-personnel-timeline-track') || null;
        const key = track || `top:${Math.round(target.getBoundingClientRect().top / 12)}`;
        if (!rows.has(key)) rows.set(key, []);
        rows.get(key).push({ target, index, start: parseFloat(target.dataset?.timeStart) || 0 });
    });
    const delays = new Array(shifts.length).fill(TIMELINE_REVEAL.shifts.start);
    [...rows.values()].slice(0, TIMELINE_REVEAL.rows).forEach((row, rowIndex) => {
        row.sort((a, b) => a.start - b.start).forEach((entry, itemIndex) => {
            delays[entry.index] = TIMELINE_REVEAL.shifts.start + rowIndex * TIMELINE_REVEAL.shifts.row + itemIndex * TIMELINE_REVEAL.shifts.item;
        });
    });

    return delays;
}

function finishRevealBatch(state, batch) {
    if (!state.active.delete(batch)) return;
    batch.tween?.kill();
    batch.targets.forEach(restoreRevealTarget);
}

export function clearStaffTimelineReveals(root) {
    if (!root) return;
    const state = contentReveals.get(root);
    contentReveals.delete(root);
    if (state) {
        state.media?.removeEventListener?.('change', state.reduce);
        [...state.active].forEach(batch => finishRevealBatch(state, batch));
    }
    // A cached navigation snapshot can contain an interrupted entrance.
    root.querySelectorAll('[data-timeline-revealing]').forEach(restoreRevealTarget);
}

export function revealStaffTimeline(root, grid, body) {
    if (root.dataset.timelineMotion !== 'true') return;
    const engine = typeof window !== 'undefined' ? window.gsap : null;
    let state = contentReveals.get(root);
    if (!state) {
        const media = typeof window !== 'undefined' ? window.matchMedia?.('(prefers-reduced-motion: reduce)') : null;
        state = { seen: new WeakSet(), active: new Set(), media, reduce: null };
        state.reduce = () => {
            if (media?.matches) [...state.active].forEach(batch => finishRevealBatch(state, batch));
        };
        media?.addEventListener?.('change', state.reduce);
        contentReveals.set(root, state);
    }
    const unseen = [...grid.querySelectorAll('.rt-personnel-timeline-name, .rt-personnel-timeline-event')]
        .filter(target => !state.seen.has(target));
    unseen.forEach(target => state.seen.add(target));
    if (!unseen.length || !engine?.fromTo || state.media?.matches) return;
    // The whole loaded page enters at once: a later page batch (infinite scroll) gets its own entrance.
    const rendered = unseen.filter(target => {
        const rect = target.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    });
    const people = rendered.filter(target => target.classList.contains('rt-personnel-timeline-name'));
    const shifts = rendered.filter(target => target.classList.contains('rt-personnel-timeline-event'));
    const mark = (targets, moves) => targets.forEach(target => {
        target.dataset.timelineRevealing = 'true';
        target.dataset.timelineRevealOpacity = target.style.getPropertyValue('opacity');
        target.dataset.timelineRevealPriority = target.style.getPropertyPriority('opacity');
        if (moves) target.dataset.timelineRevealTranslate = target.style.getPropertyValue('translate');
    });
    if (people.length) {
        const batch = { targets: people, tween: null };
        state.active.add(batch);
        mark(people, false);
        // All employees together; the sticky column itself never moves.
        batch.tween = engine.fromTo(people, { opacity: 0 }, {
            opacity: 1, duration: TIMELINE_REVEAL.people.duration, delay: 0, ease: 'power2.out', inherit: false,
            onComplete: () => finishRevealBatch(state, batch),
        });
    }
    if (shifts.length) {
        const delays = timelineRevealDelays(shifts);
        const batch = { targets: shifts, tween: null };
        state.active.add(batch);
        mark(shifts, true);
        // `translate` is visual only: lanes, widths and the time positions stay untouched.
        batch.tween = engine.fromTo(shifts, { opacity: 0, translate: `${TIMELINE_REVEAL.shifts.offset}px 0px` }, {
            opacity: 1, translate: '0px 0px', duration: TIMELINE_REVEAL.shifts.duration, ease: 'expo.out', inherit: false,
            delay: 0, stagger: index => delays[index],
            onComplete: () => finishRevealBatch(state, batch),
        });
    }
}

function clearPersonnelMotion(root) {
    if (!root) return;
    const motion = personnelMotions.get(root);
    personnelMotions.delete(root);
    motion?.tween?.kill();
    root.style.removeProperty('--timeline-name-width');
    delete root.dataset.personnelAnimating;
}

export function staffTimeline() {
    return {
        canScrollLeft: false,
        canScrollRight: false,
        observer: null,
        contentObserver: null,
        resizeFrame: null,
        mirroredScrollbarLeft: 0,
        lastScrollLeft: 0,
        compactRequested: false,
        personnelDefaultCompact: null,
        personnelExplicit: false,
        personnelPointerType: null,
        personnelHovered: false,
        personnelFocused: false,
        appliedPersonnelCompact: null,
        layoutScrollOffset: null,
        measuredLayout: null,
        horizontalIntent: null,
        intentTimer: null,
        get personnelCompact() {
            return this.compactRequested && !this.personnelHovered && !this.personnelFocused;
        },
        setCompactRequested(compact) {
            this.compactRequested = Boolean(compact);
            this.personnelExplicit = false;
        },
        refreshPersonnelPreference(style = getComputedStyle(this.$el)) {
            const compact = (style.getPropertyValue('--timeline-personnel-default-compact') || '').trim() === '1';
            const previous = this.personnelDefaultCompact;
            if (previous === compact) return;
            this.personnelDefaultCompact = compact;
            // Responsive defaults never replace an explicit button choice.
            // Preserve an input received before the first Alpine effect too.
            if (!this.personnelExplicit && (previous !== null || !this.compactRequested)) {
                this.compactRequested = compact;
            }
        },
        togglePersonnelColumn() {
            const compact = !this.personnelCompact;
            const pendingIntent = this.horizontalIntent !== null;
            this.finishHorizontalIntent();
            this.personnelHovered = false;
            this.personnelFocused = false;
            if (pendingIntent) this.holdHorizontalIntent(compact ? 1 : -1);
            else this.setCompactRequested(compact);
            this.personnelExplicit = true;
        },
        ownsPersonnel(target) {
            const visited = new Set();
            while (target?.closest) {
                const column = target.closest('[data-timeline-person-column]');
                if (column && this.$el.contains(column)) return true;
                const owner = target.closest('[data-rt-dropdown-owner]')?.dataset.rtDropdownOwner;
                if (!owner || visited.has(owner)) break;
                visited.add(owner);
                target = [...document.querySelectorAll('[data-rt-dropdown-root]')]
                    .find(root => root.dataset.rtDropdownId === owner);
            }
            return false;
        },
        pointerPersonnel(event) {
            if (event.pointerType !== 'mouse') {
                if (event.type === 'pointerover' && this.ownsPersonnel(event.target)) {
                    this.personnelPointerType = event.pointerType;
                    this.personnelHovered = false;
                    this.personnelFocused = false;
                }
                return;
            }
            const target = event.type === 'pointerout' ? event.relatedTarget : event.target;
            const owns = this.ownsPersonnel(target);
            if (owns) this.personnelPointerType = 'mouse';
            // The explicit button must remain usable as its own column moves.
            this.personnelHovered = !target?.closest?.('[data-timeline-person-toggle]') && owns;
        },
        wheelPersonnel(event) {
            // Intent only: never prevent or replace the browser's native scrolling.
            if (Math.abs(event.deltaX) >= Math.max(1, Math.abs(event.deltaY))) {
                this.holdHorizontalIntent(event.deltaX);
            }
        },
        holdHorizontalIntent(direction) {
            this.horizontalIntent = direction > 0 ? 1 : -1;
            this.setCompactRequested(direction > 0);
            this.queueIntentRelease();
        },
        queueIntentRelease() {
            if (this.intentTimer !== null) clearTimeout(this.intentTimer);
            // Also covers older browsers and an edge gesture that does not scroll.
            this.intentTimer = setTimeout(() => this.finishHorizontalIntent(), 350);
        },
        finishHorizontalIntent() {
            if (this.intentTimer !== null) clearTimeout(this.intentTimer);
            this.intentTimer = null;
            this.horizontalIntent = null;
            if (this.$refs?.timelineBody) this.lastScrollLeft = this.$refs.timelineBody.scrollLeft;
        },
        focusPersonnel(target) {
            // A preview is teleported, but still belongs to its employee trigger.
            const owns = !target?.closest?.('[data-timeline-person-toggle]') && this.ownsPersonnel(target);
            // A tap can focus an avatar without making the phone column jump open.
            // Keyboard focus remains discoverable even after using touch.
            this.personnelFocused = owns && (this.personnelPointerType !== 'touch' || target.matches(':focus-visible'));
        },
        applyPersonnelMode() {
            if (this.personnelDefaultCompact === null) this.refreshPersonnelPreference();
            const compact = this.personnelCompact;
            if (this.appliedPersonnelCompact === compact) return;
            const root = this.$el;
            const initial = this.appliedPersonnelCompact === null;
            // Cached Livewire history may contain an interrupted animation style.
            if (initial) clearPersonnelMotion(root);
            const body = this.$refs.timelineBody;
            const style = getComputedStyle(root);
            const currentWidth = parseFloat(style.getPropertyValue('--timeline-name-width'));
            const fullWidth = parseFloat(style.getPropertyValue('--timeline-name-full-width')) || currentWidth;
            const compactWidth = parseFloat(style.getPropertyValue('--timeline-name-compact-width')) || 52;
            const targetWidth = compact ? compactWidth : fullWidth;
            const previousOffset = body.scrollLeft;
            const previousMaximum = Math.max(0, body.scrollWidth - body.clientWidth);
            const atEnd = Math.abs(previousOffset - previousMaximum) < 1 && previousMaximum > 0;
            const engine = typeof window !== 'undefined' ? window.gsap : null;
            const reduceQuery = typeof window !== 'undefined' ? window.matchMedia?.('(prefers-reduced-motion: reduce)') : null;
            const animate = !initial && engine && !reduceQuery?.matches
                && Number.isFinite(currentWidth) && Number.isFinite(targetWidth) && Math.abs(currentWidth - targetWidth) >= 1;
            clearPersonnelMotion(root);
            if (animate) root.style.setProperty('--timeline-name-width', `${currentWidth}px`);
            this.appliedPersonnelCompact = compact;
            root.dataset.personnelCompact = compact ? 'true' : 'false';
            if (!animate) {
                this.rebasePersonnelLayout(atEnd, previousOffset);
                return;
            }
            const motion = { width: currentWidth, fullWidth, atEnd, reduceQuery, tween: null };
            personnelMotions.set(root, motion);
            root.dataset.personnelAnimating = 'true';
            motion.tween = engine.to(motion, {
                width: targetWidth,
                duration: 0.22,
                ease: 'power2.out',
                onUpdate: () => {
                    if (personnelMotions.get(root) !== motion) return;
                    if (motion.reduceQuery?.matches) {
                        this.finishPersonnelMotion(motion);
                        return;
                    }
                    root.style.setProperty('--timeline-name-width', `${motion.width}px`);
                    this.rebasePersonnelLayout(motion.atEnd, body.scrollLeft);
                },
                onComplete: () => this.finishPersonnelMotion(motion),
            });
        },
        finishPersonnelMotion(motion) {
            if (personnelMotions.get(this.$el) !== motion) return;
            const offset = this.$refs.timelineBody.scrollLeft;
            clearPersonnelMotion(this.$el);
            this.rebasePersonnelLayout(motion.atEnd, offset);
        },
        rebasePersonnelLayout(atEnd, previousOffset) {
            const body = this.$refs.timelineBody;
            const maximum = Math.max(0, body.scrollWidth - body.clientWidth);
            const expectedOffset = atEnd ? maximum : Math.min(previousOffset, maximum);
            // Preserve the final expectation for a delayed native snap event too.
            this.layoutScrollOffset = atEnd || Math.abs(expectedOffset - previousOffset) >= 1 ? expectedOffset : null;
            this.syncHorizontal(body, false);
        },
        init() {
            clearStaffTimelineReveals(this.$el);
            this.refreshPersonnelPreference();
            this.observer = new ResizeObserver(() => this.queueMeasure());
            this.observer.observe(this.$refs.timelineBody);
            this.observer.observe(this.$refs.timelineGrid);
            this.contentObserver = new MutationObserver(() => this.queueMeasure());
            this.contentObserver.observe(this.$refs.timelineGrid, { childList: true, subtree: true });
            this.$nextTick(() => this.measure());
        },
        destroy() {
            this.finishHorizontalIntent();
            this.observer?.disconnect();
            this.contentObserver?.disconnect();
            if (this.resizeFrame !== null) cancelAnimationFrame(this.resizeFrame);
            clearPersonnelMotion(this.$el);
            clearStaffTimelineReveals(this.$el);
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
            this.refreshPersonnelPreference(style);
            // The time scale stays identical when only the employee column changes.
            const fullNameWidth = parseFloat(style.getPropertyValue('--timeline-name-full-width'))
                || parseFloat(style.getPropertyValue('--timeline-name-width'));
            const days = parseInt(style.getPropertyValue('--timeline-days'), 10) || 1;
            const layout = `${body.clientWidth}:${fullNameWidth}:${days}`;
            const resized = this.measuredLayout !== null && this.measuredLayout !== layout;
            const wasAtEnd = this.canScrollLeft && !this.canScrollRight;
            this.measuredLayout = layout;
            const motion = personnelMotions.get(this.$el);
            // Let the responsive CSS tokens own the endpoint after a breakpoint change.
            if (motion && Math.abs(motion.fullWidth - fullNameWidth) >= 1) this.finishPersonnelMotion(motion);
            const available = body.clientWidth - fullNameWidth;
            if (available <= 0) return;
            const width = timelineDayWidth(available,
                parseFloat(style.getPropertyValue('--timeline-day-min-width')) || 190,
                days);
            this.$el.style.setProperty('--timeline-day-width', `${width}px`);
            this.$el.style.setProperty('--timeline-gutter', `${body.offsetWidth - body.clientWidth}px`);
            this.measureEventLabels(width * days);
            if (resized) this.rebasePersonnelLayout(wasAtEnd, body.scrollLeft);
            else this.syncHorizontal(body, false);
            revealStaffTimeline(this.$el, this.$refs.timelineGrid, body);
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
                const laneCount = Math.max(rowLaneCount, Math.max(...positions.map(position => position.lane)) + 1);
                track.style.setProperty('--timeline-lanes', laneCount);
                // Include readable-label collisions, not only the server's true overlaps.
                // Two compact lanes fit the existing 48px row; further lanes stay visible.
                const density = laneCount > 1 ? 'compact' : 'normal';
                if (track.dataset.timelineDensity !== density) track.dataset.timelineDensity = density;
            }
        },
        syncHorizontal(source, detectDirection = true) {
            const { timelineBody: body, timelineHeader: header, timelineScrollbar: scrollbar } = this.$refs;
            if (source === scrollbar) {
                // Ignore our mirrored scroll event; otherwise it cancels an in-flight smooth scroll.
                if (Math.abs(scrollbar.scrollLeft - this.mirroredScrollbarLeft) < 1) return;
                if (detectDirection) this.finishHorizontalIntent();
                body.scrollLeft = scrollbar.scrollLeft;
            }
            const offset = body.scrollLeft;
            const motion = personnelMotions.get(this.$el);
            const motionEdge = source !== scrollbar && motion?.atEnd
                && Math.abs(offset - Math.max(0, body.scrollWidth - body.clientWidth)) < 1;
            const layoutScroll = motionEdge || (this.layoutScrollOffset !== null && Math.abs(offset - this.layoutScrollOffset) < 1);
            const moved = Math.abs(offset - this.lastScrollLeft) >= 1;
            if (detectDirection && (layoutScroll || moved)) this.layoutScrollOffset = null;
            if (detectDirection && !layoutScroll && moved && this.horizontalIntent === null) {
                this.setCompactRequested(offset > this.lastScrollLeft);
            }
            if (detectDirection && this.horizontalIntent !== null) this.queueIntentRelease();
            this.lastScrollLeft = offset;
            header.scrollLeft = offset;
            if (Math.abs(scrollbar.scrollLeft - offset) >= 1) scrollbar.scrollLeft = offset;
            this.mirroredScrollbarLeft = scrollbar.scrollLeft;
            this.canScrollLeft = offset > 1;
            this.canScrollRight = offset < body.scrollWidth - body.clientWidth - 1;
        },
        scrollDay(direction) {
            const body = this.$refs.timelineBody;
            const width = parseFloat(getComputedStyle(this.$el).getPropertyValue('--timeline-day-width'));
            if (!width) return;
            this.holdHorizontalIntent(direction);
            body.scrollTo({
                left: timelineDayOffset(body.scrollLeft, width, direction, body.scrollWidth - body.clientWidth),
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
            });
        },
    };
}
