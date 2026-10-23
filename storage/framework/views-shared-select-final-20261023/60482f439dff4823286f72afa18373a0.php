<div class="rt-calendar rt-disposition rt-disposition--calendar" <?php if($viewport): ?> data-disposition-viewport="calendar" <?php endif; ?> data-operations-calendar data-calendar-view="<?php echo e($viewMode); ?>" data-calendar-week="<?php echo e($weekStartDate->toDateString()); ?>"
    x-data="rtShiftDetailDrawer" x-on:operations-shift-detail-request.window="openShiftDetail($event.detail.id)">
    <template x-teleport="[data-topbar-page-search]" wire:key="calendar-topbar-search-<?php echo e($this->getId()); ?>">
        <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','resultsCount' => $shifts->count(),'placeholder' => 'Schicht, Kunde oder Ort','ariaLabel' => 'Kalenderschichten suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','results-count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shifts->count()),'placeholder' => 'Schicht, Kunde oder Ort','aria-label' => 'Kalenderschichten suchen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $attributes = $__attributesOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $component = $__componentOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__componentOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
    </template>
    <?php
        $calendarViews = [
            'month' => ['Monat', 'fa-calendar-alt', 'Der ganze Monat mit offenen Plätzen'],
            'week' => ['Woche', 'fa-calendar-week', 'Sieben Tage mit allen Schichten'],
            'day' => ['Tag', 'fa-calendar-day', 'Ein Tag im Detail'],
            'list' => ['Liste', 'fa-list-ul', 'Sortierbare Tabelle des Zeitraums'],
        ];
        [$currentViewLabel, $currentViewIcon] = $calendarViews[$viewMode];
        $periodHint = match ($viewMode) {
            'week', 'list' => 'KW '.$weekStartDate->isoWeek(),
            'month' => $shiftCount.' '.($shiftCount === 1 ? 'Schicht' : 'Schichten'),
            default => $shiftCount.' '.($shiftCount === 1 ? 'Schicht' : 'Schichten'),
        };
    ?>
    
    <template x-teleport="[data-page-header-search]" wire:key="calendar-header-controls-<?php echo e($this->getId()); ?>">
        <div class="rt-shift-plan-header-controls rt-calendar-header-controls" data-calendar-header-controls>
            <div class="rt-calendar-period-group" role="group" aria-label="Kalenderzeitraum">
                <button type="button" class="rt-calendar-step" wire:click="previousPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Vorheriger Zeitraum" title="Vorheriger Zeitraum"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => 'auto','offset' => '6','dropdownId' => 'calendar-period-'.e($this->getId()).'','layerGroup' => 'operations-calendar','contentRole' => 'dialog','contentLabel' => 'Datum wählen','dropdownClasses' => 'rt-calendar-period-dropdown','contentClasses' => 'p-3 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => 'auto','offset' => '6','dropdown-id' => 'calendar-period-'.e($this->getId()).'','layer-group' => 'operations-calendar','content-role' => 'dialog','content-label' => 'Datum wählen','dropdown-classes' => 'rt-calendar-period-dropdown','content-classes' => 'p-3 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
                     <?php $__env->slot('trigger', null, []); ?> 
                        <button type="button" class="rt-calendar-period-trigger" aria-label="Datum wählen: <?php echo e($periodLabel); ?>" title="Zu einem Datum springen">
                            <span><strong class="rt-calendar-period" aria-live="polite" aria-atomic="true"><?php echo e($periodLabel); ?></strong><small><?php echo e($periodHint); ?> · <?php echo e($displayTimezone); ?></small></span>
                            <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
                        </button>
                     <?php $__env->endSlot(); ?>
                     <?php $__env->slot('content', null, []); ?> 
                        <div class="rt-calendar-jump">
                            <label class="rt-calendar-jump__label" for="calendar-anchor-date">Springe zu Datum</label>
                            <?php if (isset($component)) { $__componentOriginal5f8fcc47e27967eb225f394cd5723df5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5f8fcc47e27967eb225f394cd5723df5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-field','data' => ['id' => 'calendar-anchor-date','wire:model.live' => 'anchorDate','clearable' => false,'keepDropdownOpen' => true,'ariaLabel' => 'Kalenderdatum']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'calendar-anchor-date','wire:model.live' => 'anchorDate','clearable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'keep-dropdown-open' => true,'aria-label' => 'Kalenderdatum']); ?>
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
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['anchorDate'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="rt-calendar-jump__error" role="alert"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
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
                <button type="button" class="rt-calendar-step" wire:click="nextPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Nächster Zeitraum" title="Nächster Zeitraum"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
            </div>
            <button type="button" class="rt-calendar-today" wire:click="today" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today">Heute</button>

            <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => '72','offset' => '6','dropdownId' => 'calendar-view-'.e($this->getId()).'','layerGroup' => 'operations-calendar','contentLabel' => 'Kalenderansicht auswählen','contentClasses' => 'rt-shift-plan-view-menu p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => '72','offset' => '6','dropdown-id' => 'calendar-view-'.e($this->getId()).'','layer-group' => 'operations-calendar','content-label' => 'Kalenderansicht auswählen','content-classes' => 'rt-shift-plan-view-menu p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
                 <?php $__env->slot('trigger', null, []); ?> 
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-calendar-view-trigger','ariaLabel' => 'Kalenderansicht ändern: '.e($currentViewLabel).'','title' => 'Ansicht: '.e($currentViewLabel).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-calendar-view-trigger','aria-label' => 'Kalenderansicht ändern: '.e($currentViewLabel).'','title' => 'Ansicht: '.e($currentViewLabel).'']); ?>
                        <i class="far <?php echo e($currentViewIcon); ?>" aria-hidden="true"></i>
                        <span class="rt-shift-plan-control__label"><strong><?php echo e($currentViewLabel); ?></strong></span>
                        <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
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
                    <p class="rt-shift-plan-menu-heading" role="presentation">Kalender anzeigen als</p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $calendarViews; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $view => [$label, $icon, $description]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <button type="button" role="menuitemradio" aria-checked="<?php echo e($viewMode === $view ? 'true' : 'false'); ?>" wire:click="switchView('<?php echo e($view); ?>')" x-on:click="close()" class="rt-shift-plan-view-option" data-calendar-view-option="<?php echo e($view); ?>">
                            <i class="far <?php echo e($icon); ?>" aria-hidden="true"></i><span><strong><?php echo e($label); ?></strong><small><?php echo e($description); ?></small></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === $view): ?><i class="far fa-check rt-shift-plan-view-option__check" aria-hidden="true"></i><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </button>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Operations\OperationsAccess::ready() && auth()->user()->can('operations.manage')): ?>
                        <div class="rt-shift-plan-view-divider" role="separator"></div>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','role' => 'menuitem','class' => 'rt-shift-plan-view-option','dataCalendarPlanningReturn' => true,'href' => \App\Support\Operations\OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'order' => $orderFilter !== 'all' ? (int) $orderFilter : null, 'customer' => $customerFilter !== 'all' ? (int) $customerFilter : null, 'from' => $planningRange['from'] ?? $weekStartDate->toDateString(), 'until' => $planningRange['until'] ?? $weekEndDate->toDateString()])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','role' => 'menuitem','class' => 'rt-shift-plan-view-option','data-calendar-planning-return' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(\App\Support\Operations\OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'order' => $orderFilter !== 'all' ? (int) $orderFilter : null, 'customer' => $customerFilter !== 'all' ? (int) $customerFilter : null, 'from' => $planningRange['from'] ?? $weekStartDate->toDateString(), 'until' => $planningRange['until'] ?? $weekEndDate->toDateString()]))]); ?>
                            <i class="far fa-clock" aria-hidden="true"></i><span><strong>Schichtplanung öffnen</strong><small>Zeitleiste, Leistungsübersicht und Besetzung</small></span>
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
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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

            <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => '80','offset' => '6','dropdownId' => 'calendar-filter-'.e($this->getId()).'','layerGroup' => 'operations-calendar','contentRole' => 'dialog','contentLabel' => 'Kalender filtern','contentClasses' => 'p-3 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => '80','offset' => '6','dropdown-id' => 'calendar-filter-'.e($this->getId()).'','layer-group' => 'operations-calendar','content-role' => 'dialog','content-label' => 'Kalender filtern','content-classes' => 'p-3 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
                 <?php $__env->slot('trigger', null, []); ?> 
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-calendar-filter-trigger','ariaLabel' => 'Filter'.e($filterCount ? ': '.$filterCount.' aktiv' : '').'','title' => 'Kunde, Leistung und Status filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-calendar-filter-trigger','aria-label' => 'Filter'.e($filterCount ? ': '.$filterCount.' aktiv' : '').'','title' => 'Kunde, Leistung und Status filtern']); ?>
                        <i class="far fa-filter" aria-hidden="true"></i>
                        <span class="rt-shift-plan-control__label"><strong>Filter</strong></span>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($filterCount > 0): ?><span class="rt-calendar-filter-count" aria-hidden="true"><?php echo e($filterCount); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
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
                    <div class="rt-calendar-filters" id="calendar-filters">
                        <label class="rt-calendar-filters__field" for="calendar-customer"><span>Kunde</span>
                            <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'calendar-customer','wire:model.live' => 'customerFilter','ariaLabel' => 'Kalenderkunde']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'calendar-customer','wire:model.live' => 'customerFilter','aria-label' => 'Kalenderkunde']); ?><option value="all">Alle Kunden</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $customers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $customer): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($customer->id); ?>"><?php echo e($customer->company_name); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
                        </label>
                        <label class="rt-calendar-filters__field" for="calendar-order"><span>Leistung / Auftrag</span>
                            <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'calendar-order','wire:model.live' => 'orderFilter','searchModel' => 'orderSearch','ariaLabel' => 'Kalenderauftrag']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'calendar-order','wire:model.live' => 'orderFilter','search-model' => 'orderSearch','aria-label' => 'Kalenderauftrag']); ?><option value="all">Alle Leistungen</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $order): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($order->id); ?>"><?php echo e($order->order_number); ?> · <?php echo e($order->title); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
                        </label>
                        <label class="rt-calendar-filters__field" for="calendar-status"><span>Status</span>
                            <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'calendar-status','wire:model.live' => 'statusFilter','ariaLabel' => 'Kalenderstatus']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'calendar-status','wire:model.live' => 'statusFilter','aria-label' => 'Kalenderstatus']); ?><option value="active">Ohne stornierte</option><option value="all">Alle Status</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $statusOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
                        </label>
                        <div class="rt-calendar-filters__toggle"><?php if (isset($component)) { $__componentOriginal73478c8be7272bff1d507d0522fd3d44 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal73478c8be7272bff1d507d0522fd3d44 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.checkbox','data' => ['wire:model.live' => 'onlyOpen','label' => 'Nur offene Besetzung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.checkbox'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model.live' => 'onlyOpen','label' => 'Nur offene Besetzung']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $attributes = $__attributesOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $component = $__componentOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__componentOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?></div>
                        <div class="rt-calendar-filters__foot">
                            <button type="button" class="rt-calendar-filters__reset" wire:click="resetFilters" x-on:click="close()" <?php if($filterCount === 0 && blank($search)): echo 'disabled'; endif; ?>><i class="far fa-undo" aria-hidden="true"></i>Zurücksetzen</button>
                        </div>
                    </div>
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

            
            <button type="button" class="rt-calendar-open-toggle" wire:click="$toggle('onlyOpen')" aria-pressed="<?php echo e($onlyOpen ? 'true' : 'false'); ?>" data-has-open="<?php echo e($openCount > 0 ? 'true' : 'false'); ?>"
                aria-label="<?php echo e($openCount); ?> offene Plätze · <?php echo e($onlyOpen ? 'alle Schichten zeigen' : 'nur offene zeigen'); ?>" title="<?php echo e($reservedCount); ?>/<?php echo e($requiredCount); ?> Plätze eingeplant">
                <span class="rt-calendar-open-toggle__dot" aria-hidden="true"></span><strong><?php echo e($openCount); ?></strong> offen
            </button>
        </div>
    </template>
    <?php if (isset($component)) { $__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.feedback','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.feedback'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5)): ?>
<?php $attributes = $__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5; ?>
<?php unset($__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5)): ?>
<?php $component = $__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5; ?>
<?php unset($__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5); ?>
<?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewport): ?><div data-disposition-calendar-surface><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'list'): ?>
        <div class="rt-disposition-table">
            <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Schicht / Kunde','key'=>'title','width'=>'2fr'],['label'=>'Zeitraum','key'=>'starts_at','width'=>'1.5fr'],['label'=>'Besetzung','key'=>'required_staff'],['label'=>'Status','key'=>'status']],'items' => $shifts,'flushTop' => $viewport,'scrollBody' => $viewport,'label' => 'Kalenderschichten','dataDispositionListTable' => true,'detailEvent' => 'operations-shift-detail-request','rowView' => 'components.tables.rows.operations.calendar','empty' => 'Keine Schichten in diesem Zeitraum.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Schicht / Kunde','key'=>'title','width'=>'2fr'],['label'=>'Zeitraum','key'=>'starts_at','width'=>'1.5fr'],['label'=>'Besetzung','key'=>'required_staff'],['label'=>'Status','key'=>'status']]),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shifts),'flush-top' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($viewport),'scroll-body' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($viewport),'label' => 'Kalenderschichten','data-disposition-list-table' => true,'detail-event' => 'operations-shift-detail-request','row-view' => 'components.tables.rows.operations.calendar','empty' => 'Keine Schichten in diesem Zeitraum.']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a)): ?>
