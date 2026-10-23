<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['ownerId']));

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

foreach (array_filter((['ownerId']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<div class="rt-service-detail-host" wire:ignore.self data-service-detail-panel-owner="<?php echo e($ownerId); ?>" x-bind:aria-busy="loading">
    <div x-show="loading || error" x-cloak class="rt-service-detail-state">
        <div class="rt-ops-panel__accent" aria-hidden="true"></div>
        <?php if (isset($component)) { $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.header','data' => ['eyebrow' => 'Leistung','title' => 'Leistungsdetails','icon' => 'fa-briefcase','closeLabel' => 'Leistungsdetails schließen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['eyebrow' => 'Leistung','title' => 'Leistungsdetails','icon' => 'fa-briefcase','close-label' => 'Leistungsdetails schließen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $attributes = $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $component = $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
        <div class="rt-ops-panel__body rt-modal-content">
            <div x-show="loading" data-service-detail-loading class="rt-service-detail-loader" role="status" aria-live="polite">
                <span class="rt-service-detail-loader__indicator" aria-hidden="true"><i class="far fa-spinner-third fa-spin"></i></span>
                <strong>Leistungsdetails werden geladen …</strong>
                <span>Besetzung, Schichten und Verlauf werden vorbereitet.</span>
                <div class="rt-service-detail-skeleton" aria-hidden="true"><?php if (isset($component)) { $__componentOriginal24e17cb66881c3321b8b712021de0468 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal24e17cb66881c3321b8b712021de0468 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.loading.skeleton','data' => ['variant' => 'list','rows' => 3]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.loading.skeleton'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'list','rows' => 3]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal24e17cb66881c3321b8b712021de0468)): ?>
<?php $attributes = $__attributesOriginal24e17cb66881c3321b8b712021de0468; ?>
<?php unset($__attributesOriginal24e17cb66881c3321b8b712021de0468); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal24e17cb66881c3321b8b712021de0468)): ?>
<?php $component = $__componentOriginal24e17cb66881c3321b8b712021de0468; ?>
<?php unset($__componentOriginal24e17cb66881c3321b8b712021de0468); ?>
<?php endif; ?></div>
            </div>
            <div x-show="!loading && error" data-service-detail-error class="rt-service-detail-loader" role="alert">
                <i class="far fa-circle-exclamation" aria-hidden="true"></i><p x-text="error"></p>
                <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','xOn:click' => 'retryServiceDetail()']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','x-on:click' => 'retryServiceDetail()']); ?><i class="far fa-arrow-rotate-right" aria-hidden="true"></i>Erneut versuchen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
            </div>
        </div>
    </div>
    <div x-show="!loading && !error" x-cloak class="rt-service-detail-ready" data-service-detail-ready><?php echo e($slot); ?></div>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-detail-shell.blade.php ENDPATH**/ ?>