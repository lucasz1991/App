<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['order' => null, 'previewId' => null]));

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

foreach (array_filter((['order' => null, 'previewId' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $location = $order?->location_name ?: 'Einsatzort offen';
    $locationPreview = $order instanceof \App\Models\Order
        ? \App\Support\Operations\TimelineLocationPreview::fromOrder($order)
        : ['state' => 'unknown', 'label' => 'Standort nicht verortet'];
?>
<?php if (isset($component)) { $__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-cell-preview','data' => ['label' => 'Ort: '.$location,'previewId' => $previewId,'kind' => 'location']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-cell-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('Ort: '.$location),'preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewId),'kind' => 'location']); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <span class="rt-table-value"><?php echo e($location); ?></span>
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <section class="rt-service-cell-preview__body rt-service-location-preview" aria-label="Ort der Leistung">
            <h3 class="rt-service-cell-preview__title"><?php echo e($location); ?></h3>
            <template x-if="open">
                <div class="rt-service-location-preview__map">
                    <?php if (isset($component)) { $__componentOriginal8e63117d4f57364d8602eb85edbb579c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8e63117d4f57364d8602eb85edbb579c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.timeline-mini-map','data' => ['preview' => $locationPreview,'location' => $order?->location_name]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.timeline-mini-map'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['preview' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($locationPreview),'location' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($order?->location_name)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8e63117d4f57364d8602eb85edbb579c)): ?>
<?php $attributes = $__attributesOriginal8e63117d4f57364d8602eb85edbb579c; ?>
<?php unset($__attributesOriginal8e63117d4f57364d8602eb85edbb579c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8e63117d4f57364d8602eb85edbb579c)): ?>
<?php $component = $__componentOriginal8e63117d4f57364d8602eb85edbb579c; ?>
<?php unset($__componentOriginal8e63117d4f57364d8602eb85edbb579c); ?>
<?php endif; ?>
                </div>
            </template>
        </section>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed)): ?>
<?php $attributes = $__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed; ?>
<?php unset($__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed)): ?>
<?php $component = $__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed; ?>
<?php unset($__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-location-preview.blade.php ENDPATH**/ ?>