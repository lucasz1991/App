@props([
    'label',
    'model',
    'previewUrl' => null,
    'temporaryUrl' => null,
    'previewClass' => 'h-16 w-16 object-contain',
    'mode' => 'single',
    'acceptedFiles' => 'image/*',
    'maxFilesize' => 2,
    'dropLabel' => 'Bild hier ablegen oder klicken.',
    'emptyLabel' => 'Noch kein Bild vorhanden.',
    'deleteAction' => null,
])

@php
    $resolvedPreview = $temporaryUrl ?: $previewUrl;
    $hasPreview = (bool) $resolvedPreview;
@endphp

<div
    class="rt-ui-surface space-y-3 rounded-xl border border-rt-border bg-rt-surface p-4 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface"
    x-data="{ open: false }"
    x-init="$nextTick(() => {
        const input = $el.querySelector('input[type=file]');
        input?.addEventListener('change', () => {
            if (typeof changed !== 'undefined') changed = true;
        });
    })"
>
    <div class="space-y-1">
        <label class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $label }}</label>
    </div>

    <div class="rt-ui-surface-muted flex min-h-24 items-center justify-center rounded-lg border border-rt-border bg-rt-surface-muted p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
        @if ($resolvedPreview)
            <img src="{{ $resolvedPreview }}" alt="{{ $label }}" class="{{ $previewClass }}">
        @else
            <span class="text-center text-xs text-rt-soft dark:text-rt-dark-soft">{{ $emptyLabel }}</span>
        @endif
    </div>

    <div class="flex items-center gap-2">
        <button
            type="button"
            class="rt-ui-button rt-ui-button-secondary inline-flex items-center rounded-lg border border-rt-border bg-rt-surface px-3 py-2 text-sm font-medium text-rt-text transition hover:bg-rt-surface-muted focus:outline-none focus:ring-2 focus:ring-rt-red/40 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
            @click="open = !open"
        >
            <span x-text="open ? 'Upload schließen' : 'Upload öffnen'"></span>
        </button>

        @if ($deleteAction)
            <button
                type="button"
                class="rt-ui-button rt-ui-button-danger inline-flex items-center rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10"
                @click="window.dispatchEvent(new CustomEvent('filepool:reset', { detail: { model: @js($model) } })); open = false"
                wire:click="{{ $deleteAction }}"
                @disabled(!$hasPreview)
            >
                Bild löschen
            </button>
        @endif
    </div>

    <div x-show="open" x-collapse x-cloak class="pt-1">
        <x-ui.filepool.drop-zone
            :model="$model"
            :mode="$mode"
            :accepted-files="$acceptedFiles"
            :max-filesize="$maxFilesize"
            :label="$dropLabel"
        />
    </div>
</div>
