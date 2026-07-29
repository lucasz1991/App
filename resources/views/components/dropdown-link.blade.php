@props([
    'can' => true,
    'tone' => 'default',
    'confirmMethod' => null,
    'confirmArguments' => [],
    'confirmTitle' => null,
    'confirmMessage' => null,
    'confirmVariant' => 'destructive',
    'confirmLabel' => null,
])

@php
if (is_string($can) && $can !== '') {
    $isAllowed = auth()->check() && auth()->user()->can($can);
} else {
    $isAllowed = (bool) $can;
}

$isDeniedByCan = ! $isAllowed;
$isDisabled = isset($attributes['disabled']) || $isDeniedByCan;

$toneClasses = match ($tone) {
    'danger' => 'text-red-700 dark:text-red-300',
    'warning' => 'text-amber-700 dark:text-amber-300',
    'success' => 'text-emerald-700 dark:text-emerald-300',
    default => 'text-rt-text dark:text-rt-dark-text',
};

$classes = 'rt-ui-dropdown-link inline-flex min-h-10 w-full items-center gap-2.5 rounded-[0.7rem] px-3 py-2 text-start text-sm leading-5 focus:outline-none transition-all duration-200 ease-rt-spring ' . $toneClasses;
if ($isDisabled) {
    $classes .= ' opacity-50 cursor-not-allowed';
} else {
    $classes .= ' hover:bg-rt-surface-muted dark:hover:bg-rt-dark-nav-hover hover:text-rt-red dark:hover:text-rt-dark-accent focus:bg-rt-surface-muted dark:focus:bg-rt-dark-nav-hover';
}

$title = $isDeniedByCan
    ? __('app.no_permission')
    : $attributes->get('title');

$attributesWithoutTitle = $attributes->except('title');

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

$sanitizedAttributes = $attributesWithoutTitle->filter(function ($value, $key) use ($isDisabled) {
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

<a
    data-rt-tone="{{ $tone }}"
    data-rt-dropdown-item
    @if($shouldNavigate) wire:navigate @endif
    {{ $sanitizedAttributes->merge(['class' => $classes]) }}
    @if($title) title="{{ $title }}" @endif
    @if($isDisabled) aria-disabled="true" disabled tabindex="-1" x-on:click.prevent.stop @endif
>
    {{ $slot }}
</a>
