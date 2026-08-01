@props([
    // Alpine-Ausdruck, der den offenen Zustand haelt, z. B. "editorOpen".
    'state' => 'open',
    // Ueberschrift im Kopfbereich (oder den header-Slot nutzen).
    'title' => null,
    'eyebrow' => null,
    'icon' => 'far fa-window-maximize',
    // Schliessen per Escape erlauben.
    'closeOnEscape' => true,
    // Fokusfalle/Inertisierung kann fuer Arbeitsflaechen mit einem bewusst
    // ausserhalb liegenden Begleiter deaktiviert und dort gemeinsam verwaltet
    // werden. Standard bleibt der vollstaendig isolierte Dialog.
    'trap' => true,
    // Eigener Lifecycle-Ausdruck, z. B. Speichern + Fokus zurueckgeben.
    'closeAction' => null,
    'escapeAction' => null,
    'labelledby' => null,
    'bodyClass' => 'min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-4 sm:px-5',
    'contentClass' => 'mx-auto max-w-[100rem]',
    'headerClass' => '',
])

@php
    $dialogId = 'rt-fullscreen-'.\Illuminate\Support\Str::random(6);
    $closeExpression = filled($closeAction) ? $closeAction : $state.' = false';
    $escapeExpression = filled($escapeAction) ? $escapeAction : $closeExpression;
    $labelledById = filled($labelledby) ? $labelledby : $dialogId.'-title';
@endphp

{{--
    Wiederverwendbares VOLLBILD-Modal (randlos, eigener Kopfbereich, scrollender
    Inhalt) — im Unterschied zum zentrierten Dialog in components/modal.blade.php.
    Vorbild ist der Wagenlisten-Editor.

    Verwendung: der offene Zustand lebt in der umgebenden Alpine-Komponente.

        <div x-data="{ editorOpen: false }">
            <button x-on:click="editorOpen = true">oeffnen</button>

            <x-ui.fullscreen-modal state="editorOpen" title="Wagenliste">
                <x-slot:actions>…Kopfzeilen-Buttons…</x-slot:actions>
                …Inhalt…
                <x-slot:footer>…Fusszeile…</x-slot:footer>
            </x-ui.fullscreen-modal>
        </div>

    WICHTIG — x-show.important:
    Die Legacy-Datei public/build/css/tailwind.min.css setzt die Display-
    Utilities auf !important (.flex{display:flex!important}). Alpines x-show
    schreibt ein INLINE display:none, das dagegen verliert. Ohne .important
    bliebe dieses Modal dauerhaft offen und unverschliessbar. Nicht entfernen.
--}}
<template x-teleport="body">
    <section
        x-show.important="{{ $state }}"
        x-cloak
        @if ($closeOnEscape)
            x-on:keydown.escape.window="if ({{ $state }}) { {{ $escapeExpression }} }"
        @endif
        @if ($trap)
            x-trap.inert.noscroll="{{ $state }}"
        @endif
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $labelledById }}"
        x-transition:enter="transition duration-300 ease-out"
        x-transition:enter-start="opacity-0 scale-[0.995]"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition duration-200 ease-in"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-[0.995]"
        {{ $attributes->class('fixed inset-0 z-[190] flex min-h-0 flex-col bg-rt-canvas text-rt-text dark:bg-rt-dark-canvas dark:text-rt-dark-text') }}
        data-rt-fullscreen-modal
        data-rt-overlay-layer
        data-rt-overlay-base="190"
    >
        <header class="z-10 shrink-0 border-b border-rt-border/70 bg-rt-surface/95 px-3 py-2.5 shadow-rt-xs backdrop-blur-xl dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/95 sm:px-5 sm:py-3 {{ $headerClass }}">
            <div class="mx-auto flex max-w-[100rem] items-center gap-3">
                @isset($header)
                    {{ $header }}
                @else
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                        <i class="{{ $icon }}" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        @if (filled($eyebrow))
                            <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">{{ $eyebrow }}</p>
                        @endif
                        <h2 id="{{ $dialogId }}-title" tabindex="-1" class="truncate text-base font-semibold outline-none sm:text-lg">{{ $title }}</h2>
                    </div>
                @endisset

                <div class="flex shrink-0 items-center gap-2">
                    @isset($actions)
                        {{ $actions }}
                    @endisset

                    <button
                        type="button"
                        x-on:click="{{ $closeExpression }}"
                        class="flex h-10 w-10 items-center justify-center rounded-lg border border-rt-border bg-rt-control text-rt-muted transition hover:border-rt-accent/40 hover:text-rt-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:text-rt-dark-accent"
                        aria-label="{{ __('app.close') }}"
                    >
                        <i class="far fa-times" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </header>

        <div class="{{ $bodyClass }}">
            <div class="{{ $contentClass }}">
                {{ $slot }}
            </div>
        </div>

        @isset($footer)
            <footer class="shrink-0 border-t border-rt-border/70 bg-rt-surface/95 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur-xl dark:border-rt-dark-border/70 dark:bg-rt-dark-surface/95 sm:px-5 sm:pb-3">
                <div class="mx-auto flex max-w-[100rem] items-center justify-end gap-2">
                    {{ $footer }}
                </div>
            </footer>
        @endisset

        <div wire:ignore class="pointer-events-none fixed inset-0 z-[20]" data-rt-overlay-portal></div>
    </section>
</template>
