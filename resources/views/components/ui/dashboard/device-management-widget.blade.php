@props([
    'stats',
    'href' => null,
])

@php
    $available = (bool) ($stats['available'] ?? false);
    $attention = (int) ($stats['attention'] ?? 0);
    $metrics = [
        ['key' => 'total', 'label' => __('app.device_management_total'), 'dot' => 'bg-sky-500'],
        ['key' => 'assigned', 'label' => __('app.device_management_assigned'), 'dot' => 'bg-emerald-500'],
        ['key' => 'inventory', 'label' => __('app.device_management_inventory'), 'dot' => 'bg-cyan-500'],
        ['key' => 'attention', 'label' => __('app.device_management_attention'), 'dot' => $attention > 0 ? 'bg-amber-500' : 'bg-slate-400'],
    ];
@endphp

<article
    {{ $attributes->class('rt-admin-panel mt-3 overflow-hidden rounded-[1.5rem]') }}
    data-dashboard-device-widget
    data-dashboard-device-available="{{ $available ? 'true' : 'false' }}"
    data-dashboard-device-total="{{ (int) ($stats['total'] ?? 0) }}"
    data-dashboard-device-assigned="{{ (int) ($stats['assigned'] ?? 0) }}"
    data-dashboard-device-inventory="{{ (int) ($stats['inventory'] ?? 0) }}"
    data-dashboard-device-attention="{{ $attention }}"
>
    <div class="grid min-w-0 gap-4 p-4 sm:p-5 xl:grid-cols-12 xl:items-center">
        <header class="flex min-w-0 items-start gap-3 xl:col-span-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/70 dark:text-sky-300 dark:ring-sky-800" aria-hidden="true">
                <i data-feather="monitor" class="h-5 w-5"></i>
            </span>

            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red dark:text-rt-dark-accent">{{ __('app.device_management_dashboard_eyebrow') }}</p>
                <h3 class="mt-1 text-base font-semibold text-rt-text dark:text-white">{{ __('app.device_management_dashboard_title') }}</h3>
                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.device_management_dashboard_description') }}</p>
            </div>
        </header>

        <dl class="grid grid-cols-2 overflow-hidden rounded-xl border border-slate-200 bg-slate-200 sm:grid-cols-4 xl:col-span-7 dark:border-slate-700 dark:bg-slate-700" aria-label="{{ __('app.device_management_metrics') }}">
            @foreach ($metrics as $metric)
                @php($value = (int) ($stats[$metric['key']] ?? 0))
                <div class="min-w-0 bg-rt-surface px-3 py-3 dark:bg-rt-dark-surface">
                    <dt class="flex min-w-0 items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $metric['dot'] }}" aria-hidden="true"></span>
                        <span class="truncate">{{ $metric['label'] }}</span>
                    </dt>
                    <dd @class([
                        'mt-1.5 text-2xl font-bold leading-none tabular-nums tracking-[-0.04em]',
                        'text-rt-text dark:text-white' => $metric['key'] !== 'attention' || $value === 0,
                        'text-amber-700 dark:text-amber-300' => $metric['key'] === 'attention' && $value > 0,
                    ])>
                        {{ $available ? number_format($value, 0, ',', '.') : '—' }}
                    </dd>
                </div>
            @endforeach
        </dl>

        <div class="flex min-w-0 flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between xl:col-span-2 xl:flex-col xl:items-stretch">
            <span @class([
                'inline-flex min-h-9 items-center justify-center gap-2 rounded-full px-3 py-1.5 text-[11px] font-semibold ring-1 ring-inset',
                'bg-slate-100 text-rt-muted ring-slate-200 dark:bg-slate-800 dark:text-rt-dark-muted dark:ring-slate-700' => ! $available,
                'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/70 dark:text-emerald-200 dark:ring-emerald-800' => $available && $attention === 0,
                'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/70 dark:text-amber-200 dark:ring-amber-800' => $available && $attention > 0,
            ]) role="status">
                <span @class([
                    'h-1.5 w-1.5 rounded-full',
                    'bg-slate-400' => ! $available,
                    'bg-emerald-500' => $available && $attention === 0,
                    'bg-amber-500' => $available && $attention > 0,
                ]) aria-hidden="true"></span>
                {{ $available
                    ? trans_choice('app.device_management_attention_status', $attention, ['count' => $attention])
                    : __('app.device_management_unavailable') }}
            </span>

            @if ($available && filled($href))
                <a
                    href="{{ $href }}"
                    wire:navigate
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-text px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 focus-visible:ring-offset-2 dark:bg-slate-700 dark:text-white dark:hover:bg-slate-600 dark:focus-visible:ring-offset-rt-dark-surface"
                    data-dashboard-action="devices-manage"
                >
                    {{ __('app.device_management_open') }}
                    <i data-feather="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                </a>
            @else
                <span
                    class="inline-flex min-h-11 cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-slate-100 px-4 py-2 text-sm font-semibold text-rt-soft ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-rt-dark-soft dark:ring-slate-700"
                    aria-disabled="true"
                    data-dashboard-device-unavailable
                >
                    {{ __('app.device_management_open') }}
                    <i data-feather="lock" class="h-4 w-4" aria-hidden="true"></i>
                </span>
            @endif
        </div>
    </div>
</article>
