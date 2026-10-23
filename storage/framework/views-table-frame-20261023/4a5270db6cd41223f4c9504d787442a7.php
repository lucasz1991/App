<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'options' => [],
    'value' => null,
    'action' => null,
    'label' => 'Ansicht wählen',
    'id' => null,
    'disabled' => false,
    'iconSet' => 'default',
    'showLabels' => false,
    'navigate' => false,
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
    'options' => [],
    'value' => null,
    'action' => null,
    'label' => 'Ansicht wählen',
    'id' => null,
    'disabled' => false,
    'iconSet' => 'default',
    'showLabels' => false,
    'navigate' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $action) ? $action : null;
    $isDisabled = filter_var($disabled, FILTER_VALIDATE_BOOL) || $safeAction === null;
    $selectedValue = is_string($value) || is_int($value) ? (string) $value : null;
    $normalizedOptions = [];
    $seenValues = [];
    foreach (is_array($options) ? $options : [] as $option) {
        if (!is_array($option) || !isset($option['value'], $option['label'], $option['icon'])
            || (!is_string($option['value']) && !is_int($option['value']))
            || !is_string($option['label']) || trim($option['label']) === ''
            || !is_string($option['icon']) || !preg_match('/^fa-[a-z0-9-]+$/D', $option['icon'])) {
            continue;
        }
        $optionValue = (string) $option['value'];
        if (in_array($optionValue, $seenValues, true)) continue;
        $seenValues[] = $optionValue;
        $normalizedOptions[] = [
            'value' => $optionValue,
            'label' => trim($option['label']),
            'compactLabel' => is_string($option['compactLabel'] ?? null) && trim($option['compactLabel']) !== '' ? trim($option['compactLabel']) : null,
            'icon' => $option['icon'],
            'href' => $navigate && is_string($option['href'] ?? null) ? $option['href'] : null,
            'disabled' => $isDisabled || filter_var($option['disabled'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }
    $enabledOptions = collect($normalizedOptions)->reject(fn ($option) => $option['disabled']);
    $tabValue = $enabledOptions->first(fn ($option) => $option['value'] === $selectedValue)['value'] ?? $enabledOptions->first()['value'] ?? null;
?>

<div
    <?php echo e($attributes->class(['rt-multi-toggle'])->merge(array_filter(['id' => $id]))); ?>

    role="group"
    aria-label="<?php echo e($label); ?>"
    <?php if($isDisabled): ?> aria-disabled="true" <?php endif; ?>
    <?php if($safeAction): ?> wire:loading.attr="aria-busy" wire:target="<?php echo e($safeAction); ?>" <?php endif; ?>
    data-multi-toggle
    <?php if($showLabels): ?> data-toggle-labels <?php endif; ?>
    x-id="['multi-toggle-tooltip']"
    x-data="{
        tooltipOpen: false,
        tooltipReady: false,
        tooltipText: '',
        tooltipTarget: null,
        touchInteraction: false,
        focusAfterLoad: null,
        wasBusy: false,
        observer: null,
        init() {
            this.observer = new MutationObserver(() => {
                if (this.$root.getAttribute('aria-busy') === 'true') {
                    this.wasBusy = true;
                    this.hideTooltip();
                    return;
                }
                if (!this.wasBusy) return;
                this.wasBusy = false;
                const key = this.focusAfterLoad;
                this.focusAfterLoad = null;
                this.$nextTick(() => {
                    if (key === null || (document.activeElement !== document.body && !this.$root.contains(document.activeElement))) return;
                    this.buttons().find(button => button.dataset.toggleValue === key)?.focus({ preventScroll: true });
                });
            });
            this.observer.observe(this.$root, { attributes: true, attributeFilter: ['aria-busy'] });
        },
        destroy() { this.observer?.disconnect(); },
        buttons() {
            return Array.from(this.$root.querySelectorAll('[data-multi-toggle-option]')).filter(button => !button.disabled);
        },
        rememberFocus(event) {
            const button = event.target.closest('[data-multi-toggle-option]');
            if (button && !button.disabled) this.focusAfterLoad = button.dataset.toggleValue;
        },
        handleKey(event) {
            const keys = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
            if (!keys.includes(event.key) || !event.target.closest('[data-multi-toggle-option]')) return;
            event.preventDefault();
            if (this.$root.getAttribute('aria-busy') === 'true') return;
            const buttons = this.buttons();
            if (!buttons.length) return;
            let index = buttons.indexOf(event.target.closest('[data-multi-toggle-option]'));
            const rtl = getComputedStyle(this.$root).direction === 'rtl';
            if (event.key === 'Home') index = 0;
            else if (event.key === 'End') index = buttons.length - 1;
            else {
                let step = ['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1;
                if (rtl && ['ArrowLeft', 'ArrowRight'].includes(event.key)) step *= -1;
                index = (index + step + buttons.length) % buttons.length;
            }
            buttons.forEach((button, buttonIndex) => button.tabIndex = buttonIndex === index ? 0 : -1);
            buttons[index].focus({ preventScroll: true });
            buttons[index].click();
        },
        showTooltip(button) {
            if (button.disabled || this.$root.getAttribute('aria-busy') === 'true') return;
            this.tooltipTarget = button;
            this.tooltipText = button.dataset.tooltipText;
            this.tooltipReady = false;
            this.tooltipOpen = true;
            this.$nextTick(() => {
                if (!this.tooltipOpen || this.tooltipTarget !== button) return;
                const tooltip = this.$refs.tooltip;
                const anchor = button.getBoundingClientRect();
                const viewport = window.visualViewport;
                const left = viewport?.offsetLeft || 0;
                const top = viewport?.offsetTop || 0;
                const width = viewport?.width || window.innerWidth;
                const height = viewport?.height || window.innerHeight;
                tooltip.style.maxWidth = Math.max(0, Math.min(288, width - 16)) + 'px';
                const bounds = tooltip.getBoundingClientRect();
                const x = Math.max(left + 8, Math.min(anchor.left + (anchor.width - bounds.width) / 2, left + width - bounds.width - 8));
                let y = anchor.top - bounds.height - 8;
                if (y < top + 8) y = anchor.bottom + 8;
                y = Math.max(top + 8, Math.min(y, top + height - bounds.height - 8));
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
                tooltip.style.zIndex = Math.max(250, Number(this.$root.closest('[data-rt-overlay-base]')?.dataset.rtOverlayBase || 0) + 30);
                this.tooltipReady = true;
            });
        },
        hideTooltip() { this.tooltipOpen = false; this.tooltipReady = false; this.tooltipTarget = null; },
    }"
    x-on:keydown="handleKey($event)"
    x-on:keydown.window="touchInteraction = false"
    x-on:pointerdown.capture="touchInteraction = $event.pointerType === 'touch'; if (touchInteraction) hideTooltip()"
    x-on:keydown.escape="hideTooltip()"
    x-on:click.capture="rememberFocus($event)"
    x-on:resize.window="hideTooltip()"
    x-on:scroll.window.capture="hideTooltip()"
    x-on:focusin.window="if (focusAfterLoad !== null && $event.target !== document.body && !$root.contains($event.target)) focusAfterLoad = null"
>
    <?php echo e($slot); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $normalizedOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php
            // Keep values raw until the single attribute-bag merge below.
            $buttonAttributes = [
                'type' => 'button',
                'class' => 'rt-multi-toggle__button',
                'data-multi-toggle-option' => '',
                'data-toggle-value' => $option['value'],
                'data-tooltip-text' => $option['label'],
                'aria-label' => $option['label'],
                'aria-pressed' => $selectedValue === $option['value'] ? 'true' : 'false',
                'tabindex' => !$option['disabled'] && $option['value'] === $tabValue ? '0' : '-1',
                'title' => $option['label'],
            ];
            if ($id) $buttonAttributes['wire:key'] = $id.'-'.substr(hash('sha256', $option['value']), 0, 16);
            if ($option['disabled']) {
                $buttonAttributes += ['disabled' => true, 'aria-disabled' => 'true'];
            } elseif ($option['href']) {
                unset($buttonAttributes['type'], $buttonAttributes['aria-pressed']);
                $buttonAttributes += [
                    'href' => $option['href'],
                    'wire:navigate' => true,
                    'aria-current' => $selectedValue === $option['value'] ? 'page' : null,
                ];
            } else {
                $buttonAttributes += [
                    'wire:click' => $safeAction.'('.\Illuminate\Support\Js::from($option['value'])->toHtml().')',
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => $safeAction,
                ];
            }
        ?>
        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'basic','attributes' => $attributes->only([])->merge($buttonAttributes),'xOn:pointerenter' => 'if ($event.pointerType !== \'touch\') showTooltip($el)','xOn:pointerleave' => 'if (document.activeElement !== $el) hideTooltip()','xOn:focus' => 'if (!touchInteraction) showTooltip($el)','xOn:blur' => 'hideTooltip()','xBind:title' => 'tooltipOpen && tooltipTarget === $el ? null : $el.dataset.tooltipText','xBind:ariaDescribedby' => 'tooltipOpen && tooltipTarget === $el ? $id(\'multi-toggle-tooltip\') : null']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'basic','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->only([])->merge($buttonAttributes)),'x-on:pointerenter' => 'if ($event.pointerType !== \'touch\') showTooltip($el)','x-on:pointerleave' => 'if (document.activeElement !== $el) hideTooltip()','x-on:focus' => 'if (!touchInteraction) showTooltip($el)','x-on:blur' => 'hideTooltip()','x-bind:title' => 'tooltipOpen && tooltipTarget === $el ? null : $el.dataset.tooltipText','x-bind:aria-describedby' => 'tooltipOpen && tooltipTarget === $el ? $id(\'multi-toggle-tooltip\') : null']); ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($iconSet === 'customer-portal'): ?>
                <?php if (isset($component)) { $__componentOriginal194a6f5deadc9477ab0b632fe0feb5cc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal194a6f5deadc9477ab0b632fe0feb5cc = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.customer-portal.icon','data' => ['name' => $option['icon']]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('customer-portal.icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($option['icon'])]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal194a6f5deadc9477ab0b632fe0feb5cc)): ?>
