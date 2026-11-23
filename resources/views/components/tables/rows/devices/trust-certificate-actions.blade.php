@if($item->can_manage)
    <x-tables.row-actions :label="'Aktionen für '.$item->name">
        <x-ui.dropdown.dropdown-link wire:click.prevent="toggleCertificate('{{ $item->public_id }}')">
            <i class="far {{ $item->active ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i>
            {{ $item->active ? 'Deaktivieren' : 'Aktivieren' }}
        </x-ui.dropdown.dropdown-link>
    </x-tables.row-actions>
@endif
