@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="rt-modal-content px-5 pb-5 pt-6 sm:px-6 sm:pb-6">
        <div class="flex items-start gap-3">
            <span class="rt-modal-title-mark mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-rt-red shadow-[0_0_0_5px_rgba(228,0,43,0.10)]" aria-hidden="true"></span>
            <div class="min-w-0">
                <div class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                    {{ $title }}
                </div>

                <div class="mt-3 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    {{ $content }}
                </div>
            </div>
        </div>
    </div>

    <div class="rt-modal-footer flex flex-row flex-wrap justify-end gap-2 border-t border-rt-border bg-rt-surface-muted/70 px-5 py-4 text-end dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/55 sm:px-6">
        {{ $footer }}
    </div>
</x-modal>
