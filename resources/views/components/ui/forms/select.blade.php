@props([
    'disabled' => false,
    'placeholder' => null,
    'change' => null,
    'searchModel' => null,
    'value' => null,
    'action' => null,
    'counts' => [],
    'icon' => null,
    'variant' => 'default',
])

@php
    $slotHtml = trim((string) $slot);
    $options = [];

    if ($placeholder !== null) {
        $options[] = [
            'value' => '',
            'label' => (string) $placeholder,
            'icon' => null,
            'iconClass' => null,
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
                'iconClass' => trim($option->getAttribute('data-icon-class')) ?: null,
                'disabled' => $option->hasAttribute('disabled'),
                'selected' => $option->hasAttribute('selected'),
            ];
        }
    }

    $selectedOption = collect($options)->firstWhere('selected', true) ?? collect($options)->first();
    $initialValue = (string) ($value ?? $selectedOption['value'] ?? '');
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $action) ? $action : null;
    $disabled = $disabled || ($action !== null && $safeAction === null);
    $variant = in_array($variant, ['compact', 'icon'], true) ? $variant : 'default';
    $wireModel = $attributes->wire('model')->value();
    $xModel = $attributes->get('x-model');
    $name = $attributes->get('name');
    $id = $attributes->get('id')
        ?: 'rt-select-'.substr(md5(($wireModel ?: $xModel ?: $name ?: '').$slotHtml), 0, 10);
    $listboxId = $id.'-listbox';
    $controlAriaLabel = $attributes->get('aria-label');
    $controlAriaDescribedBy = $attributes->get('aria-describedby');
    $controlAriaInvalid = $attributes->get('aria-invalid');
    $outerClasses = trim('min-w-0 rt-select rt-select--'.$variant.' '.$attributes->get('class', ''));
    $autosaveFieldId = (string) ($attributes->get('data-autosave-field-id') ?: $id);
@endphp

