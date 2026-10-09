// Kalender-Einblendung wie in der Schichtplan-Zeitleiste: Wochenzeilen nacheinander, in jeder Zeile
// die Tage von links nach rechts, je Tag die Einträge nacheinander – jeweils leicht von rechts.
export const CALENDAR_REVEAL = Object.freeze({ row: 0.07, column: 0.035, item: 0.045, duration: 0.45, offset: 14 });
export const CALENDAR_READY_ATTRIBUTE = 'data-rt-calendar-ready';

/** Verzögerung je Eintrag aus Tagesposition (Index im Raster) und Rang im Tag. */
export function calendarRevealDelays(items, columns = 7, days = []) {
    const dayIndex = new Map(days.map((day, index) => [day, index]));
    const perDay = new Map();
    return items.map((item) => {
        const day = item.closest?.('[data-calendar-day]') ?? null;
        if (day && !dayIndex.has(day)) dayIndex.set(day, dayIndex.size);
        const index = day ? dayIndex.get(day) : 0;
        const rank = perDay.get(day) ?? 0;
        perDay.set(day, rank + 1);
        const cols = Math.max(1, columns);
        return Math.floor(index / cols) * CALENDAR_REVEAL.row + (index % cols) * CALENDAR_REVEAL.column + rank * CALENDAR_REVEAL.item;
    });
}

export function calendarReveal() {
    const seen = new WeakSet();
    let tween = null;
    let observer = null;
    let frame = null;
    return {
        init() {
            this.reveal();
            observer = new MutationObserver(() => {
                if (frame !== null) return;
                frame = requestAnimationFrame(() => {
                    frame = null;
                    this.reveal();
                });
            });
            observer.observe(this.$el, { childList: true, subtree: true });
        },
        destroy() {
            observer?.disconnect();
            if (frame !== null) cancelAnimationFrame(frame);
            tween?.kill();
        },
        reveal(attempt = 0) {
            const root = this.$el;
            const ready = () => root.ownerDocument?.documentElement?.setAttribute(CALENDAR_READY_ATTRIBUTE, 'true');
            const items = [...root.querySelectorAll('[data-calendar-reveal-item]')].filter((item) => !seen.has(item));
            const engine = window.gsap;
            const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            if (!items.length || !engine?.fromTo || reduced) {
                items.forEach((item) => seen.add(item));
                ready();
                return;
            }
            const visible = items.filter((item) => item.getClientRects().length > 0);
            if (!visible.length) {
                // Inhalt noch nicht dargestellt (Seitenlader): weiter verborgen lassen und erneut versuchen;
                // nach rund 2,5 s übernimmt die CSS-Rückfallblende.
                if (attempt < 150) frame = requestAnimationFrame(() => { frame = null; this.reveal(attempt + 1); });
                else { items.forEach((item) => seen.add(item)); ready(); }
                return;
            }
            visible.forEach((item) => seen.add(item));
            const columns = root.classList.contains('rt-calendar-month') || root.classList.contains('rt-calendar-week') ? 7 : 1;
            const delays = calendarRevealDelays(visible, columns, [...root.querySelectorAll('[data-calendar-day]')]);
            tween?.progress(1);
            tween = engine.fromTo(visible, { opacity: 0, translate: `${CALENDAR_REVEAL.offset}px 0px` }, {
                opacity: 1, translate: '0px 0px', duration: CALENDAR_REVEAL.duration, ease: 'expo.out',
                // Startwerte sofort schreiben (GSAP verschiebt sie sonst in den nächsten Takt).
                immediateRender: true, lazy: false,
                stagger: (index) => delays[index],
                onComplete: () => visible.forEach((item) => { item.style.removeProperty('opacity'); item.style.removeProperty('translate'); }),
            });
            // Erst nach den Startwerten freigeben: der Server-Stand blitzt nicht vor der Einblendung auf.
            ready();
        },
    };
}
