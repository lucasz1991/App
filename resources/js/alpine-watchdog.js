// ---------------------------------------------------------------
// Alpine-Watchdog: Sicherheitsnetz gegen einen abgestuerzten oder nie
// gestarteten Alpine-Stack.
//
// Warum das noetig ist: Alpine kommt aus Livewires Bundle und wird erst durch
// Livewire.start() ganz am Ende von app.js initialisiert. Wirft irgendeine
// Anweisung davor (klassischer Fall: eine doppelt registrierte Alpine-Plugin-
// Erweiterung -> "Cannot redefine property"), bricht die Modulauswertung ab.
// Dann laeuft Livewire.start() nie, kein x-data reagiert — und weil app.css
// [x-cloak]{display:none!important} setzt, bleiben Topbar-Schalter, Dropdowns
// und alle eigenen Selects dauerhaft unsichtbar. Die Seite wirkt "halb kaputt",
// ohne dass der Nutzer etwas dagegen tun kann.
//
// Ablauf (bewusst konservativ):
//   1. Ausloeser ist ausschliesslich ein echter JavaScript-Fehler
//      (window 'error' mit Error-Objekt oder 'unhandledrejection').
//   2. Erst dann wird geprueft, ob Alpine wirklich erreichbar/initialisiert
//      ist. Ist alles gesund, passiert nichts.
//   3. Nur bei defektem Alpine wird die Seite neu geladen — hoechstens einmal
//      pro Zeitfenster, gesichert ueber sessionStorage, damit niemals eine
//      Reload-Schleife entstehen kann.
//
// Dieses Modul wird in app.js ALS ERSTES importiert, damit die Listener stehen,
// bevor ein anderes Modul ausgewertet werden kann.
// ---------------------------------------------------------------

const ATTEMPT_KEY = 'rt-alpine-recovery';
// Hoechstens ein automatischer Reload pro Zeitfenster. Hilft der Reload nicht,
// bleibt die Seite defekt stehen — besser als eine Endlosschleife.
const MAX_ATTEMPTS = 1;
const ATTEMPT_WINDOW_MS = 60000;
// Absolute Obergrenze fuer die Sitzung. Ohne sie wuerde ein dauerhaft defektes
// Bundle (404, CSP-Block, Adblocker) die Seite endlos neu laden — nur eben
// gebremst auf einen Reload pro Zeitfenster. Ein gesunder Seitenaufbau setzt
// den Zaehler wieder zurueck (siehe markHealthy).
const MAX_ATTEMPTS_TOTAL = 2;
// Alpine braucht nach dem Laden einen Moment. Vor Ablauf dieser Zeit gilt ein
// fehlendes Alpine nicht als Absturz.
const GRACE_MS = 2500;
// Zwei Messungen mit Abstand: verhindert Fehlalarm, wenn Livewire gerade
// frisches HTML eingehaengt hat und Alpine es noch nicht verarbeitet hat.
const FIRST_CHECK_MS = 400;
const SECOND_CHECK_MS = 900;

const bootedAt = Date.now();
let scheduled = false;
let reloading = false;

function readAttempts() {
    try {
        const raw = window.sessionStorage.getItem(ATTEMPT_KEY);
        if (!raw) {
            return { count: 0, at: 0, total: 0 };
        }
        const parsed = JSON.parse(raw);

        return {
            count: Number(parsed.count) || 0,
            at: Number(parsed.at) || 0,
            total: Number(parsed.total) || 0,
        };
    } catch (_) {
        // Private-Mode/blockierter Storage: dann lieber gar nicht neu laden.
        return { count: MAX_ATTEMPTS, at: Date.now(), total: MAX_ATTEMPTS_TOTAL };
    }
}

function writeAttempts(state) {
    try {
        window.sessionStorage.setItem(ATTEMPT_KEY, JSON.stringify(state));
    } catch (_) {
        // Ohne Storage kein Zaehler — reloadAllowed() blockt dann bereits.
    }
}

function clearAttempts() {
    try {
        window.sessionStorage.removeItem(ATTEMPT_KEY);
    } catch (_) {
        // Nicht kritisch.
    }
}

function reloadAllowed() {
    const { count, at, total } = readAttempts();

    // Harte Obergrenze zuerst: sie gilt auch dann, wenn das Zeitfenster
    // laengst abgelaufen ist. Nur so kann ein dauerhaft defektes Bundle keine
    // (langsame) Endlos-Reloadschleife erzeugen.
    if (total >= MAX_ATTEMPTS_TOTAL) {
        return false;
    }

    // Liegt der letzte Versuch lange zurueck, beginnt ein neues Zeitfenster.
    if (at && (Date.now() - at) > ATTEMPT_WINDOW_MS) {
        return true;
    }

    return count < MAX_ATTEMPTS;
}

