@props([
    'field',
    'label',
    'description' => null,
    'fallback' => '#000000',
    'usage' => [],
    'swatches' => [],
])

@php
    $usage = is_array($usage) ? array_values(array_filter($usage)) : [];
@endphp

<div
    {{ $attributes->except(['class'])->merge(['class' => 'rounded-[20px] border bg-white/90 px-3 py-2.5 transition-all duration-200 ease-out']) }}
    x-data="{
        field: @js($field),
        value: @js(strtoupper($fallback)),
        open: false,
        focusPicker() {
            this.$nextTick(() => {
                const container = this.$refs.picker;
                if (!container) {
                    return;
                }

                const target = container.querySelector('[data-colorpicker-focus]');
                if (target) {
                    target.focus();
                }
            });
        },
    }"
    x-modelable="value"
    :style="typeof spotStyle === 'function' ? spotStyle(field, value) : {}"
    @mouseenter="$dispatch('settings-color-active', { field })"
    @mouseleave="if (!open) $dispatch('settings-color-inactive', { field })"
    @focusin="$dispatch('settings-color-active', { field })"
    @focusout="$nextTick(() => { if (!$el.contains(document.activeElement)) { open = false; $dispatch('settings-color-inactive', { field }); } })"
    @colorpicker:changed.stop="value = $event.detail.value; open = true; $dispatch('settings-color-input', { field, value: $event.detail.value })"
    @colorpicker:open.window="if ($event.detail.field === field) { open = true; $dispatch('settings-color-active', { field }); focusPicker(); $nextTick(() => $el.scrollIntoView({ block: 'nearest', behavior: 'smooth' })); }"
>
    <button
        type="button"
        class="flex w-full items-center justify-between gap-3 text-left"
        @click="open = !open; if (open) { $dispatch('settings-color-active', { field }); focusPicker(); } else { $dispatch('settings-color-inactive', { field }); }"
    >
        <span class="flex min-w-0 items-center gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-white/70 shadow-sm" :style="{ backgroundColor: value }">
                <span class="h-3 w-3 rounded-full bg-white/80"></span>
            </span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-slate-900">{{ $label }}</span>
                <span class="block text-[11px] text-slate-500">Inline Picker</span>
            </span>
        </span>

        <span class="flex items-center gap-2">
            <span class="rounded-full bg-slate-100 px-2.5 py-1 font-mono text-[11px] text-slate-600" x-text="value"></span>
            <svg class="h-4 w-4 text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
        </span>
    </button>

    <div x-show="open" x-collapse.duration.250ms class="overflow-hidden">
        <div class="pt-3">
            @if ($description)
                <p class="text-xs leading-5 text-slate-500">{{ $description }}</p>
            @endif

            @if (!empty($usage))
                <div class="mt-2.5 flex flex-wrap gap-2">
                    @foreach ($usage as $item)
                        <span class="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-500">{{ $item }}</span>
                    @endforeach
                </div>
            @endif

            <div x-ref="picker" class="mt-3 rounded-[18px] border border-slate-200 bg-slate-50/80 p-3">
                <x-form.colorpicker x-model="value" fallback="{{ $fallback }}" :swatches="$swatches" />
            </div>
        </div>
    </div>
</div>
