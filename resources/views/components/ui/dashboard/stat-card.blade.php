@props(['label', 'value', 'tone' => 'sky'])

@php
    $toneClasses = match ($tone) {
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300',
        'red' => 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent',
        'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300',
        default => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300',
    };
@endphp

{{-- Double-bezel: aeussere Schale + innerer Kern (Designsprache v2) --}}
<div {{ $attributes->merge(['class' => 'rounded-2xl bg-rt-surface-muted p-1.5 ring-1 ring-rt-border/60 shadow-rt-sm dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60']) }}>
    <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-5 dark:bg-rt-dark-surface">
        <div class="flex items-center gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg {{ $toneClasses }}">
                {{ $slot }}
            </span>
            <div>
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ $label }}</p>
                <p class="text-3xl font-semibold tracking-tight tabular-nums text-rt-text dark:text-rt-dark-text">{{ $value }}</p>
            </div>
        </div>
    </div>
</div>
