<x-ui.buttons.button-basic type="button" mode="link" class="rt-table-row-action" wire:click="select({{ $item->id }})" wire:loading.attr="disabled" wire:target="select" aria-label="Anfrage {{ $item->number }} öffnen" title="Anfrage öffnen">
    <i class="far fa-chevron-right" aria-hidden="true"></i>
</x-ui.buttons.button-basic>
