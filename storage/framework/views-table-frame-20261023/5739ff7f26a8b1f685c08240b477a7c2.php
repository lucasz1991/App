<div class="rt-disposition rt-disposition--shifts rt-shift-plan min-w-0" data-operations-shift-management
    x-data="rtShiftDetailDrawer" x-on:operations-shift-detail-request.window="openShiftDetail($event.detail.id)">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode !== 'timeline'): ?>
        <template x-teleport="[data-topbar-page-search]" wire:key="shift-plan-topbar-search">
            <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','resultsCount' => $shifts->count(),'placeholder' => 'Schicht, Kunde oder Einsatzort suchen','ariaLabel' => 'Schichten suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','results-count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shifts->count()),'placeholder' => 'Schicht, Kunde oder Einsatzort suchen','aria-label' => 'Schichten suchen']); ?>
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
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <template x-teleport="[data-page-header-search]">
        <div class="rt-shift-plan-header-controls" data-shift-plan-header-controls>
            <?php
                $planViews = [
                    'table' => ['Tabelle', 'fa-list-alt'],
                    'day' => ['Tagesübersicht', 'fa-calendar-day'],
                    'staffing' => ['Besetzung', 'fa-users'],
                    'orders' => ['Leistungsübersicht', 'fa-briefcase'],
                    'timeline' => ['Zeitleiste', 'fa-clock'],
                    'map' => ['Karte', 'fa-map-marked-alt'],
                ];
                [$currentViewLabel, $currentViewIcon] = $planViews[$viewMode];
                $timelineDatePicker = $viewMode === 'timeline';
                $rangeLabel = ($rangeFrom ? \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.Y') : '').' – '.($rangeTo ? \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') : '');
                $pendingTotal = $pendingShifts->total() + $unplannedOrders->total();
                $viewDescriptions = [
                    'table' => 'Alle Schichten als sortierbare Liste',
                    'day' => 'Nach Einsatztagen gruppiert',
                    'staffing' => 'Offene und besetzte Schichten',
                    'orders' => 'Schichten je Leistung bündeln',
                    'timeline' => 'Mitarbeiter und Zeiten im Überblick',
                    'map' => 'Schichten nach Einsatzort auf der Deutschlandkarte',
                ];
            ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'orders'): ?>
                <?php if (isset($component)) { $__componentOriginalab18da67b6c969a9158a73a6ce596dde = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalab18da67b6c969a9158a73a6ce596dde = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-table-selection','data' => ['count' => count($selectedServiceRows)]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-table-selection'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(count($selectedServiceRows))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalab18da67b6c969a9158a73a6ce596dde)): ?>
<?php $attributes = $__attributesOriginalab18da67b6c969a9158a73a6ce596dde; ?>
<?php unset($__attributesOriginalab18da67b6c969a9158a73a6ce596dde); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalab18da67b6c969a9158a73a6ce596dde)): ?>
<?php $component = $__componentOriginalab18da67b6c969a9158a73a6ce596dde; ?>
<?php unset($__componentOriginalab18da67b6c969a9158a73a6ce596dde); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-shift-plan-period-controls', 'rt-shift-plan-period-controls--date' => $timelineDatePicker]); ?>">
                <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'left','width' => 'auto','offset' => '6','maxHeight' => $timelineDatePicker ? 640 : 448,'dropdownId' => 'shift-plan-period-'.e($this->getId()).'','layerGroup' => 'operations-shift-plan','contentRole' => 'dialog','contentLabel' => $timelineDatePicker ? 'Tag auswählen' : 'Planungszeitraum anpassen','dropdownClasses' => $timelineDatePicker ? 'rt-shift-plan-date-dropdown' : 'rt-shift-plan-range-dropdown','contentClasses' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'left','width' => 'auto','offset' => '6','max-height' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($timelineDatePicker ? 640 : 448),'dropdown-id' => 'shift-plan-period-'.e($this->getId()).'','layer-group' => 'operations-shift-plan','content-role' => 'dialog','content-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($timelineDatePicker ? 'Tag auswählen' : 'Planungszeitraum anpassen'),'dropdown-classes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($timelineDatePicker ? 'rt-shift-plan-date-dropdown' : 'rt-shift-plan-range-dropdown'),'content-classes' => 'bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
                     <?php $__env->slot('trigger', null, []); ?> 
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-period-trigger '.e($timelineDatePicker ? 'rt-shift-plan-date-trigger' : '').'','ariaLabel' => ''.e($timelineDatePicker ? 'Zu einem Tag springen' : 'Planungszeitraum auswählen: '.$rangeLabel).'','title' => ''.e($timelineDatePicker ? 'Tag auswählen' : $rangeLabel).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-period-trigger '.e($timelineDatePicker ? 'rt-shift-plan-date-trigger' : '').'','aria-label' => ''.e($timelineDatePicker ? 'Zu einem Tag springen' : 'Planungszeitraum auswählen: '.$rangeLabel).'','title' => ''.e($timelineDatePicker ? 'Tag auswählen' : $rangeLabel).'']); ?>
                            <i class="far fa-calendar-alt" aria-hidden="true"></i>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($timelineDatePicker)): ?>
                            <span><strong class="rt-calendar-period"><?php echo e($rangeFrom ? \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') : ''); ?> – <?php echo e($rangeTo ? \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') : ''); ?></strong></span>
                            <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
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
                     <?php $__env->endSlot(); ?>
                     <?php $__env->slot('content', null, []); ?> 
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($timelineDatePicker): ?>
                            <?php if (isset($component)) { $__componentOriginal0ec80a222b9f3310c75fd8d383c36bce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-range-picker','data' => ['wire:key' => 'shift-plan-date-picker-'.e($this->getId()).'-single','fromModel' => 'rangeFrom','untilModel' => 'rangeTo','applyAction' => 'applyPeriod','applyEvent' => 'operations-timeline-period-request','selectionMode' => 'single','presetKeys' => ['week'],'maxDays' => 94,'resetOnOpen' => true,'xOn:dateRangeApplied' => 'close(true)','xOn:dateRangeCancel' => 'close(true)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-range-picker'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:key' => 'shift-plan-date-picker-'.e($this->getId()).'-single','from-model' => 'rangeFrom','until-model' => 'rangeTo','apply-action' => 'applyPeriod','apply-event' => 'operations-timeline-period-request','selection-mode' => 'single','preset-keys' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(['week']),'max-days' => 94,'reset-on-open' => true,'x-on:date-range-applied' => 'close(true)','x-on:date-range-cancel' => 'close(true)']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce)): ?>
<?php $attributes = $__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce; ?>
<?php unset($__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0ec80a222b9f3310c75fd8d383c36bce)): ?>
<?php $component = $__componentOriginal0ec80a222b9f3310c75fd8d383c36bce; ?>
<?php unset($__componentOriginal0ec80a222b9f3310c75fd8d383c36bce); ?>
<?php endif; ?>
                        <?php else: ?>
                            <?php if (isset($component)) { $__componentOriginal0ec80a222b9f3310c75fd8d383c36bce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-range-picker','data' => ['wire:key' => 'shift-plan-date-picker-'.e($this->getId()).'-range','fromModel' => 'rangeFrom','untilModel' => 'rangeTo','applyAction' => 'applyPeriod','maxDays' => 94,'resetOnOpen' => true,'xOn:dateRangeApplied' => 'close(true)','xOn:dateRangeCancel' => 'close(true)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-range-picker'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:key' => 'shift-plan-date-picker-'.e($this->getId()).'-range','from-model' => 'rangeFrom','until-model' => 'rangeTo','apply-action' => 'applyPeriod','max-days' => 94,'reset-on-open' => true,'x-on:date-range-applied' => 'close(true)','x-on:date-range-cancel' => 'close(true)']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce)): ?>
