<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'id' => null,
    'label' => null,
    'help' => null,
    'toggle' => false,
    'size' => 'md',
    'disabled' => false,
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
    'id' => null,
    'label' => null,
    'help' => null,
    'toggle' => false,
    'size' => 'md',
    'disabled' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $inputId = $id ?: \Illuminate\Support\Str::uuid();
    $isToggle = filter_var($toggle, FILTER_VALIDATE_BOOLEAN);
    $resolvedSize = in_array($size, ['sm', 'md'], true) ? $size : 'md';
    $baseCheckbox = 'rt-ui-checkbox h-5 w-5 shrink-0 rounded-md border-rt-border bg-rt-control text-rt-accent shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring disabled:cursor-not-allowed disabled:opacity-50 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-accent';
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(! $isToggle): ?>
    <label
        for="<?php echo e($inputId); ?>"
        class="<?php echo \Illuminate\Support\Arr::toCssClasses([
            'rt-ui-check-field inline-flex min-h-11 items-center gap-3',
            'cursor-pointer' => ! $disabled,
            'cursor-not-allowed opacity-60' => $disabled,
        ]); ?>"
    >
        <input
            id="<?php echo e($inputId); ?>"
            type="checkbox"
            <?php echo e($disabled ? 'disabled' : ''); ?>

            <?php echo $attributes->merge(['class' => $baseCheckbox]); ?>

        />
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($label): ?>
            <span class="rt-ui-check-label text-sm font-medium leading-5 text-rt-text dark:text-rt-dark-text"><?php echo e($label); ?></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </label>
<?php else: ?>
    <label
        for="<?php echo e($inputId); ?>"
        <?php echo e($attributes->only('class')->class([
            'rt-ui-toggle group',
            'cursor-pointer' => ! $disabled,
            'is-disabled' => $disabled,
        ])); ?>

    >
        <input
            id="<?php echo e($inputId); ?>"
            type="checkbox"
            role="switch"
            <?php echo e($disabled ? 'disabled' : ''); ?>

            class="rt-ui-toggle__input peer sr-only"
            <?php echo $attributes->except('class'); ?>

        />

        <span
            data-toggle-control
            aria-hidden="true"
            class="rt-ui-toggle-control rt-ui-toggle-control--<?php echo e($resolvedSize); ?>"
        ></span>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($label): ?>
            <span class="rt-ui-toggle__label">
                <?php echo e($label); ?>

            </span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </label>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($help): ?>
    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted"><?php echo e($help); ?></p>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/ui/forms/checkbox.blade.php ENDPATH**/ ?>