<div class="rt-topbar-search-slot">
    <form
        x-on:submit.prevent="$wire.openResults($event.currentTarget.querySelector('input')?.value || '')"
        class="flex items-center"
    >
        <x-tables.search-field
            context="topbar"
            wire:model.live.debounce.300ms="query"
            :placeholder="__('app.global_search_placeholder')"
        />
        <button type="submit" class="sr-only">{{ __('app.search') }}</button>
    </form>

    @if ($showResults)
        <div
            class="fixed inset-0 z-[70] flex items-start justify-center bg-slate-950/50 px-3 pb-4 pt-[5.25rem] backdrop-blur-sm sm:px-6 sm:pt-24"
            role="dialog"
            aria-modal="true"
            aria-labelledby="global-search-title"
            wire:click.self="closeResults"
            x-on:keydown.escape.window="$wire.closeResults()"
            data-global-search-modal
        >
            <section class="flex max-h-[min(44rem,calc(100dvh-7rem))] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-rt-surface shadow-[0_30px_90px_-30px_rgba(15,23,42,0.62)] dark:border-rt-dark-border dark:bg-rt-dark-surface">
                <header class="flex items-start justify-between gap-4 border-b border-rt-border/70 px-4 py-4 sm:px-6 dark:border-rt-dark-border/70">
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.global_search') }}</p>
                        <h2 id="global-search-title" class="mt-1 truncate text-xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                            {{ $queryReady ? __('app.search_results_for', ['query' => $query]) : __('app.global_search_hint') }}
                        </h2>
                    </div>

                    <button
                        type="button"
                        wire:click="closeResults"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-muted transition duration-200 hover:bg-rt-surface-muted hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/50 active:scale-95 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted"
                        aria-label="{{ __('app.close') }}"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <div class="overflow-y-auto px-3 py-3 sm:px-5 sm:py-5">
                    @if (! $queryReady)
                        <div class="rounded-xl bg-rt-surface-muted px-4 py-8 text-center dark:bg-rt-dark-surface-muted">
                            <svg class="mx-auto h-8 w-8 text-rt-soft dark:text-rt-dark-soft" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" />
                            </svg>
                            <p class="mt-3 text-sm font-medium text-rt-muted dark:text-rt-dark-muted">{{ __('app.global_search_minimum') }}</p>
                        </div>
                    @elseif (count($results) === 0)
                        <div class="rounded-xl bg-rt-surface-muted px-4 py-8 text-center dark:bg-rt-dark-surface-muted">
                            <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.no_search_results') }}</p>
                            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.try_another_search') }}</p>
                        </div>
                    @else
                        <div class="space-y-5">
                            @foreach (collect($results)->groupBy('group') as $group => $groupResults)
                                <section aria-labelledby="global-search-group-{{ $loop->index }}">
                                    <h3 id="global-search-group-{{ $loop->index }}" class="px-2 text-[10px] font-semibold uppercase tracking-[0.17em] text-rt-soft dark:text-rt-dark-soft">
                                        {{ $group }}
                                    </h3>
                                    <div class="mt-2 space-y-1">
                                        @foreach ($groupResults as $result)
                                            <a
                                                href="{{ $result['url'] }}"
                                                wire:navigate
                                                class="group flex min-h-14 items-center gap-3 rounded-xl px-3 py-2.5 transition duration-200 hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red/45 active:scale-[0.995] dark:hover:bg-rt-dark-surface-muted"
                                                wire:key="global-search-result-{{ md5($result['url']) }}"
                                            >
                                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-red/8 text-rt-red ring-1 ring-rt-red/10 transition duration-200 group-hover:bg-rt-red group-hover:text-white" aria-hidden="true">
                                                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 4.75h7.1a2 2 0 0 1 1.42.59l2.39 2.39a2 2 0 0 1 .59 1.42v10.1H6.75V4.75Z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 4.9V9h4.1M9.5 13h6M9.5 16h4" />
                                                    </svg>
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $result['title'] }}</span>
                                                    <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $result['description'] }}</span>
                                                </span>
                                                <svg class="h-4 w-4 shrink-0 text-rt-soft transition duration-200 group-hover:translate-x-0.5 group-hover:text-rt-red dark:text-rt-dark-soft" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                                                </svg>
                                            </a>
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </div>
    @endif
</div>
