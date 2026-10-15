/*
 * RailTime Videoanrufe: Alpine-Komponente um livekit-client.
 *
 * Eigener Vite-Einstieg, wird ausschliesslich im Anruf-Fenster geladen
 * (livekit-client bleibt aus dem globalen app.js-Bundle heraus).
 *
 * Zustaendigkeiten: Verbindung, Track-Rendering, Mikro/Kamera/Screenshare,
 * Reconnect. Klingeln/Einladungen laufen ueber Reverb (app.js), die
 * DB-Wahrheit ueber LiveKit-Webhooks – siehe
 * .lmzdev/media-server-livekit-integration/UMSETZUNGSPLAN.md.
 */
import {
    Room,
    RoomEvent,
    Track,
    ConnectionState,
    DisconnectReason,
} from 'livekit-client';
import '../css/call-window.css';
import {
    clampSelfPreviewPosition,
    isSelfPreviewCenterOutside,
    nearestSelfPreviewEdge,
    selfPreviewDockPosition,
} from './call-self-preview.js';

// Alpine proxies component state, including SDK class instances. Native room
// ownership and SDK calls must use the underlying instance on both sides.
function rawCallValue(value) {
    return typeof window.Alpine?.raw === 'function' ? window.Alpine.raw(value) : value;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('callRoom', (config) => ({
        room: null,
        connected: false,
        connectionBusy: false,
        leaving: false,
        terminal: false,
        disposed: false,
        disconnectPromise: null,
        micBusy: false,
        cameraBusy: false,
        screenShareBusy: false,
        statusLabel: config.labels.connecting,
        participantCount: 1,
        panelOpen: !config.embedded && window.innerWidth >= 1024,
        panelTab: 'participants',
        panelTrigger: null,
        participantMayPublish: Boolean(config.canPublish),
        canPublish: Boolean(config.canPublish),
        sfuCanPublish: null,
        recordingCanPublish: false,
        permissionAbortController: null,
        recordingEnabled: false,
        recordingStatus: 'disabled',
        recordingPolicyVersion: null,
        recordingStartDeadlineAt: null,
        recordingRequiresAcknowledgement: false,
        recordingAcknowledgementSubmitting: false,
        recordingAcknowledgementError: '',
        recordingPollTimer: null,
        recordingPollInFlight: false,
        recordingUnlockAttempts: 0,
        sessionAbortController: null,
        connectionGeneration: 0,
        startWithVideo: config.startWithVideo !== false,
        micOn: false,
        cameraOn: false,
        screenSharing: false,
        audioBlocked: false,
        selfVideoVisible: false,
        selfPreviewX: 0,
        selfPreviewY: 0,
        selfPreviewLastSafeX: 0,
        selfPreviewLastSafeY: 0,
        selfPreviewWidth: 176,
        selfPreviewHeight: 99,
        selfPreviewInitialized: false,
        selfPreviewMinimized: false,
        selfPreviewDockEdge: 'right',
        selfPreviewDockX: 8,
        selfPreviewDockY: 8,
        selfPreviewDragging: false,
        selfPreviewDragCleanup: null,
        selfPreviewResizeCleanup: null,
        selfPreviewGeometryFrame: null,
        callViewportHeight: null,
        callViewportWidth: null,
        callViewportTop: 0,
        callViewportFrame: null,
        // Hochkant nur dort, wo die Kamera wirklich hochkant aufnimmt: Handy
        // aufrecht in der Hand. Am Rechner (und am quer gehaltenen Handy)
        // liefert die Kamera Querformat — ein 9:16-Rahmen wuerde das Bild dann
        // links und rechts abschneiden.
        selfPreviewPortrait: false,
        selfPreviewShapeCleanup: null,
        // Wer eingeladen ist, aber weder angenommen noch abgelehnt hat.
        // Serverwahrheit: kommt beim Aufbau aus der Konfiguration und danach
        // ueber das Livewire-Ereignis 'rt-call-waiting'.
        waiting: Array.isArray(config.waiting) ? config.waiting : [],
        remoteCount: 0,
        ringbackActive: false,
        // Automatisches Einschalten der Geraete beim Beitritt gescheitert:
        // Dann zeigt die Buehne einen Aktivieren-Knopf. Der Klick ist eine
        // echte Nutzergeste — iOS Safari verweigert getUserMedia GRUNDSAETZLICH
        // ausserhalb einer Geste, und genau ausserhalb lief der Auto-Enable.
        deviceSetupNeeded: false,
        // Verbindungsfehler und regulaeres Ende sind ZWEI Zustaende: ein
        // fehlgeschlagener Aufbau zeigte frueher dieselbe Meldung wie ein
        // beendeter Anruf — die Fehlersuche lief dadurch in die Irre.
        failed: false,
        everConnected: false,
        tiles: new Map(),

        get canControlMedia() {
            return Boolean(this.room && this.connected && this.canPublish
                && !this.connectionBusy && !this.leaving && !this.terminal
                && !this.failed && !this.recordingRequiresAcknowledgement);
        },

        get screenShareSupported() {
            return typeof navigator.mediaDevices?.getDisplayMedia === 'function';
        },

        isParticipantConnected(identity) {
            // participantCount makes transport roster changes reactive even
            // though LiveKit owns the participant Map outside Alpine.
            const hasParticipants = this.participantCount > 0;
            const activeRoom = rawCallValue(this.room);
            if (!hasParticipants || !this.connected || !activeRoom
                || typeof identity !== 'string' || !identity.trim() || identity !== identity.trim()) return false;

            return activeRoom.localParticipant?.identity === identity
                || activeRoom.remoteParticipants?.has(identity) === true;
        },

        togglePanel(tab, trigger = null) {
            if (this.panelOpen && this.panelTab === tab) {
                this.closePanel();
                return;
            }

            this.panelTab = tab;
            this.panelTrigger = trigger;
            this.panelOpen = true;

            if (window.innerWidth < 1024) {
                this.$nextTick(() => this.$refs.panelClose?.focus());
            }
        },

        closePanel() {
            if (!this.panelOpen) return;

            this.panelOpen = false;
            const trigger = this.panelTrigger;
            this.panelTrigger = null;
            this.$nextTick(() => {
                if (trigger?.isConnected !== false) trigger?.focus();
            });
        },

        isActiveRoom(room, generation = this.connectionGeneration) {
            const activeRoom = rawCallValue(this.room);
            return Boolean(activeRoom && !this.disposed && activeRoom === rawCallValue(room)
                && generation === this.connectionGeneration && !this.terminal);
        },

        resetMediaState() {
            this.micOn = false;
            this.cameraOn = false;
            this.screenSharing = false;
            this.selfVideoVisible = false;
            this.audioBlocked = false;
            this.deviceSetupNeeded = false;
            this.sfuCanPublish = null;
            this.recordingCanPublish = false;
            this.micBusy = false;
            this.cameraBusy = false;
            this.screenShareBusy = false;
            this.remoteCount = 0;
            this.participantCount = 1;
        },

        syncLocalMediaState(activeRoom = this.room) {
            activeRoom = rawCallValue(activeRoom);
            const participant = activeRoom?.localParticipant;
            if (!participant || activeRoom !== rawCallValue(this.room)) return;

            const enabled = (source, property) => {
                if (typeof participant[property] === 'boolean') return participant[property];
                const publications = participant.trackPublications;
                if (!publications) return null;
                return [...publications.values()].some((publication) =>
                    publication.source === source && publication.track && !publication.isMuted);
            };

            const microphone = enabled(Track.Source.Microphone, 'isMicrophoneEnabled');
            const camera = enabled(Track.Source.Camera, 'isCameraEnabled');
            const screen = enabled(Track.Source.ScreenShare, 'isScreenShareEnabled');
            if (microphone !== null) this.micOn = microphone;
            if (camera !== null) this.cameraOn = camera;
            if (screen !== null) this.screenSharing = screen;
        },

        clearTiles(activeRoom = null) {
            activeRoom = rawCallValue(activeRoom);
            const participants = activeRoom
                ? [activeRoom.localParticipant, ...activeRoom.remoteParticipants?.values() || []]
                : [];
            participants.forEach((participant) => participant?.trackPublications?.forEach((publication) => {
                publication.track?.detach();
            }));
            this.tiles.forEach((tile) => tile.root.remove());
            this.tiles.clear();
        },

        async init() {
            this.watchViewportShape();
            this.$nextTick(() => {
                this.observeSelfPreviewStage();
                this.queueSelfPreviewGeometry();
            });
            await this.connect();
        },

        // --- Klingelzustand beim ANRUFER ----------------------------------

        /**
         * Es wird noch auf jemanden gewartet.
         *
         * remoteCount ist dabei die harte Grenze: sobald irgendwer wirklich im
         * Raum ist, laeuft der Anruf – auch wenn weitere Einladungen noch
         * offen sind. Sonst liefe das Freizeichen ueber ein bereits laufendes
         * Gespraech.
         */
        get outgoingRinging() {
            return ! this.failed && this.remoteCount === 0 && this.waiting.length > 0;
        },

        /** Mindestens ein Geraet der Gegenseite hat das Laeuten bestaetigt. */
        get ringsThere() {
            return this.waiting.some((entry) => entry && entry.ringing);
        },

        get ringStatusLabel() {
            return this.ringsThere ? config.labels.ringsThere : config.labels.beingCalled;
        },

        get waitingNames() {
            return this.waiting
                .map((entry) => (entry && entry.name) || '')
                .filter(Boolean)
                .join(', ');
        },

        /** Nur beim Einzelanruf zeigt das Signal ein Gesicht statt eines Symbols. */
        get waitingAvatar() {
            return this.waiting.length === 1 ? (this.waiting[0].avatar || '') : '';
        },

        setWaiting(list) {
            this.waiting = Array.isArray(list) ? list : [];
            this.syncRingback();
        },

        /**
         * Freizeichen an-/abschalten.
         *
         * Bewusst zustandsgesteuert und idempotent: dieselbe Methode laeuft
         * nach jeder Teilnehmeraenderung, jeder Warteliste-Aktualisierung und
         * beim Verbindungsende. Ein Vergleich mit ringbackActive verhindert,
         * dass der Ton bei jedem Aufruf neu anfaengt und dadurch stottert.
         */
        syncRingback() {
            const shouldRing = this.connected && this.outgoingRinging;

            if (shouldRing === this.ringbackActive) {
                return;
            }

            this.ringbackActive = shouldRing;

            if (shouldRing) {
                window.RTSound?.startRingback();
            } else {
                window.RTSound?.stopRingback();
            }
        },

        /**
         * Geraetefehler ehrlich benennen statt pauschal "nicht freigegeben".
         *
         * Der Einrichtungsdialog liest den Berechtigungs-STATUS des Browsers;
         * hier wird das Geraet tatsaechlich GEOEFFNET – und das scheitert auch
         * bei erteilter Freigabe: Geraet von anderer Anwendung belegt oder vom
         * Betriebssystem blockiert (NotReadableError), gar kein Geraet
         * vorhanden (NotFoundError). Die alte Pauschalmeldung "nicht
         * freigegeben" widersprach dann sichtbar dem Dialog ("Erteilt") und
         * schickte den Nutzer in die falsche Richtung.
         */
        deviceErrorLabel(error, kind) {
            const name = error?.name || '';

            if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                return kind === 'camera' ? config.labels.cameraMissing : config.labels.microphoneMissing;
            }

            if (name === 'NotReadableError' || name === 'AbortError') {
                return kind === 'camera' ? config.labels.cameraBusy : config.labels.microphoneBusy;
            }

            const label = kind === 'camera' ? config.labels.cameraBlocked : config.labels.microphoneBlocked;

            // Unbekannte Fehlerarten mit technischem Namen ausweisen: Nur so
            // laesst sich aus einer Nutzermeldung die Ursache eingrenzen.
            return name && name !== 'NotAllowedError' && name !== 'SecurityError'
                ? `${label} (${name})`
                : label;
        },

        /** In den Fehlerzustand wechseln und den Grund fuer Diagnose loggen. */
        fail(label, error) {
            this.stopRecordingPolling();
            this.connected = false;
            this.failed = true;
            this.terminal = false;
            this.statusLabel = label || config.labels.connectionFailed;
            // Ein Freizeichen ueber einer Fehlermeldung waere schlicht gelogen.
            this.syncRingback();

            if (error) {
                console.error('[calls] Verbindung fehlgeschlagen:', error);
            }
        },

        async finishTerminalSession(label = config.labels.ended) {
            await this.disconnect();
            if (this.disposed) return;
            this.failed = false;
            this.terminal = true;
            this.recordingRequiresAcknowledgement = false;
            this.statusLabel = label;
        },

        /** Nach einem Fehlschlag neu verbinden (Button im Fehler-Panel). */
        async retry() {
            if (this.disposed || this.connectionBusy || this.leaving) return;

            await this.disconnect();
            if (this.disposed) return;
            this.failed = false;
            this.terminal = false;
            this.statusLabel = config.labels.connecting;
            await this.connect();
        },

        get gridStyle() {
            const count = Math.max(1, this.tiles.size - (this.tiles.has(config.selfIdentity) ? 1 : 0));
            const columns = count <= 1 ? 1 : count <= 4 ? 2 : count <= 9 ? 3 : 4;

            return `grid-template-columns: repeat(${columns}, minmax(0, 1fr));`;
        },

        /**
         * Groesse und Seitenverhaeltnis kommen aus dem Stil, nicht aus
         * Klassen: das vorgebaute Tailwind-Stylesheet setzt Breiten mit
         * !important, gegen das eine Utility-Klasse nicht ankaeme. Beide
         * Formate haben ungefaehr dieselbe Flaeche, damit der Wechsel beim
         * Drehen des Geraets nicht springt.
         */
        get selfPreviewStyle() {
            const width = this.selfPreviewPortrait ? 104 : 176;
            const ratio = this.selfPreviewPortrait ? '9 / 16' : '16 / 9';

            return `transform: translate3d(${this.selfPreviewX}px, ${this.selfPreviewY}px, 0);`
                + ` width: ${width}px; max-width: 42vw; aspect-ratio: ${ratio};`;
        },

        get selfPreviewRestoreStyle() {
            return `transform: translate3d(${this.selfPreviewDockX}px, ${this.selfPreviewDockY}px, 0);`;
        },

        get callViewportStyle() {
            const height = Number.isFinite(this.callViewportHeight) && this.callViewportHeight > 0
                ? `${Math.round(this.callViewportHeight)}px` : '100dvh';
            const top = Number.isFinite(this.callViewportTop) ? Math.max(0, Math.round(this.callViewportTop)) : 0;
            return `--rt-call-visual-height: ${height}; --rt-call-visual-top: ${top}px;`;
        },

        /**
         * Format der Eigenvorschau an die Geraeteform binden.
         *
         * 'pointer: coarse' grenzt Touch-Geraete ab; erst zusammen mit der
         * Hochformat-Ausrichtung ergibt das "Handy aufrecht". Ein Rechner mit
         * hochkantem Monitor bleibt damit im Querformat, ein quer gehaltenes
         * Handy wechselt korrekt mit.
         */
        watchViewportShape() {
            this.selfPreviewShapeCleanup?.();
            const viewport = window.visualViewport;
            const measure = () => {
                const visualHeight = Number(viewport?.height);
                const layoutHeight = Number(window.innerHeight);
                const visualWidth = Number(viewport?.width);
                const layoutWidth = Number(window.innerWidth);
                this.callViewportWidth = Number.isFinite(visualWidth) && visualWidth > 0
                    ? visualWidth : (Number.isFinite(layoutWidth) && layoutWidth > 0 ? layoutWidth : null);
                this.callViewportHeight = Number.isFinite(visualHeight) && visualHeight > 0
                    ? visualHeight : (Number.isFinite(layoutHeight) && layoutHeight > 0 ? layoutHeight : null);
                const top = Number(viewport?.offsetTop);
                this.callViewportTop = Number.isFinite(top) ? Math.max(0, top) : 0;
            };
            const refresh = () => {
                if (this.disposed || this.callViewportFrame !== null) return;
                this.callViewportFrame = requestAnimationFrame(() => {
                    this.callViewportFrame = null;
                    if (this.disposed) return;
                    measure();
                    this.queueSelfPreviewGeometry();
                });
            };
            const cleanupViewport = () => {
                window.removeEventListener('resize', refresh);
                window.removeEventListener('orientationchange', refresh);
                viewport?.removeEventListener('resize', refresh);
                viewport?.removeEventListener('scroll', refresh);
                if (this.callViewportFrame !== null) {
                    cancelAnimationFrame(this.callViewportFrame);
                    this.callViewportFrame = null;
                }
            };
            window.addEventListener('resize', refresh, { passive: true });
            window.addEventListener('orientationchange', refresh, { passive: true });
            viewport?.addEventListener('resize', refresh, { passive: true });
            viewport?.addEventListener('scroll', refresh, { passive: true });
            measure();
            this.selfPreviewShapeCleanup = cleanupViewport;
            if (typeof window.matchMedia !== 'function') return;

            const query = window.matchMedia('(orientation: portrait) and (pointer: coarse)');

            const apply = () => {
                if (query.matches === this.selfPreviewPortrait) return;

                this.selfPreviewPortrait = query.matches;
                // Das neue Format wird vermessen und die letzte Position
                // innerhalb der nun verfuegbaren Buehne gehalten.
                this.$nextTick(() => this.queueSelfPreviewGeometry());
            };

            this.selfPreviewPortrait = query.matches;

            if (typeof query.addEventListener === 'function') {
                query.addEventListener('change', apply);
                this.selfPreviewShapeCleanup = () => {
                    query.removeEventListener('change', apply);
                    cleanupViewport();
                };
            } else if (typeof query.addListener === 'function') {
                // Safari vor 14 kennt nur den alten Weg.
                query.addListener(apply);
                this.selfPreviewShapeCleanup = () => {
                    query.removeListener(apply);
                    cleanupViewport();
                };
            }
        },

        observeSelfPreviewStage() {
            const stage = this.$refs.selfPreviewStage;
            if (!stage) return;

            this.selfPreviewResizeCleanup?.();

            const refresh = () => this.queueSelfPreviewGeometry();
            const cleanups = [];

            if (typeof ResizeObserver === 'function') {
                const observer = new ResizeObserver(refresh);
                observer.observe(stage);
                cleanups.push(() => observer.disconnect());
            } else {
                window.addEventListener('resize', refresh, { passive: true });
                cleanups.push(() => window.removeEventListener('resize', refresh));
            }

            window.addEventListener('orientationchange', refresh, { passive: true });
            cleanups.push(() => window.removeEventListener('orientationchange', refresh));

            this.selfPreviewResizeCleanup = () => {
                cleanups.forEach((cleanup) => cleanup());
                this.selfPreviewResizeCleanup = null;
            };
        },

        queueSelfPreviewGeometry() {
            if (this.selfPreviewGeometryFrame !== null) {
                cancelAnimationFrame(this.selfPreviewGeometryFrame);
            }

            this.selfPreviewGeometryFrame = requestAnimationFrame(() => {
                this.selfPreviewGeometryFrame = null;
                this.positionSelfPreview();
            });
        },

        selfPreviewMetrics() {
            const preview = this.$refs.selfPreview;
            const stage = this.$refs.selfPreviewStage;
            if (!preview || !stage || !stage.clientWidth || !stage.clientHeight) return null;

            const desiredWidth = this.selfPreviewPortrait ? 104 : 176;
            const viewportWidth = Number(window.innerWidth) || stage.clientWidth;
            const fallbackWidth = Math.min(desiredWidth, viewportWidth * 0.42);
            const fallbackHeight = fallbackWidth * (this.selfPreviewPortrait ? 16 / 9 : 9 / 16);
            const previewWidth = preview.offsetWidth || fallbackWidth || this.selfPreviewWidth;
            const previewHeight = preview.offsetHeight || fallbackHeight || this.selfPreviewHeight;

            this.selfPreviewWidth = previewWidth;
            this.selfPreviewHeight = previewHeight;

            return {
                stage: { width: stage.clientWidth, height: stage.clientHeight },
                preview: { width: previewWidth, height: previewHeight },
            };
        },

        positionSelfPreview() {
            const metrics = this.selfPreviewMetrics();
            if (!metrics) return;

            const candidate = this.selfPreviewInitialized
                ? { x: this.selfPreviewLastSafeX, y: this.selfPreviewLastSafeY }
                : {
                    x: metrics.stage.width - metrics.preview.width - 12,
                    y: metrics.stage.height - metrics.preview.height - 12,
                };
            const safe = clampSelfPreviewPosition(candidate, metrics.stage, metrics.preview);

            this.selfPreviewLastSafeX = safe.x;
            this.selfPreviewLastSafeY = safe.y;

            if (!this.selfPreviewMinimized) {
                this.selfPreviewX = safe.x;
                this.selfPreviewY = safe.y;
            }

            this.updateSelfPreviewDock(
                this.selfPreviewMinimized
                    ? { x: this.selfPreviewLastSafeX, y: this.selfPreviewLastSafeY }
                    : safe,
                metrics,
                this.selfPreviewDockEdge,
            );
            this.selfPreviewInitialized = true;
        },

        updateSelfPreviewDock(candidate, metrics = this.selfPreviewMetrics(), edge = null) {
            if (!metrics) return;

            this.selfPreviewDockEdge = edge
                || nearestSelfPreviewEdge(candidate, metrics.stage, metrics.preview);
            const dock = selfPreviewDockPosition(
                this.selfPreviewDockEdge,
                candidate,
                metrics.stage,
                metrics.preview,
            );

            this.selfPreviewDockX = dock.x;
            this.selfPreviewDockY = dock.y;
        },

        rememberSafeSelfPreviewPosition(candidate, metrics) {
            const safe = clampSelfPreviewPosition(candidate, metrics.stage, metrics.preview);
            this.selfPreviewLastSafeX = safe.x;
            this.selfPreviewLastSafeY = safe.y;

            return safe;
        },

        minimizeSelfPreview(candidate = null) {
            if (!this.selfPreviewInitialized) {
                this.positionSelfPreview();
            }

            const metrics = this.selfPreviewMetrics();
            if (!metrics || !this.selfVideoVisible) return;

            const current = candidate || { x: this.selfPreviewX, y: this.selfPreviewY };

            if (!isSelfPreviewCenterOutside(current, metrics.stage, metrics.preview)) {
                this.rememberSafeSelfPreviewPosition(current, metrics);
            }

            this.updateSelfPreviewDock(current, metrics);
            this.selfPreviewMinimized = true;

            this.$nextTick(() => this.$refs.selfPreviewRestore?.focus());
        },

        restoreSelfPreview() {
            const metrics = this.selfPreviewMetrics();
            if (!metrics) return;

            const safe = clampSelfPreviewPosition(
                { x: this.selfPreviewLastSafeX, y: this.selfPreviewLastSafeY },
                metrics.stage,
                metrics.preview,
            );

            this.selfPreviewX = safe.x;
            this.selfPreviewY = safe.y;
            this.selfPreviewLastSafeX = safe.x;
            this.selfPreviewLastSafeY = safe.y;
            this.selfPreviewMinimized = false;
            this.selfPreviewInitialized = true;

            this.$nextTick(() => {
                this.queueSelfPreviewGeometry();
                this.$refs.selfPreviewMinimize?.focus();
            });
        },

        startSelfPreviewDrag(event) {
            if (event.button !== 0 && event.pointerType === 'mouse') return;
            if (this.selfPreviewMinimized || this.selfPreviewDragging) return;

            if (!this.selfPreviewInitialized) {
                this.positionSelfPreview();
            }

            const preview = this.$refs.selfPreview;
            const metrics = this.selfPreviewMetrics();
            if (!preview || !metrics) return;

            event.preventDefault();
            preview.setPointerCapture?.(event.pointerId);
            const pointerId = event.pointerId;
            const startX = event.clientX;
            const startY = event.clientY;
            const originX = this.selfPreviewX;
            const originY = this.selfPreviewY;
            this.selfPreviewDragging = true;

            const move = (moveEvent) => {
                if (moveEvent.pointerId !== pointerId) return;

                const candidate = {
                    x: originX + moveEvent.clientX - startX,
                    y: originY + moveEvent.clientY - startY,
                };

                // Waehrend des Ziehens darf die Kachel die Buehne verlassen.
                // Erst beim Loslassen entscheidet ihr Mittelpunkt ueber Dock
                // oder Rueckkehr in den voll sichtbaren Bereich.
                this.selfPreviewX = candidate.x;
                this.selfPreviewY = candidate.y;

                if (!isSelfPreviewCenterOutside(candidate, metrics.stage, metrics.preview)) {
                    this.rememberSafeSelfPreviewPosition(candidate, metrics);
                }
            };

            const removeListeners = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', finish);
                window.removeEventListener('pointercancel', finish);
                try { preview.releasePointerCapture?.(pointerId); } catch (_) {}
            };

            const finish = (finishEvent) => {
                if (finishEvent?.pointerId !== pointerId) return;

                removeListeners();
                this.selfPreviewDragging = false;
                this.selfPreviewDragCleanup = null;

                if (finishEvent.type === 'pointercancel') {
                    this.selfPreviewX = this.selfPreviewLastSafeX;
                    this.selfPreviewY = this.selfPreviewLastSafeY;
                    return;
                }

                const candidate = { x: this.selfPreviewX, y: this.selfPreviewY };

                if (isSelfPreviewCenterOutside(candidate, metrics.stage, metrics.preview)) {
                    this.minimizeSelfPreview(candidate);
                    return;
                }

                const safe = this.rememberSafeSelfPreviewPosition(candidate, metrics);
                this.selfPreviewX = safe.x;
                this.selfPreviewY = safe.y;
            };

            this.selfPreviewDragCleanup?.();
            this.selfPreviewDragCleanup = () => {
                removeListeners();
                this.selfPreviewDragging = false;
                this.selfPreviewX = this.selfPreviewLastSafeX;
                this.selfPreviewY = this.selfPreviewLastSafeY;
                this.selfPreviewDragCleanup = null;
            };
            window.addEventListener('pointermove', move, { passive: true });
            window.addEventListener('pointerup', finish);
            window.addEventListener('pointercancel', finish);
        },

        /** Muss aus einer echten Nutzergeste heraus laufen (Klick). */
        async unlockAudio() {
            const activeRoom = rawCallValue(this.room);
            const generation = this.connectionGeneration;
            if (!activeRoom || !this.connected || this.terminal || this.leaving || this.disposed) return;
            try {
                await activeRoom.startAudio();
                if (this.isActiveRoom(activeRoom, generation)) this.audioBlocked = !activeRoom.canPlaybackAudio;
            } catch (error) {
                console.error('[calls] Audio konnte nicht freigegeben werden.', error);
            }
        },

        async connect() {
            if (this.disposed || this.connectionBusy || this.leaving) return;

            const generation = ++this.connectionGeneration;
            this.connectionBusy = true;
            this.terminal = false;
            this.failed = false;
            this.connected = false;
            this.everConnected = false;
            this.statusLabel = config.labels.connecting;
            let response;
            let session;

            try {
                try {
                    response = await this.requestSession();

                    if (!response.ok) {
                        const payload = await response.json().catch(() => ({}));

                        if (generation !== this.connectionGeneration) return;

                        if (payload.recording) {
                            this.applyRecordingSession(payload.recording);
                        }

                        if (response.status === 428 && payload.recording?.requiresAcknowledgement) {
                            this.recordingEnabled = true;
                            this.recordingStatus = 'acknowledgement_required';
                            this.recordingPolicyVersion = payload.recording.policyVersion || config.recordingPolicyVersion;
                            this.recordingRequiresAcknowledgement = true;
                            this.recordingAcknowledgementError = '';
                            this.canPublish = false;
                            this.statusLabel = config.labels.recordingAcknowledgementRequired;

                            return;
                        }

                        if ([403, 410].includes(response.status)) {
                            await this.finishTerminalSession(payload.message || config.labels.ended);
                            return;
                        }

                        this.fail(payload.message || config.labels.connectionFailed, `Token-Endpoint HTTP ${response.status}`);
                        return;
                    }

                    session = await response.json();
                } catch (error) {
                    if (generation !== this.connectionGeneration || error?.name === 'AbortError') return;

                    this.fail(config.labels.connectionFailed, error);
                    return;
                }

                if (generation !== this.connectionGeneration) return;

                this.applySessionRole(session);
                this.applyRecordingSession(session.recording);

                const options = {
                    adaptiveStream: true,
                    dynacast: true,
                };

                if (Array.isArray(session.iceServers) && session.iceServers.length > 0) {
                    options.rtcConfig = { iceServers: session.iceServers };
                }

                const activeRoom = new Room(options);
                this.room = activeRoom;
                this.bindRoomEvents(activeRoom, generation);

                try {
                    await activeRoom.connect(session.wsUrl, session.token);
                } catch (error) {
                    if (generation !== this.connectionGeneration) return;

                    await this.disconnect();
                    if (this.disposed) return;
                    this.fail(config.labels.connectionFailed, error);
                    return;
                }

                if (!this.isActiveRoom(activeRoom, generation)) {
                    await activeRoom.disconnect().catch(() => {});

                    return;
                }

                this.connected = true;
                this.everConnected = true;
                this.failed = false;
                this.renderAllParticipants();
                this.syncRingback();

                if (this.recordingEnabled && this.recordingStatus !== 'active') {
                    this.startRecordingPolling();
                } else if (this.canPublish) {
                    await this.enableDevices(false);
                }
            } finally {
                if (generation === this.connectionGeneration) {
                    this.connectionBusy = false;
                }
            }
        },

        async requestSession() {
            this.sessionAbortController?.abort();
            const controller = new AbortController();
            this.sessionAbortController = controller;

            try {
                return await fetch(config.tokenUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrf,
                        Accept: 'application/json',
                    },
                    signal: controller.signal,
                });
            } finally {
                if (this.sessionAbortController === controller) {
                    this.sessionAbortController = null;
                }
            }
        },

        applyRecordingSession(recording) {
            this.recordingEnabled = Boolean(recording?.enabled);
            this.recordingStatus = recording?.status || (this.recordingEnabled ? 'requested' : 'disabled');
            this.recordingPolicyVersion = recording?.policyVersion || this.recordingPolicyVersion || config.recordingPolicyVersion;
            this.recordingStartDeadlineAt = recording?.startDeadlineAt || this.recordingStartDeadlineAt;
            this.recordingRequiresAcknowledgement = Boolean(recording?.requiresAcknowledgement);
            this.recordingCanPublish = Boolean(recording?.canPublish);
            this.canPublish = (this.recordingEnabled ? this.recordingCanPublish : this.participantMayPublish)
                && this.sfuCanPublish !== false && !this.recordingRequiresAcknowledgement;
        },

        applySessionRole(session) {
            if (['viewer', 'speaker', 'host'].includes(session?.role)) {
                this.participantMayPublish = session.role !== 'viewer';
            }
        },

        async syncPublishingPermission(participant, activeRoom, generation) {
            activeRoom = rawCallValue(activeRoom);
            participant = rawCallValue(participant);
            if (participant !== activeRoom.localParticipant || typeof participant.permissions?.canPublish !== 'boolean') return;
            this.sfuCanPublish = participant.permissions.canPublish;
            if (!this.recordingEnabled) this.participantMayPublish = this.sfuCanPublish;
            this.canPublish = (this.recordingEnabled ? this.recordingCanPublish : this.participantMayPublish)
                && this.sfuCanPublish && !this.recordingRequiresAcknowledgement;

            // Preparing recordings already have their own poll. An active
            // recording role change needs fresh server approval without
            // cancelling that poll or automatically opening any device.
            if (!this.connected || !this.recordingEnabled || this.recordingStatus !== 'active') return;
            this.permissionAbortController?.abort();
            const controller = new AbortController();
            this.permissionAbortController = controller;
            try {
                const response = await fetch(config.tokenUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.csrf, Accept: 'application/json' },
                    signal: controller.signal,
                });
                const payload = await response.json().catch(() => ({}));
                if (!this.isActiveRoom(activeRoom, generation) || this.permissionAbortController !== controller) return;
                if (response.ok) {
                    this.applySessionRole(payload);
                    this.applyRecordingSession(payload.recording);
                } else {
                    this.canPublish = false;
                    if ([403, 410].includes(response.status)) {
                        await this.finishTerminalSession(payload.message || config.labels.ended);
                    } else if (response.status === 428 && payload.recording?.requiresAcknowledgement) {
                        this.applyRecordingSession(payload.recording);
                        this.recordingRequiresAcknowledgement = true;
                        await this.disconnect();
                    }
                }
            } catch (error) {
                if (error?.name !== 'AbortError' && this.isActiveRoom(activeRoom, generation)) {
                    console.warn('[calls] Medienrechte konnten nicht aktualisiert werden.', error);
                }
            } finally {
                if (this.permissionAbortController === controller) this.permissionAbortController = null;
            }
        },

        get recordingStatusLabel() {
            if (this.recordingRequiresAcknowledgement) {
                return config.labels.recordingAcknowledgementRequired;
            }

            if (this.recordingStatus === 'active') {
                return config.labels.recordingActive;
            }

            if (['failed', 'aborted', 'unavailable'].includes(this.recordingStatus)) {
                return config.labels.recordingFailed;
            }

            return config.labels.recordingPreparing;
        },

        async acknowledgeRecording() {
            if (this.recordingAcknowledgementSubmitting || ! this.recordingPolicyVersion) return;

            const generation = this.connectionGeneration;
            this.recordingAcknowledgementSubmitting = true;
            this.recordingAcknowledgementError = '';

            try {
                const response = await fetch(config.recordingAcknowledgementUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrf,
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        accepted: true,
                        policyVersion: this.recordingPolicyVersion,
                    }),
                });

                if (! response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    this.recordingAcknowledgementError = payload.message || config.labels.recordingAcknowledgementFailed;

                    return;
                }

                if (generation !== this.connectionGeneration) return;

                await this.disconnectPromise;
                if (generation !== this.connectionGeneration || this.disposed) return;

                this.recordingRequiresAcknowledgement = false;
                this.recordingStatus = 'requested';
                this.statusLabel = config.labels.connecting;
                await this.connect();
            } catch (error) {
                console.error('[calls] Aufnahmehinweis konnte nicht bestaetigt werden.', error);
                this.recordingAcknowledgementError = config.labels.recordingAcknowledgementFailed;
            } finally {
                this.recordingAcknowledgementSubmitting = false;
            }
        },

        startRecordingPolling() {
            this.stopRecordingPolling();
            const generation = this.connectionGeneration;

            const poll = async () => {
                if (! this.room || generation !== this.connectionGeneration) return;

                // Reconnect darf den Aufnahme-Waechter nicht dauerhaft
                // verlieren. Solange die Room-Instanz lebt, wird nach kurzer
                // Pause erneut geprueft.
                if (! this.connected || this.recordingPollInFlight) {
                    this.recordingPollTimer = window.setTimeout(poll, 1000);

                    return;
                }

                this.recordingPollInFlight = true;

                try {
                    const response = await this.requestSession();
                    const payload = await response.json().catch(() => ({}));

                    if (generation !== this.connectionGeneration || ! this.room) return;

                    if (! response.ok) {
                        if (payload.recording) {
                            this.applyRecordingSession(payload.recording);
                        }

                        if ([403, 410].includes(response.status)) {
                            await this.finishTerminalSession(payload.message || config.labels.ended);
                            return;
                        }

                        if (response.status === 428 && payload.recording?.requiresAcknowledgement) {
                            this.recordingRequiresAcknowledgement = true;
                            this.recordingPolicyVersion = payload.recording.policyVersion || this.recordingPolicyVersion;
                            this.recordingStatus = 'acknowledgement_required';
                            this.disconnect();

                            return;
                        }

                        if (response.status !== 429) {
                            this.disconnect();
                            this.fail(payload.message || config.labels.recordingFailed, `Recording-Status HTTP ${response.status}`);

                            return;
                        }
                    } else {
                        this.applySessionRole(payload);
                        this.applyRecordingSession(payload.recording);

                        if (this.recordingStatus === 'active'
                            && (! this.participantMayPublish || this.canPublish)) {
                            this.recordingUnlockAttempts = 0;
                            this.stopRecordingPolling();

                            if (this.canPublish && generation === this.connectionGeneration && this.room) {
                                await this.enableDevices(false);
                            }

                            return;
                        }

                        if (this.recordingStatus === 'active' && this.participantMayPublish) {
                            this.recordingUnlockAttempts += 1;

                            if (this.recordingUnlockAttempts >= 5) {
                                this.recordingStatus = 'failed';
                                this.disconnect();
                                this.fail(config.labels.recordingFailed, 'LiveKit publishing permission was not confirmed');

                                return;
                            }
                        }
                    }
                } catch (error) {
                    if (generation !== this.connectionGeneration || error?.name === 'AbortError') return;

                    // Der serverseitige 30-Sekunden-Waechter bleibt die harte
                    // Grenze. Ein einzelner Netzfehler darf den Client davor
                    // nicht in einen unaufgezeichneten Ersatzmodus schicken.
                    console.warn('[calls] Aufnahme-Status konnte nicht abgefragt werden.', error);
                } finally {
                    if (generation === this.connectionGeneration) this.recordingPollInFlight = false;
                }

                if (this.recordingStatus !== 'active'
                    && this.recordingStartDeadlineAt
                    && Date.now() > Date.parse(this.recordingStartDeadlineAt) + 1000) {
                    this.disconnect();
                    this.fail(config.labels.recordingFailed, 'Recording start deadline exceeded');

                    return;
                }

                this.recordingPollTimer = window.setTimeout(poll, 2000);
            };

            this.recordingPollTimer = window.setTimeout(poll, 1000);
        },

        stopRecordingPolling() {
            if (this.recordingPollTimer !== null) {
                window.clearTimeout(this.recordingPollTimer);
                this.recordingPollTimer = null;
            }
        },

        /**
         * Mikrofon (und ggf. Kamera) einschalten.
         *
         * fromGesture=false ist der automatische Versuch direkt nach dem
         * Beitritt. Scheitert er, KEINE Fehlermeldung, sondern der
         * Aktivieren-Knopf: iOS Safari lehnt getUserMedia ohne Nutzergeste
         * grundsaetzlich ab — der automatische Versuch KANN dort gar nicht
         * gelingen. Erst wenn auch der Klick-Versuch scheitert, liegt ein
         * echtes Problem vor, und die Meldung nennt es beim Namen.
         */
        async enableDevices(fromGesture) {
            const activeRoom = rawCallValue(this.room);
            const generation = this.connectionGeneration;
            if (!activeRoom || !this.connected || !this.canPublish || this.terminal || this.failed) return;
            let needsGesture = false;

            // Mikrofon und Kamera GETRENNT behandeln: Frueher liess ein
            // Kamerafehler auch das bereits aktive Mikrofon als "nicht
            // verfuegbar" erscheinen – die Meldung war schlicht falsch.
            if (!this.micOn && !this.micBusy) {
                this.micBusy = true;
                try {
                    await this.enableTrack('microphone');
                    if (!this.isActiveRoom(activeRoom, generation)) return;
                    this.micOn = true;
                } catch (error) {
                    if (!this.isActiveRoom(activeRoom, generation)) return;
                    console.error('[calls] Mikrofon nicht verfuegbar:', error);

                    if (fromGesture) {
                        this.toast(this.deviceErrorLabel(error, 'microphone'), 'warning');
                    } else {
                        needsGesture = true;
                    }
                } finally {
                    if (generation === this.connectionGeneration) this.micBusy = false;
                }
            }

            if (this.startWithVideo && !this.cameraOn && !this.cameraBusy) {
                this.cameraBusy = true;
                try {
                    await this.enableTrack('camera');
                    if (!this.isActiveRoom(activeRoom, generation)) return;
                    this.cameraOn = true;
                } catch (error) {
                    if (!this.isActiveRoom(activeRoom, generation)) return;
                    // Kein Grund, den Anruf zu stoeren: Ton laeuft weiter,
                    // die Kamera laesst sich jederzeit nachtraeglich zuschalten.
                    console.error('[calls] Kamera nicht verfuegbar:', error);

                    if (fromGesture) {
                        this.toast(this.deviceErrorLabel(error, 'camera'), 'warning');
                    } else {
                        needsGesture = true;
                    }
                } finally {
                    if (generation === this.connectionGeneration) this.cameraBusy = false;
                }
            }

            this.deviceSetupNeeded = needsGesture;
        },

        /** Klick auf den Aktivieren-Knopf (echte Nutzergeste). */
        async retryDevices() {
            if (!this.canControlMedia || this.micBusy || this.cameraBusy) return;
            this.deviceSetupNeeded = false;
            await this.enableDevices(true);
        },

        /**
         * Ein Geraet ueber LiveKit einschalten — und wenn dessen interner
         * Weg (Geraeteauswahl, Constraints) scheitert, direkt ueber
         * getUserMedia aufnehmen und den Track von Hand publizieren. Echte
         * Freigabefehler des Browsers werden unveraendert durchgereicht,
         * nur fuer alles andere existiert der zweite Weg.
         */
        async enableTrack(kind) {
            const activeRoom = rawCallValue(this.room);
            const generation = this.connectionGeneration;

            if (!activeRoom || !this.isActiveRoom(activeRoom, generation) || !this.canPublish || this.failed || this.leaving) {
                throw new DOMException('Call is no longer publishable.', 'AbortError');
            }

            try {
                if (kind === 'camera') {
                    await activeRoom.localParticipant.setCameraEnabled(true);
                } else {
                    await activeRoom.localParticipant.setMicrophoneEnabled(true);
                }

                if (!this.isActiveRoom(activeRoom, generation) || !this.canPublish) {
                    await activeRoom.localParticipant.setCameraEnabled?.(false).catch(() => {});
                    await activeRoom.localParticipant.setMicrophoneEnabled?.(false).catch(() => {});
                    throw new DOMException('Call was closed while enabling media.', 'AbortError');
                }
                return;
            } catch (error) {
                const name = error?.name || '';

                if (!this.isActiveRoom(activeRoom, generation)) {
                    throw new DOMException('Call was closed while enabling media.', 'AbortError');
                }
                if (name === 'NotAllowedError' || name === 'SecurityError') {
                    throw error;
                }

                console.warn(`[calls] LiveKit-Weg fuer ${kind} scheiterte (${name || 'unbekannt'}), versuche direkten Weg:`, error);
            }

            if (!this.isActiveRoom(activeRoom, generation) || !this.canPublish) {
                throw new DOMException('Call was closed while enabling media.', 'AbortError');
            }

            const stream = await navigator.mediaDevices.getUserMedia(
                kind === 'camera' ? { video: true } : { audio: true },
            );
            const track = kind === 'camera' ? stream.getVideoTracks()[0] : stream.getAudioTracks()[0];

            if (!this.isActiveRoom(activeRoom, generation) || !this.canPublish) {
                stream.getTracks().forEach((mediaTrack) => mediaTrack.stop());
                throw new DOMException('Call was closed while opening media.', 'AbortError');
            }

            try {
                await activeRoom.localParticipant.publishTrack(track, {
                    source: kind === 'camera' ? Track.Source.Camera : Track.Source.Microphone,
                });
                if (!this.isActiveRoom(activeRoom, generation) || !this.canPublish) {
                    await activeRoom.localParticipant.unpublishTrack?.(track).catch(() => {});
                    throw new DOMException('Call was closed while publishing media.', 'AbortError');
                }
            } catch (error) {
                stream.getTracks().forEach((mediaTrack) => mediaTrack.stop());
                throw error;
            }
        },

        bindRoomEvents(activeRoom = this.room, generation = this.connectionGeneration) {
            activeRoom = rawCallValue(activeRoom);
            const current = (callback) => (...args) => {
                if (this.isActiveRoom(activeRoom, generation)) return callback(...args);
            };
            const render = () => {
                this.syncLocalMediaState(activeRoom);
                this.renderAllParticipants();
            };

            activeRoom
                .on(RoomEvent.ParticipantConnected, current(render))
                .on(RoomEvent.ParticipantDisconnected, current(render))
                .on(RoomEvent.TrackSubscribed, current(render))
                .on(RoomEvent.TrackUnsubscribed, current((track) => { track?.detach(); render(); }))
                .on(RoomEvent.LocalTrackPublished, current(render))
                .on(RoomEvent.LocalTrackUnpublished, current(render))
                .on(RoomEvent.ParticipantPermissionsChanged, current((_previous, participant) =>
                    this.syncPublishingPermission(participant, activeRoom, generation)))
                .on(RoomEvent.ActiveSpeakersChanged, current((speakers) => this.highlightSpeakers(speakers)))
                // Browser blockieren Autoplay ohne Nutzergeste. Ohne diesen
                // Zweig bliebe der Anruf dauerhaft stumm, ohne dass irgendetwas
                // darauf hinweist – die Kacheln sehen voellig normal aus.
                .on(RoomEvent.AudioPlaybackStatusChanged, current(() => {
                    this.audioBlocked = !activeRoom.canPlaybackAudio;
                }))
                .on(RoomEvent.TrackMuted, current((publication, participant) => {
                    if (participant === activeRoom.localParticipant && publication.kind === Track.Kind.Audio) {
                        this.toast(config.labels.muted, 'warning');
                    }
                    render();
                }))
                .on(RoomEvent.TrackUnmuted, current(render))
                .on(RoomEvent.ConnectionStateChanged, current((state) => {
                    if (state === ConnectionState.Reconnecting) {
                        this.connected = false;
                        this.statusLabel = config.labels.reconnecting;
                    } else if (state === ConnectionState.Connected) {
                        this.connected = true;
                    }
                    this.syncRingback();
                }))
                .on(RoomEvent.Disconnected, current((reason) => {
                    this.stopRecordingPolling();
                    this.connectionGeneration += 1;
                    this.sessionAbortController?.abort();
                    this.sessionAbortController = null;
                    this.permissionAbortController?.abort();
                    this.permissionAbortController = null;
                    this.room = null;
                    this.connected = false;
                    this.connectionBusy = false;
                    this.recordingPollInFlight = false;
                    this.resetMediaState();
                    this.clearTiles(activeRoom);
                    this.syncRingback();

                    const ended = [
                        DisconnectReason.CLIENT_INITIATED,
                        DisconnectReason.PARTICIPANT_REMOVED,
                        DisconnectReason.ROOM_DELETED,
                        DisconnectReason.ROOM_CLOSED,
                        DisconnectReason.DUPLICATE_IDENTITY,
                        'clientInitiated',
                    ].includes(reason);
                    if (!ended) {
                        this.fail(config.labels.connectionFailed, `Disconnected: ${reason ?? 'unbekannt'}`);
                        return;
                    }

                    this.terminal = true;
                    this.failed = false;
                    this.statusLabel = config.labels.ended;
                }));
        },

        renderAllParticipants() {
            const activeRoom = rawCallValue(this.room);
            if (!activeRoom) {
                return;
            }

            const grid = this.$refs.grid;
            const participants = [activeRoom.localParticipant, ...activeRoom.remoteParticipants.values()];
            this.participantCount = participants.length;
            this.remoteCount = activeRoom.remoteParticipants.size;
            // Sobald jemand wirklich da ist, hoert das Freizeichen auf.
            this.syncRingback();

            const seen = new Set();

            participants.forEach((participant) => {
                seen.add(participant.identity);
                const tile = this.ensureTile(participant);
                this.attachTracks(participant, tile);
            });

            // Kacheln verlassener Teilnehmer entfernen
            this.tiles.forEach((tile, identity) => {
                if (!seen.has(identity)) {
                    tile.root.remove();
                    this.tiles.delete(identity);
                }
            });

            if (this.selfVideoVisible) {
                this.queueSelfPreviewGeometry();
            }
        },

        ensureTile(participant) {
            participant = rawCallValue(participant);
            let tile = this.tiles.get(participant.identity);

            if (tile) {
                tile.name.textContent = participant.name || participant.identity;
                return tile;
            }

            const root = document.createElement('div');
            const isLocal = participant === rawCallValue(this.room).localParticipant;
            root.className = isLocal
                ? 'rt-call-tile rt-call-tile--local absolute inset-0 flex min-h-0 items-center justify-center overflow-hidden bg-black'
                : 'rt-call-tile relative flex min-h-0 items-center justify-center overflow-hidden rounded-[1.1rem] bg-white/[0.04] ring-1 ring-white/10 transition-shadow';
            root.dataset.identity = participant.identity;

            const video = document.createElement('video');
            video.autoplay = true;
            video.playsInline = true;
            video.muted = isLocal;
            video.className = 'absolute inset-0 hidden h-full w-full object-cover';

            const audio = document.createElement('audio');
            audio.autoplay = true;

            const placeholder = document.createElement('div');
            placeholder.className = 'flex h-16 w-16 items-center justify-center rounded-full bg-white/10 text-xl font-extrabold text-white/80 sm:h-20 sm:w-20';
            placeholder.textContent = (participant.name || participant.identity || '?').trim().charAt(0).toUpperCase();

            const badge = document.createElement('span');
            badge.className = 'absolute bottom-2 left-2 z-10 inline-flex max-w-[85%] items-center gap-1.5 truncate rounded-full bg-black/55 px-2.5 py-1 text-[11px] font-bold text-white backdrop-blur';

            const name = document.createElement('span');
            name.className = 'truncate';
            name.textContent = participant.name || participant.identity;

            const mutedIcon = document.createElement('i');
            mutedIcon.className = 'far fa-microphone-slash hidden text-rt-red-light';
            mutedIcon.setAttribute('aria-hidden', 'true');

            badge.appendChild(mutedIcon);
            badge.appendChild(name);
            root.appendChild(video);
            root.appendChild(audio);
            root.appendChild(placeholder);
            root.appendChild(badge);

            (isLocal ? this.$refs.selfPreview : this.$refs.grid).appendChild(root);

            tile = { root, video, audio, placeholder, name, mutedIcon };
            this.tiles.set(participant.identity, tile);

            return tile;
        },

        attachTracks(participant, tile) {
            participant = rawCallValue(participant);
            const isLocal = participant === rawCallValue(this.room).localParticipant;
            const preferredVideoSource = isLocal ? Track.Source.Camera : Track.Source.ScreenShare;
            let selectedVideoPublication = null;
            let audioMuted = true;

            participant.trackPublications.forEach((publication) => {
                const track = publication.track;

                if (publication.kind === Track.Kind.Audio) {
                    audioMuted = publication.isMuted;

                    if (track && !isLocal) {
                        track.attach(tile.audio);
                    }
                }

                if (publication.kind === Track.Kind.Video && track && !publication.isMuted) {
                    if (!selectedVideoPublication
                        || (publication.source === preferredVideoSource
                            && selectedVideoPublication.source !== preferredVideoSource)) {
                        selectedVideoPublication = publication;
                    }
                }
            });

            // Im eigenen PiP bleibt die Kamera sichtbar, waehrend der Screen-
            // Track publiziert bleibt. Bei anderen Teilnehmern hat deren
            // Bildschirmfreigabe weiterhin Vorrang in der grossen Kachel.
            participant.trackPublications.forEach((publication) => {
                if (publication.kind !== Track.Kind.Video || !publication.track) return;

                if (publication === selectedVideoPublication) {
                    publication.track.attach(tile.video);
                } else {
                    publication.track.detach(tile.video);
                }
            });

            const hasVideo = selectedVideoPublication !== null;

            tile.video.classList.toggle('hidden', !hasVideo);
            tile.placeholder.classList.toggle('hidden', hasVideo);
            tile.mutedIcon.classList.toggle('hidden', !audioMuted);

            if (isLocal) {
                this.selfVideoVisible = hasVideo;
            }
        },

        highlightSpeakers(speakers) {
            const active = new Set(speakers.map((speaker) => speaker.identity));

            this.tiles.forEach((tile, identity) => {
                tile.root.classList.toggle('rt-call-tile--speaking', active.has(identity));
                tile.root.style.boxShadow = active.has(identity)
                    ? '0 0 0 2px rgb(16 185 129), 0 0 24px -4px rgb(16 185 129 / 0.55)'
                    : '';
            });
        },

        async toggleMic() { await this.toggleMedia('microphone'); },

        async toggleCamera() { await this.toggleMedia('camera'); },

        async toggleMedia(kind) {
            const state = kind === 'camera' ? 'cameraOn' : 'micOn';
            const busy = kind === 'camera' ? 'cameraBusy' : 'micBusy';
            if (!this.canControlMedia || this[busy]) return;

            const activeRoom = rawCallValue(this.room);
            const generation = this.connectionGeneration;
            const enable = !this[state];
            this[busy] = true;

            try {
                if (enable) {
                    await this.enableTrack(kind);
                } else if (kind === 'camera') {
                    await activeRoom.localParticipant.setCameraEnabled(false);
                } else {
                    await activeRoom.localParticipant.setMicrophoneEnabled(false);
                }
                if (!this.isActiveRoom(activeRoom, generation)) return;
                this[state] = enable;
                this.syncLocalMediaState(activeRoom);
            } catch (error) {
                if (!this.isActiveRoom(activeRoom, generation)) return;
                this.syncLocalMediaState(activeRoom);
                console.error(`[calls] ${kind}-Umschalten fehlgeschlagen:`, error);
                if (enable) this.toast(this.deviceErrorLabel(error, kind), 'warning');
            } finally {
                if (generation === this.connectionGeneration) this[busy] = false;
            }
        },

        async toggleScreenShare() {
            if (!this.canControlMedia || this.screenShareBusy || !this.screenShareSupported) return;
            const activeRoom = rawCallValue(this.room);
            const generation = this.connectionGeneration;
            const enable = !this.screenSharing;
            this.screenShareBusy = true;
            try {
                await activeRoom.localParticipant.setScreenShareEnabled(enable);
                if (!this.isActiveRoom(activeRoom, generation)) {
                    await activeRoom.localParticipant.setScreenShareEnabled(false).catch(() => {});
                    return;
                }
                this.screenSharing = enable;
                this.syncLocalMediaState(activeRoom);
            } catch (error) {
                if (this.isActiveRoom(activeRoom, generation)) {
                    this.syncLocalMediaState(activeRoom);
                    console.warn('[calls] Bildschirmfreigabe konnte nicht geaendert werden.', error);
                }
            } finally {
                if (generation === this.connectionGeneration) this.screenShareBusy = false;
            }
        },

        toast(text, type = 'info') {
            window.dispatchEvent(new CustomEvent('swal:toast', { detail: { text, type } }));
        },

        disconnect() {
            if (this.leaving && this.disconnectPromise) return this.disconnectPromise;
            const activeRoom = rawCallValue(this.room);
            this.stopRecordingPolling();
            this.connectionGeneration += 1;
            this.sessionAbortController?.abort();
            this.sessionAbortController = null;
            this.permissionAbortController?.abort();
            this.permissionAbortController = null;
            this.room = null;
            this.connected = false;
            this.connectionBusy = false;
            this.terminal = true;
            this.leaving = true;
            this.statusLabel = config.labels.ended;
            this.recordingPollInFlight = false;
            this.resetMediaState();
            this.clearTiles(activeRoom);
            // Immer stoppen, nicht nur ueber syncRingback: beim Auflegen faehrt
            // die Seite gleich darauf weg, und ein weiterlaufender Timer im
            // globalen RTSound-Objekt wuerde nach dem Anruf weiter tuten.
            this.ringbackActive = false;
            window.RTSound?.stopRingback();

            const operation = Promise.resolve().then(() => activeRoom?.disconnect()).catch((error) => {
                console.warn('[calls] Verbindung konnte nicht vollstaendig getrennt werden.', error);
            }).finally(() => {
                if (this.disconnectPromise === operation) {
                    this.disconnectPromise = null;
                    this.leaving = false;
                }
            });
            this.disconnectPromise = operation;
            return operation;
        },

        destroy() {
            this.disposed = true;
            this.selfPreviewDragCleanup?.();
            this.selfPreviewShapeCleanup?.();
            this.selfPreviewResizeCleanup?.();

            if (this.selfPreviewGeometryFrame !== null) {
                cancelAnimationFrame(this.selfPreviewGeometryFrame);
                this.selfPreviewGeometryFrame = null;
            }

            this.disconnect();
        },
    }));
});
