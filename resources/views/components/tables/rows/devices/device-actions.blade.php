<x-ui.dropdown.anchor-dropdown align="right" width="48">
    <x-slot name="trigger">
        <x-ui.dropdown.action-trigger
            class="rt-device-action-trigger"
            orientation="vertical"
            aria-label="Aktionen für {{ $item->display_name ?: $item->hostname ?: 'Gerät' }}"
        />
    </x-slot>

    <x-slot name="content">
        <x-dropdown-link href="#" wire:click.prevent="selectDeviceById({{ $item->id }})">
            <i class="far fa-eye mr-2" aria-hidden="true"></i>
            Details öffnen
        </x-dropdown-link>
    </x-slot>
</x-ui.dropdown.anchor-dropdown>
