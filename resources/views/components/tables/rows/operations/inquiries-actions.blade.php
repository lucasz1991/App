<button type="button" class="rt-disposition-row-open" wire:click="select({{ $item->id }})" wire:loading.attr="disabled" wire:target="select" aria-label="Anfrage {{ $item->number }} öffnen" title="Anfrage öffnen">
    <i class="far fa-chevron-right" aria-hidden="true"></i>
</button>
