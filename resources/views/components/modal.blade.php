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

$dialogRole = $attributes->get('role', 'dialog');
$labelledBy = $attributes->get('aria-labelledby');
$describedBy = $attributes->get('aria-describedby');
$ariaLabel = $attributes->get('aria-label', config('app.name') . ' Dialog');
@endphp

<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:rt-navigation:prepare.window="show = false"
    x-on:keydown.escape.window="show = false"
>
    <template x-teleport="body">
        <div
            x-show.important="show"
            x-on:close.stop="show = false"
            x-trap.inert.noscroll="show"
            id="{{ $id }}"
            class="rt-ui-modal rt-modal-shell rt-modal-center-shell jetstream-modal fixed inset-0 z-[190] overflow-x-hidden overflow-y-auto overscroll-contain p-3 sm:p-6"
            style="display: none;"
            data-rt-modal-shell
            data-rt-overlay-layer
            data-rt-overlay-base="190"
        >
            <div x-show.important="show" aria-hidden="true" class="rt-modal-backdrop fixed inset-0" x-on:click="show = false" x-transition:enter="rt-motion-backdrop-enter"
                            x-transition:enter-start="rt-motion-faded"
                            x-transition:enter-end="rt-motion-shown"
                            x-transition:leave="rt-motion-backdrop-leave"
                            x-transition:leave-start="rt-motion-shown"
                            x-transition:leave-end="rt-motion-faded">
                <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-md"></div>
            </div>

            <div x-show.important="show"
                            role="{{ $dialogRole }}"
                            aria-modal="true"
                            @if (filled($labelledBy))
                                aria-labelledby="{{ $labelledBy }}"
                            @else
                                aria-label="{{ $ariaLabel }}"
                            @endif
                            @if (filled($describedBy))
                                aria-describedby="{{ $describedBy }}"
                            @endif
                            class="rt-ui-surface rt-ui-modal-panel rt-modal-frame relative my-auto flex max-h-[calc(100dvh-1.5rem)] min-h-0 min-w-0 w-full max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-[1.4rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 sm:max-h-[calc(100dvh-3rem)] {{ $maxWidth }}"
                            x-transition:enter="rt-motion-modal-enter"
                            x-transition:enter-start="rt-motion-modal-enter-from"
                            x-transition:enter-end="rt-motion-modal-enter-to"
                            x-transition:leave="rt-motion-modal-leave"
                            x-transition:leave-start="rt-motion-modal-leave-from"
                            x-transition:leave-end="rt-motion-modal-leave-to"
                            data-rt-modal-panel>
                {{ $slot }}
            </div>

            {{--
                Teleport-Ziel fuer Selects und verschachtelte Anchor-Dropdowns.
                Es liegt im selben Focus-Trap wie der Dialog, aber ausserhalb
                des animierten Panels, damit fixed-Geometrie viewportbezogen
                bleibt und Listen den Modal-Inhalt nicht aufweiten.
            --}}
            <div
                wire:ignore
                class="pointer-events-none fixed inset-0 z-[20]"
                data-rt-overlay-portal
                data-rt-modal-portal
            ></div>
        </div>
    </template>
</div>
