@props([
    'disabled' => false,
    'placeholder' => null,
    'change' => null,
])

@php
    $slotHtml = trim((string) $slot);
    $options = [];

    if ($placeholder !== null) {
        $options[] = [
            'value' => '',
            'label' => (string) $placeholder,
            'icon' => null,
            'disabled' => false,
            'selected' => false,
        ];
    }

    if ($slotHtml !== '') {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><'.'select>'.$slotHtml.'</'.'select>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('option') as $option) {
            $label = trim(preg_replace('/\s+/u', ' ', $option->textContent ?? '') ?? '');
            $options[] = [
                'value' => $option->hasAttribute('value') ? $option->getAttribute('value') : $label,
                'label' => $label,
                'icon' => $option->hasAttribute('data-icon') ? $option->getAttribute('data-icon') : null,
                'disabled' => $option->hasAttribute('disabled'),
                'selected' => $option->hasAttribute('selected'),
            ];
        }
    }

    $selectedOption = collect($options)->firstWhere('selected', true) ?? collect($options)->first();
    $initialValue = (string) ($selectedOption['value'] ?? '');
    $wireModel = $attributes->wire('model')->value();
    $xModel = $attributes->get('x-model');
    $name = $attributes->get('name');
    $id = $attributes->get('id')
        ?: 'rt-select-'.substr(md5(($wireModel ?: $xModel ?: $name ?: '').$slotHtml), 0, 10);
    $listboxId = $id.'-listbox';
    $outerClasses = trim('min-w-0 '.$attributes->get('class', ''));
@endphp

<div
    x-data="{
        selected: @if($wireModel) @entangle($attributes->wire('model')) @else @js($initialValue) @endif,
        options: @js($options),
        activeIndex: -1,
        get selectedLabel() {
            const current = this.options.find(option => String(option.value) === String(this.selected ?? ''));
            return current?.label || @js((string) ($placeholder ?? __('app.please_select')));
        },
        get selectedIcon() {
            const current = this.options.find(option => String(option.value) === String(this.selected ?? ''));
            return current?.icon || null;
        },
        choose(option) {
            if (!option || option.disabled) return;
            this.selected = option.value;
            this.activeIndex = this.options.findIndex(item => String(item.value) === String(option.value));
            this.$nextTick(() => {
                this.$refs.valueInput?.dispatchEvent(new Event('input', { bubbles: true }));
                this.$refs.valueInput?.dispatchEvent(new Event('change', { bubbles: true }));
            });
        },
        moveActive(direction) {
            if (!this.options.length) return;
            let next = this.activeIndex;
            for (let attempts = 0; attempts !== this.options.length; attempts += 1) {
                next = (next + direction + this.options.length) % this.options.length;
                if (!this.options[next]?.disabled) break;
            }
            this.activeIndex = Math.max(0, next);
            this.$nextTick(() => document.getElementById(@js($listboxId) + '-option-' + this.activeIndex)?.focus());
        },
        selectActive() {
            this.choose(this.options[this.activeIndex]);
        },
    }"
    x-modelable="selected"
    @if($xModel) x-model="{{ $xModel }}" @endif
    @if($change) @change="{{ $change }}" @endif
    class="{{ $outerClasses }}"
    data-rt-custom-select
