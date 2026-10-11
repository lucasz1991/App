<x-ui.dropdown.anchor-dropdown width="64" :dropdown-id="'inbox-message-'.$item->id" layer-group="inbox-controls">
    <x-slot:trigger>
        <x-ui.dropdown.action-trigger orientation="vertical" class="rt-message-inbox__row-action" x-on:keydown.enter.stop :aria-label="'Aktionen für Nachricht '.$item->subject" />
    </x-slot:trigger>
    <x-slot:content>
        <x-dropdown-link href="#" wire:click.prevent="openMessageDetail({{ $item->id }})" x-on:click="close()">
            <i class="far fa-eye" aria-hidden="true"></i> Nachricht öffnen
        </x-dropdown-link>
        @if((int)$item->status === 1)
            <x-dropdown-link href="#" wire:click.prevent="markAsRead({{ $item->id }})" x-on:click="close()">
                <i class="far fa-envelope-open" aria-hidden="true"></i> Als gelesen markieren
            </x-dropdown-link>
        @else
            <x-dropdown-link href="#" wire:click.prevent="markAsUnread({{ $item->id }})" x-on:click="close()">
                <i class="far fa-envelope" aria-hidden="true"></i> Als ungelesen markieren
            </x-dropdown-link>
        @endif
        <x-dropdown-link href="#" tone="danger" confirm-method="deleteMessage" :confirm-arguments="[(int)$item->id]"
            confirm-title="Nachricht löschen?" confirm-message="Diese Nachricht und ihre Anhänge werden endgültig gelöscht. Diese Aktion kann nicht rückgängig gemacht werden." confirm-label="Endgültig löschen">
            <i class="far fa-trash-alt" aria-hidden="true"></i> Nachricht löschen
        </x-dropdown-link>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
