const STORAGE_VERSION = 2;
const MAX_WAGONS = 40;
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
];
const ASSISTANT_WAGON_FIELDS = [
    'wagon.number',
    'wagon.category',
    'wagon.axlesEmpty',
    'wagon.axlesLoaded',
    'wagon.length',
    'wagon.wagonWeight',
    'wagon.loadWeight',
    'wagon.brakeG',
    'wagon.brakeP',
    'wagon.shippingStation',
    'wagon.destinationStation',
    'wagon.brakeType',
    'wagon.discBrake',
    'wagon.parkingBrake',
    'wagon.maxSpeed',
    'wagon.remark',
];
const ASSISTANT_BRAKE_SHEET_FIELDS = [
    'brakeSheet.tractionWeight',
    'brakeSheet.tractionBrakeWeight',
    'brakeSheet.tractionAxles',
    'brakeSheet.minimumBrakePercentage',
    'brakeSheet.brakedAxles',
    'brakeSheet.lowerVehicleSpeed',
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

// Die Ja/Nein-Fragen der besonderen Angaben — Reihenfolge wie im Bremszettel.
// Dient dem Fortschritt ("x von 9 beantwortet"); die Anzeige gruppiert sie in
// resources/views/.../partials/wagon-special-information.blade.php.
const SPECIAL_FIELDS = [
    'nbuepBrake',
    'emergencyBrakeBridge',
    'epBrake',
    'dangerousGoods',
    'passengerFeatureHzee',
    'passengerFeatureNOe',
    'passengerFeatureTb0',
    'passengerFeatureOZub',
    'passengerFeatureOther',
];

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
    discBrake: null,
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
    const hasAssistantFieldPresence = Number(draft.assistantFieldPresenceVersion || 0)
        >= ASSISTANT_FIELD_PRESENCE_VERSION;

    return {
        id: String(draft.id || fallbackId || createDraftId()),
        createdAt: draft.createdAt || draft.persistedAt || now,
        persistedAt: draft.persistedAt || draft.createdAt || now,
        assistantFieldPresenceVersion: ASSISTANT_FIELD_PRESENCE_VERSION,
        meta: { ...emptyMeta(), ...(draft.meta || {}) },
        wagons: Array.from({ length: MAX_WAGONS }, (_, index) => {
            const wagon = draft.wagons?.[index] || {};
            const hasExplicitDiscBrakeAnswer = wagon.discBrake === true
                || (hasAssistantFieldPresence && wagon.discBrake === false);

            return {
                ...emptyWagon(),
                ...wagon,
                discBrake: hasExplicitDiscBrakeAnswer ? wagon.discBrake : null,
            };
        }),
        brakeSheet: { ...emptyBrakeSheet(), ...(draft.brakeSheet || {}) },
        visibleCount,
    };
};

