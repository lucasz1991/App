@props([
    'type' => 'text',
    'disabled' => false,
    'readonly' => false,
    'autofocus' => false,
])

@php
    // Mobil immer mindestens 16 px Schriftgroesse: verhindert den automatischen
    // Browser-Zoom beim Fokussieren auf iOS und bleibt am Desktop kompakt.
    $baseClasses = 'rt-ui-control min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft transition-[border-color,box-shadow,background-color,color] duration-200 ease-rt-spring hover:border-rt-accent/50 hover:shadow-rt-sm focus:border-rt-accent disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-60 disabled:shadow-none read-only:bg-rt-surface-muted read-only:shadow-none sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas dark:read-only:bg-rt-dark-canvas';

    // Typ-spezifische Klassen (optional)
    $typeClasses = match($type) {
        'file' => 'cursor-pointer file:mr-3 file:rounded-lg file:border-0 file:bg-rt-accent-soft file:px-3 file:py-2 file:text-sm file:font-semibold file:text-rt-accent hover:file:bg-rt-accent hover:file:text-white dark:file:bg-rt-dark-surface-muted dark:file:text-white',
        'number' => 'text-right tabular-nums',
        default => '',
    };

    $allClasses = $baseClasses . ' ' . $typeClasses;
    $wireModel = $attributes->wire('model')->value();
    $autosaveFieldId = (string) ($attributes->get('data-autosave-field-id')
        ?: $attributes->get('id')
        ?: ($wireModel ? 'autosave-'.substr(md5($wireModel), 0, 10) : ''));
    $autosaveAttributes = $wireModel ? [
        'data-autosave-field' => '',
        'data-autosave-model' => $wireModel,
        'data-autosave-field-id' => $autosaveFieldId,
        'data-autosave-state' => 'idle',
        'data-autosave-visual' => '',
    ] : [];
@endphp

<input
    type="{{ $type }}"
    {{ $disabled ? 'disabled' : '' }}
    {{ $readonly ? 'readonly' : '' }}
    {{ $autofocus ? 'autofocus' : '' }}
    {!! $attributes->merge(array_merge(['class' => $allClasses], $autosaveAttributes)) !!}
>
