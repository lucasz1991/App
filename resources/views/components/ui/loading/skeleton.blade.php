@props([
    'variant' => 'page',
    'rows' => 4,
])

@php
    $resolvedVariant = in_array($variant, ['page', 'list', 'table', 'card'], true)
        ? $variant
        : 'page';
    $resolvedRows = max(2, min(8, (int) $rows));
@endphp

<div
    {{ $attributes->class('rt-loading-skeleton') }}
    role="status"
    aria-live="polite"
    aria-busy="true"
    data-rt-loading-skeleton="{{ $resolvedVariant }}"
>
    <span class="sr-only">{{ __('app.loading') }}</span>

    <div class="rt-loading-skeleton__heading" aria-hidden="true">
        <span class="rt-loading-skeleton__eyebrow"></span>
        <span class="rt-loading-skeleton__title"></span>
    </div>

    @if ($resolvedVariant === 'page')
        <div class="rt-loading-skeleton__cards" aria-hidden="true">
            @foreach (range(1, 3) as $card)
                <span class="rt-loading-skeleton__card">
                    <span class="rt-loading-skeleton__icon"></span>
                    <span class="rt-loading-skeleton__line rt-loading-skeleton__line--short"></span>
                    <span class="rt-loading-skeleton__line"></span>
                </span>
            @endforeach
        </div>
    @elseif ($resolvedVariant === 'card')
        <div class="rt-loading-skeleton__feature-card" aria-hidden="true">
            <span class="rt-loading-skeleton__icon"></span>
            <span class="rt-loading-skeleton__line rt-loading-skeleton__line--short"></span>
            <span class="rt-loading-skeleton__line"></span>
            <span class="rt-loading-skeleton__line rt-loading-skeleton__line--medium"></span>
        </div>
    @endif

    @if ($resolvedVariant !== 'card')
        <div
            class="rt-loading-skeleton__rows {{ $resolvedVariant === 'table' ? 'rt-loading-skeleton__rows--table' : '' }}"
            aria-hidden="true"
        >
            @foreach (range(1, $resolvedRows) as $row)
                <span class="rt-loading-skeleton__row">
                    <span class="rt-loading-skeleton__avatar"></span>
                    <span class="rt-loading-skeleton__row-copy">
                        <span class="rt-loading-skeleton__line {{ $row % 2 === 0 ? 'rt-loading-skeleton__line--medium' : '' }}"></span>
                        <span class="rt-loading-skeleton__line rt-loading-skeleton__line--short"></span>
                    </span>
                    <span class="rt-loading-skeleton__action"></span>
                </span>
            @endforeach
        </div>
    @endif
</div>
