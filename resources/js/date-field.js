// Gemeinsamer ISO-Datumswert fuer Alpine x-modelable und Livewire entangle.
// Das sichtbare Feld bleibt lokal formatiert; der Server erhaelt YYYY-MM-DD.
// Das bestehende Body-Popover bleibt auch in schmalen Rastern/Overlays nutzbar.

const ISO_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const PANEL_WIDTH = 336;
const PANEL_HEIGHT = 400;
const VIEWPORT_MARGIN = 8;
const pad = (value) => String(value).padStart(2, '0');
const toIso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

const parseIso = (value) => {
    if (!ISO_PATTERN.test(String(value ?? ''))) return null;

    const [year, month, day] = String(value).split('-').map(Number);
    if (year < 1) return null;
    const date = new Date(0);
    date.setFullYear(year, month - 1, day);
    date.setHours(12, 0, 0, 0);

    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
        ? date
        : null;
};

const shiftDateMonth = (date, delta) => {
    const target = new Date(date.getFullYear(), date.getMonth() + delta, 1, 12);
    const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0, 12).getDate();
    target.setDate(Math.min(date.getDate(), lastDay));

    return target;
};

export const dateField = (config = {}) => ({
    value: config.value ?? '',
    locale: config.locale || 'de-DE',
    weekStart: Number.isInteger(config.weekStart) && config.weekStart >= 0 && config.weekStart <= 6
        ? config.weekStart : 1,
    min: parseIso(config.min) ? String(config.min) : null,
    max: parseIso(config.max) ? String(config.max) : null,
    disabled: config.disabled ?? false,
    readonly: config.readonly ?? false,
    clearable: config.clearable ?? true,
    open: false,
    display: '',
    viewYear: new Date().getFullYear(),
    viewMonth: new Date().getMonth(),
    focusedIso: null,
    panelStyle: '',
    repositionHandler: null,
    focusHandler: null,
    returnFocusTo: null,

    init() {
        this.$nextTick(() => this.syncFromValue());
        this.$watch('value', () => this.syncFromValue());
        this.$watch('open', (isOpen) => (isOpen ? this.bindReposition() : this.unbindReposition()));
    },

    destroy() {
        this.unbindReposition();
    },

    get locked() {
        const field = this.$refs.display;
        // Das sichtbare Feld bleibt Livewire-owned; dessen Attribute koennen
        // sich nach dem initialen x-data-Aufbau serverseitig aendern.
        return field ? Boolean(field.disabled || field.readOnly) : this.disabled || this.readonly;
    },

    get hasValue() {
        return parseIso(this.value) !== null;
    },

    get todaySelectable() {
        return !this.locked && this.isSelectable(toIso(new Date()));
    },

    formatDisplay(iso) {
        const date = parseIso(iso);
        if (!date) return '';

        return new Intl.DateTimeFormat(this.locale, {
            day: '2-digit', month: '2-digit', year: 'numeric',
        }).format(date);
    },

    formatLong(iso) {
        const date = parseIso(iso);
        if (!date) return '';

        return new Intl.DateTimeFormat(this.locale, {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
        }).format(date);
    },

    syncFromValue() {
        this.display = this.formatDisplay(this.value);
        const source = parseIso(this.value) ? this.value : toIso(new Date());
        this.focusedIso = this.nearestSelectable(source);
        const date = parseIso(this.focusedIso) || parseIso(source) || new Date();
        this.viewYear = date.getFullYear();
        this.viewMonth = date.getMonth();
    },

    write(iso) {
        if (this.locked || (iso === '' ? !this.clearable : !this.isSelectable(iso))) return;

        this.value = iso;
        this.display = this.formatDisplay(iso);
    },

    commitTyped() {
        if (this.locked) return;
        const raw = String(this.display ?? '').trim();

        if (raw === '') {
            this.write('');
            this.syncFromValue();
            return;
        }

        const iso = this.parseTyped(raw);
        if (!iso || !this.isSelectable(iso)) {
            this.display = this.formatDisplay(this.value);
            return;
        }

        this.write(iso);
        this.syncFromValue();
    },

    parseTyped(raw) {
        if (ISO_PATTERN.test(raw)) return parseIso(raw) ? raw : null;
        if (!/^\d{1,2}[.\-/\s]\d{1,2}[.\-/\s]\d{2,4}$/.test(raw)) return null;

        const [day, month, shortYear] = raw.split(/[.\-/\s]/).map(Number);
        const year = shortYear < 100 ? 2000 + shortYear : shortYear;
        const candidate = `${String(year).padStart(4, '0')}-${pad(month)}-${pad(day)}`;

        return parseIso(candidate) ? candidate : null;
    },

    isSelectable(iso) {
        if (!parseIso(iso)) return false;
        if (this.min && iso < this.min) return false;
        if (this.max && iso > this.max) return false;

        return true;
    },

    nearestSelectable(iso) {
        if (this.min && this.max && this.min > this.max) return null;
        if (!parseIso(iso)) return null;
        if (this.min && iso < this.min) return this.min;
        if (this.max && iso > this.max) return this.max;

        return iso;
    },

    get monthName() {
        return new Intl.DateTimeFormat(this.locale, { month: 'long' })
            .format(new Date(this.viewYear, this.viewMonth, 1));
    },

    get monthLabel() {
        return new Intl.DateTimeFormat(this.locale, { month: 'long', year: 'numeric' })
            .format(new Date(this.viewYear, this.viewMonth, 1));
    },

    get weekdayLabels() {
        const formatter = new Intl.DateTimeFormat(this.locale, { weekday: 'short' });
        return Array.from({ length: 7 }, (_, index) => {
            const day = new Date(2024, 0, 1 + ((index + this.weekStart + 6) % 7));
            return formatter.format(day).replace('.', '');
        });
    },

    get days() {
        const first = new Date(this.viewYear, this.viewMonth, 1, 12);
        const offset = (first.getDay() - this.weekStart + 7) % 7;
        const start = new Date(this.viewYear, this.viewMonth, 1 - offset, 12);
        const todayIso = toIso(new Date());

        return Array.from({ length: 42 }, (_, index) => {
            const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index, 12);
            const iso = toIso(date);
            return {
                iso,
                label: date.getDate(),
                outside: date.getMonth() !== this.viewMonth,
                today: iso === todayIso,
                selected: iso === this.value,
                focused: iso === this.focusedIso,
                disabled: !this.isSelectable(iso),
            };
        });
    },

    get weeks() {
        const days = this.days;
        return Array.from({ length: 6 }, (_, index) => days.slice(index * 7, (index + 1) * 7));
    },

    canShiftMonth(delta) {
        const first = new Date(this.viewYear, this.viewMonth + delta, 1, 12);
        const last = new Date(first.getFullYear(), first.getMonth() + 1, 0, 12);
        if (this.locked || first.getFullYear() < 1 || first.getFullYear() > 9999) return false;
        if (this.min && toIso(last) < this.min) return false;
        if (this.max && toIso(first) > this.max) return false;

        return true;
    },

    shiftMonth(delta, focusDay = false) {
        if (!this.canShiftMonth(delta)) return;

        const current = parseIso(this.focusedIso) || new Date(this.viewYear, this.viewMonth, 1, 12);
        const source = new Date(this.viewYear, this.viewMonth, 1, 12);
        source.setDate(Math.min(current.getDate(), new Date(this.viewYear, this.viewMonth + 1, 0).getDate()));
        this.moveFocus(toIso(shiftDateMonth(source, delta)), focusDay);
    },

    moveFocus(iso, focusDay = true) {
        const next = this.nearestSelectable(iso);
        if (!next) return;

        this.focusedIso = next;
        const date = parseIso(next);
        this.viewYear = date.getFullYear();
        this.viewMonth = date.getMonth();
        if (focusDay) this.focusDay();
    },

    focusDay() {
        this.$nextTick(() => {
            const panel = this.$refs.panel;
            const target = panel?.querySelector('[data-date-focused="true"]:not(:disabled)');
            (target || panel)?.focus({ preventScroll: true });
        });
    },

    select(iso) {
        if (this.locked || !this.isSelectable(iso)) return;

        this.write(iso);
        this.focusedIso = iso;
        this.closePanel(true);
    },

    selectToday() {
        if (this.todaySelectable) this.select(toIso(new Date()));
    },

    clear() {
        if (this.locked || !this.clearable) return;

        this.write('');
        this.syncFromValue();
        this.closePanel(true);
    },

    togglePanel(trigger = null) {
        if (this.open) {
            this.closePanel(true);
            return;
        }
        this.openPanel(trigger);
    },

    openPanel(trigger = null) {
        if (this.locked) return;

        this.attachToOverlayPortal();
        this.returnFocusTo = trigger || this.$refs.display;
        this.syncFromValue();
        this.position();
        this.open = true;
        this.$nextTick(() => {
            this.position();
            if (this.$refs.panel) this.$refs.panel.scrollTop = 0;
            this.focusDay();
        });
    },

    attachToOverlayPortal() {
        const panel = this.$refs.panel;
        const overlay = this.$refs.anchor?.closest?.('[data-rt-overlay-layer]');
        const portal = overlay?.querySelector(':scope > [data-rt-overlay-portal]');
        if (!panel || !portal) return;

        // Same portal contract as shared dropdowns: remain inside the modal's
        // focus trap, outside its transformed and clipped content panel.
        if (panel.parentElement !== portal) portal.appendChild(panel);
        panel.removeAttribute('aria-hidden');
    },

    closePanel(returnFocus = false) {
        if (!this.open) return;

        this.open = false;
        this.unbindReposition();
        if (returnFocus) {
            this.$nextTick(() => {
                const target = this.returnFocusTo?.isConnected === false ? this.$refs.display : this.returnFocusTo;
                (target || this.$refs.display)?.focus({ preventScroll: true });
            });
        }
    },

    position() {
        const anchor = this.$refs.anchor;
        if (!anchor || typeof window === 'undefined') return;

        const rect = anchor.getBoundingClientRect();
        const viewport = window.visualViewport;
        const viewportLeft = viewport?.offsetLeft || 0;
        const viewportTop = viewport?.offsetTop || 0;
        const viewportWidth = viewport?.width || window.innerWidth;
        const viewportHeight = viewport?.height || window.innerHeight;
        const width = Math.max(0, Math.min(PANEL_WIDTH, viewportWidth - VIEWPORT_MARGIN * 2));
        const topEdge = viewportTop + VIEWPORT_MARGIN;
        const bottomEdge = viewportTop + viewportHeight - VIEWPORT_MARGIN;
        const spaceBelow = Math.max(0, bottomEdge - rect.bottom - 6);
        const spaceAbove = Math.max(0, rect.top - topEdge - 6);
        const panel = this.$refs.panel;
        const borderHeight = Number.isFinite(panel?.offsetHeight) && Number.isFinite(panel?.clientHeight)
            ? Math.max(0, panel.offsetHeight - panel.clientHeight) : 0;
        // scrollHeight misst den Inhalt; max-height gilt hier fuer border-box.
        const actualHeight = (panel?.scrollHeight || PANEL_HEIGHT) + borderHeight;
        const placeAbove = spaceBelow < actualHeight && spaceAbove > spaceBelow;
        const available = Math.min(viewportHeight - VIEWPORT_MARGIN * 2, placeAbove ? spaceAbove : spaceBelow);
        const height = Math.max(0, Math.min(actualHeight, available));
        const left = Math.min(
            Math.max(viewportLeft + VIEWPORT_MARGIN, rect.left),
            Math.max(viewportLeft + VIEWPORT_MARGIN, viewportLeft + viewportWidth - width - VIEWPORT_MARGIN),
        );
        const desiredTop = placeAbove ? rect.top - height - 6 : rect.bottom + 6;
        const top = Math.max(topEdge, Math.min(desiredTop, bottomEdge - height));

        this.panelStyle = `left:${Math.round(left)}px;top:${Math.round(top)}px;width:${Math.round(width)}px;max-height:${Math.round(height)}px;`;
    },

    bindReposition() {
        if (this.repositionHandler || typeof window === 'undefined') return;

        this.repositionHandler = () => this.position();
        this.focusHandler = (event) => {
            if (this.open && !this.$refs.panel?.contains(event.target) && !this.$refs.anchor?.contains(event.target)) {
                this.closePanel();
            }
        };
        window.addEventListener('resize', this.repositionHandler, { passive: true });
        window.addEventListener('scroll', this.repositionHandler, { passive: true, capture: true });
        window.visualViewport?.addEventListener('resize', this.repositionHandler, { passive: true });
        window.visualViewport?.addEventListener('scroll', this.repositionHandler, { passive: true });
        document.addEventListener('focusin', this.focusHandler);
    },

    unbindReposition() {
        if (!this.repositionHandler || typeof window === 'undefined') return;

        window.removeEventListener('resize', this.repositionHandler);
        window.removeEventListener('scroll', this.repositionHandler, { capture: true });
        window.visualViewport?.removeEventListener('resize', this.repositionHandler);
        window.visualViewport?.removeEventListener('scroll', this.repositionHandler);
        document.removeEventListener('focusin', this.focusHandler);
        this.repositionHandler = null;
        this.focusHandler = null;
    },

    handlePanelKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.closePanel(true);
            return;
        }

        if (event.key !== 'Tab') return;
        const controls = [...(this.$refs.panel?.querySelectorAll('button:not(:disabled):not([tabindex="-1"])') || [])]
            .filter((control) => control.getClientRects().length);
        const boundary = event.shiftKey ? controls[0] : controls.at(-1);
        if (event.target === boundary || !controls.length) {
            event.preventDefault();
            this.closePanel(true);
        }
    },

    handleGridKeydown(event) {
        const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        const handled = [...Object.keys(moves), 'Home', 'End', 'PageUp', 'PageDown', 'Enter', ' ', 'Escape'];
        if (!handled.includes(event.key)) return;

        event.preventDefault();
        event.stopPropagation?.();
        if (event.key === 'Escape') {
            this.closePanel(true);
            return;
        }
        if (this.locked) return;
        if (event.key === 'Enter' || event.key === ' ') {
            if (this.focusedIso) this.select(this.focusedIso);
            return;
        }
        if (event.key === 'PageUp' || event.key === 'PageDown') {
            this.shiftMonth((event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1), true);
            return;
        }

        const current = parseIso(this.focusedIso) || new Date();
        const weekdayOffset = (current.getDay() - this.weekStart + 7) % 7;
        const delta = event.key === 'Home' ? -weekdayOffset
            : event.key === 'End' ? 6 - weekdayOffset : moves[event.key];
        this.moveFocus(toIso(new Date(current.getFullYear(), current.getMonth(), current.getDate() + delta, 12)));
    },
});
