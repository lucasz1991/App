@props([
    'file',
    'readOnly' => false,
    'canMove' => false,
    'dragHintId' => null,
])

@php
    $canManageFile = ! $readOnly && (
        $file->is_owned_by_auth_user
        || auth()->user()?->isAdmin()
        || auth()->user()?->can('files.manage')
        || auth()->user()?->can('users.edit')
    );

    /*
     * Blade-Direktiven in Attributwerten anonymer Components werden nicht
     * erneut kompiliert. Den vollständigen Alpine-Ausdruck deshalb bereits
     * hier mit sicher serialisierten Texten und einer numerischen ID bauen.
     */
    $fileDeleteConfirmationAction = '$dispatch("rt-confirm", {'
        . 'title: ' . \Illuminate\Support\Js::from(__('app.delete')) . ','
        . 'message: ' . \Illuminate\Support\Js::from(__('app.delete_file_confirm')) . ','
        . 'variant: "destructive",'
        . 'confirmLabel: ' . \Illuminate\Support\Js::from(__('app.delete')) . ','
        . 'action: () => $wire.deleteFile(' . (int) $file->id . ')'
        . '})';
@endphp

<article
    x-data="{
        actionsOpen: false,
        pointerFine: window.matchMedia('(hover: hover) and (pointer: fine)').matches,
    }"
    class="rt-file-card group relative aspect-square overflow-hidden rounded-xl bg-rt-surface-muted text-rt-text shadow-rt-xs ring-1 ring-rt-border/70 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-sm hover:ring-rt-accent/35 focus-within:ring-2 focus-within:ring-rt-accent/45 dark:bg-rt-dark-surface-muted dark:text-white dark:ring-rt-dark-border/70 dark:hover:ring-rt-dark-accent/35 {{ $canMove ? 'cursor-grab active:cursor-grabbing' : '' }}"
    tabindex="0"
    role="group"
    aria-label="{{ __('app.file_actions_label', ['name' => $file->name]) }}"
    :aria-expanded="actionsOpen ? 'true' : 'false'"
    @mouseenter="if (pointerFine) actionsOpen = true"
    @mouseleave="if (pointerFine && !$root.contains(document.activeElement)) actionsOpen = false"
    @focusin="actionsOpen = true"
    @focusout="if (!$root.contains($event.relatedTarget)) actionsOpen = false"
    @click="if (!pointerFine && !$event.target.closest('[data-file-action]')) actionsOpen = true"
    @click.outside="if (!pointerFine) actionsOpen = false"
    @contextmenu.prevent.stop="
        actionsOpen = false;
        $nextTick(() => $root.querySelector('[data-file-menu-trigger]')?.click())
    "
    @keydown.escape.stop="actionsOpen = false; $root.focus()"
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
        class="absolute inset-0 h-full w-full select-none @if($file->is_image) object-cover @else object-contain p-[22%] @endif"
    >

    <div class="pointer-events-none absolute inset-x-0 bottom-0 z-10 bg-gradient-to-t from-slate-950/90 via-slate-950/64 to-transparent px-2 pb-2 pt-7 text-left">
        <div class="line-clamp-2 break-words text-[11px] font-semibold leading-snug text-white" title="{{ $file->name }}">
            {{ $file->name }}
        </div>
        <div class="mt-0.5 text-[10px] font-medium text-white/70" title="{{ $file->getMimeTypeForHumans() }}">
            {{ $file->size_formatted }}
        </div>
    </div>

    @if($file->expires_at)
        <span
            data-rt-tone="{{ $file->isExpired() ? 'red' : 'slate' }}"
            class="rt-ui-badge absolute left-2 top-2 z-30 rounded-md border px-1.5 py-0.5 text-[10px] shadow-rt-xs {{ $file->isExpired() ? 'border-red-200 bg-red-100 text-red-700 dark:border-red-500/40 dark:bg-red-500/20 dark:text-red-200' : 'border-white/70 bg-white/90 text-slate-600 dark:border-white/10 dark:bg-slate-900/85 dark:text-slate-200' }}"
        >
            {{ $file->isExpired() ? __('app.expired') : __('app.expires_on', ['date' => $file->expires_at->format('d.m.Y')]) }}
        </span>
    @endif

    @if($canManageFile)
        <div class="absolute right-2 top-2 z-40">
            <x-dropdown align="right" width="48" :offset="6">
                <x-slot name="trigger">
                    <button
                        type="button"
                        data-file-action
                        data-file-menu-trigger
                        draggable="false"
                        class="rt-file-card-menu-trigger pointer-events-none translate-y-[-0.2rem] opacity-0 transition duration-200 ease-out group-hover:pointer-events-auto group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:translate-y-0 group-focus-within:opacity-100"
                        title="{{ __('app.file_actions') }}"
                        aria-label="{{ __('app.file_actions') }}"
                    >
                        <i class="far fa-ellipsis-v" aria-hidden="true"></i>
                    </button>
                </x-slot>
                <x-slot name="content">
                    <x-dropdown-link wire:click.prevent="editFile({{ $file->id }})">
                        <i class="far fa-pen mr-2 w-4 text-center" aria-hidden="true"></i>
                        {{ __('app.edit') }}
                    </x-dropdown-link>
                    <x-dropdown-link
                        x-on:click.prevent="{{ $fileDeleteConfirmationAction }}"
                        tone="danger"
                    >
                        <i class="far fa-trash-alt mr-2 w-4 text-center" aria-hidden="true"></i>
                        {{ __('app.delete') }}
                    </x-dropdown-link>
                </x-slot>
            </x-dropdown>
        </div>
    @endif

    <div
        x-show="actionsOpen"
        x-cloak
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="opacity-0 scale-[0.98]"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-[0.985]"
        class="rt-file-card-action-overlay absolute inset-0 z-20 items-end justify-center rounded-xl pb-12"
        @click.stop
    >
        <div class="flex items-center justify-center gap-2" role="toolbar" aria-label="{{ __('app.file_actions') }}">
            <button
                type="button"
                data-file-action
                draggable="false"
                @click.stop="actionsOpen = false; window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                title="{{ __('app.preview') }}"
                class="rt-file-card-action"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span class="sr-only">{{ __('app.preview') }}</span>
            </button>

            <button
                type="button"
                data-file-action
                draggable="false"
                wire:click="downloadFile({{ $file->id }})"
                @click.stop="actionsOpen = false"
                title="{{ __('app.download') }}"
                class="rt-file-card-action"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span class="sr-only">{{ __('app.download') }}</span>
            </button>
        </div>
    </div>
</article>
