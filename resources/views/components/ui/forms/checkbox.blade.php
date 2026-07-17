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
    $baseCheckbox = 'rounded border-slate-300 text-rt-red shadow-sm focus:ring-rt-red/40 dark:border-slate-600 dark:bg-slate-800';
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
            <span class="text-sm text-slate-900 dark:text-slate-200">{{ $label }}</span>
        @endif
    </label>

    @if($help)
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
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
                    bg-slate-200 dark:bg-slate-700 rounded-full
                    transition
                    peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-rt-red/30
                    peer-checked:bg-rt-red
                    after:content-[''] after:absolute after:bg-white after:border after:border-slate-300
                    after:rounded-full after:transition-all
                    peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full
                    {{ $disabled ? 'opacity-50 pointer-events-none' : '' }}">
        </div>

        @if($label)
            <span class="ms-3 text-sm font-medium text-slate-500 dark:text-slate-400">
                {{ $label }}
            </span>
        @endif
    </label>

    @if($help)
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $help }}</p>
    @endif
@endif
