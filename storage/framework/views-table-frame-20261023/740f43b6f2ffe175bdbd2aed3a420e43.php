<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'options' => [],
    'counts' => [],
    'value' => null,
    'action' => null,
    'label' => 'Bearbeitungsstand',
    'disabled' => false,
    'variant' => 'group',
    'dropdownId' => null,
]));

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

foreach (array_filter(([
    'options' => [],
    'counts' => [],
    'value' => null,
    'action' => null,
    'label' => 'Bearbeitungsstand',
    'disabled' => false,
    'variant' => 'group',
    'dropdownId' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $safeAction = is_string($action) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $action) ? $action : null;
    $isDisabled = filter_var($disabled, FILTER_VALIDATE_BOOL) || $safeAction === null;
    $isDropdown = $variant === 'dropdown';
    $selectedLabel = $options[$value] ?? $label;
    $selectedCount = $counts[$value] ?? null;
?>


<nav <?php echo e($attributes->class(['rt-status-menu', 'rt-status-menu--dropdown' => $isDropdown])); ?> aria-label="<?php echo e($label); ?>"
    <?php if($safeAction): ?> wire:loading.attr="aria-busy" wire:target="<?php echo e($safeAction); ?>" <?php endif; ?>>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isDropdown): ?>
        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => $dropdownId,'value' => $value,'action' => $safeAction,'counts' => $counts,'variant' => 'compact','icon' => 'far fa-filter','disabled' => $isDisabled,'ariaLabel' => $label.': '.$selectedLabel.($selectedCount !== null ? ' ('.$selectedCount.')' : ''),'title' => $label.': '.$selectedLabel,'wire:loading.attr' => 'disabled','wire:target' => ''.e($safeAction).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dropdownId),'value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($value),'action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($safeAction),'counts' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($counts),'variant' => 'compact','icon' => 'far fa-filter','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($isDisabled),'aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label.': '.$selectedLabel.($selectedCount !== null ? ' ('.$selectedCount.')' : '')),'title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label.': '.$selectedLabel),'wire:loading.attr' => 'disabled','wire:target' => ''.e($safeAction).'']); ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $options; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $optionLabel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($key); ?>" <?php if((string) $value === (string) $key): echo 'selected'; endif; ?>><?php echo e($optionLabel); ?></option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
    <?php else: ?>
    <?php if (isset($component)) { $__componentOriginal2b506e4df073ac4ddbab18ccfd67ba1a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2b506e4df073ac4ddbab18ccfd67ba1a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.btn-group.btn-group','data' => ['class' => 'rt-status-menu__group','label' => $label]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.btn-group.btn-group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-status-menu__group','label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label)]); ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $options; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $optionLabel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <button type="button" class="rt-status-menu__item"
            data-status-value="<?php echo e($key); ?>"
            aria-pressed="<?php echo e((string) $value === (string) $key ? 'true' : 'false'); ?>"
            <?php if($isDisabled): echo 'disabled'; endif; ?>
            <?php if(! $isDisabled): ?>
                wire:click="<?php echo e($safeAction); ?>(<?php echo e(\Illuminate\Support\Js::from((string) $key)); ?>)"
                wire:loading.attr="disabled" wire:target="<?php echo e($safeAction); ?>"
            <?php endif; ?>>
            <span><?php echo e($optionLabel); ?></span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($counts[$key])): ?>
                <span class="rt-status-menu__count"><?php echo e($counts[$key]); ?></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </button>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2b506e4df073ac4ddbab18ccfd67ba1a)): ?>
<?php $attributes = $__attributesOriginal2b506e4df073ac4ddbab18ccfd67ba1a; ?>
<?php unset($__attributesOriginal2b506e4df073ac4ddbab18ccfd67ba1a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2b506e4df073ac4ddbab18ccfd67ba1a)): ?>
<?php $component = $__componentOriginal2b506e4df073ac4ddbab18ccfd67ba1a; ?>
<?php unset($__componentOriginal2b506e4df073ac4ddbab18ccfd67ba1a); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</nav>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/navigation/status-menu.blade.php ENDPATH**/ ?>