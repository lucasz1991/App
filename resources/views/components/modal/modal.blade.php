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
    class="jetstream-modal fixed inset-0 overflow-y-auto px-4 py-6 z-50"
    style="display: none; z-index: 9999 !important;"
>
    {{-- Overlay --}}
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all"
        x-on:click="close()"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
    </div>

    {{-- Modal-Container --}}
    <div
        x-show="show"
        class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidthClass }} sm:mx-auto"
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
            <div class="px-2 md:px-6 py-2 pt-4 md:pt-5 md:py-3 bg-gray-100 border-b border-gray-300">
                <div class="text-lg font-medium text-gray-900">
                    {{ $title }}
                </div>

                @if(!$trapClose)
                    <button
                        type="button"
                        class="absolute top-1 right-1 text-gray-500 hover:text-gray-700 transition p-1 rounded"
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
            <div class="px-2 md:px-6 py-2 md:py-4 text-sm text-gray-600">
                {{ $content }}
            </div>

            {{-- FOOTER --}}
            <div class="flex flex-row justify-end px-2 md:px-6 py-2 md:py-4 bg-gray-100 text-end border-t border-gray-300">
                {{ $footer }}
            </div>
        </div>
    </div>
</div>
