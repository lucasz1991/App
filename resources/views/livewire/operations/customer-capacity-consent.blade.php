<section class="rt-ops ops-stack min-w-0" aria-label="Meine Verfügbarkeitsfreigaben">
    <x-ui.section-header title="Verfügbarkeitsfreigaben" size="lg" />
    <x-operations.feedback />
    <x-tables.toolbar title="Verfügbarkeitsfreigaben durchsuchen" id="customer-capacity-filters">
        <x-slot:search><x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Kunde, Einsatzort oder Tätigkeit" /></x-slot:search>
    </x-tables.toolbar>
    <x-tables.table :items="$items" :columns="[['label'=>'Kunde / Tätigkeit','key'=>'service','width'=>'2fr'],['label'=>'Zeitraum','key'=>'period','width'=>'2fr'],['label'=>'Rückmeldung','key'=>'state'],['label'=>'Aktion','key'=>'actions']]" row-view="components.tables.rows.customers.capacity-consent-row" empty="Keine Verfügbarkeitsanfragen." />
    <x-tables.pagination :paginator="$items" />
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
