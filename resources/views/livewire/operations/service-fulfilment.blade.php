<div class="min-w-0 space-y-4">
    <x-operations.feedback />
    @include('livewire.operations.partials.enhancement-record-tabs')
    @if(!$ready)
        <p class="ops-muted">Arbeitsbereich nicht verfügbar.</p>
    @else
        @unless($modalOnly)
        <x-tables.toolbar :title="$this::TABS[$tab]" id="operations-enhancement-filters">
            <x-slot:search>
                @if(in_array($tab, ['proofs','travel','partners','imports']))
                    <x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Vorgang suchen" />
                @endif
            </x-slot:search>
            <x-slot:bulk>@if(!($personal && $tab === 'partners') && !(!$personal && in_array($tab,['proofs','travel'])))<x-ui.buttons.button-basic mode="primary" type="button" wire:click="create">Neu</x-ui.buttons.button-basic>@endif</x-slot:bulk>
        </x-tables.toolbar>
        <x-tables.table :columns="[['label'=>'Vorgang','key'=>'title'],['label'=>'Mitarbeiter','key'=>'user_id'],['label'=>'Status','key'=>'status'],['label'=>'Revision','key'=>'revision'],['label'=>'Aktion','key'=>'action']]" :items="$records" row-view="components.tables.rows.time.enhancement-row" empty="Keine Vorgänge in dieser Ansicht." />
        @if($records instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<x-tables.pagination :paginator="$records" />@endif
        @if($tab === 'costs')
            @include('livewire.operations.partials.service-fulfilment.cost-calculation')
        @endif
        @endunless
        <x-operations.modal wire:model="formOpen" :title="$this::TABS[$tab]" max-width="4xl">
            @if($formOpen)
            <form wire:submit="save" class="ops-form">
                @include('livewire.operations.partials.service-fulfilment.form-fields')
                <div class="ops-full ops-actions"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">{{ $tab === 'imports' ? 'Vorschau erstellen' : 'Speichern' }}</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Abbrechen</x-ui.buttons.button-basic></div>
            </form>
            @endif
        </x-operations.modal>
        <x-operations.modal wire:model="detailOpen" :title="$this::TABS[$tab]" max-width="4xl" :variant="$modalOnly ? 'drawer' : 'modal'">
            @if($selected)
                <header class="ops-toolbar"><h2>{{ $selected->title ?? $selected->name ?? $selected->month ?? 'Terminalzugang' }}</h2><span class="ops-muted">Revision {{ $selectedRevision }}</span></header>
                @if($selected instanceof \App\Models\OperationWorkflow)
                    <x-operations.status :value="$selected->status" />
                    @if($tab === 'proofs')
                        @include('livewire.operations.partials.service-fulfilment.detail-proof')
                    @elseif($tab === 'travel')
                        @include('livewire.operations.partials.service-fulfilment.detail-travel')
                    @elseif($tab === 'partners')
                        @include('livewire.operations.partials.service-fulfilment.detail-partner')
                    @elseif($tab === 'imports')
                        @include('livewire.operations.partials.service-fulfilment.detail-import')
                    @endif
                    @if(($tab === 'proofs' && $selected->status === 'draft' && $selected->user_id === auth()->id()) || ($tab === 'travel' && $selected->status === 'booked' && $selected->user_id === auth()->id()))<form wire:submit="uploadFile" class="ops-form"><x-operations.field label="Beleg (PDF/JPG/PNG)" model="upload" type="file" accept="application/pdf,image/jpeg,image/png" /><div class="self-end"><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled">Beleg hinzufügen</x-ui.buttons.button-basic></div></form>@endif
                @elseif($tab === 'costs')<dl class="ops-meta"><div><dt>Stundenkosten</dt><dd>{{ number_format($selected->hourly_cents/100,2,',','.') }} €</dd></div><div><dt>Gültig ab</dt><dd>{{ $selected->starts_on->format('d.m.Y') }}</dd></div></dl>
                @endif
            @endif
        </x-operations.modal>
    @endif
</div>
