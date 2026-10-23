<?php
    $ordersRangeLabel = \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.Y').' – '.\Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y');
?>
<?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'right','width' => 'auto','offset' => '6','maxHeight' => 448,'dropdownId' => 'orders-period-'.e($this->getId()).'','layerGroup' => 'operations-orders','contentRole' => 'dialog','contentLabel' => 'Auftragszeitraum anpassen','dropdownClasses' => 'rt-shift-plan-range-dropdown','contentClasses' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','dataOrdersPeriodControl' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'right','width' => 'auto','offset' => '6','max-height' => 448,'dropdown-id' => 'orders-period-'.e($this->getId()).'','layer-group' => 'operations-orders','content-role' => 'dialog','content-label' => 'Auftragszeitraum anpassen','dropdown-classes' => 'rt-shift-plan-range-dropdown','content-classes' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text','data-orders-period-control' => true]); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-period-trigger rt-orders-period-trigger','ariaLabel' => 'Auftragszeitraum auswählen: '.e($ordersRangeLabel).'','title' => ''.e($ordersRangeLabel).'','dataOrdersPeriodTrigger' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-period-trigger rt-orders-period-trigger','aria-label' => 'Auftragszeitraum auswählen: '.e($ordersRangeLabel).'','title' => ''.e($ordersRangeLabel).'','data-orders-period-trigger' => true]); ?>
            <i class="far fa-calendar-alt" aria-hidden="true"></i>
            <span><strong class="rt-calendar-period"><?php echo e(\Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.')); ?> – <?php echo e(\Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y')); ?></strong></span>
            <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <?php if (isset($component)) { $__componentOriginal0ec80a222b9f3310c75fd8d383c36bce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-range-picker','data' => ['fromModel' => 'rangeFrom','untilModel' => 'rangeTo','applyAction' => 'applyPeriod','today' => now($displayTimezone)->toDateString(),'maxDays' => 94,'resetOnOpen' => true,'xOn:dateRangeApplied' => 'close(true)','xOn:dateRangeCancel' => 'close(true)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-range-picker'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['from-model' => 'rangeFrom','until-model' => 'rangeTo','apply-action' => 'applyPeriod','today' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(now($displayTimezone)->toDateString()),'max-days' => 94,'reset-on-open' => true,'x-on:date-range-applied' => 'close(true)','x-on:date-range-cancel' => 'close(true)']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce)): ?>
<?php $attributes = $__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce; ?>
<?php unset($__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0ec80a222b9f3310c75fd8d383c36bce)): ?>
<?php $component = $__componentOriginal0ec80a222b9f3310c75fd8d383c36bce; ?>
<?php unset($__componentOriginal0ec80a222b9f3310c75fd8d383c36bce); ?>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/partials/orders-period-trigger.blade.php ENDPATH**/ ?>