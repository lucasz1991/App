<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['title', 'maxWidth' => '3xl', 'id' => null, 'variant' => 'modal', 'showExpression' => null, 'showOffline' => true]));

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

foreach (array_filter((['title', 'maxWidth' => '3xl', 'id' => null, 'variant' => 'modal', 'showExpression' => null, 'showOffline' => true]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php ($scopedId = $id ?? 'ops-modal-'.md5((isset($__livewire) ? $__livewire->getId() : $title).':'.$attributes->wire('model'))); ?>
<?php if (isset($component)) { $__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dialog-modal','data' => ['id' => $scopedId,'maxWidth' => $maxWidth,'placement' => $variant === 'drawer' ? 'right' : 'center','showExpression' => $showExpression,'attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dialog-modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($scopedId),'max-width' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($maxWidth),'placement' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($variant === 'drawer' ? 'right' : 'center'),'show-expression' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($showExpression),'attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
     <?php $__env->slot('title', null, []); ?> <?php echo e($title); ?> <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-ops ops-stack', 'rt-disposition' => $variant === 'drawer']); ?>"><?php if (isset($component)) { $__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.feedback','data' => ['showOffline' => $showOffline]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.feedback'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['show-offline' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($showOffline)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5)): ?>
<?php $attributes = $__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5; ?>
<?php unset($__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5)): ?>
<?php $component = $__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5; ?>
<?php unset($__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5); ?>
<?php endif; ?><?php echo e($slot); ?></div> <?php $__env->endSlot(); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($footer)): ?>
         <?php $__env->slot('footer', null, []); ?> <?php echo e($footer); ?> <?php $__env->endSlot(); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f)): ?>
<?php $attributes = $__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f; ?>
<?php unset($__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f)): ?>
<?php $component = $__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f; ?>
<?php unset($__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/modal.blade.php ENDPATH**/ ?>