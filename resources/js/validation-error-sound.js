// ---------------------------------------------------------------
// Fehlerton bei Validierungsfehlern: Livewire fuehrt den Fehler-Bag im
// Snapshot-memo mit. Nach jedem Commit mit echtem Action-Aufruf (Button/
// Submit, kein reines wire:model-Sync und kein Event-Dispatch) wird der
// Fehler-Bag mit dem Stand vor dem Request verglichen. Der Ton spielt nur,
// wenn ein Fehler-Key hinzukommt oder sich seine Messages aendern — nicht,
// wenn alte Fehler unveraendert weitergereicht werden (z.B. Modal schliessen
// nach fehlgeschlagenem Save) oder der Bag nur schrumpft (partielles
// resetValidation).
// Bekannte Grenze: Die Signatur unterscheidet nicht zwischen "Fehler
// unveraendert weitergereicht" und "identische Fehler neu erhoben" — ein
// unveraendert wiederholter fehlschlagender Submit bleibt daher lautlos,
// solange der persistierte Fehler-Bag der neuen Signatur gleicht. Bewusst
// akzeptiert: Die Inline-Fehler bleiben sichtbar, der erste Fehlschlag war
// hoerbar, und jede Teil-Korrektur mit anderem Fehlerbild toent wieder.
// ---------------------------------------------------------------
function rtErrorBag(snapshot) {
    try {
        const parsed = typeof snapshot === 'string' ? JSON.parse(snapshot) : snapshot;
        const errors = parsed?.memo?.errors;

        if (!errors || typeof errors !== 'object') {
            return {};
        }

        const bag = {};
        Object.keys(errors).forEach((key) => {
            bag[key] = JSON.stringify(errors[key]);
        });

        return bag;
    } catch (_) {
        return {};
    }
}

function installValidationErrorSound(Livewire) {
    Livewire.hook('commit', ({ component, commit, succeed }) => {
        const hasUserCall = (commit?.calls || []).some(
            (call) => call?.method && call.method !== '__dispatch' && call.method !== '$refresh',
        );

        if (!hasUserCall) {
            return;
        }

        const previousErrors = rtErrorBag(component?.snapshot ?? component?.snapshotEncoded);

        succeed(({ snapshot }) => {
            const nextErrors = rtErrorBag(snapshot);
            const grew = Object.keys(nextErrors).some(
                (key) => previousErrors[key] === undefined || previousErrors[key] !== nextErrors[key],
            );

            if (grew) {
                window.RTSound?.play('error');
            }
        });
    });
}

export { installValidationErrorSound };
