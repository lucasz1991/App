// ---------------------------------------------------------------
// Logik fuer das gemeinsame Datumsfeld (x-ui.forms.date-field).
//
// Ersetzt <input type="date">: der Browser-Datepicker laesst sich weder
// gestalten noch zuverlaessig positionieren, und seine Mindestbreite sprengt
// enge Raster (Wagenliste: fuenfspaltige Kopfzeile, zweispaltiges Mobilraster).
//
// Bauform wie bei der Zahlen-Eingabe: die Komponente BESITZT den Wert nicht.
// Gebunden wird am versteckten <input x-ref="field"> (wire:model ODER x-model),
// Aenderungen werden als input/change-Event gemeldet — darauf hoeren Livewire
// und Alpine gleichermassen. Der sichtbare Text liegt in einem zweiten Feld
// und wird nur formatiert; die Quelle der Wahrheit bleibt ISO (YYYY-MM-DD).
//
// Der Kalender haengt per x-teleport am <body> und wird fixed positioniert.
// Grund: das Feld steht in Flaechen mit overflow:hidden (Vollbild-Modal,
// Wizard-Folien) — ein absolut positioniertes Popover waere dort abgeschnitten.
// ---------------------------------------------------------------

const ISO_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const PANEL_WIDTH = 304;
const PANEL_HEIGHT = 372;
const VIEWPORT_MARGIN = 8;

const pad = (value) => String(value).padStart(2, '0');

const toIso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

const parseIso = (value) => {
    if (!ISO_PATTERN.test(String(value ?? ''))) return null;

    const [year, month, day] = String(value).split('-').map(Number);
    const date = new Date(year, month - 1, day);

    // Verwirft Scheindaten wie 2026-02-31, die der Date-Konstruktor still
    // in den Folgemonat schiebt.
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
        ? date
        : null;
};