<?php $attributes = $__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce; ?>
<?php unset($__attributesOriginal0ec80a222b9f3310c75fd8d383c36bce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0ec80a222b9f3310c75fd8d383c36bce)): ?>
<?php $component = $__componentOriginal0ec80a222b9f3310c75fd8d383c36bce; ?>
<?php unset($__componentOriginal0ec80a222b9f3310c75fd8d383c36bce); ?>
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
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($timelineDatePicker): ?>
                <div class="rt-shift-plan-timeline-zoom" role="group" aria-label="Zeitleiste vergrößern oder verkleinern" data-timeline-zoom-controls data-timeline-zoom-owner="<?php echo e($this->getId()); ?>" wire:ignore wire:key="timeline-zoom-<?php echo e($this->getId()); ?>"
                    x-data="{ mode: new URL(window.location.href).searchParams.get('zoom') === 'day' ? 'day' : 'week' }"
                    x-on:operations-timeline-zoom-updated.window="if ($event.detail?.owner === $el.dataset.timelineZoomOwner) mode = $event.detail.mode">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['day' => 'Tag', 'week' => 'Woche']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $zoom => $zoomLabel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','mode' => 'secondary','class' => 'rt-shift-plan-timeline-zoom__button','dataTimelineZoomMode' => ''.e($zoom).'','ariaLabel' => ''.e($zoom === 'day' ? 'Tageszoom: einen Tag anzeigen' : 'Wochenzoom: bis zu sieben Tage anzeigen').'','title' => ''.e($zoom === 'day' ? 'Ein Tag im Detail' : 'Sieben Tage im Überblick').'','ariaPressed' => ''.e((request()->query('zoom') === 'day' ? 'day' : 'week') === $zoom ? 'true' : 'false').'','xBind:ariaPressed' => 'mode === \''.e($zoom).'\' ? \'true\' : \'false\'','xOn:click' => '$dispatch(\'operations-timeline-zoom-request\', { owner: $el.closest(\'[data-timeline-zoom-owner]\').dataset.timelineZoomOwner, mode: \''.e($zoom).'\' })']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','mode' => 'secondary','class' => 'rt-shift-plan-timeline-zoom__button','data-timeline-zoom-mode' => ''.e($zoom).'','aria-label' => ''.e($zoom === 'day' ? 'Tageszoom: einen Tag anzeigen' : 'Wochenzoom: bis zu sieben Tage anzeigen').'','title' => ''.e($zoom === 'day' ? 'Ein Tag im Detail' : 'Sieben Tage im Überblick').'','aria-pressed' => ''.e((request()->query('zoom') === 'day' ? 'day' : 'week') === $zoom ? 'true' : 'false').'','x-bind:aria-pressed' => 'mode === \''.e($zoom).'\' ? \'true\' : \'false\'','x-on:click' => '$dispatch(\'operations-timeline-zoom-request\', { owner: $el.closest(\'[data-timeline-zoom-owner]\').dataset.timelineZoomOwner, mode: \''.e($zoom).'\' })']); ?><?php echo e($zoomLabel); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['class' => 'rt-shift-plan-view-dropdown','align' => 'left','width' => '72','offset' => '6','dropdownId' => 'shift-plan-view-'.e($this->getId()).'','layerGroup' => 'operations-shift-plan','contentLabel' => 'Schichtplanansicht auswählen','contentClasses' => 'rt-shift-plan-view-menu p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-shift-plan-view-dropdown','align' => 'left','width' => '72','offset' => '6','dropdown-id' => 'shift-plan-view-'.e($this->getId()).'','layer-group' => 'operations-shift-plan','content-label' => 'Schichtplanansicht auswählen','content-classes' => 'rt-shift-plan-view-menu p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
                 <?php $__env->slot('trigger', null, []); ?> 
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-view-trigger','ariaLabel' => 'Ansicht ändern: '.e($currentViewLabel).'','title' => 'Ansicht: '.e($currentViewLabel).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-view-trigger','aria-label' => 'Ansicht ändern: '.e($currentViewLabel).'','title' => 'Ansicht: '.e($currentViewLabel).'']); ?>
                        <i class="far <?php echo e($currentViewIcon); ?>" aria-hidden="true" data-current-view="<?php echo e($viewMode); ?>"></i>
                        <i class="far fa-eye rt-shift-plan-view-icon-mobile" aria-hidden="true"></i>
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
                    <p class="rt-shift-plan-menu-heading" role="presentation">Schichtplan anzeigen als</p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $planViews; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $view => [$label, $icon]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <button type="button" role="menuitemradio" aria-checked="<?php echo e($viewMode === $view ? 'true' : 'false'); ?>" wire:click="setView('<?php echo e($view); ?>')" x-on:click="close()" class="rt-shift-plan-view-option">
                            <i class="far <?php echo e($icon); ?>" aria-hidden="true"></i><span><strong><?php echo e($label); ?></strong><small><?php echo e($viewDescriptions[$view]); ?></small></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === $view): ?><i class="far fa-check rt-shift-plan-view-option__check" aria-hidden="true"></i><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </button>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('operations.manage')): ?>
                            <a href="<?php echo e(\App\Support\Operations\OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'calendar', 'order' => $orderFilter !== 'all' ? (int) $orderFilter : null, 'customer' => $customerFilter !== 'all' ? (int) $customerFilter : null, 'from' => $rangeFrom, 'until' => $rangeTo])); ?>" wire:navigate role="menuitem" x-on:click="close()" class="rt-shift-plan-view-option" data-shift-calendar-link>
                                <i class="far fa-calendar-alt" aria-hidden="true"></i><span><strong>Kalender</strong><small>Schichten im Monats-, Wochen- oder Tageskalender</small></span>
                            </a>
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
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($timelineDatePicker): ?>
                <div data-timeline-filter-owner="<?php echo e($this->getId()); ?>" wire:ignore wire:key="timeline-filter-host-<?php echo e($this->getId()); ?>"></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </template>
    <template x-teleport="[data-page-header-actions]">
            <div class="rt-shift-plan-header-action" data-shift-plan-header-actions>
            
            <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-distribution-trigger','wire:click' => 'toggleDistribution','wire:loading.attr' => 'disabled','wire:target' => 'toggleDistribution','ariaPressed' => ''.e($distributionOpen ? 'true' : 'false').'','ariaControls' => 'shift-distribution-side-'.e($this->getId()).'','ariaLabel' => 'Noch zu verteilen: '.e($pendingShifts->total()).' Schichten und '.e($unplannedOrders->total()).' Leistungen · Seitenleiste '.e($distributionOpen ? 'schließen' : 'öffnen').'','title' => 'Noch zu verteilen im gewählten Zeitraum']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-control rt-shift-plan-distribution-trigger','wire:click' => 'toggleDistribution','wire:loading.attr' => 'disabled','wire:target' => 'toggleDistribution','aria-pressed' => ''.e($distributionOpen ? 'true' : 'false').'','aria-controls' => 'shift-distribution-side-'.e($this->getId()).'','aria-label' => 'Noch zu verteilen: '.e($pendingShifts->total()).' Schichten und '.e($unplannedOrders->total()).' Leistungen · Seitenleiste '.e($distributionOpen ? 'schließen' : 'öffnen').'','title' => 'Noch zu verteilen im gewählten Zeitraum']); ?>
                        <span class="rt-shift-plan-distribution-counters" aria-hidden="true">
                            <span class="rt-shift-plan-distribution-count" data-distribution-count="shifts" data-has-pending="<?php echo e($pendingShifts->total() > 0 ? 'true' : 'false'); ?>"><i class="far fa-clock" aria-hidden="true"></i><strong><?php echo e($pendingShifts->total()); ?></strong></span>
                            <span class="rt-shift-plan-distribution-count" data-distribution-count="orders" data-has-pending="<?php echo e($unplannedOrders->total() > 0 ? 'true' : 'false'); ?>"><i class="far fa-briefcase" aria-hidden="true"></i><strong><?php echo e($unplannedOrders->total()); ?></strong></span>
                        </span>
                        <span class="rt-shift-plan-distribution-count rt-shift-plan-distribution-total" data-distribution-count="total" data-has-pending="<?php echo e($pendingTotal > 0 ? 'true' : 'false'); ?>" aria-hidden="true"><i class="far <?php echo e($pendingShifts->total() > 0 || $pendingTotal === 0 ? 'fa-clock' : 'fa-briefcase'); ?>" aria-hidden="true"></i><strong><?php echo e($pendingTotal > 99 ? '99+' : $pendingTotal); ?></strong></span>
                <i class="far fa-columns rt-shift-plan-control__chevron" aria-hidden="true"></i>
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
                <?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['align' => 'right','width' => '64','offset' => '6','dropdownId' => 'shift-plan-actions-'.e($this->getId()).'','layerGroup' => 'operations-shift-plan','contentLabel' => 'Schichtplan-Optionen','contentClasses' => 'rt-shift-plan-view-menu p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['align' => 'right','width' => '64','offset' => '6','dropdown-id' => 'shift-plan-actions-'.e($this->getId()).'','layer-group' => 'operations-shift-plan','content-label' => 'Schichtplan-Optionen','content-classes' => 'rt-shift-plan-view-menu p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
                     <?php $__env->slot('trigger', null, []); ?> 
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-options-trigger','ariaLabel' => 'Schichtplan-Optionen','title' => 'Weitere Optionen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','class' => 'rt-shift-plan-options-trigger','aria-label' => 'Schichtplan-Optionen','title' => 'Weitere Optionen']); ?><i class="far fa-ellipsis-vertical" aria-hidden="true"></i> <?php echo $__env->renderComponent(); ?>
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
                        <p class="rt-shift-plan-menu-heading" role="presentation">Planung organisieren</p>
                        <button type="button" role="menuitem" class="rt-shift-plan-view-option rt-shift-plan-compact-create" wire:click="createShift" x-on:click="close()"><i class="far fa-plus" aria-hidden="true"></i><span><strong>Neue Schicht</strong></span></button>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Operations\PlanningSchema::ready()): ?>
                            <button type="button" role="menuitem" class="rt-shift-plan-view-option" x-on:click="$dispatch('open-shift-series-planner'); close()"><i class="far fa-repeat" aria-hidden="true"></i><span><strong>Vorlagen &amp; Serien</strong><small>Wiederkehrende Schichten planen</small></span></button>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                            <button type="button" role="menuitem" class="rt-shift-plan-view-option" aria-label="AI-Besetzung für den Zeitraum" x-on:click="$dispatch('open-ai-period-planning'); close()"><i class="far fa-sparkles" aria-hidden="true"></i><span><strong>AI-Besetzung</strong><small>Vorschlag für den Zeitraum erstellen</small></span></button>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php
                            $planHelp = app(\App\Support\PageHelpCatalog::class)->forRoute(request()->route()?->getName(), 'Schichtplan');
                            $planHelpPayload = ['title' => $planHelp['title'] ?? 'Schichtplan', 'summary' => $planHelp['summary'] ?? null, 'points' => array_values($planHelp['points'] ?? [])];
                        ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($planHelp): ?>
                            <button type="button" role="menuitem" class="rt-shift-plan-view-option rt-shift-plan-compact-action" x-on:click="close(); $dispatch('rt-info:open', <?php echo e(\Illuminate\Support\Js::from($planHelpPayload)); ?>)"><i class="far fa-info-circle" aria-hidden="true"></i><span><strong>Seitenhilfe</strong></span></button>
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
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'orders'): ?>
                    <?php echo $__env->make('livewire.admin.operations.partials.service-filter-trigger', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
    </template>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.ai-planning-helper', ['from' => $rangeFrom,'until' => $rangeTo]);

$__key = 'ai-planning-period-'.$rangeFrom.'-'.$rangeTo;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3336335058-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Operations\PlanningSchema::ready()): ?>
        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.shift-series-planner', ['showTrigger' => false]);

