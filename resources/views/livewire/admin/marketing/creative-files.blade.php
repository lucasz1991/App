@section('title', 'Marketing · '.$creativeRecord->title)

<x-ui.page
    :title="$creativeRecord->title"
    eyebrow="Marketing-Motiv"
    description="Originaldateien, Exportformate und Begleitmaterial zentral verwalten."
    :show-back="false"
    content-class="space-y-5"
    data-marketing-motive-files
>
    <x-slot:actions>
        <a
            href="{{ route('admin.marketing.creatives.index') }}"
            wire:navigate
            class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:border-rt-red/40 hover:text-rt-red dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text"
        >
            <i data-feather="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
            <span>Alle Motive</span>
        </a>
    </x-slot:actions>

    <x-ui.surface.card padding="p-4 sm:p-5">
        <form wire:submit="saveMetadata" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_14rem_auto] xl:items-end">
            <label class="block min-w-0">
                <span class="text-xs font-bold uppercase tracking-[0.1em] text-rt-red">Bezeichnung</span>
                <input
                    type="text"
                    wire:model="title"
                    maxlength="160"
                    autocomplete="off"
                    class="mt-2 min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm"
                >
                @error('title')
                    <span class="mt-2 block text-xs font-semibold text-rt-red" role="alert">{{ $message }}</span>
                @enderror
            </label>

            <label class="block">
                <span class="text-xs font-bold uppercase tracking-[0.1em] text-rt-red">Motivtyp</span>
                <select
                    wire:model="type"
                    class="mt-2 min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text focus:border-rt-red focus:ring-4 focus:ring-rt-red/10 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text sm:text-sm"
                >
                    <option value="info">Information</option>
                    <option value="job">Job</option>
                </select>
                @error('type')
                    <span class="mt-2 block text-xs font-semibold text-rt-red" role="alert">{{ $message }}</span>
                @enderror
            </label>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="saveMetadata"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:cursor-wait disabled:opacity-60"
            >
                <i data-feather="save" class="h-4 w-4" aria-hidden="true"></i>
                <span wire:loading.remove wire:target="saveMetadata">Details speichern</span>
                <span wire:loading wire:target="saveMetadata">Wird gespeichert …</span>
            </button>
        </form>
    </x-ui.surface.card>

    <x-ui.surface.card padding="p-4 sm:p-5">
        <div class="mb-4 flex flex-col gap-3 border-b border-rt-border/70 pb-4 dark:border-rt-dark-border/70 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.1em] text-rt-red">Motivdateien</p>
                <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-rt-dark-text">Dateien hochladen und organisieren</h2>
                <p class="mt-1 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    Lege hier fertige Motive, offene Arbeitsdateien und alternative Exportformate ab. Pro Upload sind bis zu 20 Dateien mit jeweils 50 MB möglich.
                </p>
            </div>

            <span class="inline-flex min-h-9 w-fit items-center gap-2 rounded-full bg-rt-surface-muted px-3 text-xs font-semibold text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                <i data-feather="hard-drive" class="h-3.5 w-3.5" aria-hidden="true"></i>
                Privater Motiv-Dateipool
            </span>
        </div>

        <livewire:tools.file-pools.manage-file-pools
            :pool-id="$filePoolId"
            :read-only="false"
            :allow-team-permissions="false"
            :key="'marketing-motive-files-'.$creativeRecord->public_id.'-'.$filePoolId"
        />
    </x-ui.surface.card>

    <x-ui.surface.card padding="p-4 sm:p-5" class="border-rose-200/80 dark:border-rose-900/70">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Motiv aus der Übersicht entfernen</h2>
                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                    Das Motiv wird nur ausgeblendet. Der private Dateipool und seine Dateien bleiben für eine Wiederherstellung erhalten.
                </p>
            </div>
            <button
                type="button"
                wire:click="deleteMotive"
                wire:confirm="Dieses Motiv aus der Marketing-Übersicht entfernen? Die Dateien bleiben erhalten."
                wire:loading.attr="disabled"
                wire:target="deleteMotive"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rose-200 px-4 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60 dark:border-rose-900 dark:text-rose-300 dark:hover:bg-rose-500/10"
            >
                <i data-feather="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                Motiv entfernen
            </button>
        </div>
    </x-ui.surface.card>
</x-ui.page>
