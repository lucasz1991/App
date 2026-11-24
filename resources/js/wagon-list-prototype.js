import {
    BRAKE_SOLE_TYPES,
    MAX_WAGONS,
    checkState as ruleCheckState,
    computeBrakeSheet,
    computeChecks,
    computeTotals,
    emptyBrakeSheet,
    emptyMeta,
    emptyReport,
    emptyWagon,
    expectedCheckDigit as ruleExpectedCheckDigit,
    hasValue,
    isWagonFilled as ruleIsWagonFilled,
    localToday,
    missingWagonFields,
    normalizeBrakeSheet,
    normalizeReport,
    normalizeWagon,
    numeric,
    splitWagonNumber,
    trainEnds,
    trainStrip,
    wagonDigits as ruleWagonDigits,
    wagonNumber as ruleWagonNumber,
    wagonStatus as ruleWagonStatus,
    wagonTotalWeight,
} from './wagon-list-rules.js';

// Speicherformat 3 = Datenmodell der Excel-Wagenliste v16_5. Entwürfe aus
// Format 2 (zwei Achsspalten, Scheibenbremse als Kästchen) werden beim Laden
// umgestellt, der einzelne Entwurf aus Format 1 ebenso.
const STORAGE_VERSION = 3;
const LEGACY_STORAGE_VERSION = 2;
const ASSISTANT_EVENT_VERSION = 1;
const ASSISTANT_FIELD_PRESENCE_VERSION = 1;
const ASSISTANT_COMMANDS = new Set([
    'start',
    'next',
    'previous',
    'select_wagon',
    'save',
    'set_field',
]);
const ASSISTANT_STEP_IDS = [
    'train',
    'identity',
    'vehicle',
    'brakes',
    'route',
    'calculation',
    'special',
    'review',
];

// Mobil liegen diese Schritte nicht mehr auf eigenen Folien, sondern als
// Anker im Wagen-Modal — goToMobileStep() oeffnet dafuer das Modal.
// Alle uebrigen Schritte sind Anker auf der einen Formularseite.
const WAGON_MODAL_STEP_IDS = ['identity', 'vehicle', 'brakes', 'route'];
const ASSISTANT_META_FIELDS = [
    'meta.trainNumber',
    'meta.date',
    'meta.origin',
    'meta.destination',
    'meta.reference',
    'meta.customer',
    'meta.track',
];
const ASSISTANT_WAGON_FIELDS = [
    'wagon.number',
    'wagon.category',
    'wagon.axles',
    'wagon.length',
    'wagon.wagonWeight',
    'wagon.loadWeight',
    'wagon.brakeG',
    'wagon.brakeP',
    'wagon.shippingStation',
    'wagon.destinationStation',
    'wagon.brakeType',
    'wagon.parkingBrake',
    'wagon.maxSpeed',
    'wagon.unNumber',
    'wagon.hazardLabel',
    'wagon.remark',
];
const ASSISTANT_BRAKE_SHEET_FIELDS = [
    'brakeSheet.tractionWeight',
    'brakeSheet.tractionBrakeWeight',
    'brakeSheet.tractionAxles',
    'brakeSheet.tractionLength',
    'brakeSheet.minimumBrakePercentage',
    'brakeSheet.brakePosition',
    'brakeSheet.timetableLoad',
    'brakeSheet.timetableLength',
    'brakeSheet.timetableSpeed',
    'brakeSheet.nbuepBrake',
    'brakeSheet.emergencyBrakeBridge',
    'brakeSheet.passengerFeatureHzee',
    'brakeSheet.passengerFeatureNOe',
    'brakeSheet.passengerFeatureTb0',
    'brakeSheet.passengerFeatureOZub',
    'brakeSheet.passengerFeatureOther',
    'brakeSheet.dangerousGoods',
    'brakeSheet.epBrake',
    'brakeSheet.issuerName',
];

// Feldarten für set_field. Sonderfälle (Datum, Wagennummer, Bremssohle,
// UN-Nummer, Bremsstellung) behandelt setAssistantField() selbst.
const ASSISTANT_TEXT_LIMITS = {
    'meta.trainNumber': 80,
    'meta.origin': 120,
    'meta.destination': 120,
    'meta.reference': 160,
    'meta.customer': 120,
    'meta.track': 40,
    'wagon.category': 80,
    'wagon.shippingStation': 120,
    'wagon.destinationStation': 120,
    'wagon.hazardLabel': 20,
    'wagon.remark': 255,
    'brakeSheet.issuerName': 160,
};
const ASSISTANT_NUMBER_FIELDS = new Set([
    'wagon.axles',
    'wagon.length',
    'wagon.wagonWeight',
    'wagon.loadWeight',
    'wagon.brakeG',
    'wagon.brakeP',
    'wagon.parkingBrake',
    'wagon.maxSpeed',
    'brakeSheet.tractionWeight',
    'brakeSheet.tractionBrakeWeight',
    'brakeSheet.tractionAxles',
    'brakeSheet.tractionLength',
    'brakeSheet.minimumBrakePercentage',
    'brakeSheet.timetableLoad',
    'brakeSheet.timetableLength',
    'brakeSheet.timetableSpeed',
]);
const ASSISTANT_YES_NO_FIELDS = new Set([
    'brakeSheet.nbuepBrake',
    'brakeSheet.emergencyBrakeBridge',
    'brakeSheet.passengerFeatureHzee',
    'brakeSheet.passengerFeatureNOe',
    'brakeSheet.passengerFeatureTb0',
    'brakeSheet.passengerFeatureOZub',
    'brakeSheet.passengerFeatureOther',
    'brakeSheet.dangerousGoods',
    'brakeSheet.epBrake',
]);

// Die Ja/Nein-Fragen der besonderen Angaben — Reihenfolge wie im Bremszettel.
// Dient dem Fortschritt ("x von 8 beantwortet"); die Anzeige gruppiert sie in
// resources/views/.../partials/wagon-special-information.blade.php.
// "Gefahrgut" steht nicht mehr in der Liste: Die v16_5 leitet die Angabe aus
// UN-Nummer und Gefahrzettel der Wagen ab, sie lässt sich nur noch übersteuern.
const SPECIAL_FIELDS = [
    'nbuepBrake',
    'emergencyBrakeBridge',
    'epBrake',
    'passengerFeatureHzee',
    'passengerFeatureNOe',
    'passengerFeatureTb0',
    'passengerFeatureOZub',
    'passengerFeatureOther',
];

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
        assistantFieldPresenceVersion: ASSISTANT_FIELD_PRESENCE_VERSION,
        meta: { ...emptyMeta(), ...(draft.meta || {}) },
        wagons: Array.from({ length: MAX_WAGONS }, (_, index) => normalizeWagon(draft.wagons?.[index])),
        brakeSheet: normalizeBrakeSheet(draft.brakeSheet),
        report: normalizeReport(draft.report),
        visibleCount,
    };
};

