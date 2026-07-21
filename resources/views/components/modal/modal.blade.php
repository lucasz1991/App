@props([
    'id' => null,
    'maxWidth' => '2xl',
    'trapClose' => false,
])

@php
    $id = $id ?? md5($attributes->wire('model'));

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
    class="rt-ui-modal jetstream-modal fixed inset-0 z-[130] overflow-y-auto px-4 py-6"
    style="display: none;"
>
    {{-- Overlay --}}
    <div
        x-show="show"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $attributes->get('aria-label', config('app.name') . ' Dialog') }}"
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
        class="rt-ui-surface rt-ui-modal-panel mb-6 overflow-hidden rounded-2xl border border-rt-border bg-rt-surface text-rt-text shadow-rt-lg transform transition-all dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text sm:w-full {{ $maxWidthClass }} sm:mx-auto"
        x-trap.inert.noscroll="show"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        <div class="relative">
            {{-- TITLE BAR --}}
            <div class="rt-ui-surface-muted border-b border-rt-border bg-rt-surface-muted px-2 py-2 pt-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted md:px-6 md:py-3 md:pt-5">
                <div class="text-lg font-medium text-rt-text dark:text-rt-dark-text">
                    {{ $title }}
                </div>

                @if(!$trapClose)
                    <button
                        type="button"
                        aria-label="{{ __('app.close') }}"
                        class="rt-ui-button rt-ui-button-secondary absolute right-2 top-2 rounded-lg p-2 text-rt-muted transition hover:bg-rt-nav-hover hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-nav-hover dark:hover:text-white"
                        @click="close()"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            viewBox="0 0 20 20"
                            fill="currentColor"
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
            </div>

            {{-- CONTENT --}}
            <div class="px-2 py-2 text-sm text-rt-muted dark:text-rt-dark-muted md:px-6 md:py-4">
                {{ $content }}
            </div>

            {{-- FOOTER --}}
            <div class="rt-ui-surface-muted flex flex-row justify-end border-t border-rt-border bg-rt-surface-muted px-2 py-2 text-end dark:border-rt-dark-border dark:bg-rt-dark-surface-muted md:px-6 md:py-4">
                {{ $footer }}
            </div>
        </div>
    </div>
</div>
