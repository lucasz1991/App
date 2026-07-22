const STORAGE_VERSION = 1;
const MAX_WAGONS = 40;

const emptyWagon = () => ({
    number12: '',
    number34: '',
    number58: '',
    number911: '',
    checkDigit: '',
    category: '',
    axlesEmpty: '',
    axlesLoaded: '',
    length: '',
    wagonWeight: '',
    loadWeight: '',
    brakeG: '',
    brakeP: '',
    shippingStation: '',
    destinationStation: '',
    brakeType: '',
    discBrake: false,
    parkingBrake: '',
    maxSpeed: '',
    remark: '',
});

const emptyBrakeSheet = () => ({
    tractionWeight: '',
    tractionBrakeWeight: '',
    tractionAxles: '',
    minimumBrakePercentage: '',
    brakedAxles: '',
    lowerVehicleSpeed: '',
    nbuepBrake: '',
    emergencyBrakeBridge: '',
    passengerFeatureHzee: '',
    passengerFeatureNOe: '',
    passengerFeatureTb0: '',
    passengerFeatureOZub: '',
    passengerFeatureOther: '',
    dangerousGoods: '',
    epBrake: '',
    issuerName: '',
});

const numeric = (value) => {
    const normalized = String(value ?? '').trim().replace(',', '.');
    const parsed = Number.parseFloat(normalized);

    return Number.isFinite(parsed) ? parsed : 0;
};

