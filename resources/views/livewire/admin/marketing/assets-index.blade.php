@section('title', 'Marketing · Medien')

<x-ui.page
    title="Marketing-Medien"
    eyebrow="Management"
    description="Private Bilddateien für Motive verwalten. Uploads werden geprüft und nur angemeldeten Administratoren ausgeliefert."
    :auto-intro="false"
    :show-back="false"
    content-class="space-y-5"
    data-marketing-assets
>
    @include('livewire.admin.marketing.partials.navigation', ['active' => 'assets'])

    <div x-data="marketingAssetLibrary({{ \Illuminate\Support\Js::from($assetUiConfig) }})" class="space-y-5">
        <x-ui.surface.card padding="p-4 sm:p-5">
            <form
                x-on:submit.prevent="upload($event)"
                class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-center"
                data-marketing-asset-upload
            >
                <label class="group flex min-h-28 cursor-pointer items-center gap-4 rounded-xl border border-dashed border-rt-border bg-rt-surface-muted px-5 py-4 transition hover:border-rt-red/50 hover:bg-rose-50/40 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:hover:bg-rose-500/5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-rt-red shadow-rt-xs dark:bg-rt-dark-surface">
                        <i data-feather="upload-cloud" class="h-5 w-5" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-rt-text dark:text-rt-dark-text" x-text="uploading ? 'Bilder werden hochgeladen …' : 'Bilder auswählen oder hier ablegen'"></span>
                        <span class="mt-1 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">JPEG, PNG, WebP oder GIF · maximal 8 MB je Datei</span>
                    </span>
                    <input type="file" name="files" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="sr-only" x-bind:disabled="uploading" x-on:change="$el.form.requestSubmit()">
                </label>

                <div class="space-y-2" aria-live="polite">
                    <div class="flex items-center justify-between text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">
                        <span>Upload</span>
                        <span x-text="progress"></span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                        <span class="block h-full rounded-full bg-rt-red transition-[width] duration-300" x-bind:style="`width: ${progressPercent}%`"></span>
                    </div>
                    <p class="min-h-5 text-xs text-rt-muted dark:text-rt-dark-muted" x-text="message"></p>
                </div>
            </form>
        </x-ui.surface.card>

        <x-ui.surface.card padding="p-4">
            <label class="relative block max-w-xl">
                <span class="sr-only">Medien durchsuchen</span>
                <i data-feather="search" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-rt-soft" aria-hidden="true"></i>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Dateinamen durchsuchen …" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control py-2 pl-10 pr-3 text-base text-rt-text placeholder:text-rt-soft focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm">
            </label>
        </x-ui.surface.card>

        @if ($assets->isEmpty())
            <x-ui.surface.card padding="p-10" class="text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-rt-surface-muted text-rt-soft dark:bg-rt-dark-surface-muted"><i data-feather="image" class="h-5 w-5" aria-hidden="true"></i></span>
                <h2 class="mt-4 text-lg font-semibold">Noch keine passenden Medien</h2>
                <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">Lade das erste RailTime-Bild hoch oder ändere den Suchbegriff.</p>
            </x-ui.surface.card>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6" data-marketing-asset-grid>
                @foreach ($assets as $asset)
                    <article wire:key="marketing-asset-{{ $asset->public_id }}" class="group overflow-hidden rounded-2xl border border-rt-border bg-rt-surface shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface">
                        <a href="{{ route('admin.marketing.assets.show', $asset) }}" target="_blank" rel="noopener" class="relative block aspect-square overflow-hidden bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                            <img src="{{ route('admin.marketing.assets.show', $asset) }}" alt="" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
                            @if ($asset->width && $asset->height)
                                <span class="absolute bottom-2 left-2 rounded-lg bg-slate-950/75 px-2 py-1 text-[10px] font-semibold text-white backdrop-blur">{{ $asset->width }} × {{ $asset->height }}</span>
                            @endif
                        </a>
                        <div class="flex items-center gap-1 p-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-semibold text-rt-text dark:text-rt-dark-text" title="{{ $asset->original_name }}">{{ $asset->original_name }}</p>
                                <p class="mt-0.5 text-[10px] text-rt-soft">{{ number_format(((int) $asset->size) / 1024, 0, ',', '.') }} KB</p>
                            </div>
                            <label class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl text-rt-soft transition hover:bg-rt-surface-muted hover:text-rt-red dark:hover:bg-rt-dark-surface-muted" title="Datei ersetzen">
                                <span class="sr-only">{{ $asset->original_name }} ersetzen</span>
                                <i data-feather="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                    class="sr-only"
                                    x-bind:disabled="replacing === '{{ $asset->public_id }}'"
                                    x-on:change="replace('{{ $asset->public_id }}', $event)"
                                >
                            </label>
                            <button type="button" x-on:click="remove('{{ $asset->public_id }}', {{ \Illuminate\Support\Js::from($asset->original_name) }})" x-bind:disabled="deleting === '{{ $asset->public_id }}'" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-soft transition hover:bg-rose-50 hover:text-rt-red disabled:opacity-50 dark:hover:bg-rose-500/10" aria-label="{{ $asset->original_name }} löschen" title="Löschen">
                                <i data-feather="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($assets->hasPages())
                <div>{{ $assets->links() }}</div>
            @endif
        @endif
    </div>
</x-ui.page>
