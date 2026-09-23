<x-ui.buttons.button-basic mode="link" type="button" wire:click="openDetails({{ $item->id }})" wire:loading.attr="disabled" wire:target="openDetails" class="rt-table-row-action" aria-label="{{ $item->title }} öffnen" title="Leistung öffnen">
    <i class="far fa-chevron-right" aria-hidden="true"></i>
</x-ui.buttons.button-basic>
