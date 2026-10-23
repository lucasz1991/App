<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'tabs' => [],
    'label',
    'idPrefix',
    'model' => 'tab',
    // Server-owned lazy panels use manual activation: focus alone never loads data.
    'action' => null,
    'active' => null,
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
    'label',
    'idPrefix',
    'model' => 'tab',
    // Server-owned lazy panels use manual activation: focus alone never loads data.
    'action' => null,
    'active' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div <?php echo e($attributes->class('rt-ops-panel__tabs')); ?> role="tablist" aria-label="<?php echo e($label); ?>"
    x-on:keydown.arrow-right.prevent="$focus.within($el).wrap().next()"
    x-on:keydown.arrow-left.prevent="$focus.within($el).wrap().previous()"
    x-on:keydown.home.prevent="$focus.within($el).first()"
    x-on:keydown.end.prevent="$focus.within($el).last()">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tabs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $tab): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <button type="button" role="tab" class="rt-ops-panel__tab"
            <?php if(filled($tab['icon'] ?? null)): ?> title="<?php echo e($tab['label']); ?>" <?php endif; ?>
            id="<?php echo e($idPrefix); ?>-tab-<?php echo e($key); ?>" aria-controls="<?php echo e($idPrefix); ?>-panel-<?php echo e($key); ?>"
            aria-selected="<?php echo e(($action ? (string) $active === (string) $key : $loop->first) ? 'true' : 'false'); ?>" tabindex="<?php echo e(($action ? (string) $active === (string) $key : $loop->first) ? '0' : '-1'); ?>"
            <?php if(! $action): ?>
                x-bind:aria-selected="<?php echo e($model); ?> === <?php echo \Illuminate\Support\Js::from((string) $key)->toHtml() ?> ? 'true' : 'false'"
                x-bind:tabindex="<?php echo e($model); ?> === <?php echo \Illuminate\Support\Js::from((string) $key)->toHtml() ?> ? 0 : -1"
                x-on:click="<?php echo e($model); ?> = <?php echo \Illuminate\Support\Js::from((string) $key)->toHtml() ?>"
                x-on:focus="<?php echo e($model); ?> = <?php echo \Illuminate\Support\Js::from((string) $key)->toHtml() ?>"
            <?php else: ?>
                x-on:click="await $wire.call(<?php echo \Illuminate\Support\Js::from($action)->toHtml() ?>, <?php echo \Illuminate\Support\Js::from((string) $key)->toHtml() ?>); if ($el.isConnected) $el.focus({ preventScroll: true })"
                wire:loading.attr="disabled" wire:target="<?php echo e($action); ?>"
            <?php endif; ?>
            data-panel-tab="<?php echo e($key); ?>">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($tab['icon'] ?? null)): ?>
                <i class="<?php echo e($tab['icon']); ?>" aria-hidden="true"></i>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <span><?php echo e($tab['label']); ?></span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($tab['countExpression'] ?? null)): ?>
                <span class="rt-ops-panel__count" x-text="<?php echo e($tab['countExpression']); ?>"><?php echo e($tab['count'] ?? ''); ?></span>
            <?php elseif(isset($tab['count']) && $tab['count'] !== '' && $tab['count'] !== null): ?>
                <span class="rt-ops-panel__count"><?php echo e($tab['count']); ?></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! empty($tab['alert'])): ?>
                <span class="rt-ops-panel__alert" aria-hidden="true"></span><span class="sr-only">Enthält Hinweise</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </button>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($selectionIndicator)): ?>
        <?php echo e($selectionIndicator); ?>

    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/panel/tabs.blade.php ENDPATH**/ ?>