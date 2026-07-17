@props(['file', 'readOnly' => false])

<div    x-data="{ isHovered: false }"
        class="relative border border-gray-300 dark:border-slate-700 rounded-xl overflow-hidden bg-white dark:bg-slate-800 shadow mb-2 cardgroup"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
        @touchstart="isHovered = true"
        >
    <div class="transition" :class="{ 'blur-sm': isHovered }">
        <img src="{{ $file->icon_or_thumbnail }}" alt="{{ $file->name }}" class="w-full !aspect-square @if($file->is_image) object-cover @else object-contain p-6 @endif">
    </div>
    <div class="p-2 space-y-2 bg-gray-100 dark:bg-slate-700 transition" :class="{ 'blur-sm': isHovered }">
        <div class="text-sm text-gray-800 dark:text-slate-200 truncate" title="{{ $file->name }}">{{ $file->name }}</div>
        <div class="text-xs text-gray-500 dark:text-slate-400 truncate w-full mb-1" title="{{ $file->getMimeTypeForHumans() }}">
            <span>{{ $file->getMimeTypeForHumans() }}</span>
        </div>
        <div class="text-xs text-gray-500 dark:text-slate-400">
            <span>{{ $file->size_formatted }}</span>
        </div>
        @if($file->expires_at)
            @if(!$file->isExpired())
                <span class="text-gray-500 dark:text-slate-300 text-xs p-1 bg-gray-100 dark:bg-slate-700 rounded border dark:border-slate-600 absolute top-2" :class="{ 'hidden': isHovered }">{{ __('app.expires_on', ['date' => $file->expires_at->format('d.m.Y')]) }}</span>
            @else
                <span class="text-red-500 dark:text-red-400 text-xs p-1 bg-red-100 dark:bg-red-500/20 rounded border dark:border-red-500/40 absolute top-2" :class="{ 'hidden': isHovered }">{{ __('app.expired') }}</span>
            @endif
        @endif
    </div>
    <div class="absolute inset-0 flex items-center justify-center flex-wrap bg-white bg-opacity-65 dark:bg-slate-900 dark:bg-opacity-65 rounded-lg" x-show="isHovered" x-collapse>
        <button
                type="button"
                @click="window.dispatchEvent(new CustomEvent('filepool-preview', { detail: { id: {{ $file->id }} } }))"
                title="{{ __('app.preview') }}"
                class="text-gray-600 hover:text-blue-600 dark:text-slate-200 dark:hover:text-blue-400 text-sm bg-gray-300 dark:bg-slate-600 rounded-full p-2 m-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span class="sr-only">{{ __('app.preview') }}</span>
        </button>
        <button wire:click="downloadFile({{ $file->id }})"
                title="{{ __('app.download') }}"
                class="text-gray-600 hover:text-blue-600 dark:text-slate-200 dark:hover:text-blue-400 text-sm bg-gray-300 dark:bg-slate-600 rounded-full p-2 m-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="sr-only">{{ __('app.download') }}</span>
        </button>
        @if(!$readOnly && $file->is_owned_by_auth_user)
            <button wire:click="editFile({{ $file->id }})"
                    title="{{ __('app.edit') }}"
                    class="text-gray-600 hover:text-blue-600 dark:text-slate-200 dark:hover:text-blue-400 text-sm bg-gray-300 dark:bg-slate-600 rounded-full p-2 m-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                <span class="sr-only">{{ __('app.edit') }}</span>
            </button>
            <button wire:click="deleteFile({{ $file->id }})"
                    wire:confirm="{{ __('app.delete_file_confirm') }}"
                    title="{{ __('app.delete') }}"
                    class="text-red-600 dark:text-red-400 text-sm bg-gray-300 dark:bg-slate-600 rounded-full p-2 m-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 aspect-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                <span class="sr-only">{{ __('app.delete') }}</span>
            </button>
        @endif
    </div>
</div>