<?php $attributes = $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a; ?>
<?php unset($__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a)): ?>
<?php $component = $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a; ?>
<?php unset($__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a); ?>
<?php endif; ?>
        </div>
    <?php elseif($viewMode === 'month'): ?>
        <?php
            $statePriority = ['open' => 0, 'pending' => 1, 'staffed' => 2, 'closed' => 3];
        ?>
        <div class="rt-calendar-month" x-data="rtCalendarReveal" data-calendar-reveal-key="<?php echo e($viewMode); ?>-<?php echo e($weekStartDate->toDateString()); ?>" aria-label="Monatskalender <?php echo e($periodLabel); ?>" style="--calendar-weeks: <?php echo e((int) ceil($days->count() / 7)); ?>">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $weekday): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-calendar-weekday', 'is-weekend' => $loop->index > 4]); ?>"><span class="rt-calendar-weekday__long"><?php echo e($weekday); ?></span><span class="rt-calendar-weekday__short" aria-hidden="true"><?php echo e(mb_substr($weekday, 0, 2)); ?></span></div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $days; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    // Monat: je Tag nur Schichten, die an diesem Tag beginnen; Lücken zuerst, dann nach Beginn.
                    $dayShifts = $day['shifts']->filter(fn ($shift) => $shift->calendar_starts->isSameDay($day['date']))
                        ->sortBy(fn ($shift) => [$statePriority[$shift->calendar_state] ?? 9, $shift->calendar_starts->getTimestamp()])->values();
                    $dayOpen = (int) $dayShifts->sum('calendar_open');
                ?>
                <section class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-calendar-month-day', 'is-outside' => !$day['in_month'], 'is-today' => $day['is_today'], 'is-weekend' => $day['date']->isWeekend(), 'has-open' => $dayOpen > 0]); ?>" wire:key="month-day-<?php echo e($day['date']->toDateString()); ?>" data-calendar-day="<?php echo e($day['date']->toDateString()); ?>">
                    <div class="rt-calendar-month-day__head">
                        <button type="button" class="rt-calendar-month-date" wire:click="showDay('<?php echo e($day['date']->toDateString()); ?>')" aria-current="<?php echo e($day['is_today'] ? 'date' : 'false'); ?>" aria-label="<?php echo e($day['date']->locale('de')->isoFormat('dddd, D. MMMM')); ?> öffnen"><?php echo e($day['date']->format('j')); ?></button>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($dayOpen > 0): ?>
                            <span class="rt-calendar-day-open" title="<?php echo e($dayOpen); ?> <?php echo e($dayOpen === 1 ? 'Platz' : 'Plätze'); ?> offen"><?php echo e($dayOpen); ?> offen</span>
                        <?php elseif($dayShifts->isNotEmpty()): ?>
                            <span class="rt-calendar-day-total"><?php echo e($dayShifts->count()); ?></span>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div class="rt-calendar-month-day__shifts">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $dayShifts->take(3); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $shift): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <button type="button" class="rt-calendar-chip" x-on:click="$dispatch('operations-shift-detail-request', { id: <?php echo e($shift->id); ?> })" aria-haspopup="dialog" data-calendar-reveal-item data-calendar-shift="<?php echo e($shift->id); ?>" data-calendar-state="<?php echo e($shift->calendar_state); ?>" data-calendar-staffing="<?php echo e($shift->calendar_open > 0 ? 'open' : 'staffed'); ?>" <?php if((int) $shift->published_revision === 0): ?> data-calendar-draft="true" <?php endif; ?>
                                title="<?php echo e($shift->calendar_starts->format('H:i')); ?>–<?php echo e($shift->calendar_ends->format('H:i')); ?> · <?php echo e($shift->title); ?> · <?php echo e($shift->calendar_reserved); ?>/<?php echo e($shift->required_staff); ?> eingeplant">
                                <span class="rt-calendar-chip__dot" aria-hidden="true"></span>
                                <span class="rt-calendar-chip__time"><?php echo e($shift->calendar_starts->format('H:i')); ?></span>
                                <span class="rt-calendar-chip__title"><?php echo e($shift->title); ?></span>
                                <span class="rt-calendar-chip__staff"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shift->calendar_state === 'open'): ?><?php echo e($shift->calendar_open); ?> offen <?php elseif($shift->calendar_state === 'pending'): ?><?php echo e($shift->calendar_requested); ?> angefr. <?php else: ?><?php echo e($shift->calendar_reserved); ?>/<?php echo e($shift->required_staff); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                            </button>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($dayShifts->count() > 3): ?>
                            <button type="button" class="rt-calendar-month-more" wire:click="showDay('<?php echo e($day['date']->toDateString()); ?>')">+<?php echo e($dayShifts->count() - 3); ?> weitere</button>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($dayShifts->isNotEmpty()): ?>
                        <span class="rt-calendar-day-dots" aria-hidden="true">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $dayShifts->take(4); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $shift): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><i data-calendar-state="<?php echo e($shift->calendar_state); ?>"></i><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </section>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php else: ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'week'): ?>
            <div class="rt-calendar-week" x-data="rtCalendarReveal" data-calendar-desktop-grid aria-label="Wochenkalender">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $days; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <section class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-calendar-week-day', 'is-today'=>$day['is_today'], 'is-weekend' => $day['date']->isWeekend()]); ?>" wire:key="week-day-<?php echo e($day['date']->toDateString()); ?>" data-calendar-day="<?php echo e($day['date']->toDateString()); ?>">
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','type' => 'button','class' => 'rt-calendar-week-date','wire:click' => 'showDay(\''.e($day['date']->toDateString()).'\')','ariaCurrent' => ''.e($day['is_today'] ? 'date' : 'false').'','ariaLabel' => ''.e($day['date']->format('d.m.Y')).' öffnen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','type' => 'button','class' => 'rt-calendar-week-date','wire:click' => 'showDay(\''.e($day['date']->toDateString()).'\')','aria-current' => ''.e($day['is_today'] ? 'date' : 'false').'','aria-label' => ''.e($day['date']->format('d.m.Y')).' öffnen']); ?>
                            <span><?php echo e($day['date']->locale('de')->isoFormat('dd')); ?></span><strong><?php echo e($day['date']->format('d')); ?></strong>
                            <span class="rt-calendar-week-count"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($day['is_today']): ?>Heute · <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><?php echo e($day['shifts']->count()); ?> <?php echo e($day['shifts']->count() === 1 ? 'Schicht' : 'Schichten'); ?></span>
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
                        <div class="rt-calendar-week-shifts">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $day['shifts']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $shift): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if (isset($component)) { $__componentOriginal504bd9ebefd079e1a63c73556c71b318 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal504bd9ebefd079e1a63c73556c71b318 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.calendar-shift','data' => ['shift' => $shift,'compact' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.calendar-shift'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['shift' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shift),'compact' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal504bd9ebefd079e1a63c73556c71b318)): ?>
