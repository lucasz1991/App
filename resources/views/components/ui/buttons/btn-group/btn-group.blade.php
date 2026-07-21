@props([
    'label' => null,     // optional für aria-label
    'class' => '',       // zusätzliche Klassen
])

@php
$base = 'rt-ui-button-group rt-ui-surface inline-flex flex-wrap items-stretch overflow-hidden rounded-lg border border-rt-border
         bg-rt-surface shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface';
@endphp

<div {{ $attributes->merge([
        'class' => $base . ' ' . $class,
        'role'  => 'group',
        'aria-label' => $label ?? 'Button group'
    ]) }}>
    {{ $slot }}
</div>
