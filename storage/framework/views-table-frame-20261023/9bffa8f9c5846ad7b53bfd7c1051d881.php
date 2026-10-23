<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['options' => [], 'value' => null, 'action' => null, 'label' => 'Bereichsmenü', 'id' => null, 'navigate' => false, 'disabled' => false]));

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

foreach (array_filter((['options' => [], 'value' => null, 'action' => null, 'label' => 'Bereichsmenü', 'id' => null, 'navigate' => false, 'disabled' => false]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>


<?php if (isset($component)) { $__componentOriginal40d2a0490d3b28389a93520916f6d5e8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal40d2a0490d3b28389a93520916f6d5e8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.multi-toggle','data' => ['options' => $options,'value' => $value,'action' => $action,'label' => $label,'id' => $id,'navigate' => $navigate,'disabled' => $disabled,'showLabels' => true,'attributes' => $attributes->class(['rt-area-menu'])->merge(['data-area-menu' => ''])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.multi-toggle'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($options),'value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($value),'action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($action),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label),'id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($id),'navigate' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($navigate),'disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($disabled),'show-labels' => true,'attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->class(['rt-area-menu'])->merge(['data-area-menu' => '']))]); ?>
    <span class="rt-area-menu__indicator" aria-hidden="true" x-data="rtAreaMenu" wire:ignore></span>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal40d2a0490d3b28389a93520916f6d5e8)): ?>
<?php $attributes = $__attributesOriginal40d2a0490d3b28389a93520916f6d5e8; ?>
<?php unset($__attributesOriginal40d2a0490d3b28389a93520916f6d5e8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal40d2a0490d3b28389a93520916f6d5e8)): ?>
<?php $component = $__componentOriginal40d2a0490d3b28389a93520916f6d5e8; ?>
<?php unset($__componentOriginal40d2a0490d3b28389a93520916f6d5e8); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/navigation/area-menu.blade.php ENDPATH**/ ?>