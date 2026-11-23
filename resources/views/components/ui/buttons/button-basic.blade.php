@props([
    'mode' => 'secondary',
    'size' => 'md',
    'tone' => 'neutral',
    'icon' => null,
    'label' => null,
    'can' => true,
    'confirmMethod' => null,
    'confirmArguments' => [],
    'confirmTitle' => null,
    'confirmMessage' => null,
    'confirmVariant' => 'destructive',
    'confirmLabel' => null,
])
{{--
    Modi: primary, secondary (Standard), danger, success, link und icon.
    Unbekannte Modi (auch das fruehere 'basic') fallen auf secondary zurueck.

    mode="icon" ist der quadratische Icon-Knopf:
      size  sm (h-8) | md (h-10) | lg (h-11)
      tone  neutral (Sekundaer-Optik) | quiet (ruhig, z. B. Modal-Schliessen)
            | primary | danger | plain (ohne Anstrich, Optik kommt per class)
      icon  Font-Awesome-Klassen; ohne icon wird nur der Slot gerendert
      label setzt aria-label und title, sofern nicht selbst angegeben
    Icon-Knoepfe sind standardmaessig type="button".
--}}
@php

$isIcon = $mode === 'icon';

$modeClasses = match ($mode) {
    'primary' => ' text-white dark:text-white bg-rt-red hover:bg-rt-red-dark focus:ring-white/80 border-rt-red',
    'danger' => ' text-white dark:text-white bg-red-600 hover:bg-red-700 focus:ring-white/80 border-red-600',
    'success' => ' text-white dark:text-white bg-emerald-600 hover:bg-emerald-700 focus:ring-white/80 border-emerald-600',
    'link' => ' text-rt-red dark:text-rt-dark-accent bg-transparent hover:bg-rt-red/10 dark:hover:bg-rt-dark-accent-soft focus:ring-rt-red/30 border-transparent',
    'icon' => '',
    default => ' text-rt-text dark:text-white bg-rt-surface dark:bg-rt-dark-surface hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted focus:ring-rt-accent/40 border-rt-border dark:border-rt-dark-border',
};

$themeVariant = match ($mode) {
    'primary', 'danger', 'success', 'link' => $mode,
    default => 'secondary',
};

$sizeClasses = $isIcon
    ? match ($size) {
        'sm' => 'h-8 w-8',
        'lg' => 'h-11 w-11',
        default => 'h-10 w-10',
    }
    : match ($size) {
        'sm' => 'px-2 py-1 text-sm',
        'lg' => 'px-5 py-3 text-lg',
        'xl' => 'px-6 py-4 text-xl',
        '2xl' => 'px-7 py-5 text-2xl',
        default => 'px-4 py-2 text-sm',
    };

if (is_string($can) && $can !== '') {
    $isAllowed = auth()->check() && auth()->user()->can($can);
} else {
    $isAllowed = (bool) $can;
}

$isDeniedByCan = ! $isAllowed;
$isDisabled = $isDeniedByCan || ! in_array($attributes->get('disabled'), [null, false], true);

if ($isIcon) {
    $toneClasses = match ($tone) {
        'plain' => '',
        'quiet' => 'rt-ui-button rt-ui-button-secondary rounded-lg border border-rt-border/70 bg-rt-control text-rt-muted shadow-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/35 hover:text-rt-accent active:scale-[0.97] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border/70 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/35 dark:hover:text-rt-dark-accent',
        'primary' => 'rt-ui-button rt-ui-button-primary rounded-xl border border-rt-red bg-rt-red text-white shadow-rt-xs transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-white/80 dark:text-white',
        'danger' => 'rt-ui-button rt-ui-button-danger rounded-xl border border-red-600 bg-red-600 text-white shadow-rt-xs transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-white/80 dark:text-white',
        default => 'rt-ui-button rt-ui-button-secondary rounded-xl border border-rt-border bg-rt-surface text-rt-text shadow-rt-xs transition hover:bg-rt-surface-muted hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/40 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted dark:hover:text-white',
    };

    $classes = trim('rt-ui-button-icon inline-flex shrink-0 items-center justify-center ' . $toneClasses . ' ' . $sizeClasses);
} else {
    $classes = 'rt-ui-button rt-ui-button-' . $themeVariant . ' ' . $modeClasses . ' ' . $sizeClasses;
    $classes .= ' transition-all duration-300 ease-rt-spring inline-flex items-center justify-center gap-2 text-center font-semibold leading-none border rounded-lg shadow-rt-xs active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-inset focus:ring-offset-0 disabled:opacity-50 [&>i]:shrink-0 [&>i]:leading-none [&>svg]:h-[1em] [&>svg]:w-[1em] [&>svg]:shrink-0';

    if ($mode === 'primary') {
        $classes .= ' hover:shadow-rt-glow hover:-translate-y-px';
    }
}

