<x-ui.dropdown.anchor-dropdown width="80" dropdown-id="customer-list-filters"
    content-role="dialog" content-label="Kunden filtern" layer-group="topbar-controls"
    content-classes="customer-directory__filter-panel p-4 bg-rt-surface dark:bg-rt-dark-surface">
    <x-slot:trigger>
        <x-ui.buttons.button-basic mode="secondary" type="button" class="customer-directory__filter-trigger" aria-label="Kunden filtern" title="Kunden filtern">
            <i class="far fa-sliders-h" aria-hidden="true"></i>
            @if($activeFilterCount)<span class="customer-directory__filter-count">{{ $activeFilterCount }}</span>@endif
        </x-ui.buttons.button-basic>
    </x-slot:trigger>
    <x-slot:content>
        <header class="mb-3 flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">Kunden filtern</h2>
            <x-ui.buttons.button-basic type="button" mode="link" wire:click="resetFilters">Zurücksetzen</x-ui.buttons.button-basic>
        </header>
        <div class="grid gap-4">
            @if($canCreate)
                <x-tables.filter-field label="Status" icon="far fa-signal-alt-3" for="customer-status-filter">
                    <x-ui.forms.select id="customer-status-filter" wire:model.live="activeFilter" aria-label="Kundenstatus" class="w-full">
                        <option value="all">Alle Kunden</option><option value="active">Aktiv</option><option value="inactive">Inaktiv</option>
                    </x-ui.forms.select>
                </x-tables.filter-field>
            @endif
            <x-tables.filter-field label="Anzeige" icon="far fa-list-ol" for="customer-page-size">
                <x-ui.forms.select id="customer-page-size" wire:model.live="perPage" aria-label="Kunden pro Seite" class="w-full">
                    @foreach([15,30,50,100] as $size)<option value="{{ $size }}">{{ $size }} pro Seite</option>@endforeach
                </x-ui.forms.select>
            </x-tables.filter-field>
            @if(trim($search)!=='' || ($canCreate && $activeFilter!=='all'))
                <div class="flex flex-wrap gap-2" aria-label="Aktive Kundenfilter">
                    @if(trim($search)!=='')<x-tables.filter-chip label="Suche" :value="$search" wire:click="$set('search', '')" />@endif
                    @if($canCreate && $activeFilter!=='all')<x-tables.filter-chip label="Status" :value="$activeFilter==='active'?'Aktiv':'Inaktiv'" wire:click="$set('activeFilter', 'all')" />@endif
                </div>
            @endif
        </div>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
