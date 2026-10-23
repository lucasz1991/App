<div class="rt-staffing" data-shift-staffing x-data="{ filtersOpen: false }">
    <section class="rt-staffing-section" aria-label="Aktuelle Besetzung">
        <div class="rt-staffing-heading">
            <div><h3>Besetzung & Rückmeldungen</h3><p><?php echo e($selectedReservedCount); ?> von <?php echo e($selectedShift->required_staff); ?> Plätzen reserviert · <?php echo e(max(0, $selectedShift->required_staff - $selectedReservedCount)); ?> offen</p></div>
            <span class="rt-staffing-count"><?php echo e($feedback->count()); ?></span>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($feedback->isNotEmpty()): ?>
            <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Mitarbeiter','key'=>'name','width'=>'minmax(0,1.2fr)'],['label'=>'Rückmeldung & Zustellung','key'=>'response','width'=>'minmax(0,1.3fr)'],['label'=>'','key'=>'action','width'=>'44px']],'items' => $feedback,'rowView' => 'components.tables.rows.operations.staffing-feedback','label' => 'Aktuelle Besetzung und Rückmeldungen','class' => 'rt-staffing-table']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Mitarbeiter','key'=>'name','width'=>'minmax(0,1.2fr)'],['label'=>'Rückmeldung & Zustellung','key'=>'response','width'=>'minmax(0,1.3fr)'],['label'=>'','key'=>'action','width'=>'44px']]),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($feedback),'row-view' => 'components.tables.rows.operations.staffing-feedback','label' => 'Aktuelle Besetzung und Rückmeldungen','class' => 'rt-staffing-table']); ?>
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
            <p class="rt-staffing-note">Noch niemand zugewiesen. Wählen Sie unten passende Mitarbeitende aus.</p>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Routing\TomTomSettings::all()['enabled'] && $feedback->filter(fn($entry) => $entry->status->blocksAvailability())->isNotEmpty()): ?>
                <?php
                    $allAssignedRouteIds = $feedback->filter(fn($entry) => $entry->status->blocksAvailability())->pluck('user_id')->unique();
                    $assignedRouteIds = $assignedRouteUserId && $allAssignedRouteIds->contains($assignedRouteUserId) ? [$assignedRouteUserId] : $allAssignedRouteIds->take(24)->all();
                    $assignedRouteInitial = array_intersect_key($planningTravel[$selectedShift->id] ?? [], array_flip($assignedRouteIds));
                ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($allAssignedRouteIds->count() > count($assignedRouteIds)): ?><p class="rt-staffing-note"><?php echo e(count($assignedRouteIds)); ?> von <?php echo e($allAssignedRouteIds->count()); ?> zugeteilten Personen in der Fahrtansicht. Über „Fahrten prüfen“ an einer Person gezielt wechseln.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div x-data x-intersect.once="$wire.loadShiftTravel(<?php echo e($selectedShift->id); ?>)" wire:key="assigned-travel-start-<?php echo e($selectedShift->id); ?>-<?php echo e($selectedShift->revision); ?>">
                    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.shift-travel-panel', ['shiftId' => $selectedShift->id,'employeeIds' => $assignedRouteIds,'initialAssessments' => $assignedRouteInitial ?: null,'parentManaged' => true]);

$__key = 'assigned-travel-'.$selectedShift->id.'-'.$selectedShift->revision.'-'.md5(json_encode([$assignedRouteIds, $assignedRouteInitial]));

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2442469517-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Operations\WorkforcePlanningSchema::ready()): ?><details class="rt-staffing-more"><summary>Planungsvarianten</summary><?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.plan-variants', ['shiftIds' => [$selectedShift->id]]);

