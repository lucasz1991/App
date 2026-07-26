const STORAGE_VERSION = 2;
const MAX_WAGONS = 40;

const emptyMeta = () => ({
    trainNumber: '',
    date: new Date().toISOString().slice(0, 10),
    origin: '',
    destination: '',
    reference: '',
});

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

const createDraftId = () => {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    return `draft-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
};

const normalizeDraft = (draft = {}, fallbackId = null) => {
    const now = new Date().toISOString();
    const visibleCount = Math.max(3, Math.min(MAX_WAGONS, Number(draft.visibleCount || 3)));

    return {
        id: String(draft.id || fallbackId || createDraftId()),
        createdAt: draft.createdAt || draft.persistedAt || now,
        persistedAt: draft.persistedAt || draft.createdAt || now,
        meta: { ...emptyMeta(), ...(draft.meta || {}) },
        wagons: Array.from({ length: MAX_WAGONS }, (_, index) => ({
            ...emptyWagon(),
            ...(draft.wagons?.[index] || {}),
        })),
        brakeSheet: { ...emptyBrakeSheet(), ...(draft.brakeSheet || {}) },
        visibleCount,
    };
};

export function wagonListPrototype(config = {}) {
    return {
        activeSheet: 'wagons',
        storageKey: String(config.storageKey || 'rt-wagon-list-prototype:v1'),
        drafts: [],
        activeDraftId: null,
        editorOpen: false,
        hydrating: false,
        storageError: false,
        modalReturnFocus: null,
        visibleCount: 3,
        openWagon: 0,
        mobileWagon: 0,
        persistedAt: null,
        persistTimer: null,
        exporting: false,
        wagonTouchStartX: null,
        wagonTouchStartY: null,
        meta: emptyMeta(),
        wagons: Array.from({ length: MAX_WAGONS }, emptyWagon),
        brakeSheet: emptyBrakeSheet(),

        init() {
            this.restoreDrafts();

            const persistWhenEditing = () => {
                if (!this.hydrating && this.editorOpen && this.activeDraftId) {
                    this.schedulePersist();
                }
            };

            this.$watch('meta', persistWhenEditing);
            this.$watch('wagons', persistWhenEditing);
            this.$watch('brakeSheet', persistWhenEditing);
            this.$watch('visibleCount', persistWhenEditing);
        },

        destroy() {
            window.clearTimeout(this.persistTimer);
            if (this.editorOpen && this.activeDraftId) {
                this.persistDraft();
            }
            this.unlockEditor();
        },

        restoreDrafts() {
            try {
                const stored = JSON.parse(localStorage.getItem(this.storageKey) || 'null');
                if (!stored) return;

                if (stored.version === STORAGE_VERSION && Array.isArray(stored.drafts)) {
                    this.drafts = stored.drafts
                        .filter((draft) => draft && typeof draft === 'object')
                        .map((draft) => normalizeDraft(draft));
                    return;
                }

                // Bestehende Einzelentwuerfe aus v1 ohne Datenverlust in die
                // neue Mehrfachablage uebernehmen.
                if (stored.version === 1 && (stored.meta || stored.wagons || stored.brakeSheet)) {
                    this.drafts = [normalizeDraft(stored)];
                    this.saveCollection(this.drafts);
                    return;
                }

                localStorage.removeItem(this.storageKey);
            } catch (_) {
                localStorage.removeItem(this.storageKey);
            }
        },

        schedulePersist() {
            window.clearTimeout(this.persistTimer);
            this.persistTimer = window.setTimeout(() => this.persistDraft(), 250);
        },

        persistDraft() {
            window.clearTimeout(this.persistTimer);

            if (!this.activeDraftId) return false;

            const current = this.drafts.find((draft) => draft.id === this.activeDraftId);
            const persistedAt = new Date().toISOString();
            const updatedDraft = normalizeDraft({
                id: this.activeDraftId,
                createdAt: current?.createdAt || persistedAt,
                persistedAt,
                meta: this.meta,
                wagons: this.wagons,
                brakeSheet: this.brakeSheet,
                visibleCount: this.visibleCount,
            }, this.activeDraftId);
            const nextDrafts = [
                updatedDraft,
                ...this.drafts.filter((draft) => draft.id !== this.activeDraftId),
            ];

            if (!this.saveCollection(nextDrafts)) return false;

            this.drafts = nextDrafts;
            this.persistedAt = persistedAt;
            this.storageError = false;

            return true;
        },

        saveCollection(drafts) {
            try {
                localStorage.setItem(this.storageKey, JSON.stringify({
                    version: STORAGE_VERSION,
                    drafts,
                }));

                return true;
            } catch (_) {
                this.storageError = true;
                this.notify(config.saveError || 'Der Entwurf konnte nicht gespeichert werden.', 'error', 4600);

                return false;
            }
        },

        createDraft(returnFocus = null) {
            const draft = normalizeDraft({ id: createDraftId() });
            const nextDrafts = [draft, ...this.drafts];

            if (!this.saveCollection(nextDrafts)) return false;

            this.drafts = nextDrafts;
            this.openDraft(draft.id, returnFocus);

            return true;
        },

        openDraft(id, returnFocus = null) {
            const draft = this.drafts.find((entry) => entry.id === id);
            if (!draft) return;

            this.hydrating = true;
            this.activeDraftId = draft.id;
            this.meta = { ...emptyMeta(), ...draft.meta };
            this.wagons = Array.from({ length: MAX_WAGONS }, (_, index) => ({
                ...emptyWagon(),
                ...(draft.wagons[index] || {}),
            }));
            this.brakeSheet = { ...emptyBrakeSheet(), ...draft.brakeSheet };
            this.visibleCount = Math.max(3, Math.min(MAX_WAGONS, Number(draft.visibleCount || 3)));
            this.openWagon = 0;
            this.mobileWagon = 0;
            this.persistedAt = draft.persistedAt || null;
            this.modalReturnFocus = returnFocus && typeof returnFocus.focus === 'function'
                ? returnFocus
                : this.$refs?.newDraftButton;
            this.editorOpen = true;
            this.lockEditor();

            this.$nextTick(() => {
                this.hydrating = false;
                this.$refs?.editorHeading?.focus();
            });
        },

        saveDraft(showNotification = true) {
            const saved = this.persistDraft();
            if (saved && showNotification) {
                this.notify(config.draftSaved || 'Entwurf lokal gespeichert');
            }

            return saved;
        },

        saveAndClose() {
            if (!this.saveDraft(false)) return;
            this.finishClosingEditor();
        },

        cancelEditor() {
            this.saveAndClose();
        },

        handleEscape(event) {
            if (!this.editorOpen || event.defaultPrevented) return;

            event.preventDefault();
            this.cancelEditor();
        },

        finishClosingEditor() {
            this.editorOpen = false;
            this.activeDraftId = null;
            this.unlockEditor();

            const returnFocus = this.modalReturnFocus;
            this.modalReturnFocus = null;
            this.$nextTick(() => returnFocus?.focus?.());
        },

        lockEditor() {
            if (typeof document === 'undefined') return;

            document.documentElement.classList.add('rt-wagon-editor-is-open');
            document.body.classList.add('rt-wagon-editor-is-open');
        },

        unlockEditor() {
            if (typeof document === 'undefined') return;

            document.documentElement.classList.remove('rt-wagon-editor-is-open');
            document.body.classList.remove('rt-wagon-editor-is-open');
        },

        trapEditorFocus(event) {
            if (!this.editorOpen || event.key !== 'Tab') return;

            const dialog = this.$refs?.editorDialog;
            if (!dialog) return;

            const focusable = Array.from(dialog.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )).filter((element) => element.offsetParent !== null);
            if (!focusable.length) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        async confirmDeletion({ title, text, confirmButtonText }) {
            if (window.Swal) {
                const result = await window.Swal.fire({
                    title,
                    text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText,
                    cancelButtonText: config.cancel || 'Abbrechen',
                    confirmButtonColor: '#e4002b',
                    focusCancel: true,
                });

                return result.isConfirmed;
            }

            return window.confirm(`${title}\n\n${text}`);
        },

        async deleteDraft(id) {
            const confirmed = await this.confirmDeletion({
                title: config.deleteTitle || 'Entwurf wirklich löschen?',
                text: config.deleteText || 'Diese lokal gespeicherte Wagenliste kann nicht wiederhergestellt werden.',
                confirmButtonText: config.deleteConfirm || 'Entwurf löschen',
            });
            if (!confirmed) return;

            const nextDrafts = this.drafts.filter((draft) => draft.id !== id);
            if (!this.saveCollection(nextDrafts)) return;

            this.drafts = nextDrafts;

            if (this.activeDraftId === id) {
                this.finishClosingEditor();
            }
        },

        async deleteAllDrafts() {
            if (!this.drafts.length) return;

            const confirmed = await this.confirmDeletion({
                title: config.deleteAllTitle || 'Alle Entwürfe löschen?',
                text: config.deleteAllText || 'Alle lokal gespeicherten Wagenlisten werden endgültig entfernt.',
                confirmButtonText: config.deleteAllConfirm || 'Alle löschen',
            });
            if (!confirmed) return;

            if (!this.saveCollection([])) return;

            this.drafts = [];
        },

        async resetDraft() {
            if (!this.activeDraftId) return;
            await this.deleteDraft(this.activeDraftId);
        },

        notify(title, icon = 'success', timer = 2600) {
            window.Swal?.fire({
                toast: true,
                position: 'top-end',
                timer,
                showConfirmButton: false,
                icon,
                title,
            });
        },

        async exportWorkbook() {
            if (this.exporting || !config.exportUrl) return;

            if (!this.persistDraft()) return;
            this.exporting = true;

            try {
                const response = await fetch(config.exportUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        meta: this.meta,
                        wagons: this.wagons.slice(0, this.visibleCount),
                        brakeSheet: this.brakeSheet,
                    }),
                });

                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    const validationMessage = Object.values(error.errors || {}).flat()[0];
                    throw new Error(validationMessage || error.message || config.exportError);
                }

                const blob = await response.blob();
                const disposition = response.headers.get('Content-Disposition') || '';
                const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
                const fallbackName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
                const filename = encodedName
                    ? decodeURIComponent(encodedName)
                    : (fallbackName || 'RailTime_Wagenliste.xlsx');
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1500);

                this.notify(config.exportSuccess);
            } catch (error) {
                this.notify(error?.message || config.exportError, 'error', 4200);
            } finally {
                this.exporting = false;
            }
        },

        addWagon() {
            if (this.visibleCount >= MAX_WAGONS) return;
            this.openWagon = this.visibleCount;
            this.mobileWagon = this.visibleCount;
            this.visibleCount += 1;
            this.$nextTick(() => this.$root.querySelector('[data-mobile-wagon-editor]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },

        showMobileWagon(index) {
            this.mobileWagon = Math.max(0, Math.min(this.visibleCount - 1, Number(index)));
        },

        previousMobileWagon() {
            this.showMobileWagon(this.mobileWagon - 1);
        },

        nextMobileWagon() {
            this.showMobileWagon(this.mobileWagon + 1);
        },

        wagonTouchStart(event) {
            if (window.innerWidth >= 1024 || event.touches.length !== 1) {
                this.cancelWagonSwipe();
                return;
            }

            if (event.target.closest('input, textarea, button, [contenteditable="true"], [data-no-wagon-swipe]')) {
                this.cancelWagonSwipe();
                return;
            }

            this.wagonTouchStartX = event.touches[0].clientX;
            this.wagonTouchStartY = event.touches[0].clientY;
        },

        wagonTouchEnd(event) {
            if (this.wagonTouchStartX === null || event.changedTouches.length !== 1) {
                this.cancelWagonSwipe();
                return;
            }

            const deltaX = event.changedTouches[0].clientX - this.wagonTouchStartX;
            const deltaY = event.changedTouches[0].clientY - this.wagonTouchStartY;
            const threshold = Math.max(54, Math.min(96, window.innerWidth * 0.18));

            if (Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.25) {
                deltaX < 0 ? this.nextMobileWagon() : this.previousMobileWagon();
            }

            this.cancelWagonSwipe();
        },

        cancelWagonSwipe() {
            this.wagonTouchStartX = null;
            this.wagonTouchStartY = null;
        },

        focusNextCell(event) {
            const table = event.target.closest('[data-wagon-sheet]');
            if (!table) return;

            const cells = Array.from(table.querySelectorAll('[data-wagon-cell]'))
                .filter((cell) => !cell.disabled && cell.offsetParent !== null);
            const currentIndex = cells.indexOf(event.target);
            const next = cells[currentIndex + 1];

            if (next) {
                next.focus();
                next.select?.();
            }
        },

        clearWagon(index) {
            this.wagons[index] = emptyWagon();
            this.schedulePersist();
        },

        cycleBrakeType(wagon) {
            const values = ['', 'K', 'L', 'LL'];
            wagon.brakeType = values[(values.indexOf(wagon.brakeType) + 1) % values.length];
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

        get completionCount() {
            return this.wagons.slice(0, this.visibleCount).filter((wagon) => this.isWagonFilled(wagon)).length;
        },

        get sortedDrafts() {
            return [...this.drafts].sort((left, right) => {
                const leftTime = Date.parse(left.persistedAt || left.createdAt || '') || 0;
                const rightTime = Date.parse(right.persistedAt || right.createdAt || '') || 0;

                return rightTime - leftTime;
            });
        },

        draftTitle(draft) {
            const trainNumber = String(draft?.meta?.trainNumber || '').trim();
            if (trainNumber) {
                return `${config.trainLabel || 'Zug'} ${trainNumber}`;
            }

            const reference = String(draft?.meta?.reference || '').trim();

            return reference || config.untitledDraft || 'Wagenliste ohne Zugnummer';
        },

        draftRoute(draft) {
            const origin = String(draft?.meta?.origin || '').trim();
            const destination = String(draft?.meta?.destination || '').trim();

            if (!origin && !destination) {
                return config.noRoute || 'Laufweg noch nicht eingetragen';
            }

            return `${origin || '—'} → ${destination || '—'}`;
        },

        draftWagonCount(draft) {
            return (draft?.wagons || [])
                .slice(0, Number(draft?.visibleCount || MAX_WAGONS))
                .filter((wagon) => this.isWagonFilled(wagon))
                .length;
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

        formatDate(value) {
            if (!value) return '—';

            const date = /^\d{4}-\d{2}-\d{2}$/.test(String(value))
                ? new Date(`${value}T12:00:00`)
                : new Date(value);
            if (Number.isNaN(date.getTime())) return '—';

            return new Intl.DateTimeFormat(config.locale || 'de-DE', {
                dateStyle: 'medium',
            }).format(date);
        },

        formatSavedAt(value = this.persistedAt) {
            if (!value) return config.notSaved || 'Noch nicht gespeichert';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return config.notSaved || 'Noch nicht gespeichert';

            return new Intl.DateTimeFormat(config.locale || 'de-DE', {
                dateStyle: 'short',
                timeStyle: 'short',
            }).format(date);
        },
    };
}