$__key = null;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3336335058-1', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div class="rt-shift-plan-split" data-distribution-open="<?php echo e($distributionOpen ? 'true' : 'false'); ?>" data-view="<?php echo e($viewMode); ?>">
    <div class="rt-shift-plan-split__main">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode !== 'timeline' && $viewMode !== 'orders'): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($contextCustomer): ?><p class="ops-muted" role="status" data-shift-customer-context>Kunde: <?php echo e($contextCustomer->company_name); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if (isset($component)) { $__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.toolbar','data' => ['title' => 'Filter','id' => 'operations-shift-management-filters','filterCount' => (int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.toolbar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Filter','id' => 'operations-shift-management-filters','filter-count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute((int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all'))]); ?>
        <details class="rt-shift-filters" x-bind:open="!desktopFilters">
            <summary class="rt-shift-filters__trigger" aria-label="Schichtfilter öffnen" title="Schichtfilter">
                <i class="far fa-sliders" aria-hidden="true"></i><span>Filter</span>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all') > 0): ?><span class="rt-shift-filters__count"><?php echo e((int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all')); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <i class="far fa-chevron-down rt-shift-filters__chevron" aria-hidden="true"></i>
            </summary>
            <div class="rt-shift-filters__panel">
                <?php echo $__env->make('livewire.admin.operations.partials.shift-filter-fields', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </details>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc)): ?>
<?php $attributes = $__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc; ?>
<?php unset($__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc)): ?>
<?php $component = $__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc; ?>
<?php unset($__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php
        $shiftColumns = [
            ['label' => 'Schicht', 'key' => 'shift', 'width' => 'minmax(0,1.5fr)', 'sortable' => true],
            ['label' => 'Kunde / Ort', 'key' => 'customer', 'width' => 'minmax(0,1.2fr)', 'sortable' => true],
            ['label' => 'Zeitfenster', 'key' => 'schedule', 'width' => 'minmax(0,1.3fr)', 'sortable' => true],
            ['label' => 'Besetzung', 'key' => 'staffing', 'width' => 'minmax(0,1.1fr)', 'sortable' => true],
            ['label' => 'Planstatus', 'key' => 'status', 'width' => 'minmax(0,1fr)', 'sortable' => true],
        ];
    ?>
    <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['space-y-6', 'rt-disposition-board' => $viewMode === 'staffing']); ?>" data-shift-view="<?php echo e($viewMode); ?>" wire:loading.class="opacity-60" wire:target="tableSort,sortServices,servicePerPage,gotoPage,nextPage,previousPage,setView,search,rangeFrom,rangeTo,orderFilter,statusFilter,attentionFilter,movePeriod,currentWeek">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'timeline'): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.staff-timeline', ['from' => $rangeFrom,'until' => $rangeTo,'periodNavigationOwner' => $this->getId(),'attentionCustomerId' => $customerFilter === 'all' ? null : (int) $customerFilter,'searchInHeader' => true,'planningEnabled' => true,'showSuggestions' => $distributionOpen,'focusShiftId' => $distributionOpen ? $distributionShiftId : null]);

$__key = 'shift-plan-staff-timeline';

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3336335058-2', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
            <?php else: ?>
                <div class="rt-shift-plan-unavailable" role="status"><i class="far fa-clock" aria-hidden="true"></i><span>Die Zeitleiste ist verfügbar, sobald der Mitarbeiter- und Abwesenheitsbereich eingerichtet ist.</span></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php elseif($viewMode === 'orders'): ?>
            <?php echo $__env->make('livewire.admin.operations.partials.service-overview', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php elseif($viewMode === 'map'): ?>
            <?php echo $__env->make('livewire.admin.operations.partials.shift-plan-map', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <?php elseif($viewMode === 'table' || $shifts->isEmpty()): ?>
            <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => $shiftColumns,'sortAction' => 'tableSort','sortBy' => $sortBy,'sortDir' => $sortDir,'tableKey' => 'shift-plan','class' => 'rt-shift-plan-table','flushTop' => true,'items' => $shifts,'selectedItems' => [$selectedShiftId],'selectionAction' => 'selectShift','detailEvent' => 'operations-shift-detail-request','rowView' => 'components.tables.rows.operations.shift-plan','empty' => 'Keine Schichten für diese Filter gefunden.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shiftColumns),'sort-action' => 'tableSort','sort-by' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortBy),'sort-dir' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortDir),'table-key' => 'shift-plan','class' => 'rt-shift-plan-table','flush-top' => true,'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shifts),'selected-items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([$selectedShiftId]),'selection-action' => 'selectShift','detail-event' => 'operations-shift-detail-request','row-view' => 'components.tables.rows.operations.shift-plan','empty' => 'Keine Schichten für diese Filter gefunden.']); ?>
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
        <?php else: ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = match($viewMode) { 'day' => $dailyGroups, 'orders' => $orderGroups, default => $staffingGroups }; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $groupKey => $group): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($group['items']->isNotEmpty()): ?>
                    <section class="rt-disposition-panel min-w-0" wire:key="shift-group-<?php echo e($viewMode); ?>-<?php echo e($groupKey); ?>" aria-labelledby="shift-group-<?php echo e($viewMode); ?>-<?php echo e($groupKey); ?>" data-shift-group="<?php echo e($groupKey); ?>">
                        <div class="rt-shift-plan-group">
                            <div class="min-w-0">
                                <h2 id="shift-group-<?php echo e($viewMode); ?>-<?php echo e($groupKey); ?>" class="break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text"><?php echo e($group['label']); ?></h2>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'orders'): ?><p class="mt-1 break-words text-xs text-rt-muted dark:text-rt-dark-muted"><?php echo e($group['customer']); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <span class="text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted"><?php echo e($group['items']->count()); ?> <?php echo e($group['items']->count() === 1 ? 'Schicht' : 'Schichten'); ?></span>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'orders'): ?>
                                <p class="w-full text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">Aktiv: <?php echo e($group['summary']['required']); ?> Einsatzplätze · <?php echo e($group['summary']['reserved']); ?> eingeplant · <?php echo e($group['summary']['confirmed']); ?> bestätigt · <?php echo e($group['summary']['open']); ?> offen</p>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(in_array($viewMode, ['day', 'staffing'], true)): ?>
                            <div class="rt-disposition-shift-list">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $group['items']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $shift): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php if (isset($component)) { $__componentOriginal2a8875d64dd0cf5d7150fe3e75a28da2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2a8875d64dd0cf5d7150fe3e75a28da2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.shift-card','data' => ['shift' => $shift,'timezone' => $displayTimezone,'compact' => $viewMode === 'staffing','wire:key' => 'shift-card-'.e($viewMode).'-'.e($groupKey).'-'.e($shift->id).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.shift-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['shift' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shift),'timezone' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($displayTimezone),'compact' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($viewMode === 'staffing'),'wire:key' => 'shift-card-'.e($viewMode).'-'.e($groupKey).'-'.e($shift->id).'']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2a8875d64dd0cf5d7150fe3e75a28da2)): ?>
<?php $attributes = $__attributesOriginal2a8875d64dd0cf5d7150fe3e75a28da2; ?>
<?php unset($__attributesOriginal2a8875d64dd0cf5d7150fe3e75a28da2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2a8875d64dd0cf5d7150fe3e75a28da2)): ?>
<?php $component = $__componentOriginal2a8875d64dd0cf5d7150fe3e75a28da2; ?>
<?php unset($__componentOriginal2a8875d64dd0cf5d7150fe3e75a28da2); ?>
<?php endif; ?>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => $shiftColumns,'sortAction' => 'tableSort','sortBy' => $sortBy,'sortDir' => $sortDir,'tableKey' => 'shift-order-'.$groupKey,'flushTop' => true,'items' => $group['items'],'selectedItems' => [$selectedShiftId],'selectionAction' => 'selectShift','detailEvent' => 'operations-shift-detail-request','rowView' => 'components.tables.rows.operations.shift-plan']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shiftColumns),'sort-action' => 'tableSort','sort-by' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortBy),'sort-dir' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortDir),'table-key' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('shift-order-'.$groupKey),'flush-top' => true,'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($group['items']),'selected-items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([$selectedShiftId]),'selection-action' => 'selectShift','detail-event' => 'operations-shift-detail-request','row-view' => 'components.tables.rows.operations.shift-plan']); ?>
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
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </section>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'orders'): ?>
        <div class="rt-service-pagination" data-service-pagination aria-label="Leistungsseiten">
            <?php if (isset($component)) { $__componentOriginal7db1307087545d03fcb5b0947d4de73b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7db1307087545d03fcb5b0947d4de73b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.pagination','data' => ['paginator' => $serviceGroups,'label' => 'Leistungsseiten','tableKey' => 'service-overview']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.pagination'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['paginator' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($serviceGroups),'label' => 'Leistungsseiten','table-key' => 'service-overview']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7db1307087545d03fcb5b0947d4de73b)): ?>
