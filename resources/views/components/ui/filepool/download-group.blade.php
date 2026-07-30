@props([
    'title',
    'eyebrow' => null,
    'icon' => 'fad fa-folder',
    'files',
    'delay' => null,
])

<section
    class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
    data-anim="fade-up"
    @if($delay) data-anim-delay="{{ $delay }}" @endif
>
    <div class="mb-5 flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
            <i class="{{ $icon }}"></i>
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

    {{-- Dasselbe Raster wie im Dateimanager: Die feste Breite w-32 im
         flex-wrap liess auf schmalen Geraeten Luecken und auf breiten eine
         unruhige letzte Zeile. Das Raster fuellt gleichmaessig und bringt
         das gestaffelte Einlaufen (--rt-stagger) gleich mit. --}}
    <div class="rt-file-explorer-grid" data-anim-stagger>
        @foreach($files as $file)
            <div
                class="rt-file-explorer-card min-w-0"
                style="--rt-stagger: {{ $loop->index }}"
                wire:key="dl-{{ $file->id }}"
            >
                <x-ui.filepool.file-card :file="$file" :read-only="true" />
            </div>
        @endforeach
    </div>
</section>
