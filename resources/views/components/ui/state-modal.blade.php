@props([
    'id',
    'state',
    'title',
    'description' => null,
    'icon' => 'far fa-window-maximize',
    'maxWidth' => '4xl',
    'closeAction' => null,
    'layer' => 190,
])

@php
    $titleId = $id.'-title';
    $descriptionId = $id.'-description';
    $closeExpression = filled($closeAction) ? $closeAction : $state.' = false';
    $resolvedLayer = max(1, (int) $layer);
    $maxWidthClass = [
        '2xl' => 'sm:max-w-2xl',
        '3xl' => 'sm:max-w-3xl',
        '4xl' => 'sm:max-w-4xl',
        '5xl' => 'sm:max-w-5xl',
        '6xl' => 'sm:max-w-6xl',
    ][$maxWidth] ?? 'sm:max-w-4xl';
@endphp

<template x-teleport="body">
    <div
        x-show.important="{{ $state }}"
        x-cloak
        x-on:keydown.escape.window="if ({{ $state }}) { {{ $closeExpression }} }"
        x-on:rt-navigation:prepare.window="{{ $state }} = false"
        x-trap.inert.noscroll="{{ $state }}"
        class="fixed inset-0 z-[190] flex items-end justify-center overflow-hidden pt-4 sm:items-center sm:p-6"
        style="display: none; z-index: {{ $resolvedLayer }} !important;"
        data-rt-state-modal
        data-rt-overlay-layer
        data-rt-overlay-base="{{ $resolvedLayer }}"
    >
        <div
            x-show.important="{{ $state }}"
            x-on:click="{{ $closeExpression }}"
            x-transition:enter="transition duration-200 ease-out"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-slate-950/60 backdrop-blur-md"
            aria-hidden="true"
        ></div>

        <section
            id="{{ $id }}"
            x-show.important="{{ $state }}"
            x-effect="if ({{ $state }}) { $nextTick(() => document.getElementById(@js($titleId))?.focus()) }"
            x-transition:enter="transition duration-300 ease-out"
            x-transition:enter-start="translate-y-5 opacity-0 sm:translate-y-2 sm:scale-[0.985]"
            x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="translate-y-0 opacity-100 sm:scale-100"
            x-transition:leave-end="translate-y-4 opacity-0 sm:translate-y-1 sm:scale-[0.99]"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $titleId }}"
            @if (filled($description))
                aria-describedby="{{ $descriptionId }}"
            @endif
            {{ $attributes->class([
                'relative flex max-h-[calc(100dvh-1rem)] min-h-0 w-full flex-col overflow-hidden rounded-t-[1.6rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/70 sm:max-h-[calc(100dvh-3rem)] sm:rounded-[1.6rem]',
                $maxWidthClass,
            ]) }}
        >
            <header class="relative grid shrink-0 grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-rt-border/70 bg-rt-surface px-4 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:gap-4 sm:px-6 sm:py-5">
                <div class="flex min-w-0 items-start gap-3.5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red ring-1 ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="{{ $icon }}" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 pt-0.5">
                        <h2
                            id="{{ $titleId }}"
                            tabindex="-1"
                            class="text-balance text-lg font-semibold leading-6 tracking-[-0.025em] outline-none sm:text-xl"
                        >
                            {{ $title }}
                        </h2>
                        @if (filled($description))
                            <p id="{{ $descriptionId }}" class="mt-1 max-w-3xl text-xs leading-5 text-rt-muted dark:text-rt-dark-muted sm:text-sm">
                                {{ $description }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @isset($actions)
                        {{ $actions }}
                    @endisset

                    <button
                        type="button"
                        x-on:click="{{ $closeExpression }}"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted ring-1 ring-rt-border/70 transition duration-200 hover:text-rt-red active:scale-[0.97] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70 dark:hover:text-rt-dark-accent"
                        aria-label="{{ __('app.close') }}"
                    >
                        <i class="far fa-times" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <div class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-4 py-4 [overflow-wrap:anywhere] [scrollbar-gutter:stable] sm:px-6 sm:py-6">
                {{ $slot }}
            </div>

            @isset($footer)
                <footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-rt-border/70 bg-rt-surface-muted/55 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35 sm:px-6 sm:py-4">
                    {{ $footer }}
                </footer>
            @endisset
        </section>

        <div wire:ignore class="pointer-events-none fixed inset-0 z-[20]" data-rt-overlay-portal></div>
    </div>
</template>
