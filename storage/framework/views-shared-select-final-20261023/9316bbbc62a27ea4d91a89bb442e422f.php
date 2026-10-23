<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'tabs' => [],
    'selected' => null,
    'action' => null,
    'idPrefix' => 'floating-window',
    'controls' => null,
    'label' => 'Fensterbereiche',
    'appearance' => 'window',
    'clientState' => null,
    'clientAction' => null,
    'clientKeyboardAction' => null,
    'tabDataAttribute' => null,
    'tabClass' => null,
    'countClass' => null,
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
    'tabs' => [],
    'selected' => null,
    'action' => null,
    'idPrefix' => 'floating-window',
    'controls' => null,
    'label' => 'Fensterbereiche',
    'appearance' => 'window',
    'clientState' => null,
    'clientAction' => null,
    'clientKeyboardAction' => null,
    'tabDataAttribute' => null,
    'tabClass' => null,
    'countClass' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $validIdentifier = static fn ($value) => is_string($value) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $value);
    $client = $validIdentifier($clientState) && $validIdentifier($clientAction) && $validIdentifier($clientKeyboardAction);
    $safeAction = $validIdentifier($action) ? $action : null;
    $safeTabs = array_filter(is_array($tabs) ? $tabs : [], static fn ($tab, $key) => is_array($tab) && preg_match('/\A[a-z][a-z0-9-]*\z/', (string) $key), ARRAY_FILTER_USE_BOTH);
    $tabValue = array_key_exists($selected ?? '', $safeTabs) ? $selected : array_key_first($safeTabs);
    $dataAttribute = is_string($tabDataAttribute) && preg_match('/\Adata-[a-z][a-z0-9-]*\z/', $tabDataAttribute) ? $tabDataAttribute : null;
    $appearance = $appearance === 'surface' ? 'surface' : 'window';