<?php $attributes = $__attributesOriginal7db1307087545d03fcb5b0947d4de73b; ?>
<?php unset($__attributesOriginal7db1307087545d03fcb5b0947d4de73b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7db1307087545d03fcb5b0947d4de73b)): ?>
<?php $component = $__componentOriginal7db1307087545d03fcb5b0947d4de73b; ?>
<?php unset($__componentOriginal7db1307087545d03fcb5b0947d4de73b); ?>
<?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($distributionOpen): ?>
        <aside class="rt-shift-distribution-side" id="shift-distribution-side-<?php echo e($this->getId()); ?>" aria-label="Noch zu verteilen" wire:key="shift-distribution-side">
            <?php echo $__env->make('livewire.admin.operations.partials.pending-distribution', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        </aside>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <?php
        $shiftPanelId = 'shift-plan-detail-'.$this->getId();
        $detailIdPrefix = 'shift-detail-'.$this->getId();
        $formIdPrefix = 'shift-form-'.$this->getId();
        if ($selectedShift) {
            $selectedShiftStatus = $selectedShift->status instanceof \BackedEnum ? $selectedShift->status->value : (string) $selectedShift->status;
            $selectedAssignments = $selectedShift->assignments->filter(fn ($assignment) => in_array(
                $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                ['requested', 'confirmed'],
                true,
            ));
            $selectedReservedCount = $selectedAssignments->count();
            $shiftStart = $selectedShift->starts_at?->setTimezone($displayTimezone)->locale('de');
            $shiftEnd = $selectedShift->ends_at?->setTimezone($displayTimezone)->locale('de');
            $shiftWindow = $shiftStart && $shiftEnd
                ? ($shiftStart->isSameDay($shiftEnd)
                    ? $shiftStart->isoFormat('dd, DD.MM.').' · '.$shiftStart->format('H:i').'–'.$shiftEnd->format('H:i')
                    : $shiftStart->isoFormat('dd, DD.MM. HH:mm').' – '.$shiftEnd->isoFormat('dd, DD.MM. HH:mm'))
                : null;
            $shiftLocation = $selectedShift->location_name ?: $selectedShift->order?->location_name;
            $shiftClosed = in_array($selectedShiftStatus, ['cancelled', 'completed'], true);
            $shiftPublished = (int) $selectedShift->published_revision === (int) $selectedShift->revision;
            $canPublish = $nativeOperations && ! $shiftPublished && ! $shiftClosed;
            $detailTabs = [
                'overview' => ['label' => 'Übersicht', 'alert' => $canPublish],
                'staffing' => ['label' => $nativeOperations ? 'Besetzung & Rückmeldungen' : 'Besetzung', 'count' => $selectedReservedCount.'/'.$selectedShift->required_staff],
            ];
            if ($nativeOperations) {
                if (\App\Support\Operations\PlanningSchema::ready()) {
                    $detailTabs['activity'] = ['label' => 'Aktivität'];
                }
            }
        }
    ?>
    
    <?php if (isset($component)) { $__componentOriginalee5b1959550127dcd3c5272f16dd7b79 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalee5b1959550127dcd3c5272f16dd7b79 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel','data' => ['id' => $shiftPanelId,'showExpression' => 'detailVisible','label' => $formOpen ? ($editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht anlegen') : 'Schichtdetails']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shiftPanelId),'show-expression' => 'detailVisible','label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($formOpen ? ($editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht anlegen') : 'Schichtdetails')]); ?>
        
        
        <div class="rt-ops-panel__mode" data-panel-mode="detail" <?php if($formOpen): ?> hidden <?php endif; ?>
            x-bind:hidden="$wire.formOpen || (!$wire.detailOpen && !loading && !error)"
            x-data="{ tab: 'overview' }" wire:key="shift-panel-detail-<?php echo e($selectedShiftId ?? 'none'); ?>">
            <div class="rt-ops-panel__accent" aria-hidden="true"></div>

            <header class="rt-ops-panel__header" x-show="loading || error" style="display: none" data-shift-detail-placeholder>
                <span class="rt-ops-panel__icon" aria-hidden="true"><i class="far fa-clock"></i></span>
                <div class="rt-ops-panel__heading" aria-hidden="true">
                    <span class="rt-ops-panel__eyebrow">Schichtdetails</span>
                    <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--title"></span>
                    <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--line"></span>
                </div>
                <div class="rt-ops-panel__actions">
                    <button type="button" class="rt-ops-panel__icon-button" x-on:click="$dispatch('close')" aria-label="Schichtdetails schließen" title="Schichtdetails schließen"><i class="far fa-xmark" aria-hidden="true"></i></button>
                </div>
            </header>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift): ?>
                <?php if (isset($component)) { $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.header','data' => ['xShow' => '!loading && !error','eyebrow' => 'Schichtdetails','title' => $selectedShift->title,'subtitle' => collect([$selectedShift->order?->order_number, $selectedShift->order?->title, $selectedShift->order?->customer?->company_name])->filter()->implode(' · '),'icon' => 'fa-clock','closeLabel' => 'Schichtdetails schließen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['x-show' => '!loading && !error','eyebrow' => 'Schichtdetails','title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedShift->title),'subtitle' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect([$selectedShift->order?->order_number, $selectedShift->order?->title, $selectedShift->order?->customer?->company_name])->filter()->implode(' · ')),'icon' => 'fa-clock','close-label' => 'Schichtdetails schließen']); ?>
                     <?php $__env->slot('actions', null, []); ?> 
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','wire:click' => 'editShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled','wire:target' => 'editShift','dataShiftDetailEdit' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','wire:click' => 'editShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled','wire:target' => 'editShift','data-shift-detail-edit' => true]); ?>
                            <i wire:loading.remove wire:target="editShift" class="far fa-pen" aria-hidden="true"></i>
                            <i wire:loading wire:target="editShift" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                            Bearbeiten
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
                     <?php $__env->slot('meta', null, []); ?> 
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($selectedShift->role_name)): ?>
                            <span class="rt-ops-panel__fact"><i class="far fa-id-badge" aria-hidden="true"></i><?php echo e($selectedShift->role_name); ?></span>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shiftWindow): ?>
                            <span class="rt-ops-panel__fact rt-ops-panel__fact--strong"><i class="far fa-calendar" aria-hidden="true"></i><time datetime="<?php echo e($shiftStart->toIso8601String()); ?>"><?php echo e($shiftWindow); ?></time></span>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($shiftLocation)): ?>
                            <span class="rt-ops-panel__fact"><i class="far fa-map-marker-alt" aria-hidden="true"></i><?php echo e($shiftLocation); ?></span>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <span class="rt-ops-panel__meta-end">
                            <?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9140b74635d19149dc471db3f05482a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.status','data' => ['value' => $selectedShiftStatus,'label' => $selectedShift->status->label()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedShiftStatus),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedShift->status->label())]); ?>
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
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($shiftClosed)): ?>
                                <span class="rt-ops-panel__pill" data-tone="<?php echo e($selectedReservedCount >= $selectedShift->required_staff ? 'success' : 'critical'); ?>"><?php echo e($selectedReservedCount); ?>/<?php echo e($selectedShift->required_staff); ?> eingeplant</span>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </span>
                     <?php $__env->endSlot(); ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $attributes = $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $component = $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginal6714da93c59fa1f131790fd720f4f95e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6714da93c59fa1f131790fd720f4f95e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tabs','data' => ['xShow' => '!loading && !error','tabs' => $detailTabs,'label' => 'Bereiche der Schichtdetails','idPrefix' => $detailIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tabs'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['x-show' => '!loading && !error','tabs' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailTabs),'label' => 'Bereiche der Schichtdetails','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailIdPrefix)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6714da93c59fa1f131790fd720f4f95e)): ?>