if ($isDisabled) {
    $classes .= ' opacity-80 cursor-not-allowed';
}

$title = $isDeniedByCan
    ? __('app.no_permission')
    : ($attributes->get('title') ?? ($isIcon ? $label : null));

$attributesWithoutTitle = $attributes->except(['title', 'disabled']);

if ($isIcon && filled($label) && ! $attributes->has('aria-label')) {
    $attributesWithoutTitle = $attributesWithoutTitle->merge(['aria-label' => $label]);
}

if (is_string($confirmMethod) && trim($confirmMethod) !== '') {
    $attributesWithoutTitle = $attributesWithoutTitle
        ->filter(function ($value, $key) {
            foreach (['wire:click', '@click', 'x-on:click', 'onclick'] as $prefix) {
                if ($key === $prefix || str_starts_with($key, $prefix . '.')) {
                    return false;
                }
            }

            return true;
        })
        ->merge([
            'x-on:click.prevent' => \App\Support\Ui\ConfirmationAction::alpine(
                method: $confirmMethod,
                arguments: is_array($confirmArguments) ? $confirmArguments : [$confirmArguments],
                title: $confirmTitle,
                message: $confirmMessage,
                variant: $confirmVariant,
                confirmLabel: $confirmLabel,
            ),
        ]);
}

$interactiveAttributes = $attributesWithoutTitle->filter(function ($value, $key) use ($isDisabled) {
    if (! $isDisabled) {
        return true;
    }

    if ($key === 'href') {
        return false;
    }

    foreach (['wire:click', '@click', 'x-on:click', 'onclick'] as $prefix) {
        if ($key === $prefix || str_starts_with($key, $prefix . '.')) {
            return false;
        }
    }

    return true;
});

// Interne Links navigieren SPA-artig via wire:navigate (Livewire);
// mit data-no-navigate kann ein Aufrufer das gezielt deaktivieren.
$href = (string) $attributes->get('href', '');
$shouldNavigate = $href !== ''
    && ! $isDisabled
    && ! $attributes->has('wire:navigate')
    && ! $attributes->has('target')
    && ! $attributes->has('download')
    && ! $attributes->has('data-no-navigate')
    && ! preg_match('~^(mailto:|tel:|#|javascript:|data:)~i', $href)
    && (str_starts_with($href, '/') || str_starts_with($href, url('/')));

@endphp

@if (isset($attributes['href']))
    <a {!! $interactiveAttributes->merge(['class' => $classes]) !!}
        @if($shouldNavigate) wire:navigate @endif
        @if($title) title="{{ $title }}" @endif
        @if($isDisabled) aria-disabled="true" tabindex="-1" x-on:click.prevent.stop @endif
        >
        @if($isIcon && filled($icon))
            <i class="{{ $icon }}" aria-hidden="true"></i>
        @endif
        {{ $slot }}
    </a>
@else
    <button {!! $interactiveAttributes->merge($isIcon ? ['type' => 'button', 'class' => $classes] : ['class' => $classes]) !!}
        @if($title) title="{{ $title }}" @endif
        @if($isDisabled) disabled @endif>
        @if($isIcon && filled($icon))
            <i class="{{ $icon }}" aria-hidden="true"></i>
        @endif
        {{ $slot }}
    </button>
@endif
