<x-tables.row-actions width="56" x-on:keydown.enter.stop :label="'Aktionen für '.$item->company_name">
    <x-ui.dropdown.dropdown-link wire:click.prevent="selectCustomer({{ $item->id }})"><i class="far fa-id-card" aria-hidden="true"></i>Kundenprofil öffnen</x-ui.dropdown.dropdown-link>
    @if(array_key_exists('is_active', $item->getAttributes()))<x-ui.dropdown.dropdown-link wire:click.prevent="editCustomer({{ $item->id }})"><i class="far fa-pen" aria-hidden="true"></i>Bearbeiten</x-ui.dropdown.dropdown-link>@endif
</x-tables.row-actions>
