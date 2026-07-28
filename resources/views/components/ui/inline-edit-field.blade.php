@props([
    'id' => null,
    'field',
    'model' => null,
    'type' => 'text',
    'canEdit' => false,
    'multiline' => false,
    'options' => [],
    'autocomplete' => 'off',
    'rows' => 3,
    'align' => 'right',
])

@php
    $wireModel = $model ?: 'inlineValues.'.$field;
    $inputId = $id ?: 'inline-'.\Illuminate\Support\Str::slug($field).'-'.substr(md5($wireModel), 0, 8);
    $alignment = $align === 'left' ? 'text-left' : 'text-right';
    $justification = $align === 'left' ? 'justify-start' : 'justify-end';
@endphp

<div
    x-data="{
        editing: false,
        saving: false,
        open() {
            if (this.saving) return;
            this.editing = true;
            this.$nextTick(() => {
                this.$refs.editor?.focus();
                this.$refs.editor?.select?.();
            });
        },
        requestAutosave() {
            const scope = this.$el.closest('[data-autosave-scope]');

            if (! scope) {
                return this.$wire.saveInlineField(@js($field));
            }

            const detail = { scope, promise: null };
            scope.dispatchEvent(new CustomEvent('rt-autosave-flush', {
                bubbles: true,
                detail,
            }));

            return detail.promise ?? this.$wire.saveInlineField(@js($field));
        },
        async commit() {
            if (! this.editing || this.saving) return;
            this.saving = true;

            try {
                const saved = await this.requestAutosave();
                if (saved) this.editing = false;
            } finally {
                this.saving = false;
            }
        },
        async cancel() {
            if (this.saving) return;
            this.saving = true;

            try {
                await this.$wire.cancelInlineField(@js($field));
                this.editing = false;
            } finally {
                this.saving = false;
            }
        },
    }"
    class="min-w-0 flex-1"
    data-inline-edit-field="{{ $field }}"
>
    @if ($canEdit)
        <button
            x-show.important="! editing"
            type="button"
            x-on:dblclick.prevent="open()"
            x-on:keydown.enter.prevent="open()"
            class="group/inline-edit flex min-h-8 w-full min-w-0 items-center {{ $justification }} gap-2 rounded-lg px-1.5 py-1 {{ $alignment }} text-sm font-medium text-rt-text transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
            aria-label="{{ trim(strip_tags((string) $slot)) }} · {{ __('app.double_click_to_edit') }}"
            title="{{ __('app.double_click_to_edit') }}"
            data-inline-edit-display
        >
            <span class="min-w-0 break-words">{{ $slot }}</span>
            <i
                class="far fa-pen shrink-0 text-[9px] text-rt-soft opacity-0 transition group-hover/inline-edit:opacity-100 group-focus-visible/inline-edit:opacity-100 dark:text-rt-dark-soft"
                aria-hidden="true"
            ></i>
        </button>

        <div x-cloak x-show.important="editing" class="min-w-0">
            @if ($type === 'select')
                <select
                    x-ref="editor"
                    id="{{ $inputId }}"
                    wire:model="{{ $wireModel }}"
                    x-on:blur="commit()"
                    x-on:change="$nextTick(() => $el.blur())"
                    x-on:keydown.escape.prevent.stop="cancel()"
                    x-bind:disabled="saving"
                    class="rt-ui-control min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition duration-200 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 disabled:opacity-60 sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:focus:ring-rt-dark-accent/20"
                >
                    @foreach ($options as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                    @endforeach
                </select>
            @elseif ($multiline)
                <textarea
                    x-ref="editor"
                    id="{{ $inputId }}"
                    wire:model="{{ $wireModel }}"
                    rows="{{ $rows }}"
                    x-on:blur="commit()"
                    x-on:keydown.escape.prevent.stop="cancel()"
                    x-on:keydown.ctrl.enter.prevent="$el.blur()"
                    x-bind:disabled="saving"
                    class="rt-ui-control min-h-24 w-full resize-y rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition duration-200 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 disabled:opacity-60 sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:focus:ring-rt-dark-accent/20"
                ></textarea>
            @else
                <x-ui.forms.input
                    x-ref="editor"
                    :id="$inputId"
                    :type="$type"
                    wire:model="{{ $wireModel }}"
                    x-on:blur="commit()"
                    x-on:keydown.enter.prevent="$el.blur()"
                    x-on:keydown.escape.prevent.stop="cancel()"
                    x-bind:disabled="saving"
                    :autocomplete="$autocomplete"
                    class="{{ $alignment }}"
                />
            @endif

            <x-input-error :for="$wireModel" class="text-left" />
        </div>
    @else
        <div class="{{ $alignment }} text-sm font-medium text-rt-text dark:text-rt-dark-text">
            {{ $slot }}
        </div>
    @endif
</div>