$__key = 'variants-'.$selectedShift->id;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2442469517-1', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?></details><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($regionalEmployee): ?>
        <?php echo $__env->make('livewire.operations.partials.staffing-regions', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedCandidate): ?>
        <?php echo $__env->make('livewire.operations.partials.staffing-assignment-review', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['assignment'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p class="rt-staffing-alert" role="alert"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shiftClosed): ?>
        <p class="rt-staffing-note">Diese Schicht ist abgeschlossen oder storniert. Neue Zuweisungen sind nicht möglich.</p>
    <?php else: ?>
        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.ai-planning-helper', ['shiftId' => $selectedShift->id]);

$__key = 'ai-planning-shift-'.$selectedShift->id.'-'.$selectedShift->revision;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2442469517-2', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
        <section class="rt-staffing-section" aria-label="Mitarbeitende finden" data-staffing-candidates>
            <div class="rt-staffing-heading">
                <div><h3>Mitarbeitende finden</h3><p>Kernregeln zuerst · Fahrten werden für angezeigte Personen ergänzt</p></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($candidatesReady): ?><span class="rt-staffing-count"><?php echo e($candidateTotal); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$candidatesReady): ?>
                <div x-data x-intersect.once="$wire.loadStaffingCandidates()" class="rt-staffing-loading" role="status">
                    <i class="far fa-spinner-third fa-spin" aria-hidden="true"></i><span>Eignung wird geprüft …</span>
                    <button type="button" wire:click="loadStaffingCandidates" wire:loading.attr="disabled">Liste laden</button>
                </div>
            <?php else: ?>
                <?php ($pendingTravelIds = $candidates->filter(fn($person) => $person->staffing_state !== 'blocked' && (($person->planning_travel['route_state'] ?? '') === 'pending' || !isset($planningTravel[$selectedShift->id][$person->id])))->pluck('id')->all()); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Routing\TomTomSettings::all()['enabled'] && $pendingTravelIds && empty($planningTravelAutoLoaded[$staffingTravelAutoKey])): ?>
                    <div x-data x-intersect.once="$wire.loadShiftTravel(<?php echo e($selectedShift->id); ?>)" wire:key="candidate-travel-load-<?php echo e($staffingTravelAutoKey); ?>" role="status" class="rt-staffing-note">Fahrzeiten werden nachgeladen …</div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div class="rt-staffing-search">
                    <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['wire:model.live.debounce.350ms' => 'candidateSearch','placeholder' => 'Name suchen','ariaLabel' => 'Mitarbeitende nach Namen suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model.live.debounce.350ms' => 'candidateSearch','placeholder' => 'Name suchen','aria-label' => 'Mitarbeitende nach Namen suchen']); ?>
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
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','xOn:click' => 'filtersOpen = !filtersOpen','xBind:ariaExpanded' => 'filtersOpen','ariaControls' => 'staffing-filters-'.e($selectedShift->id).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-on:click' => 'filtersOpen = !filtersOpen','x-bind:aria-expanded' => 'filtersOpen','aria-controls' => 'staffing-filters-'.e($selectedShift->id).'']); ?><i class="far fa-sliders-h" aria-hidden="true"></i> Filter <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($candidateQualification || $candidatePool || $candidateSuitability !== 'all' || $candidateRegion !== 'all'): ?><span class="rt-staffing-filter-dot" aria-label="Filter aktiv"></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
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
                <div class="rt-staffing-filters" x-show="filtersOpen" x-cloak id="staffing-filters-<?php echo e($selectedShift->id); ?>">
                    <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Eignung','for' => 'staffing-suitability']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Eignung','for' => 'staffing-suitability']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'staffing-suitability','wire:model.live' => 'candidateSuitability']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'staffing-suitability','wire:model.live' => 'candidateSuitability']); ?><option value="all">Alle Eignungen</option><option value="eligible">Geeignet</option><option value="review">Zeitkonflikt prüfen</option><option value="blocked">Nicht zuweisbar</option> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $attributes = $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $component = $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Qualifikation','for' => 'staffing-qualification']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Qualifikation','for' => 'staffing-qualification']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'staffing-qualification','wire:model.live' => 'candidateQualification']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'staffing-qualification','wire:model.live' => 'candidateQualification']); ?><option value="">Alle Qualifikationen</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $candidateFilterOptions['qualifications']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $qualification): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($qualification['id']); ?>"><?php echo e($qualification['name']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $attributes = $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $component = $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Personalpool','for' => 'staffing-pool']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Personalpool','for' => 'staffing-pool']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'staffing-pool','wire:model.live' => 'candidatePool']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'staffing-pool','wire:model.live' => 'candidatePool']); ?><option value="">Alle Personalpools</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $candidateFilterOptions['pools']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pool): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($pool['id']); ?>"><?php echo e($pool['name']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $attributes = $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $component = $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Einsatzgebiet','for' => 'staffing-region']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Einsatzgebiet','for' => 'staffing-region']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'staffing-region','wire:model.live' => 'candidateRegion']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'staffing-region','wire:model.live' => 'candidateRegion']); ?><option value="all">Alle Gebiete</option><option value="preferred">Wunschgebiet</option><option value="border">Mögliches Gebiet</option><option value="emergency">Notfallgebiet</option><option value="outside">Außerhalb Wunschgebiet</option><option value="no_go">No-Go-Gebiet</option><option value="unknown">Ort nicht prüfbar</option><option value="neutral">Ohne Regionalwunsch</option> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $attributes = $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__attributesOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823)): ?>
