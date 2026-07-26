// ---------------------------------------------------------------
// Logik fuer die gemeinsame Zahlen-Eingabe (x-ui.forms.number-input).
//
// Ersetzt <input type="number">: keine Browser-Spinner, dafuer eine
// Alpine-Mask-Eingabe mit eigenen Schritt-Tasten. Die Komponente besitzt den
// Wert NICHT — gebunden wird weiterhin am <input> selbst (wire:model ODER
// x-model). Geaenderte Werte werden deshalb als input/change-Event gemeldet,
// darauf hoeren Livewire und Alpine gleichermassen.
//
// Funktioniert in zwei Bauformen:
//   * mit Schritt-Tasten -> x-data sitzt am Rahmen, das Feld traegt x-ref="field"
//   * kompakt (Tabellenraster) -> x-data sitzt direkt am <input>
// Deshalb wird das Feld ueber $refs.field ODER $el aufgeloest.
// ---------------------------------------------------------------
export const numberInput = (config = {}) => ({
    min: config.min ?? null,
    max: config.max ?? null,
    stepSize: config.step ?? 1,
    decimals: config.decimals ?? 0,
    separator: config.separator ?? ',',
    // Darf das Feld leer bleiben? Bei serverseitig typisierten Pflichtfeldern
    // (z. B. public int $invitationExpiryDays) bewusst false, sonst wuerde ein
    // leeres Feld beim Speichern einen Typfehler ausloesen.
    nullable: config.nullable ?? true,

    field() {
        return this.$refs.field ?? this.$el;
    },

    toNumber(raw) {
        const text = String(raw ?? '').trim().replace(this.separator, '.');

        if (text === '') {
            return null;
        }

        const parsed = Number.parseFloat(text);

        return Number.isFinite(parsed) ? parsed : null;
    },

    format(value) {
        // toFixed raeumt gleich die Fliesskomma-Reste von Schrittrechnungen auf
        // (0.1 + 0.2). Ohne Dezimalstellen bleibt es eine ganze Zahl.
        const text = this.decimals > 0
            ? Number(value).toFixed(this.decimals)
            : String(Math.round(value));

        return this.decimals > 0 ? text.replace('.', this.separator) : text;
    },

    clamp(value) {
        if (this.min !== null && value < this.min) {
            return this.min;
        }

        if (this.max !== null && value > this.max) {
            return this.max;
        }

        return value;
    },

    write(value) {
        const field = this.field();
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    },

    nudge(direction) {
        const field = this.field();

        if (field.disabled || field.readOnly) {
            return;
        }

        const current = this.toNumber(field.value);
        const base = current === null ? (this.min ?? 0) : current;

        this.write(this.format(this.clamp(base + (direction * this.stepSize))));
    },

    /**
     * Beim Verlassen des Feldes nur eingreifen, wenn es noetig ist: einen Wert
     * ausserhalb von min/max zurechtruecken bzw. ein leeres Pflichtfeld
     * auffuellen. Getippte Genauigkeit bleibt sonst unangetastet ("12" wird
     * nicht zu "12,00").
     */
    normalize() {
        const field = this.field();
        const current = this.toNumber(field.value);

        if (current === null) {
            if (this.nullable) {
                if (field.value !== '') {
                    this.write('');
                }

                return;
            }

            this.write(this.format(this.clamp(this.min ?? 0)));

            return;
        }

        const clamped = this.clamp(current);

        if (clamped !== current) {
            this.write(this.format(clamped));
        }
    },

    /** Fuer aria-valuenow. */
    get ariaValue() {
        const current = this.toNumber(this.field()?.value);

        return current === null ? null : current;
    },
});
