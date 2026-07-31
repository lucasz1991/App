@props([
    'file',
    'readOnly' => false,
    'canMove' => false,
    'dragHintId' => null,
])

@php
    $currentUser = auth()->user();
    $isFileManager = (bool) ($currentUser?->isAdmin() || $currentUser?->can('files.manage'));

    $canManageFile = ! $readOnly && (
        $file->is_owned_by_auth_user
        || $isFileManager
        || $currentUser?->can('users.edit')
    );

    // Zusatzhuerde des Servers spiegeln: ManageFilePools::authorizeFileMutation()
    // verlangt ueber authorizeSourceFolder() zusaetzlich das Team-Recht
    // 'delete' am Ordner. Ohne diese Pruefung zeigten wir Knoepfe, die beim
    // Klick mit 403 abbrechen. Verwaltungsrechte umgehen die Matrix (wie dort).
    if ($canManageFile && ! $isFileManager && $file->folder) {
        $canManageFile = $file->folder->allowsForUser($currentUser, 'delete')
            && $file->folder->isPubliclyVisible($currentUser);
    }
@endphp

{{--
    Bedienmodell der Kachel:

      Linksklick / Enter → Vorschau. Das ist in neun von zehn Faellen die
                           gemeinte Handlung und braucht deshalb keinen Umweg
                           ueber ein Menue.
      Rechtsklick        → Menue mit allen weiteren Aktionen.
      Hover / Fokus      → derselbe Menue-Ausloeser oben rechts wird sichtbar.

    Die frueheren grossen Hover-Knoepfe (Vorschau, Herunterladen …) sind
    entfallen: Sie verdeckten genau das Vorschaubild, nach dem man sucht, und
    auf Touch gab es kein Hover, das sie haette einblenden koennen.
--}}
<article
    x-data="{
        openFileMenu() {
            this.$root.querySelector('[data-file-menu-trigger]')?.click();
        },
    }"
    class="rt-file-card group relative aspect-square overflow-hidden rounded-xl bg-rt-surface-muted text-rt-text shadow-rt-xs ring-1 ring-rt-border/70 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-sm hover:ring-rt-accent/35 focus-within:ring-2 focus-within:ring-rt-accent/45 dark:bg-rt-dark-surface-muted dark:text-white dark:ring-rt-dark-border/70 dark:hover:ring-rt-dark-accent/35 {{ $canMove ? 'cursor-grab active:cursor-grabbing' : '' }}"
    role="group"
    aria-label="{{ $file->name }}"
    @contextmenu.prevent.stop="openFileMenu()"
    @if($canMove)
        draggable="true"
        aria-grabbed="false"
        @if($dragHintId) aria-describedby="{{ $dragHintId }}" @endif
        @dragstart="startFileDrag($event, {{ $file->id }})"
        @dragend="endFileDrag($event)"
    @endif
>
    <img
        src="{{ $file->icon_or_thumbnail }}"
        alt=""
        draggable="false"
        class="pointer-events-none absolute inset-0 h-full w-full select-none @if($file->is_image) object-cover @else object-contain p-[22%] @endif"
    >

    {{-- Die ganze Kachel ist der Vorschau-Knopf. Als echtes <button> ist sie
         auch per Tabulator und Enter erreichbar; das Ziehen bleibt Sache des
         umschliessenden <article>, das die Drag-Quelle stellt. --}}
    <button
        type="button"
        data-file-preview
        draggable="false"
        title="{{ $file->name }}"
        aria-label="{{ __('app.preview') }}: {{ $file->name }}"
        @click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
        class="rt-file-card-preview absolute inset-0 z-10 block w-full text-left focus:outline-none focus-visible:outline-none {{ $canMove ? '' : 'cursor-pointer' }}"
    >
        <span class="absolute inset-x-0 bottom-0 block bg-gradient-to-t from-slate-950/90 via-slate-950/64 to-transparent px-2 pb-2 pt-7 text-left">
            <span class="line-clamp-2 block break-words text-[11px] font-semibold leading-snug text-white">
                {{ $file->name }}
            </span>
            <span class="mt-0.5 block text-[10px] font-medium text-white/70">
                {{ $file->size_formatted }}
            </span>
        </span>
    </button>

    @if($file->expires_at)
        {{-- Rein informativ: Klicks laufen zum Vorschau-Knopf durch. --}}
        <span
            data-rt-tone="{{ $file->isExpired() ? 'red' : 'slate' }}"
            class="rt-ui-badge pointer-events-none absolute left-2 top-2 z-30 rounded-md border px-1.5 py-0.5 text-[10px] shadow-rt-xs {{ $file->isExpired() ? 'border-red-200 bg-red-100 text-red-700 dark:border-red-500/40 dark:bg-red-500/20 dark:text-red-200' : 'border-white/70 bg-white/90 text-slate-600 dark:border-white/10 dark:bg-slate-900/85 dark:text-slate-200' }}"
        >
            {{ $file->isExpired() ? __('app.expired') : __('app.expires_on', ['date' => $file->expires_at->format('d.m.Y')]) }}
        </span>
    @endif

    <div class="absolute right-2 top-2 z-40">
        <x-dropdown align="right" width="56" :offset="6">
            <x-slot name="trigger">
                <button
                    type="button"
                    data-file-action
                    data-file-menu-trigger
                    draggable="false"
                    {{-- Ein-/Ausblenden steuert app.css (.rt-file-card-menu-trigger).
                         Als Utility-Klassen ging es nicht: das Legacy-Bundle
                         build/css/tailwind.min.css setzt .opacity-0 und
                         .pointer-events-none mit !important, wodurch die
                         group-hover-Varianten nie durchkamen — der Ausloeser
                         waere am Desktop unsichtbar geblieben. --}}
                    class="rt-file-card-menu-trigger"
                    title="{{ __('app.file_actions') }}"
                    aria-label="{{ __('app.file_actions_label', ['name' => $file->name]) }}"
                >
                    <i class="far fa-ellipsis-v" aria-hidden="true"></i>
                </button>
            </x-slot>
            <x-slot name="content">
                <x-dropdown-link
                    x-on:click.prevent="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                >
                    <i class="far fa-eye mr-2 w-4 text-center" aria-hidden="true"></i>
                    {{ __('app.preview') }}
                </x-dropdown-link>

                <x-dropdown-link wire:click="downloadFile({{ $file->id }})">
                    <i class="far fa-download mr-2 w-4 text-center" aria-hidden="true"></i>
                    {{ __('app.download') }}
                </x-dropdown-link>

                @if($canManageFile)
                    <x-dropdown-link wire:click.prevent="editFile({{ $file->id }})">
                        <i class="far fa-pen mr-2 w-4 text-center" aria-hidden="true"></i>
                        {{ __('app.edit') }}
                    </x-dropdown-link>

                    <x-dropdown-link
                        confirm-method="deleteFile"
                        :confirm-arguments="[(int) $file->id]"
                        :confirm-title="__('app.delete')"
                        :confirm-message="__('app.delete_file_confirm')"
                        :confirm-label="__('app.delete')"
                        tone="danger"
                    >
                        <i class="far fa-trash-alt mr-2 w-4 text-center" aria-hidden="true"></i>
                        {{ __('app.delete') }}
                    </x-dropdown-link>
                @endif
            </x-slot>
        </x-dropdown>
    </div>
</article>
