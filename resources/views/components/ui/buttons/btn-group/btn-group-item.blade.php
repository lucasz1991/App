@props([
    'href'     => null,     // wenn gesetzt -> <a>, sonst <button>
    'active'   => false,    // aktiven Zustand hervorheben
    'disabled' => false,    // disabled Zustand
    'size'     => 'md',     // sm|md|lg
    'class'    => '',       // zusätzliche Klassen
])

@php
$sizeMap = [
    'sm' => 'px-2 py-1 text-xs',
    'md' => 'px-3 py-2 text-sm',
    'lg' => 'px-4 py-2.5 text-base',
];

$base = 'inline-flex items-center gap-2 font-medium
         focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
         transition-colors select-none';

$colors = $active
    ? 'rt-ui-button-group-item-active bg-rt-red text-white hover:bg-rt-red-dark focus-visible:ring-rt-red/40'
    : 'bg-rt-surface text-rt-text hover:bg-rt-surface-muted focus-visible:ring-rt-red/40
       dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted';

$borders = 'border-l border-rt-border first:border-l-0 dark:border-rt-dark-border';

$rounded = 'first:rounded-l-lg last:rounded-r-lg';

$disabledCls = $disabled
    ? 'opacity-50 cursor-not-allowed hover:bg-inherit'
    : '';

$cls = implode(' ', [
    'rt-ui-button rt-ui-button-group-item',
    $base,
    $sizeMap[$size] ?? $sizeMap['md'],
    $colors,
    $borders,
    $rounded,
    $disabledCls,
    $class,
]);

$interactiveAttributes = $attributes->filter(function ($value, $key) use ($disabled) {
    if (! $disabled) {
        return true;
    }

    foreach (['wire:click', '@click', 'x-on:click', 'onclick'] as $prefix) {
        if ($key === $prefix || str_starts_with($key, $prefix . '.')) {
            return false;
        }
    }

    return true;
});
@endphp

@if($href)
    <a
        @unless($disabled) href="{{ $href }}" @endunless
        {{ $interactiveAttributes->merge(['class' => $cls]) }}
        @if($disabled) aria-disabled="true" tabindex="-1" x-on:click.prevent.stop @endif
    >
        {{ $slot }}
    </a>
@else
    <button
        type="button"
        {{ $attributes->merge(['class' => $cls]) }}
        @disabled($disabled)
    >
        {{ $slot }}
    </button>
@endif
