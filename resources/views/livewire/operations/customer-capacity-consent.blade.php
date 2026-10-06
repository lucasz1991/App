<section class="rt-ops ops-stack min-w-0" aria-label="Meine Verfügbarkeitsfreigaben">
    <header class="ops-toolbar"><h2 class="text-lg font-semibold">Verfügbarkeitsfreigaben</h2></header>
    <x-operations.feedback />
    <x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Kunde, Einsatzort oder Tätigkeit" />
    <x-tables.table :items="$items" :columns="[['label'=>'Kunde / Tätigkeit','key'=>'service','width'=>'2fr'],['label'=>'Zeitraum','key'=>'period','width'=>'2fr'],['label'=>'Rückmeldung','key'=>'state'],['label'=>'Aktion','key'=>'actions']]" row-view="components.tables.rows.operations.customer-capacity" empty="Keine Verfügbarkeitsanfragen." />
    {{ $items->links() }}
    <x-operations.modal wire:model="formOpen" title="Verfügbarkeit bestätigen" max-width="2xl">
        @if($formOpen && $selected)
            <div class="ops-stack">
                <div><strong class="block break-words">{{ $selected->customer }} · {{ $selected->title }}</strong><span class="ops-muted block break-words">{{ $selected->location }}</span></div>
                <div class="tabular-nums">{{ $selected->starts_at->setTimezone($selected->timezone)->format('d.m.Y H:i') }} – {{ $selected->ends_at->setTimezone($selected->timezone)->format('d.m.Y H:i') }}<span class="ops-muted block text-xs">{{ $selected->timezone }} · {{ $selected->planned_break_minutes }} Min. Pause</span></div>
                <x-ui.forms.checkbox wire:model="confirmed" label="Zeitraum geprüft" />
                @error('confirmed')<p class="ops-error" role="alert">{{ $message }}</p>@enderror
                <div class="ops-actions"><x-ui.buttons.button-basic type="button" mode="primary" wire:click="respond(true)" wire:loading.attr="disabled">Verfügbar</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="respond(false)" wire:loading.attr="disabled">Nicht verfügbar</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" mode="link" wire:click="close">Abbrechen</x-ui.buttons.button-basic></div>
            </div>
        @endif
    </x-operations.modal>
</section>
