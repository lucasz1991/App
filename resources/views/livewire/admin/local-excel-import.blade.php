<div class="min-w-0 space-y-5 text-rt-text dark:text-rt-dark-text" data-local-excel-import @if($import?->status === 'running') wire:poll.2s.keep-alive="advance" @endif>
    @if(!$ready)
        <p class="text-sm">Der lokale Excel-Import benötigt die Datenbankmigrationen für Excel-Synchronisierung und lokalen Import.</p>
    @else
        <div>
            <h3 class="text-lg font-semibold">Excel-Dateien lokal importieren</h3>
            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Datei auf diesem Gerät auswählen und einmalig in diese App übernehmen. Dropbox und Redis werden dafür nicht benötigt. Die Originaldatei wird nicht verändert.</p>
        </div>
        @if($notice)<p role="status" class="rounded-xl bg-rt-accent-soft/40 p-4 text-sm dark:bg-rt-dark-accent-soft/30">{{ $notice }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-xl border-l-4 border-rt-accent p-4 text-sm"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
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
                <div><x-ui.forms.label for="local-excel-search" value="Bezeichnung suchen" /><x-ui.forms.input id="local-excel-search" type="search" wire:model.live.debounce.300ms="search" /></div>
                @foreach($identities as $identity)
                    <div wire:key="local-identity-{{ $identity->id }}" class="grid min-w-0 gap-3 border-t border-rt-border/60 py-3 dark:border-rt-dark-border/60 md:grid-cols-3">
                        <div class="min-w-0"><p class="break-words text-sm font-semibold">{{ $identity->details['display_name'] ?? $identity->alias }}</p><p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ match($identity->kind) { 'employee' => 'Mitarbeiterkonto zugeordnet', 'provider' => 'Dienstleister', default => 'Zuordnung offen' } }}</p></div>
                        <x-ui.forms.select wire:model="targets.{{ $identity->id }}" aria-label="Zuordnung für {{ $identity->details['display_name'] ?? $identity->alias }}"><option value="">Bitte auswählen</option><option value="provider">Dienstleister (kein Benutzerkonto)</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach @if($canCreateTestUsers && $identity->kind === 'unresolved')<option value="new">Neues lokales Testkonto</option>@endif</x-ui.forms.select>
                        <div><x-ui.buttons.button-basic type="button" mode="secondary" wire:click="assign({{ $identity->id }})" wire:loading.attr="disabled">Zuordnung speichern</x-ui.buttons.button-basic></div>
                    </div>
                @endforeach
                {{ $identities->links() }}
                @if($canCreateTestUsers)<div class="space-y-2"><x-ui.forms.label for="local-excel-password" value="Passwort für ein neues lokales Testkonto (mindestens 12 Zeichen)" /><x-ui.forms.input id="local-excel-password" type="password" wire:model="testPassword" autocomplete="new-password" /><p class="text-xs text-rt-muted dark:text-rt-dark-muted">Nur bei Auswahl „Neues lokales Testkonto“. Verwendet eine Testadresse @railtime.invalid und versendet keine Einladungsmail. Bestehende Konten und Passwörter bleiben erhalten.</p></div>@endif
            </section>
            <section class="space-y-3">
                <h4 class="font-semibold">Importhinweise</h4>
                @forelse($conflicts as $conflict)
                    <div class="border-t border-rt-border/60 py-3 text-sm dark:border-rt-dark-border/60">
                        <p class="font-semibold">{{ \App\Services\Dropbox\SyncLabels::error($conflict->reason) }}</p>
                        <p class="text-xs">{{ $conflict->snapshot['sheet'] ?? '' }} · {{ $conflict->snapshot['slot'] ?? $conflict->snapshot['row'] ?? '' }}</p>
                        @foreach($conflict->snapshot['messages'] ?? [] as $messages)@foreach((array)$messages as $message)<p class="mt-1">{{ $message }}</p>@endforeach@endforeach
                        @if($conflict->reason === 'field_conflict')<p class="mt-1">App und Datei wurden unterschiedlich geändert. Die App-Werte bleiben erhalten. Werte in der Originaldatei oder App angleichen und die Datei erneut prüfen/importieren.</p>@endif
                    </div>
                @empty<p class="text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine offenen Importhinweise.</p>@endforelse
                {{ $conflicts->links() }}
            </section>
        @endif
    @endif
</div>
