@props(['can' => true])

@php
if (is_string($can) && $can !== '') {
    $isAllowed = auth()->check() && auth()->user()->can($can);
} else {
    $isAllowed = (bool) $can;
}

$isDeniedByCan = ! $isAllowed;
$isDisabled = isset($attributes['disabled']) || $isDeniedByCan;

$classes = 'rt-ui-dropdown-link inline-flex items-center w-full px-4 py-2 text-start text-sm leading-5 text-rt-text dark:text-rt-dark-text focus:outline-none transition-all duration-300 ease-rt-spring';
if ($isDisabled) {
    $classes .= ' opacity-80 cursor-not-allowed';
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
    {{ $sanitizedAttributes->merge(['class' => $classes]) }}
    @if($title) title="{{ $title }}" @endif
    @if($isDisabled) aria-disabled="true" disabled tabindex="-1" x-on:click.prevent.stop @endif
>
    {{ $slot }}
</a>
