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
} from 'livekit-client';
import {
    clampSelfPreviewPosition,
    isSelfPreviewCenterOutside,
    nearestSelfPreviewEdge,
    selfPreviewDockPosition,
} from './call-self-preview.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('callRoom', (config) => ({
        room: null,
        connected: false,
        statusLabel: config.labels.connecting,
        participantCount: 1,
        panelOpen: window.innerWidth >= 1024,
        panelTab: 'participants',
        canPublish: Boolean(config.canPublish),
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
            this.connected = false;
            this.failed = true;
            this.statusLabel = label || config.labels.connectionFailed;
            // Ein Freizeichen ueber einer Fehlermeldung waere schlicht gelogen.
            this.syncRingback();

            if (error) {
                console.error('[calls] Verbindung fehlgeschlagen:', error);
            }
        },

        /** Nach einem Fehlschlag neu verbinden (Button im Fehler-Panel). */
        async retry() {
            this.failed = false;
            this.statusLabel = config.labels.connecting;

            try { await this.room?.disconnect(); } catch (_) {}
            this.room = null;
            this.tiles.forEach((tile) => tile.root.remove());
            this.tiles.clear();

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

        /**
         * Format der Eigenvorschau an die Geraeteform binden.
         *
         * 'pointer: coarse' grenzt Touch-Geraete ab; erst zusammen mit der
         * Hochformat-Ausrichtung ergibt das "Handy aufrecht". Ein Rechner mit
         * hochkantem Monitor bleibt damit im Querformat, ein quer gehaltenes
         * Handy wechselt korrekt mit.
         */
        watchViewportShape() {
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
                this.selfPreviewShapeCleanup = () => query.removeEventListener('change', apply);
            } else if (typeof query.addListener === 'function') {
                // Safari vor 14 kennt nur den alten Weg.
                query.addListener(apply);
                this.selfPreviewShapeCleanup = () => query.removeListener(apply);
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
            try {
                await this.room?.startAudio();
                this.audioBlocked = ! this.room?.canPlaybackAudio;
            } catch (error) {
                console.error('[calls] Audio konnte nicht freigegeben werden.', error);
            }
        },

        async connect() {
            let session;

            try {
                const response = await fetch(config.tokenUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrf,
                        Accept: 'application/json',
                    },
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    this.fail(payload.message || config.labels.connectionFailed, `Token-Endpoint HTTP ${response.status}`);
                    return;
                }

                session = await response.json();
            } catch (error) {
                this.fail(config.labels.connectionFailed, error);
                return;
            }

            const options = {
                adaptiveStream: true,
                dynacast: true,
            };

            if (Array.isArray(session.iceServers) && session.iceServers.length > 0) {
                options.rtcConfig = { iceServers: session.iceServers };
            }

            this.room = new Room(options);
            this.bindRoomEvents();

            try {
                await this.room.connect(session.wsUrl, session.token);
            } catch (error) {
                this.fail(config.labels.connectionFailed, error);
                return;
            }

            this.connected = true;
            this.everConnected = true;
            this.failed = false;
            this.renderAllParticipants();
            this.syncRingback();

            if (this.canPublish) {
                await this.enableDevices(false);
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
            let needsGesture = false;

            // Mikrofon und Kamera GETRENNT behandeln: Frueher liess ein
            // Kamerafehler auch das bereits aktive Mikrofon als "nicht
            // verfuegbar" erscheinen – die Meldung war schlicht falsch.
            if (! this.micOn) {
                try {
                    await this.enableTrack('microphone');
                    this.micOn = true;
                } catch (error) {
                    console.error('[calls] Mikrofon nicht verfuegbar:', error);

                    if (fromGesture) {
                        this.toast(this.deviceErrorLabel(error, 'microphone'), 'warning');
                    } else {
                        needsGesture = true;
                    }
                }
            }

            if (this.startWithVideo && ! this.cameraOn) {
                try {
                    await this.enableTrack('camera');
                    this.cameraOn = true;
                } catch (error) {
                    // Kein Grund, den Anruf zu stoeren: Ton laeuft weiter,
                    // die Kamera laesst sich jederzeit nachtraeglich zuschalten.
                    console.error('[calls] Kamera nicht verfuegbar:', error);

                    if (fromGesture) {
                        this.toast(this.deviceErrorLabel(error, 'camera'), 'warning');
                    } else {
                        needsGesture = true;
                    }
                }
            }

            this.deviceSetupNeeded = needsGesture;
        },

        /** Klick auf den Aktivieren-Knopf (echte Nutzergeste). */
        async retryDevices() {
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
            try {
                if (kind === 'camera') {
                    await this.room.localParticipant.setCameraEnabled(true);
                } else {
                    await this.room.localParticipant.setMicrophoneEnabled(true);
                }

                return;
            } catch (error) {
                const name = error?.name || '';

                if (name === 'NotAllowedError' || name === 'SecurityError') {
                    throw error;
                }

                console.warn(`[calls] LiveKit-Weg fuer ${kind} scheiterte (${name || 'unbekannt'}), versuche direkten Weg:`, error);
            }

            const stream = await navigator.mediaDevices.getUserMedia(
                kind === 'camera' ? { video: true } : { audio: true },
            );
            const track = kind === 'camera' ? stream.getVideoTracks()[0] : stream.getAudioTracks()[0];

            await this.room.localParticipant.publishTrack(track, {
                source: kind === 'camera' ? Track.Source.Camera : Track.Source.Microphone,
            });
        },

        bindRoomEvents() {
            this.room
                .on(RoomEvent.ParticipantConnected, () => this.renderAllParticipants())
                .on(RoomEvent.ParticipantDisconnected, () => this.renderAllParticipants())
                .on(RoomEvent.TrackSubscribed, () => this.renderAllParticipants())
                .on(RoomEvent.TrackUnsubscribed, () => this.renderAllParticipants())
                .on(RoomEvent.LocalTrackPublished, () => this.renderAllParticipants())
                .on(RoomEvent.LocalTrackUnpublished, () => this.renderAllParticipants())
                .on(RoomEvent.ActiveSpeakersChanged, (speakers) => this.highlightSpeakers(speakers))
                // Browser blockieren Autoplay ohne Nutzergeste. Ohne diesen
                // Zweig bliebe der Anruf dauerhaft stumm, ohne dass irgendetwas
                // darauf hinweist – die Kacheln sehen voellig normal aus.
                .on(RoomEvent.AudioPlaybackStatusChanged, () => {
                    this.audioBlocked = ! this.room.canPlaybackAudio;
                })
                .on(RoomEvent.TrackMuted, (publication, participant) => {
                    if (participant === this.room.localParticipant && publication.kind === Track.Kind.Audio) {
                        this.micOn = false;
                        this.toast(config.labels.muted, 'warning');
                    }
                    this.renderAllParticipants();
                })
                .on(RoomEvent.TrackUnmuted, () => this.renderAllParticipants())
                .on(RoomEvent.ConnectionStateChanged, (state) => {
                    if (state === ConnectionState.Reconnecting) {
                        this.connected = false;
                        this.statusLabel = config.labels.reconnecting;
                    } else if (state === ConnectionState.Connected) {
                        this.connected = true;
                    }
                })
                .on(RoomEvent.Disconnected, (reason) => {
                    this.connected = false;
                    this.syncRingback();

                    // Kam nie eine Medienverbindung zustande, ist das ein
                    // FEHLER (z. B. blockierte UDP-Ports) — kein Anrufende.
                    if (! this.everConnected) {
                        this.fail(config.labels.connectionFailed, `Disconnected: ${reason ?? 'unbekannt'}`);
                        return;
                    }

                    this.statusLabel = config.labels.ended;
                });
        },

        renderAllParticipants() {
            if (!this.room) {
                return;
            }

            const grid = this.$refs.grid;
            const participants = [this.room.localParticipant, ...this.room.remoteParticipants.values()];
            this.participantCount = participants.length;
            this.remoteCount = this.room.remoteParticipants.size;
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
            let tile = this.tiles.get(participant.identity);

            if (tile) {
                tile.name.textContent = participant.name || participant.identity;
                return tile;
            }

            const root = document.createElement('div');
            const isLocal = participant === this.room.localParticipant;
            root.className = isLocal
                ? 'rt-call-tile rt-call-tile--local absolute inset-0 flex min-h-0 items-center justify-center overflow-hidden bg-black'
                : 'rt-call-tile relative flex min-h-0 items-center justify-center overflow-hidden rounded-[1.1rem] bg-white/[0.04] ring-1 ring-white/10 transition-shadow';
            root.dataset.identity = participant.identity;

            const video = document.createElement('video');
            video.autoplay = true;
            video.playsInline = true;
            video.muted = participant === this.room.localParticipant;
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
            const isLocal = participant === this.room.localParticipant;
            const preferredVideoSource = isLocal ? Track.Source.Camera : Track.Source.ScreenShare;
            let selectedVideoPublication = null;
            let audioMuted = true;

            participant.trackPublications.forEach((publication) => {
                const track = publication.track;

                if (publication.kind === Track.Kind.Audio) {
                    audioMuted = publication.isMuted;

                    if (track && participant !== this.room.localParticipant) {
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

            if (participant === this.room.localParticipant) {
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

        async toggleMic() {
            if (!this.room || !this.canPublish) return;
            const enable = ! this.micOn;
            this.micOn = enable;

            try {
                if (enable) {
                    await this.enableTrack('microphone');
                } else {
                    await this.room.localParticipant.setMicrophoneEnabled(false);
                }
            } catch (error) {
                // Auf den TATSAECHLICHEN Zustand zuruecksetzen: Scheitert das
                // AUSschalten, laeuft das Mikrofon real weiter – ein Knopf,
                // der dann "stumm" zeigte, waere ein Privacy-Problem.
                this.micOn = ! enable;
                console.error('[calls] Mikrofon-Umschalten fehlgeschlagen:', error);

                // Ein stiller Ruecksprung des Knopfs liesse den Nutzer raten.
                if (enable) {
                    this.toast(this.deviceErrorLabel(error, 'microphone'), 'warning');
                }
            }
        },

        async toggleCamera() {
            if (!this.room || !this.canPublish) return;
            const enable = ! this.cameraOn;
            this.cameraOn = enable;

            try {
                if (enable) {
                    await this.enableTrack('camera');
                } else {
                    await this.room.localParticipant.setCameraEnabled(false);
                }
            } catch (error) {
                // Wie beim Mikrofon: Nach Disable-Fehler laeuft die Kamera
                // real weiter – die Anzeige muss das widerspiegeln.
                this.cameraOn = ! enable;
                console.error('[calls] Kamera-Umschalten fehlgeschlagen:', error);

                if (enable) {
                    this.toast(this.deviceErrorLabel(error, 'camera'), 'warning');
                }
            }
        },

        async toggleScreenShare() {
            if (!this.room || !this.canPublish) return;
            this.screenSharing = !this.screenSharing;
            await this.room.localParticipant.setScreenShareEnabled(this.screenSharing).catch(() => {
                this.screenSharing = false;
            });
        },

        toast(text, type = 'info') {
            window.dispatchEvent(new CustomEvent('swal:toast', { detail: { text, type } }));
        },

        disconnect() {
            // Immer stoppen, nicht nur ueber syncRingback: beim Auflegen faehrt
            // die Seite gleich darauf weg, und ein weiterlaufender Timer im
            // globalen RTSound-Objekt wuerde nach dem Anruf weiter tuten.
            this.ringbackActive = false;
            window.RTSound?.stopRingback();

            if (this.room) {
                this.room.disconnect();
                this.room = null;
            }
        },

        destroy() {
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
