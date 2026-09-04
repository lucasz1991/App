@props([
    'id' => null,
    'label' => null,
    'help' => null,
    'toggle' => false,
    'size' => 'md',
    'disabled' => false,
])

@php
    $inputId = $id ?: \Illuminate\Support\Str::uuid();
    $isToggle = filter_var($toggle, FILTER_VALIDATE_BOOLEAN);
    $resolvedSize = in_array($size, ['sm', 'md'], true) ? $size : 'md';
    $baseCheckbox = 'rt-ui-checkbox h-5 w-5 shrink-0 rounded-md border-rt-border bg-rt-control text-rt-accent shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring disabled:cursor-not-allowed disabled:opacity-50 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-accent';
@endphp

@if(! $isToggle)
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
@else
    <label
        for="{{ $inputId }}"
        {{ $attributes->only('class')->class([
            'rt-ui-toggle group',
            'cursor-pointer' => ! $disabled,
            'is-disabled' => $disabled,
        ]) }}
    >
        <input
            id="{{ $inputId }}"
            type="checkbox"
            role="switch"
            {{ $disabled ? 'disabled' : '' }}
            class="rt-ui-toggle__input peer sr-only"
            {!! $attributes->except('class') !!}
        />

        <span
            data-toggle-control
            aria-hidden="true"
            class="rt-ui-toggle-control rt-ui-toggle-control--{{ $resolvedSize }}"
        ></span>

        @if($label)
            <span class="rt-ui-toggle__label">
                {{ $label }}
            </span>
        @endif
    </label>
@endif

@if($help)
    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $help }}</p>
@endif
