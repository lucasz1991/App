@props([
    'id'        => null,
    'label'     => null,
    'help'      => null,
    'toggle'    => false,     // <- wenn true: als Toggle-Switch rendern
    'size'      => 'md',      // sm|md
    'disabled'  => false,
])

@php
    $inputId = $id ?: \Illuminate\Support\Str::uuid();
    $isToggle = filter_var($toggle, FILTER_VALIDATE_BOOLEAN);

    // Größen für Switch-Variante
    $track = $size === 'sm'
        ? 'w-8 h-4 after:h-3.5 after:w-3.5 after:top-[2px] after:start-[2px]'
        : 'w-9 h-5 after:h-4 after:w-4 after:top-[2px] after:start-[2px]';

    // Basis-Input-Klassen
    $baseCheckbox = 'rounded border-rt-border bg-rt-control text-rt-accent shadow-rt-xs transition-all duration-300 ease-rt-spring focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-accent';
@endphp

@if(!$isToggle)
    {{-- Normale Checkbox --}}
    <label for="{{ $inputId }}" class="inline-flex items-start gap-2 cursor-pointer">
        <input
            id="{{ $inputId }}"
            type="checkbox"
            {{ $disabled ? 'disabled' : '' }}
            {!! $attributes->merge(['class' => $baseCheckbox]) !!}
        />
        @if($label)
            <span class="text-sm text-rt-text dark:text-rt-dark-text">{{ $label }}</span>
        @endif
    </label>

    @if($help)
        <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $help }}</p>
    @endif
@else
    {{-- Toggle-Switch --}}
    <label for="{{ $inputId }}" class="flex items-center cursor-pointer select-none">
        <input
            id="{{ $inputId }}"
            type="checkbox"
            {{ $disabled ? 'disabled' : '' }}
            class="sr-only peer"
            {{-- Alle externen Attribute übernehmen (z. B. wire:model, x-model, name, etc.) --}}
            {!! $attributes->except('class') !!}
            aria-checked="true"
            role="switch"
        />

        {{-- Track + Knob (peer styles) --}}
        <div class="relative {{ $track }}
                    bg-rt-surface-muted dark:bg-rt-dark-surface-muted rounded-full
                    transition-all duration-300 ease-rt-spring
                    peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-rt-accent/30
                    peer-checked:bg-rt-accent dark:peer-checked:bg-rt-dark-accent
                    after:content-[''] after:absolute after:bg-white after:border after:border-rt-border
                    after:rounded-full after:transition-all
                    peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full
                    {{ $disabled ? 'opacity-50 pointer-events-none' : '' }}">
        </div>

        @if($label)
            <span class="ms-3 text-sm font-medium text-rt-text dark:text-rt-dark-text">
                {{ $label }}
            </span>
        @endif
    </label>

    @if($help)
        <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $help }}</p>
    @endif
@endif
