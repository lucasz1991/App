<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['customer' => null, 'size' => 32, 'initialsOnly' => false, 'portal' => false]));

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

foreach (array_filter((['customer' => null, 'size' => 32, 'initialsOnly' => false, 'portal' => false]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $size = max(16, min(96, (int) $size));
    $logo = !$initialsOnly ? $customer?->logoUrl((bool) $portal) : null;
    $name = $customer?->company_name ?: 'Unternehmen';
    $initials = $customer?->company_initials ?: '?';
?>
<span <?php echo e($attributes->class(['relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-rt-surface-muted font-semibold text-rt-muted ring-1 ring-inset ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70'])); ?>

    style="width:<?php echo e($size); ?>px;height:<?php echo e($size); ?>px;font-size:<?php echo e(max(10, (int) round($size * .34))); ?>px;" title="<?php echo e($name); ?>" aria-hidden="true" data-company-avatar>
    <span><?php echo e($initials); ?></span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($logo): ?><img class="absolute inset-0 h-full w-full bg-white object-contain p-1" src="<?php echo e($logo); ?>" alt="" loading="lazy" decoding="async" x-data x-on:error="$el.remove()" data-company-logo /><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</span>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/company-avatar.blade.php ENDPATH**/ ?>