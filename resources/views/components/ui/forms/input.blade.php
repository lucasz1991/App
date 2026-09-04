@props([
    'type' => 'text',
    'disabled' => false,
    'readonly' => false,
    'autofocus' => false,
    'label' => null,
    'icon' => null,
    'reveal' => null,
])

@php
    // Mobil immer mindestens 16 px Schriftgroesse: verhindert den automatischen
    // Browser-Zoom beim Fokussieren auf iOS und bleibt am Desktop kompakt.
    $baseClasses = 'rt-ui-control rt-ui-field-control min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft transition-[border-color,box-shadow,background-color,color] duration-200 ease-rt-spring hover:border-rt-accent/50 hover:shadow-rt-sm focus:border-rt-accent disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-60 disabled:shadow-none read-only:bg-rt-surface-muted read-only:shadow-none sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas dark:read-only:bg-rt-dark-canvas';

    // Typ-spezifische Klassen (optional)
    $typeClasses = match($type) {
        'file' => 'cursor-pointer file:mr-3 file:rounded-lg file:border-0 file:bg-rt-accent-soft file:px-3 file:py-2 file:text-sm file:font-semibold file:text-rt-accent hover:file:bg-rt-accent hover:file:text-white dark:file:bg-rt-dark-surface-muted dark:file:text-white',
        'number' => 'text-right tabular-nums',
        default => '',
    };

    $allClasses = $baseClasses . ' ' . $typeClasses;
    $inputId = $attributes->get('id') ?: ($label ? 'field-'.\Illuminate\Support\Str::random(8) : null);
    $floating = filled($label);
    $showReveal = $type === 'password' && ($reveal === null
        ? $floating
        : filter_var($reveal, FILTER_VALIDATE_BOOLEAN));
    $resolvedIcon = $icon ?: match ($type) {
        'email' => 'email',
        'password' => 'password',
        default => null,
    };
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

@if($floating)
    <div class="rt-ui-input-field rt-login-field" @if($showReveal) x-data="{ passwordVisible: false }" @endif>
        @if($resolvedIcon)
            <span class="rt-login-field__icon" aria-hidden="true">
                @if($resolvedIcon === 'email')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5v10.5H3.75z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 7.5 7.5 5.25 7.5-5.25" />
                    </svg>
                @elseif($resolvedIcon === 'password')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="5" y="10" width="14" height="10" rx="2" />
                        <path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
                    </svg>
                @else
                    <i class="{{ $resolvedIcon }}"></i>
                @endif
            </span>
        @endif

        <input
            id="{{ $inputId }}"
            type="{{ $type }}"
            @if($showReveal) x-bind:type="passwordVisible ? 'text' : 'password'" @endif
            placeholder=" "
            {{ $disabled ? 'disabled' : '' }}
            {{ $readonly ? 'readonly' : '' }}
            {{ $autofocus ? 'autofocus' : '' }}
            {!! $attributes->except(['id', 'placeholder'])->merge(array_merge(['class' => $allClasses.' rt-login-field__input'.($showReveal ? ' rt-login-field__input--password' : '')], $autosaveAttributes)) !!}
        >

        <label for="{{ $inputId }}" class="rt-login-field__label">{{ $label }}</label>

        @if($showReveal)
            <button
                type="button"
                class="rt-login-field__toggle"
                x-on:click="passwordVisible = !passwordVisible"
                x-bind:aria-pressed="passwordVisible"
                x-bind:aria-label="passwordVisible ? @js(__('app.hide_password')) : @js(__('app.show_password'))"
                x-bind:title="passwordVisible ? @js(__('app.hide_password')) : @js(__('app.show_password'))"
            >
                <svg x-show="!passwordVisible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.75 12s3.5-5.25 9.25-5.25S21.25 12 21.25 12 17.75 17.25 12 17.25 2.75 12 2.75 12Z" />
                    <circle cx="12" cy="12" r="2.25" />
                </svg>
                <svg x-cloak x-show="passwordVisible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" d="m4 4 16 16" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.6 7.05A9.8 9.8 0 0 1 12 6.75c5.75 0 9.25 5.25 9.25 5.25a15.7 15.7 0 0 1-2.7 3.15M14.35 16.95c-.76.2-1.55.3-2.35.3C6.25 17.25 2.75 12 2.75 12a15.2 15.2 0 0 1 3.05-3.42" />
                </svg>
            </button>
        @endif
    </div>
@else
    <input
        type="{{ $type }}"
        {{ $disabled ? 'disabled' : '' }}
        {{ $readonly ? 'readonly' : '' }}
        {{ $autofocus ? 'autofocus' : '' }}
        {!! $attributes->merge(array_merge(['class' => $allClasses], $autosaveAttributes)) !!}
    >
@endif
