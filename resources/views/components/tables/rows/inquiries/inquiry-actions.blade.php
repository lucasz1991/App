<x-tables.row-open-action wire:click="select({{ $item->id }})" wire:loading.attr="disabled" wire:target="select" :label="'Anfrage '.$item->number.' öffnen'" title="Anfrage öffnen" />