>
    @if($name)
        <input x-ref="valueInput" type="hidden" name="{{ $name }}" :value="selected ?? ''">
    @else
        <input x-ref="valueInput" type="hidden" :value="selected ?? ''">
    @endif

    <x-ui.dropdown.anchor-dropdown
        align="left"
        width="full"
        :match-trigger-width="true"
        trigger-classes="inline-flex w-full"
        class="w-full"
        content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    >
        <x-slot:trigger>
            <button
                id="{{ $id }}"
                type="button"
                role="combobox"
                aria-haspopup="listbox"
                aria-controls="{{ $listboxId }}"
                :aria-activedescendant="activeIndex >= 0 ? @js($listboxId) + '-option-' + activeIndex : null"
                :aria-label="selectedLabel"
                @if($attributes->has('required')) aria-required="true" @endif
                @disabled($disabled)
                @keydown.arrow-down.prevent.stop="open = true; activeIndex = Math.max(0, options.findIndex(option => String(option.value) === String(selected ?? ''))); moveActive(0)"
                @keydown.arrow-up.prevent.stop="open = true; activeIndex = options.findIndex(option => String(option.value) === String(selected ?? '')); if (activeIndex < 0) activeIndex = options.length - 1; moveActive(0)"
                @keydown.home.prevent.stop="open = true; activeIndex = 0; moveActive(0)"
                @keydown.end.prevent.stop="open = true; activeIndex = options.length - 1; moveActive(0)"
                class="rt-ui-control group flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-left text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:border-rt-dark-accent dark:focus:ring-rt-dark-accent/20 dark:disabled:bg-rt-dark-canvas"
            >
                <span class="flex min-w-0 flex-1 items-center gap-2.5">
                    <img
                        x-show="selectedIcon"
                        x-cloak
                        :src="selectedIcon || ''"
                        alt=""
                        class="h-4 w-6 shrink-0 rounded-[3px] object-cover shadow-rt-xs"
                        aria-hidden="true"
                    >
                    <span class="min-w-0 flex-1 truncate" x-text="selectedLabel"></span>
                </span>
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-rt-surface-muted text-rt-muted transition group-hover:text-rt-text dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:group-hover:text-rt-dark-text">
                    <i class="far fa-chevron-down text-xs transition-transform" :class="open && 'rotate-180'" aria-hidden="true"></i>
                </span>
            </button>
        </x-slot:trigger>

        <x-slot:content>
            <div
                id="{{ $listboxId }}"
                role="listbox"
                :aria-labelledby="@js($id)"
                class="max-h-72 space-y-1 overflow-y-auto p-1.5"
                @keydown.arrow-down.prevent.stop="moveActive(1)"
                @keydown.arrow-up.prevent.stop="moveActive(-1)"
                @keydown.home.prevent.stop="activeIndex = 0; moveActive(0)"
                @keydown.end.prevent.stop="activeIndex = options.length - 1; moveActive(0)"
                @keydown.enter.prevent.stop="selectActive(); close(); $nextTick(() => document.getElementById(@js($id))?.focus())"
                @keydown.space.prevent.stop="selectActive(); close(); $nextTick(() => document.getElementById(@js($id))?.focus())"
            >
                <template x-for="(option, index) in options" :key="String(option.value) + '-' + index">
                    <button
                        type="button"
                        role="option"
                        :id="@js($listboxId) + '-option-' + index"
                        :aria-selected="String(option.value) === String(selected ?? '')"
                        :disabled="option.disabled"
                        @mouseenter="activeIndex = index"
                        @focus="activeIndex = index"
                        @click.stop="choose(option); close(); $nextTick(() => document.getElementById(@js($id))?.focus())"
                        :class="String(option.value) === String(selected ?? '')
                            ? 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent'
                            : 'text-rt-text hover:bg-rt-surface-muted dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted'"
                        class="flex min-h-10 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium outline-none transition focus:ring-2 focus:ring-inset focus:ring-rt-accent/35 disabled:cursor-not-allowed disabled:opacity-45 dark:focus:ring-rt-dark-accent/40"
                    >
                        <span class="flex h-5 w-7 shrink-0 items-center justify-center">
                            <img
                                x-show="option.icon"
                                :src="option.icon || ''"
                                alt=""
                                class="h-4 w-6 rounded-[3px] object-cover shadow-rt-xs"
                                aria-hidden="true"
                            >
                        </span>
                        <span class="min-w-0 flex-1 break-words" x-text="option.label"></span>
                        <i
                            x-show="String(option.value) === String(selected ?? '')"
                            class="far fa-check shrink-0 text-xs"
                            aria-hidden="true"
                        ></i>
                    </button>
                </template>
            </div>
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
</div>
