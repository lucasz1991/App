@props(['id' => null, 'maxWidth' => null])

@php
    $modalId = $id ?? md5($attributes->wire('model'));
    $titleId = $modalId.'-title';
    $contentId = $modalId.'-content';
@endphp

<x-modal
    :id="$modalId"
    :maxWidth="$maxWidth"
    role="dialog"
    aria-labelledby="{{ $titleId }}"
    aria-describedby="{{ $contentId }}"
    {{ $attributes->except(['role', 'aria-labelledby', 'aria-describedby']) }}
>
    <header class="rt-modal-header relative grid shrink-0 grid-cols-[minmax(0,1fr)_auto] items-start gap-4 border-b border-rt-border/70 bg-rt-surface px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-6 sm:py-5">
        <h2 id="{{ $titleId }}" class="min-w-0 text-lg font-semibold leading-6 tracking-[-0.02em] text-rt-text dark:text-rt-dark-text">
            {{ $title }}
        </h2>

        <div
            class="flex shrink-0 flex-col items-center gap-2"
            data-dialog-header-action-rail
        >
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="rt-ui-button rt-ui-button-secondary inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-rt-border bg-rt-surface text-rt-muted shadow-rt-xs transition-all duration-200 ease-rt-spring hover:border-rt-accent/30 hover:bg-rt-surface-muted hover:text-rt-accent active:scale-[0.97] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/35 dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
                aria-label="{{ __('app.close') }}"
                title="{{ __('app.close') }}"
                data-dialog-close
            >
                <i class="far fa-times text-base" aria-hidden="true"></i>
            </button>

            @isset($headerActions)
                <div
                    class="flex flex-col items-center gap-2 border-t border-rt-border/70 pt-2 dark:border-rt-dark-border/70"
                    role="toolbar"
                    aria-label="{{ __('app.actions') }}"
                    data-dialog-header-actions
                >
                    {{ $headerActions }}
                </div>
            @endisset
        </div>
    </header>

    <div id="{{ $contentId }}" class="rt-modal-content min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-5 py-5 text-sm leading-6 text-rt-muted [overflow-wrap:anywhere] [scrollbar-gutter:stable] dark:text-rt-dark-muted sm:px-6 sm:py-6 [&_iframe]:max-w-full [&_img]:max-w-full [&_video]:max-w-full">
        {{ $content }}
    </div>

    <footer class="rt-modal-footer flex shrink-0 flex-row flex-wrap items-center justify-end gap-2 border-t border-rt-border/70 bg-rt-surface-muted/55 px-5 py-4 text-end dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35 sm:px-6">
        {{ $footer }}
    </footer>
</x-modal>
