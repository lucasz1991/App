<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['status']));

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

foreach (array_filter((['status']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $icon = match ($status) {
        \App\Enums\OrderStatus::Requested => 'fa-inbox',
        \App\Enums\OrderStatus::Confirmed => 'fa-check-circle',
        \App\Enums\OrderStatus::Planned => 'fa-calendar-check',
        \App\Enums\OrderStatus::InProgress => 'fa-play-circle',
        \App\Enums\OrderStatus::Completed => 'fa-circle-check',
        \App\Enums\OrderStatus::Invoiced => 'fa-file-invoice',
        \App\Enums\OrderStatus::Cancelled => 'fa-ban',
    };
?>
<span class="rt-service-status" data-service-status="<?php echo e($status->value); ?>"><i class="far <?php echo e($icon); ?>" aria-hidden="true"></i><span><?php echo e($status->label()); ?></span></span>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-status.blade.php ENDPATH**/ ?>