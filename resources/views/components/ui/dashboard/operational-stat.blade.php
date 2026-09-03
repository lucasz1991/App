@props([
    'label',
    'value',
    'icon' => 'activity',
    'tone' => 'neutral',
    'variant' => 'default',
])

@php
    $isMinimal = $variant === 'minimal';
    $toneClasses = match ($tone) {
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/10 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/15',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/10 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/15',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-600/10 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/15',
        'brand' => 'bg-rt-accent-soft text-rt-accent ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15',
        default => 'bg-rt-surface-muted text-rt-muted ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70',
    };
    $dotClasses = match ($tone) {
        'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-rose-500',
        'brand' => 'bg-rt-red',
        default => 'bg-slate-400 dark:bg-slate-500',
    };
@endphp

<article
    {{ $attributes->class([
        'flex min-h-16 items-center gap-3 px-3.5 py-3 sm:min-h-20 sm:px-4',
        'rounded-xl bg-transparent ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70' => $isMinimal,
        'rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70' => ! $isMinimal,
    ]) }}
    data-rt-tone="{{ $tone }}"
    data-dashboard-stat-variant="{{ $variant }}"
>
    @if ($isMinimal)
        <span class="flex h-5 w-3 shrink-0 items-center justify-center" aria-hidden="true">
            <span class="h-2 w-2 rounded-sm {{ $dotClasses }}"></span>
        </span>
    @else
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset {{ $toneClasses }}" aria-hidden="true">
            <i data-feather="{{ $icon }}" class="h-4 w-4"></i>
        </span>
    @endif

    <dl class="flex min-w-0 flex-1 items-center justify-between gap-3 sm:block">
        <dt class="min-w-0 text-pretty text-[0.6875rem] font-semibold uppercase leading-4 tracking-[0.1em] text-rt-muted sm:text-xs dark:text-rt-dark-muted">
            {{ $label }}
        </dt>
        <dd @class([
            'shrink-0 leading-none tabular-nums tracking-[-0.03em] text-rt-text sm:mt-1 dark:text-white',
            'text-xl font-semibold sm:text-2xl' => $isMinimal,
            'text-xl font-bold sm:text-2xl' => ! $isMinimal,
        ])>
            {{ $value }}
        </dd>
    </dl>
</article>
