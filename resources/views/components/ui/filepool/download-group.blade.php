@props([
    'title',
    'eyebrow' => null,
    'icon' => 'fad fa-folder',
    'files',
    'delay' => null,
    'layout' => 'grid',
    'sortBy' => 'name',
    'sortDirection' => 'asc',
])

<section
    {{ $attributes->class('min-w-0 rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-6') }}
    data-anim="fade-up"
    @if($delay) data-anim-delay="{{ $delay }}" @endif
>
    <div class="mb-5 flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
            <i class="{{ $icon }}" aria-hidden="true"></i>
        </span>
        <div class="min-w-0">
            @if($eyebrow)
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">{{ $eyebrow }}</p>
            @endif
            <h2 class="truncate font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ $title }}</h2>
            <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                {{ trans_choice('app.files_count', $files->count(), ['count' => $files->count()]) }}
            </p>
        </div>
    </div>

    @if($layout === 'details')
        <x-ui.filepool.explorer-table :sort-by="$sortBy" :sort-direction="$sortDirection">
            @foreach($files as $file)
                <x-ui.filepool.file-card :file="$file" :read-only="true" layout="details" wire:key="dl-{{ $file->id }}" />
            @endforeach
        </x-ui.filepool.explorer-table>
    @else
        <div @class(['rt-file-explorer-grid' => $layout === 'grid', 'rt-file-explorer-list' => $layout === 'list']) @if($layout === 'grid') data-anim-stagger @endif>
            @foreach($files as $file)
                <div
                    @class(['min-w-0', 'rt-file-explorer-card' => $layout === 'grid'])
                    @if($layout === 'grid') style="--rt-stagger: {{ $loop->index }}" @endif
                    wire:key="dl-{{ $file->id }}"
                >
                    <x-ui.filepool.file-card :file="$file" :read-only="true" :layout="$layout" />
                </div>
            @endforeach
        </div>
    @endif
</section>
