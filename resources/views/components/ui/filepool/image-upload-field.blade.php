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
    class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
    x-data="{ open: false }"
    x-init="$nextTick(() => {
        const input = $el.querySelector('input[type=file]');
        input?.addEventListener('change', () => {
            if (typeof changed !== 'undefined') changed = true;
        });
    })"
>
    <div class="space-y-1">
        <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    </div>

    <div class="flex min-h-24 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 p-4">
        @if ($resolvedPreview)
            <img src="{{ $resolvedPreview }}" alt="{{ $label }}" class="{{ $previewClass }}">
        @else
            <span class="text-center text-xs text-slate-400">{{ $emptyLabel }}</span>
        @endif
    </div>

    <div class="flex items-center gap-2">
        <button
            type="button"
            class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            @click="open = !open"
        >
            <span x-text="open ? 'Upload schließen' : 'Upload öffnen'"></span>
        </button>

        @if ($deleteAction)
            <button
                type="button"
                class="inline-flex items-center rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
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