/**
 * Ist Alpine erreichbar UND hat es den DOM tatsaechlich verarbeitet?
 */
function alpineIsHealthy() {
    const alpine = window.Alpine;

    if (!alpine || typeof alpine.start !== 'function' || typeof alpine.store !== 'function') {
        return false;
    }

    // Nach der Initialisierung entfernt Alpine jedes x-cloak-Attribut. Bleibt
    // eines stehen, hat Alpine diesen Teil des DOM nicht angefasst.
    if (document.querySelector('[x-cloak]')) {
        return false;
    }

    // Stichprobe an einer echten Komponentenwurzel: Alpine haengt beim
    // Initialisieren einen _x_dataStack an jedes x-data-Element.
    //
    // NICHT ueber Alpine.$data() pruefen: das liefert intern
    // mergeProxies(...) -> new Proxy(...) und ist damit IMMER truthy, also
    // voellig unabhaengig davon, ob Alpine laeuft (verifiziert in
    // vendor/livewire/livewire/dist/livewire.esm.js, Alpine 3.15.12).
    const probe = document.querySelector('[x-data]');

    if (!probe) {
        return true;
    }

    try {
        return Array.isArray(probe._x_dataStack) && probe._x_dataStack.length > 0;
    } catch (_) {
        return false;
    }
}

/**
 * Reload nur, wenn er dem Nutzer nichts wegnimmt.
 */
function reloadIsSafe() {
    if (navigator.onLine === false) {
        return false;
    }

    // Nicht mitten in einer Eingabe neu laden — das wuerde Getipptes verwerfen.
    const active = document.activeElement;

    if (active && (active.isContentEditable
        || ((active.tagName === 'INPUT' || active.tagName === 'TEXTAREA') && String(active.value || '') !== ''))) {
        return false;
    }

    return true;
}

function recover() {
    if (reloading || !reloadAllowed() || !reloadIsSafe()) {
        return;
    }

    reloading = true;

    const { count, at, total } = readAttempts();
    const windowExpired = at && (Date.now() - at) > ATTEMPT_WINDOW_MS;

    writeAttempts({
        count: windowExpired ? 1 : count + 1,
        at: Date.now(),
        // total laeuft ueber alle Zeitfenster hinweg weiter und wird nur von
        // einem gesunden Seitenaufbau zurueckgesetzt.
        total: total + 1,
    });

    // Ein haengendes Seitenwechsel-Overlay wuerde den Reload ueberdauern.
    try {
        window.RTNavOverlay?.hide();
    } catch (_) {
        // Overlay ist optional.
    }

    console.warn('[RailTime] Alpine ist nicht erreichbar — die Seite wird einmal neu geladen.');
    window.location.reload();
}

/**
 * Zweistufige Pruefung, damit ein kurzzeitiger Zwischenzustand keinen
 * Reload ausloest.
 */
function inspectAfterError() {
    if (scheduled || reloading) {
        return;
    }

    // Innerhalb der Anlaufzeit ist ein noch fehlendes Alpine normal.
    if ((Date.now() - bootedAt) < GRACE_MS) {
        window.setTimeout(inspectAfterError, GRACE_MS);

        return;
    }

    scheduled = true;

    window.setTimeout(() => {
        if (alpineIsHealthy()) {
            scheduled = false;

            return;
        }

        window.setTimeout(() => {
            scheduled = false;

            if (!alpineIsHealthy()) {
                recover();
            }
        }, SECOND_CHECK_MS);
    }, FIRST_CHECK_MS);
}

export function installAlpineWatchdog() {
    window.addEventListener('error', (event) => {
        // Fehlgeschlagene Ressourcen (Bild, Stylesheet, Script-Tag) feuern
        // ebenfalls 'error', tragen aber kein Error-Objekt. Sie sind kein
        // JavaScript-Fehler und deshalb kein Ausloeser.
        if (event.target && event.target !== window) {
            return;
        }

        inspectAfterError();
    }, true);

    window.addEventListener('unhandledrejection', inspectAfterError);

    // Lief die Seite nach dem Start sauber durch, bekommt der naechste echte
    // Vorfall wieder das vollstaendige Reload-Budget.
    const markHealthy = () => {
        if (alpineIsHealthy()) {
            clearAttempts();
        }
    };

    window.setTimeout(markHealthy, GRACE_MS + 1500);
    document.addEventListener('livewire:navigated', () => window.setTimeout(markHealthy, 500));
}

// Direkt beim Import ausfuehren: ES-Imports werden gehoistet, ein Aufruf in
// app.js liefe erst NACH der Auswertung aller dortigen Imports. Nur so stehen
// die Listener wirklich zuerst.
installAlpineWatchdog();
