<div class="min-w-0 space-y-5 text-rt-text dark:text-rt-dark-text" data-local-excel-import @if($import?->status === 'running') wire:poll.2s.keep-alive="advance" @endif>
    @if(!$ready)
        <p class="text-sm">Der lokale Excel-Import benötigt die Datenbankmigrationen für Excel-Synchronisierung und lokalen Import.</p>
    @else
        <div>
            <h3 class="text-lg font-semibold">Excel-Dateien lokal importieren</h3>
            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Datei auf diesem Gerät auswählen und einmalig in diese App übernehmen. Dropbox und Redis werden dafür nicht benötigt. Die Originaldatei wird nicht verändert.</p>
        </div>
        @if($notice)<p role="status" class="rounded-xl bg-rt-accent-soft/40 p-4 text-sm dark:bg-rt-dark-accent-soft/30">{{ $notice }}</p>@endif
        <x-validation-errors title="" />
        <form wire:submit="inspect" class="space-y-4">
            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                <div><x-ui.forms.label for="local-excel-upload" value="Excel-Datei (.xlsx, maximal 12 MB)" /><input id="local-excel-upload" type="file" wire:model="upload" accept=".xlsx" class="block w-full min-w-0 text-sm" /><p wire:loading wire:target="upload" class="mt-2 text-sm">Datei wird hochgeladen …</p></div>
                <div><x-ui.forms.label for="local-excel-profile" value="Dateityp" /><x-ui.forms.select id="local-excel-profile" wire:model="profile"><option value="auto">Am Dateinamen erkennen</option><option value="weekly">Aufträge / Wochenplanung</option><option value="matrix">Kompetenzmatrix / Mitarbeiterdaten</option></x-ui.forms.select></div>
            </div>
            <x-ui.buttons.button-basic type="submit" mode="secondary" wire:loading.attr="disabled" wire:target="upload,inspect">Datei prüfen</x-ui.buttons.button-basic>
            <p class="text-xs text-rt-muted dark:text-rt-dark-muted">Für den ersten Test die Kompetenzmatrix und danach die Wochenplanung einlesen. Schichten bleiben Entwürfe; die Veröffentlichung erfolgt in der Schichtplanung.</p>
        </form>
        @if(!$rulesReady)
            <p class="rounded-xl border border-rt-border p-4 text-sm dark:border-rt-dark-border">Für Mitarbeiterzuordnungen fehlt noch ein aktives <a class="font-semibold underline" href="{{ route('operations.workspace', ['module' => 'rules']) }}">Regelprofil der Schichtplanung</a>. Dateien können bereits geprüft und Mitarbeiter zugeordnet werden. Einsätze mit Mitarbeitern werden erst nach Prüfung gegen diese Regeln übernommen.</p>
        @endif
        @if($imports->isNotEmpty())
            <div class="flex flex-wrap gap-2" aria-label="Bisherige Importdateien">@foreach($imports as $item)<x-ui.buttons.button-basic type="button" :mode="$importId === $item->id ? 'primary' : 'secondary'" wire:click="selectImport({{ $item->id }})">{{ $item->source->name }}</x-ui.buttons.button-basic>@endforeach</div>
        @endif
        @if($import)
            <section class="space-y-3 rounded-xl bg-rt-surface-muted p-4 dark:bg-rt-dark-surface-muted">
                <h4 class="break-all font-semibold">{{ $import->source->name }}</h4>
                <p class="text-sm">{{ $import->summary['counts']['planning'] ?? 0 }} Planungseinträge · {{ $import->summary['counts']['contacts'] ?? 0 }} Kontakte · {{ $import->summary['counts']['competencies'] ?? 0 }} Kompetenzangaben</p>
                <p class="break-words text-xs">Einsatzwochen: {{ implode(', ', $import->summary['weeks']) ?: 'Keine Wochenplanung in dieser Datei' }}. Mehrfach vorhandene Excel-Zeilen sind keine zusätzlichen Schichten.</p>
                <p class="text-sm font-semibold">{{ match($import->status) { 'running' => 'Import läuft', 'done' => 'Importdurchlauf abgeschlossen', 'paused' => 'Import pausiert', 'failed' => 'Import unterbrochen', default => 'Vorschau – noch nicht übernommen' } }}</p>
                <progress class="w-full" max="{{ $import->summary['rows'] }}" value="{{ $import->processed }}" aria-label="Importfortschritt"></progress>
                <p class="text-sm tabular-nums">{{ $import->processed }} / {{ $import->summary['rows'] }} Einträge geprüft · {{ $bound }} zugeordnete App-Datensätze · {{ $conflicts->total() }} offene Hinweise</p>
                @if($import->error)<p role="alert" class="text-sm">{{ $import->error }}</p>@endif
                @if($import->status === 'running')
                    <p class="text-xs">Diese Seite geöffnet lassen. Nach dem Schließen bleibt der bisherige Fortschritt gespeichert; zum Fortsetzen die Datei hier wieder auswählen.</p>
                    <x-ui.buttons.button-basic type="button" mode="secondary" wire:click="pause" wire:loading.attr="disabled">Import pausieren</x-ui.buttons.button-basic>
                @else
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="start" wire:loading.attr="disabled">{{ $import->status === 'done' ? 'Erneut abgleichen' : (in_array($import->status, ['paused', 'failed']) ? 'Import fortsetzen' : 'In die App importieren') }}</x-ui.buttons.button-basic>
                @endif
                @if($import->summary['issues'])<p class="text-sm">{{ count($import->summary['issues']) }} Auffälligkeit(en) in der Datei. Ungültige Angaben werden nicht stillschweigend korrigiert.</p>@endif
            </section>
            <section class="space-y-4">
                <h4 class="font-semibold">Mitarbeiter und Dienstleister zuordnen</h4>
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ $unresolved }} Bezeichnungen sind noch ungeklärt. Eindeutige Namensvorschläge sind vorausgewählt; mit „Zuordnung speichern“ bestätigen. Ungeklärte Einsätze bleiben als Hinweis erhalten.</p>
                <x-tables.toolbar title="Bezeichnungen durchsuchen" id="local-excel-filters" search-for="local-excel-search" search-label="Bezeichnung suchen">
                    <x-slot:search><x-ui.forms.search id="local-excel-search" wire:model.live.debounce.300ms="search" placeholder="Bezeichnung suchen" /></x-slot:search>
                </x-tables.toolbar>
                <x-tables.table :columns="[['label' => 'Bezeichnung', 'key' => 'alias', 'width' => 'minmax(0,1fr)'], ['label' => 'Zuordnung', 'key' => 'target', 'width' => 'minmax(0,1fr)'], ['label' => 'Aktion', 'key' => 'actions', 'width' => 'auto']]" :items="$identities" :row-context="['employees' => $employees, 'canCreateTestUsers' => $canCreateTestUsers]" table-key="local-identity" label="Zuordnungen" row-view="components.tables.rows.imports.local-identity-row" empty="Keine Bezeichnungen gefunden." />
                <x-tables.pagination :paginator="$identities" label="Personenseiten" />
                @if($canCreateTestUsers)<div class="space-y-2"><x-ui.forms.label for="local-excel-password" value="Passwort für ein neues lokales Testkonto (mindestens 12 Zeichen)" /><x-ui.forms.input id="local-excel-password" type="password" wire:model="testPassword" autocomplete="new-password" /><p class="text-xs text-rt-muted dark:text-rt-dark-muted">Nur bei Auswahl „Neues lokales Testkonto“. Verwendet eine Testadresse @railtime.invalid und versendet keine Einladungsmail. Bestehende Konten und Passwörter bleiben erhalten.</p></div>@endif
            </section>
            <section class="space-y-3">
                <h4 class="font-semibold">Importhinweise</h4>
                <x-tables.table :columns="[['label' => 'Hinweis', 'key' => 'reason', 'width' => 'minmax(0,1fr)'], ['label' => 'Fundstelle', 'key' => 'snapshot', 'width' => '12rem']]" :items="$conflicts" table-key="local-conflict" label="Importhinweise" row-view="components.tables.rows.imports.local-conflict-row" empty="Noch keine offenen Importhinweise." />
                <x-tables.pagination :paginator="$conflicts" label="Hinweisseiten" />
            </section>
        @endif
    @endif
</div>