<?php $attributes = $__attributesOriginal504bd9ebefd079e1a63c73556c71b318; ?>
<?php unset($__attributesOriginal504bd9ebefd079e1a63c73556c71b318); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal504bd9ebefd079e1a63c73556c71b318)): ?>
<?php $component = $__componentOriginal504bd9ebefd079e1a63c73556c71b318; ?>
<?php unset($__componentOriginal504bd9ebefd079e1a63c73556c71b318); ?>
<?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p class="rt-calendar-empty-cell">Keine Schichten</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-calendar-agenda', 'xl:hidden'=>$viewMode === 'week']); ?>" x-data="rtCalendarReveal" data-calendar-mobile-agenda>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $days; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $day): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <section class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-calendar-agenda-day', 'is-today' => $day['is_today'], 'is-empty' => $day['shifts']->isEmpty()]); ?>" wire:key="agenda-day-<?php echo e($day['date']->toDateString()); ?>" data-calendar-day="<?php echo e($day['date']->toDateString()); ?>">
                    <h3 class="rt-calendar-agenda-heading">
                        <span class="rt-calendar-agenda-date" aria-hidden="true"><span><?php echo e($day['date']->locale('de')->isoFormat('dd')); ?></span><strong><?php echo e($day['date']->format('d')); ?></strong></span>
                        <span class="rt-calendar-agenda-label"><?php echo e($day['date']->locale('de')->isoFormat('dddd, D. MMMM')); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($day['is_today']): ?><span class="rt-calendar-today-label">Heute</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                        <span class="rt-calendar-agenda-count"><?php echo e($day['shifts']->isEmpty() ? 'Keine Schichten' : $day['shifts']->count().' '.($day['shifts']->count() === 1 ? 'Schicht' : 'Schichten')); ?></span>
                    </h3>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($day['shifts']->isNotEmpty()): ?>
                        <div class="rt-calendar-agenda-shifts">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $day['shifts']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $shift): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if (isset($component)) { $__componentOriginal504bd9ebefd079e1a63c73556c71b318 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal504bd9ebefd079e1a63c73556c71b318 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.calendar-shift','data' => ['shift' => $shift]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.calendar-shift'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['shift' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shift)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal504bd9ebefd079e1a63c73556c71b318)): ?>
<?php $attributes = $__attributesOriginal504bd9ebefd079e1a63c73556c71b318; ?>
<?php unset($__attributesOriginal504bd9ebefd079e1a63c73556c71b318); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal504bd9ebefd079e1a63c73556c71b318)): ?>
<?php $component = $__componentOriginal504bd9ebefd079e1a63c73556c71b318; ?>
<?php unset($__componentOriginal504bd9ebefd079e1a63c73556c71b318); ?>
<?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="rt-calendar-agenda-empty" aria-hidden="true">Keine Schichten</p>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </section>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewport): ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php echo $__env->make('livewire.admin.operations.partials.calendar-shift-panel', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/calendar.blade.php ENDPATH**/ ?>