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
            <span class="inline-flex items-center gap-2 rounded-full bg-rt-surface px-3 py-1.5 text-xs font-semibold text-rt-text shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60">
                <i class="fad fa-cloud-arrow-down text-rt-accent dark:text-rt-dark-accent"></i>
                {{ trans_choice('app.files_count', $total, ['count' => number_format($total, 0, ',', '.')]) }}
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
            <div class="flex w-full flex-col items-center gap-3 rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/60 py-16 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40" data-anim="fade-up">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rt-surface text-rt-soft shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-soft dark:ring-rt-dark-border/60">
                    <i class="fad fa-folder-open text-2xl"></i>
                </span>
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_downloads_available') }}</p>
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

            {{-- Firmen-Freigaben (Rolle/Team) --}}
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
