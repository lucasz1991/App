@props([
    'stats',
    'href' => null,
])

@php
    $available = (bool) ($stats['available'] ?? false);
    $total = (int) ($stats['total'] ?? 0);
    $ready = (int) ($stats['ready'] ?? 0);
    $pending = (int) ($stats['pending'] ?? 0);
    $blocked = (int) ($stats['blocked'] ?? 0);

    if (! $available) {
        $state = 'unavailable';
        $summary = __('app.device_management_unavailable');
    } elseif ($total === 0) {
        $state = 'neutral';
        $summary = __('app.personal_device_none');
    } else {
        $state = $blocked > 0 ? 'danger' : ($pending > 0 ? 'warning' : 'success');
        $summary = __('app.personal_device_summary', [
            'total' => number_format($total, 0, ',', '.'),
            'ready' => number_format($ready, 0, ',', '.'),
            'pending' => number_format($pending, 0, ',', '.'),
            'blocked' => number_format($blocked, 0, ',', '.'),
        ]);
    }
@endphp

<article
    {{ $attributes->class('min-w-0 border-y border-rt-border/80 py-5 sm:py-6 dark:border-rt-dark-border/80') }}
    aria-labelledby="personal-device-widget-title"
    data-dashboard-device-widget
    data-dashboard-device-scope="personal"
    data-dashboard-device-available="{{ $available ? 'true' : 'false' }}"
    data-dashboard-device-total="{{ $total }}"
    data-dashboard-device-ready="{{ $ready }}"
    data-dashboard-device-pending="{{ $pending }}"
    data-dashboard-device-blocked="{{ $blocked }}"
>
    <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between sm:gap-8">
        <header class="min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red dark:text-rt-dark-accent">
                {{ __('app.personal_device_dashboard_eyebrow') }}
            </p>
            <h2 id="personal-device-widget-title" class="mt-1.5 text-xl font-semibold tracking-[-0.03em] text-rt-text dark:text-white">
                {{ __('app.personal_device_dashboard_title') }}
            </h2>
            <p class="mt-1 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.personal_device_dashboard_description') }}
            </p>
            <p @class([
                'mt-2 flex min-w-0 items-start gap-2 text-xs font-semibold leading-5',
                'text-rt-muted dark:text-rt-dark-muted' => in_array($state, ['unavailable', 'neutral'], true),
                'text-emerald-700 dark:text-emerald-300' => $state === 'success',
                'text-amber-700 dark:text-amber-300' => $state === 'warning',
                'text-red-700 dark:text-red-300' => $state === 'danger',
            ]) role="status">
                <span @class([
                    'mt-[0.4rem] h-1.5 w-1.5 shrink-0 rounded-full',
                    'bg-slate-400' => in_array($state, ['unavailable', 'neutral'], true),
                    'bg-emerald-500' => $state === 'success',
                    'bg-amber-500' => $state === 'warning',
                    'bg-red-500' => $state === 'danger',
                ]) aria-hidden="true"></span>
                <span>{{ $summary }}</span>
            </p>
        </header>

        @if ($available && filled($href))
            <a
                href="{{ $href }}"
                wire:navigate
                class="inline-flex min-h-11 w-full shrink-0 items-center justify-center rounded-md bg-rt-text px-4 py-2 text-sm font-semibold text-white outline-none transition hover:bg-slate-700 active:scale-[0.98] focus-visible:ring-2 focus-visible:ring-rt-red/35 focus-visible:ring-offset-2 dark:bg-slate-700 dark:text-white dark:hover:bg-slate-600 dark:focus-visible:ring-offset-rt-dark-surface sm:w-auto"
                data-dashboard-action="devices-mine"
            >
                {{ __('app.personal_device_open') }}
            </a>
        @else
            <span
                class="inline-flex min-h-11 w-full shrink-0 cursor-not-allowed items-center justify-center rounded-md bg-slate-100 px-4 py-2 text-sm font-semibold text-rt-soft dark:bg-slate-800 dark:text-rt-dark-soft sm:w-auto"
                aria-disabled="true"
                data-dashboard-device-unavailable
            >
                {{ __('app.personal_device_open') }}
            </span>
        @endif
    </div>
</article>
