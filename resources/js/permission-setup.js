/*
 * Berechtigungen einmalig einrichten (Mikrofon, Kamera, Benachrichtigungen).
 *
 * Warum ueberhaupt eine eigene Einrichtung?
 * Browser vergeben Geraeterechte pro Herkunft und merken sie sich – aber nur,
 * wenn der Nutzer sie einmal bewusst erteilt hat. Wird erst im Moment des
 * Anrufs gefragt, kommt die Abfrage zum denkbar schlechtesten Zeitpunkt: Es
 * klingelt, und statt zu sprechen sucht man einen Dialog. Deshalb fragen wir
 * beim ersten Start – in Ruhe, mit Erklaerung.
 *
 * Wichtige Einschraenkung, die sich NICHT umgehen laesst:
 * Es gibt keine Browser-Schnittstelle, die Rechte "fuer immer" setzt. Wir
 * koennen nur einmal fragen; ob der Browser sich die Antwort merkt, entscheidet
 * er selbst. Chrome und Edge merken sie sich auf HTTPS automatisch. Firefox
 * fragt jedes Mal erneut, solange der Nutzer im Dialog nicht "Diese
 * Entscheidung merken" ankreuzt – genau das ist die Ursache, wenn beim
 * Aufnehmen von Sprachnachrichten wiederholt gefragt wird. Deshalb blendet die
 * Oberflaeche diesen Hinweis gezielt fuer Firefox ein.
 */

import {
    acquireMicrophoneStream,
    holdMicrophoneStream,
    releaseMicrophoneStream,
} from './microphone-stream';
import { ensureCurrentDevicePushSubscription } from './pwa';

const DEVICE_KIND = { microphone: 'audioinput', camera: 'videoinput' };

/**
 * Status eines Geraets ermitteln – browserübergreifend.
 *
 * Zwei Wege, weil ein einzelner nicht reicht:
 *
 *  1. `permissions.query` — nur Chrome und Edge kennen 'microphone'/'camera'.
 *     Firefox und Safari werfen dort einen Fehler.
 *
 *  2. `enumerateDevices()` — funktioniert ueberall: Solange keine Freigabe
 *     vorliegt, liefert der Browser aus Datenschutzgruenden LEERE Geraetenamen.
 *     Sobald der Nutzer erlaubt hat, stehen die echten Namen darin. Ein
 *     gefuellter Name ist also ein verlaesslicher Beleg fuer die Freigabe.
 *
 * Ohne Weg 2 blieb der Status in Firefox und Safari dauerhaft auf "unbekannt" –
 * die Oberflaeche zeigte weiter "Erlauben", obwohl die Freigabe laengst erteilt
 * war. Genau das sah aus, als wuerde nichts gespeichert.
 */
export async function readDeviceState(kind) {
    if (! navigator.mediaDevices?.getUserMedia) {
        return 'unsupported';
    }

    // Weg 1: eindeutige Auskunft, wo verfuegbar.
    try {
        const status = await navigator.permissions.query({ name: kind });

        if (status.state === 'granted' || status.state === 'denied') {
            return status.state;
        }
    } catch (_) {
        // Firefox/Safari: kein Fehler, nur keine Auskunft – weiter mit Weg 2.
    }

    // Weg 2: Geraetenamen verraten die Freigabe.
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const matching = devices.filter((device) => device.kind === DEVICE_KIND[kind]);

        if (matching.length === 0) {
            return 'unsupported'; // Kein solches Geraet vorhanden.
        }

        return matching.some((device) => device.label !== '') ? 'granted' : 'prompt';
    } catch (_) {
        return 'unknown';
    }
}

/** Liest den Status, ohne eine Abfrage auszuloesen. */
async function readState(name) {
    if (name === 'notifications') {
        if (! ('Notification' in window)) {
            return 'unsupported';
        }

        return window.Notification.permission; // granted | denied | default
    }

    return readDeviceState(name);
}

