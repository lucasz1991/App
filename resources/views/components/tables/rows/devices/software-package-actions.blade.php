@if($item->can_manage || $item->can_assign)
    <x-tables.row-actions :label="'Aktionen für '.$item->name">
        @if($item->can_assign)
            <x-ui.dropdown.dropdown-link wire:click.prevent="openAssign('{{ $item->public_id }}')">
                <i class="far fa-laptop" aria-hidden="true"></i>
                Zuweisen
            </x-ui.dropdown.dropdown-link>
        @endif
        @if($item->can_manage)
            <x-ui.dropdown.dropdown-link wire:click.prevent="openEdit('{{ $item->public_id }}')">
                <i class="far fa-pen" aria-hidden="true"></i>
                Bearbeiten
            </x-ui.dropdown.dropdown-link>
            <x-ui.dropdown.dropdown-link wire:click.prevent="toggleActive('{{ $item->public_id }}')">
                <i class="far {{ $item->active ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i>
                {{ $item->active ? 'Deaktivieren' : 'Aktivieren' }}
            </x-ui.dropdown.dropdown-link>
        @endif
    </x-tables.row-actions>
@endif
