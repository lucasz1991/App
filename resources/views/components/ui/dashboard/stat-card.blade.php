@props([
    'label',
    'value',
    'tone' => 'sky',
    'compactMobile' => false,
])

@php
    $toneClasses = match ($tone) {
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300',
        'red' => 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent',
        'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300',
        default => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300',
    };

    $shellClasses = $compactMobile
        ? 'rounded-xl bg-rt-surface-muted p-0.5 shadow-rt-sm ring-1 ring-rt-border/60 sm:rounded-2xl sm:p-1.5 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60'
        : 'rounded-2xl bg-rt-surface-muted p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60';

    $innerClasses = $compactMobile
        ? 'flex min-h-[5.5rem] items-center justify-center rounded-[calc(.75rem-2px)] bg-rt-surface px-1 py-2 sm:min-h-0 sm:justify-start sm:rounded-[calc(1rem-2px)] sm:p-5 dark:bg-rt-dark-surface'
        : 'rounded-[calc(1rem-2px)] bg-rt-surface p-5 dark:bg-rt-dark-surface';

    $themeTone = match ($tone) {
        'emerald' => 'green',
        'violet' => 'purple',
        default => $tone,
    };
@endphp

{{-- Double-bezel: aeussere Schale + innerer Kern (Designsprache v2) --}}
<div {{ $attributes->merge(['class' => 'rt-ui-surface-muted ' . $shellClasses]) }}>
    <div class="rt-ui-surface {{ $innerClasses }}">
        <div @class([
            'flex items-center gap-4',
            'w-full min-w-0 flex-col gap-1 text-center sm:flex-row sm:gap-4 sm:text-left' => $compactMobile,
        ])>
            <span data-rt-tone="{{ $themeTone }}" @class([
                'rt-ui-badge flex shrink-0 items-center justify-center rounded-lg',
                'h-12 w-12' => ! $compactMobile,
                'h-7 w-7 sm:h-12 sm:w-12' => $compactMobile,
                $toneClasses,
            ])>
                {{ $slot }}
            </span>
            <div class="min-w-0">
                <p @class([
                    'text-rt-muted dark:text-rt-dark-muted',
                    'text-sm' => ! $compactMobile,
                    'min-h-[1.5rem] text-[9px] font-medium leading-[1.15] sm:min-h-0 sm:text-sm sm:font-normal sm:leading-normal' => $compactMobile,
                ])>{{ $label }}</p>
                <p @class([
                    'font-semibold tracking-tight tabular-nums text-rt-text dark:text-rt-dark-text',
                    'text-3xl' => ! $compactMobile,
                    'text-lg leading-none sm:text-3xl sm:leading-normal' => $compactMobile,
                ])>{{ $value }}</p>
            </div>
        </div>
    </div>
</div>