<?php $attributes = $__attributesOriginal194a6f5deadc9477ab0b632fe0feb5cc; ?>
<?php unset($__attributesOriginal194a6f5deadc9477ab0b632fe0feb5cc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal194a6f5deadc9477ab0b632fe0feb5cc)): ?>
<?php $component = $__componentOriginal194a6f5deadc9477ab0b632fe0feb5cc; ?>
<?php unset($__componentOriginal194a6f5deadc9477ab0b632fe0feb5cc); ?>
<?php endif; ?>
            <?php else: ?>
                <i class="far <?php echo e($option['icon']); ?>" aria-hidden="true"></i>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([$showLabels ? 'rt-multi-toggle__label' : 'sr-only', 'rt-multi-toggle__label--full' => $showLabels && $option['compactLabel']]); ?>"><?php echo e($option['label']); ?></span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showLabels && $option['compactLabel']): ?>
                <span class="rt-multi-toggle__label rt-multi-toggle__label--compact" aria-hidden="true"><?php echo e($option['compactLabel']); ?></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <template x-teleport="body" wire:ignore>
        <span
            x-ref="tooltip"
            x-bind:id="$id('multi-toggle-tooltip')"
            role="tooltip"
            class="rt-multi-toggle__tooltip"
            x-show="tooltipOpen"
            x-bind:style="{ visibility: tooltipReady ? 'visible' : 'hidden' }"
            x-text="tooltipText"
            x-cloak
        ></span>
    </template>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/buttons/multi-toggle.blade.php ENDPATH**/ ?>