@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="px-6 py-4">
        <div class="text-lg font-medium text-slate-900 dark:text-white">
            {{ $title }}
        </div>

        <div class="mt-4 text-sm text-slate-600 dark:text-slate-300">
            {{ $content }}
        </div>
    </div>

    <div class="flex flex-row justify-end px-6 py-4 border-t border-rt-border dark:border-rt-dark-border bg-rt-surface-muted dark:bg-rt-dark-surface-muted text-end rounded-b-2xl">
        {{ $footer }}
    </div>
</x-modal>