export default function permissionSetup(config = {}) {
    return {
        open: false,
        busy: null,
        states: { microphone: 'unknown', camera: 'unknown', notifications: 'unknown' },
        // Firefox merkt sich Geraeterechte nur mit gesetztem Haekchen.
        needsRememberHint: /firefox/i.test(navigator.userAgent),
        storageKey: `railtime.permissions.done.${config.userId ?? 'anon'}`,

        async init() {
            await this.refresh();

            // Beim ersten Start automatisch zeigen – aber nur, wenn wirklich
            // noch etwas offen ist. Wer alles erlaubt hat, wird nicht behelligt.
            if (! this.wasDismissed() && this.hasOpenItems) {
                this.open = true;
            }

            window.addEventListener('rt:permissions-open', () => this.openDialog());

            // Nach Rueckkehr in den Tab kann sich der Status geaendert haben
            // (etwa ueber die Browsereinstellungen).
            document.addEventListener('visibilitychange', () => {
                if (! document.hidden) this.refresh();
            });

            // Mikrofon oder Kamera nachtraeglich angesteckt? Dann aendert sich
            // die Geraeteliste – und mit ihr unsere Statusermittlung.
            navigator.mediaDevices?.addEventListener?.('devicechange', () => this.refresh());

            this.watchPermissionChanges();
        },

        /**
         * Aenderungen live mitbekommen, wo der Browser das anbietet.
         *
         * Chrome und Edge melden ueber PermissionStatus.onchange, wenn der
         * Nutzer die Freigabe in den Browsereinstellungen aendert. Firefox und
         * Safari kennen das fuer Geraete nicht – dort greifen visibilitychange
         * und devicechange oben. Fehler werden bewusst verschluckt: Diese
         * Beobachtung ist Komfort, kein Fundament.
         */
        async watchPermissionChanges() {
            for (const kind of ['microphone', 'camera']) {
                try {
                    const status = await navigator.permissions.query({ name: kind });
                    status.addEventListener('change', () => this.refresh());
                } catch (_) {
                    // Nicht unterstuetzt – kein Problem.
                }
            }
        },

        /**
         * Mikrofon und Kamera werden GETRENNT erteilt.
         *
         * Gebuendelt waere bequemer (nur eine Abfrage), aber fachlich falsch:
         * Draussen an der Strecke wird meist ohne Kamera telefoniert. Wer die
         * Kamera in einer gemeinsamen Abfrage ablehnt, verliert sonst auch das
         * Mikrofon – und damit die Telefonie ueberhaupt.
         *
         * Deshalb: Mikrofon ist Voraussetzung, Kamera ausdruecklich optional.
         */
        isReady(state) {
            return state === 'granted' || state === 'unsupported';
        },

        /** Nur das Noetige blockiert den Einstieg – die Kamera nicht. */
        get hasOpenItems() {
            return ! ['granted', 'unsupported'].includes(this.states.microphone)
                || ! ['granted', 'unsupported'].includes(this.states.notifications);
        },

        wasDismissed() {
            try {
                return window.localStorage.getItem(this.storageKey) === '1';
            } catch (_) {
                return false;
            }
        },

        /**
         * Nur zuklappen, NICHT dauerhaft merken.
         *
         * Backdrop-Klick und Escape landen hier: Ein versehentlicher Klick
         * neben den Dialog darf ihn nicht fuer immer unterdruecken. Dauerhaft
         * merkt sich das nur dismiss() – der ausdrueckliche Knopf im Fuss.
         */
        close() {
            this.open = false;
        },

        async refresh() {
            this.states = {
                microphone: await readState('microphone'),
                camera: await readState('camera'),
                notifications: await readState('notifications'),
            };
        },

        openDialog() {
            this.refresh();
            this.open = true;
        },

        dismiss() {
            try {
                window.localStorage.setItem(this.storageKey, '1');
            } catch (_) {
                // Privater Modus: dann eben bei jedem Start erneut anbieten.
            }

            this.open = false;
        },

        /**
         * Mikrofon anfordern.
         *
         * In Firefox wird der Stream danach bewusst kurz OFFEN gehalten
         * (geteilter Halter, gleiche Ruhephase wie bei Sprachnachrichten):
         * Ohne "Diese Entscheidung merken" gilt die Freigabe dort nur fuer
         * diese eine Anforderung – sofortiges Stoppen wuerde sie wertlos
         * machen, und die naechste Sprachnachricht fragte doch wieder.
         * Chrome und Edge merken sich die Antwort dauerhaft; dort wird sofort
         * gestoppt, damit das Aufnahmesymbol nicht grundlos leuchtet.
         */
        async requestMicrophone() {
            if (this.busy) return;
            this.busy = 'microphone';

            try {
                await acquireMicrophoneStream();

                if (this.needsRememberHint) {
                    holdMicrophoneStream();
                } else {
                    releaseMicrophoneStream();
                }
            } catch (error) {
                console.error('[permissions] microphone nicht verfuegbar:', error);
            } finally {
                await this.refresh();
                this.busy = null;
            }
        },

        /**
         * Kamera anfordern. Die erteilte Spur wird sofort wieder gestoppt: Es
         * geht nur um die Freigabe – eine dauerhaft leuchtende Kamera waere
         * das Gegenteil von Vertrauen.
         */
        async requestCamera() {
            if (this.busy) return;
            this.busy = 'camera';

            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                stream.getTracks().forEach((track) => track.stop());
            } catch (error) {
                console.error('[permissions] camera nicht verfuegbar:', error);
            } finally {
                await this.refresh();
                this.busy = null;
            }
        },

        async requestNotifications() {
            if (this.busy || ! ('Notification' in window)) return;
            this.busy = 'notifications';

            try {
                const permission = await window.Notification.requestPermission();

                // Die Berechtigung allein zeigt noch nichts an: Eingehende
                // Anrufe kommen per Web-Push ueber den Service Worker – dafuer
                // braucht dieses Geraet ein Abo beim Server. Ohne diesen
                // Schritt waere "Erteilt" ein leeres Versprechen, sobald kein
                // Tab mehr offen ist.
                if (permission === 'granted' && config.push) {
                    await ensureCurrentDevicePushSubscription(config.push).catch((error) => {
                        // Kein Blocker fuer den Dialog: Die Einstellungsseite
                        // bietet das Abo weiterhin manuell an.
                        console.error('[permissions] Push-Abo nicht angelegt:', error);
                    });
                }
            } catch (error) {
                console.error('[permissions] Benachrichtigungen:', error);
            } finally {
                await this.refresh();
                this.busy = null;
            }
        },

        /** Anzeigeklasse je Zustand. */
        toneFor(state) {
            if (state === 'granted') return 'granted';
            if (state === 'denied') return 'denied';
            if (state === 'unsupported') return 'unsupported';

            return 'open';
        },
    };
}
