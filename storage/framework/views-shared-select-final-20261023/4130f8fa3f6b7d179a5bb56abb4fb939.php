<?php
    $calendarPanelId = 'calendar-shift-detail-'.$this->getId();
    $calendarTabsPrefix = 'calendar-shift-tabs-'.$this->getId();
    if ($selectedShift) {
        $panelStatus = $selectedShift->status instanceof \BackedEnum ? $selectedShift->status->value : (string) $selectedShift->status;
        $panelAssignments = $selectedShift->assignments
            ->sortBy(fn ($assignment) => ['confirmed' => 0, 'requested' => 1, 'declined' => 2, 'cancelled' => 3][$assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status] ?? 9)
            ->values();
        $panelReserved = $panelAssignments->filter(fn ($assignment) => in_array($assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status, ['requested', 'confirmed'], true))->count();
        $panelStart = $selectedShift->starts_at?->setTimezone($displayTimezone)->locale('de');
        $panelEnd = $selectedShift->ends_at?->setTimezone($displayTimezone)->locale('de');
        $panelWindow = $panelStart && $panelEnd
            ? ($panelStart->isSameDay($panelEnd)
                ? $panelStart->isoFormat('dd, DD.MM.').' · '.$panelStart->format('H:i').'–'.$panelEnd->format('H:i')
                : $panelStart->isoFormat('dd, DD.MM. HH:mm').' – '.$panelEnd->isoFormat('dd, DD.MM. HH:mm'))
            : null;
        $panelLocation = $selectedShift->location_name ?: $selectedShift->order?->location_name;
        $panelClosed = in_array($panelStatus, ['cancelled', 'completed'], true);
        $panelOpenSlots = $panelClosed ? 0 : max(0, $selectedShift->required_staff - $panelReserved);
        $panelTabs = [
            'overview' => ['label' => 'Übersicht'],
            'staffing' => ['label' => 'Besetzung', 'count' => $panelReserved.'/'.$selectedShift->required_staff],
        ];
    }
?>

