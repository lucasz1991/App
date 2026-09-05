@props([
    'label',
    'value' => null,
    'removable' => true,
])

@php
    $removable = filter_var($removable, FILTER_VALIDATE_BOOL);
    $description = filled($value) ? $label.': '.$value : $label;
@endphp

<button
    type="button"
    {{ $attributes->class(['rt-filter-chip']) }}
    @disabled(! $removable)
    @if ($removable) aria-label="{{ __('app.remove_filter', ['filter' => $description]) }}" @endif
    data-rt-filter-chip
>
    <span class="rt-filter-chip__label">{{ $label }}</span>
    @if (filled($value))
        <span class="rt-filter-chip__value">{{ $value }}</span>
    @endif
    @if ($removable)
        <svg class="rt-filter-chip__remove" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" d="M4.5 4.5l7 7m0-7-7 7" />
        </svg>
    @endif
</button>
