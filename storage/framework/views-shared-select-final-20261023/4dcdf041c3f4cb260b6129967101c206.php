<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['kind' => 'assist']));

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

foreach (array_filter((['kind' => 'assist']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<template x-teleport="[data-rt-floating-launcher-group]">
    <div data-rt-floating-launcher-item="<?php echo e($kind === 'communication' ? 'communication' : 'assist'); ?>"
        <?php echo e($attributes->class(['rt-floating-launcher-item', 'rt-floating-launcher-item--communication' => $kind === 'communication'])); ?>>
        <?php echo e($slot); ?>

    </div>
</template>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/floating/launcher-item.blade.php ENDPATH**/ ?>