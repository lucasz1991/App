@section('title', $moduleData['title'])

@php
    $toneClasses = [
        'red' => [
            'soft' => 'border-rose-200 bg-rose-50 text-rt-red dark:border-rose-900 dark:bg-rose-950 dark:text-rose-300',
            'icon' => 'bg-rt-red text-white shadow-[0_12px_28px_-12px_rgba(228,0,43,.8)]',
            'bar' => 'bg-rt-red',
        ],
        'amber' => [
            'soft' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300',
            'icon' => 'bg-amber-500 text-white shadow-[0_12px_28px_-12px_rgba(245,158,11,.8)]',
            'bar' => 'bg-amber-500',
        ],
        'blue' => [
            'soft' => 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-300',
            'icon' => 'bg-sky-600 text-white shadow-[0_12px_28px_-12px_rgba(2,132,199,.8)]',
            'bar' => 'bg-sky-600',
        ],
        'emerald' => [
            'soft' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
            'icon' => 'bg-emerald-600 text-white shadow-[0_12px_28px_-12px_rgba(5,150,105,.8)]',
            'bar' => 'bg-emerald-600',
        ],
    ];
    $tone = $toneClasses[$moduleData['tone']] ?? $toneClasses['red'];
@endphp

<x-ui.page
    :title="$moduleData['title']"
    :eyebrow="__('app.operations_preview')"
    :description="$moduleData['description']"
    class="rt-operational-page !space-y-4 !py-4 lg:!py-5"
    data-operational-module="{{ $moduleData['slug'] }}"
