// Civil dates are calculated in UTC, independently of DST and browser timezone.
const DAY = 86400000;
const parse = (value) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value ?? ''))) return null;
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(0);
    date.setUTCFullYear(year, month - 1, day);
    date.setUTCHours(0, 0, 0, 0);
    return year > 0 && date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? date : null;
};
const iso = (date) => date.toISOString().slice(0, 10);
const addDays = (value, days) => iso(new Date(parse(value).getTime() + days * DAY));
const monthStart = (value) => `${value.slice(0, 7)}-01`;
const addMonths = (value, months) => {
    const date = parse(monthStart(value));
    date.setUTCMonth(date.getUTCMonth() + months);
    return iso(date);
};

export const dateRangePicker = (config = {}) => ({
    from: config.from ?? '',
    until: config.until ?? '',
    today: parse(config.today) ? config.today : iso(new Date()),
    locale: config.locale || 'de-DE',
    min: parse(config.min) ? config.min : null,
    max: parse(config.max) ? config.max : null,
    maxDays: Number(config.maxDays) > 0 ? Number(config.maxDays) : null,
    draftFrom: '',
    draftUntil: '',
    fromText: '',
    untilText: '',
    months: [],
    focused: [],
    selectingEnd: false,
    hover: null,
    touched: false,
    busy: false,
    failure: '',

    init() {
        this.resetDraft();
        if (config.resetOnOpen) this.$watch('open', (open) => { if (open) this.resetDraft(); });
        for (const key of ['from', 'until']) {
            this.$watch(key, () => { if (!this.busy) this.resetDraft(); });
        }
    },

    text(de, en) { return this.locale.startsWith('de') ? de : en; },

    format(value, long = false) {
        const date = parse(value);
        if (!date) return '';
        return new Intl.DateTimeFormat(this.locale, long
            ? { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }
            : { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(date);
    },

    parseText(value) {
        const text = String(value ?? '').trim();
        if (parse(text)) return text;
        const match = text.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})$/);
        if (!match) return null;
        const result = `${match[3]}-${match[2].padStart(2, '0')}-${match[1].padStart(2, '0')}`;
        return parse(result) ? result : null;
    },

    resetDraft() {
        this.draftFrom = parse(this.from) ? this.from : '';
        this.draftUntil = parse(this.until) ? this.until : '';
        this.syncText();
        this.showRange();
        this.selectingEnd = false;
        this.hover = null;
        this.touched = false;
        this.failure = '';
    },

    syncText() {
        this.fromText = this.format(this.draftFrom);
        this.untilText = this.format(this.draftUntil);
    },

    showRange() {
        const left = monthStart(this.draftFrom || this.today);
        const right = monthStart(this.draftUntil || this.today);
        this.months = [left, right > left ? right : addMonths(left, 1)];
        this.focused = [this.draftFrom || left, right > left ? (this.draftUntil || right) : this.months[1]];
    },

    get weekdays() {
        return Array.from({ length: 7 }, (_, i) => new Intl.DateTimeFormat(this.locale, { weekday: 'short', timeZone: 'UTC' })
            .format(parse(addDays('2024-01-01', i))).replace('.', ''));
    },

    monthLabel(side) {
        return new Intl.DateTimeFormat(this.locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(parse(this.months[side]));
    },

    days(side) {
        const first = parse(this.months[side]);
        const start = addDays(this.months[side], -((first.getUTCDay() + 6) % 7));
        return Array.from({ length: 42 }, (_, index) => {
            const value = addDays(start, index);
            return {
                iso: value,
                label: parse(value).getUTCDate(),
                outside: value.slice(0, 7) !== this.months[side].slice(0, 7),
                hiddenNeighbor: value.slice(0, 7) !== this.months[side].slice(0, 7)
                    && value.slice(0, 7) === this.months[1 - side]?.slice(0, 7),
                disabled: !this.isSelectable(value),
            };
        });
    },

    isSelectable(value) {
        return !!parse(value) && (!this.min || value >= this.min) && (!this.max || value <= this.max);
    },

    state(value) {
        let start = this.draftFrom;
        let end = this.selectingEnd ? (this.hover || start) : this.draftUntil;
        if (start && end && end < start) [start, end] = [end, start];
        return start && value === start ? 'start' : end && value === end ? 'end'
            : start && end && value > start && value < end ? 'between' : '';
    },

    select(value, side) {
        if (this.busy || !this.isSelectable(value)) return;
        this.failure = '';
        this.touched = true;
        this.focused[side] = value;
        if (!this.selectingEnd) {
            this.draftFrom = value;
            this.draftUntil = '';
            this.selectingEnd = true;
        } else {
            [this.draftFrom, this.draftUntil] = [this.draftFrom, value].sort();
            this.selectingEnd = false;
        }
        this.hover = null;
        this.syncText();
    },

    updateTyped(side, value) {
        this.touched = true;
        this.failure = '';
        const parsed = this.parseText(value);
        this[side === 0 ? 'fromText' : 'untilText'] = value;
        this[side === 0 ? 'draftFrom' : 'draftUntil'] = parsed || '';
        this.selectingEnd = false;
        if (parsed) {
            this.months[side] = monthStart(parsed);
            this.focused[side] = parsed;
        }
    },

    shiftMonth(side, delta) {
        const target = addMonths(this.months[side], delta);
        if (!parse(target)) return;
        this.months[side] = target;
        this.focused[side] = target;
    },

    handleKey(event, side) {
        const value = event.target.dataset.rangeDate;
        if (!parse(value)) return;
        const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        let target;
        if (event.key in offsets) target = addDays(value, offsets[event.key]);
        else if (event.key === 'Home') target = addDays(value, -((parse(value).getUTCDay() + 6) % 7));
        else if (event.key === 'End') target = addDays(value, 6 - ((parse(value).getUTCDay() + 6) % 7));
        else if (event.key === 'PageUp' || event.key === 'PageDown') target = addMonths(value, event.key === 'PageUp' ? -1 : 1);
        else return;
        event.preventDefault();
        if (!this.isSelectable(target)) return;
        this.months[side] = monthStart(target);
        this.focused[side] = target;
        this.$nextTick(() => this.$el.querySelector(`[data-range-calendar="${side}"] [data-range-date="${target}"]`)?.focus());
    },

    get presets() {
        const weekday = (parse(this.today).getUTCDay() + 6) % 7;
        const week = addDays(this.today, -weekday);
        const month = monthStart(this.today);
        return [
            ['today', this.text('Heute', 'Today'), this.today, this.today],
            ['week', this.text('Diese Woche', 'This week'), week, addDays(week, 6)],
            ['next-week', this.text('Nächste Woche', 'Next week'), addDays(week, 7), addDays(week, 13)],
            ['seven', this.text('Letzte 7 Tage', 'Last 7 days'), addDays(this.today, -6), this.today],
            ['thirty', this.text('Letzte 30 Tage', 'Last 30 days'), addDays(this.today, -29), this.today],
            ['month', this.text('Dieser Monat', 'This month'), month, addDays(addMonths(month, 1), -1)],
            ['last-month', this.text('Letzter Monat', 'Last month'), addMonths(month, -1), addDays(month, -1)],
        ].map(([key, label, from, until]) => ({ key, label, from, until,
            disabled: !this.isSelectable(from) || !this.isSelectable(until) || (this.maxDays && (parse(until) - parse(from)) / DAY + 1 > this.maxDays),
        }));
    },

    choosePreset(preset) {
        if (this.busy || preset.disabled) return;
        this.draftFrom = preset.from;
        this.draftUntil = preset.until;
        this.syncText();
        this.showRange();
        this.selectingEnd = false;
        this.hover = null;
        this.touched = true;
        this.failure = '';
    },

    clear() {
        this.draftFrom = this.draftUntil = this.fromText = this.untilText = '';
        this.selectingEnd = false;
        this.hover = null;
        this.failure = '';
        this.touched = false;
    },

    get validationMessage() {
        if (!parse(this.draftFrom) || !parse(this.draftUntil)) return this.text('Start- und Enddatum wählen.', 'Choose a start and end date.');
        if (!this.isSelectable(this.draftFrom) || !this.isSelectable(this.draftUntil)) return this.text('Dieser Zeitraum ist nicht verfügbar.', 'This range is unavailable.');
        if (this.draftUntil < this.draftFrom) return this.text('Das Enddatum muss nach dem Startdatum liegen.', 'The end date must follow the start date.');
        if (this.maxDays && (parse(this.draftUntil) - parse(this.draftFrom)) / DAY + 1 > this.maxDays) return this.text(`Bitte höchstens ${this.maxDays} Tage auswählen.`, `Choose at most ${this.maxDays} days.`);
        return '';
    },

    get canApply() { return !this.busy && !this.validationMessage; },

    get summary() {
        if (this.failure) return this.failure;
        if (this.validationMessage) return this.validationMessage;
        const days = (parse(this.draftUntil) - parse(this.draftFrom)) / DAY + 1;
        return this.text(`${days} ${days === 1 ? 'Tag' : 'Tage'} ausgewählt`, `${days} ${days === 1 ? 'day' : 'days'} selected`);
    },

    cancel() { this.resetDraft(); this.$dispatch('date-range-cancel'); },

    async apply() {
        this.touched = true;
        if (!this.canApply) return;
        this.busy = true;
        this.failure = '';
        const range = { from: this.draftFrom, until: this.draftUntil };
        try {
            if (config.onApply) await config.onApply(range);
            else { this.from = range.from; this.until = range.until; }
            this.$dispatch('date-range-applied', range);
        } catch {
            this.failure = this.text('Der Zeitraum konnte nicht übernommen werden. Bitte erneut versuchen.', 'The range could not be applied. Please try again.');
        } finally {
            this.busy = false;
        }
    },
});
