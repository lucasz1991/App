<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['start', 'end']));

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

foreach (array_filter((['start', 'end']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php ($calendar = \App\Support\Operations\TimelineMiniCalendar::forInterval($start, $end)); ?>

<figure <?php echo e($attributes->class(['rt-event-mini-calendar'])); ?> role="img" aria-label="<?php echo e($calendar['accessible_label']); ?>">
    <div class="rt-event-mini-calendar__header" aria-hidden="true">
        <span class="rt-event-mini-calendar__month"><?php echo e($calendar['month_label']); ?></span>
    </div>
    <div class="rt-event-mini-calendar__grid" aria-hidden="true">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $weekday): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <span class="rt-event-mini-calendar__weekday"><?php echo e($weekday); ?></span>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $calendar['days']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                'rt-event-mini-calendar__day',
                'is-outside' => $day['outside'],
                'is-selected' => $day['selected'],
                'is-start' => $day['start'],
                'is-end' => $day['end'],
            ]); ?>" data-date="<?php echo e($day['date']); ?>"><?php echo e($day['number']); ?></span>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <figcaption class="rt-event-mini-calendar__range" aria-hidden="true"><?php echo e($calendar['range_label']); ?></figcaption>
</figure>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/timeline-mini-calendar.blade.php ENDPATH**/ ?>