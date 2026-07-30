@props([
    'title',
    'description' => null,
    'metric' => null,
    'metricLabel' => null,
    'icon' => 'activity',
    'tone' => 'neutral',
    'badge' => null,
    'href' => null,
    'navigate' => true,
    'actionLabel' => null,
    'preview' => false,
])

@php
    $toneClasses = match ($tone) {
        'brand' => [
            'bar' => 'bg-rt-red',
            'icon' => 'bg-rt-red/10 text-rt-red ring-rt-red/15 dark:bg-rt-red/15 dark:text-rt-red-light dark:ring-rt-red/25',
            'wash' => 'bg-rt-red/10',
        ],
        'success' => [
            'bar' => 'bg-emerald-500',
            'icon' => 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/15 dark:text-emerald-300',
            'wash' => 'bg-emerald-500/10',
        ],
        'warning' => [
            'bar' => 'bg-amber-500',
            'icon' => 'bg-amber-500/10 text-amber-700 ring-amber-500/15 dark:text-amber-300',
            'wash' => 'bg-amber-500/10',
        ],
        default => [
            'bar' => 'bg-slate-400 dark:bg-slate-500',
            'icon' => 'bg-rt-surface-muted text-rt-muted ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70',
            'wash' => 'bg-slate-400/10',
        ],
    };
    $resolvedActionLabel = $actionLabel ?: __('app.open');
@endphp

<article
    {{ $attributes->class('group relative flex min-h-48 min-w-0 flex-col overflow-hidden rounded-[1.4rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-md sm:min-h-52 sm:p-5 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70') }}
    data-dashboard-focus-card
    data-dashboard-focus-tone="{{ $tone }}"
    data-dashboard-data-source="{{ $preview ? 'preview' : 'live' }}"
    @if ($preview) data-dashboard-focus-preview="true" @endif
>
    <span class="absolute inset-x-0 top-0 h-1 {{ $toneClasses['bar'] }}" aria-hidden="true"></span>
    <span class="pointer-events-none absolute -right-16 -top-20 h-44 w-44 rounded-full blur-3xl {{ $toneClasses['wash'] }}" aria-hidden="true"></span>

    @if (filled($href))
        <a
            href="{{ $href }}"
            @if ($navigate) wire:navigate @endif
            class="absolute inset-0 z-10 rounded-[1.4rem] outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red"
            aria-label="{{ $title }} · {{ $resolvedActionLabel }}"
        ></a>
    @endif

    <div class="relative flex items-start justify-between gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset {{ $toneClasses['icon'] }}" aria-hidden="true">
            <i data-feather="{{ $icon }}" class="h-5 w-5"></i>
        </span>

        @if ($preview || filled($badge))
            <span class="flex min-w-0 max-w-[76%] flex-wrap justify-end gap-1.5">
                @if ($preview)
                    <span
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-amber-500/10 px-2.5 py-1 text-[10px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-500/20 dark:text-amber-300"
                        data-dashboard-preview-label
                    >
                        <i data-feather="eye" class="h-3 w-3" aria-hidden="true"></i>
                        {{ __('app.demo_preview') }}
                    </span>
                @endif

                @if (filled($badge))
                    <span class="max-w-full truncate rounded-full bg-rt-surface-muted px-2.5 py-1 text-[10px] font-semibold text-rt-muted ring-1 ring-inset ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70">
                        {{ $badge }}
                    </span>
                @endif
            </span>
        @endif
    </div>

    @if (filled($metric))
        <p class="relative mt-5 text-3xl font-bold leading-none tabular-nums tracking-[-0.05em] text-rt-text sm:text-4xl dark:text-white">
            {{ $metric }}
        </p>
    @endif

    @if (filled($metricLabel))
        <p class="relative mt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-rt-muted dark:text-rt-dark-muted">
            {{ $metricLabel }}
        </p>
    @endif

    <div class="relative mt-4">
        <h3 class="text-base font-bold tracking-[-0.02em] text-rt-text dark:text-white">{{ $title }}</h3>
        @if (filled($description))
            <p class="mt-1 text-pretty text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $description }}</p>
        @endif
    </div>

    <div class="relative mt-auto flex items-center justify-between gap-3 pt-4 text-xs font-semibold {{ filled($href) ? 'text-rt-red dark:text-rt-red-light' : 'text-rt-soft dark:text-rt-dark-soft' }}">
        <span>{{ filled($href) ? $resolvedActionLabel : ($preview ? __('app.demo_preview') : __('app.no_database_connection')) }}</span>
        <i data-feather="{{ filled($href) ? 'arrow-up-right' : 'minus' }}" class="h-4 w-4 transition duration-200 group-hover:translate-x-0.5" aria-hidden="true"></i>
    </div>
</article>
