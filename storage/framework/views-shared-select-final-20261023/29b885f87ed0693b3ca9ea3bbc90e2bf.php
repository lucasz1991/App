<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'disabled' => false,
    'placeholder' => null,
    'change' => null,
    'searchModel' => null,
    'value' => null,
    'action' => null,
    'counts' => [],
    'icon' => null,
    'variant' => 'default',
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'disabled' => false,
    'placeholder' => null,
    'change' => null,
    'searchModel' => null,
    'value' => null,
    'action' => null,
    'counts' => [],
    'icon' => null,
    'variant' => 'default',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
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
?>

<div
    x-data="{
        selected: <?php if($wireModel): ?> <?php if ((object) ($attributes->wire('model')) instanceof \Livewire\WireDirective) : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e($attributes->wire('model')->value()); ?>')<?php echo e($attributes->wire('model')->hasModifier('live') ? '.live' : ''); ?><?php else : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e($attributes->wire('model')); ?>')<?php endif; ?> <?php else: ?> <?php echo \Illuminate\Support\Js::from($initialValue)->toHtml() ?> <?php endif; ?>,
        options: [],
        counts: {},
        optionsObserver: null,
        init() {
            const syncOptions = () => {
                this.options = JSON.parse(this.$el.dataset.rtSelectOptions || '[]');
                this.counts = JSON.parse(this.$el.dataset.rtSelectCounts || '{}');
                <?php if(!$wireModel && !$xModel): ?>
                    this.selected = this.$el.dataset.rtSelectValue;
                <?php endif; ?>
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
            return current?.label || <?php echo \Illuminate\Support\Js::from((string) ($placeholder ?? __('app.please_select')))->toHtml() ?>;
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
            <?php if($safeAction): ?>
                this.$wire.call(<?php echo \Illuminate\Support\Js::from($safeAction)->toHtml() ?>, option.value);
            <?php endif; ?>
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
            this.$nextTick(() => document.getElementById(<?php echo \Illuminate\Support\Js::from($listboxId)->toHtml() ?> + '-option-' + this.activeIndex)?.focus());
        },
        selectActive() {
            this.choose(this.options[this.activeIndex]);
        },
    }"
    x-modelable="selected"
    <?php if($xModel): ?> x-model="<?php echo e($xModel); ?>" <?php endif; ?>
    <?php if($change): ?> @change="<?php echo e($change); ?>" <?php endif; ?>
    class="<?php echo e($outerClasses); ?>"
    data-rt-custom-select
    data-rt-select-options="<?php echo e(json_encode($options)); ?>"
    data-rt-select-counts="<?php echo e(json_encode($counts)); ?>"
    data-rt-select-value="<?php echo e($initialValue); ?>"
    <?php if($wireModel): ?>
        data-autosave-field
        data-autosave-model="<?php echo e($wireModel); ?>"
        data-autosave-field-id="<?php echo e($autosaveFieldId); ?>"
        data-autosave-state="idle"
        <?php if($attributes->get('data-autosave-instant') === 'true'): ?> data-autosave-instant="true" <?php endif; ?>
    <?php endif; ?>
