@props(['type' => 'info', 'title' => null])

@php
    $styles = match ($type) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100',
        default => 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-400/30 dark:bg-sky-400/10 dark:text-sky-100',
    };
    $iconStyles = match ($type) {
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200',
        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-400/15 dark:text-amber-200',
        'danger' => 'bg-rose-100 text-rose-700 dark:bg-rose-400/15 dark:text-rose-200',
        default => 'bg-sky-100 text-sky-700 dark:bg-sky-400/15 dark:text-sky-200',
    };
    $icon = match ($type) {
        'success' => 'fa-check-circle',
        'warning' => 'fa-exclamation-triangle',
        'danger' => 'fa-times-circle',
        default => 'fa-info-circle',
    };
@endphp

<div data-rt-tone="{{ $type }}" {{ $attributes->class("rt-ui-alert flex gap-3 rounded-xl border p-4 shadow-rt-xs {$styles}") }} role="alert">
    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $iconStyles }}" aria-hidden="true">
        <i class="far {{ $icon }}"></i>
    </span>
    <div class="min-w-0 text-sm">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
</div>
