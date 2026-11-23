{{--
    Kennzahl-Kachel (ersetzt ui.dashboard.stat-card und ui.dashboard.operational-stat).

    variant="card"    Grosse Kachel mit Icon-Plakette links, Wert gross (vorher stat-card).
                      compactMobile verkleinert Kachel und Schrift unter sm.
    variant="row"     Zeilenkachel, mobil Label/Wert nebeneinander (vorher operational-stat).
    variant="minimal" Wie row, ohne Flaeche und mit Farbpunkt statt Icon.

    Icon: Slot (beliebiges Markup, z. B. FA im Kundenprofil) oder Prop icon (Feather-Name).
    tone: neutral | brand (alias red) | success | warning | danger.
--}}
@props([
    'label',
    'value',
    'icon' => null,
    'tone' => 'neutral',
    'variant' => 'card',
    'compactMobile' => false,
])

@php
    $resolvedVariant = in_array($variant, ['card', 'row', 'minimal'], true) ? $variant : 'card';
    $resolvedTone = match ($tone) {
        'red', 'brand' => 'brand',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        default => 'neutral',
    };
    $toneClasses = match ($resolvedTone) {
        'brand' => 'bg-rt-accent-soft text-rt-accent ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/10 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/15',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/10 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/15',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-600/10 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/15',
        default => 'bg-rt-surface-muted text-rt-muted ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70',
    };
    $dotClasses = match ($resolvedTone) {
        'brand' => 'bg-rt-red',
        'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-rose-500',
        default => 'bg-slate-400 dark:bg-slate-500',
    };
    $hasSlotIcon = trim((string) $slot) !== '';
@endphp

@if ($resolvedVariant === 'card')
    <article
        {{ $attributes->class([
            'rt-ui-surface relative flex min-w-0 items-center overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 motion-safe:transition motion-safe:duration-300 motion-safe:ease-rt-spring motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-rt-md dark:bg-rt-dark-surface dark:ring-rt-dark-border/70',
            'min-h-[4.75rem] px-2.5 py-3 sm:min-h-[6rem] sm:px-4 sm:py-4' => $compactMobile,
            'min-h-[6rem] px-4 py-4' => ! $compactMobile,
        ]) }}
        data-rt-tone="{{ $resolvedTone }}"
        data-dashboard-stat-variant="card"
        data-rt-glow
    >
        <div class="flex w-full min-w-0 items-center gap-2.5 sm:gap-3.5">
            <span class="rt-ui-badge flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset sm:h-10 sm:w-10 {{ $toneClasses }}" aria-hidden="true">
                @if ($hasSlotIcon)
                    {{ $slot }}
                @elseif ($icon)
                    <i data-feather="{{ $icon }}" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                @endif
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
@else
    @php
        $isMinimal = $resolvedVariant === 'minimal';
    @endphp
    <article
        {{ $attributes->class([
            'flex min-h-16 items-center gap-3 px-3.5 py-3 sm:min-h-20 sm:px-4',
            'rounded-xl bg-transparent ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70' => $isMinimal,
            'rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70' => ! $isMinimal,
        ]) }}
        data-rt-tone="{{ $resolvedTone }}"
        data-dashboard-stat-variant="{{ $resolvedVariant }}"
    >
        @if ($isMinimal)
            <span class="flex h-5 w-3 shrink-0 items-center justify-center" aria-hidden="true">
                <span class="h-2 w-2 rounded-sm {{ $dotClasses }}"></span>
            </span>
        @else
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset {{ $toneClasses }}" aria-hidden="true">
                @if ($hasSlotIcon)
                    {{ $slot }}
                @else
                    <i data-feather="{{ $icon ?: 'activity' }}" class="h-4 w-4"></i>
                @endif
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
@endif
