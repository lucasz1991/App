/*
 * Seitenweiter Mikrofon-Halter.
 *
 * Warum ein Modul statt Komponenten-Zustand? Die Chat-Komponente (chatRealtime)
 * wird bei JEDEM Chatwechsel zerstoert und neu erzeugt. Lag der Mikrofon-Stream
 * in der Komponente, wurde er beim Wechsel gestoppt – und Firefox fragte im
 * naechsten Chat erneut nach der Freigabe, solange der Nutzer im Abfragefenster
 * nicht "Diese Entscheidung merken" angekreuzt hat. Genau das war die
 * urspruengliche Beschwerde. Hier lebt der Stream deshalb auf Seiten-Ebene und
 * ueberlebt Komponentenwechsel.
 *
 * Lebenszyklus: acquire() liefert einen offenen Stream (vorhandenen
 * wiederverwenden, sonst getUserMedia). hold() gibt ihn nach einer Ruhephase
 * frei – kurz genug, dass das Aufnahmesymbol des Browsers nicht dauerhaft
 * leuchtet, lang genug fuer mehrere Sprachnachrichten hintereinander, auch
 * ueber einen Chatwechsel hinweg. release() stoppt sofort (Fehlerfaelle).
 */

const DEFAULT_HOLD_MS = 90000;

let stream = null;
let acquirePromise = null;
let releaseTimer = null;
let pagehideBound = false;

function hasLiveTrack(candidate) {
    return Boolean(candidate?.getAudioTracks().some((track) => track.readyState === 'live'));
}

/** Gibt es gerade einen nutzbaren Stream (ohne einen anzufordern)? */
export function hasLiveMicrophoneStream() {
    return hasLiveTrack(stream);
}

/**
 * Offenen Mikrofon-Stream liefern – ohne erneute Browser-Abfrage, solange der
 * vorige noch lebt. Ein gleichzeitiger zweiter Aufruf teilt sich dieselbe
 * Anforderung statt zwei Abfragen auszuloesen.
 */
export async function acquireMicrophoneStream() {
    window.clearTimeout(releaseTimer);
    releaseTimer = null;

    if (hasLiveTrack(stream)) {
        return stream;
    }

    if (! acquirePromise) {
        acquirePromise = navigator.mediaDevices.getUserMedia({ audio: true })
            .then((granted) => {
                stream = granted;

                // Beim Verlassen der Seite darf kein Mikrofon offen bleiben –
                // auch nicht in den Vor-/Zurueck-Cache des Browsers hinein.
                if (! pagehideBound) {
                    pagehideBound = true;
                    window.addEventListener('pagehide', () => releaseMicrophoneStream());
                }

                return granted;
            })
            .finally(() => {
                acquirePromise = null;
            });
    }

    return acquirePromise;
}

/**
 * Freigabe nach Ruhephase einplanen statt sofort zu stoppen.
 *
 * Firefox fragt ohne "Diese Entscheidung merken" bei jedem neuen getUserMedia
 * erneut. Bleibt der Stream kurz offen, entfaellt die Nachfrage fuer alles,
 * was innerhalb der Ruhephase folgt – die naechste Sprachnachricht ebenso wie
 * der Wechsel in einen anderen Chat.
 */
export function holdMicrophoneStream(delay = DEFAULT_HOLD_MS) {
    window.clearTimeout(releaseTimer);

    if (! hasLiveTrack(stream)) {
        return;
    }

    releaseTimer = window.setTimeout(() => releaseMicrophoneStream(), delay);
}

/** Mikrofon SOFORT freigeben (Fehler, Seitenverlassen). */
export function releaseMicrophoneStream() {
    window.clearTimeout(releaseTimer);
    releaseTimer = null;
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}