>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($name): ?>
        <input
            x-ref="valueInput"
            type="hidden"
            name="<?php echo e($name); ?>"
            :value="selected ?? ''"
            <?php if($wireModel): ?>
                data-autosave-model="<?php echo e($wireModel); ?>"
                data-autosave-field-id="<?php echo e($autosaveFieldId); ?>"
                <?php if($attributes->get('data-autosave-instant') === 'true'): ?> data-autosave-instant="true" <?php endif; ?>
            <?php endif; ?>
        >
    <?php else: ?>
        <input
            x-ref="valueInput"
            type="hidden"
            :value="selected ?? ''"
            <?php if($wireModel): ?>
                data-autosave-model="<?php echo e($wireModel); ?>"
                data-autosave-field-id="<?php echo e($autosaveFieldId); ?>"
                <?php if($attributes->get('data-autosave-instant') === 'true'): ?> data-autosave-instant="true" <?php endif; ?>
            <?php endif; ?>
        >
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => $variant === 'default' ? 'left' : 'right','width' => $variant === 'default' ? 'full' : '64','dropdownId' => 'select-'.$id,'matchTriggerWidth' => $variant === 'default','contentRole' => '','triggerClasses' => 'inline-flex w-full','class' => 'w-full','contentClasses' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($variant === 'default' ? 'left' : 'right'),'width' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($variant === 'default' ? 'full' : '64'),'dropdown-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('select-'.$id),'match-trigger-width' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($variant === 'default'),'content-role' => '','trigger-classes' => 'inline-flex w-full','class' => 'w-full','content-classes' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
         <?php $__env->slot('trigger', null, []); ?> 
            <button
                id="<?php echo e($id); ?>"
                type="button"
                role="combobox"
                data-rt-select-trigger
                data-select-open="false"
                x-bind:data-select-open="open ? 'true' : 'false'"
                aria-haspopup="listbox"
                aria-controls="<?php echo e($listboxId); ?>"
                <?php if($attributes->has('title')): ?> title="<?php echo e($attributes->get('title')); ?>" <?php endif; ?>
                :aria-activedescendant="activeIndex >= 0 ? <?php echo \Illuminate\Support\Js::from($listboxId)->toHtml() ?> + '-option-' + activeIndex : null"
                <?php if(filled($controlAriaLabel)): ?>
                    aria-label="<?php echo e($controlAriaLabel); ?>"
                <?php else: ?>
                    :aria-label="selectedLabel"
                <?php endif; ?>
                <?php if(filled($controlAriaDescribedBy)): ?> aria-describedby="<?php echo e($controlAriaDescribedBy); ?>" <?php endif; ?>
                <?php if($controlAriaInvalid !== null): ?> aria-invalid="<?php echo e($controlAriaInvalid); ?>" <?php endif; ?>
                <?php if($attributes->has('required')): ?> aria-required="true" <?php endif; ?>
                <?php if($disabled): echo 'disabled'; endif; ?>
                <?php if($safeAction): ?> wire:loading.attr="disabled" wire:target="<?php echo e($safeAction); ?>" <?php endif; ?>
                <?php if($wireModel): ?> data-autosave-visual data-autosave-state="idle" <?php endif; ?>
                @keydown.arrow-down.prevent.stop="open = true; activeIndex = Math.max(0, options.findIndex(option => String(option.value) === String(selected ?? ''))); moveActive(0)"
                @keydown.arrow-up.prevent.stop="open = true; activeIndex = options.findIndex(option => String(option.value) === String(selected ?? '')); if (activeIndex < 0) activeIndex = options.length - 1; moveActive(0)"
                @keydown.home.prevent.stop="open = true; activeIndex = 0; moveActive(0)"
                @keydown.end.prevent.stop="open = true; activeIndex = options.length - 1; moveActive(0)"
                class="rt-ui-control rt-ui-field-control group flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-left text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-[border-color,box-shadow,background-color,color] duration-200 ease-rt-spring hover:border-rt-accent/50 hover:shadow-rt-sm focus:border-rt-accent disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-60 disabled:shadow-none sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas"
            >
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($icon): ?><i class="<?php echo e($icon); ?> shrink-0" aria-hidden="true"></i><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($variant !== 'icon'): ?>
                <span class="flex min-w-0 flex-1 items-center gap-2.5">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$icon): ?>
                    <i x-show="!selectedIcon && selectedIconClass" x-cloak :class="selectedIconClass" class="w-5 shrink-0 text-center" aria-hidden="true"></i>
                    <img
                        x-show="selectedIcon"
                        x-cloak
                        :src="selectedIcon || ''"
                        alt=""
                        class="h-4 w-6 shrink-0 rounded-[3px] object-cover shadow-rt-xs"
                        aria-hidden="true"
                    >
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <span class="min-w-0 flex-1 truncate" x-text="selectedLabel"></span>
                    <span x-show="selectedCount !== null" x-cloak x-text="selectedCount" class="text-xs tabular-nums"></span>
                </span>
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-rt-surface-muted text-rt-muted transition group-hover:text-rt-text dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:group-hover:text-rt-dark-text">
                    <i class="far fa-chevron-down text-xs transition-transform" :class="open && 'rotate-180'" aria-hidden="true"></i>
                </span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </button>
         <?php $__env->endSlot(); ?>

         <?php $__env->slot('content', null, []); ?> 
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($searchModel): ?>
                <div class="border-b border-rt-border/70 p-2 dark:border-rt-dark-border" data-rt-dropdown-keep-open>
                    <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['type' => 'search','wire:model.live.debounce.300ms' => ''.e($searchModel).'','placeholder' => 'Auftragsnummer oder Titel suchen','ariaLabel' => 'Aufträge durchsuchen','autocomplete' => 'off','xOn:keydown.arrowDown.prevent.stop' => 'activeIndex = 0; moveActive(0)','xOn:keydown.enter.prevent.stop' => true,'class' => 'w-full']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'search','wire:model.live.debounce.300ms' => ''.e($searchModel).'','placeholder' => 'Auftragsnummer oder Titel suchen','aria-label' => 'Aufträge durchsuchen','autocomplete' => 'off','x-on:keydown.arrow-down.prevent.stop' => 'activeIndex = 0; moveActive(0)','x-on:keydown.enter.prevent.stop' => true,'class' => 'w-full']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $attributes = $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $component = $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
                    <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Bis zu 25 Treffer · Weitere Aufträge über die Suche.</p>
                    <span wire:loading wire:target="<?php echo e($searchModel); ?>" class="text-xs" role="status">Suche läuft …</span>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <div
                id="<?php echo e($listboxId); ?>"
                role="listbox"
                :aria-labelledby="<?php echo \Illuminate\Support\Js::from($id)->toHtml() ?>"
                class="rt-select-options space-y-1 overflow-y-auto p-1.5"
                @keydown.arrow-down.prevent.stop="moveActive(1)"
                @keydown.arrow-up.prevent.stop="moveActive(-1)"
                @keydown.home.prevent.stop="activeIndex = 0; moveActive(0)"
                @keydown.end.prevent.stop="activeIndex = options.length - 1; moveActive(0)"
                @keydown.enter.prevent.stop="selectActive(); close(); $nextTick(() => document.getElementById(<?php echo \Illuminate\Support\Js::from($id)->toHtml() ?>)?.focus())"
                @keydown.space.prevent.stop="selectActive(); close(); $nextTick(() => document.getElementById(<?php echo \Illuminate\Support\Js::from($id)->toHtml() ?>)?.focus())"
            >
                <template x-for="(option, index) in options" :key="String(option.value) + '-' + index">
                    <button
                        type="button"
                        role="option"
                        :id="<?php echo \Illuminate\Support\Js::from($listboxId)->toHtml() ?> + '-option-' + index"
                        :aria-selected="String(option.value) === String(selected ?? '')"
                        :disabled="option.disabled"
                        @mouseenter="activeIndex = index"
                        @focus="activeIndex = index"
                        @click.stop="choose(option); close(); $nextTick(() => document.getElementById(<?php echo \Illuminate\Support\Js::from($id)->toHtml() ?>)?.focus())"
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
         <?php $__env->endSlot(); ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $attributes = $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $component = $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/forms/select.blade.php ENDPATH**/ ?>