<div
    x-data="{
        selected: @if($wireModel) @entangle($attributes->wire('model')) @else @js($initialValue) @endif,
        options: [],
        counts: {},
        optionsObserver: null,
        init() {
            const syncOptions = () => {
                this.options = JSON.parse(this.$el.dataset.rtSelectOptions || '[]');
                this.counts = JSON.parse(this.$el.dataset.rtSelectCounts || '{}');
                @if(!$wireModel && !$xModel)
                    this.selected = this.$el.dataset.rtSelectValue;
                @endif
                this.activeIndex = -1;
            };
            syncOptions();
            this.optionsObserver = new MutationObserver(syncOptions);
            this.optionsObserver.observe(this.$el, { attributes: true, attributeFilter: ['data-rt-select-options', 'data-rt-select-counts', 'data-rt-select-value'] });
        },
        destroy() { this.optionsObserver?.disconnect(); },
        activeIndex: -1,
        get selectedCount() { return this.counts[String(this.selected ?? '')] ?? null; },
        get selectedLabel() {
            const current = this.options.find(option => String(option.value) === String(this.selected ?? ''));
            return current?.label || @js((string) ($placeholder ?? __('app.please_select')));
        },
        get selectedIcon() {
            const current = this.options.find(option => String(option.value) === String(this.selected ?? ''));
            return current?.icon || null;
        },
        get selectedIconClass() {
            return this.options.find(option => String(option.value) === String(this.selected ?? ''))?.iconClass || null;
        },
        choose(option) {
            if (!option || option.disabled) return;
            this.selected = option.value;
            @if($safeAction)
                this.$wire.call(@js($safeAction), option.value);
            @endif
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
    data-rt-select-options="{{ json_encode($options) }}"
    data-rt-select-counts="{{ json_encode($counts) }}"
    data-rt-select-value="{{ $initialValue }}"
    @if($wireModel)
        data-autosave-field
        data-autosave-model="{{ $wireModel }}"
        data-autosave-field-id="{{ $autosaveFieldId }}"
        data-autosave-state="idle"
        @if($attributes->get('data-autosave-instant') === 'true') data-autosave-instant="true" @endif
    @endif
>
    @if($name)
        <input
            x-ref="valueInput"
            type="hidden"
            name="{{ $name }}"
            :value="selected ?? ''"
            @if($wireModel)
                data-autosave-model="{{ $wireModel }}"
                data-autosave-field-id="{{ $autosaveFieldId }}"
                @if($attributes->get('data-autosave-instant') === 'true') data-autosave-instant="true" @endif
            @endif
        >
    @else
        <input
            x-ref="valueInput"
            type="hidden"
            :value="selected ?? ''"
            @if($wireModel)
                data-autosave-model="{{ $wireModel }}"
                data-autosave-field-id="{{ $autosaveFieldId }}"
                @if($attributes->get('data-autosave-instant') === 'true') data-autosave-instant="true" @endif
            @endif
        >
    @endif

    <x-ui.dropdown.anchor-dropdown
        :align="$variant === 'default' ? 'left' : 'right'"
        :width="$variant === 'default' ? 'full' : '64'"
        :dropdown-id="'select-'.$id"
        :match-trigger-width="$variant === 'default'"
        content-role=""
        trigger-classes="inline-flex w-full"
        class="w-full"
        content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
    >
        <x-slot:trigger>
            <button
                id="{{ $id }}"
                type="button"
                role="combobox"
                data-rt-select-trigger
                data-select-open="false"
                x-bind:data-select-open="open ? 'true' : 'false'"
                aria-haspopup="listbox"
                aria-controls="{{ $listboxId }}"
                @if($attributes->has('title')) title="{{ $attributes->get('title') }}" @endif
                :aria-activedescendant="activeIndex >= 0 ? @js($listboxId) + '-option-' + activeIndex : null"
                @if (filled($controlAriaLabel))
                    aria-label="{{ $controlAriaLabel }}"
                @else
                    :aria-label="selectedLabel"
                @endif
                @if (filled($controlAriaDescribedBy)) aria-describedby="{{ $controlAriaDescribedBy }}" @endif
                @if ($controlAriaInvalid !== null) aria-invalid="{{ $controlAriaInvalid }}" @endif
                @if($attributes->has('required')) aria-required="true" @endif
                @disabled($disabled)
                @if($safeAction) wire:loading.attr="disabled" wire:target="{{ $safeAction }}" @endif
                @if($wireModel) data-autosave-visual data-autosave-state="idle" @endif
                @keydown.arrow-down.prevent.stop="open = true; activeIndex = Math.max(0, options.findIndex(option => String(option.value) === String(selected ?? ''))); moveActive(0)"
                @keydown.arrow-up.prevent.stop="open = true; activeIndex = options.findIndex(option => String(option.value) === String(selected ?? '')); if (activeIndex < 0) activeIndex = options.length - 1; moveActive(0)"
                @keydown.home.prevent.stop="open = true; activeIndex = 0; moveActive(0)"
                @keydown.end.prevent.stop="open = true; activeIndex = options.length - 1; moveActive(0)"
                class="rt-ui-control rt-ui-field-control group flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-left text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-[border-color,box-shadow,background-color,color] duration-200 ease-rt-spring hover:border-rt-accent/50 hover:shadow-rt-sm focus:border-rt-accent disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-60 disabled:shadow-none sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas"
            >
                @if($icon)<i class="{{ $icon }} shrink-0" aria-hidden="true"></i>@endif
                @if($variant !== 'icon')
                <span class="flex min-w-0 flex-1 items-center gap-2.5">
                    @if(!$icon)
                    <i x-show="!selectedIcon && selectedIconClass" x-cloak :class="selectedIconClass" class="w-5 shrink-0 text-center" aria-hidden="true"></i>
                    <img
                        x-show="selectedIcon"
                        x-cloak
                        :src="selectedIcon || ''"
                        alt=""
                        class="h-4 w-6 shrink-0 rounded-[3px] object-cover shadow-rt-xs"
                        aria-hidden="true"
                    >
                    @endif
                    <span class="min-w-0 flex-1 truncate" x-text="selectedLabel"></span>
                    <span x-show="selectedCount !== null" x-cloak x-text="selectedCount" class="text-xs tabular-nums"></span>
                </span>
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-rt-surface-muted text-rt-muted transition group-hover:text-rt-text dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:group-hover:text-rt-dark-text">
                    <i class="far fa-chevron-down text-xs transition-transform" :class="open && 'rotate-180'" aria-hidden="true"></i>
                </span>
                @endif
            </button>
        </x-slot:trigger>

        <x-slot:content>
            @if($searchModel)
                <div class="border-b border-rt-border/70 p-2 dark:border-rt-dark-border" data-rt-dropdown-keep-open>
                    <x-ui.forms.input type="search" wire:model.live.debounce.300ms="{{ $searchModel }}" placeholder="Auftragsnummer oder Titel suchen" aria-label="Aufträge durchsuchen" autocomplete="off"
                        x-on:keydown.arrow-down.prevent.stop="activeIndex = 0; moveActive(0)" x-on:keydown.enter.prevent.stop class="w-full" />
                    <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Bis zu 25 Treffer · Weitere Aufträge über die Suche.</p>
                    <span wire:loading wire:target="{{ $searchModel }}" class="text-xs" role="status">Suche läuft …</span>
                </div>
            @endif
            <div
                id="{{ $listboxId }}"
                role="listbox"
                :aria-labelledby="@js($id)"
                class="rt-select-options space-y-1 overflow-y-auto p-1.5"
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
                        class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium outline-none transition focus:ring-2 focus:ring-inset focus:ring-rt-accent/35 disabled:cursor-not-allowed disabled:opacity-45 dark:focus:ring-rt-dark-accent/40"
                    >
                        <span x-show="option.icon || option.iconClass" x-cloak class="flex h-5 w-7 shrink-0 items-center justify-center">
                            <i x-show="!option.icon && option.iconClass" :class="option.iconClass" aria-hidden="true"></i>
                            <img
                                x-show="option.icon"
                                :src="option.icon || ''"
                                alt=""
                                class="h-4 w-6 rounded-[3px] object-cover shadow-rt-xs"
                                aria-hidden="true"
                            >
                        </span>
                        <span class="min-w-0 flex-1 break-words" x-text="option.label"></span>
                        <span x-show="counts[String(option.value)] !== undefined" x-text="counts[String(option.value)]" class="text-xs tabular-nums"></span>
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
