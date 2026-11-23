{{--
    Gemeinsamer Leerzustand ("noch nichts da", "nicht verfuegbar", "bitte auswaehlen").

    variant="page"   (Standard) Seiten- und Bereichsleere: optionales Font-Awesome-Icon,
                     Titel, Beschreibung, Aktion. Optik wie .rt-table-empty.
    variant="inline" Wie page, aber ohne grosse Innenabstaende (Listen, Spalten, Karten).
    variant="widget" Dashboard-Kachel (.widget-empty): Feather-Icon ueber kurzem Text,
                     in 1-zeiligen Kacheln einzeilig (operations-workspace.css).

    Props: icon (FA-Klassen, z. B. "far fa-inbox"), feather (Feather-Name, nur widget),
           title, description (alternativ der Slot), Slot "action".
--}}
@props([
    'variant' => 'page',
    'icon' => null,
    'feather' => null,
    'title' => null,
    'description' => null,
])

@php
    $resolvedVariant = in_array($variant, ['page', 'inline', 'widget'], true) ? $variant : 'page';
    $text = filled($description) ? $description : (trim((string) $slot) !== '' ? $slot : null);
@endphp

@if ($resolvedVariant === 'widget')
    <div {{ $attributes->class(['widget-empty rt-empty-state'])->merge(['data-empty-state' => 'widget']) }}>
        @if ($feather)
            <i data-feather="{{ $feather }}"></i>
        @endif
        @if (filled($title))
            <p class="font-semibold">{{ $title }}</p>
        @endif
        @if ($text !== null)
            <p>{{ $text }}</p>
        @endif
        {{ $action ?? '' }}
    </div>
@else
    <div {{ $attributes->class([
        'rt-empty-state flex flex-col items-center justify-center text-center text-sm text-rt-muted dark:text-rt-dark-muted',
        'gap-2 px-5 py-10' => $resolvedVariant === 'page',
        'gap-1.5 px-4 py-6' => $resolvedVariant === 'inline',
    ])->merge(['data-empty-state' => $resolvedVariant]) }}>
        @if ($icon)
            <span class="rt-empty-state__icon text-2xl opacity-50" aria-hidden="true"><i class="{{ $icon }}"></i></span>
        @endif
        @if (filled($title))
            <p class="rt-empty-state__title m-0 font-semibold text-rt-text dark:text-rt-dark-text">{{ $title }}</p>
        @endif
        @if ($text !== null)
            <p class="rt-empty-state__text m-0 max-w-prose leading-6">{{ $text }}</p>
        @endif
        @isset($action)
            <div class="rt-empty-state__action mt-2">{{ $action }}</div>
        @endisset
    </div>
@endif