export function wagonListPrototype(config = {}) {
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
        desktopTableMode: 'overview',
        desktopWagon: 0,
        mobileWagon: 0,
        mobileStep: 0,
        mobileSteps: Array.isArray(config.mobileSteps) ? config.mobileSteps : [],
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
            this.$watch('wagons', persistWhenEditing);
            this.$watch('brakeSheet', persistWhenEditing);
            this.$watch('visibleCount', persistWhenEditing);
            this.$watch('mobileStep', () => this.scheduleAssistantContext('step-updated'));
            this.$watch('mobileWagon', () => this.scheduleAssistantContext('wagon-updated'));
            this.$watch('desktopSection', () => this.scheduleAssistantContext('step-updated'));
            this.$watch('desktopWagon', () => this.scheduleAssistantContext('wagon-updated'));

            this.$nextTick(() => this.dispatchAssistantContext('overview-ready', false));
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
                assistantFieldPresenceVersion: ASSISTANT_FIELD_PRESENCE_VERSION,
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
            this.assistantContextNonce = this.createAssistantContextNonce();
            this.assistantHandledActionTokens = [];
            this.activeDraftId = draft.id;
            this.meta = { ...emptyMeta(), ...draft.meta };
            this.wagons = Array.from({ length: MAX_WAGONS }, (_, index) => ({
                ...emptyWagon(),
                ...(draft.wagons[index] || {}),
            }));
            this.brakeSheet = { ...emptyBrakeSheet(), ...draft.brakeSheet };
            this.visibleCount = Math.max(3, Math.min(MAX_WAGONS, Number(draft.visibleCount || 3)));
            this.openWagon = 0;
            this.desktopSection = 'wagons';
            this.desktopTableMode = 'overview';
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

            this.$nextTick(() => {
                this.hydrating = false;
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

            return mobileStep < brakeSheetStep ? mobileStep : 0;
        },

        assistantHasValue(value) {
            if (typeof value === 'boolean') return value;

            return String(value ?? '').trim() !== '';
        },

        assistantMetaPresence() {
            return {
                'meta.trainNumber': this.assistantHasValue(this.meta.trainNumber),
                'meta.date': this.assistantHasValue(this.meta.date),
                'meta.origin': this.assistantHasValue(this.meta.origin),
                'meta.destination': this.assistantHasValue(this.meta.destination),
                'meta.reference': this.assistantHasValue(this.meta.reference),
            };
        },

        assistantCurrentWagonPresence() {
            const wagon = this.wagons[this.assistantCurrentWagonIndex()] || emptyWagon();
            const fullNumber = `${this.wagonDigits(wagon)}${String(wagon.checkDigit ?? '').replace(/\D/g, '')}`;

            return {
                'wagon.number': fullNumber.length === 12,
                'wagon.category': this.assistantHasValue(wagon.category),
                'wagon.axlesEmpty': this.assistantHasValue(wagon.axlesEmpty),
                'wagon.axlesLoaded': this.assistantHasValue(wagon.axlesLoaded),
                'wagon.length': this.assistantHasValue(wagon.length),
                'wagon.wagonWeight': this.assistantHasValue(wagon.wagonWeight),
                'wagon.loadWeight': this.assistantHasValue(wagon.loadWeight),
                'wagon.brakeG': this.assistantHasValue(wagon.brakeG),
                'wagon.brakeP': this.assistantHasValue(wagon.brakeP),
                'wagon.shippingStation': this.assistantHasValue(wagon.shippingStation),
                'wagon.destinationStation': this.assistantHasValue(wagon.destinationStation),
                'wagon.brakeType': this.assistantHasValue(wagon.brakeType),
                'wagon.discBrake': typeof wagon.discBrake === 'boolean',
                'wagon.parkingBrake': this.assistantHasValue(wagon.parkingBrake),
                'wagon.maxSpeed': this.assistantHasValue(wagon.maxSpeed),
                'wagon.remark': this.assistantHasValue(wagon.remark),
            };
        },

        assistantBrakeSheetPresence() {
            return {
                'brakeSheet.tractionWeight': this.assistantHasValue(this.brakeSheet.tractionWeight),
                'brakeSheet.tractionBrakeWeight': this.assistantHasValue(this.brakeSheet.tractionBrakeWeight),
                'brakeSheet.tractionAxles': this.assistantHasValue(this.brakeSheet.tractionAxles),
                'brakeSheet.minimumBrakePercentage': this.assistantHasValue(this.brakeSheet.minimumBrakePercentage),
                'brakeSheet.brakedAxles': this.assistantHasValue(this.brakeSheet.brakedAxles),
                'brakeSheet.lowerVehicleSpeed': this.assistantHasValue(this.brakeSheet.lowerVehicleSpeed),
                'brakeSheet.nbuepBrake': this.assistantHasValue(this.brakeSheet.nbuepBrake),
                'brakeSheet.emergencyBrakeBridge': this.assistantHasValue(this.brakeSheet.emergencyBrakeBridge),
                'brakeSheet.passengerFeatureHzee': this.assistantHasValue(this.brakeSheet.passengerFeatureHzee),
                'brakeSheet.passengerFeatureNOe': this.assistantHasValue(this.brakeSheet.passengerFeatureNOe),
                'brakeSheet.passengerFeatureTb0': this.assistantHasValue(this.brakeSheet.passengerFeatureTb0),
                'brakeSheet.passengerFeatureOZub': this.assistantHasValue(this.brakeSheet.passengerFeatureOZub),
                'brakeSheet.passengerFeatureOther': this.assistantHasValue(this.brakeSheet.passengerFeatureOther),
                'brakeSheet.dangerousGoods': this.assistantHasValue(this.brakeSheet.dangerousGoods),
                'brakeSheet.epBrake': this.assistantHasValue(this.brakeSheet.epBrake),
                'brakeSheet.issuerName': this.assistantHasValue(this.brakeSheet.issuerName),
            };
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

            let normalized;

            switch (field) {
                case 'meta.trainNumber':
                    normalized = this.normalizeAssistantText(rawValue, 80);
                    if (!normalized.ok) return false;
                    this.meta.trainNumber = normalized.value;
                    return true;
                case 'meta.date':
                    normalized = this.normalizeAssistantDate(rawValue);
                    if (!normalized.ok) return false;
                    this.meta.date = normalized.value;
                    return true;
                case 'meta.origin':
                    normalized = this.normalizeAssistantText(rawValue, 120);
                    if (!normalized.ok) return false;
                    this.meta.origin = normalized.value;
                    return true;
                case 'meta.destination':
                    normalized = this.normalizeAssistantText(rawValue, 120);
                    if (!normalized.ok) return false;
                    this.meta.destination = normalized.value;
                    return true;
                case 'meta.reference':
                    normalized = this.normalizeAssistantText(rawValue, 160);
                    if (!normalized.ok) return false;
                    this.meta.reference = normalized.value;
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
                    if (!/^[0-9\s-]+$/.test(source)) return false;
                    const digits = source.replace(/\D/g, '');
                    if (digits.length !== 12) return false;
                    wagon.number12 = digits.slice(0, 2);
                    wagon.number34 = digits.slice(2, 4);
                    wagon.number58 = digits.slice(4, 8);
                    wagon.number911 = digits.slice(8, 11);
                    wagon.checkDigit = digits.slice(11, 12);
                    return true;
                }
                case 'wagon.category':
                    normalized = this.normalizeAssistantText(rawValue, 80);
                    if (!normalized.ok) return false;
                    wagon.category = normalized.value;
                    return true;
                case 'wagon.axlesEmpty':
                case 'wagon.axlesLoaded':
                case 'wagon.length':
                case 'wagon.wagonWeight':
                case 'wagon.loadWeight':
                case 'wagon.brakeG':
                case 'wagon.brakeP':
                case 'wagon.parkingBrake':
                case 'wagon.maxSpeed':
                    normalized = this.normalizeAssistantNumber(rawValue);
                    if (!normalized.ok) return false;
                    if (field === 'wagon.axlesEmpty') wagon.axlesEmpty = normalized.value;
                    else if (field === 'wagon.axlesLoaded') wagon.axlesLoaded = normalized.value;
                    else if (field === 'wagon.length') wagon.length = normalized.value;
                    else if (field === 'wagon.wagonWeight') wagon.wagonWeight = normalized.value;
                    else if (field === 'wagon.loadWeight') wagon.loadWeight = normalized.value;
                    else if (field === 'wagon.brakeG') wagon.brakeG = normalized.value;
                    else if (field === 'wagon.brakeP') wagon.brakeP = normalized.value;
                    else if (field === 'wagon.parkingBrake') wagon.parkingBrake = normalized.value;
                    else wagon.maxSpeed = normalized.value;
                    return true;
                case 'wagon.shippingStation':
                    normalized = this.normalizeAssistantText(rawValue, 120);
                    if (!normalized.ok) return false;
                    wagon.shippingStation = normalized.value;
                    return true;
                case 'wagon.destinationStation':
                    normalized = this.normalizeAssistantText(rawValue, 120);
                    if (!normalized.ok) return false;
                    wagon.destinationStation = normalized.value;
                    return true;
                case 'wagon.brakeType': {
                    const brakeType = typeof rawValue === 'string' ? rawValue.trim().toUpperCase() : '';
                    if (!['', 'K', 'L', 'LL'].includes(brakeType)) return false;
                    wagon.brakeType = brakeType;
                    return true;
                }
                case 'wagon.discBrake':
                    normalized = this.normalizeAssistantBoolean(rawValue);
                    if (!normalized.ok) return false;
                    wagon.discBrake = normalized.value;
                    return true;
                case 'wagon.remark':
                    normalized = this.normalizeAssistantText(rawValue, 255);
                    if (!normalized.ok) return false;
                    wagon.remark = normalized.value;
                    return true;
                case 'brakeSheet.tractionWeight':
                case 'brakeSheet.tractionBrakeWeight':
                case 'brakeSheet.tractionAxles':
                case 'brakeSheet.minimumBrakePercentage':
                case 'brakeSheet.brakedAxles':
                case 'brakeSheet.lowerVehicleSpeed':
                    normalized = this.normalizeAssistantNumber(rawValue);
                    if (!normalized.ok) return false;
                    if (field === 'brakeSheet.tractionWeight') this.brakeSheet.tractionWeight = normalized.value;
                    else if (field === 'brakeSheet.tractionBrakeWeight') this.brakeSheet.tractionBrakeWeight = normalized.value;
                    else if (field === 'brakeSheet.tractionAxles') this.brakeSheet.tractionAxles = normalized.value;
                    else if (field === 'brakeSheet.minimumBrakePercentage') this.brakeSheet.minimumBrakePercentage = normalized.value;
                    else if (field === 'brakeSheet.brakedAxles') this.brakeSheet.brakedAxles = normalized.value;
                    else this.brakeSheet.lowerVehicleSpeed = normalized.value;
                    return true;
                case 'brakeSheet.nbuepBrake':
                case 'brakeSheet.emergencyBrakeBridge':
                case 'brakeSheet.passengerFeatureHzee':
                case 'brakeSheet.passengerFeatureNOe':
                case 'brakeSheet.passengerFeatureTb0':
                case 'brakeSheet.passengerFeatureOZub':
                case 'brakeSheet.passengerFeatureOther':
                case 'brakeSheet.dangerousGoods':
                case 'brakeSheet.epBrake':
                    normalized = this.normalizeAssistantYesNo(rawValue);
                    if (!normalized.ok) return false;
                    if (field === 'brakeSheet.nbuepBrake') this.brakeSheet.nbuepBrake = normalized.value;
                    else if (field === 'brakeSheet.emergencyBrakeBridge') this.brakeSheet.emergencyBrakeBridge = normalized.value;
                    else if (field === 'brakeSheet.passengerFeatureHzee') this.brakeSheet.passengerFeatureHzee = normalized.value;
                    else if (field === 'brakeSheet.passengerFeatureNOe') this.brakeSheet.passengerFeatureNOe = normalized.value;
                    else if (field === 'brakeSheet.passengerFeatureTb0') this.brakeSheet.passengerFeatureTb0 = normalized.value;
                    else if (field === 'brakeSheet.passengerFeatureOZub') this.brakeSheet.passengerFeatureOZub = normalized.value;
                    else if (field === 'brakeSheet.passengerFeatureOther') this.brakeSheet.passengerFeatureOther = normalized.value;
                    else if (field === 'brakeSheet.dangerousGoods') this.brakeSheet.dangerousGoods = normalized.value;
                    else this.brakeSheet.epBrake = normalized.value;
                    return true;
                case 'brakeSheet.issuerName':
                    normalized = this.normalizeAssistantText(rawValue, 160);
                    if (!normalized.ok) return false;
                    this.brakeSheet.issuerName = normalized.value;
                    return true;
                default:
                    return false;
            }
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
            return Boolean(
                this.wagonDigits(wagon)
                || wagon.checkDigit
                || wagon.category
                || wagon.axlesEmpty
                || wagon.axlesLoaded
                || wagon.length
                || wagon.wagonWeight
                || wagon.loadWeight
                || wagon.brakeG
                || wagon.brakeP
                || wagon.shippingStation
                || wagon.destinationStation
                || wagon.brakeType
                || wagon.discBrake
                || wagon.parkingBrake
                || wagon.maxSpeed
                || wagon.remark,
            );
        },

        /**
         * Ampel eines Wagens fuer Listen und Kacheln. Die Bedingung stand
         * bisher dreimal wortgleich im Blade — jede Ansicht bewertete den
         * gleichen Wagen also aus einer eigenen Kopie derselben Regel.
         */
        wagonStatus(wagon) {
            if (!this.isWagonFilled(wagon)) return 'empty';

            const complete = this.checkState(wagon) === 'valid'
                && Boolean(wagon.category)
                && Boolean(wagon.length)
                && Boolean(wagon.wagonWeight || wagon.loadWeight);

            return complete ? 'complete' : 'partial';
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
            if (!this.isWagonFilled(wagon)) return 0;
            return numeric(wagon.wagonWeight) + numeric(wagon.loadWeight);
        },

        get activeWagons() {
            return this.wagons.slice(0, this.visibleCount).filter((wagon) => this.isWagonFilled(wagon));
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
