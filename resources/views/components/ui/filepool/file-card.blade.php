@props(['file', 'readOnly' => false])

<div    x-data="{ isHovered: false }"
        class="relative overflow-hidden rounded-xl bg-rt-surface text-rt-text shadow-rt-sm ring-1 ring-rt-border/60 transition-all duration-300 ease-rt-spring dark:bg-rt-dark-surface dark:text-white dark:ring-rt-dark-border/60 mb-2 cardgroup"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
        @touchstart="isHovered = true"
        >
    <div class="transition" :class="{ 'blur-sm': isHovered }">
        <img src="{{ $file->icon_or_thumbnail }}" alt="{{ $file->name }}" class="w-full !aspect-square @if($file->is_image) object-cover @else object-contain p-6 @endif">
    </div>
    <div class="p-2 space-y-2 bg-rt-surface-muted dark:bg-rt-dark-surface-muted transition" :class="{ 'blur-sm': isHovered }">
        <div class="truncate text-sm" title="{{ $file->name }}">{{ $file->name }}</div>
        <div class="text-xs text-rt-muted dark:text-rt-dark-muted truncate w-full mb-1" title="{{ $file->getMimeTypeForHumans() }}">
            <span>{{ $file->getMimeTypeForHumans() }}</span>
        </div>
        <div class="text-xs text-rt-muted dark:text-rt-dark-muted">
            <span>{{ $file->size_formatted }}</span>
        </div>
        @if($file->expires_at)
            @if(!$file->isExpired())
                <span class="text-slate-500 dark:text-slate-300 text-xs p-1 bg-slate-100 dark:bg-slate-700 rounded border border-slate-200 dark:border-slate-600 absolute top-2" :class="{ 'hidden': isHovered }">{{ __('app.expires_on', ['date' => $file->expires_at->format('d.m.Y')]) }}</span>
            @else
                <span class="text-red-500 dark:text-red-400 text-xs p-1 bg-red-100 dark:bg-red-500/20 rounded border dark:border-red-500/40 absolute top-2" :class="{ 'hidden': isHovered }">{{ __('app.expired') }}</span>
            @endif
        @endif
    </div>
    <div class="absolute inset-0 flex items-center justify-center flex-wrap bg-rt-surface/70 dark:bg-rt-dark-canvas/75 rounded-xl" x-show="isHovered" x-collapse>
        <button
                type="button"
                @click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                title="{{ __('app.preview') }}"
                class="text-slate-600 hover:text-rt-red dark:text-slate-200 dark:hover:text-rt-red text-sm bg-slate-200 dark:bg-slate-600 rounded-full p-2 m-2 transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span class="sr-only">{{ __('app.preview') }}</span>
        </button>
        <button wire:click="downloadFile({{ $file->id }})"
                title="{{ __('app.download') }}"
                class="text-slate-600 hover:text-rt-red dark:text-slate-200 dark:hover:text-rt-red text-sm bg-slate-200 dark:bg-slate-600 rounded-full p-2 m-2 transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="sr-only">{{ __('app.download') }}</span>
        </button>
        @if(!$readOnly && $file->is_owned_by_auth_user)
            <button wire:click="editFile({{ $file->id }})"
                    title="{{ __('app.edit') }}"
                    class="text-slate-600 hover:text-rt-red dark:text-slate-200 dark:hover:text-rt-red text-sm bg-slate-200 dark:bg-slate-600 rounded-full p-2 m-2 transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                <span class="sr-only">{{ __('app.edit') }}</span>
            </button>
            <button wire:click="deleteFile({{ $file->id }})"
                    wire:confirm="{{ __('app.delete_file_confirm') }}"
                    title="{{ __('app.delete') }}"
                    class="text-red-600 hover:text-red-700 dark:text-red-400 text-sm bg-slate-200 dark:bg-slate-600 rounded-full p-2 m-2 transition-all duration-300 ease-rt-spring active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                <span class="sr-only">{{ __('app.delete') }}</span>
            </button>
        @endif
    </div>
</div>