<?php $attributes = $__attributesOriginal6714da93c59fa1f131790fd720f4f95e; ?>
<?php unset($__attributesOriginal6714da93c59fa1f131790fd720f4f95e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6714da93c59fa1f131790fd720f4f95e)): ?>
<?php $component = $__componentOriginal6714da93c59fa1f131790fd720f4f95e; ?>
<?php unset($__componentOriginal6714da93c59fa1f131790fd720f4f95e); ?>
<?php endif; ?>
            <?php else: ?>
                <?php if (isset($component)) { $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.header','data' => ['xShow' => '!loading && !error','eyebrow' => 'Schichtdetails','title' => 'Keine Schicht ausgewählt','icon' => 'fa-clock','closeLabel' => 'Schichtdetails schließen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['x-show' => '!loading && !error','eyebrow' => 'Schichtdetails','title' => 'Keine Schicht ausgewählt','icon' => 'fa-clock','close-label' => 'Schichtdetails schließen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $attributes = $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $component = $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="rt-ops-panel__body rt-modal-content" data-shift-detail-body>
                <div class="rt-ops-panel__feedback"><?php if (isset($component)) { $__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5 = $component; } ?>
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
<?php endif; ?></div>
                <div x-show="loading" x-cloak data-shift-detail-loading>
                    <p class="mb-4 flex items-center gap-2 text-sm text-rt-muted dark:text-rt-dark-muted" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i>Schichtdetails werden geladen …</p>
                    <?php if (isset($component)) { $__componentOriginal24e17cb66881c3321b8b712021de0468 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal24e17cb66881c3321b8b712021de0468 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.loading.skeleton','data' => ['variant' => 'list','rows' => 4]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.loading.skeleton'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'list','rows' => 4]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal24e17cb66881c3321b8b712021de0468)): ?>
<?php $attributes = $__attributesOriginal24e17cb66881c3321b8b712021de0468; ?>
<?php unset($__attributesOriginal24e17cb66881c3321b8b712021de0468); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal24e17cb66881c3321b8b712021de0468)): ?>
<?php $component = $__componentOriginal24e17cb66881c3321b8b712021de0468; ?>
<?php unset($__componentOriginal24e17cb66881c3321b8b712021de0468); ?>
<?php endif; ?>
                </div>
                <div x-show="!loading && error" x-cloak class="space-y-4 py-6" data-shift-detail-error>
                    <p class="text-sm text-rt-muted dark:text-rt-dark-muted" x-text="error" role="alert"></p>
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','xOn:click' => 'openShiftDetail(requestedShiftId)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','x-on:click' => 'openShiftDetail(requestedShiftId)']); ?><i class="far fa-arrow-rotate-right" aria-hidden="true"></i>Erneut versuchen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                </div>
                <div x-show="!loading && !error" x-bind:aria-busy="loading" data-shift-detail-content>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift): ?>
                        <?php if (isset($component)) { $__componentOriginalaccc004e5db5e86b6167da033f6be736 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaccc004e5db5e86b6167da033f6be736 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'overview','idPrefix' => $detailIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'overview','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailIdPrefix)]); ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canPublish): ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['tone' => 'warning','dataShiftPublishNotice' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['tone' => 'warning','data-shift-publish-notice' => true]); ?>
                                    <div class="rt-ops-panel__notice">
                                        <i class="far fa-exclamation-triangle" aria-hidden="true"></i>
                                        <div class="rt-ops-panel__notice-text">
                                            <strong>Unveröffentlichte Änderungen</strong>
                                            <span>Revision <?php echo e($selectedShift->revision); ?> · <?php echo e($selectedShift->published_revision ? 'veröffentlicht ist Revision '.$selectedShift->published_revision : 'noch nie veröffentlicht'); ?></span>
                                        </div>
                                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'primary','wire:click' => 'publish('.e($selectedShift->id).','.e($selectedShift->revision).')','wire:confirm' => 'Diesen Dienst veröffentlichen und Bestätigungen anfordern?','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'primary','wire:click' => 'publish('.e($selectedShift->id).','.e($selectedShift->revision).')','wire:confirm' => 'Diesen Dienst veröffentlichen und Bestätigungen anfordern?','wire:loading.attr' => 'disabled']); ?>Dienst veröffentlichen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                                    </div>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations && count($planChanges) && ! $shiftPublished): ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => $selectedShift->published_revision ? 'Änderungen zur Veröffentlichung' : 'Erste Veröffentlichung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedShift->published_revision ? 'Änderungen zur Veröffentlichung' : 'Erste Veröffentlichung')]); ?>
                                    <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Neu','key'=>'after']],'items' => collect($planChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key])),'rowView' => 'components.tables.rows.operations.plan-change']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Neu','key'=>'after']]),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect($planChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key]))),'row-view' => 'components.tables.rows.operations.plan-change']); ?>
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
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen): ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Auftrag / Leistung','dataShiftParentContext' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Auftrag / Leistung','data-shift-parent-context' => true]); ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift->order): ?>
                                        <dl class="rt-ops-panel__rows">
                                            <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Leistung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Leistung']); ?><?php echo e($selectedShift->order->title); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                            <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Auftragsnummer']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Auftragsnummer']); ?><?php echo e($selectedShift->order->order_number ?: '—'); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                            <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Kunde']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Kunde']); ?><?php echo e($selectedShift->order->customer?->company_name ?: '—'); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                            <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Schichten']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Schichten']); ?><span class="rt-ops-panel__num"><?php echo e($selectedShift->order->shifts_count); ?></span> <?php echo e((int) $selectedShift->order->shifts_count === 1 ? 'Schicht insgesamt' : 'Schichten insgesamt'); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                        </dl>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                                            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('operations.manage')): ?>
                                                <div class="mt-3 flex flex-wrap gap-2">
                                                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','size' => 'sm','href' => \App\Support\Operations\OperationsPages::url('cases', ['view' => 'orders', 'section' => 'overview', 'order' => $selectedShift->order->id]),'dataShiftParentOrderLink' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','size' => 'sm','href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(\App\Support\Operations\OperationsPages::url('cases', ['view' => 'orders', 'section' => 'overview', 'order' => $selectedShift->order->id])),'data-shift-parent-order-link' => true]); ?><i class="far fa-briefcase" aria-hidden="true"></i>Auftrag öffnen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                                                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','size' => 'sm','href' => \App\Support\Operations\OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'order' => $selectedShift->order->id]),'dataShiftParentShiftsLink' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','size' => 'sm','href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(\App\Support\Operations\OperationsPages::url('cases', ['view' => 'shifts', 'section' => 'plan', 'order' => $selectedShift->order->id])),'data-shift-parent-shifts-link' => true]); ?><i class="far fa-clock" aria-hidden="true"></i>Schichten dieser Leistung <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php else: ?>
                                        <p class="rt-ops-panel__text">Zugehöriger Auftrag nicht verfügbar.</p>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen): ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Zeit & Ort','dataShiftDetailPreviews' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Zeit & Ort','data-shift-detail-previews' => true]); ?>
                                    <div class="rt-ops-panel__previews">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shiftStart && $shiftEnd): ?>
                                            <?php if (isset($component)) { $__componentOriginala73e6852b913424e697d8dc395df0e76 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala73e6852b913424e697d8dc395df0e76 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.timeline-mini-calendar','data' => ['start' => $shiftStart,'end' => $shiftEnd]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.timeline-mini-calendar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['start' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shiftStart),'end' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shiftEnd)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala73e6852b913424e697d8dc395df0e76)): ?>
<?php $attributes = $__attributesOriginala73e6852b913424e697d8dc395df0e76; ?>
<?php unset($__attributesOriginala73e6852b913424e697d8dc395df0e76); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala73e6852b913424e697d8dc395df0e76)): ?>
<?php $component = $__componentOriginala73e6852b913424e697d8dc395df0e76; ?>
<?php unset($__componentOriginala73e6852b913424e697d8dc395df0e76); ?>
<?php endif; ?>
                                        <?php else: ?>
                                            <p class="rt-ops-panel__text">Kein vollständiger Zeitraum hinterlegt.</p>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <?php if (isset($component)) { $__componentOriginal8e63117d4f57364d8602eb85edbb579c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8e63117d4f57364d8602eb85edbb579c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.timeline-mini-map','data' => ['preview' => \App\Support\Operations\TimelineLocationPreview::fromShift($selectedShift),'location' => $shiftLocation]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.timeline-mini-map'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['preview' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(\App\Support\Operations\TimelineLocationPreview::fromShift($selectedShift)),'location' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shiftLocation)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8e63117d4f57364d8602eb85edbb579c)): ?>
