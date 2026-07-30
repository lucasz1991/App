<div class="relative" wire:loading.class="cursor-wait">
    @php
        $teamsTotal = collect($grouped['teams'])->sum(fn ($entry) => $entry['files']->count());
        $total = $managedDocuments->count() + $grouped['personal']->count() + $grouped['company']->count() + $teamsTotal;
    @endphp

    <x-ui.page
        :title="__('app.download_center')"
        :eyebrow="__('app.downloads')"
        :description="__('app.downloads_intro')"
    >
        <x-slot:actions>
            {{-- Suche ueber alle Bereiche: Bei vielen Freigaben war das
                 Durchscrollen bisher der einzige Weg zu einer Datei. --}}
            <div class="relative w-full sm:w-64">
                <i class="far fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                <input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    placeholder="{{ __('app.search_files_placeholder') }}"
                    aria-label="{{ __('app.search_files_placeholder') }}"
                    class="h-11 w-full rounded-xl border border-rt-border bg-rt-control pl-9 pr-9 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition placeholder:text-rt-soft focus:border-rt-accent sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:placeholder:text-rt-dark-soft"
                >
                <button
                    type="button"
                    x-cloak
                    wire:click="clearSearch"
                    @if(! $isFiltered) hidden @endif
                    class="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted"
                    title="{{ __('app.reset') }}"
                    aria-label="{{ __('app.reset') }}"
                >
                    <i class="far fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <span class="inline-flex shrink-0 items-center gap-2 rounded-full bg-rt-surface px-3 py-1.5 text-xs font-semibold text-rt-text shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                <i class="fad fa-cloud-arrow-down text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                <span wire:loading.class="opacity-50" wire:target="search">
                    {{ trans_choice('app.files_count', $total, ['count' => number_format($total, 0, ',', '.')]) }}
                </span>
            </span>
        </x-slot:actions>

        @if($managedDocuments->isNotEmpty())
            <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up">
                <header class="flex items-start gap-3 border-b border-rt-border/60 bg-rt-surface-muted px-5 py-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted sm:px-6">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="fad fa-file-check" aria-hidden="true"></i>
                    </span>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-accent dark:text-rt-dark-accent">{{ __('app.always_current') }}</p>
                        <h2 class="mt-0.5 font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.managed_documents') }}</h2>
                        <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.managed_documents_user_hint') }}</p>
                    </div>
                </header>
                <div class="grid gap-px bg-rt-border/60 dark:bg-rt-dark-border/60 lg:grid-cols-2">
                    @foreach($managedDocuments as $document)
                        @php($version = $document->currentVersion)
                        @php($file = $version?->file)
                        <article class="flex min-w-0 flex-col bg-rt-surface p-5 dark:bg-rt-dark-surface sm:p-6" wire:key="user-managed-document-{{ $document->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $document->title }}</h3>
                                        <span class="rounded-md bg-emerald-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">v{{ $version->version_number }}</span>
                                    </div>
                                    @if($document->description)
                                        <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $document->description }}</p>
                                    @endif
                                </div>
                                <i class="far fa-badge-check shrink-0 text-lg text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                            </div>
                            <div class="mt-auto pt-5">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-rt-muted dark:text-rt-dark-muted">
                                    <span>{{ __('app.last_update') }}: {{ $document->content_updated_at?->format('d.m.Y H:i') }}</span>
                                    <span>{{ $file?->size_formatted }}</span>
                                </div>
                                <a href="{{ route('managed-documents.download', $document) }}" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-rt-red px-4 py-2.5 text-sm font-semibold text-white shadow-rt-xs transition hover:-translate-y-0.5 hover:bg-rt-red-dark active:translate-y-0 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/40">
                                    <i class="far fa-download" aria-hidden="true"></i>{{ __('app.download_current_version') }}
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($total === 0)
            {{-- Zwei verschiedene Leerzustaende: "nichts da" ist eine andere
                 Aussage als "nichts gefunden" und braucht einen anderen Ausweg. --}}
            <div class="flex w-full flex-col items-center gap-3 rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/60 py-16 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40" data-anim="fade-up">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-surface text-rt-soft shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-soft dark:ring-rt-dark-border/60">
                    <i class="fad {{ $isFiltered ? 'fa-magnifying-glass' : 'fa-folder-open' }} text-2xl" aria-hidden="true"></i>
                </span>

                @if ($isFiltered)
                    <p class="text-sm text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.no_files_for_search', ['term' => $search]) }}
                    </p>
                    <button
                        type="button"
                        wire:click="clearSearch"
                        class="inline-flex items-center gap-2 rounded-lg bg-rt-surface px-3.5 py-2 text-xs font-semibold text-rt-text shadow-rt-xs ring-1 ring-rt-border/60 transition hover:bg-rt-surface-muted dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 dark:hover:bg-rt-dark-surface-muted"
                    >
                        <i class="far fa-arrow-rotate-left" aria-hidden="true"></i>
                        {{ __('app.reset') }}
                    </button>
                @else
                    <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_downloads_available') }}</p>
                @endif
            </div>
        @else
            {{-- Fuer Sie bereitgestellt (persoenlicher Pool) --}}
            @if ($grouped['personal']->isNotEmpty())
                <x-ui.filepool.download-group
                    :title="__('app.provided_for_you')"
                    icon="fad fa-user-check"
                    :files="$grouped['personal']"
                />
            @endif

            {{-- Firmen-Freigaben (Teams) --}}
            @if ($grouped['company']->isNotEmpty())
                <x-ui.filepool.download-group
                    :title="__('app.company_files')"
                    icon="fad fa-building"
                    :files="$grouped['company']"
                    delay="0.05"
                />
            @endif

            {{-- Team-Downloads --}}
            @foreach ($grouped['teams'] as $entry)
                <x-ui.filepool.download-group
                    :title="$entry['team']->name"
                    :eyebrow="__('app.my_teams')"
                    icon="fad fa-users"
                    :files="$entry['files']"
                    delay="0.05"
                />
            @endforeach
        @endif
    </x-ui.page>
</div>
