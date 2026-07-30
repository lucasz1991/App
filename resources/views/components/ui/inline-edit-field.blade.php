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
        pointerKind: 'mouse',
        prepareOpen() {
            if (this.saving) return null;

            this.editing = true;
            const shell = this.$refs.editorShell;
            const editor = this.$refs.editor;

            // Der Editor muss vor der nativen Label-Aktivierung sichtbar sein.
            // Der Browser leitet denselben vertrauenswuerdigen Tap danach an
            // das verknuepfte Eingabefeld weiter und oeffnet so auch in einer
            // installierten iOS-PWA die Bildschirmtastatur.
            shell?.style.removeProperty('display');

            return editor;
        },
        openFromKeyboard() {
            const editor = this.prepareOpen();
            editor?.focus();
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
                const scope = this.$el.closest('[data-autosave-scope]');
                scope?.dispatchEvent(new CustomEvent('rt-autosave-reset', {
                    bubbles: true,
                    detail: {
                        scope,
                        model: @js($wireModel),
                        fieldId: @js($inputId),
                    },
                }));
                this.editing = false;
            } finally {
                this.saving = false;
            }
        },
    }"
    class="min-w-0 flex-1"
    data-inline-edit-field="{{ $field }}"
    data-autosave-field
    data-autosave-model="{{ $wireModel }}"
    data-autosave-field-id="{{ $inputId }}"
    data-autosave-state="idle"
>
    @if ($canEdit)
        <label
            x-show.important="! editing"
            for="{{ $inputId }}"
            role="button"
            tabindex="0"
            x-on:pointerdown="pointerKind = $event.pointerType || 'mouse'"
            x-on:touchstart.passive="pointerKind = 'touch'"
            x-on:click="if (pointerKind === 'mouse') { $event.preventDefault(); } else { prepareOpen(); }"
            x-on:dblclick.prevent="openFromKeyboard()"
            x-on:keydown.enter.prevent="openFromKeyboard()"
            x-on:keydown.space.prevent="openFromKeyboard()"
            class="rt-inline-edit-visual group/inline-edit flex min-h-8 w-full min-w-0 items-center {{ $justification }} gap-2 rounded-lg border border-transparent px-1.5 py-1 {{ $alignment }} text-sm font-medium text-rt-text outline-none transition-[border-color,box-shadow,background-color,color] hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
            aria-label="{{ trim(strip_tags((string) $slot)) }} · {{ __('app.edit') }}"
            title="{{ __('app.edit') }}"
            data-inline-edit-display
            data-native-inline-edit-trigger
            data-autosave-visual
        >
            <span class="min-w-0 break-words">{{ $slot }}</span>
            <i
                class="far fa-pen shrink-0 text-[9px] text-rt-soft opacity-0 transition group-hover/inline-edit:opacity-100 group-focus-visible/inline-edit:opacity-100 dark:text-rt-dark-soft"
                aria-hidden="true"
            ></i>
        </label>

        <div x-cloak x-show.important="editing" x-ref="editorShell" class="min-w-0">
            @if ($type === 'select')
                <select
                    x-ref="editor"
                    id="{{ $inputId }}"
                    wire:model="{{ $wireModel }}"
                    x-on:blur="commit()"
                    x-on:change="$nextTick(() => $el.blur())"
                    x-on:keydown.escape.prevent.stop="cancel()"
                    x-bind:disabled="saving"
                    data-autosave-visual
                    class="rt-ui-control min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-[border-color,box-shadow,background-color,color] duration-200 focus:border-rt-accent disabled:opacity-60 sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white"
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
                    data-autosave-visual
                    class="rt-ui-control min-h-24 w-full resize-y rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-[border-color,box-shadow,background-color,color] duration-200 focus:border-rt-accent disabled:opacity-60 sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white"
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
