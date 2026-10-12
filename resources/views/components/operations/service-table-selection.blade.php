@props(['count' => 0])
<div class="rt-service-selection-controls" data-service-selection-controls>
    <x-tables.bulk-actions :count="$count" select-all="toggleSelectAllServices" select-all-title="Leistungen dieser Seite auswählen oder Auswahl aufheben">
        <button type="button" role="menuitem" wire:click="clearServiceSelection" x-on:click="close(true)" class="rt-table-mobile-sort__option" data-service-selection-clear>
            <i class="far fa-times" aria-hidden="true"></i>
            <span>Auswahl aufheben</span>
        </button>
    </x-tables.bulk-actions>
    <span class="sr-only" role="status" aria-live="polite">{{ (int) $count }} Leistungen ausgewählt</span>
</div>