>
    <x-slot:actions>
        <span class="rt-operational-demo-badge inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
            <span class="h-2 w-2 rounded-full bg-amber-500"></span>
            {{ __('app.static_demo_data') }}
        </span>
    </x-slot:actions>

    <div class="rt-operational-notice rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 shadow-rt-xs dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100" role="note" data-preview-notice data-anim="fade">
        <div class="flex items-start gap-3">
            <i data-feather="info" class="mt-0.5 h-4 w-4 shrink-0"></i>
            <div>
                <p class="font-semibold">{{ __('app.preview_not_productive') }}</p>
                <p class="rt-operational-notice-copy mt-0.5 text-xs leading-5 text-amber-800 dark:text-amber-200">{{ __('app.preview_no_database') }}</p>
            </div>
        </div>
    </div>

    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(19rem,.6fr)]">
        <article class="rt-admin-panel rt-operational-main overflow-hidden rounded-2xl" data-anim="fade-up">
            <header class="rt-operational-header relative border-b border-slate-200 px-4 py-4 sm:px-6 sm:py-5 dark:border-slate-700">
                <span class="absolute inset-x-0 top-0 h-1 {{ $tone['bar'] }}"></span>
                <div class="flex flex-wrap items-start justify-between gap-3 sm:gap-5">
                    <div class="flex min-w-0 items-center gap-3 sm:gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl sm:h-12 sm:w-12 sm:rounded-2xl {{ $tone['icon'] }}">
                            <i data-feather="{{ $moduleData['icon'] }}" class="h-5 w-5"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-soft sm:text-xs dark:text-rt-dark-soft">{{ $moduleData['metric_label'] }}</p>
                            <p class="mt-0.5 text-3xl font-semibold tracking-[-0.045em] tabular-nums text-rt-text sm:mt-1 sm:text-4xl dark:text-white">{{ $moduleData['metric'] }}</p>
                        </div>
                    </div>
                    <span class="rt-operational-tone inline-flex rounded-lg border px-2.5 py-1.5 text-xs font-semibold {{ $tone['soft'] }}" data-operational-tone="{{ $moduleData['tone'] }}">{{ $moduleData['badge'] }}</span>
                </div>
            </header>

            <dl class="rt-operational-stats grid grid-cols-3 gap-px bg-slate-200 dark:bg-slate-700" aria-label="{{ __('app.preview_summary') }}">
                @foreach ($moduleData['stats'] as $stat)
                    <div class="rt-operational-stat bg-rt-surface px-3 py-3 sm:px-5 sm:py-4 dark:bg-[#111827]" data-operational-stat>
                        <dt class="truncate text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft dark:text-rt-dark-soft" title="{{ $stat['label'] }}">{{ $stat['label'] }}</dt>
                        <dd class="mt-1.5 text-lg font-semibold tabular-nums text-rt-text sm:mt-2 sm:text-2xl dark:text-white">{{ $stat['value'] }}</dd>
                        <p class="mt-1 hidden truncate text-xs text-rt-muted sm:block dark:text-rt-dark-muted" title="{{ $stat['detail'] }}">{{ $stat['detail'] }}</p>
                    </div>
                @endforeach
            </dl>

            <div class="px-4 py-4 sm:px-6 sm:py-5">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-base font-semibold text-rt-text dark:text-white">{{ $moduleData['list_title'] }}</h2>
                    <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.demo') }}</span>
                </div>

                <div class="rt-operational-list mt-3 divide-y divide-slate-200 sm:mt-4 dark:divide-slate-700">
                    @foreach ($moduleData['items'] as $item)
                        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 py-3 sm:grid-cols-[5rem_minmax(0,1fr)_auto] sm:gap-3 sm:py-4" data-operational-item>
                            <span class="order-1 text-xs font-semibold tabular-nums text-rt-red sm:order-none dark:text-rt-red-light">{{ $item['eyebrow'] }}</span>
                            <div class="order-3 col-span-2 min-w-0 sm:order-none sm:col-span-1">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-white" title="{{ $item['title'] }}">{{ $item['title'] }}</p>
                                <p class="mt-0.5 truncate text-xs text-rt-muted sm:mt-1 dark:text-rt-dark-muted" title="{{ $item['meta'] }}">{{ $item['meta'] }}</p>
                            </div>
                            <span class="rt-operational-item-status order-2 w-fit justify-self-end rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-semibold text-slate-700 sm:order-none sm:justify-self-auto sm:px-2.5 sm:py-1.5 sm:text-[11px] dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $item['status'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </article>

        <aside class="rt-admin-panel rt-operational-sidebar h-fit rounded-2xl p-4 sm:p-6" data-anim="fade-up" data-anim-delay="0.12">
            <p class="rt-operational-eyebrow text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.operations_preview') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.preview_modules') }}</h2>
            <p class="mt-2 hidden text-xs leading-5 text-rt-muted sm:block dark:text-rt-dark-muted">{{ __('app.preview_modules_hint') }}</p>

            <nav class="mt-4 grid grid-cols-2 gap-2 sm:mt-5 sm:grid-cols-1" aria-label="{{ __('app.operations_preview_navigation') }}">
                @foreach ($allModules as $previewModule)
                    <a
                        href="{{ route('admin.operations.preview', ['module' => $previewModule['slug']]) }}"
                        wire:navigate
                        data-operational-nav-link
                        @class([
                            'rt-operational-nav-link group flex items-center gap-2.5 rounded-xl border px-2.5 py-2.5 text-xs font-semibold transition duration-200 sm:gap-3 sm:px-3 sm:py-3 sm:text-sm',
                            'rt-operational-nav-link-active border-rt-red bg-rose-50 text-rt-red dark:bg-rose-950 dark:text-rose-200' => $previewModule['slug'] === $moduleData['slug'],
                            'border-slate-200 bg-slate-50 text-rt-text hover:border-slate-300 hover:bg-rt-surface dark:border-slate-700 dark:bg-slate-800 dark:text-white dark:hover:border-slate-600 dark:hover:bg-slate-700' => $previewModule['slug'] !== $moduleData['slug'],
                        ])
                        @if ($previewModule['slug'] === $moduleData['slug']) aria-current="page" @endif
                    >
                        <span class="rt-operational-nav-icon flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-current/20 bg-rt-surface/70 sm:h-8 sm:w-8 dark:bg-slate-900/60">
                            <i data-feather="{{ $previewModule['icon'] }}" class="h-4 w-4"></i>
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ $previewModule['title'] }}</span>
                        <i data-feather="chevron-right" class="hidden h-4 w-4 shrink-0 opacity-50 transition group-hover:translate-x-0.5 sm:block"></i>
                    </a>
                @endforeach
            </nav>

            <div class="rt-operational-schema mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-xs leading-5 text-rt-muted sm:mt-5 dark:border-slate-700 dark:bg-slate-800 dark:text-rt-dark-muted">
                {{ __('app.preview_schema_later') }}
            </div>
        </aside>
    </section>
</x-ui.page>
