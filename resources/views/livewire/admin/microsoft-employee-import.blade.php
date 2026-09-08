@php
    $result = $importStatus['last_result'] ?? [];
    $statusLabels = [
        'preview' => 'Vorschau abgeschlossen – keine Mitarbeiter angelegt.',
        'success' => 'Benutzerimport abgeschlossen.',
        'partial' => 'Abgeschlossen mit übersprungenen Einträgen oder Zuordnungskonflikten.',
        'disabled' => 'Der Benutzerimport-Pilot ist deaktiviert.',
        'missing_configuration' => 'Die gespeicherte Microsoft-Verbindung ist noch nicht vollständig.',
        'invalid_configuration' => 'Prüfen Sie die gespeicherte Pilot-Auswahl.',
        'stale_configuration' => 'Die Konfiguration hat sich geändert. Bitte erneut prüfen.',
        'forbidden' => 'Das Microsoft-Graph-Recht User.Read.All oder die Adminzustimmung fehlt.',
        'unauthorized' => 'Microsoft hat die Anmeldung abgelehnt.',
        'unreachable' => 'Microsoft war innerhalb des begrenzten Zeitfensters nicht erreichbar.',
        'invalid_response' => 'Microsoft hat keine vollständig verwertbare Antwort geliefert.',
        'rate_limited' => 'Microsoft begrenzt die Abrufe. Bitte später erneut versuchen.',
        'request_limit' => 'Das kurze Anfragebudget wurde erreicht. Kein Mitarbeiter wurde importiert; verkleinern Sie die Pilot-Auswahl.',
        'response_limit' => 'Die Microsoft-Antwort war zu groß. Kein Mitarbeiter wurde importiert.',
        'http_error' => 'Der Microsoft-Abruf ist fehlgeschlagen.',
        'failed' => 'Der Benutzerimport konnte nicht abgeschlossen werden.',
    ];
@endphp

<section class="mt-5 space-y-4 rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" data-microsoft-employee-import>
    <div>
        <h3 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Entra-Mitarbeiter · begrenzter Import-Pilot</h3>
        <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Ausgewählte Entra-Benutzer als inaktive Mitarbeiter vormerken: ohne Einladung, Anmeldung, Teamzuweisung oder zusätzliche Rechte. Bestehende Mitarbeiter werden nicht automatisch verändert oder per gleicher E-Mail verknüpft.</p>
        <p class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Verwendet die oben gespeicherte Microsoft-Verbindung und benötigt zusätzlich <code>User.Read.All</code> mit Adminzustimmung. Dieses Graph-Recht ist mandantenweit; die folgende RailTime-Auswahl begrenzt den Pilot-Abruf auf höchstens 20 Objekt-IDs.</p>
    </div>

    <form wire:submit="save" class="space-y-3">
        <label class="flex min-h-11 items-center gap-3 text-sm font-medium text-rt-text dark:text-rt-dark-text">
            <input type="checkbox" wire:model="enabled" class="h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface" />
            Manuellen Benutzerimport-Pilot erlauben
        </label>
        <x-input-error for="enabled" />
        <div>
            <x-ui.forms.label for="entra-employee-pilot-ids" value="Freigegebene Entra-Benutzer-Objekt-IDs" />
            <textarea id="entra-employee-pilot-ids" wire:model="pilotObjectIds" rows="3" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="entra-employee-pilot-hint" class="mt-1.5 w-full rounded-xl border border-rt-border bg-rt-surface px-3 py-2 font-mono text-sm text-rt-text focus:border-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text"></textarea>
            <p id="entra-employee-pilot-hint" class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Eine Benutzer-Objekt-ID pro Zeile, keine Client-ID und keine Kennwörter. Nur ausdrücklich ausgewählte Mitarbeiterkonten verwenden. Gastkonten und deaktivierte Entra-Konten werden nicht neu angelegt.</p>
            <x-input-error for="pilotObjectIds" />
        </div>
        <x-ui.buttons.button-basic mode="secondary" type="submit" class="min-h-11" wire:loading.attr="disabled">Pilot-Auswahl speichern</x-ui.buttons.button-basic>
    </form>

    <div class="space-y-3 border-t border-rt-border/70 pt-3 dark:border-rt-dark-border/70">
        <label class="flex items-start gap-3 text-sm leading-6 text-rt-text dark:text-rt-dark-text">
            <input type="checkbox" wire:model="confirmInactiveImport" class="mt-1 h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface" />
            Ich bestätige die Anlage ausschließlich inaktiver Mitarbeiter aus der gespeicherten Pilot-Auswahl.
        </label>
        <div class="flex flex-wrap gap-2">
            <x-ui.buttons.button-basic type="button" mode="secondary" class="min-h-11" wire:click="preview" wire:loading.attr="disabled">Vorschau prüfen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="button" mode="primary" class="min-h-11" wire:click="importInactive" wire:loading.attr="disabled">Inaktiv importieren</x-ui.buttons.button-basic>
        </div>
        <x-input-error for="operation" />
        <p wire:loading class="text-sm text-rt-muted dark:text-rt-dark-muted">Der begrenzte Pilot-Abruf läuft …</p>
        <div aria-live="polite" class="text-sm text-rt-text dark:text-rt-dark-text">
            @if ($result !== [])
                <p class="font-medium">{{ $statusLabels[$result['status'] ?? ''] ?? 'Kein abgeschlossener Importnachweis vorhanden.' }}</p>
                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach (['would_create' => 'Vorgemerkt (Vorschau)', 'created' => 'Neu und inaktiv', 'existing' => 'Bereits verknüpft', 'conflicts' => 'Konflikte'] as $key => $label)
                        <div><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums">{{ (int) ($result[$key] ?? 0) }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">Auswahl: {{ (int) ($result['requested'] ?? 0) }} · Übersprungen: {{ (int) ($result['skipped'] ?? 0) }}</p>
            @else
                <p class="text-rt-muted dark:text-rt-dark-muted">Noch kein Ergebnis für diese gespeicherte Konfiguration.</p>
            @endif
        </div>
    </div>

    <p class="rounded-lg bg-rt-surface-muted p-3 text-xs leading-5 text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">Dieser Pilot ist noch kein automatischer Import aller neuen Entra-Benutzer. Aktivierung und verifizierte Identität, Gerätezuordnung sowie Clientinstallation bleiben eigene Schritte. Eine Microsoft-365-Anmeldung installiert keinen Fernwartungsagenten.</p>
</section>
