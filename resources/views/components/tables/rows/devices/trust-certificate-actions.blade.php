@if($item->can_manage)
    <x-ui.dropdown.anchor-dropdown align="right" width="48">
        <x-slot name="trigger">
            <x-ui.dropdown.action-trigger orientation="vertical" aria-label="Aktionen für {{ $item->name }}" />
        </x-slot>

        <x-slot name="content">
            <x-ui.dropdown.dropdown-link href="#" wire:click.prevent="toggleCertificate('{{ $item->public_id }}')">
                <i class="far {{ $item->active ? 'fa-eye-slash' : 'fa-eye' }} mr-2" aria-hidden="true"></i>
                {{ $item->active ? 'Deaktivieren' : 'Aktivieren' }}
            </x-ui.dropdown.dropdown-link>
        </x-slot>
    </x-ui.dropdown.anchor-dropdown>
@endif