export const dateField = (config = {}) => ({
    locale: config.locale || 'de-DE',
    // Erster Wochentag: 1 = Montag (de), 0 = Sonntag (en).
    weekStart: Number.isInteger(config.weekStart) ? config.weekStart : 1,
    min: ISO_PATTERN.test(String(config.min ?? '')) ? String(config.min) : null,
    max: ISO_PATTERN.test(String(config.max ?? '')) ? String(config.max) : null,
    open: false,
    display: '',
    viewYear: new Date().getFullYear(),
    viewMonth: new Date().getMonth(),
    focusedIso: null,
    panelStyle: '',
    repositionHandler: null,

    init() {
        this.syncFromValue();

        // Aenderungen von aussen (Entwurf laden, Assistent, Livewire-Roundtrip)
        // schreiben direkt in den Wert — der sichtbare Text muss folgen.
        this.$watch('open', (isOpen) => (isOpen ? this.bindReposition() : this.unbindReposition()));
        this.field()?.addEventListener('rt-date-sync', () => this.syncFromValue());
    },

    destroy() {
        this.unbindReposition();
    },

    field() {
        return this.$refs.field ?? null;
    },

    get value() {
        return String(this.field()?.value ?? '');
    },

    get hasValue() {
        return parseIso(this.value) !== null;
    },

    formatDisplay(iso) {
        const date = parseIso(iso);
        if (!date) return '';

        return new Intl.DateTimeFormat(this.locale, {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        }).format(date);
    },

    formatLong(iso) {
        const date = parseIso(iso);
        if (!date) return '';

        return new Intl.DateTimeFormat(this.locale, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(date);
    },

    syncFromValue() {
        const date = parseIso(this.value) || new Date();
        this.display = this.formatDisplay(this.value);
        this.viewYear = date.getFullYear();
        this.viewMonth = date.getMonth();
        this.focusedIso = this.hasValue ? this.value : toIso(date);
    },

    write(iso) {
        const field = this.field();
        if (!field) return;

        field.value = iso;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        this.display = this.formatDisplay(iso);
    },

    /**
     * Getippten Text auswerten. Akzeptiert 1.8.26, 01.08.2026 und 2026-08-01;
     * unlesbares stellt still den letzten gueltigen Wert wieder her, statt den
     * Entwurf mit Datenmuell zu fuellen.
     */
    commitTyped() {
        const raw = String(this.display ?? '').trim();

        if (raw === '') {
            this.write('');
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

        const parts = raw.split(/[.\-/\s]+/).filter(Boolean).map(Number);
        if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) return null;

        const [day, month, shortYear] = parts;
        const year = shortYear < 100 ? 2000 + shortYear : shortYear;
        const candidate = `${year}-${pad(month)}-${pad(day)}`;

        return parseIso(candidate) ? candidate : null;
    },

    isSelectable(iso) {
        if (this.min && iso < this.min) return false;
        if (this.max && iso > this.max) return false;

        return true;
    },

    get monthLabel() {
        return new Intl.DateTimeFormat(this.locale, { month: 'long', year: 'numeric' })
            .format(new Date(this.viewYear, this.viewMonth, 1));
    },

    get weekdayLabels() {
        const formatter = new Intl.DateTimeFormat(this.locale, { weekday: 'short' });

        // 2024-01-01 war ein Montag — als stabiler Anker fuer die Reihenfolge.
        return Array.from({ length: 7 }, (_, index) => {
            const day = new Date(2024, 0, 1 + ((index + this.weekStart + 6) % 7));

            return formatter.format(day).replace('.', '');
        });
    },

    get days() {
        const first = new Date(this.viewYear, this.viewMonth, 1);
        const offset = (first.getDay() - this.weekStart + 7) % 7;
        const start = new Date(this.viewYear, this.viewMonth, 1 - offset);
        const todayIso = toIso(new Date());

        return Array.from({ length: 42 }, (_, index) => {
            const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
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

    shiftMonth(delta) {
        const date = new Date(this.viewYear, this.viewMonth + delta, 1);
        this.viewYear = date.getFullYear();
        this.viewMonth = date.getMonth();
    },

    select(iso) {
        if (!this.isSelectable(iso)) return;

        this.write(iso);
        this.focusedIso = iso;
        this.closePanel(true);
    },

    selectToday() {
        const iso = toIso(new Date());
        this.viewYear = new Date().getFullYear();
        this.viewMonth = new Date().getMonth();
        this.select(iso);
    },

    clear() {
        this.write('');
        this.syncFromValue();
        this.closePanel(true);
    },

    togglePanel() {
        if (this.open) {
            this.closePanel(true);
            return;
        }

        this.openPanel();
    },

    openPanel() {
        if (this.field()?.disabled) return;

        this.syncFromValue();
        this.position();
        this.open = true;
        this.$nextTick(() => this.$refs.panel?.querySelector('[data-date-focused="true"]')?.focus());
    },

    closePanel(returnFocus = false) {
        if (!this.open) return;

        this.open = false;
        if (returnFocus) this.$nextTick(() => this.$refs.display?.focus());
    },

    /**
     * Fixed statt absolut: das Feld steht in Flaechen mit overflow:hidden.
     * Oeffnet nach unten, kippt nach oben, sobald es unten nicht mehr passt,
     * und bleibt in jedem Fall innerhalb des sichtbaren Bereichs.
     */
    position() {
        const anchor = this.$refs.anchor;
        if (!anchor) return;

        const rect = anchor.getBoundingClientRect();
        const width = Math.min(PANEL_WIDTH, window.innerWidth - (VIEWPORT_MARGIN * 2));
        const spaceBelow = window.innerHeight - rect.bottom;
        const placeAbove = spaceBelow < PANEL_HEIGHT && rect.top > spaceBelow;

        const left = Math.min(
            Math.max(VIEWPORT_MARGIN, rect.left),
            Math.max(VIEWPORT_MARGIN, window.innerWidth - width - VIEWPORT_MARGIN),
        );
        const top = placeAbove
            ? Math.max(VIEWPORT_MARGIN, rect.top - PANEL_HEIGHT - 6)
            : Math.min(rect.bottom + 6, window.innerHeight - VIEWPORT_MARGIN);
        const maxHeight = placeAbove
            ? rect.top - VIEWPORT_MARGIN - 6
            : window.innerHeight - top - VIEWPORT_MARGIN;

        this.panelStyle = `left:${Math.round(left)}px;top:${Math.round(top)}px;width:${Math.round(width)}px;max-height:${Math.round(Math.max(220, maxHeight))}px;`;
    },

    bindReposition() {
        if (this.repositionHandler) return;

        this.repositionHandler = () => this.position();
        window.addEventListener('resize', this.repositionHandler, { passive: true });
        // capture: auch das Scrollen innerhalb der Wizard-Folie mitbekommen.
        window.addEventListener('scroll', this.repositionHandler, { passive: true, capture: true });
    },

    unbindReposition() {
        if (!this.repositionHandler) return;

        window.removeEventListener('resize', this.repositionHandler);
        window.removeEventListener('scroll', this.repositionHandler, { capture: true });
        this.repositionHandler = null;
    },

    handleGridKeydown(event) {
        const moves = {
            ArrowLeft: -1,
            ArrowRight: 1,
            ArrowUp: -7,
            ArrowDown: 7,
            PageUp: null,
            PageDown: null,
        };

        if (event.key === 'Escape') {
            event.preventDefault();
            this.closePanel(true);
            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (this.focusedIso) this.select(this.focusedIso);
            return;
        }

        if (!(event.key in moves)) return;

        event.preventDefault();

        if (event.key === 'PageUp' || event.key === 'PageDown') {
            this.shiftMonth(event.key === 'PageUp' ? -1 : 1);
            const anchorDate = new Date(this.viewYear, this.viewMonth, 1);
            this.focusedIso = toIso(anchorDate);
        } else {
            const current = parseIso(this.focusedIso) || new Date();
            const next = new Date(current.getFullYear(), current.getMonth(), current.getDate() + moves[event.key]);
            this.focusedIso = toIso(next);
            this.viewYear = next.getFullYear();
            this.viewMonth = next.getMonth();
        }

        this.$nextTick(() => this.$refs.panel?.querySelector('[data-date-focused="true"]')?.focus());
    },
});
