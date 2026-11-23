{{--
    Ladeanzeige-Standard: far fa-spinner-third fa-spin (FA Pro 5, auch im Kundenportal vorhanden).

    Ohne label: nur das Icon (aria-hidden) - fuer Buttons, deren Text den Zustand schon nennt.
      <x-ui.loading.spinner wire:loading wire:target="save" />
    Mit label: role="status" plus Text; sr-label macht den Text nur fuer Screenreader sichtbar.
      <x-ui.loading.spinner label="Wird geladen …" />
    Alle weiteren Attribute (wire:loading, x-show, class ...) landen am Wurzelelement.
--}}
@props([
    'label' => null,
    'srLabel' => false,
])

@if (filled($label))
    <span {{ $attributes->class('rt-spinner inline-flex items-center gap-2')->merge(['role' => 'status']) }}>
        <i class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
        <span @class(['sr-only' => $srLabel])>{{ $label }}</span>
    </span>
@else
    <i {{ $attributes->class('rt-spinner far fa-spinner-third fa-spin')->merge(['aria-hidden' => 'true']) }}></i>
@endif