export function wagonListPrototype(config = {}) {
    return {
        activeSheet: 'wagons',
        storageKey: String(config.storageKey || 'rt-wagon-list-prototype:v1'),
        visibleCount: 3,
        openWagon: 0,
        persistedAt: null,
        persistTimer: null,
        meta: {
            trainNumber: '',
            date: new Date().toISOString().slice(0, 10),
            origin: '',
            destination: '',
            reference: '',
        },
        wagons: Array.from({ length: MAX_WAGONS }, emptyWagon),
        brakeSheet: emptyBrakeSheet(),

        init() {
            this.restoreDraft();
            this.$watch('meta', () => this.schedulePersist());
            this.$watch('wagons', () => this.schedulePersist());
            this.$watch('brakeSheet', () => this.schedulePersist());
            this.$watch('visibleCount', () => this.schedulePersist());
        },

        restoreDraft() {
            try {
                const stored = JSON.parse(localStorage.getItem(this.storageKey) || 'null');
                if (!stored || stored.version !== STORAGE_VERSION) return;

                this.meta = { ...this.meta, ...(stored.meta || {}) };
                this.wagons = Array.from({ length: MAX_WAGONS }, (_, index) => ({
                    ...emptyWagon(),
                    ...(stored.wagons?.[index] || {}),
                }));
                this.brakeSheet = { ...emptyBrakeSheet(), ...(stored.brakeSheet || {}) };
                this.visibleCount = Math.max(3, Math.min(MAX_WAGONS, Number(stored.visibleCount || 3)));
                this.persistedAt = stored.persistedAt || null;
            } catch (_) {
                localStorage.removeItem(this.storageKey);
            }
        },

        schedulePersist() {
            window.clearTimeout(this.persistTimer);
            this.persistTimer = window.setTimeout(() => this.persistDraft(), 250);
        },

        persistDraft() {
            this.persistedAt = new Date().toISOString();
            localStorage.setItem(this.storageKey, JSON.stringify({
                version: STORAGE_VERSION,
                meta: this.meta,
                wagons: this.wagons,
                brakeSheet: this.brakeSheet,
                visibleCount: this.visibleCount,
                persistedAt: this.persistedAt,
            }));
        },

        async resetDraft() {
            let confirmed = true;
            if (window.Swal) {
                const result = await window.Swal.fire({
                    title: config.resetTitle || 'Entwurf zurücksetzen?',
                    text: config.resetText || 'Alle lokal gespeicherten Eingaben werden entfernt.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: config.resetConfirm || 'Zurücksetzen',
                    cancelButtonText: config.cancel || 'Abbrechen',
                    confirmButtonColor: '#e4002b',
                });
                confirmed = result.isConfirmed;
            }

            if (!confirmed) return;

            localStorage.removeItem(this.storageKey);
            this.meta = {
                trainNumber: '',
                date: new Date().toISOString().slice(0, 10),
                origin: '',
                destination: '',
                reference: '',
            };
            this.wagons = Array.from({ length: MAX_WAGONS }, emptyWagon);
            this.brakeSheet = emptyBrakeSheet();
            this.visibleCount = 3;
            this.openWagon = 0;
            this.persistedAt = null;
        },

        addWagon() {
            if (this.visibleCount >= MAX_WAGONS) return;
            this.openWagon = this.visibleCount;
            this.visibleCount += 1;
            this.$nextTick(() => this.$root.querySelector(`[data-wagon-index='${this.openWagon}']`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        },

        clearWagon(index) {
            this.wagons[index] = emptyWagon();
            this.schedulePersist();
        },

        wagonNumber(wagon) {
            return [wagon.number12, wagon.number34, wagon.number58, wagon.number911]
                .filter(Boolean)
                .join(' ') + (wagon.checkDigit ? `-${wagon.checkDigit}` : '');
        },

        wagonDigits(wagon) {
            return `${wagon.number12}${wagon.number34}${wagon.number58}${wagon.number911}`.replace(/\D/g, '');
        },

        expectedCheckDigit(wagon) {
            const digits = this.wagonDigits(wagon);
            if (digits.length !== 11) return null;

            const sum = digits.split('').reduce((total, digit, index) => {
                const product = Number(digit) * (index % 2 === 0 ? 2 : 1);
                return total + Math.floor(product / 10) + (product % 10);
            }, 0);

            return (10 - (sum % 10)) % 10;
        },

        checkState(wagon) {
            const expected = this.expectedCheckDigit(wagon);
            if (expected === null || String(wagon.checkDigit).length !== 1) return 'incomplete';

            return Number(wagon.checkDigit) === expected ? 'valid' : 'invalid';
        },

        isWagonFilled(wagon) {
            return Boolean(this.wagonDigits(wagon) || wagon.category || wagon.wagonWeight || wagon.loadWeight);
        },

        totalWeight(wagon) {
            if (!this.isWagonFilled(wagon)) return 0;
            return numeric(wagon.wagonWeight) + numeric(wagon.loadWeight);
        },

        get activeWagons() {
            return this.wagons.filter((wagon) => this.isWagonFilled(wagon));
        },

        sum(field) {
            return this.activeWagons.reduce((total, wagon) => total + numeric(wagon[field]), 0);
        },

        get totals() {
            const length = this.sum('length');
            const brakeG = this.sum('brakeG');
            const brakeP = this.sum('brakeP');
            const deductionG = brakeG * 0.25;
            const deductionP19 = length >= 701.1 ? brakeP * 0.19 : 0;
            const deductionP10 = length > 601.1 ? brakeP * 0.10 : 0;
            const deductionP5 = length > 500 && length <= 601 ? brakeP * 0.05 : 0;

            return {
                wagons: this.activeWagons.length,
                axlesEmpty: this.sum('axlesEmpty'),
                axlesLoaded: this.sum('axlesLoaded'),
                axles: this.sum('axlesEmpty') + this.sum('axlesLoaded'),
                length,
                wagonWeight: this.sum('wagonWeight'),
                loadWeight: this.sum('loadWeight'),
                totalWeight: this.activeWagons.reduce((total, wagon) => total + this.totalWeight(wagon), 0),
                brakeG,
                brakeP,
                brakeCount: this.activeWagons.filter((wagon) => numeric(wagon.brakeG) > 0 || numeric(wagon.brakeP) > 0).length,
                discBrakes: this.activeWagons.filter((wagon) => Boolean(wagon.discBrake)).length,
                plasticBrakes: this.activeWagons.filter((wagon) => ['K', 'L', 'LL'].includes(wagon.brakeType)).length,
                deductionG,
                deductionP19,
                deductionP10,
                deductionP5,
                usableBrakeWeight: Math.max(0, brakeG + brakeP - deductionG - deductionP19 - deductionP10 - deductionP5),
            };
        },

        get brakeTotals() {
            const trainWeight = this.totals.totalWeight + numeric(this.brakeSheet.tractionWeight);
            const brakeWeight = this.totals.usableBrakeWeight + numeric(this.brakeSheet.tractionBrakeWeight);
            const axles = this.totals.axles + numeric(this.brakeSheet.tractionAxles);
            const availablePercentage = trainWeight > 0 ? Math.round((brakeWeight * 100) / trainWeight) : 0;

            return {
                trainWeight,
                brakeWeight,
                axles,
                availablePercentage,
                missingPercentage: Math.max(0, numeric(this.brakeSheet.minimumBrakePercentage) - availablePercentage),
                lastVehicle: this.activeWagons.length ? this.wagonNumber(this.activeWagons[this.activeWagons.length - 1]) : '',
            };
        },

        formatNumber(value, digits = 1) {
            return new Intl.NumberFormat(config.locale || 'de-DE', {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits,
            }).format(numeric(value));
        },

        formatSavedAt() {
            if (!this.persistedAt) return config.notSaved || 'Noch nicht gespeichert';
            return new Intl.DateTimeFormat(config.locale || 'de-DE', {
                dateStyle: 'short',
                timeStyle: 'short',
            }).format(new Date(this.persistedAt));
        },
    };
}
