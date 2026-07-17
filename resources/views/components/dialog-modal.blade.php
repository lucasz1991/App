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

    <div class="flex flex-row justify-end px-6 py-4 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50 text-end rounded-b-xl">
        {{ $footer }}
    </div>
</x-modal>
