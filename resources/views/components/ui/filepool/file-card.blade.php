@props(['file', 'readOnly' => false])

<div    x-data="{ isHovered: false }"
        class="group relative h-full rounded-lg p-1.5 text-rt-text transition-all duration-300 ease-rt-spring hover:bg-rt-accent/5 hover:ring-1 hover:ring-rt-accent/30 dark:text-white dark:hover:bg-rt-dark-accent/10 dark:hover:ring-rt-dark-accent/30 cardgroup"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
        @touchstart="isHovered = true"
        >
    <div class="w-full">
        <img src="{{ $file->icon_or_thumbnail }}" alt="{{ $file->name }}" class="w-full !aspect-square @if($file->is_image) rounded-md object-cover @else object-contain p-3 @endif">
    </div>
    <div class="mt-1.5 space-y-0.5 text-center">
        <div class="line-clamp-2 break-words text-xs leading-snug text-rt-text dark:text-rt-dark-text" title="{{ $file->name }}">{{ $file->name }}</div>
        <div class="text-[11px] text-rt-muted dark:text-rt-dark-muted" title="{{ $file->getMimeTypeForHumans() }}">
            <span>{{ $file->size_formatted }}</span>
        </div>
        @if($file->expires_at)
            @if(!$file->isExpired())
                <span data-rt-tone="slate" class="rt-ui-badge absolute left-1 top-1 z-10 rounded border border-rt-border bg-rt-surface-muted px-1.5 py-0.5 text-[10px] text-rt-muted dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted" :class="{ 'hidden': isHovered }">{{ __('app.expires_on', ['date' => $file->expires_at->format('d.m.Y')]) }}</span>
            @else
                <span data-rt-tone="red" class="rt-ui-badge absolute left-1 top-1 z-10 rounded border border-red-200 bg-red-100 px-1.5 py-0.5 text-[10px] text-red-600 dark:border-red-500/40 dark:bg-red-500/20 dark:text-red-300" :class="{ 'hidden': isHovered }">{{ __('app.expired') }}</span>
            @endif
        @endif
    </div>
    <div class="absolute inset-x-1 top-1 z-20" x-show="isHovered" x-transition.opacity x-cloak>
        <div class="flex flex-wrap items-center justify-center gap-0.5 rounded-full bg-rt-surface/90 px-1 py-0.5 shadow-rt-sm ring-1 ring-rt-border/60 backdrop-blur-sm dark:bg-rt-dark-surface/90 dark:ring-rt-dark-border/60">
            <button
                    type="button"
                    @click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                    title="{{ __('app.preview') }}"
                    class="rt-ui-icon-button rounded-full p-1.5 text-rt-muted hover:bg-rt-nav-hover hover:text-rt-red dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-accent transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span class="sr-only">{{ __('app.preview') }}</span>
            </button>
            <button wire:click="downloadFile({{ $file->id }})"
                    title="{{ __('app.download') }}"
                    class="rt-ui-icon-button rounded-full p-1.5 text-rt-muted hover:bg-rt-nav-hover hover:text-rt-red dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-accent transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span class="sr-only">{{ __('app.download') }}</span>
            </button>
            @if(!$readOnly && $file->is_owned_by_auth_user)
                <button wire:click="editFile({{ $file->id }})"
                        title="{{ __('app.edit') }}"
                        class="rt-ui-icon-button rounded-full p-1.5 text-rt-muted hover:bg-rt-nav-hover hover:text-rt-red dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-accent transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                    <span class="sr-only">{{ __('app.edit') }}</span>
                </button>
                <button wire:click="deleteFile({{ $file->id }})"
                        wire:confirm="{{ __('app.delete_file_confirm') }}"
                        title="{{ __('app.delete') }}"
                        class="rt-ui-icon-button rt-ui-icon-button-danger rounded-full p-1.5 text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-500/10 dark:hover:text-red-300 transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    <span class="sr-only">{{ __('app.delete') }}</span>
                </button>
            @endif
        </div>
    </div>
</div>
