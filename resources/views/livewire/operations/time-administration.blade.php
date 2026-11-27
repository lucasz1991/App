<div class="min-w-0 space-y-4">
    <x-operations.feedback />
    @include('livewire.operations.partials.enhancement-record-tabs')
    @if(!$ready)
        <p class="ops-muted">Arbeitsbereich nicht verfügbar.</p>
    @else
        <x-tables.toolbar :title="$this::TABS[$tab]" id="operations-enhancement-filters">
            <x-slot:search>
                @if($tab === 'payroll')
                    <x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter oder Monat (JJJJ-MM)" />
                @endif
            </x-slot:search>
            <x-slot:bulk>@if(!$payrollExportOnly)<x-ui.buttons.button-basic mode="primary" type="button" wire:click="create">{{ $tab === 'terminal' ? 'Zugang einrichten' : 'Neu' }}</x-ui.buttons.button-basic>@endif</x-slot:bulk>
        </x-tables.toolbar>
        @if($tab === 'terminal')
            <ul class="divide-y divide-rt-border dark:divide-rt-dark-border" aria-label="{{ $personal ? 'Mein Terminalzugang' : 'Terminalzugänge der Mitarbeitenden' }}">
                @forelse($records as $profile)
                    <li wire:key="terminal-access-{{ $profile->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $personal ? 'Mein Terminalzugang' : ($profile->user?->name ?? 'Mitarbeiter #'.$profile->user_id) }}</p>
                            <p class="break-all text-sm text-rt-muted dark:text-rt-dark-muted">Terminal-ID · {{ $profile->terminal_id }}</p>
                            <p class="text-sm">{{ $profile->revoked_at ? 'Zugang widerrufen' : 'Zugang aktiv' }} · Standortprüfung {{ $profile->location_consent ? 'freigegeben' : 'aus' }}</p>
                        </div>
                        <x-ui.buttons.button-basic type="button" wire:click="openDetails({{ $profile->id }})" wire:loading.attr="disabled">Details</x-ui.buttons.button-basic>
                    </li>
                @empty
                    <li class="py-4 text-sm text-rt-muted dark:text-rt-dark-muted">Noch kein Terminalzugang eingerichtet.</li>
                @endforelse
            </ul>
        @else
            <x-tables.table :columns="[['label'=>'Vorgang','key'=>'title'],['label'=>'Mitarbeiter','key'=>'user_id'],['label'=>'Status','key'=>'status'],['label'=>'Revision','key'=>'revision'],['label'=>'Aktion','key'=>'action']]" :items="$records" row-view="components.tables.rows.time.enhancement-row" empty="Keine Vorgänge in dieser Ansicht." />
        @endif
        @if($records instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<x-tables.pagination :paginator="$records" />@endif
        <x-operations.modal wire:model="formOpen" :title="$tab === 'terminal' ? ($personal ? 'Eigenen Terminalzugang einrichten' : 'Terminalzugang für Mitarbeiter einrichten') : $this::TABS[$tab]" max-width="4xl">
            @if($formOpen)
            <form wire:submit="save" class="ops-form">
                @if(in_array($tab,['payroll','rules']) || ($tab==='terminal' && !$personal))
                    <x-operations.field label="Mitarbeiter" model="form.user_id" type="select" :required="$tab !== 'rules'"><option value="">{{ $tab === 'rules' ? 'Alle Mitarbeiter' : 'Auswählen' }}</option>@foreach($staff as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                @endif
                @if($tab === 'rules')
                    @include('livewire.operations.partials.time-administration.form-rule')
                @elseif($tab === 'payroll')
                    <x-operations.field label="Monat auswählen" model="form.month_date" type="month" required />
                    <p class="ops-muted ops-full">Die Prüfung umfasst den gesamten Abrechnungsmonat dieser Person.</p>
                @elseif($tab === 'terminal')
                    <x-operations.field label="Terminal-ID" model="form.terminal_id" required autocomplete="off" autocapitalize="none" spellcheck="false" />
                    <x-operations.field label="PIN (6–10 Ziffern)" model="form.pin" type="password" inputmode="numeric" pattern="[0-9]{6,10}" maxlength="10" required autocomplete="new-password" />
                    @if($personal)
                        <div class="ops-full">
                            <x-ui.forms.checkbox wire:model="form.location_consent" label="Standortprüfung für diesen Zugang freigeben" />
                        </div>
                        <div x-show.important="$wire.form.location_consent" x-cloak class="ops-full grid gap-4 sm:grid-cols-2">
                            <div class="ops-full"><x-ui.forms.label for="terminal-place-search" value="Terminalstandort suchen" />
                                <x-ui.forms.place-field id="terminal-place-search" model="terminalReference" :coordinates-only="true" class="rt-ui-control w-full" />
                            </div>
                            <x-operations.field label="Breitengrad" model="form.latitude" type="number" min="-90" max="90" step="0.0000001" x-bind:required="$wire.form.location_consent" />
                            <x-operations.field label="Längengrad" model="form.longitude" type="number" min="-180" max="180" step="0.0000001" x-bind:required="$wire.form.location_consent" />
                            <x-operations.field label="Radius in Metern" model="form.radius_metres" type="number" min="30" max="10000" x-bind:required="$wire.form.location_consent" />
                        </div>
                    @endif
                @endif
                <div class="ops-full ops-actions"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">{{ $tab === 'payroll' ? 'Monat prüfen' : 'Speichern' }}</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Abbrechen</x-ui.buttons.button-basic></div>
            </form>
            @endif
        </x-operations.modal>
        <x-operations.modal wire:model="detailOpen" :title="$this::TABS[$tab]" max-width="4xl" variant="modal">
            @if($selected)
                @unless($tab === 'terminal')<header class="ops-toolbar"><h2>{{ $selected->title ?? $selected->name ?? $selected->month ?? 'Terminalzugang' }}</h2><span class="ops-muted">Revision {{ $selectedRevision }}</span></header>@endunless
                @if($tab === 'payroll')
                    @include('livewire.operations.partials.time-administration.detail-payroll')
                @elseif($tab === 'rules')
                    @include('livewire.operations.partials.time-administration.detail-rule')
                @elseif($tab === 'terminal')
                    <dl class="ops-meta">
                        @unless($personal)<div><dt>Mitarbeiter</dt><dd>{{ $selected->user?->name ?? 'Mitarbeiter #'.$selected->user_id }}</dd></div>@endunless
                        <div><dt>Terminal-ID</dt><dd class="break-all">{{ $selected->terminal_id }}</dd></div>
                        <div><dt>Standortprüfung</dt><dd>{{ $selected->location_consent ? 'Freigegeben' : 'Aus' }}</dd></div>
                    </dl>
                    <div class="ops-actions"><x-ui.buttons.button-basic type="button" wire:click="$set('detailOpen', false)">Schließen</x-ui.buttons.button-basic></div>
                @endif
            @endif
        </x-operations.modal>
    @endif
</div>
