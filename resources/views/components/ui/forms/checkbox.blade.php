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
    $trackSize = $size === 'sm'
        ? 'h-6 w-10 after:start-[3px] after:top-[3px] after:h-4 after:w-4'
        : 'h-7 w-12 after:start-1 after:top-1 after:h-5 after:w-5';
    $baseCheckbox = 'rt-ui-checkbox h-5 w-5 shrink-0 rounded-md border-rt-border bg-rt-control text-rt-accent shadow-rt-xs transition-all duration-200 ease-rt-spring focus:ring-4 focus:ring-rt-accent/20 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-accent';
@endphp

@if(! $isToggle)
    <label
        for="{{ $inputId }}"
        @class([
            'inline-flex min-h-11 items-center gap-3',
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
            <span class="text-sm font-medium leading-5 text-rt-text dark:text-rt-dark-text">{{ $label }}</span>
        @endif
    </label>
@else
    <label
        for="{{ $inputId }}"
        @class([
            'group inline-flex min-h-11 items-center gap-3 select-none',
            'cursor-pointer' => ! $disabled,
            'cursor-not-allowed opacity-60' => $disabled,
        ])
    >
        <input
            id="{{ $inputId }}"
            type="checkbox"
            role="switch"
            {{ $disabled ? 'disabled' : '' }}
            class="peer sr-only"
            {!! $attributes->except('class') !!}
        />

        <span
            data-toggle-control
            aria-hidden="true"
            class="rt-ui-toggle-control relative {{ $trackSize }} shrink-0 rounded-full border border-rt-border bg-slate-200 shadow-[inset_0_1px_2px_rgba(15,23,42,.12)] transition-all duration-200 ease-rt-spring after:absolute after:rounded-full after:border after:border-slate-200 after:bg-white after:shadow-[0_2px_5px_rgba(15,23,42,.28)] after:transition-transform after:duration-200 after:ease-rt-spring peer-focus-visible:ring-4 peer-focus-visible:ring-rt-accent/20 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-white peer-checked:border-rt-accent peer-checked:bg-rt-accent peer-checked:after:translate-x-full peer-disabled:shadow-none rtl:peer-checked:after:-translate-x-full dark:border-slate-600 dark:bg-slate-700 dark:after:border-slate-500 dark:peer-focus-visible:ring-rt-dark-accent/30 dark:peer-focus-visible:ring-offset-rt-dark-surface dark:peer-checked:border-rt-dark-accent dark:peer-checked:bg-rt-dark-accent"
        ></span>

        @if($label)
            <span class="text-sm font-semibold leading-5 text-rt-text transition-colors group-hover:text-rt-accent dark:text-rt-dark-text dark:group-hover:text-white">
                {{ $label }}
            </span>
        @endif
    </label>
@endif

@if($help)
    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $help }}</p>
@endif
