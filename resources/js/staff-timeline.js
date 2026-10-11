// Fit a whole number of days beside the sticky employee column, including on phones.
export function timelineDayWidth(available, minimum, count) {
    const days = Math.max(1, count);
    const visible = Math.min(days, Math.max(1, Math.floor(available / minimum)));
    return Math.max(1, available / visible);
}

export function timelineDayOffset(offset, width, direction, maximum) {
    return Math.max(0, Math.min(maximum, (Math.round(offset / width) + direction) * width));
}

// Date-only arithmetic deliberately uses UTC civil days, not elapsed local hours:
// a spring/autumn clock change must still advance exactly one date on the axis.
const CIVIL_DAY_MILLISECONDS = 86400000;

function timelineCivilDay(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const [year, month, day] = value.split('-').map(Number);
    if (year < 1 || year > 9999) return null;
    const date = new Date(0);
    date.setUTCFullYear(year, month - 1, day);
    date.setUTCHours(0, 0, 0, 0);
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
    return date.getTime() / CIVIL_DAY_MILLISECONDS;
}

function timelineWindowPeriod(from, until, maximum = 94) {
    const start = timelineCivilDay(from);
    const end = timelineCivilDay(until);
    return start === null || end === null || end < start || end - start >= maximum ? null : { from, until };
}

function timelineDateOffset(value, direction) {
    const day = timelineCivilDay(value);
    if (day === null) return null;
    const date = new Date((day + direction) * CIVIL_DAY_MILLISECONDS);
    return date.getUTCFullYear() < 1 || date.getUTCFullYear() > 9999 ? null : date.toISOString().slice(0, 10);
}

export function timelineBufferedEdges(width, maximum, before = 0, after = 0) {
    const first = Math.min(maximum, before * width);
    return { first, last: Math.max(first, maximum - after * width) };
}

export function timelineShiftPeriod(from, until, direction) {
    if (![-1, 1].includes(direction) || !timelineWindowPeriod(from, until)) return null;
    const dates = [from, until].map(value => new Date((timelineCivilDay(value) + direction) * CIVIL_DAY_MILLISECONDS));
    if (dates.some(date => date.getUTCFullYear() < 1 || date.getUTCFullYear() > 9999)) return null;
    return { from: dates[0].toISOString().slice(0, 10), until: dates[1].toISOString().slice(0, 10) };
}

export function timelineWindowOffsets(previousFrom, nextFrom, offset, width, maximum) {
    const previous = timelineCivilDay(previousFrom);
    const next = timelineCivilDay(nextFrom);
    if (previous === null || next === null || Math.abs(next - previous) !== 1
        || ![offset, width, maximum].every(Number.isFinite) || width <= 0 || maximum < 0) return null;
    const direction = next - previous;
    const clamp = value => Math.max(0, Math.min(maximum, value));
    return { anchor: clamp(offset - direction * width), target: clamp(offset) };
}

export function timelinePeriodUrl(href, from, until) {
    if (!timelineWindowPeriod(from, until)) return null;
    try {
        const url = new URL(href);
        url.searchParams.set('from', from);
        url.searchParams.set('until', until);
        return url.href;
    } catch {
        return null;
    }
}

