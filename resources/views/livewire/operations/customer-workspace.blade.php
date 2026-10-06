<div class="rt-ops ops-stack" data-customer-workspace>
    <header class="ops-toolbar" aria-label="Kundenakte auswählen">
            <div class="min-w-0 w-full sm:min-w-64 sm:w-auto">
                <x-ui.forms.label for="customer-workspace-select" value="Kunde" />
                <x-ui.forms.select id="customer-workspace-select" change="$wire.selectCustomer(Number($event.target.value))" aria-label="Kundenakte auswählen">
                    @forelse($customers as $entry)<option value="{{ $entry->id }}" @selected($entry->id === $customerId)>{{ $entry->company_name }}</option>@empty<option value="">Keine Kunden verfügbar</option>@endforelse
                </x-ui.forms.select>
            </div>
        @if($canCreate)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="createCustomer">Kunde anlegen</x-ui.buttons.button-basic>@endif
    </header>
    @if(count($views)>1)
        <nav class="ops-actions" aria-label="Kundenakte">
            @foreach($views as $key=>$label)<x-ui.buttons.button-basic type="button" :mode="$view===$key?'primary':'link'" wire:click="setView('{{ $key }}')" :aria-current="$view===$key?'page':null">{{ $label }}</x-ui.buttons.button-basic>@endforeach
        </nav>
    @endif
    @if($view==='master')
        <livewire:admin.operations.customers :customer-id="$customerId" :embedded="true" :start-creating="$startCreating" :key="'customer-master-'.($customerId ?? 'new').'-'.$contextRevision" />
    @elseif($customer && in_array($view,['contacts','conditions'],true))
        <livewire:operations.customer-relations :customer-id="$customerId" :section="$view" :embedded="true" :key="'customer-relations-'.$customerId.'-'.$view.'-'.$contextRevision" />
    @elseif($customer && $view==='portal')
        <nav class="ops-actions" aria-label="Portalverwaltung">
            @foreach($sections as $key=>$label)<x-ui.buttons.button-basic type="button" :mode="$section===$key?'primary':'link'" wire:click="setSection('{{ $key }}')" :aria-current="$section===$key?'page':null">{{ $label }}</x-ui.buttons.button-basic>@endforeach
        </nav>
        <livewire:operations.customer-portal-management :customer-id="$customerId" :tab="$section" :embedded="true" :key="'customer-portal-'.$customerId.'-'.$section.'-'.$contextRevision" />
    @else
        <div class="ops-empty">Keine Kundenakte verfügbar.</div>
    @endif
</div>
