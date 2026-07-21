@props(['type' => 'info', 'title' => null])

@php
    $styles = match ($type) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100',
        default => 'border-rt-border bg-rt-surface-muted text-rt-text dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white',
    };
@endphp

<div data-rt-tone="{{ $type }}" {{ $attributes->class("rt-ui-alert flex gap-3 rounded-xl border p-4 {$styles}") }} role="alert">
    <span class="mt-0.5 shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 10v5M12 7h.01"/></svg>
    </span>
    <div class="min-w-0 text-sm">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
</div>
