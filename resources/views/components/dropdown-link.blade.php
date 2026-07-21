@props(['can' => true, 'tone' => 'default'])

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

$classes = 'rt-ui-dropdown-link inline-flex items-center w-full px-4 py-2 text-start text-sm leading-5 focus:outline-none transition-all duration-300 ease-rt-spring ' . $toneClasses;
if ($isDisabled) {
    $classes .= ' opacity-50 cursor-not-allowed';
} else {
    $classes .= ' hover:bg-rt-surface-muted dark:hover:bg-rt-dark-nav-hover hover:text-rt-red dark:hover:text-rt-dark-accent focus:bg-rt-surface-muted dark:focus:bg-rt-dark-nav-hover';
}

$title = $isDeniedByCan
    ? __('app.no_permission')
    : $attributes->get('title');

$attributesWithoutTitle = $attributes->except('title');

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
@endphp

<a
    data-rt-tone="{{ $tone }}"
    {{ $sanitizedAttributes->merge(['class' => $classes]) }}
    @if($title) title="{{ $title }}" @endif
    @if($isDisabled) aria-disabled="true" disabled tabindex="-1" x-on:click.prevent.stop @endif
>
    {{ $slot }}
</a>
