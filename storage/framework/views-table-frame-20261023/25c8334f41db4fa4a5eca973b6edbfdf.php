<?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => '80','offset' => '6','maxHeight' => 740,'dropdownId' => 'timeline-filters-'.e($this->getId()).'','layerGroup' => 'operations-shift-plan','contentRole' => 'dialog','contentLabel' => 'Zeitleiste filtern','dropdownClasses' => 'rt-timeline-filter-dropdown','contentClasses' => 'rt-timeline-filters bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => '80','offset' => '6','max-height' => 740,'dropdown-id' => 'timeline-filters-'.e($this->getId()).'','layer-group' => 'operations-shift-plan','content-role' => 'dialog','content-label' => 'Zeitleiste filtern','dropdown-classes' => 'rt-timeline-filter-dropdown','content-classes' => 'rt-timeline-filters bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-timeline-filter-trigger','dataTimelineFilterTrigger' => true,'dataActive' => ''.e($timelineFilterCount > 0 ? 'true' : 'false').'','ariaLabel' => 'Zeitleiste filtern'.e($timelineFilterCount ? ': '.$timelineFilterCount.' aktiv' : '').'','title' => 'Mitarbeiter und Schichten filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-timeline-filter-trigger','data-timeline-filter-trigger' => true,'data-active' => ''.e($timelineFilterCount > 0 ? 'true' : 'false').'','aria-label' => 'Zeitleiste filtern'.e($timelineFilterCount ? ': '.$timelineFilterCount.' aktiv' : '').'','title' => 'Mitarbeiter und Schichten filtern']); ?>
            <i class="far fa-filter" aria-hidden="true"></i>
            <span class="rt-timeline-filter-trigger__label">Filter</span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($timelineFilterCount > 0): ?><span class="rt-timeline-filter-count" aria-hidden="true"><?php echo e($timelineFilterCount); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
     <?php $__env->endSlot(); ?>
     <?php $__env->slot('content', null, []); ?> 
        <header class="rt-timeline-filters__header">
            <strong>Zeitleiste filtern</strong>
            <button type="button" class="rt-timeline-filters__close" x-on:click="close(true)" aria-label="Filter schließen"><i class="far fa-times" aria-hidden="true"></i></button>
        </header>
        <div class="rt-timeline-filters__body">
            <fieldset class="rt-timeline-filters__group">
                <legend><i class="far fa-users" aria-hidden="true"></i> Mitarbeiter</legend>
                <label for="timeline-filter-search-<?php echo e($this->getId()); ?>">Name suchen</label>
                <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['type' => 'search','id' => 'timeline-filter-search-'.e($this->getId()).'','wire:model.live.debounce.300ms' => 'search','maxlength' => '100','autocomplete' => 'off','placeholder' => 'Name eingeben …','ariaLabel' => 'Mitarbeiter nach Name filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'search','id' => 'timeline-filter-search-'.e($this->getId()).'','wire:model.live.debounce.300ms' => 'search','maxlength' => '100','autocomplete' => 'off','placeholder' => 'Name eingeben …','aria-label' => 'Mitarbeiter nach Name filtern']); ?>
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
                <label for="timeline-filter-presence-<?php echo e($this->getId()); ?>">Einteilung im gewählten Zeitraum</label>
                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'timeline-filter-presence-'.e($this->getId()).'','wire:model.live' => 'staffPresence','ariaLabel' => 'Mitarbeiter nach Einteilung filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'timeline-filter-presence-'.e($this->getId()).'','wire:model.live' => 'staffPresence','aria-label' => 'Mitarbeiter nach Einteilung filtern']); ?>
                    <option value="all">Alle Mitarbeiter</option>
                    <option value="assigned">Mit eingeplanten Schichten</option>
                    <option value="unassigned">Ohne eingeplante Schichten</option>
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
            </fieldset>
            <fieldset class="rt-timeline-filters__group">
                <legend><i class="far fa-clock" aria-hidden="true"></i> Schichten</legend>
                <label for="timeline-filter-role-<?php echo e($this->getId()); ?>">Schichtart / Funktion</label>
                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'timeline-filter-role-'.e($this->getId()).'','wire:model.live' => 'shiftRole','ariaLabel' => 'Schichten nach Funktion filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'timeline-filter-role-'.e($this->getId()).'','wire:model.live' => 'shiftRole','aria-label' => 'Schichten nach Funktion filtern']); ?>
                    <option value="all">Alle Funktionen</option>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $timelineRoleOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                <div class="rt-timeline-filters__columns">
                    <div>
                        <label for="timeline-filter-status-<?php echo e($this->getId()); ?>">Schichtstatus</label>
                        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'timeline-filter-status-'.e($this->getId()).'','wire:model.live' => 'shiftStatus','ariaLabel' => 'Schichten nach Status filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'timeline-filter-status-'.e($this->getId()).'','wire:model.live' => 'shiftStatus','aria-label' => 'Schichten nach Status filtern']); ?>
                            <option value="all">Alle</option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $timelineShiftStatusOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                    </div>
                    <div>
                        <label for="timeline-filter-assignment-<?php echo e($this->getId()); ?>">Zuweisung</label>
                        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'timeline-filter-assignment-'.e($this->getId()).'','wire:model.live' => 'assignmentStatusFilter','ariaLabel' => 'Schichten nach Zuweisung filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'timeline-filter-assignment-'.e($this->getId()).'','wire:model.live' => 'assignmentStatusFilter','aria-label' => 'Schichten nach Zuweisung filtern']); ?>
                            <option value="all">Alle</option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $timelineAssignmentStatusOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                    </div>
                </div>
            </fieldset>
            <p class="rt-timeline-filters__hint">Filter ändern nur die Anzeige. Abwesenheiten und die vollständige Arbeitszeit bleiben sichtbar.</p>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->hasAny(['staffPresence', 'shiftRole', 'shiftStatus', 'assignmentStatusFilter'])): ?>
                <p class="text-xs text-rt-red dark:text-rt-dark-accent" role="alert">Dieser Filter ist nicht mehr verfügbar. Bitte eine andere Option wählen.</p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
        <footer class="rt-timeline-filters__footer">
            <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','mode' => 'link','wire:click' => 'resetTimelineFilters','wire:loading.attr' => 'disabled','wire:target' => 'resetTimelineFilters','disabled' => $timelineFilterCount === 0 ? true : null]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','mode' => 'link','wire:click' => 'resetTimelineFilters','wire:loading.attr' => 'disabled','wire:target' => 'resetTimelineFilters','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($timelineFilterCount === 0 ? true : null)]); ?>
                <i class="far fa-rotate-left" aria-hidden="true"></i> Zurücksetzen
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
            <span class="rt-timeline-filters__feedback" role="status" aria-live="polite">
                <span wire:loading.remove wire:target="search,staffPresence,shiftRole,shiftStatus,assignmentStatusFilter,resetTimelineFilters"><?php echo e($timelineFilterCount ? $timelineFilterCount.' aktiv' : 'Keine Filter'); ?></span>
                <span wire:loading wire:target="search,staffPresence,shiftRole,shiftStatus,assignmentStatusFilter,resetTimelineFilters">Wird gefiltert …</span>
            </span>
        </footer>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/partials/timeline-filters.blade.php ENDPATH**/ ?>