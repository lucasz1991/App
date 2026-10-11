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
const TIMELINE_DAY_LABEL = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', timeZone: 'UTC' });

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

const plainTimelineObject = value => value !== null && typeof value === 'object' && !Array.isArray(value)
    && [Object.prototype, null].includes(Object.getPrototypeOf(value));

function timelineSnapshotValue(payload) {
    // Mirror Livewire's synthesized public-data tuples without modifying its
    // signed snapshot, including nested arrays, collections and scalar casts.
    const value = Array.isArray(payload) && payload.length === 2 && plainTimelineObject(payload[1])
        && Object.hasOwn(payload[1], 's') ? payload[0] : payload;
    if (Array.isArray(value)) return value.map(timelineSnapshotValue);
    if (plainTimelineObject(value)) return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, timelineSnapshotValue(item)]));
    return value;
}

function timelineOrderedValueEquals(left, right) {
    if (typeof left !== typeof right) return false;
    if (left === null || right === null || typeof left !== 'object') return Object.is(left, right);
    if (Array.isArray(left) !== Array.isArray(right)) return false;
    if (!Array.isArray(left) && (!plainTimelineObject(left) || !plainTimelineObject(right))) return false;
    if (Array.isArray(left) && left.length !== right.length) return false;
    const leftKeys = Object.keys(left);
    const rightKeys = Object.keys(right);
    return leftKeys.length === rightKeys.length && leftKeys.every((key, index) => key === rightKeys[index]
        && timelineOrderedValueEquals(left[key], right[key]));
}

export function timelineDiscardUnchangedRootUpdate(commit) {
    if (!plainTimelineObject(commit?.updates) || !Object.hasOwn(commit.updates, '') || !plainTimelineObject(commit.updates[''])) return false;
    try {
        const snapshot = typeof commit.snapshot === 'string' ? JSON.parse(commit.snapshot) : commit.snapshot;
        if (!plainTimelineObject(snapshot?.data)) return false;
        const canonical = timelineSnapshotValue(snapshot.data);
        const root = commit.updates[''];
        const keys = Object.keys(canonical);
        // Livewire 3.8's diff emits an invalid empty property path when public
        // root keys reorder after a morph. Discard only a complete, proven no-op:
        // root key order may differ, but every nested order/type/value must match.
        if (keys.length !== Object.keys(root).length || !keys.every(key => Object.hasOwn(root, key)
            && timelineOrderedValueEquals(canonical[key], root[key]))) return false;
        delete commit.updates[''];
        return true;
    } catch {
        // An incomplete/unsupported payload is not evidence of a no-op. Let the
        // existing Livewire/server checks handle it without dropping any update.
        return false;
    }
}

