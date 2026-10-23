<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'variant' => 'page',
    'rows' => 4,
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
    'variant' => 'page',
    'rows' => 4,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $resolvedVariant = in_array($variant, ['page', 'list', 'table', 'card'], true)
        ? $variant
        : 'page';
    $resolvedRows = max(2, min(8, (int) $rows));
?>

<div
    <?php echo e($attributes->class('rt-loading-skeleton')); ?>

    role="status"
    aria-live="polite"
    aria-busy="true"
    data-rt-loading-skeleton="<?php echo e($resolvedVariant); ?>"
>
    <span class="sr-only"><?php echo e(__('app.loading')); ?></span>

    <div class="rt-loading-skeleton__heading" aria-hidden="true">
        <span class="rt-loading-skeleton__eyebrow"></span>
        <span class="rt-loading-skeleton__title"></span>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($resolvedVariant === 'page'): ?>
        <div class="rt-loading-skeleton__cards" aria-hidden="true">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = range(1, 3); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $card): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <span class="rt-loading-skeleton__card">
                    <span class="rt-loading-skeleton__icon"></span>
                    <span class="rt-loading-skeleton__line rt-loading-skeleton__line--short"></span>
                    <span class="rt-loading-skeleton__line"></span>
                </span>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php elseif($resolvedVariant === 'card'): ?>
        <div class="rt-loading-skeleton__feature-card" aria-hidden="true">
            <span class="rt-loading-skeleton__icon"></span>
            <span class="rt-loading-skeleton__line rt-loading-skeleton__line--short"></span>
            <span class="rt-loading-skeleton__line"></span>
            <span class="rt-loading-skeleton__line rt-loading-skeleton__line--medium"></span>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($resolvedVariant !== 'card'): ?>
        <div
            class="rt-loading-skeleton__rows <?php echo e($resolvedVariant === 'table' ? 'rt-loading-skeleton__rows--table' : ''); ?>"
            aria-hidden="true"
        >
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = range(1, $resolvedRows); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <span class="rt-loading-skeleton__row">
                    <span class="rt-loading-skeleton__avatar"></span>
                    <span class="rt-loading-skeleton__row-copy">
                        <span class="rt-loading-skeleton__line <?php echo e($row % 2 === 0 ? 'rt-loading-skeleton__line--medium' : ''); ?>"></span>
                        <span class="rt-loading-skeleton__line rt-loading-skeleton__line--short"></span>
                    </span>
                    <span class="rt-loading-skeleton__action"></span>
                </span>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/loading/skeleton.blade.php ENDPATH**/ ?>