<?php $attributes = $__attributesOriginal8e63117d4f57364d8602eb85edbb579c; ?>
<?php unset($__attributesOriginal8e63117d4f57364d8602eb85edbb579c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8e63117d4f57364d8602eb85edbb579c)): ?>
<?php $component = $__componentOriginal8e63117d4f57364d8602eb85edbb579c; ?>
<?php unset($__componentOriginal8e63117d4f57364d8602eb85edbb579c); ?>
<?php endif; ?>
                                    </div>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Einsatz']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Einsatz']); ?>
                                <dl class="rt-ops-panel__rows">
                                    <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Tätigkeit']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Tätigkeit']); ?><?php echo e($selectedShift->role_name ?: '—'); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Einsatzort']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Einsatzort']); ?><?php echo e($shiftLocation ?: 'Kein Einsatzort hinterlegt'); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Zeitfenster']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Zeitfenster']); ?>
                                        <span class="rt-ops-panel__num"><?php echo e($shiftStart?->format('d.m.Y H:i')); ?> – <?php echo e($shiftEnd?->format('d.m.Y H:i')); ?></span>
                                        <small><?php echo e($displayTimezone); ?></small>
                                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                                        <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Geplante Pause']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Geplante Pause']); ?><span class="rt-ops-panel__num"><?php echo e((int) $selectedShift->planned_break_minutes); ?> Minuten</span> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Besetzung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Besetzung']); ?><span class="rt-ops-panel__num"><?php echo e($selectedReservedCount); ?> von <?php echo e($selectedShift->required_staff); ?></span> eingeplant <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                                        <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Nachweise']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Nachweise']); ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift->qualifications->isNotEmpty()): ?>
                                                <span class="rt-ops-panel__chips"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $selectedShift->qualifications; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $qualification): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><span class="ops-badge"><?php echo e($qualification->name); ?></span><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                                            <?php else: ?>
                                                Keine erforderlich
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $attributes = $__attributesOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__attributesOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal52e3918d674993c8394b253eb99dee6c)): ?>
<?php $component = $__componentOriginal52e3918d674993c8394b253eb99dee6c; ?>
<?php unset($__componentOriginal52e3918d674993c8394b253eb99dee6c); ?>
<?php endif; ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </dl>
                             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Services\Operations\SiteSnapshotService::ready()): ?>
                                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.routing-site-editor', ['subjectType' => 'shift','subjectId' => $selectedShift->id]);

$__key = 'routing-shift-'.$selectedShift->id.'-'.$selectedShift->revision;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3336335058-3', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($selectedShift->notes)): ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Interne Notizen','meta' => 'nur intern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Interne Notizen','meta' => 'nur intern']); ?>
                                    <p class="rt-ops-panel__text"><?php echo e($selectedShift->notes); ?></p>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations && count($planChanges) && $shiftPublished): ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Zuletzt veröffentlichte Änderungen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Zuletzt veröffentlichte Änderungen']); ?>
                                    <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Neu','key'=>'after']],'items' => collect($planChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key])),'rowView' => 'components.tables.rows.operations.plan-change']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Neu','key'=>'after']]),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect($planChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key]))),'row-view' => 'components.tables.rows.operations.plan-change']); ?>
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
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $attributes = $__attributesOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__attributesOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $component = $__componentOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__componentOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>

                        <?php if (isset($component)) { $__componentOriginalaccc004e5db5e86b6167da033f6be736 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaccc004e5db5e86b6167da033f6be736 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'staffing','idPrefix' => $detailIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'staffing','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailIdPrefix)]); ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                                <?php echo $__env->make('livewire.operations.partials.shift-staffing', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <?php else: ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Eingeteilte Mitarbeitende','meta' => $selectedAssignments->count().' Zuweisungen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Eingeteilte Mitarbeitende','meta' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedAssignments->count().' Zuweisungen')]); ?>
                                    <div class="rt-ops-panel__list">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $selectedAssignments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $assignment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                            <?php $assignmentValue = $assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status; ?>
                                            <div class="flex min-h-16 items-center gap-3 px-3.5 py-2.5" wire:key="shift-assignment-<?php echo e($assignment->id); ?>">
                                                <div class="min-w-0 flex-1">
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($assignment->user): ?><?php if (isset($component)) { $__componentOriginalc07b0998277cb6d4345f54b3047a566a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc07b0998277cb6d4345f54b3047a566a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.person-anchor-preview','data' => ['user' => $assignment->user,'showPresence' => false,'showEmail' => false,'size' => 9]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.person-anchor-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($assignment->user),'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'show-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'size' => 9]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc07b0998277cb6d4345f54b3047a566a)): ?>
<?php $attributes = $__attributesOriginalc07b0998277cb6d4345f54b3047a566a; ?>
<?php unset($__attributesOriginalc07b0998277cb6d4345f54b3047a566a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc07b0998277cb6d4345f54b3047a566a)): ?>
<?php $component = $__componentOriginalc07b0998277cb6d4345f54b3047a566a; ?>
<?php unset($__componentOriginalc07b0998277cb6d4345f54b3047a566a); ?>
<?php endif; ?><?php else: ?><span class="ops-muted">Unbekannter Mitarbeiter</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                                    <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted"><?php echo e(method_exists($assignment->status, 'label') ? $assignment->status->label() : \Illuminate\Support\Str::headline($assignmentValue)); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($assignment->note): ?> · <?php echo e($assignment->note); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></p>
                                                </div>
                                                <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','wire:click' => 'removeAssignment('.e($assignment->id).')','wire:confirm' => 'Zuweisung wirklich entfernen?','wire:loading.attr' => 'disabled','class' => 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-rt-red disabled:opacity-60 dark:text-rt-dark-muted dark:hover:bg-red-500/10','ariaLabel' => ''.e($assignment->user?->name).' aus der Schicht entfernen','title' => 'Entfernen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','wire:click' => 'removeAssignment('.e($assignment->id).')','wire:confirm' => 'Zuweisung wirklich entfernen?','wire:loading.attr' => 'disabled','class' => 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-rt-red disabled:opacity-60 dark:text-rt-dark-muted dark:hover:bg-red-500/10','aria-label' => ''.e($assignment->user?->name).' aus der Schicht entfernen','title' => 'Entfernen']); ?>
                                                    <i class="far fa-times" aria-hidden="true"></i>
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
                                            </div>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                            <p class="px-4 py-7 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch niemand eingeteilt. Weise unten den ersten Mitarbeiter zu.</p>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShiftStatus === 'cancelled'): ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Schicht storniert']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Schicht storniert']); ?>
                                    <p class="rt-ops-panel__text">Für eine stornierte Schicht können keine weiteren Mitarbeitenden reserviert werden.</p>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php else: ?>
                                <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Mitarbeiter zuweisen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Mitarbeiter zuweisen']); ?>
                                    <div class="space-y-3">
                                        <div class="rt-ops-panel__fields">
                                            <div>
                                                <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'assignment-employee','value' => 'Mitarbeiter']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'assignment-employee','value' => 'Mitarbeiter']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'assignment-employee','wire:model' => 'employeeId','class' => 'mt-1','placeholder' => 'Mitarbeiter auswählen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'assignment-employee','wire:model' => 'employeeId','class' => 'mt-1','placeholder' => 'Mitarbeiter auswählen']); ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $employees; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $employee): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($employee->id); ?>"><?php echo e($employee->name); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($employee->profile?->position): ?> · <?php echo e($employee->profile->position); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['employeeId'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </div>
                                            <div>
                                                <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'assignment-status','value' => 'Zuweisungsstatus']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'assignment-status','value' => 'Zuweisungsstatus']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'assignment-status','wire:model' => 'assignmentStatus','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'assignment-status','wire:model' => 'assignmentStatus','class' => 'mt-1']); ?>
                                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $assignmentStatusOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['assignmentStatus'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </div>
                                            <div class="rt-ops-panel__field--wide">
                                                <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'assignment-note','value' => 'Hinweis (optional)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'assignment-note','value' => 'Hinweis (optional)']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                                <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'assignment-note','wire:model' => 'assignmentNote','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'assignment-note','wire:model' => 'assignmentNote','class' => 'mt-1']); ?>
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
                                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['assignmentNote'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['assignment'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'primary','wire:click' => 'assignEmployee','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'primary','wire:click' => 'assignEmployee','wire:loading.attr' => 'disabled']); ?>
                                            <i wire:loading.remove wire:target="assignEmployee" class="far fa-user-plus" aria-hidden="true"></i>
                                            <i wire:loading wire:target="assignEmployee" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                                            Zuweisen
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
                                    </div>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $attributes = $__attributesOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__attributesOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $component = $__componentOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__componentOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>

                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Operations\PlanningSchema::ready()): ?>
                                <?php if (isset($component)) { $__componentOriginalaccc004e5db5e86b6167da033f6be736 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaccc004e5db5e86b6167da033f6be736 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'activity','idPrefix' => $detailIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'activity','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailIdPrefix)]); ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen): ?><?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.duty-activity', ['shiftId' => $selectedShift->id]);

$__key = 'duty-'.$selectedShift->id;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3336335058-4', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $attributes = $__attributesOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__attributesOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $component = $__componentOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__componentOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php else: ?>
                        <div class="flex min-h-80 flex-col items-center justify-center text-center">
                            <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                            <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Schicht auswählen</h2>
                            <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle eine Schicht aus, um die Besetzung zu planen.</p>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            <?php if (isset($component)) { $__componentOriginal729721974c9d27ec13799a7e5ca1e565 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal729721974c9d27ec13799a7e5ca1e565 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.footer','data' => ['xShow' => '!loading && !error']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.footer'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['x-show' => '!loading && !error']); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift && $nativeOperations): ?>
                     <?php $__env->slot('note', null, []); ?> <i class="far fa-code-branch" aria-hidden="true"></i>Revision <?php echo e($selectedShift->revision); ?> · <?php echo e($shiftPublished ? 'veröffentlicht' : ($selectedShift->published_revision ? 'veröffentlicht ist Revision '.$selectedShift->published_revision : 'noch nicht veröffentlicht')); ?> <?php $__env->endSlot(); ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <kbd class="rt-ops-panel__kbd" title="Mit Esc schließen">Esc</kbd>
                <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','xOn:click' => '$dispatch(\'close\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-on:click' => '$dispatch(\'close\')']); ?>Schließen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal729721974c9d27ec13799a7e5ca1e565)): ?>