<?php if (isset($component)) { $__componentOriginalee5b1959550127dcd3c5272f16dd7b79 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalee5b1959550127dcd3c5272f16dd7b79 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel','data' => ['id' => $calendarPanelId,'showExpression' => 'detailVisible','label' => 'Schichtdetails','dataCalendarShiftPanel' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($calendarPanelId),'show-expression' => 'detailVisible','label' => 'Schichtdetails','data-calendar-shift-panel' => true]); ?>
    <div class="rt-ops-panel__mode" data-panel-mode="detail" x-data="{ tab: 'overview' }" wire:key="calendar-panel-<?php echo e($selectedShift?->id ?? 'none'); ?>">
        <div class="rt-ops-panel__accent" aria-hidden="true"></div>

        <header class="rt-ops-panel__header" x-show="loading || error" style="display: none" data-calendar-panel-placeholder>
            <span class="rt-ops-panel__icon" aria-hidden="true"><i class="far fa-clock"></i></span>
            <div class="rt-ops-panel__heading" aria-hidden="true">
                <span class="rt-ops-panel__eyebrow">Schichtdetails</span>
                <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--title"></span>
                <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--line"></span>
            </div>
            <div class="rt-ops-panel__actions">
                <button type="button" class="rt-ops-panel__icon-button" x-on:click="$dispatch('close')" aria-label="Schichtdetails schließen" title="Schichtdetails schließen"><i class="far fa-times" aria-hidden="true"></i></button>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','wire:click' => 'openShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled','wire:target' => 'openShift','dataCalendarPanelPlan' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','wire:click' => 'openShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled','wire:target' => 'openShift','data-calendar-panel-plan' => true]); ?>
                        <i class="far fa-external-link" aria-hidden="true"></i>Im Schichtplan
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($panelWindow): ?>
                        <span class="rt-ops-panel__fact rt-ops-panel__fact--strong"><i class="far fa-calendar" aria-hidden="true"></i><time datetime="<?php echo e($panelStart->toIso8601String()); ?>"><?php echo e($panelWindow); ?></time></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($panelLocation)): ?>
                        <span class="rt-ops-panel__fact"><i class="far fa-map-marker-alt" aria-hidden="true"></i><?php echo e($panelLocation); ?></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <span class="rt-ops-panel__meta-end">
                        <?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9140b74635d19149dc471db3f05482a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.status','data' => ['value' => $panelStatus,'label' => $selectedShift->status->label()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelStatus),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedShift->status->label())]); ?>
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
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($panelClosed)): ?>
                            <span class="rt-ops-panel__pill" data-tone="<?php echo e($panelOpenSlots === 0 ? 'success' : 'critical'); ?>"><?php echo e($panelReserved); ?>/<?php echo e($selectedShift->required_staff); ?> eingeplant</span>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tabs','data' => ['xShow' => '!loading && !error','tabs' => $panelTabs,'label' => 'Bereiche der Schichtdetails','idPrefix' => $calendarTabsPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tabs'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['x-show' => '!loading && !error','tabs' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelTabs),'label' => 'Bereiche der Schichtdetails','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($calendarTabsPrefix)]); ?>
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

        <div class="rt-ops-panel__body rt-modal-content" data-calendar-panel-body>
            <div x-show="loading" x-cloak>
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
            <div x-show="!loading && error" x-cloak class="space-y-4 py-6">
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
<?php $component->withAttributes(['type' => 'button','size' => 'sm','x-on:click' => 'openShiftDetail(requestedShiftId)']); ?><i class="far fa-redo" aria-hidden="true"></i>Erneut versuchen <?php echo $__env->renderComponent(); ?>
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
            <div x-show="!loading && !error" x-bind:aria-busy="loading" data-calendar-panel-content>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift): ?>
                    <?php if (isset($component)) { $__componentOriginalaccc004e5db5e86b6167da033f6be736 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaccc004e5db5e86b6167da033f6be736 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'overview','idPrefix' => $calendarTabsPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'overview','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($calendarTabsPrefix)]); ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($panelOpenSlots > 0): ?>
                            <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['tone' => 'warning']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['tone' => 'warning']); ?>
                                <div class="rt-ops-panel__notice">
                                    <i class="far fa-exclamation-triangle" aria-hidden="true"></i>
                                    <div class="rt-ops-panel__notice-text">
                                        <strong><?php echo e($panelOpenSlots); ?> <?php echo e($panelOpenSlots === 1 ? 'Platz' : 'Plätze'); ?> offen</strong>
                                        <span><?php echo e($panelReserved); ?> von <?php echo e($selectedShift->required_staff); ?> eingeplant</span>
                                    </div>
                                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'primary','wire:click' => 'openShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'primary','wire:click' => 'openShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled']); ?>Besetzen <?php echo $__env->renderComponent(); ?>
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
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($panelStart && $panelEnd): ?>
                            <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Zeit & Ort']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Zeit & Ort']); ?>
                                <div class="rt-ops-panel__previews">
                                    <?php if (isset($component)) { $__componentOriginala73e6852b913424e697d8dc395df0e76 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala73e6852b913424e697d8dc395df0e76 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.timeline-mini-calendar','data' => ['start' => $panelStart,'end' => $panelEnd]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.timeline-mini-calendar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['start' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelStart),'end' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelEnd)]); ?>
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
                                    <?php if (isset($component)) { $__componentOriginal8e63117d4f57364d8602eb85edbb579c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8e63117d4f57364d8602eb85edbb579c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.timeline-mini-map','data' => ['preview' => \App\Support\Operations\TimelineLocationPreview::fromShift($selectedShift),'location' => $panelLocation]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.timeline-mini-map'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['preview' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(\App\Support\Operations\TimelineLocationPreview::fromShift($selectedShift)),'location' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelLocation)]); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Leistung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Leistung']); ?><?php echo e($selectedShift->order ? $selectedShift->order->order_number.' · '.$selectedShift->order->title : 'Leistung nicht verfügbar'); ?> <?php echo $__env->renderComponent(); ?>
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
<?php $component->withAttributes(['label' => 'Kunde']); ?><?php echo e($selectedShift->order?->customer?->company_name ?: '—'); ?> <?php echo $__env->renderComponent(); ?>
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
<?php $component->withAttributes(['label' => 'Einsatzort']); ?><?php echo e($panelLocation ?: 'Kein Einsatzort hinterlegt'); ?> <?php echo $__env->renderComponent(); ?>
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
                                    <span class="rt-ops-panel__num"><?php echo e($panelStart?->format('d.m.Y H:i')); ?> – <?php echo e($panelEnd?->format('d.m.Y H:i')); ?></span>
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
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift->relationLoaded('qualifications')): ?>
                                    <?php if (isset($component)) { $__componentOriginal52e3918d674993c8394b253eb99dee6c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal52e3918d674993c8394b253eb99dee6c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Nachweise']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Nachweise']); ?><?php echo e($selectedShift->qualifications->pluck('name')->filter()->implode(', ') ?: 'Keine besonderen Nachweise'); ?> <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.row','data' => ['label' => 'Planstand']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.row'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Planstand']); ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((int) $selectedShift->published_revision === 0): ?>
                                        Noch nicht veröffentlicht
                                    <?php elseif((int) $selectedShift->published_revision !== (int) $selectedShift->revision): ?>
                                        Änderung unveröffentlicht · Rev. <?php echo e($selectedShift->revision); ?>

                                    <?php else: ?>
                                        Veröffentlicht · Rev. <?php echo e($selectedShift->revision); ?>

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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tab','data' => ['name' => 'staffing','idPrefix' => $calendarTabsPrefix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tab'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'staffing','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($calendarTabsPrefix)]); ?>
                        <?php if (isset($component)) { $__componentOriginal6a1359f4ab379e69627513fc55a93478 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a1359f4ab379e69627513fc55a93478 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.group','data' => ['title' => 'Eingeteilte Mitarbeitende','meta' => $panelReserved.' von '.$selectedShift->required_staff]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Eingeteilte Mitarbeitende','meta' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($panelReserved.' von '.$selectedShift->required_staff)]); ?>
                            <div class="rt-ops-panel__list">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $panelAssignments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $assignment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                    <?php
                                        $assignmentValue = $assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status;
                                    ?>
                                    <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['flex min-h-16 items-center gap-3 px-3.5 py-2.5', 'opacity-60' => in_array($assignmentValue, ['declined', 'cancelled'], true)]); ?>" wire:key="calendar-assignment-<?php echo e($assignment->id); ?>">
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
                                    </div>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                    <p class="px-4 py-7 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch niemand eingeteilt.</p>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($slot = 0; $slot < $panelOpenSlots; $slot++): ?>
                                    <div class="flex min-h-12 items-center gap-3 px-3.5 py-2 text-sm text-rt-muted dark:text-rt-dark-muted"><span class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-dashed border-rt-line dark:border-rt-dark-line" aria-hidden="true"><i class="far fa-user-plus"></i></span>Platz offen</div>
                                <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedShift): ?>
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
                <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'primary','wire:click' => 'openShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled','wire:target' => 'openShift']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'primary','wire:click' => 'openShift('.e($selectedShift->id).')','wire:loading.attr' => 'disabled','wire:target' => 'openShift']); ?>Im Schichtplan bearbeiten <?php echo $__env->renderComponent(); ?>
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
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/partials/calendar-shift-panel.blade.php ENDPATH**/ ?>