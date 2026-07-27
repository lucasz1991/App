@props(['id', 'maxWidth'])

@php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl',
][$maxWidth ?? '2xl'];
@endphp

<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    id="{{ $id }}"
    class="rt-ui-modal rt-modal-shell jetstream-modal fixed inset-0 z-[130] overflow-y-auto px-4 py-6 sm:px-0"
    style="display: none;"
    data-rt-modal-shell
>
    <div x-show="show" class="rt-modal-backdrop fixed inset-0 transform transition-all" x-on:click="show = false" x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-md"></div>
    </div>

    <div x-show="show" role="dialog" aria-modal="true" aria-label="{{ $attributes->get('aria-label', config('app.name') . ' Dialog') }}" class="rt-ui-surface rt-ui-modal-panel rt-modal-frame relative mb-6 overflow-hidden rounded-[1.4rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/60 transform transition-all dark:bg-rt-dark-surface dark:text-white dark:ring-rt-dark-border/60 sm:w-full {{ $maxWidth }} sm:mx-auto"
                    x-trap.inert.noscroll="show"
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-2 sm:scale-[0.985]"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-3 sm:translate-y-1 sm:scale-[0.99]"
                    data-rt-modal-panel>
        {{ $slot }}
    </div>
</div>
