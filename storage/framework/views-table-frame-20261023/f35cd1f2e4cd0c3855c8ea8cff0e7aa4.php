<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['label', 'model', 'type' => 'text', 'wide' => false, 'searchModel' => null, 'scope' => null]));

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

foreach (array_filter((['label', 'model', 'type' => 'text', 'wide' => false, 'searchModel' => null, 'scope' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $fieldId = ($scope ? 'intake-'.$scope.'-' : 'ops-').str_replace('.', '-', $model);
?>
<div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['min-w-0 space-y-1.5', 'ops-full' => $wide]); ?>">
    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => $fieldId,'value' => $label]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($type === 'select'): ?>
        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => $fieldId,'ariaLabel' => $label,'searchModel' => $searchModel,'wire:model' => ''.e($model).'','attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label),'search-model' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($searchModel),'wire:model' => ''.e($model).'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?><?php echo e($slot); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
    <?php elseif($type === 'textarea'): ?>
        <?php if (isset($component)) { $__componentOriginal7f6d582ce7a3b2aeb0250793236761ee = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.textarea','data' => ['id' => $fieldId,'wire:model' => ''.e($model).'','attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'wire:model' => ''.e($model).'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee)): ?>
<?php $attributes = $__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee; ?>
<?php unset($__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7f6d582ce7a3b2aeb0250793236761ee)): ?>
<?php $component = $__componentOriginal7f6d582ce7a3b2aeb0250793236761ee; ?>
<?php unset($__componentOriginal7f6d582ce7a3b2aeb0250793236761ee); ?>
<?php endif; ?>
    <?php elseif($type === 'date'): ?>
        <?php if (isset($component)) { $__componentOriginal5f8fcc47e27967eb225f394cd5723df5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5f8fcc47e27967eb225f394cd5723df5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-field','data' => ['id' => $fieldId,'ariaLabel' => $label,'wire:model' => ''.e($model).'','attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label),'wire:model' => ''.e($model).'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
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
    <?php elseif($type === 'datetime-local'): ?>
        <?php if (isset($component)) { $__componentOriginal4878807c3bad702f346d6e9e5724ad7d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4878807c3bad702f346d6e9e5724ad7d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-time-field','data' => ['id' => $fieldId,'ariaLabel' => $label,'wire:model' => ''.e($model).'','attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-time-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label),'wire:model' => ''.e($model).'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4878807c3bad702f346d6e9e5724ad7d)): ?>
<?php $attributes = $__attributesOriginal4878807c3bad702f346d6e9e5724ad7d; ?>
<?php unset($__attributesOriginal4878807c3bad702f346d6e9e5724ad7d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4878807c3bad702f346d6e9e5724ad7d)): ?>
<?php $component = $__componentOriginal4878807c3bad702f346d6e9e5724ad7d; ?>
<?php unset($__componentOriginal4878807c3bad702f346d6e9e5724ad7d); ?>
<?php endif; ?>
    <?php elseif($type === 'number'): ?>
        <?php
            $numberStep = (string) $attributes->get('step', '1');
            $numberFraction = explode('.', rtrim(rtrim(number_format((float) $numberStep, 8, '.', ''), '0'), '.'), 2)[1] ?? '';
            $numberDecimals = (int) $attributes->get('decimals', strlen($numberFraction));
        ?>
        <?php if (isset($component)) { $__componentOriginalf93e6b313b87342ec474caffc76d3c54 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf93e6b313b87342ec474caffc76d3c54 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.number-input','data' => ['id' => $fieldId,'decimals' => $numberDecimals,'separator' => $attributes->get('separator', '.'),'wire:model' => ''.e($model).'','attributes' => $attributes->except(['decimals', 'separator'])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.number-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'decimals' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($numberDecimals),'separator' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->get('separator', '.')),'wire:model' => ''.e($model).'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->except(['decimals', 'separator']))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf93e6b313b87342ec474caffc76d3c54)): ?>
<?php $attributes = $__attributesOriginalf93e6b313b87342ec474caffc76d3c54; ?>
<?php unset($__attributesOriginalf93e6b313b87342ec474caffc76d3c54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf93e6b313b87342ec474caffc76d3c54)): ?>
<?php $component = $__componentOriginalf93e6b313b87342ec474caffc76d3c54; ?>
<?php unset($__componentOriginalf93e6b313b87342ec474caffc76d3c54); ?>
<?php endif; ?>
    <?php else: ?>
        <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => $fieldId,'type' => $type,'wire:model' => ''.e($model).'','attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($fieldId),'type' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($type),'wire:model' => ''.e($model).'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
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
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if (isset($component)) { $__componentOriginalad6e120586abfa0dc479588fa10434b3 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalad6e120586abfa0dc479588fa10434b3 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input-error','data' => ['for' => $model]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input-error'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($model)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalad6e120586abfa0dc479588fa10434b3)): ?>
<?php $attributes = $__attributesOriginalad6e120586abfa0dc479588fa10434b3; ?>
<?php unset($__attributesOriginalad6e120586abfa0dc479588fa10434b3); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalad6e120586abfa0dc479588fa10434b3)): ?>
<?php $component = $__componentOriginalad6e120586abfa0dc479588fa10434b3; ?>
<?php unset($__componentOriginalad6e120586abfa0dc479588fa10434b3); ?>
<?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/field.blade.php ENDPATH**/ ?>