<x-tables.row-actions class="rt-device-action-trigger" :label="'Aktionen für '.($item->display_name ?: $item->hostname ?: 'Gerät')">
    <x-ui.dropdown.dropdown-link wire:click.prevent="selectDeviceById({{ $item->id }})">
        <i class="far fa-eye" aria-hidden="true"></i>
        Details öffnen
    </x-ui.dropdown.dropdown-link>
</x-tables.row-actions>
