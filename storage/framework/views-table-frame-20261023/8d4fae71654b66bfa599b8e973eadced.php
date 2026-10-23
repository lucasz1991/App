<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['customer' => null, 'previewId' => null]));

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

foreach (array_filter((['customer' => null, 'previewId' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $companyName = $customer?->company_name ?: 'Kunde nicht hinterlegt';
    $customerNumber = trim((string) $customer?->customer_number);
    $actor = auth()->user();
    $canOpenProfile = $customer instanceof \App\Models\Customer
        && (int) $customer->getKey() > 0
        && $actor instanceof \App\Models\User
        && $actor->status
        && $actor->can('operations.manage');
?>
<?php if (isset($component)) { $__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-cell-preview','data' => ['label' => 'Kunde','previewId' => $previewId,'kind' => 'customer']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-cell-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Kunde','preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewId),'kind' => 'customer']); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <div class="rt-service-customer"><?php if (isset($component)) { $__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.company-avatar','data' => ['customer' => $customer,'size' => 30]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.company-avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['customer' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($customer),'size' => 30]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a)): ?>
<?php $attributes = $__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a; ?>
<?php unset($__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a)): ?>
<?php $component = $__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a; ?>
<?php unset($__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a); ?>
<?php endif; ?><span class="rt-table-value"><?php echo e($companyName); ?></span></div>
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <header class="rt-service-customer-preview__identity">
            <?php if (isset($component)) { $__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.company-avatar','data' => ['customer' => $customer,'size' => 48]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.company-avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['customer' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($customer),'size' => 48]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a)): ?>
<?php $attributes = $__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a; ?>
<?php unset($__attributesOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a)): ?>
<?php $component = $__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a; ?>
<?php unset($__componentOriginald6e9fd452a6edfd4f9a5f0d63fc3dd1a); ?>
<?php endif; ?>
            <div>
                <span class="rt-service-preview-note">Kunde</span>
                <strong><?php echo e($companyName); ?></strong>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($customerNumber !== ''): ?><span class="rt-service-customer-preview__number"><?php echo e($customerNumber); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </header>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canOpenProfile): ?>
            <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','size' => 'sm','href' => \App\Support\Operations\OperationsPages::url('customers', ['view' => 'master', 'customer' => (int) $customer->getKey()]),'class' => 'rt-service-customer-preview__link','dataServiceCustomerProfile' => true,'dataTableRowIgnore' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','size' => 'sm','href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(\App\Support\Operations\OperationsPages::url('customers', ['view' => 'master', 'customer' => (int) $customer->getKey()])),'class' => 'rt-service-customer-preview__link','data-service-customer-profile' => true,'data-table-row-ignore' => true]); ?>
                <i class="far fa-id-card" aria-hidden="true"></i>Kundenprofil öffnen<i class="far fa-arrow-right" aria-hidden="true"></i>
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
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-customer-preview.blade.php ENDPATH**/ ?>