<?php $attributes = $__attributesOriginal729721974c9d27ec13799a7e5ca1e565; ?>
<?php unset($__attributesOriginal729721974c9d27ec13799a7e5ca1e565); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal729721974c9d27ec13799a7e5ca1e565)): ?>
<?php $component = $__componentOriginal729721974c9d27ec13799a7e5ca1e565; ?>
<?php unset($__componentOriginal729721974c9d27ec13799a7e5ca1e565); ?>
<?php endif; ?>
        </div>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($formOpen): ?>
            <?php
                $formFieldTabs = [
                    'service' => ['orderId', 'title', 'roleName', 'requiredStaff', 'status', 'notes'],
                    'time' => ['startsAt', 'endsAt', 'timezone', 'plannedBreakMinutes', 'locationName'],
                    'proofs' => ['qualificationIds', 'qualificationIds.*'],
                ];
                $formTabs = [
                    'service' => ['label' => 'Dienst', 'alert' => $errors->hasAny($formFieldTabs['service'])],
                    'time' => ['label' => 'Zeit & Ort', 'alert' => $errors->hasAny($formFieldTabs['time'])],
                ];
                if ($nativeOperations) {
                    $formTabs['proofs'] = ['label' => 'Nachweise', 'count' => count($qualificationIds), 'countExpression' => '($wire.qualificationIds || []).length', 'alert' => $errors->hasAny($formFieldTabs['proofs'])];
                }
                $firstErrorTab = collect($formTabs)->filter(fn (array $tab): bool => (bool) ($tab['alert'] ?? false))->keys()->first();
                // Fehler ohne eigenes Feld (Planungsregeln, Zeitkonflikte, parallele Änderungen)
                // erschienen bisher nirgends im Formular – jetzt oben im Rumpf.
                $assignmentErrorKeys = ['assignment', 'employeeId', 'assignmentStatus', 'assignmentNote'];
                $generalFormErrors = collect($errors->getMessages())
                    ->reject(fn ($messages, $key) => in_array($key, array_merge($formFieldTabs['service'], $formFieldTabs['time'], $assignmentErrorKeys), true) || str_starts_with((string) $key, 'qualificationIds'))
                    ->flatten()->unique()->values();
                $editingOrder = $orderId ? $orders->firstWhere('id', $orderId) : null;
            ?>
            <div class="rt-ops-panel__mode" data-panel-mode="form" x-data="{ tab: 'service' }" wire:key="shift-panel-form-<?php echo e($editingShiftId ?? 'new'); ?>">
                <div class="rt-ops-panel__accent" aria-hidden="true"></div>
                <?php if (isset($component)) { $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.header','data' => ['eyebrow' => $editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht','title' => $editingShiftId ? (filled($title) ? $title : 'Schicht') : 'Neue Schicht anlegen','subtitle' => collect([$editingOrder ? $editingOrder->order_number.' · '.$editingOrder->title : null, $editingShiftId && $editingRevision ? 'Revision '.$editingRevision : null])->filter()->implode(' · '),'icon' => $editingShiftId ? 'fa-pen' : 'fa-calendar-plus','closeAction' => $detailOpen ? 'closeShiftForm' : null,'closeLabel' => $editingShiftId ? 'Bearbeiten abbrechen' : 'Anlegen abbrechen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['eyebrow' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht'),'title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($editingShiftId ? (filled($title) ? $title : 'Schicht') : 'Neue Schicht anlegen'),'subtitle' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect([$editingOrder ? $editingOrder->order_number.' · '.$editingOrder->title : null, $editingShiftId && $editingRevision ? 'Revision '.$editingRevision : null])->filter()->implode(' · ')),'icon' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($editingShiftId ? 'fa-pen' : 'fa-calendar-plus'),'close-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailOpen ? 'closeShiftForm' : null),'close-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($editingShiftId ? 'Bearbeiten abbrechen' : 'Anlegen abbrechen')]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $attributes = $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5)): ?>
<?php $component = $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5; ?>
<?php unset($__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginal6714da93c59fa1f131790fd720f4f95e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6714da93c59fa1f131790fd720f4f95e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tabs','data' => ['tabs' => $formTabs,'label' => 'Bereiche des Schichtformulars','idPrefix' => $formIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tabs'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['tabs' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($formTabs),'label' => 'Bereiche des Schichtformulars','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($formIdPrefix)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6714da93c59fa1f131790fd720f4f95e)): ?>
<?php $attributes = $__attributesOriginal6714da93c59fa1f131790fd720f4f95e; ?>
<?php unset($__attributesOriginal6714da93c59fa1f131790fd720f4f95e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6714da93c59fa1f131790fd720f4f95e)): ?>
<?php $component = $__componentOriginal6714da93c59fa1f131790fd720f4f95e; ?>
<?php unset($__componentOriginal6714da93c59fa1f131790fd720f4f95e); ?>
<?php endif; ?>

                <div class="rt-ops-panel__body" data-shift-form-body>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($firstErrorTab): ?>
                        
                        <span hidden wire:key="shift-form-errors-<?php echo e(md5(implode('|', $errors->keys()))); ?>" x-init="tab = <?php echo \Illuminate\Support\Js::from($firstErrorTab)->toHtml() ?>"></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($generalFormErrors->isNotEmpty()): ?>
                        <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['tone' => 'critical','role' => 'alert','dataShiftFormErrors' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['tone' => 'critical','role' => 'alert','data-shift-form-errors' => true]); ?>
                            <div class="rt-ops-panel__notice">
                                <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                                <ul class="rt-ops-panel__errors">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $generalFormErrors; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $message): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($message); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </ul>
                            </div>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <?php if (isset($component)) { $__componentOriginalaccc004e5db5e86b6167da033f6be736 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaccc004e5db5e86b6167da033f6be736 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'service','idPrefix' => $formIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'service','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($formIdPrefix)]); ?>
                        <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Auftrag & Tätigkeit']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Auftrag & Tätigkeit']); ?>
                            <div class="rt-ops-panel__fields">
                                <div class="rt-ops-panel__field--wide">
                                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-order','value' => 'Auftrag']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-order','value' => 'Auftrag']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'shift-order','wire:model' => 'orderId','searchModel' => 'orderSearch','class' => 'mt-1','placeholder' => 'Auftrag auswählen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-order','wire:model' => 'orderId','search-model' => 'orderSearch','class' => 'mt-1','placeholder' => 'Auftrag auswählen']); ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $order): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($order->id); ?>"><?php echo e($order->order_number); ?> · <?php echo e($order->title); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['orderId'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div>
                                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-title','value' => 'Schichttitel']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-title','value' => 'Schichttitel']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'shift-title','wire:model' => 'title','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-title','wire:model' => 'title','class' => 'mt-1']); ?>
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
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['title'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div>
                                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-role','value' => 'Rolle / Funktion']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-role','value' => 'Rolle / Funktion']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'shift-role','wire:model' => 'roleName','class' => 'mt-1','placeholder' => 'z. B. Triebfahrzeugführer']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-role','wire:model' => 'roleName','class' => 'mt-1','placeholder' => 'z. B. Triebfahrzeugführer']); ?>
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
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['roleName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Umfang & Status']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Umfang & Status']); ?>
                            <div class="rt-ops-panel__fields">
                                <div>
                                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-required-staff','value' => 'Benötigte Mitarbeitende']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-required-staff','value' => 'Benötigte Mitarbeitende']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                    <div class="mt-1"><?php if (isset($component)) { $__componentOriginalf93e6b313b87342ec474caffc76d3c54 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf93e6b313b87342ec474caffc76d3c54 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.number-input','data' => ['id' => 'shift-required-staff','min' => '1','max' => '999','nullable' => false,'wire:model' => 'requiredStaff']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.number-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-required-staff','min' => '1','max' => '999','nullable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'wire:model' => 'requiredStaff']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf93e6b313b87342ec474caffc76d3c54)): ?>
<?php $attributes = $__attributesOriginalf93e6b313b87342ec474caffc76d3c54; ?>
<?php unset($__attributesOriginalf93e6b313b87342ec474caffc76d3c54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf93e6b313b87342ec474caffc76d3c54)): ?>
<?php $component = $__componentOriginalf93e6b313b87342ec474caffc76d3c54; ?>
<?php unset($__componentOriginalf93e6b313b87342ec474caffc76d3c54); ?>
<?php endif; ?></div>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['requiredStaff'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div>
                                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-status','value' => 'Schichtstatus']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-status','value' => 'Schichtstatus']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'shift-status','wire:model' => 'status','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-status','wire:model' => 'status','class' => 'mt-1']); ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $statusOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['status'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
                            <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-notes','value' => 'Interne Notizen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-notes','value' => 'Interne Notizen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                            <?php if (isset($component)) { $__componentOriginal7f6d582ce7a3b2aeb0250793236761ee = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.textarea','data' => ['id' => 'shift-notes','wire:model' => 'notes','rows' => '4','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-notes','wire:model' => 'notes','rows' => '4','class' => 'mt-1']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee)): ?>
<?php $attributes = $__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee; ?>
<?php unset($__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7f6d582ce7a3b2aeb0250793236761ee)): ?>
<?php $component = $__componentOriginal7f6d582ce7a3b2aeb0250793236761ee; ?>
<?php unset($__componentOriginal7f6d582ce7a3b2aeb0250793236761ee); ?>
<?php endif; ?>
                            <p class="rt-ops-panel__help">Nur in der Disposition sichtbar.</p>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['notes'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $attributes = $__attributesOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__attributesOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $component = $__componentOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__componentOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>

                    <?php if (isset($component)) { $__componentOriginalaccc004e5db5e86b6167da033f6be736 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaccc004e5db5e86b6167da033f6be736 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'time','idPrefix' => $formIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'time','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($formIdPrefix)]); ?>
                        <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Zeitfenster','meta' => $timezone]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Zeitfenster','meta' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($timezone)]); ?>
                            <div class="rt-ops-panel__fields">
                                <div>
                                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-start','value' => 'Beginn']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-start','value' => 'Beginn']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal4878807c3bad702f346d6e9e5724ad7d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4878807c3bad702f346d6e9e5724ad7d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-time-field','data' => ['id' => 'shift-start','wire:model' => 'startsAt','ariaLabel' => 'Beginn ('.$timezone.')','class' => 'mt-1','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-time-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-start','wire:model' => 'startsAt','aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('Beginn ('.$timezone.')'),'class' => 'mt-1','required' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4878807c3bad702f346d6e9e5724ad7d)): ?>