<?php $component = $__componentOriginal6f1786746f05efe88dc6b44bb19ef823; ?>
<?php unset($__componentOriginal6f1786746f05efe88dc6b44bb19ef823); ?>
<?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $attributes = $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $component = $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
                    <button type="button" class="rt-staffing-text-button" wire:click="resetCandidateFilters">Filter zurücksetzen</button>
                </div>
                <details class="rt-staffing-more"><summary>Wie wird die Eignung ermittelt?</summary><p>Der Wert ist eine Planungshilfe, keine Wahrscheinlichkeit oder Rechtsfreigabe: 85 Basispunkte, bevorzugt +15, möglich +5, Notfallgebiet −10, außerhalb der neuen Zonen −20. Bestehende Radiusprofile behalten außerhalb −10 bis zur Umstellung. Fehlende Regionalwünsche bleiben neutral. Zeitkonflikte senken den Wert und erfordern eine gesonderte Prüfung; feste Ausschlussgründe stehen immer zuletzt. Pflichtqualifikationen müssen während der gesamten Schicht gültig sein.</p></details>
                <div wire:loading.flex wire:target="candidateSearch,candidateQualification,candidatePool,candidateSuitability,candidateRegion,resetCandidateFilters" class="rt-staffing-loading" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i> Treffer werden neu geprüft …</div>
                <div wire:loading.class="opacity-50 pointer-events-none" wire:target="candidateSearch,candidateQualification,candidatePool,candidateSuitability,candidateRegion,resetCandidateFilters">
                    <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Mitarbeiter','key'=>'name','width'=>'minmax(0,1.25fr)'],['label'=>'Eignung ↓','key'=>'eligibility','width'=>'minmax(0,1.2fr)'],['label'=>'','key'=>'action','width'=>'110px']],'items' => $candidates,'rowView' => 'components.tables.rows.operations.staffing-candidate','empty' => 'Keine Treffer. Suche oder Filter anpassen.','label' => 'Mitarbeitende nach Planungseignung absteigend','class' => 'rt-staffing-table']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Mitarbeiter','key'=>'name','width'=>'minmax(0,1.25fr)'],['label'=>'Eignung ↓','key'=>'eligibility','width'=>'minmax(0,1.2fr)'],['label'=>'','key'=>'action','width'=>'110px']]),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($candidates),'row-view' => 'components.tables.rows.operations.staffing-candidate','empty' => 'Keine Treffer. Suche oder Filter anpassen.','label' => 'Mitarbeitende nach Planungseignung absteigend','class' => 'rt-staffing-table']); ?>
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
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($candidates->count() < $candidateTotal): ?>
                    <div class="rt-staffing-loading" wire:key="staffing-more-<?php echo e($selectedShift->id); ?>-<?php echo e($candidateLimit); ?>-<?php echo e(md5($candidateSearch.$candidateQualification.$candidatePool.$candidateSuitability.$candidateRegion)); ?>" x-data x-intersect.once="$wire.loadMoreCandidates()">
                        <button type="button" wire:click="loadMoreCandidates" wire:loading.attr="disabled" wire:target="loadMoreCandidates"><span wire:loading.remove wire:target="loadMoreCandidates">Weitere Mitarbeitende laden</span><span wire:loading wire:target="loadMoreCandidates" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i> Wird geladen …</span></button>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <p class="rt-staffing-note" aria-live="polite"><?php echo e($candidates->count()); ?> von <?php echo e($candidateTotal); ?> Mitarbeitenden · Bereits reservierte Personen stehen oben.</p>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Support\Routing\TomTomSettings::all()['enabled'] && $candidates->isNotEmpty()): ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($pendingTravelIds): ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($planningTravelAutoLoaded[$staffingTravelAutoKey])): ?><button type="button" class="rt-staffing-text-button min-h-11" wire:click="loadShiftTravel(<?php echo e($selectedShift->id); ?>)" wire:loading.attr="disabled">Weitere angezeigte Fahrten prüfen (bis 24 Personen)</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <p class="rt-staffing-note">Bekannte Fahrzeitkonflikte fließen in Eignung und Filter ein. Bei gleicher Bewertung zählen kürzere geplante Fahrten nur in vollständig geprüften Vergleichsgruppen. Jede verbindliche Zuteilung prüft die Übergänge erneut.</p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $candidates->where('staffing_state', '!=', 'blocked')->chunk(24); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $travelCandidates): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php ($candidateRouteInitial = array_intersect_key($planningTravel[$selectedShift->id] ?? [], array_flip($travelCandidates->pluck('id')->all()))); ?>
                        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.shift-travel-panel', ['shiftId' => $selectedShift->id,'employeeIds' => $travelCandidates->pluck('id')->all(),'initialAssessments' => $candidateRouteInitial ?: null,'parentManaged' => true]);

$__key = 'shift-travel-'.$selectedShift->id.'-'.$selectedShift->revision.'-'.md5($travelCandidates->pluck('id')->join(',').json_encode($candidateRouteInitial));

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2442469517-3', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/partials/shift-staffing.blade.php ENDPATH**/ ?>