// Scroll target that brings a focused day fully into view, or null when it is already visible.
export function timelineFocusOffset(index, width, offset, visible, maximum) {
    if (index < 0 || !width) return null;
    const start = index * width;
    if (start >= offset - 1 && start + width <= offset + visible + 1) return null;
    return Math.max(0, Math.min(maximum, start));
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
        const leftInset = event.continuesBefore ? 0 : 4;
        const rightInset = event.continuesAfter ? 0 : 4;
        const labelWidth = Math.min(Math.max(end - start, event.labelWidth || 0), Math.max(0, width - leftInset - rightInset));
        const half = labelWidth / 2;
        const center = event.continuesBefore ? half : event.continuesAfter ? width - half
            : Math.max(half + leftInset, Math.min(width - half - rightInset, (start + end) / 2));
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
// Requests/anchors stay outside Alpine's proxy, so their identities survive a morph.
const windowNavigations = new WeakMap();

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

// The CSS keeps the first page hidden until this marker exists, so the server render never flashes before the entrance.
export const TIMELINE_READY_ATTRIBUTE = 'data-rt-timeline-ready';

export function revealStaffTimeline(root, grid, body) {
    try {
        startStaffTimelineReveal(root, grid, body);
    } finally {
        // Set after the tweens wrote their start values: no frame paints the targets between both steps.
        root.ownerDocument?.documentElement?.setAttribute(TIMELINE_READY_ATTRIBUTE, 'true');
    }
}

function startStaffTimelineReveal(root, grid, body) {
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
        focusDayIndex: -1,
        observer: null,
        contentObserver: null,
        windowObserver: null,
        windowRoot: null,
        windowLoading: false,
        windowError: '',
        windowPendingDate: '',
        windowPendingBefore: false,
        windowPendingAfter: false,
        windowDisposed: false,
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
        get timelineElement() {
            // Alpine's $el is the currently evaluating directive (also a header
            // button for x-show/x-on), not necessarily this component's x-data root.
            return this.windowRoot || this.$el;
        },
        get windowNavigationEnabled() {
            return this.timelineElement?.dataset?.timelineWindowNavigation === 'true'
                && Boolean(this.timelineElement.dataset.timelinePeriodOwner);
        },
        get windowPendingLabel() {
            const day = timelineCivilDay(this.windowPendingDate);
            return day === null ? '' : new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', timeZone: 'UTC' })
                .format(new Date(day * CIVIL_DAY_MILLISECONDS));
        },
        windowDayLoading(date) {
            return this.windowLoading && date === this.windowPendingDate;
        },
        windowAxisPeriod() {
            const root = this.timelineElement;
            return timelineWindowPeriod(root.dataset.timelineAxisFrom || root.dataset.timelineFrom,
                root.dataset.timelineAxisUntil || root.dataset.timelineUntil, 100);
        },
        windowBuffer(side) {
            const value = this.timelineElement.dataset[side === 'before' ? 'timelineBufferBefore' : 'timelineBufferAfter'];
            return /^[0-3]$/.test(value || '') ? Number(value) : 0;
        },
        windowNavigationState() {
            const root = this.timelineElement;
            let state = windowNavigations.get(root);
            if (!state) {
                state = { period: timelineWindowPeriod(root.dataset.timelineFrom, root.dataset.timelineUntil),
                    axis: this.windowAxisPeriod(), positioned: false, request: null, timer: null, frame: null, cleanup: null };
                windowNavigations.set(root, state);
            }
            return state;
        },
        timelineScrollEdges(selected = false) {
            const body = this.$refs.timelineBody;
            const maximum = Math.max(0, body.scrollWidth - body.clientWidth);
            if (!this.windowNavigationEnabled || (!selected && windowNavigations.get(this.timelineElement)?.request)) {
                return { first: 0, last: maximum };
            }
            const width = parseFloat(getComputedStyle(this.timelineElement).getPropertyValue('--timeline-day-width')) || 0;
            return timelineBufferedEdges(width, maximum,
                this.windowBuffer('before') + Number(this.windowPendingBefore),
                this.windowBuffer('after') + Number(this.windowPendingAfter));
        },
        clearWindowRequest(state) {
            if (state.timer !== null) clearTimeout(state.timer);
            state.timer = null;
            state.request = null;
            this.windowLoading = false;
            this.windowPendingDate = '';
            this.windowPendingBefore = false;
            this.windowPendingAfter = false;
        },
        failWindowRequest(request) {
            const state = windowNavigations.get(this.timelineElement);
            if (this.windowDisposed || state?.request !== request) return;
            const body = this.$refs.timelineBody;
            const width = parseFloat(getComputedStyle(this.timelineElement).getPropertyValue('--timeline-day-width')) || 0;
            const offset = body.scrollLeft - (request.introduced && this.windowPendingBefore ? width : 0);
            const top = body.scrollTop;
            if (state.frame !== null) cancelAnimationFrame(state.frame);
            state.frame = null;
            this.clearWindowRequest(state);
            body.scrollLeft = Math.max(0, offset);
            body.scrollTop = top;
            this.syncHorizontal(body, false);
            this.windowError = 'Weitere Tage konnten nicht geladen werden. Bitte erneut versuchen.';
        },
        noteWindowGesture() {
            const request = windowNavigations.get(this.timelineElement)?.request;
            if (request) request.userMoved = true;
        },
        bindWindowScrollbarIntent() {
            const footer = this.$refs.timelineScrollbar;
            if (!this.windowNavigationEnabled || typeof footer?.addEventListener !== 'function') return;
            const state = this.windowNavigationState();
            const intent = event => {
                if (event.type === 'keydown' && !['ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown', ' '].includes(event.key)) return;
                this.noteWindowGesture();
            };
            for (const name of ['pointerdown', 'wheel', 'keydown']) footer.addEventListener(name, intent, { passive: true });
            state.cleanup = () => {
                for (const name of ['pointerdown', 'wheel', 'keydown']) footer.removeEventListener(name, intent);
            };
        },
        syncWindowPeriod(width) {
            if (!this.windowNavigationEnabled || this.windowDisposed) return false;
            const root = this.timelineElement;
            const state = this.windowNavigationState();
            const period = timelineWindowPeriod(root.dataset.timelineFrom, root.dataset.timelineUntil);
            const axis = this.windowAxisPeriod();
            if (!period || !axis) {
                if (state.request) this.failWindowRequest(state.request);
                return false;
            }
            if (!state.period) {
                state.period = period;
                state.axis = axis;
            }
            if (!state.positioned) {
                state.positioned = true;
                this.$refs.timelineBody.scrollLeft = this.windowBuffer('before') * width;
                this.syncHorizontal(this.$refs.timelineBody, false);
            }
            const periodChanged = period.from !== state.period.from || period.until !== state.period.until;
            const axisChanged = axis.from !== state.axis?.from || axis.until !== state.axis?.until;
            if (!periodChanged && !axisChanged) return false;
            const previousAxis = state.axis;
            state.period = period;
            state.axis = axis;
            this.windowError = '';
            this.focusDayIndex = -1;
            if (state.frame !== null) cancelAnimationFrame(state.frame);
            state.frame = null;
            // Only confirmed server dates enter the URL; mounting never changes it.
            const url = timelinePeriodUrl(window.location?.href, period.from, period.until);
            if (periodChanged && url && window.history?.replaceState && url !== window.location.href) {
                window.history.replaceState(window.history.state, '', url);
                this.$dispatch?.('rt-workspace-url-updated');
            }
            const request = state.request;
            const body = this.$refs.timelineBody;
            const offset = body.scrollLeft;
            const top = body.scrollTop;
            const ghostBefore = request?.introduced && this.windowPendingBefore ? 1 : 0;
            const expected = request && period.from === request.next.from && period.until === request.next.until;
            this.clearWindowRequest(state);
            // A date-picker/calendar change is newer intent, not our requested edge.
            if (!expected) {
                body.scrollLeft = this.windowBuffer('before') * width;
                body.scrollTop = top;
                this.syncHorizontal(body, false);
                return true;
            }
            const maximum = Math.max(0, body.scrollWidth - body.clientWidth);
            const delta = timelineCivilDay(axis.from) - timelineCivilDay(previousAxis.from);
            const compensation = (delta + ghostBefore) * width;
            body.scrollTop = top;
            body.scrollLeft = Math.max(0, Math.min(maximum, offset - compensation));
            this.syncHorizontal(body, false);
            const remainingTarget = request.target === null ? null : Math.max(0, Math.min(maximum, request.target - compensation));
            state.frame = requestAnimationFrame(() => {
                state.frame = null;
                if (this.windowDisposed || root.dataset.timelineFrom !== period.from || root.dataset.timelineUntil !== period.until) return;
                if (request.userMoved || remainingTarget === null || Math.abs(body.scrollLeft - remainingTarget) < 1) return;
                this.holdHorizontalIntent(request.direction);
                // Finish an interrupted first movement, not another one-day step.
                body.scrollTo({ left: remainingTarget,
                    behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
            });
            return true;
        },
        async shiftWindow(direction) {
            if (!this.windowNavigationEnabled || this.windowLoading || this.windowDisposed) return;
            const root = this.timelineElement;
            const previous = timelineWindowPeriod(root.dataset.timelineFrom, root.dataset.timelineUntil);
            const next = previous && timelineShiftPeriod(previous.from, previous.until, direction);
            const axis = this.windowAxisPeriod();
            const pendingDate = axis && timelineDateOffset(direction < 0 ? axis.from : axis.until, direction);
            if (!next || !axis || !pendingDate) return;
            const state = this.windowNavigationState();
            const body = this.$refs.timelineBody;
            // Capture before the reactive ghost prefix grows the DOM: native snap/
            // anchoring may already add one width before our nextTick callback.
            const request = { previous, next, direction, offset: body.scrollLeft, introduced: false, target: null, userMoved: false };
            state.request = request;
            this.windowLoading = true;
            this.windowError = '';
            this.windowPendingDate = pendingDate;
            this.windowPendingBefore = direction < 0;
            this.windowPendingAfter = direction > 0;
            this.holdHorizontalIntent(direction);
            this.$nextTick(() => {
                if (this.windowDisposed || state.request !== request || root.dataset.timelineFrom !== previous.from || root.dataset.timelineUntil !== previous.until) return;
                const width = parseFloat(getComputedStyle(root).getPropertyValue('--timeline-day-width'));
                if (!width) return;
                // A temporary unknown outer column is outside the preloaded data.
                // Its prefix shifts the old axis; compensate before showing the next
                // already-loaded day immediately, without waiting on the response.
                body.scrollLeft = request.offset + (direction < 0 ? width : 0);
                request.introduced = true;
                this.syncHorizontal(body, false);
                request.target = Math.max(0, Math.min(body.scrollWidth - body.clientWidth, body.scrollLeft + direction * width));
                // Mandatory snap first settles the temporary prefix/instant anchor.
                // Starting smooth scrolling in that same layout turn can otherwise
                // be cancelled by Chromium's pending automatic snap adjustment.
                state.frame = requestAnimationFrame(() => {
                    state.frame = null;
                    if (this.windowDisposed || state.request !== request || request.userMoved
                        || root.dataset.timelineFrom !== previous.from || root.dataset.timelineUntil !== previous.until) return;
                    this.holdHorizontalIntent(direction);
                    body.scrollTo({ left: request.target,
                        behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
                });
            });
            // Livewire can leave action promises pending on transport failure; do not
            // strand the navigation. A late response may update dates but not an old anchor.
            state.timer = setTimeout(() => this.failWindowRequest(request), 15000);
            try {
                const owner = window.Livewire?.find?.(root.dataset.timelinePeriodOwner);
                if (typeof owner?.applyPeriod !== 'function') throw new Error('Planning period owner unavailable');
                await owner.applyPeriod(next.from, next.until);
                if (!this.windowDisposed && state.request === request) this.queueMeasure();
            } catch {
                this.failWindowRequest(request);
            }
        },
        get personnelCompact() {
            return this.compactRequested && !this.personnelHovered && !this.personnelFocused;
        },
        setCompactRequested(compact) {
            this.compactRequested = Boolean(compact);
            this.personnelExplicit = false;
        },
        refreshPersonnelPreference(style = getComputedStyle(this.timelineElement)) {
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
                if (column && this.timelineElement.contains(column)) return true;
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
            if (Math.abs(event.deltaX) >= Math.max(1, Math.abs(event.deltaY))) {
                this.noteWindowGesture();
                const body = this.$refs.timelineBody;
                const start = body.scrollLeft;
                const edges = this.timelineScrollEdges();
                const target = Math.max(edges.first, Math.min(edges.last, start + event.deltaX));
                if (target !== start || this.windowNavigationEnabled) {
                    // The body hides its native x scrollbar for cross-browser consistency;
                    // preserve trackpad horizontal scrolling through the existing sync path.
                    event.preventDefault();
                    if (target !== start) body.scrollLeft = target;
                    if (event.deltaY) body.scrollTop += event.deltaY;
                }
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
        restorePersonnelRuntime() {
            if (this.appliedPersonnelCompact === null) return;
            const root = this.timelineElement;
            const compact = String(this.appliedPersonnelCompact);
            if (root.dataset.personnelCompact !== compact) root.dataset.personnelCompact = compact;
            // A Livewire morph replaces server-owned attributes/styles, while
            // Alpine and the active tween retain their runtime state. Reapply
            // that state before geometry reads, without restarting the tween.
            const motion = personnelMotions.get(root);
            if (motion) {
                root.dataset.personnelAnimating = 'true';
                if (parseFloat(root.style.getPropertyValue('--timeline-name-width')) !== motion.width) {
                    root.style.setProperty('--timeline-name-width', `${motion.width}px`);
                }
            } else {
                delete root.dataset.personnelAnimating;
                root.style.removeProperty('--timeline-name-width');
            }
        },
        applyPersonnelMode() {
            if (this.personnelDefaultCompact === null) this.refreshPersonnelPreference();
            this.restorePersonnelRuntime();
            const compact = this.personnelCompact;
            if (this.appliedPersonnelCompact === compact) return;
            const root = this.timelineElement;
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
            const previousEdges = this.timelineScrollEdges(true);
            // A pending arrow is crossing the selected edge, not anchoring the
            // employee-column animation to the still-old selected window.
            const atEnd = !windowNavigations.get(root)?.request && previousEdges.last > previousEdges.first
                && Math.abs(previousOffset - previousEdges.last) < 1;
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
            if (personnelMotions.get(this.timelineElement) !== motion) return;
            const offset = this.$refs.timelineBody.scrollLeft;
            clearPersonnelMotion(this.timelineElement);
            this.rebasePersonnelLayout(motion.atEnd, offset);
        },
        rebasePersonnelLayout(atEnd, previousOffset) {
            const body = this.$refs.timelineBody;
            const edges = this.timelineScrollEdges();
            const pinEnd = atEnd && !windowNavigations.get(this.timelineElement)?.request;
            const expectedOffset = pinEnd ? edges.last : Math.max(edges.first, Math.min(previousOffset, edges.last));
            // Preserve the final expectation for a delayed native snap event too.
            this.layoutScrollOffset = pinEnd || Math.abs(expectedOffset - previousOffset) >= 1 ? expectedOffset : null;
            // The loaded reserve means the browser's physical end is not the
            // selected end. Only embedded buffered timelines need this rebase.
            if (this.windowNavigationEnabled && Math.abs(body.scrollLeft - expectedOffset) >= 1) body.scrollLeft = expectedOffset;
            this.syncHorizontal(body, false);
        },
        init() {
            this.windowRoot = this.$el;
            this.windowDisposed = false;
            clearStaffTimelineReveals(this.timelineElement);
            this.refreshPersonnelPreference();
            if (this.windowNavigationEnabled) this.windowNavigationState();
            this.bindWindowScrollbarIntent();
            this.observer = new ResizeObserver(() => this.queueMeasure());
            this.observer.observe(this.$refs.timelineBody);
            this.observer.observe(this.$refs.timelineGrid);
            this.contentObserver = new MutationObserver(() => this.queueMeasure());
            this.contentObserver.observe(this.$refs.timelineGrid, { childList: true, subtree: true });
            this.windowObserver = new MutationObserver(() => this.queueMeasure());
            this.windowObserver.observe(this.timelineElement, { attributes: true,
                attributeFilter: ['data-timeline-from', 'data-timeline-until', 'data-timeline-axis-from', 'data-timeline-axis-until'] });
            this.$nextTick(() => this.measure());
        },
        destroy() {
            this.windowDisposed = true;
            const state = windowNavigations.get(this.timelineElement);
            if (state) {
                this.clearWindowRequest(state);
                if (state.frame !== null) cancelAnimationFrame(state.frame);
                state.cleanup?.();
                windowNavigations.delete(this.timelineElement);
            }
            this.finishHorizontalIntent();
            this.observer?.disconnect();
            this.contentObserver?.disconnect();
            this.windowObserver?.disconnect();
            if (this.resizeFrame !== null) cancelAnimationFrame(this.resizeFrame);
            clearPersonnelMotion(this.timelineElement);
            clearStaffTimelineReveals(this.timelineElement);
        },
        queueMeasure() {
            if (this.windowDisposed || this.resizeFrame !== null) return;
            this.resizeFrame = requestAnimationFrame(() => {
                this.resizeFrame = null;
                this.measure();
            });
        },
        measure() {
            const body = this.$refs.timelineBody;
            const root = this.timelineElement;
            this.restorePersonnelRuntime();
            const style = getComputedStyle(root);
            this.refreshPersonnelPreference(style);
            // The time scale stays identical when only the employee column changes.
            const fullNameWidth = parseFloat(style.getPropertyValue('--timeline-name-full-width'))
                || parseFloat(style.getPropertyValue('--timeline-name-width'));
            const days = parseInt(style.getPropertyValue('--timeline-days'), 10) || 1;
            const layout = `${body.clientWidth}:${fullNameWidth}:${days}`;
            const resized = this.measuredLayout !== null && this.measuredLayout !== layout;
            const wasAtEnd = this.canScrollLeft && !this.canScrollRight;
            this.measuredLayout = layout;
            const motion = personnelMotions.get(root);
            // Let the responsive CSS tokens own the endpoint after a breakpoint change.
            if (motion && Math.abs(motion.fullWidth - fullNameWidth) >= 1) this.finishPersonnelMotion(motion);
            const available = body.clientWidth - fullNameWidth;
            if (available <= 0) return;
            const selectedDays = this.windowNavigationEnabled ? parseInt(root.dataset.timelineSelectedDays, 10) || days : days;
            const width = timelineDayWidth(available,
                parseFloat(style.getPropertyValue('--timeline-day-min-width')) || 190,
                selectedDays);
            root.style.setProperty('--timeline-day-width', `${width}px`);
            root.style.setProperty('--timeline-gutter', `${body.offsetWidth - body.clientWidth}px`);
            this.measureEventLabels(width * days);
            if (resized) this.rebasePersonnelLayout(wasAtEnd, body.scrollLeft);
            else this.syncHorizontal(body, false);
            if (!this.syncWindowPeriod(width)) this.followFocusDay(body, width);
            revealStaffTimeline(root, this.$refs.timelineGrid, body);
        },
        // The shift chosen in "Noch zu verteilen" may lie outside the visible days: bring its day into view once.
        followFocusDay(body, width) {
            const header = this.$refs.timelineHeader;
            if (typeof header?.querySelectorAll !== 'function' || typeof body.scrollTo !== 'function') return;
            const heads = [...header.querySelectorAll('.rt-personnel-timeline-header-days > .rt-personnel-timeline-head')];
            const index = heads.findIndex(head => head.dataset.focus === 'true');
            if (index === this.focusDayIndex) return;
            this.focusDayIndex = index;
            const target = timelineFocusOffset(index, width, body.scrollLeft, header.clientWidth,
                body.scrollWidth - body.clientWidth);
            if (target === null) return;
            this.holdHorizontalIntent(target - body.scrollLeft);
            body.scrollTo({ left: target, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
        },
        measureEventLabels(periodWidth) {
            for (const track of this.$refs.timelineGrid.querySelectorAll('.rt-personnel-timeline-track')) {
                const events = [...track.querySelectorAll('.rt-personnel-timeline-event')];
                if (!events.length) continue;
                const positions = timelineEventLanes(events.map(event => ({
                    start: parseFloat(event.dataset.timeStart) || 0,
                    duration: parseFloat(event.dataset.timeWidth) || 0,
                    lane: parseInt(event.dataset.timeLane, 10) || 0,
                    continuesBefore: event.dataset.continuesBefore === 'true',
                    continuesAfter: event.dataset.continuesAfter === 'true',
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
            const selectedEdges = this.timelineScrollEdges(true);
            const footerPrefix = this.windowNavigationEnabled ? selectedEdges.first : 0;
            if (source === scrollbar) {
                // Ignore our mirrored scroll event; otherwise it cancels an in-flight smooth scroll.
                if (Math.abs(scrollbar.scrollLeft - this.mirroredScrollbarLeft) < 1) return;
                // Ghost insertion/removal and delayed mirrored scroll events are
                // layout movement. During a request only actual footer input may
                // cancel the one-day target (registered by scoped input listeners).
                const pending = windowNavigations.get(this.timelineElement)?.request;
                if (pending && !pending.userMoved) return;
                if (detectDirection) this.finishHorizontalIntent();
                const footerOffset = this.windowNavigationEnabled
                    ? Math.max(0, Math.min(selectedEdges.last - selectedEdges.first, scrollbar.scrollLeft)) : scrollbar.scrollLeft;
                body.scrollLeft = footerPrefix + footerOffset;
            }
            // Reserve columns are preloaded for arrow transitions, not extra
            // selected dates. Native keyboard/snap input follows the same bound.
            const state = windowNavigations.get(this.timelineElement);
            if (this.windowNavigationEnabled && state?.positioned && !state.request) {
                const bounded = Math.max(selectedEdges.first, Math.min(selectedEdges.last, body.scrollLeft));
                if (Math.abs(body.scrollLeft - bounded) >= 1) body.scrollLeft = bounded;
            }
            const offset = body.scrollLeft;
            const motion = personnelMotions.get(this.timelineElement);
            const motionEdge = source !== scrollbar && motion?.atEnd && !state?.request
                && Math.abs(offset - selectedEdges.last) < 1;
            const layoutScroll = motionEdge || (this.layoutScrollOffset !== null && Math.abs(offset - this.layoutScrollOffset) < 1);
            const moved = Math.abs(offset - this.lastScrollLeft) >= 1;
            if (detectDirection && (layoutScroll || moved)) this.layoutScrollOffset = null;
            if (detectDirection && !layoutScroll && moved && this.horizontalIntent === null) {
                this.setCompactRequested(offset > this.lastScrollLeft);
            }
            if (detectDirection && this.horizontalIntent !== null) this.queueIntentRelease();
            this.lastScrollLeft = offset;
            header.scrollLeft = offset;
            const footerOffset = this.windowNavigationEnabled
                ? Math.max(0, Math.min(selectedEdges.last - selectedEdges.first, offset - footerPrefix)) : offset;
            if (Math.abs(scrollbar.scrollLeft - footerOffset) >= 1) scrollbar.scrollLeft = footerOffset;
            this.mirroredScrollbarLeft = scrollbar.scrollLeft;
            this.canScrollLeft = offset > selectedEdges.first + 1;
            this.canScrollRight = offset < selectedEdges.last - 1;
        },
        scrollDay(direction) {
            if (![-1, 1].includes(direction) || this.windowLoading || this.windowDisposed) return;
            const body = this.$refs.timelineBody;
            const width = parseFloat(getComputedStyle(this.timelineElement).getPropertyValue('--timeline-day-width'));
            if (!width) return;
            const edges = this.timelineScrollEdges(true);
            const atEdge = direction < 0 ? body.scrollLeft <= edges.first + 1 : body.scrollLeft >= edges.last - 1;
            if (atEdge && this.windowNavigationEnabled) return this.shiftWindow(direction);
            this.holdHorizontalIntent(direction);
            body.scrollTo({
                left: Math.max(edges.first, Math.min(edges.last, timelineDayOffset(body.scrollLeft, width, direction, edges.last))),
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
            });
        },
    };
}
