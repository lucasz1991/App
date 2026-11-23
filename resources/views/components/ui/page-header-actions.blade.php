@props([
    // Ziel im Seitenkopf von x-ui.page-header:
    //   action-list -> [data-page-header-action-list] (Aktionsknoepfe, Standard)
    //   actions     -> [data-page-header-actions] (vor der Aktionsliste, neben dem Info-Knopf)
    //   search      -> [data-page-header-search] (Steuerung/Suche neben dem Titel)
    'target' => 'action-list',
])

@php
    $teleportTarget = match ($target) {
        'action-list' => '[data-page-header-action-list]',
        'actions' => '[data-page-header-actions]',
        'search' => '[data-page-header-search]',
        default => throw new \InvalidArgumentException('x-ui.page-header-actions: unbekanntes Ziel "'.$target.'".'),
    };
@endphp

{{--
    Teleport in den Seitenkopf der umgebenden x-ui.page. Nur fuer eingebettete
    Livewire-Kindkomponenten: Slots von x-ui.page sind ueber die
    Komponentengrenze nicht erreichbar. Wer x-ui.page selbst rendert, nutzt
    die Slots controls/actions. Der Slot braucht genau EIN Wurzelelement
    (x-teleport-Regel); wire:key und weitere Attribute landen am <template>.
--}}
<template x-teleport="{{ $teleportTarget }}" {{ $attributes }}>
    {{ $slot }}
</template>
