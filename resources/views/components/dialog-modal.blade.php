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
    <header class="rt-modal-header relative shrink-0 border-b border-rt-border/70 bg-rt-surface px-5 py-4 pr-16 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-6 sm:py-5 sm:pr-16">
        <h2 id="{{ $titleId }}" class="text-lg font-semibold leading-6 tracking-[-0.02em] text-rt-text dark:text-rt-dark-text">
            {{ $title }}
        </h2>

        <button
            type="button"
            x-on:click="$dispatch('close')"
            class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-nav-hover hover:text-rt-text focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-text sm:right-4"
            aria-label="{{ __('app.close') }}"
            title="{{ __('app.close') }}"
        >
            <i class="far fa-times text-base" aria-hidden="true"></i>
        </button>
    </header>

    <div id="{{ $contentId }}" class="rt-modal-content min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-5 py-5 text-sm leading-6 text-rt-muted [overflow-wrap:anywhere] [scrollbar-gutter:stable] dark:text-rt-dark-muted sm:px-6 sm:py-6 [&_iframe]:max-w-full [&_img]:max-w-full [&_video]:max-w-full">
        {{ $content }}
    </div>

    <footer class="rt-modal-footer flex shrink-0 flex-row flex-wrap items-center justify-end gap-2 border-t border-rt-border/70 bg-rt-surface-muted/55 px-5 py-4 text-end dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35 sm:px-6">
        {{ $footer }}
    </footer>
</x-modal>
