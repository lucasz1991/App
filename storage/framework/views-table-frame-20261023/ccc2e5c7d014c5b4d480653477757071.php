<?php
    $workloadLabel = $workload['percent'] !== null ? $workload['percent'].' % verplant' : ($workload['target'] === 0 ? 'Kein Soll im Zeitraum' : 'Auslastung ohne Sollquote');
    $hours = static fn ($minutes) => number_format($minutes / 60, 1, ',', '.').' h';
?>
<?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => '80','openOnHover' => true,'hoverOpenDelay' => 180,'contentRole' => 'dialog','contentLabel' => 'Auslastung von '.e($person->name).'','dropdownId' => 'timeline-workload-'.e($this->getId()).'-'.e($person->id).'','layerGroup' => 'staff-timeline-events','class' => 'rt-timeline-workload-anchor','contentClasses' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => '80','open-on-hover' => true,'hover-open-delay' => 180,'content-role' => 'dialog','content-label' => 'Auslastung von '.e($person->name).'','dropdown-id' => 'timeline-workload-'.e($this->getId()).'-'.e($person->id).'','layer-group' => 'staff-timeline-events','class' => 'rt-timeline-workload-anchor','content-classes' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <button type="button" class="rt-timeline-workload" data-state="<?php echo e($workload['state']); ?>" data-target="<?php echo e($workload['target'] === null ? 'unknown' : ($workload['target'] === 0 ? 'zero' : 'known')); ?>" aria-label="Auslastung <?php echo e($person->name); ?>: <?php echo e($workloadLabel); ?>. Details anzeigen">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($workload['target'] === null): ?>
                <span class="rt-timeline-workload__unknown" aria-hidden="true">–</span>
            <?php else: ?>
                <svg viewBox="0 0 32 32" aria-hidden="true"><circle class="rt-timeline-workload__track" cx="16" cy="16" r="12" /><circle class="rt-timeline-workload__fill" cx="16" cy="16" r="12" pathLength="100" stroke-dasharray="<?php echo e($workload['ratio'] !== null ? min(100, max(0, $workload['ratio'] * 100)) : 0); ?> 100" /></svg>
                <span aria-hidden="true"><?php echo e($workload['state'] === 'over' ? '!' : ($workload['percent'] === null ? '–' : '')); ?></span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </button>
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <article class="rt-timeline-workload-detail" data-rt-dropdown-keep-open>
            <header><strong><?php echo e($person->name); ?></strong><span><?php echo e($days->first()->format('d.m.')); ?> – <?php echo e($days->last()->format('d.m.Y')); ?></span></header>
            <div class="rt-timeline-workload-detail__total"><strong><?php echo e($hours($workload['planned'])); ?></strong><span><?php echo e($workloadLabel); ?></span></div>
            <dl>
                <div><dt>Bestätigte Schichten</dt><dd><?php echo e($hours($workload['confirmed'])); ?></dd></div>
                <div><dt>Angefragte Schichten</dt><dd><?php echo e($hours($workload['requested'])); ?></dd></div>
                <div><dt>Schulungen</dt><dd><?php echo e($hours($workload['training'])); ?></dd></div>
                <div><dt>Soll im Zeitraum</dt><dd><?php echo e($workload['target'] !== null ? $hours($workload['target']) : 'Nicht vollständig hinterlegt'); ?></dd></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($workload['target'] !== null): ?><div><dt><?php echo e($workload['planned'] > $workload['target'] ? 'Über Soll' : 'Noch bis Soll'); ?></dt><dd><?php echo e($hours(abs($workload['target'] - $workload['planned']))); ?></dd></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div><dt>Schichten / Abwesenheiten</dt><dd><?php echo e($workload['shift_count']); ?> / <?php echo e($workload['absence_count']); ?></dd></div>
            </dl>
            <p>Planungsstand, keine Ist-Zeiten. Soll aus freigegebenen Tageswerten; Abwesenheiten sind nicht abgezogen. Freie Stunden bedeuten nicht automatisch Verfügbarkeit.</p>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($workload['clipped_breaks']): ?><p>Bei angeschnittenen Schichten ist die Pausenlage unbekannt; diese Stunden enthalten die Pause.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </article>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $attributes = $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492)): ?>
<?php $component = $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492; ?>
<?php unset($__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/partials/timeline-workload.blade.php ENDPATH**/ ?>