@props([
    'label',
    'value',
    'tone' => 'sky',
    'compactMobile' => false,
])

@php
    $toneClasses = match ($tone) {
        'red', 'brand' => 'bg-rt-accent-soft text-rt-accent ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/10 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/15',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/10 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/15',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-600/10 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/15',
        default => 'bg-rt-surface-muted text-rt-muted ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70',
    };

    $cardClasses = $compactMobile
        ? 'min-h-[4.75rem] px-2.5 py-3 sm:min-h-[6rem] sm:px-4 sm:py-4'
        : 'min-h-[6rem] px-4 py-4';

    $themeTone = match ($tone) {
        'red', 'brand' => 'brand',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        default => 'neutral',
    };
@endphp

<article
    {{ $attributes->class('rt-ui-surface relative flex min-w-0 items-center overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 motion-safe:transition motion-safe:duration-300 motion-safe:ease-rt-spring motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-rt-md '.$cardClasses.' dark:bg-rt-dark-surface dark:ring-rt-dark-border/70') }}
    data-rt-tone="{{ $themeTone }}"
    data-rt-glow
>
    <div class="flex w-full min-w-0 items-center gap-2.5 sm:gap-3.5">
        <span class="rt-ui-badge flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset sm:h-10 sm:w-10 {{ $toneClasses }}" aria-hidden="true">
            {{ $slot }}
        </span>

        <dl class="min-w-0 flex-1">
            <dt @class([
                'text-pretty text-[10px] font-semibold uppercase leading-[1.25] tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted',
                'sm:text-xs' => $compactMobile,
                'sm:text-sm sm:normal-case sm:tracking-normal' => ! $compactMobile,
            ])>
                {{ $label }}
            </dt>
            <dd @class([
                'mt-1 truncate font-bold leading-none tabular-nums tracking-[-0.035em] text-rt-text dark:text-rt-dark-text',
                'text-xl sm:text-2xl' => $compactMobile,
                'text-3xl' => ! $compactMobile,
            ])>
                {{ $value }}
            </dd>
        </dl>
    </div>
</article>
