@props(['id' => null, 'maxWidth' => null])

@php
    $modalId = $id ?? md5($attributes->wire('model'));
    $titleId = $modalId.'-title';
    $contentId = $modalId.'-content';
@endphp

<x-modal
    :id="$modalId"
    :maxWidth="$maxWidth"
    role="alertdialog"
    aria-labelledby="{{ $titleId }}"
    aria-describedby="{{ $contentId }}"
    {{ $attributes->except(['role', 'aria-labelledby', 'aria-describedby']) }}
>
    <div class="rt-modal-content bg-rt-surface px-4 pb-5 pt-6 dark:bg-rt-dark-surface sm:p-6">
        <div class="sm:flex sm:items-start">
            <div class="rt-ui-danger-soft rt-confirmation-icon mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-red-100 ring-1 ring-red-200/70 dark:bg-red-500/10 dark:ring-red-400/20 sm:mx-0">
                <svg class="h-6 w-6 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>

            <div class="mt-3 text-center sm:mt-0 sm:ms-4 sm:text-start">
                <h3 id="{{ $titleId }}" class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">
                    {{ $title }}
                </h3>

                <div id="{{ $contentId }}" class="mt-3 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                    {{ $content }}
                </div>
            </div>
        </div>
    </div>

    <div class="rt-modal-footer flex flex-row flex-wrap justify-end gap-2 border-t border-rt-border bg-rt-surface-muted/70 px-5 py-4 text-end dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/55 sm:px-6">
        {{ $footer }}
    </div>
</x-modal>
