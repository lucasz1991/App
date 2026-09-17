// Compose local wall-clock values. The server owns IANA-zone/DST validation.
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const TIME = /^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/;

export const dateTimeField = (config = {}) => ({
    value: config.value ?? '',
    datePart: '',
    timePart: '',

    init() {
        this.sync();
        this.$watch('value', () => this.sync());
        this.$watch('datePart', () => this.commit());
        this.$watch('timePart', () => this.commit());
    },

    compose() {
        // Preserve incomplete input for server validation. In an optional
        // field, silently replacing half an entered appointment with '' would
        // otherwise discard it without an error.
        return this.datePart || this.timePart ? `${this.datePart}T${this.timePart}` : '';
    },

    sync() {
        const incoming = String(this.value ?? '');
        // Preserve editing state without an entangle feedback loop.
        if (incoming === this.compose()) return;
        const parts = incoming.split('T');
        this.datePart = DATE.test(parts[0] ?? '') ? parts[0] : '';
        this.timePart = TIME.test(parts[1] ?? '') ? parts[1] : '';
    },

    commit() {
        if (this.$refs.time?.disabled || this.$refs.time?.readOnly) return;
        const next = this.compose();
        if (this.value !== next) this.value = next;
    },
});