<?php $attributes = $__attributesOriginal4878807c3bad702f346d6e9e5724ad7d; ?>
<?php unset($__attributesOriginal4878807c3bad702f346d6e9e5724ad7d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4878807c3bad702f346d6e9e5724ad7d)): ?>
<?php $component = $__componentOriginal4878807c3bad702f346d6e9e5724ad7d; ?>
<?php unset($__componentOriginal4878807c3bad702f346d6e9e5724ad7d); ?>
<?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['startsAt'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div>
                                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-end','value' => 'Ende']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-end','value' => 'Ende']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                                    <?php if (isset($component)) { $__componentOriginal4878807c3bad702f346d6e9e5724ad7d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4878807c3bad702f346d6e9e5724ad7d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-time-field','data' => ['id' => 'shift-end','wire:model' => 'endsAt','ariaLabel' => 'Ende ('.$timezone.')','class' => 'mt-1','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-time-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-end','wire:model' => 'endsAt','aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('Ende ('.$timezone.')'),'class' => 'mt-1','required' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4878807c3bad702f346d6e9e5724ad7d)): ?>
<?php $attributes = $__attributesOriginal4878807c3bad702f346d6e9e5724ad7d; ?>
<?php unset($__attributesOriginal4878807c3bad702f346d6e9e5724ad7d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4878807c3bad702f346d6e9e5724ad7d)): ?>
<?php $component = $__componentOriginal4878807c3bad702f346d6e9e5724ad7d; ?>
<?php unset($__componentOriginal4878807c3bad702f346d6e9e5724ad7d); ?>
<?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['endsAt'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Geplante Pause (min)','model' => 'plannedBreakMinutes','type' => 'number','min' => '0','max' => '1439']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Geplante Pause (min)','model' => 'plannedBreakMinutes','type' => 'number','min' => '0','max' => '1439']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['timezone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
                            <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'shift-location','value' => 'Einsatzort']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'shift-location','value' => 'Einsatzort']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                            <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'shift-location','wire:model' => 'locationName','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-location','wire:model' => 'locationName','class' => 'mt-1']); ?>
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
                            <p class="rt-ops-panel__help">Leer lassen, um den Einsatzort der Leistung zu übernehmen.</p>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['locationName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $attributes = $__attributesOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__attributesOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $component = $__componentOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__componentOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?>
                        <?php if (isset($component)) { $__componentOriginalaccc004e5db5e86b6167da033f6be736 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaccc004e5db5e86b6167da033f6be736 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'proofs','idPrefix' => $formIdPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'proofs','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($formIdPrefix)]); ?>
                            <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Erforderliche Nachweise']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Erforderliche Nachweise']); ?>
                                <p class="rt-ops-panel__help">Bei der Zuweisung wird geprüft, ob Mitarbeitende für jeden gewählten Nachweis einen gültigen Nachweis haben.</p>
                                <fieldset class="rt-ops-panel__choices">
                                    <legend class="sr-only">Erforderliche Nachweise</legend>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $qualificationTypes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                        <?php if (isset($component)) { $__componentOriginal73478c8be7272bff1d507d0522fd3d44 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal73478c8be7272bff1d507d0522fd3d44 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.checkbox','data' => ['wire:model' => 'qualificationIds','value' => ''.e($type->id).'','label' => $type->name]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.checkbox'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'qualificationIds','value' => ''.e($type->id).'','label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($type->name)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $attributes = $__attributesOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $component = $__componentOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__componentOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                        <p class="rt-ops-panel__help">Noch keine Nachweisarten angelegt.</p>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </fieldset>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['qualificationIds'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $attributes = $__attributesOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__attributesOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a1359f4ab379e69627513fc55a93478)): ?>
<?php $component = $__componentOriginal6a1359f4ab379e69627513fc55a93478; ?>
<?php unset($__componentOriginal6a1359f4ab379e69627513fc55a93478); ?>
<?php endif; ?>
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $attributes = $__attributesOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__attributesOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaccc004e5db5e86b6167da033f6be736)): ?>
<?php $component = $__componentOriginalaccc004e5db5e86b6167da033f6be736; ?>
<?php unset($__componentOriginalaccc004e5db5e86b6167da033f6be736); ?>
<?php endif; ?>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <?php if (isset($component)) { $__componentOriginal729721974c9d27ec13799a7e5ca1e565 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal729721974c9d27ec13799a7e5ca1e565 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.footer','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.footer'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations && $editingShiftId): ?>
                         <?php $__env->slot('note', null, []); ?> <i class="far fa-info-circle" aria-hidden="true"></i>Geänderte Angaben werden als neue Revision gespeichert und erreichen Eingeteilte erst nach dem Veröffentlichen. <?php $__env->endSlot(); ?>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen): ?>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','wire:click' => 'closeShiftForm','wire:loading.attr' => 'disabled','wire:target' => 'closeShiftForm,saveShift']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','wire:click' => 'closeShiftForm','wire:loading.attr' => 'disabled','wire:target' => 'closeShiftForm,saveShift']); ?>Abbrechen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    <?php else: ?>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','xOn:click' => '$dispatch(\'close\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-on:click' => '$dispatch(\'close\')']); ?>Abbrechen <?php echo $__env->renderComponent(); ?>
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
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'primary','wire:click' => 'saveShift','wire:loading.attr' => 'disabled','wire:target' => 'saveShift,closeShiftForm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'primary','wire:click' => 'saveShift','wire:loading.attr' => 'disabled','wire:target' => 'saveShift,closeShiftForm']); ?>
                        <i wire:loading.remove wire:target="saveShift" class="far fa-check" aria-hidden="true"></i>
                        <i wire:loading wire:target="saveShift" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                        Speichern
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
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal729721974c9d27ec13799a7e5ca1e565)): ?>
<?php $attributes = $__attributesOriginal729721974c9d27ec13799a7e5ca1e565; ?>
<?php unset($__attributesOriginal729721974c9d27ec13799a7e5ca1e565); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal729721974c9d27ec13799a7e5ca1e565)): ?>
<?php $component = $__componentOriginal729721974c9d27ec13799a7e5ca1e565; ?>
<?php unset($__componentOriginal729721974c9d27ec13799a7e5ca1e565); ?>
<?php endif; ?>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalee5b1959550127dcd3c5272f16dd7b79)): ?>
<?php $attributes = $__attributesOriginalee5b1959550127dcd3c5272f16dd7b79; ?>
<?php unset($__attributesOriginalee5b1959550127dcd3c5272f16dd7b79); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalee5b1959550127dcd3c5272f16dd7b79)): ?>
<?php $component = $__componentOriginalee5b1959550127dcd3c5272f16dd7b79; ?>
<?php unset($__componentOriginalee5b1959550127dcd3c5272f16dd7b79); ?>
<?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/shift-management.blade.php ENDPATH**/ ?>