// Scroll target for an offscreen day, or null when any part is already visible.
export function timelineFocusOffset(index, width, offset, visible, maximum) {
    if (index < 0 || ![width, offset, visible, maximum].every(Number.isFinite) || width <= 0 || visible <= 0 || maximum < 0) return null;
    const start = index * width;
    // A day that is already visible, even partly, must remain exactly in place.
    if (start < offset + visible && start + width > offset) return null;
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
const distributionFocusMotions = new WeakMap();
const contentReveals = new WeakMap();
// Requests/anchors stay outside Alpine's proxy, so their identities survive a morph.
const windowNavigations = new WeakMap();

function timelineEventGeometryKey(event) {
    return ['timeStart', 'timeWidth', 'timeLane', 'continuesBefore', 'continuesAfter']
        .map(name => event.dataset[name] || '').join(':') + `:${event.querySelector('.rt-personnel-timeline-time-text')?.textContent || ''}`;
}

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
        state = { seen: new WeakSet(), active: new Set(), media, reduce: null, axis: null };
        state.reduce = () => {
            if (media?.matches) [...state.active].forEach(batch => finishRevealBatch(state, batch));
        };
        media?.addEventListener?.('change', state.reduce);
        contentReveals.set(root, state);
    }
    const unseen = [...grid.querySelectorAll('.rt-personnel-timeline-name, .rt-personnel-timeline-event')]
        .filter(target => !state.seen.has(target));
    unseen.forEach(target => state.seen.add(target));
    const axis = `${root.dataset.timelineAxisFrom || root.dataset.timelineFrom || ''}:${root.dataset.timelineAxisUntil || root.dataset.timelineUntil || ''}`;
    const windowChanged = state.axis !== null && state.axis !== axis;
    state.axis = axis;
    // Navigation data is already positioned on the stable scaffold. Replaying a
    // multi-second row entrance here hides loaded duties and makes a morph jump.
    if (windowChanged) {
        [...state.active].forEach(batch => finishRevealBatch(state, batch));
        return;
    }
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
        geometryCleanup: null,
        eventGeometryDirty: true,
        eventPeriodWidth: null,
        windowObserver: null,
        windowRoot: null,
        windowLoading: false,
        windowRevision: 0,
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
        get windowDisplayPeriod() {
            this.windowRevision;
            return this.windowNavigationState().wanted;
        },
        windowDisplayAxis() {
            if (!this.windowNavigationEnabled) return this.windowAxisPeriod();
            const period = this.windowDisplayPeriod;
            if (!period) return this.windowAxisPeriod();
            return { from: timelineDateOffset(period.from, -3) || '0001-01-01',
                until: timelineDateOffset(period.until, 3) || '9999-12-31' };
        },
        get windowScaffoldDays() {
            this.windowRevision;
            const axis = this.windowDisplayAxis();
            const loaded = this.windowAxisPeriod();
            if (!axis) return [];
            const state = this.windowNavigationState();
            const key = `${axis.from}:${axis.until}:${loaded?.from}:${loaded?.until}:${this.timelineElement.dataset.timelineFocusDate || ''}`;
            if (state.scaffoldKey === key) return state.scaffoldDays;
            const start = timelineCivilDay(axis.from);
            const end = timelineCivilDay(axis.until);
            const loadedFrom = loaded && timelineCivilDay(loaded.from);
            const loadedUntil = loaded && timelineCivilDay(loaded.until);
            state.scaffoldKey = key;
            state.scaffoldDays = Array.from({ length: Math.max(0, Math.min(100, end - start + 1)) }, (_, index) => {
                const day = start + index;
                const value = new Date(day * CIVIL_DAY_MILLISECONDS);
                const date = value.toISOString().slice(0, 10);
                return { date, label: TIMELINE_DAY_LABEL.format(value), weekend: [0, 6].includes(value.getUTCDay()),
                    pending: loadedFrom === null || loadedUntil === null || day < loadedFrom || day > loadedUntil,
                    focus: date === this.timelineElement.dataset.timelineFocusDate };
            });
            return state.scaffoldDays;
        },
        get windowDisplayDayCount() { return this.windowScaffoldDays.length; },
        get windowDisplaySelectedDayCount() {
            const period = this.windowDisplayPeriod;
            return period ? timelineCivilDay(period.until) - timelineCivilDay(period.from) + 1 : 0;
        },
        get windowBeforeDays() {
            if (!this.windowNavigationEnabled) return this.windowBuffer('before');
            const axis = this.windowDisplayAxis();
            const period = this.windowDisplayPeriod;
            return axis && period ? timelineCivilDay(period.from) - timelineCivilDay(axis.from) : this.windowBuffer('before');
        },
        get windowAfterDays() {
            if (!this.windowNavigationEnabled) return this.windowBuffer('after');
            const axis = this.windowDisplayAxis();
            const period = this.windowDisplayPeriod;
            return axis && period ? timelineCivilDay(axis.until) - timelineCivilDay(period.until) : this.windowBuffer('after');
        },
        get windowContentOffset() {
            this.windowRevision;
            if (!this.windowNavigationEnabled) return 0;
            const loaded = this.windowAxisPeriod();
            const axis = this.windowDisplayAxis();
            return loaded && axis ? timelineCivilDay(loaded.from) - timelineCivilDay(axis.from) : 0;
        },
        get windowContentLoading() {
            this.windowRevision;
            if (!this.windowNavigationEnabled) return false;
            const period = this.windowDisplayPeriod;
            const confirmed = this.windowNavigationState().period;
            return this.windowLoading || Boolean(period && confirmed && (period.from !== confirmed.from || period.until !== confirmed.until));
        },
        windowDayLoading(date) {
            return this.windowScaffoldDays.some(day => day.date === date && day.pending);
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
                const period = timelineWindowPeriod(root.dataset.timelineFrom, root.dataset.timelineUntil);
                state = { period, wanted: period, axis: this.windowAxisPeriod(), positioned: false,
                    request: null, activeTransport: null, settlingTransport: null, retryRequested: false,
                    ownPeriods: new Set(), motionTarget: null, displayVersion: 0, busyCommits: 0,
                    lifecycleTracked: false, requestFrame: null, commitCleanup: null,
                    timer: null, frame: null, cleanup: null };
                windowNavigations.set(root, state);
            }
            return state;
        },
        timelineScrollEdges(selected = false) {
            const body = this.$refs.timelineBody;
            const maximum = Math.max(0, body.scrollWidth - body.clientWidth);
            if (!this.windowNavigationEnabled) {
                return { first: 0, last: maximum };
            }
            const width = parseFloat(getComputedStyle(this.timelineElement).getPropertyValue('--timeline-day-width')) || 0;
            return timelineBufferedEdges(width, maximum,
                this.windowBeforeDays, this.windowAfterDays);
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
            this.clearWindowRequest(state);
            state.waiter?.reject(new Error('Weitere Tage konnten nicht geladen werden.'));
            state.waiter = null;
            this.windowError = 'Weitere Tage konnten nicht geladen werden. Bitte erneut versuchen.';
            this.windowRevision++;
        },
        noteWindowGesture() {
            this.cancelDistributionFocus();
            const state = windowNavigations.get(this.timelineElement);
            if (state) state.motionTarget = null;
            const request = state?.request;
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
        bindWindowCommits() {
            if (!this.windowNavigationEnabled || typeof window.Livewire?.hook !== 'function') return;
            const state = this.windowNavigationState();
            if (state.lifecycleTracked) return;
            state.lifecycleTracked = true;
            const root = this.timelineElement;
            state.commitCleanup = window.Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                if (this.windowDisposed || ![root.dataset.timelineId, root.dataset.timelinePeriodOwner].includes(component.id)) return;
                if (component.id === root.dataset.timelinePeriodOwner) timelineDiscardUnchangedRootUpdate(commit);
                state.busyCommits++;
                let finished = false;
                const complete = (failed) => {
                    if (finished || this.windowDisposed) return;
                    finished = true;
                    state.busyCommits = Math.max(0, state.busyCommits - 1);
                    const request = state.activeTransport || state.settlingTransport;
                    const ownCall = component.id === root.dataset.timelinePeriodOwner && request && commit.calls?.some(call =>
                        call.method === 'applyPeriod' && call.params?.[0] === request.next.from && call.params?.[1] === request.next.until);
                    if (failed && ownCall) {
                        state.activeTransport = null;
                        state.settlingTransport = null;
                        this.failWindowRequest(request);
                    }
                    this.queueWindowDrain();
                };
                succeed(() => complete(false));
                fail(() => complete(true));
            });
        },
        queueWindowDrain() {
            if (this.windowDisposed) return;
            const state = this.windowNavigationState();
            if (state.requestFrame !== null) return;
            // Livewire's success hook runs before the request pool removes itself.
            // A frame also lets queued 5ms child commits register before we ask
            // the parent for another reactive period, avoiding separate stale pools.
            state.requestFrame = requestAnimationFrame(() => {
                state.requestFrame = null;
                if (this.windowDisposed || state.busyCommits || state.settlingTransport) return;
                this.drainWindowRequest();
            });
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
            state.period = period;
            state.axis = axis;
            const request = state.request;
            const body = this.$refs.timelineBody;
            const top = body.scrollTop;
            const expected = request && period.from === request.next.from && period.until === request.next.until;
            const owned = state.ownPeriods.has(`${period.from}:${period.until}`);
            if (state.activeTransport?.next.from === period.from && state.activeTransport.next.until === period.until) {
                if (state.lifecycleTracked && !state.activeTransport.settled) state.settlingTransport = state.activeTransport;
                state.activeTransport = null;
                state.retryRequested = false;
            }
            // An older serialized response updates data, never a newer viewport.
            if (expected) this.clearWindowRequest(state);
            if (!expected && !owned) {
                this.cancelDistributionFocus();
                if (state.frame !== null) cancelAnimationFrame(state.frame);
                state.frame = null;
                this.clearWindowRequest(state);
                state.activeTransport = null;
                state.settlingTransport = null;
                state.waiter?.reject(new Error('Zeitraum wurde durch eine neuere Auswahl ersetzt.'));
                state.waiter = null;
                state.wanted = period;
                state.displayVersion++;
                state.motionTarget = null;
                body.scrollLeft = this.windowBeforeDays * width;
                body.scrollTop = top;
                this.syncHorizontal(body, false);
            }
            const current = state.wanted?.from === period.from && state.wanted?.until === period.until;
            if (current) {
                this.windowError = '';
                const url = timelinePeriodUrl(window.location?.href, period.from, period.until);
                if (periodChanged && url && window.history?.replaceState && url !== window.location.href) {
                    window.history.replaceState(window.history.state, '', url);
                    this.$dispatch?.('rt-workspace-url-updated');
                }
                state.waiter?.resolve();
                state.waiter = null;
            }
            this.windowRevision++;
            if (!current && !state.request && !state.activeTransport) {
                if (state.lifecycleTracked) this.queueWindowDrain();
                else this.drainWindowRequest();
            }
            return true;
        },
        paintWindowPeriod(period) {
            const state = this.windowNavigationState();
            state.wanted = period;
            state.displayVersion++;
            this.windowError = '';
            this.windowRevision++;
            this.timelineElement.style.setProperty('--timeline-selected-days', this.windowDisplaySelectedDayCount);
            this.timelineElement.style.setProperty('--timeline-display-days', this.windowDisplayDayCount);
            this.timelineElement.style.setProperty('--timeline-content-offset', this.windowContentOffset);
            this.$dispatch?.('operations-timeline-window-pending', { timelineId: this.timelineElement.dataset.timelineId });
            return state.displayVersion;
        },
        requestWindowPeriod(event) {
            const detail = event?.detail;
            if (!this.windowNavigationEnabled || this.windowDisposed || !detail || detail.owner !== this.timelineElement.dataset.timelinePeriodOwner) return;
            const period = timelineWindowPeriod(detail.from, detail.until);
            if (!period) return;
            this.cancelDistributionFocus();
            detail.handled = true;
            const state = this.windowNavigationState();
            state.waiter?.reject(new Error('Zeitraum wurde durch eine neuere Auswahl ersetzt.'));
            detail.promise = new Promise((resolve, reject) => { state.waiter = { resolve, reject }; });
            const version = this.paintWindowPeriod(period);
            state.motionTarget = null;
            if (state.frame !== null) cancelAnimationFrame(state.frame);
            state.frame = null;
            this.$nextTick(() => {
                if (this.windowDisposed || state.displayVersion !== version) return;
                const width = parseFloat(getComputedStyle(this.timelineElement).getPropertyValue('--timeline-day-width')) || 0;
                this.$refs.timelineBody.scrollLeft = this.windowBeforeDays * width;
                this.syncHorizontal(this.$refs.timelineBody, false);
                this.queueMeasure();
            });
            if (!state.request && period.from === state.period?.from && period.until === state.period?.until) {
                state.waiter.resolve(); state.waiter = null;
            } else this.drainWindowRequest();
        },
        retryWindow() {
            const state = this.windowNavigationState();
            state.retryRequested = true;
            this.windowError = '';
            return this.drainWindowRequest();
        },
        async drainWindowRequest() {
            if (!this.windowNavigationEnabled || this.windowDisposed) return;
            const root = this.timelineElement;
            const state = this.windowNavigationState();
            const next = state.wanted;
            if (state.request || state.activeTransport || state.settlingTransport || state.busyCommits || this.windowError
                || !next || (next.from === state.period?.from && next.until === state.period?.until)) return;
            const request = { next, userMoved: false, settled: false };
            state.request = request;
            state.activeTransport = request;
            state.retryRequested = false;
            state.ownPeriods.add(`${next.from}:${next.until}`);
            if (state.ownPeriods.size > 12) state.ownPeriods.delete(state.ownPeriods.values().next().value);
            this.windowLoading = true;
            this.windowRevision++;
            state.timer = setTimeout(() => this.failWindowRequest(request), 15000);
            try {
                const owner = window.Livewire?.find?.(root.dataset.timelinePeriodOwner);
                if (typeof owner?.applyPeriod !== 'function') throw new Error('Planning period owner unavailable');
                await owner.applyPeriod(next.from, next.until);
                request.settled = true;
                if (state.settlingTransport === request) {
                    state.settlingTransport = null;
                    this.queueWindowDrain();
                }
                if (!this.windowDisposed && state.request === request) this.queueMeasure();
            } catch {
                if (state.activeTransport === request) state.activeTransport = null;
                if (state.settlingTransport === request) state.settlingTransport = null;
                this.failWindowRequest(request);
                if (!this.windowDisposed && state.retryRequested && !state.request) this.drainWindowRequest();
            }
        },
        shiftWindow(direction, steps = 1, wheelDelta = null) {
            if (!this.windowNavigationEnabled || this.windowDisposed || ![-1, 1].includes(direction)) return;
            this.cancelDistributionFocus();
            const root = this.timelineElement;
            const state = this.windowNavigationState();
            const previous = state.wanted;
            let next = previous;
            let moved = 0;
            for (let index = 0; index < Math.min(100, Math.max(1, steps)); index++) {
                const candidate = next && timelineShiftPeriod(next.from, next.until, direction);
                if (!candidate) break;
                next = candidate; moved++;
            }
            if (!next || !moved) return;
            const body = this.$refs.timelineBody;
            const offset = body.scrollLeft;
            const targetOffset = state.motionTarget ?? offset;
            const width = parseFloat(getComputedStyle(root).getPropertyValue('--timeline-day-width')) || 0;
            const oldAxis = this.windowDisplayAxis();
            const version = this.paintWindowPeriod(next);
            const delta = timelineCivilDay(this.windowDisplayAxis().from) - timelineCivilDay(oldAxis.from);
            state.waiter?.reject(new Error('Zeitraum wurde durch eine neuere Navigation ersetzt.'));
            state.waiter = null;
            if (state.frame !== null) cancelAnimationFrame(state.frame);
            state.frame = null;
            this.holdHorizontalIntent(direction);
            this.$nextTick(() => {
                if (this.windowDisposed || state.displayVersion !== version) return;
                if (!width) return;
                const edges = this.timelineScrollEdges(true);
                const target = Math.max(edges.first, Math.min(edges.last, wheelDelta === null ? targetOffset : offset + wheelDelta - delta * width));
                body.scrollLeft = Math.max(edges.first, Math.min(edges.last, offset - delta * width));
                this.syncHorizontal(body, false);
                state.motionTarget = target;
                state.frame = requestAnimationFrame(() => {
                    state.frame = null;
                    if (this.windowDisposed || state.displayVersion !== version) return;
                    this.holdHorizontalIntent(direction);
                    body.scrollTo({ left: target,
                        behavior: wheelDelta !== null || matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
                });
            });
            return this.drainWindowRequest();
        },
        get personnelCompact() {
            return this.compactRequested;
        },
        setCompactRequested(compact) {
            this.compactRequested = Boolean(compact);
            this.personnelExplicit = true;
        },
        refreshPersonnelPreference(style = getComputedStyle(this.timelineElement)) {
            const compact = (style.getPropertyValue('--timeline-personnel-default-compact') || '').trim() === '1';
            const previous = this.personnelDefaultCompact;
            if (previous === compact) return;
            this.personnelDefaultCompact = compact;
            // Only the initial layout selects a responsive default. Neither a
            // resize nor scrolling/focusing/hovering may change the user's mode.
            if (previous === null && !this.personnelExplicit) {
                this.compactRequested = compact;
            }
        },
        togglePersonnelColumn() {
            const compact = !this.personnelCompact;
            this.finishHorizontalIntent();
            this.setCompactRequested(compact);
        },
        wheelPersonnel(event) {
            this.cancelDistributionFocus();
            if (Math.abs(event.deltaX) >= Math.max(1, Math.abs(event.deltaY))) {
                this.noteWindowGesture();
                const body = this.$refs.timelineBody;
                const start = body.scrollLeft;
                const edges = this.timelineScrollEdges();
                const unbounded = start + event.deltaX;
                if (this.windowNavigationEnabled && (unbounded < edges.first || unbounded > edges.last)) {
                    event.preventDefault();
                    if (event.deltaY) body.scrollTop += event.deltaY;
                    const width = parseFloat(getComputedStyle(this.timelineElement).getPropertyValue('--timeline-day-width')) || 1;
                    const overflow = unbounded < edges.first ? edges.first - unbounded : unbounded - edges.last;
                    this.shiftWindow(event.deltaX < 0 ? -1 : 1, Math.ceil(overflow / width), event.deltaX);
                    return;
                }
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
            this.queueIntentRelease();
        },
        queueIntentRelease() {
            if (this.intentTimer !== null) clearTimeout(this.intentTimer);
            // Also covers older browsers and an edge gesture that does not scroll.
            this.intentTimer = setTimeout(() => this.finishHorizontalIntent(), 350);
        },
        finishHorizontalIntent() {
            // Native scrollend and the ordinary 350ms intent fallback may fire
            // during a focus tween; its guarded completion owns that release.
            if (distributionFocusMotions.has(this.timelineElement)) return;
            if (this.intentTimer !== null) clearTimeout(this.intentTimer);
            this.intentTimer = null;
            this.horizontalIntent = null;
            const state = windowNavigations.get(this.timelineElement);
            if (state) state.motionTarget = null;
            if (this.$refs?.timelineBody) this.lastScrollLeft = this.$refs.timelineBody.scrollLeft;
        },
        preserveMorphGeometry({ el, toEl }) {
            const root = this.timelineElement;
            if (this.windowDisposed || !toEl?.style) return;
            if (el !== root) {
                const event = el?.classList?.contains('rt-personnel-timeline-event');
                const track = el?.classList?.contains('rt-personnel-timeline-track');
                if ((!event && !track) || !root.contains(el)) return;
                if (event && toEl.classList?.contains('rt-personnel-timeline-event')
                    && timelineEventGeometryKey(el) === timelineEventGeometryKey(toEl)) {
                    for (const name of ['--event-lane', '--time-label-offset', '--time-badge-width']) {
                        const value = el.style.getPropertyValue(name);
                        if (value !== undefined && value !== '') toEl.style.setProperty(name, value);
                    }
                } else if (track && toEl.classList?.contains('rt-personnel-timeline-track')
                    && el.dataset.timelineLanes === toEl.dataset.timelineLanes) {
                    const previous = [...el.querySelectorAll('.rt-personnel-timeline-event')].map(timelineEventGeometryKey);
                    const incoming = [...toEl.querySelectorAll('.rt-personnel-timeline-event')].map(timelineEventGeometryKey);
                    if (previous.length && previous.length === incoming.length && previous.every((key, index) => key === incoming[index])) {
                        const value = el.style.getPropertyValue('--timeline-lanes');
                        if (value !== undefined && value !== '') toEl.style.setProperty('--timeline-lanes', value);
                        toEl.dataset.timelineDensity = el.dataset.timelineDensity;
                    }
                }
                return;
            }
            // Server event styles can drop client lane/badge geometry even when
            // their source percentage attributes did not change on a focus morph.
            this.eventGeometryDirty = true;
            // The server owns dates/content, the client owns the scroll geometry.
            // Preserve it before Livewire swaps attributes, not a frame later:
            // otherwise the physical width collapses to CSS defaults and the
            // browser clamps/anchors scrollLeft before measurement can restore it.
            for (const name of ['--timeline-day-width', '--timeline-gutter', '--timeline-selected-days', '--timeline-display-days', '--timeline-content-offset']) {
                const value = root.style.getPropertyValue(name);
                if (value) toEl.style.setProperty(name, value);
            }
            if (this.windowNavigationEnabled) {
                const loadedFrom = timelineCivilDay(toEl.dataset.timelineAxisFrom || toEl.dataset.timelineFrom);
                const displayFrom = timelineCivilDay(this.windowDisplayAxis()?.from);
                if (loadedFrom !== null && displayFrom !== null) toEl.style.setProperty('--timeline-content-offset', loadedFrom - displayFrom);
            }
            if (this.appliedPersonnelCompact !== null) toEl.dataset.personnelCompact = String(this.appliedPersonnelCompact);
            const motion = personnelMotions.get(root);
            if (motion) {
                toEl.style.setProperty('--timeline-name-width', `${motion.width}px`);
                toEl.dataset.personnelAnimating = 'true';
            }
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
            const atEnd = !distributionFocusMotions.has(root) && !windowNavigations.get(root)?.request && previousEdges.last > previousEdges.first
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
            const pinEnd = atEnd && !distributionFocusMotions.has(this.timelineElement) && !windowNavigations.get(this.timelineElement)?.request;
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
            this.bindWindowCommits();
            this.bindWindowScrollbarIntent();
            if (typeof window.Livewire?.hook === 'function') {
                this.geometryCleanup = window.Livewire.hook('morph.updating', detail => this.preserveMorphGeometry(detail));
            }
            this.observer = new ResizeObserver(() => this.queueMeasure());
            this.observer.observe(this.$refs.timelineBody);
            this.observer.observe(this.$refs.timelineGrid);
            this.contentObserver = new MutationObserver(() => {
                this.eventGeometryDirty = true;
                this.queueMeasure();
            });
            this.contentObserver.observe(this.$refs.timelineGrid, { childList: true, subtree: true, characterData: true,
                attributes: true, attributeFilter: ['data-time-start', 'data-time-width', 'data-time-lane', 'data-continues-before', 'data-continues-after', 'data-timeline-lanes'] });
            this.windowObserver = new MutationObserver((mutations = []) => {
                if (this.windowDisposed) return;
                // Focus attributes belong to Livewire, not Alpine's reactive
                // state. Refresh the client-owned header highlight explicitly.
                if (mutations.some(record => ['data-timeline-focus-shift-id', 'data-timeline-focus-date'].includes(record.attributeName))) {
                    this.windowRevision++;
                }
                this.queueMeasure();
            });
            this.windowObserver.observe(this.timelineElement, { attributes: true,
                attributeFilter: ['data-timeline-from', 'data-timeline-until', 'data-timeline-axis-from', 'data-timeline-axis-until',
                    'data-timeline-focus-shift-id', 'data-timeline-focus-date'] });
            this.$nextTick(() => this.measure());
        },
        destroy() {
            this.windowDisposed = true;
            this.cancelDistributionFocus();
            const state = windowNavigations.get(this.timelineElement);
            if (state) {
                this.clearWindowRequest(state);
                if (state.frame !== null) cancelAnimationFrame(state.frame);
                if (state.requestFrame !== null) cancelAnimationFrame(state.requestFrame);
                state.cleanup?.();
                state.commitCleanup?.();
                state.waiter?.reject(new Error('Zeitleiste wurde geschlossen.'));
                windowNavigations.delete(this.timelineElement);
            }
            this.finishHorizontalIntent();
            this.observer?.disconnect();
            this.contentObserver?.disconnect();
            this.windowObserver?.disconnect();
            this.geometryCleanup?.();
            this.geometryCleanup = null;
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
            const display = this.windowNavigationEnabled && this.windowDisplayPeriod;
            const selectedDays = display ? timelineCivilDay(display.until) - timelineCivilDay(display.from) + 1 : days;
            const width = timelineDayWidth(available,
                parseFloat(style.getPropertyValue('--timeline-day-min-width')) || 190,
                selectedDays);
            root.style.setProperty('--timeline-day-width', `${width}px`);
            root.style.setProperty('--timeline-gutter', `${body.offsetWidth - body.clientWidth}px`);
            if (this.windowNavigationEnabled) {
                root.style.setProperty('--timeline-selected-days', selectedDays);
                root.style.setProperty('--timeline-display-days', this.windowDisplayDayCount);
                root.style.setProperty('--timeline-content-offset', this.windowContentOffset);
            }
            if (this.eventGeometryDirty || this.eventPeriodWidth !== width * days) this.measureEventLabels(width * days);
            if (resized) this.rebasePersonnelLayout(wasAtEnd, body.scrollLeft);
            else this.syncHorizontal(body, false);
            this.syncWindowPeriod(width);
            revealStaffTimeline(root, this.$refs.timelineGrid, body);
        },
        requestDistributionFocus(detail) {
            const root = this.timelineElement;
            const owner = root.dataset.timelinePeriodOwner;
            if (this.windowDisposed || !owner || detail?.owner !== owner || !Number.isSafeInteger(detail.shiftId)
                || detail.shiftId <= 0 || timelineCivilDay(detail.date) === null || typeof detail.selected !== 'boolean') return false;
            this.cancelDistributionFocus();
            if (detail.selected) return true;
            // The clicked date is already rendered presentation state. Navigate
            // now; selection, ranking and assignment still follow server truth.
            const body = this.$refs.timelineBody;
            const header = this.$refs.timelineHeader;
            const width = parseFloat(getComputedStyle(root).getPropertyValue('--timeline-day-width'));
            if (typeof header?.querySelectorAll !== 'function' || typeof body?.scrollTo !== 'function'
                || ![width, body.scrollLeft, header.clientWidth, body.scrollWidth, body.clientWidth].every(Number.isFinite)
                || width <= 0 || header.clientWidth <= 0 || body.clientWidth <= 0 || body.scrollWidth < body.clientWidth) return true;
            const heads = [...header.querySelectorAll('.rt-personnel-timeline-header-days > .rt-personnel-timeline-head')];
            const index = heads.findIndex(head => head.dataset.timelineDate === detail.date);
            const requested = timelineFocusOffset(index, width, body.scrollLeft, header.clientWidth,
                body.scrollWidth - body.clientWidth);
            if (requested === null) return true;
            const edges = this.timelineScrollEdges(true);
            const target = Math.max(edges.first, Math.min(edges.last, requested));
            if (Math.abs(target - body.scrollLeft) < 1) return true;
            const personnel = personnelMotions.get(root);
            // A previous width tween must not later restore its old right edge
            // after this explicit navigation (including instant/reduced motion).
            if (personnel) personnel.atEnd = false;
            this.finishHorizontalIntent();
            const reduceQuery = typeof window !== 'undefined' ? window.matchMedia?.('(prefers-reduced-motion: reduce)') : null;
            if (reduceQuery?.matches || (typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches)) {
                body.scrollTo({ left: target, behavior: 'instant' });
                this.syncHorizontal(body, false);
                return true;
            }
            const engine = typeof window !== 'undefined' ? window.gsap : null;
            const motion = { left: body.scrollLeft, target, reduceQuery, tween: null, timer: null, native: !engine?.to };
            distributionFocusMotions.set(root, motion);
            if (motion.native) {
                body.scrollTo({ left: target, behavior: 'smooth' });
                motion.timer = setTimeout(() => this.finishDistributionFocus(motion, false), 800);
            } else {
                // Cancel any earlier native arrow movement before taking over.
                body.scrollTo({ left: body.scrollLeft, behavior: 'instant' });
                motion.tween = engine.to(motion, {
                    left: target, duration: 0.42, ease: 'power2.inOut',
                    onUpdate: () => {
                        if (this.windowDisposed || distributionFocusMotions.get(root) !== motion) return;
                        if (motion.reduceQuery?.matches) return this.finishDistributionFocus(motion);
                        const current = this.timelineScrollEdges(true);
                        body.scrollLeft = Math.max(current.first, Math.min(current.last, motion.left));
                        this.syncHorizontal(body, false);
                    },
                    onComplete: () => this.finishDistributionFocus(motion),
                });
            }
            return true;
        },
        cancelDistributionFocus() {
            const root = this.timelineElement;
            const motion = distributionFocusMotions.get(root);
            if (!motion) return;
            distributionFocusMotions.delete(root);
            motion.tween?.kill();
            if (motion.timer !== null) clearTimeout(motion.timer);
            if (motion.native && !this.windowDisposed) {
                const body = this.$refs.timelineBody;
                body.scrollTo({ left: body.scrollLeft, behavior: 'instant' });
            }
            this.finishHorizontalIntent();
        },
        finishDistributionFocus(motion, moveToTarget = true) {
            if (this.windowDisposed || distributionFocusMotions.get(this.timelineElement) !== motion) return;
            distributionFocusMotions.delete(this.timelineElement);
            motion.tween?.kill();
            if (motion.timer !== null) clearTimeout(motion.timer);
            const body = this.$refs.timelineBody;
            if (moveToTarget) {
                const edges = this.timelineScrollEdges(true);
                body.scrollLeft = Math.max(edges.first, Math.min(edges.last, motion.target));
            }
            this.syncHorizontal(body, false);
            this.finishHorizontalIntent();
        },
        measureEventLabels(periodWidth) {
            const measurements = [];
            // Gather all layout reads before any row writes. Alternating the two
            // used to force a complete grid layout once per employee on a morph.
            for (const track of this.$refs.timelineGrid.querySelectorAll('.rt-personnel-timeline-track')) {
                const events = [...track.querySelectorAll('.rt-personnel-timeline-event')];
                if (!events.length) continue;
                const positions = timelineEventLanes(events.map(event => {
                    const label = event.querySelector('.rt-personnel-timeline-time-text');
                    return {
                        start: parseFloat(event.dataset.timeStart) || 0,
                        duration: parseFloat(event.dataset.timeWidth) || 0,
                        lane: parseInt(event.dataset.timeLane, 10) || 0,
                        continuesBefore: event.dataset.continuesBefore === 'true',
                        continuesAfter: event.dataset.continuesAfter === 'true',
                        labelWidth: label ? label.getBoundingClientRect().width + 16 : 0,
                    };
                }), periodWidth);
                const rowLaneCount = parseInt(track.dataset.timelineLanes, 10) || 1;
                const laneCount = Math.max(rowLaneCount, Math.max(...positions.map(position => position.lane)) + 1);
                measurements.push({ track, events, positions, laneCount });
            }
            for (const { track, events, positions, laneCount } of measurements) {
                positions.forEach((position, index) => {
                    for (const [name, value] of [['--event-lane', String(position.lane)], ['--time-label-offset', `${position.labelOffset}px`], ['--time-badge-width', `${position.badgeWidth}px`]]) {
                        if (events[index].style.getPropertyValue(name) !== value) events[index].style.setProperty(name, value);
                    }
                });
                if (track.style.getPropertyValue('--timeline-lanes') !== String(laneCount)) track.style.setProperty('--timeline-lanes', laneCount);
                // Include readable-label collisions, not only the server's true overlaps.
                // Two compact lanes fit the existing 48px row; further lanes stay visible.
                const density = laneCount > 1 ? 'compact' : 'normal';
                if (track.dataset.timelineDensity !== density) track.dataset.timelineDensity = density;
            }
            this.eventGeometryDirty = false;
            this.eventPeriodWidth = periodWidth;
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
                if (detectDirection) {
                    this.cancelDistributionFocus();
                    this.finishHorizontalIntent();
                }
                const footerOffset = this.windowNavigationEnabled
                    ? Math.max(0, Math.min(selectedEdges.last - selectedEdges.first, scrollbar.scrollLeft)) : scrollbar.scrollLeft;
                body.scrollLeft = footerPrefix + footerOffset;
            }
            // Reserve columns are preloaded for arrow transitions, not extra
            // selected dates. Native keyboard/snap input follows the same bound.
            const state = windowNavigations.get(this.timelineElement);
            if (this.windowNavigationEnabled && state?.positioned) {
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
            if (![-1, 1].includes(direction) || this.windowDisposed) return;
            this.cancelDistributionFocus();
            const body = this.$refs.timelineBody;
            const width = parseFloat(getComputedStyle(this.timelineElement).getPropertyValue('--timeline-day-width'));
            if (!width) return;
            const edges = this.timelineScrollEdges(true);
            const state = this.windowNavigationEnabled ? this.windowNavigationState() : null;
            const offset = state?.motionTarget ?? body.scrollLeft;
            const atEdge = direction < 0 ? offset <= edges.first + 1 : offset >= edges.last - 1;
            if (atEdge && this.windowNavigationEnabled) return this.shiftWindow(direction);
            this.holdHorizontalIntent(direction);
            const target = Math.max(edges.first, Math.min(edges.last, timelineDayOffset(offset, width, direction, edges.last)));
            if (state) state.motionTarget = target;
            body.scrollTo({
                left: target,
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
            });
            if (state && this.windowContentLoading && !state.request) return this.drainWindowRequest();
        },
    };
}
