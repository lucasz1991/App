@props([
    'id' => null,
    'label' => null,
    'help' => null,
    'disabled' => false,
])

{{-- Nur Auswahlkaestchen. Schalter laufen ueber x-ui.forms.toggle-button. --}}
@php
    $inputId = $id ?: \Illuminate\Support\Str::uuid();
    $baseCheckbox = 'rt-ui-checkbox h-5 w-5 shrink-0 rounded-md border-rt-border bg-rt-control text-rt-accent shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring disabled:cursor-not-allowed disabled:opacity-50 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-accent';
@endphp

<label
    for="{{ $inputId }}"
    @class([
        'rt-ui-check-field inline-flex min-h-11 items-center gap-3',
        'cursor-pointer' => ! $disabled,
        'cursor-not-allowed opacity-60' => $disabled,
    ])
>
    <input
        id="{{ $inputId }}"
        type="checkbox"
        {{ $disabled ? 'disabled' : '' }}
        {!! $attributes->merge(['class' => $baseCheckbox]) !!}
    />
    @if($label)
        <span class="rt-ui-check-label text-sm font-medium leading-5 text-rt-text dark:text-rt-dark-text">{{ $label }}</span>
    @endif
</label>

@if($help)
    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $help }}</p>
@endif
