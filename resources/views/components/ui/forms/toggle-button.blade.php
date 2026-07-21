@props([
    'id' => null,
    'label' => null,
    'model' => null,
    'change' => null,
    'disabled' => false,
])

@php
    $inputId = $id ?: 'toggle-' . \Illuminate\Support\Str::random(6);
@endphp

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
        @if($model) wire:model.live="{{ $model }}" @endif
        @if($change) @change="{{ $change }}" @endif
        {{ $disabled ? 'disabled' : '' }}
        {!! $attributes->except('class') !!}
        class="peer sr-only"
    />

    <span
        data-toggle-control
        aria-hidden="true"
        class="rt-ui-toggle-control relative h-7 w-12 shrink-0 rounded-full border border-rt-border bg-slate-200 shadow-[inset_0_1px_2px_rgba(15,23,42,.12)] transition-all duration-200 ease-rt-spring after:absolute after:start-1 after:top-1 after:h-5 after:w-5 after:rounded-full after:border after:border-slate-200 after:bg-white after:shadow-[0_2px_5px_rgba(15,23,42,.28)] after:transition-transform after:duration-200 after:ease-rt-spring peer-focus-visible:ring-4 peer-focus-visible:ring-rt-red/20 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-white peer-checked:border-rt-red peer-checked:bg-rt-red peer-checked:after:translate-x-full peer-disabled:shadow-none rtl:peer-checked:after:-translate-x-full dark:border-slate-600 dark:bg-slate-700 dark:after:border-slate-500 dark:peer-focus-visible:ring-rt-red/30 dark:peer-focus-visible:ring-offset-rt-dark-surface dark:peer-checked:border-rt-red dark:peer-checked:bg-rt-red"
    ></span>

    @if($label)
        <span class="text-sm font-semibold leading-5 text-rt-text transition-colors group-hover:text-rt-accent dark:text-rt-dark-text dark:group-hover:text-white">
            {{ $label }}
        </span>
    @endif
</label>
