<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['disabled' => false, 'readonly' => false, 'required' => false, 'ariaLabel' => null]));

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

foreach (array_filter((['disabled' => false, 'readonly' => false, 'required' => false, 'ariaLabel' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $wireModel = $attributes->wire('model')->value();
    $wireLive = $attributes->wire('model')->hasModifier('live');
    $fieldId = $attributes->get('id') ?: 'date-time-'.str_replace('.', '-', $wireModel ?: \Illuminate\Support\Str::random(8));
    $controlLabel = $ariaLabel ?: $attributes->get('aria-label', 'Datum und Uhrzeit');
    $outer = $attributes->whereDoesntStartWith('wire:model')->except(['id', 'name', 'value', 'aria-label', 'aria-describedby', 'aria-invalid', 'min', 'max', 'step']);
?>
<div
    x-data="rtDateTimeField({ value: <?php if($wireModel): ?> $wire.entangle(<?php echo e(\Illuminate\Support\Js::from($wireModel)); ?>)<?php echo e($wireLive ? '.live' : ''); ?> <?php else: ?> <?php echo e(\Illuminate\Support\Js::from((string) $attributes->get('value', ''))); ?> <?php endif; ?> })"
    x-modelable="value"
    <?php echo e($outer->merge(['class'=>'rt-date-time-field'])); ?>

    data-rt-date-time-field
>
    <?php if (isset($component)) { $__componentOriginal5f8fcc47e27967eb225f394cd5723df5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5f8fcc47e27967eb225f394cd5723df5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-field','data' => ['id' => $fieldId,'xModel' => 'datePart','disabled' => $disabled,'readonly' => $readonly,'ariaLabel' => $controlLabel.' · Datum','required' => $required,'ariaInvalid' => $attributes->get('aria-invalid'),'ariaDescribedby' => $attributes->get('aria-describedby'),'min' => $attributes->get('min') ? substr($attributes->get('min'), 0, 10) : null,'max' => $attributes->get('max') ? substr($attributes->get('max'), 0, 10) : null]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'x-model' => 'datePart','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($disabled),'readonly' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($readonly),'aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($controlLabel.' · Datum'),'required' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($required),'aria-invalid' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('aria-invalid')),'aria-describedby' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('aria-describedby')),'min' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('min') ? substr($attributes->get('min'), 0, 10) : null),'max' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('max') ? substr($attributes->get('max'), 0, 10) : null)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5f8fcc47e27967eb225f394cd5723df5)): ?>
<?php $attributes = $__attributesOriginal5f8fcc47e27967eb225f394cd5723df5; ?>
<?php unset($__attributesOriginal5f8fcc47e27967eb225f394cd5723df5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5f8fcc47e27967eb225f394cd5723df5)): ?>
<?php $component = $__componentOriginal5f8fcc47e27967eb225f394cd5723df5; ?>
<?php unset($__componentOriginal5f8fcc47e27967eb225f394cd5723df5); ?>
<?php endif; ?>
    <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => $fieldId.'-time','type' => 'time','xRef' => 'time','xModel' => 'timePart','disabled' => $disabled,'readonly' => $readonly,'required' => $required,'ariaLabel' => $controlLabel.' · Uhrzeit','ariaInvalid' => $attributes->get('aria-invalid'),'ariaDescribedby' => $attributes->get('aria-describedby'),'step' => $attributes->get('step', 60),'class' => 'rt-date-time-field__time']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId.'-time'),'type' => 'time','x-ref' => 'time','x-model' => 'timePart','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($disabled),'readonly' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($readonly),'required' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($required),'aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($controlLabel.' · Uhrzeit'),'aria-invalid' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('aria-invalid')),'aria-describedby' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('aria-describedby')),'step' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('step', 60)),'class' => 'rt-date-time-field__time']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $attributes = $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $component = $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($attributes->has('name')): ?><input type="hidden" name="<?php echo e($attributes->get('name')); ?>" :value="value" <?php if($disabled): echo 'disabled'; endif; ?> /><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/forms/date-time-field.blade.php ENDPATH**/ ?>