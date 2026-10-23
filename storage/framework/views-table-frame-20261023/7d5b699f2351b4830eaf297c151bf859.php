<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name',
    'idPrefix',
    'model' => 'tab',
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
    'name',
    'idPrefix',
    'model' => 'tab',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<section <?php echo e($attributes->class('rt-ops-panel__section')); ?> role="tabpanel" tabindex="0"
    id="<?php echo e($idPrefix); ?>-panel-<?php echo e($name); ?>" aria-labelledby="<?php echo e($idPrefix); ?>-tab-<?php echo e($name); ?>"
    x-show="<?php echo e($model); ?> === <?php echo \Illuminate\Support\Js::from((string) $name)->toHtml() ?>" data-panel-section="<?php echo e($name); ?>">
    <?php echo e($slot); ?>

</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/panel/tab.blade.php ENDPATH**/ ?>