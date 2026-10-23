<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['count' => 0]));

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

foreach (array_filter((['count' => 0]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<div class="rt-service-selection-controls" data-service-selection-controls>
    <?php if (isset($component)) { $__componentOriginal1e7b02924f66db4f746ec2f6bc26497a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal1e7b02924f66db4f746ec2f6bc26497a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.bulk-actions','data' => ['count' => $count,'selectAll' => 'toggleSelectAllServices','selectAllTitle' => 'Leistungen dieser Seite auswählen oder Auswahl aufheben']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.bulk-actions'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($count),'select-all' => 'toggleSelectAllServices','select-all-title' => 'Leistungen dieser Seite auswählen oder Auswahl aufheben']); ?>
        <button type="button" role="menuitem" wire:click="toggleSelectAllServices" x-on:click="close(true)" class="rt-table-mobile-sort__option" data-service-selection-page>
            <i class="far fa-check-square" aria-hidden="true"></i>
            <span>Seite auswählen / abwählen</span>
        </button>
        <button type="button" role="menuitem" wire:click="clearServiceSelection" x-on:click="close(true)" class="rt-table-mobile-sort__option" data-service-selection-clear>
            <i class="far fa-times" aria-hidden="true"></i>
            <span>Auswahl aufheben</span>
        </button>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal1e7b02924f66db4f746ec2f6bc26497a)): ?>
<?php $attributes = $__attributesOriginal1e7b02924f66db4f746ec2f6bc26497a; ?>
<?php unset($__attributesOriginal1e7b02924f66db4f746ec2f6bc26497a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal1e7b02924f66db4f746ec2f6bc26497a)): ?>
<?php $component = $__componentOriginal1e7b02924f66db4f746ec2f6bc26497a; ?>
<?php unset($__componentOriginal1e7b02924f66db4f746ec2f6bc26497a); ?>
<?php endif; ?>
    <span class="sr-only" role="status" aria-live="polite"><?php echo e((int) $count); ?> Leistungen ausgewählt</span>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-table-selection.blade.php ENDPATH**/ ?>