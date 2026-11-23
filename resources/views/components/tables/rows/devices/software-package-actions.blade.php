@if($item->can_manage || $item->can_assign)
    <x-ui.dropdown.anchor-dropdown align="right" width="48">
        <x-slot name="trigger">
            <x-ui.dropdown.action-trigger orientation="vertical" aria-label="Aktionen für {{ $item->name }}" />
        </x-slot>

        <x-slot name="content">
            @if($item->can_assign)
                <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="openAssign('{{ $item->public_id }}')">
                    <i class="far fa-laptop mr-2" aria-hidden="true"></i>
                    Zuweisen
                </x-ui.dropdown.dropdown-link>
            @endif
            @if($item->can_manage)
                <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="openEdit('{{ $item->public_id }}')">
                    <i class="far fa-pen mr-2" aria-hidden="true"></i>
                    Bearbeiten
                </x-ui.dropdown.dropdown-link>
                <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="toggleActive('{{ $item->public_id }}')">
                    <i class="far {{ $item->active ? 'fa-eye-slash' : 'fa-eye' }} mr-2" aria-hidden="true"></i>
                    {{ $item->active ? 'Deaktivieren' : 'Aktivieren' }}
                </x-ui.dropdown.dropdown-link>
            @endif
        </x-slot>
    </x-ui.dropdown.anchor-dropdown>
@endif
