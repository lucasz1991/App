<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['seats', 'summary', 'previewId' => null, 'timezone' => 'Europe/Berlin']));

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

foreach (array_filter((['seats', 'summary', 'previewId' => null, 'timezone' => 'Europe/Berlin']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $staffingLabel = $summary['reserved'].' von '.$summary['required'].' Einsatzplätzen eingeplant, '.$summary['confirmed'].' bestätigt, '.max(0, (int) $summary['open']).' offen';
?>
<?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => 'auto','openOnHover' => true,'hoverOpenDelay' => 400,'hoverCloseDelay' => 180,'dropdownId' => $previewId,'layerGroup' => 'service-staffing','contentRole' => 'dialog','contentLabel' => 'Besetzung vergrößert','contentClasses' => 'rt-service-staffing-preview__surface bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','dropdownClasses' => 'rt-service-staffing-preview','triggerClasses' => 'rt-service-staffing__trigger-wrap','class' => 'rt-service-staffing','dataServiceStaffing' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => 'auto','open-on-hover' => true,'hover-open-delay' => 400,'hover-close-delay' => 180,'dropdown-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewId),'layer-group' => 'service-staffing','content-role' => 'dialog','content-label' => 'Besetzung vergrößert','content-classes' => 'rt-service-staffing-preview__surface bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','dropdown-classes' => 'rt-service-staffing-preview','trigger-classes' => 'rt-service-staffing__trigger-wrap','class' => 'rt-service-staffing','data-service-staffing' => true]); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <div
            class="rt-service-staffing__trigger"
            role="button"
            tabindex="0"
            aria-label="<?php echo e($staffingLabel); ?> · Besetzung vergrößern"
            data-table-row-ignore
            x-bind:data-service-preview-state="open ? 'open' : (hoverOpenTimer !== null ? 'pending' : 'idle')"
            x-bind:class="hoverOpenTimer !== null && !open ? 'cursor-wait' : 'cursor-zoom-in'"
            @keydown.enter.prevent.stop="toggle()"
            @keydown.space.prevent.stop="toggle()"
        >
            <?php if (isset($component)) { $__componentOriginal92b971aa100c2d8a3096a622de7d8225 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal92b971aa100c2d8a3096a622de7d8225 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-staffing-content','data' => ['seats' => $seats,'summary' => $summary]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-staffing-content'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['seats' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($seats),'summary' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($summary)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal92b971aa100c2d8a3096a622de7d8225)): ?>
<?php $attributes = $__attributesOriginal92b971aa100c2d8a3096a622de7d8225; ?>
<?php unset($__attributesOriginal92b971aa100c2d8a3096a622de7d8225); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal92b971aa100c2d8a3096a622de7d8225)): ?>
<?php $component = $__componentOriginal92b971aa100c2d8a3096a622de7d8225; ?>
<?php unset($__componentOriginal92b971aa100c2d8a3096a622de7d8225); ?>
<?php endif; ?>
        </div>
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <button type="button" class="rt-service-staffing-preview__close" aria-label="Vergrößerte Besetzung schließen" data-dropdown-initial-focus @click.stop="close(true)">
            <i class="far fa-times" aria-hidden="true"></i>
        </button>
        <?php if (isset($component)) { $__componentOriginal92b971aa100c2d8a3096a622de7d8225 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal92b971aa100c2d8a3096a622de7d8225 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-staffing-content','data' => ['seats' => $seats,'summary' => $summary,'timezone' => $timezone,'expanded' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-staffing-content'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['seats' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($seats),'summary' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($summary),'timezone' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($timezone),'expanded' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal92b971aa100c2d8a3096a622de7d8225)): ?>
<?php $attributes = $__attributesOriginal92b971aa100c2d8a3096a622de7d8225; ?>
<?php unset($__attributesOriginal92b971aa100c2d8a3096a622de7d8225); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal92b971aa100c2d8a3096a622de7d8225)): ?>
<?php $component = $__componentOriginal92b971aa100c2d8a3096a622de7d8225; ?>
<?php unset($__componentOriginal92b971aa100c2d8a3096a622de7d8225); ?>
<?php endif; ?>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-staffing.blade.php ENDPATH**/ ?>