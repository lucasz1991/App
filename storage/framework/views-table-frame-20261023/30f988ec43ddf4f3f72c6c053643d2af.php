<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['shift', 'timezone', 'compact' => false]));

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

foreach (array_filter((['shift', 'timezone', 'compact' => false]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $activeAssignments = $shift->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability());
    $reserved = $activeAssignments->count();
    $confirmed = $activeAssignments->where('status', \App\Enums\ShiftAssignmentStatus::Confirmed)->count();
    $closed = in_array($shift->status, [\App\Enums\ShiftStatus::Completed, \App\Enums\ShiftStatus::Cancelled], true);
    $missing = $closed ? 0 : max(0, $shift->required_staff - $reserved);
    $start = $shift->starts_at->copy()->setTimezone($timezone);
    $end = $shift->ends_at->copy()->setTimezone($timezone);
    $coverage = min(100, (int) round($reserved / max(1, $shift->required_staff) * 100));
?>
<button type="button" <?php echo e($attributes->class(['rt-disposition-shift', 'rt-disposition-shift--compact' => $compact])); ?> x-on:click="$dispatch('operations-shift-detail-request', { id: <?php echo e($shift->id); ?> })" aria-label="Schichtdetails: <?php echo e($shift->title); ?>, <?php echo e($start->format('d.m. H:i')); ?>" data-open="<?php echo e($missing > 0 ? 'true' : 'false'); ?>">
    <span class="rt-disposition-shift__time"><strong><?php echo e($start->format('H:i')); ?>–<?php echo e($end->format('H:i')); ?></strong><span><?php echo e($start->format('d.m.')); ?> <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$start->isSameDay($end)): ?>–<?php echo e($end->format('d.m.')); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span></span>
    <span class="rt-disposition-shift__task"><span class="rt-disposition-record-code"><?php echo e($shift->order?->order_number); ?></span><strong><?php echo e($shift->title); ?></strong><span class="rt-disposition-record-meta"><?php echo e($shift->order?->customer?->company_name); ?> · <?php echo e($shift->role_name ?: 'Tätigkeit offen'); ?></span><span class="rt-disposition-record-meta"><i class="far fa-location-dot" aria-hidden="true"></i> <?php echo e($shift->location_name ?: $shift->order?->location_name ?: 'Einsatzort offen'); ?></span></span>
    <span class="rt-disposition-shift__staff"><strong><?php echo e($reserved); ?>/<?php echo e($shift->required_staff); ?> eingeplant</strong><span class="rt-disposition-coverage" aria-hidden="true"><span style="width: <?php echo e($coverage); ?>%"></span></span><span class="rt-disposition-record-meta"><?php echo e($confirmed); ?> bestätigt <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($missing > 0): ?> · <span class="rt-disposition-warning"><?php echo e($missing); ?> <?php echo e($missing === 1 ? 'Platz offen' : 'Plätze offen'); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shift->planning_conflict_count > 0): ?><span class="rt-disposition-warning"><?php echo e($shift->planning_conflict_count); ?> mit Konflikt</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shift->feedback_pending > 0): ?><span class="rt-disposition-record-meta"><?php echo e($shift->feedback_pending); ?> Rückmeldung ausstehend</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shift->feedback_declined > 0): ?><span class="rt-disposition-warning"><?php echo e($shift->feedback_declined); ?> abgelehnt</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </span>
    <span class="rt-disposition-shift__state"><?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
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
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shift->revision !== null): ?><span class="rt-disposition-record-meta"><?php echo e($shift->published_revision === $shift->revision ? 'Veröffentlicht' : ($shift->published_revision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht')); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
    <i class="far fa-chevron-right rt-disposition-shift__arrow" aria-hidden="true"></i>
</button>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/shift-card.blade.php ENDPATH**/ ?>