export function wagonListPrototype(config = {}) {
    // ERA-Gattungszeichen (Ziffern 5-8 -> Gattung). Bewusst außerhalb des
    // reaktiven Zustands: rund 3900 Einträge, die nur nachgeschlagen werden.
    let eraTypes = null;
    let eraTypesPromise = null;

    return {
        storageKey: String(config.storageKey || 'rt-wagon-list-prototype:v1'),
        drafts: [],
        activeDraftId: null,
        editorOpen: false,
        hydrating: false,
        storageError: false,
        modalReturnFocus: null,
        visibleCount: 3,
        openWagon: 0,
        desktopSection: 'wagons',
        desktopTableMode: 'quick',
        // Seitenleiste mit Summen und Prüfung; unter 1280 px zunächst zu,
        // damit die Wagentabelle die Breite bekommt.
        desktopSummaryOpen: typeof window === 'undefined' || !(window.innerWidth < 1280),
        desktopWagon: 0,
        mobileWagon: 0,
        mobileStep: 0,
        mobileSteps: Array.isArray(config.mobileSteps) ? config.mobileSteps : [],
        eraReady: false,
        checkLabels: config.checkLabels && typeof config.checkLabels === 'object' ? config.checkLabels : {},
        missingLabels: config.missingLabels && typeof config.missingLabels === 'object' ? config.missingLabels : {},
        wagonModalOpen: false,
        persistedAt: null,
        persistTimer: null,
        assistantContextNonce: null,
        assistantContextTimer: null,
        assistantContextReason: 'updated',
        assistantHandledActionTokens: [],
        exporting: false,
        meta: emptyMeta(),
        wagons: Array.from({ length: MAX_WAGONS }, emptyWagon),
        brakeSheet: emptyBrakeSheet(),
        report: emptyReport(),

        init() {
            this.restoreDrafts();
            this.assistantContextNonce = this.createAssistantContextNonce();

            const persistWhenEditing = () => {
                if (!this.hydrating && this.editorOpen && this.activeDraftId) {
                    this.schedulePersist();
                    this.scheduleAssistantContext('fields-updated');
                }
            };

            this.$watch('meta', persistWhenEditing);
            this.$watch('wagons', () => {
                this.syncDerivedWagonFields();
                persistWhenEditing();
            });
            this.$watch('brakeSheet', persistWhenEditing);
            this.$watch('report', persistWhenEditing);
            this.$watch('visibleCount', persistWhenEditing);
            this.$watch('mobileStep', () => this.scheduleAssistantContext('step-updated'));
            this.$watch('mobileWagon', () => this.scheduleAssistantContext('wagon-updated'));
            this.$watch('desktopSection', () => this.scheduleAssistantContext('step-updated'));
            this.$watch('desktopWagon', () => this.scheduleAssistantContext('wagon-updated'));

            this.$nextTick(() => {
                if (config.proof) {
                    if (this.drafts.length) this.openDraft(this.drafts[0].id);
                    else this.createDraft();
                }
                this.dispatchAssistantContext('overview-ready', false);
            });
        },

        destroy() {
            window.clearTimeout(this.persistTimer);
            window.clearTimeout(this.assistantContextTimer);
            this.motion()?.dispose();
            if (this.editorOpen && this.activeDraftId) {
                this.persistDraft();
                this.dispatchAssistantContext('editor-closed', false);
            }
            this.assistantContextNonce = null;
            this.unlockEditor();
        },

        restoreDrafts() {
            try {
                const stored = JSON.parse(localStorage.getItem(this.storageKey) || 'null');
                if (!stored) return;

                if ([STORAGE_VERSION, LEGACY_STORAGE_VERSION].includes(stored.version) && Array.isArray(stored.drafts)) {
                    this.drafts = stored.drafts
                        .filter((draft) => draft && typeof draft === 'object')
                        .map((draft) => normalizeDraft(draft));
                    if (stored.version !== STORAGE_VERSION) this.saveCollection(this.drafts);
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
                assistantFieldPresenceVersion: ASSISTANT_FIELD_PRESENCE_VERSION,
                meta: this.meta,
                wagons: this.wagons,
                brakeSheet: this.brakeSheet,
                report: this.report,
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
            const draft = normalizeDraft({ ...config.proof?.draft, id: createDraftId(), visibleCount: config.proof?.draft?.wagons?.length || undefined });
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
            this.assistantContextNonce = this.createAssistantContextNonce();
            this.assistantHandledActionTokens = [];
            this.activeDraftId = draft.id;
            this.meta = { ...emptyMeta(), ...draft.meta };
            this.wagons = Array.from({ length: MAX_WAGONS }, (_, index) => normalizeWagon(draft.wagons[index]));
            this.brakeSheet = normalizeBrakeSheet(draft.brakeSheet);
            this.report = normalizeReport(draft.report);
            this.visibleCount = Math.max(3, Math.min(MAX_WAGONS, Number(draft.visibleCount || 3)));
            this.openWagon = 0;
            this.desktopSection = 'wagons';
            this.desktopWagon = 0;
            this.mobileWagon = 0;
            this.mobileStep = 0;
            this.wagonModalOpen = false;
            this.persistedAt = draft.persistedAt || null;
            this.modalReturnFocus = returnFocus && typeof returnFocus.focus === 'function'
                ? returnFocus
                : this.$refs?.newDraftButton;
            this.editorOpen = true;
            this.lockEditor();

            this.loadEraTypes();

            this.$nextTick(() => {
                this.hydrating = false;
                this.syncDerivedWagonFields();
                this.$refs?.editorHeading?.focus();
                this.motion()?.editorOpened(this.$refs?.editorDialog);
                this.animateStep(0);
                this.dispatchAssistantContext('editor-opened');
                this.dispatchAssistantHelp();
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
            this.persistDraft();
            this.finishClosingEditor();
        },

        handleEscape(event) {
            if (!this.editorOpen || event.defaultPrevented) return;

            const chatbotRoot = typeof document !== 'undefined' && typeof document.querySelector === 'function'
                ? document.querySelector('[data-railtime-chatbot-root]')
                : null;
            const chatbotPanel = chatbotRoot?.querySelector?.('.rt-chatbot__panel');
            const chatbotPanelVisible = Boolean(chatbotPanel && (
                chatbotPanel.offsetParent !== null
                || (typeof chatbotPanel.getClientRects === 'function' && chatbotPanel.getClientRects().length > 0)
            ));
            const escapeBelongsToChatbot = Boolean(
                event.target?.closest?.('[data-railtime-chatbot-root]')
                || chatbotPanelVisible,
            );

            if (escapeBelongsToChatbot) return;

            // Offene Video-Erfassung: deren eigener Escape-Handler schliesst
            // sie — der Editor bleibt offen.
            if (typeof document !== 'undefined' && document.body?.classList?.contains?.('rt-wagon-capture-open')) {
                return;
            }

            // Das Wagen-Modal faengt Escape zuerst — erst der zweite Druck
            // schliesst den Editor selbst.
            if (this.wagonModalOpen) {
                event.preventDefault();
                this.closeWagonModal();
                return;
            }

            event.preventDefault();
            this.cancelEditor();
        },

        finishClosingEditor() {
            const closingNonce = this.assistantContextNonce;
            this.motion()?.dispose();
            this.editorOpen = false;
            this.activeDraftId = null;
            this.dispatchAssistantContext('editor-closed', false, closingNonce);
            this.assistantContextNonce = this.createAssistantContextNonce();
            this.assistantHandledActionTokens = [];
            this.dispatchAssistantContext('overview-ready', false);
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

        createAssistantContextNonce() {
            if (typeof globalThis.crypto?.randomUUID === 'function') {
                return globalThis.crypto.randomUUID();
            }

            if (typeof globalThis.crypto?.getRandomValues === 'function') {
                const bytes = new Uint8Array(16);
                globalThis.crypto.getRandomValues(bytes);

                return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
            }

            return `ctx-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
        },

        assistantCurrentWagonIndex() {
            const desktop = typeof window !== 'undefined' && window.innerWidth >= 1024;
            const requested = desktop ? this.desktopWagon : this.mobileWagon;

            return Math.max(0, Math.min(this.visibleCount - 1, Number(requested) || 0));
        },

        assistantCurrentStepIndex() {
            const mobileStep = Math.max(0, Math.min(
                ASSISTANT_STEP_IDS.length - 1,
                Number(this.mobileStep) || 0,
            ));
            const desktop = typeof window !== 'undefined' && window.innerWidth >= 1024;
            const brakeSheetStep = ASSISTANT_STEP_IDS.indexOf('calculation');

            if (!desktop) return mobileStep;
            if (this.desktopSection === 'brakeSheet') return Math.max(brakeSheetStep, mobileStep);
            if (this.desktopSection === 'report') return ASSISTANT_STEP_IDS.indexOf('special');

            return mobileStep < brakeSheetStep ? mobileStep : 0;
        },

        assistantHasValue(value) {
            if (typeof value === 'boolean') return value;

            return String(value ?? '').trim() !== '';
        },

        assistantMetaPresence() {
            return Object.fromEntries(ASSISTANT_META_FIELDS.map((field) => [
                field,
                this.assistantHasValue(this.meta[field.slice('meta.'.length)]),
            ]));
        },

        assistantCurrentWagonPresence() {
            const wagon = this.wagons[this.assistantCurrentWagonIndex()] || emptyWagon();
            const fullNumber = `${this.wagonDigits(wagon)}${String(wagon.checkDigit ?? '').replace(/\D/g, '')}`;

            return Object.fromEntries(ASSISTANT_WAGON_FIELDS.map((field) => [
                field,
                field === 'wagon.number'
                    ? fullNumber.length === 12
                    : this.assistantHasValue(wagon[field.slice('wagon.'.length)]),
            ]));
        },

        assistantBrakeSheetPresence() {
            return Object.fromEntries(ASSISTANT_BRAKE_SHEET_FIELDS.map((field) => [
                field,
                this.assistantHasValue(this.brakeSheet[field.slice('brakeSheet.'.length)]),
            ]));
        },

        assistantContextPayload(eventName = 'updated', editorOpen = this.editorOpen, contextNonce = this.assistantContextNonce) {
            const stepIndex = this.assistantCurrentStepIndex();
            const isOpen = Boolean(editorOpen && contextNonce);

            return {
                version: ASSISTANT_EVENT_VERSION,
                event: String(eventName || 'updated'),
                context_nonce: String(contextNonce || ''),
                editor_open: isOpen,
                current_step: isOpen ? ASSISTANT_STEP_IDS[stepIndex] : null,
                current_step_index: isOpen ? stepIndex : null,
                // Der oeffentliche Eventvertrag ist bewusst 1-basiert; nur
                // intern arbeitet das Array weiterhin mit 0-basierten Indizes.
                current_wagon_index: isOpen ? this.assistantCurrentWagonIndex() + 1 : null,
                visible_wagons: isOpen ? this.visibleCount : 0,
                filled_wagons: isOpen ? this.completionCount : 0,
                presence: isOpen ? {
                    meta: this.assistantMetaPresence(),
                    brake_sheet: this.assistantBrakeSheetPresence(),
                } : {
                    meta: {},
                    brake_sheet: {},
                },
                current_wagon_fields: isOpen ? this.assistantCurrentWagonPresence() : {},
                allowed_meta_fields: isOpen ? [...ASSISTANT_META_FIELDS] : [],
                allowed_brake_sheet_fields: isOpen ? [...ASSISTANT_BRAKE_SHEET_FIELDS] : [],
                allowed_current_wagon_fields: isOpen ? [...ASSISTANT_WAGON_FIELDS] : [],
            };
        },

        dispatchAssistantContext(eventName = 'updated', editorOpen = this.editorOpen, contextNonce = this.assistantContextNonce) {
            if (typeof window?.dispatchEvent !== 'function') return;

            window.dispatchEvent(new CustomEvent('railtime-wagon-context-updated', {
                detail: this.assistantContextPayload(eventName, editorOpen, contextNonce),
            }));
        },

        scheduleAssistantContext(eventName = 'updated') {
            if (!this.editorOpen || !this.assistantContextNonce || this.hydrating) return;

            this.assistantContextReason = String(eventName || 'updated');
            window.clearTimeout(this.assistantContextTimer);
            this.assistantContextTimer = window.setTimeout(() => {
                this.assistantContextTimer = null;
                this.dispatchAssistantContext(this.assistantContextReason);
            }, 280);
        },

        dispatchAssistantHelp() {
            if (!this.editorOpen || !this.assistantContextNonce || typeof window?.dispatchEvent !== 'function') return;

            window.dispatchEvent(new CustomEvent('railtime-wagon-assistant-help', {
                detail: this.assistantContextPayload('help-requested'),
            }));
        },

        normalizeAssistantCommand(rawDetail) {
            const detail = Array.isArray(rawDetail) ? (rawDetail[0] ?? null) : rawDetail;

            return detail && typeof detail === 'object' && !Array.isArray(detail) ? detail : null;
        },

        assistantActionToken(value) {
            const token = typeof value === 'string' ? value.trim() : '';

            return /^[A-Za-z0-9._:-]{1,180}$/.test(token) ? token : '';
        },

        dispatchAssistantResult(actionToken, status, command, reason = null, contextNonce = this.assistantContextNonce) {
            if (typeof window?.dispatchEvent !== 'function') return;

            const payload = this.assistantContextPayload('command-result');
            const detail = {
                version: ASSISTANT_EVENT_VERSION,
                action_token: String(actionToken || ''),
                context_nonce: String(contextNonce || ''),
                command: String(command || ''),
                status,
                current_step: payload.current_step,
                current_step_index: payload.current_step_index,
                current_wagon_index: payload.current_wagon_index,
                visible_wagons: payload.visible_wagons,
                filled_wagons: payload.filled_wagons,
                presence: payload.presence,
                current_wagon_fields: payload.current_wagon_fields,
            };

            if (reason) detail.reason = String(reason);

            window.dispatchEvent(new CustomEvent('railtime-wagon-assistant-result', { detail }));
        },

        handleAssistantCommand(rawDetail) {
            const detail = this.normalizeAssistantCommand(rawDetail);
            const actionToken = this.assistantActionToken(detail?.action_token);
            const command = typeof detail?.command === 'string' ? detail.command.trim() : '';
            const contextNonce = typeof detail?.context_nonce === 'string' ? detail.context_nonce : '';

            if (!detail || Number(detail.version) !== ASSISTANT_EVENT_VERSION || !actionToken) {
                this.dispatchAssistantResult(actionToken, 'rejected', command, 'invalid_contract', contextNonce);
                return;
            }

            if (contextNonce !== this.assistantContextNonce) {
                this.dispatchAssistantResult(actionToken, 'stale_context', command, 'context_mismatch', contextNonce);
                return;
            }

            if (!ASSISTANT_COMMANDS.has(command)) {
                this.dispatchAssistantResult(actionToken, 'rejected', command, 'unknown_command', contextNonce);
                return;
            }

            if (this.assistantHandledActionTokens.includes(actionToken)) {
                this.dispatchAssistantResult(actionToken, 'rejected', command, 'duplicate_action', contextNonce);
                return;
            }

            this.assistantHandledActionTokens = [
                ...this.assistantHandledActionTokens,
                actionToken,
            ].slice(-120);

            // Auf der Entwurfsuebersicht bestaetigt "start" das Anlegen eines
            // neuen lokalen Entwurfs. createDraft() persistiert synchron und
            // openDraft() vergibt danach einen frischen Editor-Nonce.
            if (command === 'start' && !this.editorOpen) {
                if (!this.createDraft()) {
                    this.dispatchAssistantResult(actionToken, 'storage_error', command, 'draft_not_created', contextNonce);
                    return;
                }

                this.dispatchAssistantResult(actionToken, 'applied', command, null, contextNonce);
                return;
            }

            if (!this.editorOpen || !this.activeDraftId || !this.assistantContextNonce) {
                this.dispatchAssistantResult(actionToken, 'stale_context', command, 'editor_closed', contextNonce);
                return;
            }

            if (command === 'set_field') {
                this.applyAssistantFieldCommand(detail, actionToken, contextNonce);
                return;
            }

            if (command === 'select_wagon') {
                this.applyAssistantWagonSelection(detail, actionToken, contextNonce);
                return;
            }

            if (!this.persistDraft()) {
                this.dispatchAssistantResult(actionToken, 'storage_error', command, 'draft_not_persisted', contextNonce);
                return;
            }

            if (command === 'start') {
                this.applyAssistantStep(0);
                this.selectAssistantWagon(0);
            } else if (command === 'next') {
                this.applyAssistantStep(this.assistantCurrentStepIndex() + 1);
            } else if (command === 'previous') {
                this.applyAssistantStep(this.assistantCurrentStepIndex() - 1);
            }

            this.dispatchAssistantContext('command-applied');
            this.dispatchAssistantResult(actionToken, 'applied', command, null, contextNonce);
        },

        applyAssistantStep(index) {
            const target = Math.max(0, Math.min(ASSISTANT_STEP_IDS.length - 1, Number(index) || 0));
            this.mobileStep = target;
            this.desktopSection = target >= 5 ? 'brakeSheet' : 'wagons';
            this.goToMobileStep(target, 'auto');
        },

        selectAssistantWagon(index) {
            const target = Math.max(0, Math.min(this.visibleCount - 1, Number(index) || 0));
            this.openWagon = target;
            this.desktopWagon = target;
            this.showMobileWagon(target);
        },

        applyAssistantWagonSelection(detail, actionToken, contextNonce) {
            const requestedWagon = Number(detail.wagon_index);

            if (!Number.isInteger(requestedWagon) || requestedWagon < 1 || requestedWagon > this.visibleCount) {
                this.dispatchAssistantResult(actionToken, 'rejected', 'select_wagon', 'invalid_wagon_index', contextNonce);
                return;
            }

            const wagonIndex = requestedWagon - 1;

            if (!this.persistDraft()) {
                this.dispatchAssistantResult(actionToken, 'storage_error', 'select_wagon', 'draft_not_persisted', contextNonce);
                return;
            }

            this.selectAssistantWagon(wagonIndex);
            this.dispatchAssistantContext('command-applied');
            this.dispatchAssistantResult(actionToken, 'applied', 'select_wagon', null, contextNonce);
        },

        assistantEditableSnapshot(wagonIndex) {
            return {
                meta: { ...this.meta },
                wagon: { ...(this.wagons[wagonIndex] || emptyWagon()) },
                brakeSheet: { ...this.brakeSheet },
            };
        },

        restoreAssistantEditableSnapshot(snapshot, wagonIndex) {
            this.meta = { ...snapshot.meta };
            this.wagons[wagonIndex] = { ...snapshot.wagon };
            this.brakeSheet = { ...snapshot.brakeSheet };
        },

        applyAssistantFieldCommand(detail, actionToken, contextNonce) {
            const field = typeof detail.field === 'string' ? detail.field.trim() : '';
            const wagonIndex = this.assistantCurrentWagonIndex();

            if (field.startsWith('wagon.')) {
                const expectedWagon = detail.wagon_index;
                const currentWagon = wagonIndex + 1;

                if (
                    !Number.isInteger(expectedWagon)
                    || expectedWagon < 1
                    || expectedWagon > this.visibleCount
                    || expectedWagon !== currentWagon
                ) {
                    this.dispatchAssistantResult(actionToken, 'stale_context', 'set_field', 'wagon_mismatch', contextNonce);
                    return;
                }
            }

            const snapshot = this.assistantEditableSnapshot(wagonIndex);

            if (!this.setAssistantField(field, detail.value, wagonIndex)) {
                this.dispatchAssistantResult(actionToken, 'rejected', 'set_field', 'invalid_field_or_value', contextNonce);
                return;
            }

            if (!this.persistDraft()) {
                this.restoreAssistantEditableSnapshot(snapshot, wagonIndex);
                this.dispatchAssistantResult(actionToken, 'storage_error', 'set_field', 'draft_not_persisted', contextNonce);
                return;
            }

            this.dispatchAssistantContext('command-applied');
            this.dispatchAssistantResult(actionToken, 'applied', 'set_field', null, contextNonce);
        },

        normalizeAssistantText(value, maxLength) {
            if (!['string', 'number'].includes(typeof value)) return { ok: false, value: '' };

            const normalized = String(value)
                .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '')
                .trim();

            return normalized.length <= maxLength
                ? { ok: true, value: normalized }
                : { ok: false, value: '' };
        },

        normalizeAssistantNumber(value) {
            if (value === '' || value === null) return { ok: true, value: '' };
            if (!['string', 'number'].includes(typeof value)) return { ok: false, value: '' };

            const normalized = String(value).trim().replace(',', '.');
            if (!/^(?:\d+|\d*\.\d+)$/.test(normalized)) return { ok: false, value: '' };

            const parsed = Number(normalized);

            return Number.isFinite(parsed) && parsed >= 0
                ? { ok: true, value: normalized }
                : { ok: false, value: '' };
        },

        normalizeAssistantDate(value) {
            const text = this.normalizeAssistantText(value, 10);
            if (!text.ok || text.value === '') return text;
            if (!/^\d{4}-\d{2}-\d{2}$/.test(text.value)) return { ok: false, value: '' };

            const [year, month, day] = text.value.split('-').map(Number);
            const date = new Date(Date.UTC(year, month - 1, day));
            const valid = date.getUTCFullYear() === year
                && date.getUTCMonth() === month - 1
                && date.getUTCDate() === day;

            return valid ? text : { ok: false, value: '' };
        },

        normalizeAssistantBoolean(value) {
            if (value === true || value === 1 || value === '1' || value === 'yes' || value === 'true') {
                return { ok: true, value: true };
            }
            if (value === false || value === 0 || value === '0' || value === 'no' || value === 'false') {
                return { ok: true, value: false };
            }

            return { ok: false, value: false };
        },

        normalizeAssistantYesNo(value) {
            const normalized = typeof value === 'string' ? value.trim().toLowerCase() : value;

            if (normalized === '' || normalized === null) return { ok: true, value: '' };
            if (normalized === true || normalized === 1 || normalized === '1' || normalized === 'yes' || normalized === 'ja') {
                return { ok: true, value: 'yes' };
            }
            if (normalized === false || normalized === 0 || normalized === '0' || normalized === 'no' || normalized === 'nein') {
                return { ok: true, value: 'no' };
            }

            return { ok: false, value: '' };
        },

        setAssistantField(field, rawValue, wagonIndex) {
            const wagon = this.wagons[wagonIndex];
            if (!wagon) return false;

            const allowed = ASSISTANT_META_FIELDS.includes(field)
                || ASSISTANT_WAGON_FIELDS.includes(field)
                || ASSISTANT_BRAKE_SHEET_FIELDS.includes(field);
            if (!allowed) return false;

            const separator = field.indexOf('.');
            const group = field.slice(0, separator);
            const key = field.slice(separator + 1);
            const target = group === 'meta' ? this.meta : (group === 'wagon' ? wagon : this.brakeSheet);
            let normalized;

            switch (field) {
                case 'meta.date':
                    normalized = this.normalizeAssistantDate(rawValue);
                    if (!normalized.ok) return false;
                    this.meta.date = normalized.value;
                    return true;
                case 'wagon.number': {
                    if (!['string', 'number'].includes(typeof rawValue)) return false;
                    const source = String(rawValue).trim();
                    if (source === '') {
                        wagon.number12 = '';
                        wagon.number34 = '';
                        wagon.number58 = '';
                        wagon.number911 = '';
                        wagon.checkDigit = '';
                        return true;
                    }
                    const parts = splitWagonNumber(source);
                    if (!parts) return false;
                    Object.assign(wagon, parts);
                    return true;
                }
                case 'wagon.brakeType': {
                    const brakeType = typeof rawValue === 'string' ? rawValue.trim().toUpperCase() : '';
                    if (brakeType !== '' && !BRAKE_SOLE_TYPES.includes(brakeType)) return false;
                    wagon.brakeType = brakeType;
                    return true;
                }
                case 'wagon.unNumber': {
                    normalized = this.normalizeAssistantText(rawValue, 39);
                    if (!normalized.ok) return false;
                    const unNumber = normalized.value.replace(/\s+/g, '');
                    if (unNumber !== '' && !/^\d{4}(\+\d{4})*$/.test(unNumber)) return false;
                    wagon.unNumber = unNumber;
                    return true;
                }
                case 'brakeSheet.brakePosition': {
                    const position = typeof rawValue === 'string' ? rawValue.trim().toUpperCase() : '';
                    if (!['', 'R/P', 'R', 'P', 'G'].includes(position)) return false;
                    this.brakeSheet.brakePosition = ['R', 'P'].includes(position) ? 'R/P' : position;
                    return true;
                }
                default:
                    break;
            }

            if (Object.prototype.hasOwnProperty.call(ASSISTANT_TEXT_LIMITS, field)) {
                normalized = this.normalizeAssistantText(rawValue, ASSISTANT_TEXT_LIMITS[field]);
            } else if (ASSISTANT_NUMBER_FIELDS.has(field)) {
                normalized = this.normalizeAssistantNumber(rawValue);
            } else if (ASSISTANT_YES_NO_FIELDS.has(field)) {
                normalized = this.normalizeAssistantYesNo(rawValue);
            } else {
                return false;
            }

            if (!normalized.ok) return false;
            target[key] = normalized.value;

            return true;
        },

        trapEditorFocus(event) {
            if (!this.editorOpen || event.key !== 'Tab' || event.defaultPrevented) return;

            const dialog = this.$refs?.editorDialog;
            if (!dialog) return;

            const chatbot = typeof document !== 'undefined' && typeof document.querySelector === 'function'
                ? document.querySelector('[data-railtime-chatbot-root]')
                : null;
            const selector = 'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
            const focusable = [dialog, chatbot]
                .filter(Boolean)
                .flatMap((scope) => Array.from(scope.querySelectorAll(selector)))
                .filter((element) => (
                    (
                        element.offsetParent !== null
                        || (typeof element.getClientRects === 'function' && element.getClientRects().length > 0)
                    )
                    && !element.closest('[inert]')
                    && element.getAttribute('aria-hidden') !== 'true'
                ));
            if (!focusable.length) return;

            const currentIndex = focusable.indexOf(document.activeElement);
            const nextIndex = event.shiftKey
                ? (currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1)
                : (currentIndex < 0 || currentIndex >= focusable.length - 1 ? 0 : currentIndex + 1);

            event.preventDefault();
            focusable[nextIndex]?.focus?.({ preventScroll: true });
        },

        async confirmDeletion({ title, text, confirmButtonText }) {
            return new Promise((resolve) => {
                let settled = false;
                const finish = (value) => {
                    if (settled) return;
                    settled = true;
                    resolve(value);
                };

                window.dispatchEvent(new CustomEvent('rt-confirm', {
                    detail: {
                        title,
                        message: text,
                        variant: 'destructive',
                        confirmLabel: confirmButtonText,
                        cancelLabel: config.cancel || 'Abbrechen',
                        action: () => finish(true),
                        cancel: () => finish(false),
                    },
                }));
            });
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
            if (window.RTAlert?.show) {
                window.RTAlert.show({ title, type: icon, timer });

                return;
            }

            window.Swal?.fire({
                toast: true,
                position: 'top-end',
                timer,
                showConfirmButton: false,
                icon,
                title,
            });
        },

        async exportWorkbook(asProof = false) {
            const endpoint = asProof ? config.proof?.url : config.exportUrl;
            if (this.exporting || !endpoint) return;

            if (!this.persistDraft()) return;
            this.exporting = true;

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: asProof ? 'application/json' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        meta: this.meta,
                        ...(asProof ? { revision: config.proof.revision } : {}),
                        wagons: this.wagons.slice(0, this.visibleCount),
                        brakeSheet: this.brakeSheet,
                        report: this.report,
                    }),
                });

                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    const validationMessage = Object.values(error.errors || {}).flat()[0];
                    throw new Error(validationMessage || error.message || config.exportError);
                }

                if (asProof) {
                    const result = await response.json();
                    window.location.assign(result.redirect);
                    return;
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

        loadEraTypes() {
            if (eraTypes) {
                this.eraReady = true;
                this.syncDerivedWagonFields();

                return;
            }

            if (!eraTypesPromise) {
                const loader = typeof config.eraTypesLoader === 'function'
                    ? config.eraTypesLoader
                    : () => import('./wagon-era-codes.js').then((module) => module.eraWagonTypes());
                eraTypesPromise = Promise.resolve().then(loader);
            }

            eraTypesPromise
                .then((types) => {
                    eraTypes = types instanceof Map ? types : null;
                    this.eraReady = Boolean(eraTypes);
                    this.syncDerivedWagonFields();
                })
                .catch(() => {
                    // Ohne Tabelle bleibt die Gattung ein freies Eingabefeld.
                    eraTypesPromise = null;
                });
        },

        /** Gattungszeichen zu den Ziffern 5-8, sobald die Tabelle geladen ist. */
        eraCategory(wagon) {
            const code = String(wagon?.number58 ?? '').trim();
            if (!this.eraReady || !eraTypes || !/^\d{4}$/.test(code)) return '';

            return eraTypes.get(code) || '';
        },

        /**
         * Abgeleitete Wagenfelder wie in der Vorlage nachführen: Gattung aus
         * den Ziffern 5-8 und die ersten acht Stellen vom Wagen darüber,
         * sobald bei einem Wagen nur die Stellen 9-11 eingetragen sind.
         */
        syncDerivedWagonFields() {
            if (this.hydrating) return;

            for (let index = 0; index < this.visibleCount; index += 1) {
                const wagon = this.wagons[index];
                if (!wagon) continue;

                this.carryNumberPrefix(index);
                this.syncCategory(wagon);
            }
        },

        carryNumberPrefix(index) {
            const wagon = this.wagons[index];
            if (index < 1 || !wagon || !hasValue(wagon.number911)) return;
            if (hasValue(wagon.number12) || hasValue(wagon.number34) || hasValue(wagon.number58)) return;

            for (let previous = index - 1; previous >= 0; previous -= 1) {
                const source = this.wagons[previous];
                if (hasValue(source?.number12) || hasValue(source?.number34) || hasValue(source?.number58)) {
                    wagon.number12 = source.number12;
                    wagon.number34 = source.number34;
                    wagon.number58 = source.number58;

                    return;
                }
            }
        },

        syncCategory(wagon) {
            if (!this.eraReady || !wagon) return;

            const derived = this.eraCategory(wagon);
            const automatic = hasValue(wagon.categoryAuto) && wagon.category === wagon.categoryAuto;

            if (derived) {
                if (!hasValue(wagon.category) || automatic) wagon.category = derived;
            } else if (automatic) {
                wagon.category = '';
            }

            if (wagon.categoryAuto !== derived) wagon.categoryAuto = derived;
        },

        /** Eine vollständige zwölfstellige Nummer beim Einfügen auf die fünf Felder verteilen. */
        handleNumberPaste(event, wagon) {
            const parts = splitWagonNumber(event?.clipboardData?.getData?.('text'));
            if (!parts || !wagon) return;

            event.preventDefault();
            Object.assign(wagon, parts);
        },

        /** Ist ein Nummernteil voll, springt der Fokus in den nächsten. */
        advanceNumberField(event) {
            const field = event?.target;
            const limit = Number(field?.maxLength);
            if (!field || !(limit > 0)) return;
            if (event.inputType && !String(event.inputType).startsWith('insert')) return;
            if (String(field.value ?? '').length < limit || field.selectionStart !== field.value.length) return;

            const group = field.closest?.('[data-wagon-number], [data-wagon-row]');
            const parts = group ? Array.from(group.querySelectorAll('[data-wagon-number-part]')) : [];
            const next = parts[parts.indexOf(field) + 1];

            if (next) {
                next.focus();
                next.select?.();
            }
        },

        setToday() {
            this.meta.date = localToday();
        },

        setCurrentTime() {
            const now = new Date();
            this.meta.preparedTime = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
        },

        normalizeSole(wagon) {
            if (!wagon) return;

            const value = String(wagon.brakeType ?? '').trim().toUpperCase();
            wagon.brakeType = BRAKE_SOLE_TYPES.includes(value) ? value : '';
        },

        /** Wagen eine Position nach oben (-1) oder unten (+1) setzen. */
        moveWagon(index, direction) {
            const from = Number(index);
            const to = from + Number(direction);
            if (!Number.isInteger(from) || !Number.isInteger(to)) return false;
            if (from < 0 || to < 0 || from >= this.visibleCount || to >= this.visibleCount) return false;

            const moved = this.wagons[from];
            this.wagons[from] = this.wagons[to];
            this.wagons[to] = moved;

            for (const key of ['desktopWagon', 'mobileWagon', 'openWagon']) {
                if (this[key] === from) this[key] = to;
                else if (this[key] === to) this[key] = from;
            }

            return true;
        },

        /** Reihung drehen: die ausgefüllten Wagen in umgekehrter Folge. */
        reverseWagons() {
            let last = -1;
            for (let index = 0; index < this.visibleCount; index += 1) {
                if (this.isWagonFilled(this.wagons[index])) last = index;
            }
            if (last < 1) return false;

            this.wagons.slice(0, last + 1).reverse().forEach((wagon, index) => {
                this.wagons[index] = wagon;
            });

            return true;
        },

        /** Wagen aus der Liste nehmen; die folgenden rücken auf. */
        async removeWagon(index) {
            const target = Number(index);
            if (!Number.isInteger(target) || target < 0 || target >= this.visibleCount) return;

            if (this.isWagonFilled(this.wagons[target])) {
                const confirmed = await this.confirmDeletion({
                    title: config.removeWagonTitle || 'Wagen wirklich entfernen?',
                    text: config.removeWagonText || 'Der Wagen wird aus der Liste genommen, die folgenden rücken auf.',
                    confirmButtonText: config.removeWagonConfirm || 'Wagen entfernen',
                });
                if (!confirmed) return;
            }

            this.wagons.splice(target, 1);
            this.wagons.push(emptyWagon());
            this.visibleCount = Math.max(3, this.visibleCount - 1);

            for (const key of ['desktopWagon', 'mobileWagon', 'openWagon']) {
                if (this[key] > target) this[key] -= 1;
                this[key] = Math.max(0, Math.min(this.visibleCount - 1, this[key]));
            }

            this.schedulePersist();
        },

        /** Tastatur der Wagentabelle: Enter = nächstes Feld, Pfeil hoch/runter = Nachbarzeile. */
        handleSheetKeydown(event) {
            if (!event?.target?.matches?.('[data-wagon-cell]')) return;
            if (event.altKey || event.ctrlKey || event.metaKey) return;

            if (event.key === 'Enter') {
                event.preventDefault();
                this.focusNextCell(event);
            } else if (event.key === 'ArrowDown') {
                this.focusCellVertical(event, 1);
            } else if (event.key === 'ArrowUp') {
                this.focusCellVertical(event, -1);
            }
        },

        /** Pfeiltasten hoch/runter in der Tabelle: gleiche Spalte, Nachbarzeile. */
        focusCellVertical(event, direction) {
            const cell = event?.target;
            const column = cell?.dataset?.wagonColumn;
            const row = cell?.closest?.('[data-wagon-row]');
            if (!column || !row) return;

            const rows = Array.from(row.parentElement?.querySelectorAll?.('[data-wagon-row]') || []);
            const target = rows[rows.indexOf(row) + Number(direction)]?.querySelector?.(`[data-wagon-column="${column}"]`);
            if (!target) return;

            event.preventDefault();
            target.focus();
            target.select?.();
        },

        addWagon() {
            if (this.visibleCount >= MAX_WAGONS) return;
            const nextIndex = this.visibleCount;
            this.visibleCount += 1;
            this.openWagon = nextIndex;
            this.desktopWagon = nextIndex;
            this.showMobileWagon(nextIndex);

            this.$nextTick(() => this.motion()?.pop(
                this.$refs?.mobileWagonRail?.querySelector(`[data-wagon-index="${nextIndex}"]`),
            ));
        },

        /**
         * Wagen aus der Liste im Pruefschritt heraus oeffnen. Ohne diesen Weg
         * war nicht erkennbar, dass die Eintraege dort dieselben Wagen sind,
         * die vorne in den Schritten 2 bis 5 erfasst werden.
         */
        openWagonForEditing(index) {
            this.showMobileWagon(index);
            this.desktopWagon = Math.max(0, Math.min(this.visibleCount - 1, Number(index) || 0));
            this.goToMobileStep(ASSISTANT_STEP_IDS.indexOf('identity'));
        },

        addWagonAndOpen() {
            if (this.visibleCount >= MAX_WAGONS) {
                this.notify(config.wagonLimitReached || '', 'info', 2600);

                return;
            }

            this.addWagon();
            this.openWagonForEditing(this.visibleCount - 1);
        },

        showMobileWagon(index) {
            this.mobileWagon = Math.max(0, Math.min(this.visibleCount - 1, Number(index)));
            this.centerMobileRailControl('mobileWagonRail', `[data-wagon-index="${this.mobileWagon}"]`);
        },

        showDesktopWagon(index) {
            this.desktopWagon = Math.max(0, Math.min(this.visibleCount - 1, Number(index)));
        },

        previousMobileWagon() {
            this.showMobileWagon(this.mobileWagon - 1);
        },

        nextMobileWagon() {
            this.showMobileWagon(this.mobileWagon + 1);
        },

        get mobileStepCount() {
            return Math.max(1, this.mobileSteps.length);
        },

        get mobileStepProgress() {
            return ((this.mobileStep + 1) / this.mobileStepCount) * 100;
        },

        get mobileStepTitle() {
            return this.mobileSteps[this.mobileStep]?.label || '';
        },

        get isMobileWagonStep() {
            return this.mobileStep >= 1 && this.mobileStep <= 4;
        },

        mobileStepId(index = this.mobileStep) {
            const resolved = Math.max(0, Math.min(this.mobileStepCount - 1, Number(index) || 0));

            return this.mobileSteps[resolved]?.id || ASSISTANT_STEP_IDS[resolved] || 'train';
        },

        /**
         * Aktiver Abschnitt der einseitigen Mobilansicht. Die vier
         * Wagen-Schritte buendeln sich in der Abschnittsleiste zu "Wagen".
         */
        get mobileSectionId() {
            const stepId = this.mobileStepId();

            return WAGON_MODAL_STEP_IDS.includes(stepId) ? 'wagons' : stepId;
        },

        /**
         * Bewegungsmodul, falls vorhanden. Der Editor muss ohne es vollstaendig
         * bedienbar bleiben — deshalb ueberall nur optional aufrufen.
         */
        motion() {
            return typeof window !== 'undefined' ? window.RailTimeWagonMotion || null : null;
        },

        animateStep(index) {
            const stepId = this.mobileStepId(index);
            const host = WAGON_MODAL_STEP_IDS.includes(stepId)
                ? this.$refs?.wagonModalBody
                : this.$refs?.mobilePage;
            const panel = typeof host?.querySelector === 'function'
                ? host.querySelector(`[data-wagon-anchor="${stepId}"]`)
                : null;
            if (panel) this.motion()?.stepEntered(panel);

            if (stepId === 'review') {
                this.$nextTick(() => this.motion()?.listReveal(this.$refs?.reviewWagonList));
            }
        },

        prefersReducedMotion() {
            return typeof window?.matchMedia === 'function'
                && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        /**
         * Einen der acht Assistenten-Schritte ansteuern. Auf der einseitigen
         * Mobilansicht heisst das: Seiten-Anker anscrollen — oder fuer die
         * vier Wagen-Schritte das Wagen-Modal oeffnen und dort den passenden
         * Abschnitt anscrollen.
         */
        goToMobileStep(index, behavior = null) {
            const targetStep = Math.max(0, Math.min(this.mobileStepCount - 1, Number(index) || 0));
            const stepId = this.mobileStepId(targetStep);
            const resolvedBehavior = behavior || (this.prefersReducedMotion() ? 'auto' : 'smooth');

            this.commitMobileStep(targetStep);

            if (WAGON_MODAL_STEP_IDS.includes(stepId)) {
                this.wagonModalOpen = true;
                this.$nextTick(() => this.scrollMobileAnchor(this.$refs?.wagonModalBody, stepId, resolvedBehavior));
                return;
            }

            this.wagonModalOpen = false;
            this.$nextTick(() => this.scrollMobileAnchor(this.$refs?.mobilePage, stepId, resolvedBehavior));
        },

        /**
         * Abschnittsleiste der einseitigen Ansicht. "wagons" ist kein
         * Assistenten-Schritt, sondern die Wagenliste auf der Seite —
         * dorthin wird ohne Modal gescrollt.
         */
        goToMobileSection(sectionId) {
            if (sectionId === 'wagons') {
                this.wagonModalOpen = false;
                this.commitMobileStep(ASSISTANT_STEP_IDS.indexOf('identity'));
                this.$nextTick(() => this.scrollMobileAnchor(
                    this.$refs?.mobilePage,
                    'wagons',
                    this.prefersReducedMotion() ? 'auto' : 'smooth',
                ));
                return;
            }

            const target = ASSISTANT_STEP_IDS.indexOf(String(sectionId));
            this.goToMobileStep(target >= 0 ? target : 0);
        },

        scrollMobileAnchor(host, anchorId, behavior = 'smooth') {
            const anchor = typeof host?.querySelector === 'function'
                ? host.querySelector(`[data-wagon-anchor="${anchorId}"]`)
                : null;
            if (!host || !anchor || typeof host.scrollTo !== 'function') return;

            host.scrollTo({
                top: Math.max(0, (anchor.offsetTop || 0) - 10),
                behavior,
            });
        },

        openWagonModal(index = this.mobileWagon) {
            this.showMobileWagon(index);
            this.desktopWagon = this.mobileWagon;
            this.goToMobileStep(ASSISTANT_STEP_IDS.indexOf('identity'));
        },

        closeWagonModal() {
            this.wagonModalOpen = false;
        },

        commitMobileStep(index) {
            const nextStep = Math.max(0, Math.min(this.mobileStepCount - 1, Number(index) || 0));
            if (this.mobileStep === nextStep) return;

            this.mobileStep = nextStep;
            this.centerMobileRailControl('mobileStepRail', `[data-mobile-section-chip="${this.mobileSectionId}"]`);
            this.$nextTick(() => this.animateStep(nextStep));
        },

        centerMobileRailControl(refName, selector) {
            this.$nextTick(() => {
                const rail = this.$refs?.[refName];
                const control = rail?.querySelector?.(selector);
                if (!rail || !control || typeof rail.scrollTo !== 'function') return;

                const left = Math.max(0, control.offsetLeft - ((rail.clientWidth - control.offsetWidth) / 2));
                rail.scrollTo({
                    left,
                    behavior: this.prefersReducedMotion() ? 'auto' : 'smooth',
                });
            });
        },

        previousMobileStep() {
            this.goToMobileStep(this.mobileStep - 1);
        },

        nextMobileStep() {
            this.goToMobileStep(this.mobileStep + 1);
        },

        handleMobileWizardKeydown(event) {
            if (!this.editorOpen || window.innerWidth >= 1024) return;
            if (event.target?.closest?.('input, textarea, select, button, [contenteditable="true"]')) return;

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                this.previousMobileStep();
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.nextMobileStep();
            } else if (event.key === 'Home') {
                event.preventDefault();
                this.goToMobileStep(0);
            } else if (event.key === 'End') {
                event.preventDefault();
                this.goToMobileStep(this.mobileStepCount - 1);
            }
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

        async clearWagon(index) {
            const confirmed = await this.confirmDeletion({
                title: config.clearWagonTitle || 'Wagen wirklich leeren?',
                text: config.clearWagonText || 'Alle Eingaben dieses Wagens werden entfernt.',
                confirmButtonText: config.clearWagonConfirm || 'Wagen leeren',
            });
            if (!confirmed) return;

            this.wagons[index] = emptyWagon();
            this.schedulePersist();
        },

        cycleBrakeType(wagon) {
            const values = ['', ...BRAKE_SOLE_TYPES];
            wagon.brakeType = values[(values.indexOf(wagon.brakeType) + 1) % values.length];
        },

        wagonNumber(wagon) {
            return ruleWagonNumber(wagon);
        },

        wagonDigits(wagon) {
            return ruleWagonDigits(wagon);
        },

        expectedCheckDigit(wagon) {
            return ruleExpectedCheckDigit(wagon);
        },

        checkState(wagon) {
            return ruleCheckState(wagon);
        },

        isWagonFilled(wagon) {
            return ruleIsWagonFilled(wagon);
        },

        /** Ampel eines Wagens für Listen und Tabelle: empty, invalid, partial oder complete. */
        wagonStatus(wagon) {
            return ruleWagonStatus(wagon);
        },

        /** Fehlende Pflichtfelder eines Wagens als lesbarer Text. */
        missingFieldsText(wagon) {
            if (!this.isWagonFilled(wagon)) return '';

            return missingWagonFields(wagon)
                .map((field) => this.missingLabels[field] || field)
                .join(', ');
        },

        wagonRouteLabel(wagon) {
            const from = String(wagon?.shippingStation || '').trim();
            const to = String(wagon?.destinationStation || '').trim();

            if (!from && !to) return '';

            return `${from || '—'} → ${to || '—'}`;
        },

        /** Besondere Angaben: wie viele der Ja/Nein-Fragen sind beantwortet? */
        get specialAnsweredCount() {
            return SPECIAL_FIELDS.filter((field) => String(this.brakeSheet[field] ?? '') !== '').length;
        },

        get specialFieldCount() {
            return SPECIAL_FIELDS.length;
        },

        totalWeight(wagon) {
            return wagonTotalWeight(wagon);
        },

        get visibleWagons() {
            return this.wagons.slice(0, this.visibleCount);
        },

        get activeWagons() {
            return this.visibleWagons.filter((wagon) => this.isWagonFilled(wagon));
        },

        get completionCount() {
            return this.activeWagons.length;
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

        /** Summen und Bremsabzüge der Wagenliste (Regeln der v16_5). */
        get totals() {
            return computeTotals(this.visibleWagons, this.brakeSheet);
        },

        /** Bremszettel: Wagenzug, arbeitende Triebfahrzeuge und Gesamtzug. */
        get brakeTotals() {
            return computeBrakeSheet(this.totals, this.brakeSheet, trainEnds(this.visibleWagons, this.brakeSheet));
        },

        /** Prüfhinweise mit Text, wichtigste zuerst. */
        get checks() {
            return computeChecks(this.visibleWagons, this.meta, this.brakeSheet).map((check) => ({
                ...check,
                text: this.checkText(check),
            }));
        },

        get checkSummary() {
            const checks = this.checks;
            const errors = checks.filter((check) => check.tone === 'error').length;
            const warnings = checks.filter((check) => check.tone === 'warning').length;

            return {
                errors,
                warnings,
                infos: checks.length - errors - warnings,
                tone: this.completionCount === 0 ? 'empty' : (errors > 0 ? 'error' : (warnings > 0 ? 'warning' : 'ok')),
            };
        },

        /** Kurztext der Statusanzeige in der Reiterleiste. */
        get checkStatusLabel() {
            const summary = this.checkSummary;
            if (summary.tone === 'empty') return config.checksEmpty || '';
            if (summary.tone === 'ok') return config.checksOk || '';

            return [
                summary.errors > 0 ? `${summary.errors} ${config.checksErrors || ''}` : '',
                summary.warnings > 0 ? `${summary.warnings} ${config.checksWarnings || ''}` : '',
            ].filter(Boolean).join(' · ');
        },

        /** Vorhandene gegen mindestens geforderte Bremshundertstel. */
        get brakeGauge() {
            const sheet = this.brakeTotals;
            const available = sheet.availablePercentage;
            const minimum = sheet.minimumPercentage;

            if (available === null) {
                return { tone: 'neutral', ratio: 0, note: config.gaugeMissingData || '' };
            }
            if (!(minimum > 0)) {
                return { tone: 'neutral', ratio: Math.min(1, available / 100), note: config.gaugeNoMinimum || '' };
            }

            const satisfied = available >= minimum || sheet.ninetySatisfied;
            const note = String((satisfied ? config.gaugeOk : config.gaugeMissing) || '')
                .split(':minimum').join(String(minimum))
                .split(':missing').join(String(Math.max(0, minimum - available)));

            return { tone: satisfied ? 'ok' : 'error', ratio: Math.min(1, available / minimum), note };
        },

        /** Wagen der Zugzeile über der Arbeitsfläche: Position, Form, Zustand und Breitenanteil. */
        get trainCars() {
            return trainStrip(this.visibleWagons).map((car) => ({
                ...car,
                grow: car.length > 0 ? car.length : 15,
            }));
        },

        /** Wagen in der Zugzeile anklicken: zur Wagenliste wechseln und die Zeile aufsuchen. */
        selectTrainCar(index) {
            this.desktopSection = 'wagons';
            this.showDesktopWagon(index);

            if (typeof this.$nextTick !== 'function') return;
            this.$nextTick(() => {
                const rows = this.$refs?.editorDialog?.querySelectorAll?.('[data-wagon-row]');
                rows?.[this.desktopWagon]?.querySelector?.('[data-wagon-cell]')?.focus?.();
            });
        },

        /** Erledigte Aufträge des Meldezettels. */
        get reportProgress() {
            const tasks = Object.values(this.report?.tasks || {});
            const done = tasks.filter(Boolean).length;

            return { done, total: tasks.length, ratio: tasks.length ? done / tasks.length : 0 };
        },

        get reportProgressLabel() {
            const { done, total } = this.reportProgress;
            const template = String(config.tasksProgress || '');

            return template
                ? template.split(':done').join(String(done)).split(':total').join(String(total))
                : `${done} / ${total}`;
        },

        /** Kurzstand je Blatt für die Reiter und die Ausgabe: Ton (empty, ok, warning, error) und Text. */
        get sheetStatus() {
            const summary = this.checkSummary;
            const available = this.brakeTotals.availablePercentage;
            const gauge = this.brakeGauge;
            const progress = this.reportProgress;
            const wagonsText = summary.tone === 'error'
                ? `${summary.errors} ${config.checksErrors || ''}`
                : (summary.tone === 'warning'
                    ? `${summary.warnings} ${config.checksWarnings || ''}`
                    : (summary.tone === 'ok' ? `${this.completionCount} ${config.wagonsLabel || ''}` : ''));

            return {
                wagons: { tone: summary.tone, text: wagonsText.trim() },
                brakeSheet: {
                    tone: available === null || gauge.tone === 'neutral' ? 'empty' : gauge.tone,
                    text: available === null ? '' : `${available} %`,
                },
                report: {
                    tone: progress.done === 0 ? 'empty' : (progress.done >= progress.total ? 'ok' : 'warning'),
                    text: progress.done === 0 ? '' : `${progress.done} / ${progress.total}`,
                },
            };
        },

        /**
         * Balken der Bremshundertstel in Prozent der Skala: Anteil bis zum
         * Mindestwert, Überschuss darüber und Fehlbetrag darunter.
         */
        get brakeBar() {
            const available = this.brakeTotals.availablePercentage;
            const minimum = this.brakeTotals.minimumPercentage;
            if (available === null) return { base: 0, surplus: 0, deficit: 0, marker: null };

            const scale = Math.max(100, available, minimum > 0 ? minimum * 1.2 : 0);
            const share = (value) => Math.round((Math.max(0, value) / scale) * 1000) / 10;
            if (!(minimum > 0)) return { base: share(available), surplus: 0, deficit: 0, marker: null };

            return {
                base: share(Math.min(available, minimum)),
                surplus: share(available - minimum),
                deficit: share(minimum - available),
                marker: share(minimum),
            };
        },

        /** Zahl für Ergebnisfelder; leer, null und (auf Wunsch) 0 werden zum Gedankenstrich. */
        displayNumber(value, maximumDigits = 0, hideZero = false) {
            if (value === null || value === undefined || String(value).trim() === '') return '—';
            if (hideZero && numeric(value) === 0) return '—';

            return this.formatPlain(value, maximumDigits);
        },

        checkText(check) {
            let text = String(this.checkLabels[check.id] || check.id);

            for (const [key, value] of Object.entries(check.values || {})) {
                text = text.split(`:${key}`).join(String(value));
            }

            return text;
        },

        /** Zahl ohne erzwungene Nachkommastellen, z. B. 138 oder 43,6. */
        formatPlain(value, maximumDigits = 1) {
            return new Intl.NumberFormat(config.locale || 'de-DE', {
                minimumFractionDigits: 0,
                maximumFractionDigits: maximumDigits,
            }).format(numeric(value));
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
