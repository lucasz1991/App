@props([
    'event' => 'saved',
    'target' => null,
    'dirtyTarget' => null,
])

@php
    $dirtyTargets = collect(explode(',', (string) $dirtyTarget))
        ->map(fn ($item) => trim($item))
        ->filter()
        ->values()
        ->all();
@endphp

<span
    x-data="{
        saved: false,
        dirty: false,
        timer: null,
        targets: {{ \Illuminate\Support\Js::from($dirtyTargets) }},
        markDirty(event) {
            const field = event.target;
            const model = [
                'wire:model',
                'wire:model.live',
                'wire:model.blur',
                'wire:model.lazy',
            ].map(attribute => field?.getAttribute?.(attribute)).find(Boolean);

            if (!model || !this.targets.some(target => model === target || model.startsWith(`${target}.`))) {
                return;
            }

            this.dirty = true;
            this.saved = false;
            window.clearTimeout(this.timer);
        },
    }"
    x-on:input.window="markDirty($event)"
    x-on:change.window="markDirty($event)"
    x-on:{{ $event }}.window="
        dirty = false;
        saved = true;
        window.clearTimeout(timer);
        timer = window.setTimeout(() => saved = false, 2200);
    "
    class="inline-flex min-h-6 items-center gap-2 text-xs font-semibold"
    aria-live="polite"
    data-autosave-status="{{ $event }}"
>
    <span
        wire:loading.class.remove="!hidden"
        wire:loading.class="!inline-flex"
        @if($target) wire:target="{{ $target }}" @endif
        class="!hidden items-center gap-1.5 text-rt-muted dark:text-rt-dark-muted"
    >
        <i class="far fa-spinner-third animate-spin" aria-hidden="true"></i>
        {{ __('app.saving_changes') }}
    </span>

    <span
        x-cloak
        x-show.important="saved"
        @if($target) wire:target="{{ $target }}" @endif
        class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-300"
    >
        <i class="far fa-check-circle" aria-hidden="true"></i>
        {{ __('app.saved_automatically') }}
    </span>

    @if($dirtyTarget)
        <span
            x-cloak
            x-show.important="dirty && ! saved"
            class="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-300"
        >
            <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
            {{ __('app.unsaved_changes') }}
        </span>
    @endif
</span>