?>
<nav <?php echo e($attributes->class(['rt-window-tabs', 'rt-window-tabs--'.$appearance])); ?> role="tablist" aria-label="<?php echo e($label); ?>" aria-orientation="horizontal"
    data-window-tabs wire:key="<?php echo e($idPrefix); ?>-navigation"
    <?php if (! ($client)): ?>
    wire:loading.attr="aria-busy" x-id="['window-tabs-tooltip']"
    x-data="{
        tooltipOpen: false, tooltipReady: false, tooltipText: '', tooltipTarget: null, touchInteraction: false, observer: null, focusAfterLoad: null, wasBusy: false,
        init() {
            this.observer = new MutationObserver(() => {
                if (this.$root.getAttribute('aria-busy') === 'true') { this.wasBusy = true; this.hideTooltip(); return; }
                if (!this.wasBusy) return;
                this.wasBusy = false;
                const key = this.focusAfterLoad; this.focusAfterLoad = null;
                this.$nextTick(() => {
                    if (key === null || (document.activeElement !== document.body && !this.$root.contains(document.activeElement))) return;
                    this.buttons().find(button => button.dataset.tab === key)?.focus({ preventScroll: true });
                });
            });
            this.observer.observe(this.$root, { attributes: true, attributeFilter: ['aria-busy'] });
        },
        destroy() { this.observer?.disconnect(); },
        buttons() { return Array.from(this.$root.querySelectorAll('[data-window-tab]')).filter(button => !button.disabled); },
        rememberFocus(event) { const button = event.target.closest('[data-window-tab]'); if (button && !button.disabled) this.focusAfterLoad = button.dataset.tab; },
        handleKey(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || !event.target.closest('[data-window-tab]')) return;
            event.preventDefault();
            if (this.$root.getAttribute('aria-busy') === 'true') return;
            const buttons = this.buttons();
            if (!buttons.length) return;
            let index = buttons.indexOf(event.target.closest('[data-window-tab]'));
            if (event.key === 'Home') index = 0;
            else if (event.key === 'End') index = buttons.length - 1;
            else {
                const rtl = getComputedStyle(this.$root).direction === 'rtl';
                const step = (event.key === 'ArrowRight' ? 1 : -1) * (rtl ? -1 : 1);
                index = (index + step + buttons.length) % buttons.length;
            }
            buttons.forEach((button, buttonIndex) => button.tabIndex = buttonIndex === index ? 0 : -1);
            buttons[index].focus({ preventScroll: true });
        },
        showTooltip(button) {
            if (button.disabled || this.$root.getAttribute('aria-busy') === 'true') return;
            if (button.querySelector('.rt-window-tabs__label')?.textContent.trim() === button.dataset.tooltipText) return;
            this.tooltipTarget = button; this.tooltipText = button.dataset.tooltipText; this.tooltipReady = false; this.tooltipOpen = true;
            this.$nextTick(() => {
                if (!this.tooltipOpen || this.tooltipTarget !== button) return;
                const tooltip = this.$refs.tooltip, anchor = button.getBoundingClientRect(), viewport = window.visualViewport;
                const left = viewport?.offsetLeft || 0, top = viewport?.offsetTop || 0;
                const width = viewport?.width || window.innerWidth, height = viewport?.height || window.innerHeight;
                tooltip.style.maxWidth = Math.max(0, Math.min(288, width - 16)) + 'px';
                const bounds = tooltip.getBoundingClientRect();
                const x = Math.max(left + 8, Math.min(anchor.left + (anchor.width - bounds.width) / 2, left + width - bounds.width - 8));
                let y = anchor.bottom + 8;
                if (y + bounds.height > top + height - 8) y = anchor.top - bounds.height - 8;
                y = Math.max(top + 8, Math.min(y, top + height - bounds.height - 8));
                tooltip.style.left = x + 'px'; tooltip.style.top = y + 'px';
                tooltip.style.zIndex = Math.max(250, Number(this.$root.closest('[data-rt-overlay-base]')?.dataset.rtOverlayBase || 0) + 30);
                this.tooltipReady = true;
            });
        },
        hideTooltip() { this.tooltipOpen = false; this.tooltipReady = false; this.tooltipTarget = null; },
    }"
    x-on:keydown="handleKey($event)" x-on:keydown.window="touchInteraction = false"
    x-on:pointerdown.capture="touchInteraction = $event.pointerType === 'touch'; if (touchInteraction) hideTooltip()"
    x-on:keydown.escape="hideTooltip()" x-on:click.capture="rememberFocus($event); hideTooltip()"
    x-on:resize.window="hideTooltip()" x-on:scroll.window.capture="hideTooltip()"
    x-on:focusin.window="if (focusAfterLoad !== null && $event.target !== document.body && !$root.contains($event.target)) focusAfterLoad = null"
    <?php else: ?>
    x-on:keydown="<?php echo e($clientKeyboardAction); ?>($event)"
    <?php endif; ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $safeTabs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $tab): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php
            $tabLabel = (string) ($tab['label'] ?? $key);
            $accessibleLabel = (string) ($tab['accessibleLabel'] ?? $tabLabel);
            $count = max(0, (int) ($tab['count'] ?? 0));
        ?>
        <button type="button" class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-window-tabs__tab', $tabClass]); ?>" role="tab" id="<?php echo e($idPrefix); ?>-tab-<?php echo e($key); ?>"
            aria-controls="<?php echo e($tab['controls'] ?? $controls); ?>" aria-label="<?php echo e($accessibleLabel); ?>" title="<?php echo e($accessibleLabel); ?>"
            data-window-tab data-tab="<?php echo e($key); ?>" <?php if($dataAttribute): ?> <?php echo e($dataAttribute); ?>="<?php echo e($key); ?>" <?php endif; ?>
            wire:key="<?php echo e($idPrefix); ?>-nav-<?php echo e($key); ?>"
            <?php if($client): ?>
                x-bind:aria-selected="(<?php echo e($clientState); ?> === '<?php echo e($key); ?>').toString()" x-bind:tabindex="<?php echo e($clientState); ?> === '<?php echo e($key); ?>' ? 0 : -1"
                x-on:click="<?php echo e($clientAction); ?>('<?php echo e($key); ?>')"
            <?php else: ?>
                aria-selected="<?php echo e($tabValue === $key ? 'true' : 'false'); ?>" tabindex="<?php echo e($tabValue === $key && $safeAction ? '0' : '-1'); ?>"
                data-tooltip-text="<?php echo e($accessibleLabel); ?>"
                <?php if($safeAction): ?> wire:click="<?php echo e($safeAction); ?>('<?php echo e($key); ?>')" wire:loading.attr="disabled" <?php else: ?> disabled <?php endif; ?>
                x-on:pointerenter="if ($event.pointerType !== 'touch') showTooltip($el)" x-on:pointerleave="if (document.activeElement !== $el) hideTooltip()"
                x-on:focus="if (!touchInteraction) showTooltip($el)" x-on:blur="hideTooltip()"
                x-bind:title="tooltipOpen && tooltipTarget === $el ? null : $el.dataset.tooltipText"
                x-bind:aria-describedby="tooltipOpen && tooltipTarget === $el ? $id('window-tabs-tooltip') : null"
            <?php endif; ?>>
            <i class="far <?php echo e($tab['icon'] ?? 'fa-circle'); ?>" aria-hidden="true"></i>
            <span class="rt-window-tabs__label"><?php echo e($tabLabel); ?></span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($count > 0): ?><span class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-window-tabs__count', $countClass]); ?>" aria-hidden="true"><?php echo e($count > 99 ? '99+' : $count); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </button>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($client)): ?>
    <template x-teleport="body" wire:ignore>
        <span x-ref="tooltip" x-bind:id="$id('window-tabs-tooltip')" role="tooltip" class="rt-window-tabs__tooltip"
            x-show="tooltipOpen" x-bind:style="{ visibility: tooltipReady ? 'visible' : 'hidden' }" x-text="tooltipText" x-cloak></span>
    </template>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</nav>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/floating/window-tabs.blade.php ENDPATH**/ ?>