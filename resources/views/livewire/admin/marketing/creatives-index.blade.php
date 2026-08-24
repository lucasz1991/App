@section('title', 'Marketing · Motive')

<x-ui.page
    title="Marketing-Motive"
    eyebrow="Management"
    description="Jobanzeigen und Informationsmotive zentral gestalten, prüfen und als PNG ausgeben."
    :auto-intro="false"
    :show-back="false"
    content-class="space-y-5"
    data-marketing-creatives
    :x-data="$errors->has('bundle') ? '{ importOpen: true }' : '{ importOpen: false }'"
>
    <x-slot:actions>
        <button
            type="button"
            x-on:click="importOpen = true; $nextTick(() => $refs.bundleInput?.focus())"
            aria-label="Motivpaket importieren"
            class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:border-rt-red/40 hover:text-rt-red dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text"
        >
            <i data-feather="upload-cloud" class="h-4 w-4" aria-hidden="true"></i>
            <span class="hidden sm:inline">Importieren</span>
        </button>
        <button
            type="button"
            wire:click="create('info')"
            wire:loading.attr="disabled"
            aria-label="Info-Motiv erstellen"
            class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:border-rt-red/40 hover:text-rt-red disabled:opacity-50 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text"
        >
            <i data-feather="info" class="h-4 w-4" aria-hidden="true"></i>
            <span class="hidden sm:inline">Info-Motiv</span>
        </button>
        <button
            type="button"
            wire:click="create('job')"
            wire:loading.attr="disabled"
            aria-label="Job-Motiv erstellen"
            class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-3.5 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:opacity-50"
        >
            <i data-feather="briefcase" class="h-4 w-4" aria-hidden="true"></i>
            <span class="hidden sm:inline">Job-Motiv</span>
        </button>
    </x-slot:actions>

    @if (session('marketing_import_success'))
        <div class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200" role="status">
            <i data-feather="check-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
            <p>{{ session('marketing_import_success') }}</p>
        </div>
    @endif

    <x-ui.surface.card padding="p-4 sm:p-5" data-marketing-media-source>
        <form wire:submit="saveMediaFolder" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-end">
            <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.8fr)] lg:items-end">
                <label class="block min-w-0">
                    <span class="text-xs font-bold uppercase tracking-[0.1em] text-rt-red">Bildquelle für Motive</span>
                    <select
                        wire:model="mediaFolderId"
                        class="mt-2 min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm"
                        aria-describedby="marketing-media-source-help marketing-media-source-error"
                    >
                        <option value="">Grundverzeichnis (alle Ordner)</option>
                        @if ($mediaSourceInvalid)
                            <option value="{{ $mediaFolderId }}" disabled>Ordner #{{ $mediaFolderId }} · nicht mehr verfügbar</option>
                        @endif
                        @foreach ($mediaFolderTree as $folder)
                            <option value="{{ $folder['id'] }}">
                                {{ str_repeat('— ', (int) ($folder['depth'] ?? 0)) }}{{ $folder['path'] }}
                            </option>
                        @endforeach
                    </select>
                    <p id="marketing-media-source-help" class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        Der gewählte Ordner und alle Unterordner bilden die schreibgeschützte Bilderbibliothek im Editor.
                    </p>
                    @error('mediaFolderId')
                        <p id="marketing-media-source-error" class="mt-2 text-xs font-semibold leading-5 text-rt-red" role="alert">{{ $message }}</p>
                    @enderror
                </label>

                <div class="min-w-0 rounded-xl border border-rt-border bg-rt-surface-muted px-4 py-3 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
                    <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-rt-soft">Aktuell freigegeben</p>
                    <p class="mt-1 break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $mediaSourcePath }}</p>
                    <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">
                        @if ($mediaAssetTruncated)
                            {{ $mediaAssetVisibleCount }} von {{ $mediaAssetCount }} Bildern im Editor geladen · Für weitere Treffer einen Unterordner wählen
                        @else
                            {{ $mediaAssetCount }} {{ $mediaAssetCount === 1 ? 'Bild' : 'Bilder' }} im Editor verfügbar
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 xl:justify-end">
                <a
                    href="{{ $mediaFilesUrl }}"
                    wire:navigate
                    class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-muted transition hover:border-rt-red/30 hover:text-rt-red dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted"
                >
                    <i data-feather="folder" class="h-4 w-4" aria-hidden="true"></i>
                    Dateien öffnen
                </a>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveMediaFolder"
                    class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white transition hover:bg-rt-red-dark disabled:opacity-50"
                >
                    <i data-feather="check" class="h-4 w-4" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="saveMediaFolder">Bildquelle speichern</span>
                    <span wire:loading wire:target="saveMediaFolder">Wird geprüft …</span>
                </button>
            </div>
        </form>

        @if ($mediaSourceInvalid)
            <div class="mt-4 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-200" role="alert" data-marketing-media-source-invalid>
                <i data-feather="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
                <p><strong>Die gespeicherte Bildquelle ist nicht mehr verfügbar.</strong> Der Editor zeigt aus Sicherheitsgründen keine Bilder. Wähle das Grundverzeichnis oder einen vorhandenen Ordner und speichere erneut.</p>
            </div>
        @endif
    </x-ui.surface.card>

    <x-ui.surface.card padding="p-4">
        <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem]" aria-label="Motive filtern">
            <label class="relative block">
                <span class="sr-only">Motive durchsuchen</span>
                <i data-feather="search" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-rt-soft" aria-hidden="true"></i>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Titel durchsuchen …"
                    class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control py-2 pl-10 pr-3 text-base text-rt-text placeholder:text-rt-soft focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm"
                >
            </label>

            <label>
                <span class="sr-only">Motivtyp</span>
                <select wire:model.live="type" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm">
                    <option value="">Alle Typen</option>
                    <option value="job">Jobs</option>
                    <option value="info">Informationen</option>
                </select>
            </label>

            <label>
                <span class="sr-only">Status</span>
                <select wire:model.live="status" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm">
                    <option value="">Alle Status</option>
                    <option value="draft">Entwurf</option>
                    <option value="approved">Freigegeben</option>
                    <option value="archived">Archiviert</option>
                </select>
            </label>
        </div>
    </x-ui.surface.card>

    <div wire:loading.delay class="w-full rounded-xl border border-rt-border bg-rt-surface p-5 text-sm text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted" role="status">
        Motive werden aktualisiert …
    </div>

    <div wire:loading.remove>
        @if ($creatives->isEmpty())
            <x-ui.surface.card padding="p-8 sm:p-12" class="text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rt-red dark:bg-rose-500/10 dark:text-rose-300">
                    <i data-feather="layout" class="h-5 w-5" aria-hidden="true"></i>
                </span>
                <h2 class="mt-4 text-lg font-semibold">Noch keine passenden Motive</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Erstelle ein Job- oder Info-Motiv. Story, Post und Webformat werden direkt gemeinsam angelegt.</p>
            </x-ui.surface.card>
        @else
            <div class="grid gap-4 xl:grid-cols-2" data-marketing-creative-list>
                @foreach ($creatives as $creative)
                    @php
                        $typeValue = $creative->type instanceof \BackedEnum ? $creative->type->value : (string) $creative->type;
                        $statusValue = $creative->status instanceof \BackedEnum ? $creative->status->value : (string) $creative->status;
                        $statusLabel = ['draft' => 'Entwurf', 'approved' => 'Freigegeben', 'archived' => 'Archiviert'][$statusValue] ?? $statusValue;
                        $preferredPreviewFormat = data_get($creative->shared_content, 'preferred_preview_format', 'post');
                        if (! array_key_exists($preferredPreviewFormat, $previewFormats)) {
                            $preferredPreviewFormat = 'post';
                        }
                        $creativePreviewSources = collect($previewFormats)->mapWithKeys(
                            fn (array $preview, string $format): array => [$format => array_merge($preview, [
                                'url' => route('admin.marketing.creatives.preview', [$creative, $format]),
                                'editUrl' => route('admin.marketing.creatives.editor', [
                                    'creative' => $creative,
                                    'format' => $format,
                                    'open' => 1,
                                ]),
                            ])]
                        )->all();
                    @endphp
                    <article wire:key="marketing-creative-{{ $creative->public_id }}" class="group rounded-2xl border border-rt-border bg-rt-surface p-5 shadow-rt-xs transition hover:border-rt-red/25 hover:shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface" data-marketing-creative-card>
                        <x-ui.page-builder.preview-card
                            class="mb-4"
                            :title="$creative->title"
                            :description="($typeValue === 'job' ? 'Jobmotiv' : 'Informationsmotiv').' · zuletzt geändert '.$creative->updated_at?->diffForHumans()"
                            :status="$statusLabel"
                            :sources="$creativePreviewSources"
                            :default-source="$preferredPreviewFormat"
                            :edit-url="route('admin.marketing.creatives.editor', [
                                'creative' => $creative,
                                'format' => $preferredPreviewFormat,
                                'open' => 1,
                            ])"
                            :edit-label="$statusValue === 'archived' ? 'Im Vollbild ansehen' : 'Im Vollbild bearbeiten'"
                        />

                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-red dark:bg-rt-dark-surface-muted dark:text-rose-300">
                                <i data-feather="{{ $typeValue === 'job' ? 'briefcase' : 'info' }}" class="h-5 w-5" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.08em]',
                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $statusValue === 'draft',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $statusValue === 'approved',
                                        'bg-slate-100 text-slate-600 dark:bg-slate-500/10 dark:text-slate-300' => $statusValue === 'archived',
                                    ])>{{ $statusLabel }}</span>
                                    <span class="text-xs font-medium text-rt-soft">{{ $typeValue === 'job' ? 'Job' : 'Information' }}</span>
                                </div>
                                <h2 class="mt-2 truncate text-base font-semibold text-rt-text dark:text-rt-dark-text" title="{{ $creative->title }}">{{ $creative->title }}</h2>
                                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Zuletzt geändert {{ $creative->updated_at?->diffForHumans() }}</p>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2" aria-label="Verfügbare Formate">
                            @foreach (['story' => 'Story 9:16', 'post' => 'Post 1:1', 'web' => 'Web 1,91:1'] as $format => $label)
                                <span class="rounded-lg border border-rt-border/80 bg-rt-surface-muted px-2.5 py-1.5 text-[11px] font-semibold text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">{{ $label }}</span>
                            @endforeach
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-rt-border/70 pt-4 dark:border-rt-dark-border/70">
                            <a href="{{ route('admin.marketing.creatives.editor', ['creative' => $creative, 'open' => 1]) }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-3.5 text-sm font-semibold text-white transition hover:bg-rt-red-dark">
                                <i data-feather="{{ $statusValue === 'archived' ? 'eye' : 'edit-3' }}" class="h-4 w-4" aria-hidden="true"></i>
                                {{ $statusValue === 'archived' ? 'Ansehen' : 'Bearbeiten' }}
                            </a>
                            <button type="button" wire:click="duplicate('{{ $creative->public_id }}')" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border px-3 text-sm font-semibold text-rt-muted transition hover:border-rt-red/30 hover:text-rt-red disabled:opacity-50 dark:border-rt-dark-border dark:text-rt-dark-muted">
                                <i data-feather="copy" class="h-4 w-4" aria-hidden="true"></i>
                                Duplizieren
                            </button>
                            <a
                                href="{{ route('admin.marketing.creatives.export', $creative) }}"
                                class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border px-3 text-sm font-semibold text-rt-muted transition hover:border-rt-red/30 hover:text-rt-red dark:border-rt-dark-border dark:text-rt-dark-muted"
                                title="Editierbares Motivpaket mit Bildern exportieren"
                            >
                                <i data-feather="download-cloud" class="h-4 w-4" aria-hidden="true"></i>
                                Exportieren
                            </a>
                            @if ($statusValue === 'draft')
                                <button type="button" wire:click="approve('{{ $creative->public_id }}')" wire:confirm="Dieses Motiv ohne Entwurfs-Wasserzeichen freigeben?" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-emerald-200 px-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-50 disabled:opacity-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-500/10">
                                    <i data-feather="check-circle" class="h-4 w-4" aria-hidden="true"></i>
                                    Freigeben
                                </button>
                            @endif
                            @if ($statusValue !== 'archived')
                                <button type="button" wire:click="archive('{{ $creative->public_id }}')" wire:confirm="Dieses Motiv archivieren?" wire:loading.attr="disabled" class="ml-auto inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl text-rt-soft transition hover:bg-rose-50 hover:text-rt-red disabled:opacity-50 dark:hover:bg-rose-500/10" title="Archivieren" aria-label="{{ $creative->title }} archivieren">
                                    <i data-feather="archive" class="h-4 w-4" aria-hidden="true"></i>
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="deleteCreative('{{ $creative->public_id }}')"
                                wire:confirm="Dieses Motiv endgültig aus dem Marketing-Studio entfernen? Es ist danach nicht mehr im Editor, in Vorschauen oder Exporten erreichbar."
                                wire:loading.attr="disabled"
                                @class([
                                    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl text-rt-soft transition hover:bg-rose-50 hover:text-rt-red disabled:opacity-50 dark:hover:bg-rose-500/10',
                                    'ml-auto' => $statusValue === 'archived',
                                ])
                                title="Motiv entfernen"
                                aria-label="{{ $creative->title }} aus dem Marketing-Studio entfernen"
                            >
                                <i data-feather="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($creatives->hasPages())
                <div class="mt-5">{{ $creatives->links() }}</div>
            @endif
        @endif
    </div>

    <div
        x-cloak
        x-show="importOpen"
        x-transition.opacity
        x-on:keydown.escape.window="importOpen = false"
        class="fixed inset-0 z-[120] flex items-center justify-center p-4 sm:p-6"
        role="dialog"
        aria-modal="true"
        aria-labelledby="marketing-import-title"
    >
        <button type="button" class="absolute inset-0 bg-slate-950/70 backdrop-blur-xl" x-on:click="importOpen = false" aria-label="Importdialog schließen"></button>

        <section
            x-show="importOpen"
            x-transition
            x-trap.inert.noscroll="importOpen"
            class="relative w-full max-w-xl overflow-hidden rounded-3xl border border-white/15 bg-white shadow-2xl dark:bg-[#0b1118]"
        >
            <header class="flex items-start gap-4 border-b border-rt-border px-5 py-5 dark:border-rt-dark-border sm:px-6">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rose-50 text-rt-red dark:bg-rose-500/10 dark:text-rose-300">
                    <i data-feather="package" class="h-5 w-5" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-rt-red">Portabler Entwurf</p>
                    <h2 id="marketing-import-title" class="mt-1 text-xl font-semibold text-rt-text dark:text-white">Motivpaket importieren</h2>
                    <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Story, Post, Web und verwendete Bilder werden gemeinsam geprüft. Der Import wird immer als neuer, ungeprüfter Entwurf angelegt.</p>
                </div>
                <button type="button" x-on:click="importOpen = false" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl text-rt-soft transition hover:bg-rt-surface-muted hover:text-rt-text dark:hover:bg-white/5 dark:hover:text-white" aria-label="Schließen">
                    <i data-feather="x" class="h-5 w-5" aria-hidden="true"></i>
                </button>
            </header>

            <form method="POST" action="{{ route('admin.marketing.creatives.import') }}" enctype="multipart/form-data" class="p-5 sm:p-6">
                @csrf
                <label class="group flex min-h-52 cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-rt-border bg-rt-surface-muted px-6 py-8 text-center transition hover:border-rt-red/45 hover:bg-rose-50/60 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:hover:bg-rose-500/5">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-rt-red shadow-rt-xs dark:bg-white/5">
                        <i data-feather="file-plus" class="h-6 w-6" aria-hidden="true"></i>
                    </span>
                    <strong class="mt-4 text-base text-rt-text dark:text-white">RailTime-Motivpaket auswählen</strong>
                    <span class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">JSON · maximal 32 MiB</span>
                    <input x-ref="bundleInput" type="file" name="bundle" accept=".json,application/json" required class="mt-5 block w-full max-w-sm text-sm text-rt-muted file:mr-3 file:min-h-11 file:rounded-xl file:border-0 file:bg-rt-red file:px-4 file:font-semibold file:text-white hover:file:bg-rt-red-dark dark:text-rt-dark-muted">
                </label>

                @error('bundle')
                    <p class="mt-3 flex items-start gap-2 text-sm font-semibold text-rt-red" role="alert">
                        <i data-feather="alert-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
                        {{ $message }}
                    </p>
                @enderror

                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" x-on:click="importOpen = false" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-muted transition hover:text-rt-text dark:border-rt-dark-border dark:text-rt-dark-muted dark:hover:text-white">
                        <i data-feather="x" class="h-4 w-4" aria-hidden="true"></i>
                        Abbrechen
                    </button>
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-5 text-sm font-semibold text-white transition hover:bg-rt-red-dark">
                        <i data-feather="upload-cloud" class="h-4 w-4" aria-hidden="true"></i>
                        Prüfen &amp; importieren
                    </button>
                </div>
            </form>
        </section>
    </div>
</x-ui.page>
