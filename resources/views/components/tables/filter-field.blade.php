@props([
    'label',
    'icon' => null,
    'for' => null,
])

<div {{ $attributes->class(['rt-filter-field min-w-0']) }}>
    @if (filled($for))
        <label class="rt-filter-field__label" for="{{ $for }}">
    @else
        <span class="rt-filter-field__label">
    @endif
        @if ($icon)
            <i class="{{ $icon }}" aria-hidden="true"></i>
        @endif
        <span>{{ $label }}</span>
    @if (filled($for))
        </label>
    @else
        </span>
    @endif
    <div class="rt-filter-field__control">
        {{ $slot }}
    </div>
</div>
