<x-ui.dropdown.anchor-dropdown align="right" width="56">
    <x-slot:trigger><x-ui.dropdown.action-trigger orientation="vertical" :aria-label="'Aktionen für '.$item->company_name" /></x-slot:trigger>
    <x-slot:content>
        <x-dropdown-link wire:click.prevent="selectCustomer({{ $item->id }})"><i class="far fa-id-card" aria-hidden="true"></i>Kundenprofil öffnen</x-dropdown-link>
        @if(array_key_exists('is_active', $item->getAttributes()))<x-dropdown-link wire:click.prevent="editCustomer({{ $item->id }})"><i class="far fa-pen" aria-hidden="true"></i>Bearbeiten</x-dropdown-link>@endif
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
