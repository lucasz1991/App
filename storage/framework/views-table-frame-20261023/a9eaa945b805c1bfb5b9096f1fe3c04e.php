<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['label', 'previewId', 'kind' => null]));

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

foreach (array_filter((['label', 'previewId', 'kind' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => 'auto','openOnHover' => true,'hoverOpenDelay' => 400,'hoverCloseDelay' => 180,'dropdownId' => $previewId,'layerGroup' => 'service-staffing','contentRole' => 'dialog','contentLabel' => $label.' vergrößert','contentClasses' => 'rt-service-cell-preview__surface bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','dropdownClasses' => 'rt-service-cell-preview','triggerClasses' => 'rt-service-staffing__trigger-wrap','class' => 'rt-service-staffing','dataServiceCellPreview' => $kind]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => 'auto','open-on-hover' => true,'hover-open-delay' => 400,'hover-close-delay' => 180,'dropdown-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewId),'layer-group' => 'service-staffing','content-role' => 'dialog','content-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label.' vergrößert'),'content-classes' => 'rt-service-cell-preview__surface bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','dropdown-classes' => 'rt-service-cell-preview','trigger-classes' => 'rt-service-staffing__trigger-wrap','class' => 'rt-service-staffing','data-service-cell-preview' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($kind)]); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <div class="rt-service-staffing__trigger" role="button" tabindex="0" aria-label="<?php echo e($label); ?> vergrößern" data-table-row-ignore
            x-bind:data-service-preview-state="open ? 'open' : (hoverOpenTimer !== null ? 'pending' : 'idle')"
            x-bind:class="hoverOpenTimer !== null && !open ? 'cursor-wait' : 'cursor-zoom-in'"
            @keydown.enter.prevent.stop="toggle()" @keydown.space.prevent.stop="toggle()">
            <?php echo e($trigger); ?>

        </div>
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <button type="button" class="rt-service-staffing-preview__close" aria-label="<?php echo e($label); ?> schließen" data-dropdown-initial-focus @click.stop="close(true)"><i class="far fa-times" aria-hidden="true"></i></button>
        <div class="rt-service-cell-preview__content" data-service-preview-kind="<?php echo e($kind); ?>"><?php echo e($content); ?></div>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $attributes = $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $component = $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-cell-preview.blade.php ENDPATH**/ ?>