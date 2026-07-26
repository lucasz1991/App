@props([
    'file',
    'readOnly' => false,
    'canMove' => false,
    'dragHintId' => null,
])

<article
    x-data="{
        actionsOpen: false,
        pointerFine: window.matchMedia('(hover: hover) and (pointer: fine)').matches,
    }"
    class="rt-file-card group relative h-full rounded-lg p-1.5 text-rt-text transition-all duration-300 ease-rt-spring hover:bg-rt-accent/5 hover:ring-1 hover:ring-rt-accent/30 focus-within:ring-2 focus-within:ring-rt-accent/40 dark:text-white dark:hover:bg-rt-dark-accent/10 dark:hover:ring-rt-dark-accent/30 cardgroup {{ $canMove ? 'cursor-grab active:cursor-grabbing' : '' }}"
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
    @keydown.escape.stop="actionsOpen = false; $root.focus()"
    @if($canMove)
        draggable="true"
        aria-grabbed="false"
        @if($dragHintId) aria-describedby="{{ $dragHintId }}" @endif
        @dragstart="startFileDrag($event, {{ $file->id }})"
        @dragend="endFileDrag($event)"
    @endif
>
    <div class="w-full">
        <img
            src="{{ $file->icon_or_thumbnail }}"
            alt=""
            draggable="false"
            class="w-full !aspect-square select-none @if($file->is_image) rounded-md object-cover @else object-contain p-3 @endif"
        >
    </div>

    <div class="mt-1.5 space-y-0.5 text-center">
        <div class="line-clamp-2 break-words text-xs leading-snug text-rt-text dark:text-rt-dark-text" title="{{ $file->name }}">
            {{ $file->name }}
        </div>
        <div class="text-[11px] text-rt-muted dark:text-rt-dark-muted" title="{{ $file->getMimeTypeForHumans() }}">
            <span>{{ $file->size_formatted }}</span>
        </div>

        @if($file->expires_at)
            @if(!$file->isExpired())
                <span
                    data-rt-tone="slate"
                    class="rt-ui-badge absolute left-1 top-1 z-10 rounded border border-rt-border bg-rt-surface-muted px-1.5 py-0.5 text-[10px] text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted"
                    :class="{ 'invisible': actionsOpen }"
                >
                    {{ __('app.expires_on', ['date' => $file->expires_at->format('d.m.Y')]) }}
                </span>
            @else
                <span
                    data-rt-tone="red"
                    class="rt-ui-badge absolute left-1 top-1 z-10 rounded border border-red-200 bg-red-100 px-1.5 py-0.5 text-[10px] text-red-600 dark:border-red-500/40 dark:bg-red-500/20 dark:text-red-300"
                    :class="{ 'invisible': actionsOpen }"
                >
                    {{ __('app.expired') }}
                </span>
            @endif
        @endif
    </div>

    <div
        x-show="actionsOpen"
        x-cloak
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="opacity-0 scale-[0.97]"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-[0.98]"
        class="rt-file-card-action-overlay absolute inset-0 z-20 items-center justify-center rounded-lg p-1.5"
        @click.stop
    >
        <div class="grid grid-cols-2 gap-1.5" role="toolbar" aria-label="{{ __('app.file_actions') }}">
            <button
                type="button"
                data-file-action
                draggable="false"
                @click.stop="actionsOpen = false; window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                title="{{ __('app.preview') }}"
                class="rt-file-card-action"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
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
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span class="sr-only">{{ __('app.download') }}</span>
            </button>

            @if(!$readOnly && $file->is_owned_by_auth_user)
                <button
                    type="button"
                    data-file-action
                    draggable="false"
                    wire:click="editFile({{ $file->id }})"
                    @click.stop="actionsOpen = false"
                    title="{{ __('app.edit') }}"
                    class="rt-file-card-action"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                    </svg>
                    <span class="sr-only">{{ __('app.edit') }}</span>
                </button>

                <button
                    type="button"
                    data-file-action
                    draggable="false"
                    wire:click="deleteFile({{ $file->id }})"
                    wire:confirm="{{ __('app.delete_file_confirm') }}"
                    @click.stop="actionsOpen = false"
                    title="{{ __('app.delete') }}"
                    class="rt-file-card-action rt-file-card-action-danger"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        <line x1="10" y1="11" x2="10" y2="17"/>
                        <line x1="14" y1="11" x2="14" y2="17"/>
                    </svg>
                    <span class="sr-only">{{ __('app.delete') }}</span>
                </button>
            @endif
        </div>
    </div>
</article>
