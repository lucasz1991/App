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

document.addEventListener('alpine:init', () => {
    window.Alpine.data('callRoom', (config) => ({
        room: null,
        connected: false,
        statusLabel: config.labels.connecting,
        participantCount: 1,
        panelOpen: window.innerWidth >= 1024,
        canPublish: Boolean(config.canPublish),
        startWithVideo: config.startWithVideo !== false,
        micOn: false,
        cameraOn: false,
        screenSharing: false,
        audioBlocked: false,
        // Verbindungsfehler und regulaeres Ende sind ZWEI Zustaende: ein
        // fehlgeschlagener Aufbau zeigte frueher dieselbe Meldung wie ein
        // beendeter Anruf — die Fehlersuche lief dadurch in die Irre.
        failed: false,
        everConnected: false,
        tiles: new Map(),

        async init() {
            await this.connect();
        },

        /** In den Fehlerzustand wechseln und den Grund fuer Diagnose loggen. */
        fail(label, error) {
            this.connected = false;
            this.failed = true;
            this.statusLabel = label || config.labels.connectionFailed;

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
            const count = Math.max(1, this.tiles.size);
            const columns = count <= 1 ? 1 : count <= 4 ? 2 : count <= 9 ? 3 : 4;

            return `grid-template-columns: repeat(${columns}, minmax(0, 1fr));`;
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

            if (this.canPublish) {
                try {
                    await this.room.localParticipant.setMicrophoneEnabled(true);
                    this.micOn = true;
                    await this.room.localParticipant.setCameraEnabled(this.startWithVideo);
                    this.cameraOn = this.startWithVideo;
                } catch (error) {
                    // Berechtigung verweigert: Anruf laeuft als Zuhoerer weiter,
                    // aber der Nutzer erfaehrt jetzt, WARUM er stumm ist – und
                    // bekommt den Einrichtungsdialog, statt ratlos dazusitzen.
                    console.error('[calls] Geraetefreigabe fehlgeschlagen:', error);
                    this.toast(config.labels.deviceBlocked, 'warning');
                    window.dispatchEvent(new CustomEvent('rt:permissions-open'));
                }
            }
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

            // Reihenfolge stabil halten: lokale Kachel zuletzt
            const local = this.tiles.get(this.room.localParticipant.identity);
            if (local) {
                grid.appendChild(local.root);
            }
        },

        ensureTile(participant) {
            let tile = this.tiles.get(participant.identity);

            if (tile) {
                tile.name.textContent = participant.name || participant.identity;
                return tile;
            }

            const root = document.createElement('div');
            root.className = 'rt-call-tile relative flex min-h-0 items-center justify-center overflow-hidden rounded-[1.1rem] bg-white/[0.04] ring-1 ring-white/10 transition-shadow';
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

            this.$refs.grid.appendChild(root);

            tile = { root, video, audio, placeholder, name, mutedIcon };
            this.tiles.set(participant.identity, tile);

            return tile;
        },

        attachTracks(participant, tile) {
            let hasVideo = false;
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
                    // Screenshare hat Vorrang vor der Kamera
                    if (!hasVideo || publication.source === Track.Source.ScreenShare) {
                        track.attach(tile.video);
                        hasVideo = true;
                    }
                }
            });

            tile.video.classList.toggle('hidden', !hasVideo);
            tile.placeholder.classList.toggle('hidden', hasVideo);
            tile.mutedIcon.classList.toggle('hidden', !audioMuted);
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
            this.micOn = !this.micOn;
            await this.room.localParticipant.setMicrophoneEnabled(this.micOn).catch(() => {
                this.micOn = false;
            });
        },

        async toggleCamera() {
            if (!this.room || !this.canPublish) return;
            this.cameraOn = !this.cameraOn;
            await this.room.localParticipant.setCameraEnabled(this.cameraOn).catch(() => {
                this.cameraOn = false;
            });
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
            if (this.room) {
                this.room.disconnect();
                this.room = null;
            }
        },

        destroy() {
            this.disconnect();
        },
    }));
});
