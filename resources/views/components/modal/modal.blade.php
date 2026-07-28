@props([
    'id' => null,
    'maxWidth' => '2xl',
    'trapClose' => false,
])

@php
    $id = $id ?? md5($attributes->wire('model'));
    $hasStructuredSlots = isset($title) || isset($content) || isset($footer);
    $labelledBy = $attributes->get('aria-labelledby')
        ?? (isset($title) ? $id . '-title' : null);

    $maxWidthClass = [
        'sm'  => 'sm:max-w-sm',
        'md'  => 'sm:max-w-md',
        'lg'  => 'sm:max-w-lg',
        'xl'  => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
        '3xl' => 'sm:max-w-3xl',
        '4xl' => 'sm:max-w-4xl',
    ][$maxWidth] ?? 'sm:max-w-2xl';
@endphp

<div
    x-data="{
        show: @entangle($attributes->wire('model')),
        trap: {{ $trapClose ? 'true' : 'false' }},
        close() {
            if (!this.trap) {
                this.show = false;
            }
        }
    }"
    x-on:close.stop="close()"
    x-on:keydown.escape.window="close()"
    x-show="show"
    id="{{ $id }}"
    class="rt-ui-modal jetstream-modal fixed inset-0 z-[130] flex items-center justify-center overflow-hidden p-3 sm:p-6"
    style="display: none;"
>
    {{-- Overlay --}}
    <div
        x-show="show"
        aria-hidden="true"
        class="fixed inset-0 transform transition-all"
        x-on:click="close()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm"></div>
    </div>

    {{-- Modal-Container --}}
    <div
        x-show="show"
        role="dialog"
        aria-modal="true"
        @if($labelledBy)
            aria-labelledby="{{ $labelledBy }}"
        @else
            aria-label="{{ $attributes->get('aria-label', config('app.name') . ' Dialog') }}"
        @endif
        class="rt-ui-surface rt-ui-modal-panel rt-modal-frame relative flex max-h-[calc(100dvh-1.5rem)] min-h-0 min-w-0 w-full max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-[1.4rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/70 transform transition-all dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/70 sm:max-h-[calc(100dvh-3rem)] {{ $maxWidthClass }}"
        x-trap.inert.noscroll="show"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        @if($hasStructuredSlots)
            {{-- TITLE BAR --}}
            @isset($title)
                <header class="rt-modal-header relative shrink-0 border-b border-rt-border/70 bg-rt-surface px-5 py-4 pr-16 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-6 sm:py-5 sm:pr-16">
                    <h2 id="{{ $id }}-title" class="text-balance text-lg font-semibold leading-6 tracking-tight text-rt-text dark:text-rt-dark-text">
                        {{ $title }}
                    </h2>

                    @if(!$trapClose)
                        <button
                            type="button"
                            aria-label="{{ __('app.close') }}"
                            class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-xl text-rt-muted transition hover:bg-rt-nav-hover hover:text-rt-text focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-rt-dark-text sm:right-4"
                            @click="close()"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414
                                    1.414L11.414 10l4.293 4.293a1 1 0
                                    01-1.414 1.414L10 11.414l-4.293
                                    4.293a1 1 0 01-1.414-1.414L8.586
                                    10 4.293 5.707a1 1 0 010-1.414z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </button>
                    @endif
                </header>
            @endisset

            {{-- CONTENT --}}
            @isset($content)
                <div class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-5 py-5 text-sm leading-6 text-rt-muted [overflow-wrap:anywhere] [scrollbar-gutter:stable] dark:text-rt-dark-muted sm:px-6 sm:py-6 [&_iframe]:max-w-full [&_img]:max-w-full [&_video]:max-w-full">
                    {{ $content }}
                </div>
            @endisset

            {{-- FOOTER --}}
            @isset($footer)
                <footer class="rt-modal-footer flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-rt-border/70 bg-rt-surface-muted/55 px-5 py-4 text-end dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35 sm:px-6 [&>*]:max-w-full">
                    {{ $footer }}
                </footer>
            @endisset
        @else
            <div class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain [overflow-wrap:anywhere]">
                {{ $slot }}
            </div>
        @endif
    </div>
</div>
