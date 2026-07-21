<div class="relative" wire:loading.class="cursor-wait">
    @php
        $teamsTotal = collect($grouped['teams'])->sum(fn ($entry) => $entry['files']->count());
        $total = $grouped['personal']->count() + $grouped['company']->count() + $teamsTotal;
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
