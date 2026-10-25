@props(['count' => 0])
<div class="rt-service-selection-controls" data-service-selection-controls>
    <x-tables.bulk-actions :count="$count" :show-select-all="false">
        <button type="button" role="menuitem" wire:click="toggleSelectAllServices" x-on:click="close(true)" class="rt-table-mobile-sort__option" data-service-selection-page>
            <i class="far fa-check-square" aria-hidden="true"></i>
            <span>Seite auswählen / abwählen</span>
        </button>
        <button type="button" role="menuitem" wire:click="clearServiceSelection" x-on:click="close(true)" class="rt-table-mobile-sort__option" data-service-selection-clear>
            <i class="far fa-times" aria-hidden="true"></i>
            <span>Auswahl aufheben</span>
        </button>
    </x-tables.bulk-actions>
    <span class="sr-only" role="status" aria-live="polite">{{ (int) $count }} Leistungen ausgewählt</span>
</div>
