<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['item', 'timezone' => 'Europe/Berlin', 'previewId' => null, 'detailAction' => null, 'periodLabel' => 'Im Zeitraum', 'requiredStaff' => null]));

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

foreach (array_filter((['item', 'timezone' => 'Europe/Berlin', 'previewId' => null, 'detailAction' => null, 'periodLabel' => 'Im Zeitraum', 'requiredStaff' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $items = $item->shifts->sortBy('starts_at')->values();
    $visibleShifts = $items->take(8);
?>
<?php if (isset($component)) { $__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-cell-preview','data' => ['label' => 'Schichten','previewId' => $previewId ?? 'service-shifts-'.$item->id,'kind' => 'shifts']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-cell-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Schichten','preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewId ?? 'service-shifts-'.$item->id),'kind' => 'shifts']); ?>
     <?php $__env->slot('trigger', null, []); ?> <div><span class="rt-table-value tabular-nums"><?php echo e($item->count); ?> <?php echo e($item->count === 1 ? 'Schicht' : 'Schichten'); ?></span><span class="rt-table-meta"><?php echo e($requiredStaff !== null ? 'Bedarf: '.$requiredStaff : $periodLabel); ?></span></div> <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <header class="rt-service-preview-heading"><i class="far fa-calendar-day" aria-hidden="true"></i><div><strong><?php echo e($item->count); ?> <?php echo e($item->count === 1 ? 'Schicht' : 'Schichten'); ?></strong><span><?php echo e($periodLabel); ?></span></div></header>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($requiredStaff !== null): ?><p class="rt-service-preview-note">Auftragsbedarf: <?php echo e($requiredStaff); ?> <?php echo e($requiredStaff === 1 ? 'Mitarbeiter/in' : 'Mitarbeitende'); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($items->isEmpty()): ?><p class="rt-service-preview-note">Für diesen Auftrag wurden noch keine Schichten angelegt.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <ul class="rt-service-preview-shifts">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $visibleShifts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $shift): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    $from = $shift->starts_at?->copy()->setTimezone($timezone);
                    $until = $shift->ends_at?->copy()->setTimezone($timezone);
                    $reserved = $shift->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability())->count();
                ?>
                <li data-service-preview-shift="<?php echo e($shift->id); ?>">
                    <div><strong><?php echo e($shift->title ?: 'Schicht'); ?></strong><span><?php echo e($from?->format('d.m.Y H:i')); ?> – <?php echo e($until?->format('d.m.Y H:i')); ?></span></div>
                    <span class="rt-service-preview-shifts__count" aria-label="<?php echo e($reserved); ?> von <?php echo e($shift->required_staff); ?> Einsatzplätzen eingeplant"><?php echo e($reserved); ?>/<?php echo e($shift->required_staff); ?></span>
                </li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </ul>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($items->count() > $visibleShifts->count()): ?><p class="rt-service-preview-note"><?php echo e($items->count() - $visibleShifts->count()); ?> weitere <?php echo e($items->count() - $visibleShifts->count() === 1 ? 'Schicht' : 'Schichten'); ?> in den Leistungsdetails.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->order): ?>
            <button type="button" class="rt-service-preview-details" x-on:click="open = false; $dispatch('operations-service-detail-request', { id: <?php echo e($item->id); ?>, ownerId: $wire.$id })">Alle Leistungsdetails <i class="far fa-arrow-right" aria-hidden="true"></i></button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed)): ?>
<?php $attributes = $__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed; ?>
<?php unset($__attributesOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed)): ?>
<?php $component = $__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed; ?>
<?php unset($__componentOriginal0bf9f2c001ca7dcd6d85fd2a1f1c5eed); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-shifts-preview.blade.php ENDPATH**/ ?>