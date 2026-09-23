<x-ui.buttons.button-basic mode="link" type="button" wire:click="openDetails({{ $item->id }})" class="rt-disposition-row-action" aria-label="{{ $item->title }} öffnen">
    <i class="far fa-chevron-right" aria-hidden="true"></i>
</x-ui.buttons.button-basic>
