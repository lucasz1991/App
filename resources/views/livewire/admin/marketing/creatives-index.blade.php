@section('title', 'Marketing · Motive')

<x-ui.page
    title="Marketing-Motive"
    eyebrow="Marketing"
    description="Motive und zugehörige Dateien zentral ablegen, wiederfinden und verwalten."
    :count="$creatives->total()"
    :auto-intro="false"
    :show-back="false"
    content-class="space-y-5"
    data-marketing-creatives
>
    <x-slot:actions>
        <x-ui.buttons.button-basic
            type="button"
            mode="primary"
            size="md"
            wire:click="openCreateMotive"
            wire:loading.attr="disabled"
            wire:target="openCreateMotive"
            class="min-h-11 px-4"
            aria-label="Neues Marketing-Motiv anlegen"
        >
            <i class="far fa-plus" aria-hidden="true"></i>
            <span>Motiv anlegen</span>
        </x-ui.buttons.button-basic>
    </x-slot:actions>

    <x-ui.surface.card padding="p-4 sm:p-5">
        <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_14rem]" aria-label="Marketing-Motive filtern">
            <label class="relative block min-w-0">
                <span class="sr-only">Motive durchsuchen</span>
                <i class="far fa-search pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-rt-soft" aria-hidden="true"></i>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Nach Motivnamen suchen …"
                    autocomplete="off"
                    class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control py-2.5 pl-10 pr-4 text-base text-rt-text outline-none transition placeholder:text-rt-soft focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm"
                >
            </label>

            <label class="block min-w-0">
                <span class="sr-only">Nach Motivtyp filtern</span>
                <select
                    wire:model.live="type"
                    class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text outline-none transition focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm"
                >
                    <option value="">Alle Motivtypen</option>
                    <option value="job">Job-Motive</option>
                    <option value="info">Informationsmotive</option>
                </select>
            </label>
        </div>

        <div
            wire:loading.flex.delay
            wire:target="search,type"
            class="mt-3 hidden items-center gap-2 text-xs font-semibold text-rt-muted dark:text-rt-dark-muted"
            role="status"
            aria-live="polite"
        >
            <i class="far fa-spinner-third animate-spin text-rt-red" aria-hidden="true"></i>
            Motive werden aktualisiert …
        </div>
    </x-ui.surface.card>

    @if ($creatives->isEmpty())
        <x-ui.surface.card padding="p-8 sm:p-12" class="overflow-hidden text-center">
            <div class="relative mx-auto flex h-20 w-20 items-center justify-center">
                <span class="absolute inset-0 rounded-[1.75rem] bg-rt-red/10 ring-1 ring-rt-red/15 dark:bg-rt-red/15"></span>
                <span class="absolute -right-1 -top-1 flex h-7 w-7 items-center justify-center rounded-full bg-rt-red text-white shadow-rt-sm" aria-hidden="true">
                    <i class="far fa-plus text-xs"></i>
                </span>
                <i class="fad fa-photo-video relative text-3xl text-rt-red" aria-hidden="true"></i>
            </div>

            <h2 class="mt-5 text-xl font-semibold tracking-[-0.02em] text-rt-text dark:text-rt-dark-text">
                {{ $search !== '' || $type !== '' ? 'Keine passenden Motive gefunden' : 'Noch keine Marketing-Motive' }}
            </h2>
            <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                @if ($search !== '' || $type !== '')
                    Passe Suche oder Motivtyp an, um andere Ergebnisse anzuzeigen.
                @else
                    Lege dein erstes Motiv an und füge direkt die fertigen Bilder, PDFs oder weiteren Ausgabedateien hinzu.
                @endif
            </p>

            @if ($search === '' && $type === '')
                <x-ui.buttons.button-basic
                    type="button"
                    mode="primary"
                    size="md"
                    wire:click="openCreateMotive"
                    class="mt-6 min-h-11 px-5"
                >
                    <i class="far fa-plus" aria-hidden="true"></i>
                    Erstes Motiv anlegen
                </x-ui.buttons.button-basic>
            @endif
        </x-ui.surface.card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3" data-marketing-motive-list>
            @foreach ($creatives as $creative)
                @php
                    $typeValue = $creative->type instanceof \BackedEnum ? $creative->type->value : (string) $creative->type;
                    $typeLabel = $typeValue === 'job' ? 'Job-Motiv' : 'Informationsmotiv';
                    $pool = $creative->filePool;
                    $fileCount = (int) ($pool?->files_count ?? 0);
                    $previewFile = $pool?->latestFile;
                    $previewIsImage = (bool) ($previewFile?->is_image ?? false);
                    $previewSource = $previewFile?->icon_or_thumbnail;
                @endphp

                <article
                    wire:key="marketing-motive-{{ $creative->public_id }}"
                    class="group flex min-w-0 flex-col overflow-hidden rounded-2xl border border-rt-border bg-rt-surface shadow-rt-xs transition duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/25 hover:shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface"
                    data-marketing-motive-card
                >
                    <a
                        href="{{ route('admin.marketing.creatives.files', ['creative' => $creative]) }}"
                        wire:navigate
                        class="relative block aspect-[16/10] overflow-hidden bg-rt-surface-muted focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-rt-red/25 dark:bg-rt-dark-surface-muted"
                        aria-label="Dateien von {{ $creative->title }} verwalten"
                    >
                        @if ($previewSource)
                            <img
                                src="{{ $previewSource }}"
                                alt=""
                                @class([
                                    'h-full w-full transition duration-500 ease-rt-spring group-hover:scale-[1.025]',
                                    'object-cover' => $previewIsImage,
                                    'object-contain p-12 sm:p-14' => ! $previewIsImage,
                                ])
                                loading="lazy"
                            >
                            @if ($previewIsImage)
                                <span class="pointer-events-none absolute inset-0 bg-gradient-to-t from-slate-950/30 via-transparent to-transparent" aria-hidden="true"></span>
                            @endif
                        @else
                            <span class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-rt-soft dark:text-rt-dark-soft" aria-hidden="true">
                                <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-rt-surface text-2xl text-rt-red shadow-rt-xs ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border">
                                    <i class="fad fa-folder-open"></i>
                                </span>
                                <span class="text-xs font-semibold uppercase tracking-[0.12em]">Noch ohne Dateien</span>
                            </span>
                        @endif

                        <span class="absolute left-3 top-3 inline-flex min-h-8 items-center gap-1.5 rounded-full border border-white/60 bg-white/90 px-2.5 text-[11px] font-bold uppercase tracking-[0.08em] text-slate-700 shadow-sm backdrop-blur-md dark:border-white/10 dark:bg-slate-950/80 dark:text-slate-200">
                            <i class="far {{ $typeValue === 'job' ? 'fa-briefcase' : 'fa-info-circle' }} text-rt-red" aria-hidden="true"></i>
                            {{ $typeLabel }}
                        </span>

                        <span class="absolute bottom-3 right-3 inline-flex min-h-8 items-center gap-1.5 rounded-full border border-white/60 bg-white/90 px-2.5 text-xs font-semibold text-slate-700 shadow-sm backdrop-blur-md dark:border-white/10 dark:bg-slate-950/80 dark:text-slate-200">
                            <i class="far fa-paperclip text-rt-red" aria-hidden="true"></i>
                            {{ $fileCount }} {{ $fileCount === 1 ? 'Datei' : 'Dateien' }}
                        </span>
                    </a>

                    <div class="flex min-w-0 flex-1 flex-col p-4 sm:p-5">
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-semibold tracking-[-0.015em] text-rt-text dark:text-rt-dark-text" title="{{ $creative->title }}">
                                {{ $creative->title }}
                            </h2>
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                                <i class="far fa-clock text-rt-soft" aria-hidden="true"></i>
                                Aktualisiert {{ $creative->updated_at?->diffForHumans() }}
                            </p>
                        </div>

                        <div class="mt-5 flex items-center gap-2 border-t border-rt-border/70 pt-4 dark:border-rt-dark-border/70">
                            <x-ui.buttons.button-basic
                                href="{{ route('admin.marketing.creatives.files', ['creative' => $creative]) }}"
                                mode="primary"
                                size="md"
                                class="min-h-11 min-w-0 flex-1 px-3"
                                aria-label="Dateien von {{ $creative->title }} verwalten"
                            >
                                <i class="far fa-folder-open" aria-hidden="true"></i>
                                <span class="truncate">Dateien verwalten</span>
                            </x-ui.buttons.button-basic>

                            <button
                                type="button"
                                wire:click="deleteMotive('{{ $creative->public_id }}')"
                                wire:confirm="Dieses Motiv aus der aktiven Bibliothek entfernen? Die Dateien bleiben für eine Wiederherstellung erhalten."
                                wire:loading.attr="disabled"
                                wire:target="deleteMotive('{{ $creative->public_id }}')"
                                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-transparent text-rt-soft transition hover:border-rose-200 hover:bg-rose-50 hover:text-rt-red focus:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 disabled:cursor-wait disabled:opacity-50 dark:hover:border-rose-900 dark:hover:bg-rose-500/10"
                                title="Motiv entfernen"
                                aria-label="{{ $creative->title }} entfernen"
                            >
                                <i wire:loading.remove wire:target="deleteMotive('{{ $creative->public_id }}')" class="far fa-trash-alt" aria-hidden="true"></i>
                                <i wire:loading wire:target="deleteMotive('{{ $creative->public_id }}')" class="far fa-spinner-third animate-spin" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        @if ($creatives->hasPages())
            <div>{{ $creatives->links() }}</div>
        @endif
    @endif

    <x-dialog-modal
        wire:model.live="createMotiveOpen"
        maxWidth="4xl"
        :instant="true"
        data-filepool-upload-modal
    >
        <x-slot name="title">
            <span class="flex min-w-0 items-center gap-3">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-red/10 text-rt-red dark:bg-rt-red/20 dark:text-rt-dark-accent" aria-hidden="true">
                    <i class="fad fa-photo-video"></i>
                </span>
                <span class="min-w-0">
                    <span class="block">Neues Motiv anlegen</span>
                    <span class="mt-0.5 block text-xs font-medium text-rt-muted dark:text-rt-dark-muted">Name, Typ und Dateien gemeinsam speichern</span>
                </span>
            </span>
        </x-slot>

        <x-slot name="content">
            <form id="marketing-motive-create-form" wire:submit="createMotive" class="space-y-6">
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.72fr)]">
                    <label class="block min-w-0">
                        <span class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Name des Motivs</span>
                        <span class="ml-1 text-rt-red" aria-hidden="true">*</span>
                        <input
                            type="text"
                            wire:model="motiveTitle"
                            maxlength="160"
                            required
                            autocomplete="off"
                            placeholder="z. B. Wagenmeister · Herbstkampagne"
                            aria-describedby="marketing-motive-title-help marketing-motive-title-error"
                            class="mt-2 min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 text-base text-rt-text outline-none transition placeholder:text-rt-soft focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm"
                        >
                        <p id="marketing-motive-title-help" class="mt-2 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Ein klarer Name macht das Motiv später schnell auffindbar.</p>
                        @error('motiveTitle')
                            <p id="marketing-motive-title-error" class="mt-2 text-sm font-semibold text-rt-red" role="alert">{{ $message }}</p>
                        @enderror
                    </label>

                    <fieldset class="min-w-0">
                        <legend class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Motivtyp</legend>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <label class="cursor-pointer">
                                <input type="radio" wire:model="motiveType" value="job" class="peer sr-only">
                                <span class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-control px-3 text-center text-sm font-semibold text-rt-muted transition hover:border-rt-red/35 peer-checked:border-rt-red peer-checked:bg-rt-red/[0.06] peer-checked:text-rt-red peer-focus-visible:ring-4 peer-focus-visible:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted">
                                    <i class="far fa-briefcase text-lg" aria-hidden="true"></i>
                                    Job-Motiv
                                </span>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" wire:model="motiveType" value="info" class="peer sr-only">
                                <span class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-control px-3 text-center text-sm font-semibold text-rt-muted transition hover:border-rt-red/35 peer-checked:border-rt-red peer-checked:bg-rt-red/[0.06] peer-checked:text-rt-red peer-focus-visible:ring-4 peer-focus-visible:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted">
                                    <i class="far fa-info-circle text-lg" aria-hidden="true"></i>
                                    Information
                                </span>
                            </label>
                        </div>
                        @error('motiveType')
                            <p class="mt-2 text-sm font-semibold text-rt-red" role="alert">{{ $message }}</p>
                        @enderror
                    </fieldset>
                </div>

                <section aria-labelledby="marketing-motive-files-title">
                    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
                        <div>
                            <h3 id="marketing-motive-files-title" class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                Dateien <span class="text-rt-red" aria-hidden="true">*</span>
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Mindestens eine, maximal 20 Dateien mit jeweils bis zu 50 MB.</p>
                        </div>
                        <span wire:loading.flex wire:target="motiveUploads" class="items-center gap-2 text-xs font-semibold text-rt-red" role="status" aria-live="polite">
                            <i class="far fa-spinner-third animate-spin" aria-hidden="true"></i>
                            Dateien werden vorbereitet …
                        </span>
                    </div>

                    <x-ui.filepool.drop-zone
                        model="motiveUploads"
                        :max-files="20"
                        :max-filesize="50"
                    />

                    @error('motiveUploads.*')
                        <p class="mt-2 flex items-start gap-2 text-sm font-semibold text-rt-red" role="alert">
                            <i class="far fa-exclamation-circle mt-0.5 shrink-0" aria-hidden="true"></i>
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </section>
            </form>
        </x-slot>

        <x-slot name="footer">
            <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.buttons.button-basic
                    type="button"
                    mode="basic"
                    size="md"
                    wire:click="cancelCreateMotive"
                    wire:loading.attr="disabled"
                    wire:target="createMotive"
                    class="min-h-11 min-w-[8.5rem]"
                >
                    <i class="far fa-times" aria-hidden="true"></i>
                    Abbrechen
                </x-ui.buttons.button-basic>

                <x-ui.buttons.button-basic
                    type="submit"
                    form="marketing-motive-create-form"
                    mode="primary"
                    size="md"
                    wire:loading.attr="disabled"
                    wire:target="createMotive,motiveUploads"
                    class="min-h-11 min-w-[10.5rem]"
                >
                    <i wire:loading.remove wire:target="createMotive" class="fad fa-cloud-upload-alt" aria-hidden="true"></i>
                    <i wire:loading wire:target="createMotive" class="far fa-spinner-third animate-spin" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="createMotive">Motiv anlegen</span>
                    <span wire:loading wire:target="createMotive">Wird gespeichert …</span>
                </x-ui.buttons.button-basic>
            </div>
        </x-slot>
    </x-dialog-modal>
</x-ui.page>
