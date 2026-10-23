<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['shift', 'compact' => false]));

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

foreach (array_filter((['shift', 'compact' => false]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','type' => 'button','xOn:click' => '$dispatch(\'operations-shift-detail-request\', { id: '.e($shift->id).' })','ariaHaspopup' => 'dialog','dataCalendarRevealItem' => true,'class' => 'rt-calendar-shift '.e($compact ? 'rt-calendar-shift-compact' : '').' !block !h-auto w-full !whitespace-normal !text-left','dataCalendarShift' => ''.e($shift->id).'','dataCalendarStaffing' => ''.e($shift->calendar_open > 0 ? 'open' : 'staffed').'','dataCalendarState' => ''.e($shift->calendar_state ?? 'staffed').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','type' => 'button','x-on:click' => '$dispatch(\'operations-shift-detail-request\', { id: '.e($shift->id).' })','aria-haspopup' => 'dialog','data-calendar-reveal-item' => true,'class' => 'rt-calendar-shift '.e($compact ? 'rt-calendar-shift-compact' : '').' !block !h-auto w-full !whitespace-normal !text-left','data-calendar-shift' => ''.e($shift->id).'','data-calendar-staffing' => ''.e($shift->calendar_open > 0 ? 'open' : 'staffed').'','data-calendar-state' => ''.e($shift->calendar_state ?? 'staffed').'']); ?>
    <span class="rt-calendar-shift-time block text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted"><?php echo e($shift->calendar_starts->format($compact && $shift->calendar_starts->isSameDay($shift->calendar_ends) ? 'H:i' : 'd.m. H:i')); ?> – <?php echo e($shift->calendar_ends->format($shift->calendar_starts->isSameDay($shift->calendar_ends) ? 'H:i' : 'd.m. H:i')); ?></span>
    <span class="rt-calendar-shift-title mt-1 block break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text"><?php echo e($shift->title); ?></span>
    <span class="rt-calendar-shift-order mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted"><?php echo e($shift->order?->order_number); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($compact)): ?> · <?php echo e($shift->order?->title); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($compact)): ?>
        <span class="mt-1 block break-words text-xs text-rt-muted dark:text-rt-dark-muted"><?php echo e($shift->order?->customer?->company_name); ?> · <?php echo e($shift->location_name ?: '—'); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <span class="rt-calendar-shift-status mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-calendar-shift-staffing font-semibold tabular-nums', 'text-rt-red dark:text-rt-dark-accent' => $shift->calendar_open > 0, 'text-rt-muted dark:text-rt-dark-muted' => $shift->calendar_open === 0]); ?>"><?php echo e($shift->calendar_reserved); ?>/<?php echo e($shift->required_staff); ?> eingeplant</span>
        <?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9140b74635d19149dc471db3f05482a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.status','data' => ['value' => $shift->status->value,'label' => $shift->status->label()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shift->status->value),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shift->status->label())]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9140b74635d19149dc471db3f05482a0)): ?>
<?php $attributes = $__attributesOriginal9140b74635d19149dc471db3f05482a0; ?>
<?php unset($__attributesOriginal9140b74635d19149dc471db3f05482a0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9140b74635d19149dc471db3f05482a0)): ?>
<?php $component = $__componentOriginal9140b74635d19149dc471db3f05482a0; ?>
<?php unset($__componentOriginal9140b74635d19149dc471db3f05482a0); ?>
<?php endif; ?>
    </span>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shift->revision && $shift->published_revision !== $shift->revision): ?>
        <span class="mt-1 block text-xs text-rt-muted dark:text-rt-dark-muted"><?php echo e($shift->published_revision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht'); ?></span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/calendar-shift.blade.php ENDPATH**/ ?>