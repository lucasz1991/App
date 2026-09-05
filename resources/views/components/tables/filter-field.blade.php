@props([
    'label',
    'icon' => null,
])

<div {{ $attributes->class(['rt-filter-field min-w-0']) }}>
    <span class="rt-filter-field__label">
        @if ($icon)
            <i class="{{ $icon }}" aria-hidden="true"></i>
        @endif
        <span>{{ $label }}</span>
    </span>
    <div class="rt-filter-field__control">
        {{ $slot }}
    </div>
</div>
