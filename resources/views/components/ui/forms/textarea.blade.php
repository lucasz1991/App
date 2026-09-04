@props([
    'disabled' => false,
    'readonly' => false,
    'rows' => 4,
])

@php
    // Gemeinsamer mehrzeiliger Text-Control: mobil mindestens 16 px und mit
    // demselben dualen Dark-Mode-Vertrag wie Input und Select.
    $baseClasses = 'rt-ui-control rt-ui-field-control min-h-28 w-full resize-y rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft transition-[border-color,box-shadow,background-color,color] duration-200 ease-rt-spring hover:border-rt-accent/50 hover:shadow-rt-sm focus:border-rt-accent disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-60 disabled:shadow-none read-only:bg-rt-surface-muted read-only:shadow-none sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas dark:read-only:bg-rt-dark-canvas';
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

<textarea
    rows="{{ $rows }}"
    {{ $disabled ? 'disabled' : '' }}
    {{ $readonly ? 'readonly' : '' }}
    {{ $attributes->merge(array_merge(['class' => $baseClasses], $autosaveAttributes)) }}
>{{ $slot }}</textarea>
