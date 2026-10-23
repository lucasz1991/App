<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedOrder): ?>
            <?php
                $selectedStatus = $selectedOrder->status instanceof \BackedEnum ? $selectedOrder->status->value : (string) $selectedOrder->status;
                $selectedPriority = $selectedOrder->priority instanceof \BackedEnum ? $selectedOrder->priority->value : (string) $selectedOrder->priority;
                $planningRoute = \App\Support\Operations\OperationsAccess::ready() ? 'operations.workspace' : 'admin.operations.preview';
            ?>
            <div class="rt-ops-panel__accent" aria-hidden="true"></div>
            <?php if (isset($component)) { $__componentOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5a4c656b55ce9a64e16930d00e0ac6f5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.header','data' => ['eyebrow' => $selectedOrder->order_number,'title' => $selectedOrder->title,'subtitle' => 'Leistungsdetails','icon' => 'fa-briefcase','closeLabel' => 'Leistungsdetails schließen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['eyebrow' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedOrder->order_number),'title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedOrder->title),'subtitle' => 'Leistungsdetails','icon' => 'fa-briefcase','close-label' => 'Leistungsdetails schließen']); ?>
                 <?php $__env->slot('actions', null, []); ?> 
                    <button type="button" wire:click="editOrder(<?php echo e($selectedOrder->id); ?>)" wire:loading.attr="disabled" <?php if($embeddedDetail): ?> x-on:click="$dispatch('close')" <?php endif; ?> class="rt-ops-panel__icon-button" aria-label="Leistung bearbeiten" title="Leistung bearbeiten"><i class="far fa-pen" aria-hidden="true"></i></button>
                 <?php $__env->endSlot(); ?>
                 <?php $__env->slot('meta', null, []); ?> <?php if (isset($component)) { $__componentOriginalda64f642c383ba8bc276b70e81e47b77 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalda64f642c383ba8bc276b70e81e47b77 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-status','data' => ['status' => $selectedOrder->status]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedOrder->status)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalda64f642c383ba8bc276b70e81e47b77)): ?>
<?php $attributes = $__attributesOriginalda64f642c383ba8bc276b70e81e47b77; ?>
<?php unset($__attributesOriginalda64f642c383ba8bc276b70e81e47b77); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalda64f642c383ba8bc276b70e81e47b77)): ?>
<?php $component = $__componentOriginalda64f642c383ba8bc276b70e81e47b77; ?>
<?php unset($__componentOriginalda64f642c383ba8bc276b70e81e47b77); ?>
<?php endif; ?><span class="rt-ops-panel__fact"><?php echo e($serviceDetailItem->count); ?> <?php echo e($serviceDetailItem->count === 1 ? 'Schicht' : 'Schichten'); ?> · Gesamte Leistung</span> <?php $__env->endSlot(); ?>
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
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($consolidated): ?>
                <?php
                    $detailTabIcons = ['overview'=>'far fa-briefcase', 'shifts'=>'far fa-calendar-days', 'staffing'=>'far fa-users', 'planning'=>'far fa-calendar-check', 'proofs'=>'far fa-file-check', 'costs'=>'far fa-coins', 'history'=>'far fa-clock-rotate-left'];
                    $detailTabs = collect($this->detailSections())->map(fn ($label, $key) => [
                        'label'=>$label, 'icon'=>$detailTabIcons[$key] ?? 'far fa-circle',
                        'count'=>match ($key) { 'shifts'=>$serviceDetailItem->count, 'staffing'=>$serviceDetailItem->summary['reserved'], default=>null },
                        'alert'=>$key === 'staffing' && $serviceDetailItem->summary['open'] > 0,
                    ])->all();
                    $detailPrefix = 'service-detail-'.$this->getId();
                ?>
                <?php if (isset($component)) { $__componentOriginal6714da93c59fa1f131790fd720f4f95e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6714da93c59fa1f131790fd720f4f95e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel.tabs','data' => ['tabs' => $detailTabs,'label' => 'Leistungsdetails','idPrefix' => $detailPrefix,'action' => 'setDetailSection','active' => $detailSection]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel.tabs'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['tabs' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailTabs),'label' => 'Leistungsdetails','id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailPrefix),'action' => 'setDetailSection','active' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailSection)]); ?>
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
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="rt-ops-panel__body rt-modal-content" data-service-detail-body <?php if($detailOnly): ?> wire:key="service-detail-body-<?php echo e($this->getId()); ?>-<?php echo e($detailSection); ?>" <?php endif; ?>>
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
                <div <?php if($consolidated): ?> role="tabpanel" id="<?php echo e($detailPrefix); ?>-panel-<?php echo e($detailSection); ?>" aria-labelledby="<?php echo e($detailPrefix); ?>-tab-<?php echo e($detailSection); ?>" tabindex="0" <?php endif; ?> class="rt-service-detail-section" wire:loading.class="opacity-60" wire:target="setDetailSection">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($consolidated && $detailSection === 'shifts'): ?>
                <?php if (isset($component)) { $__componentOriginalc32baf72e12f9e4f2803b0d7673ab063 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc32baf72e12f9e4f2803b0d7673ab063 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-detail-shifts','data' => ['item' => $serviceDetailItem,'detailOnly' => $detailOnly,'planningRoute' => $planningRoute]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-detail-shifts'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['item' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($serviceDetailItem),'detail-only' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailOnly),'planning-route' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($planningRoute)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc32baf72e12f9e4f2803b0d7673ab063)): ?>
<?php $attributes = $__attributesOriginalc32baf72e12f9e4f2803b0d7673ab063; ?>
<?php unset($__attributesOriginalc32baf72e12f9e4f2803b0d7673ab063); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc32baf72e12f9e4f2803b0d7673ab063)): ?>
<?php $component = $__componentOriginalc32baf72e12f9e4f2803b0d7673ab063; ?>
<?php unset($__componentOriginalc32baf72e12f9e4f2803b0d7673ab063); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($consolidated && $detailSection === 'staffing'): ?>
                <?php if (isset($component)) { $__componentOriginalb802988c75c1d42cff26179690604595 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb802988c75c1d42cff26179690604595 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-detail-staffing','data' => ['item' => $serviceDetailItem]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-detail-staffing'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['item' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($serviceDetailItem)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb802988c75c1d42cff26179690604595)): ?>
<?php $attributes = $__attributesOriginalb802988c75c1d42cff26179690604595; ?>
<?php unset($__attributesOriginalb802988c75c1d42cff26179690604595); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb802988c75c1d42cff26179690604595)): ?>
<?php $component = $__componentOriginalb802988c75c1d42cff26179690604595; ?>
<?php unset($__componentOriginalb802988c75c1d42cff26179690604595); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$consolidated || $detailSection === 'overview'): ?>
            <?php if (isset($component)) { $__componentOriginalceba3334e74522ed8dd5c5a4bfe768e3 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalceba3334e74522ed8dd5c5a4bfe768e3 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-detail-summary','data' => ['item' => $serviceDetailItem]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-detail-summary'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['item' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($serviceDetailItem)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalceba3334e74522ed8dd5c5a4bfe768e3)): ?>
<?php $attributes = $__attributesOriginalceba3334e74522ed8dd5c5a4bfe768e3; ?>
<?php unset($__attributesOriginalceba3334e74522ed8dd5c5a4bfe768e3); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalceba3334e74522ed8dd5c5a4bfe768e3)): ?>
<?php $component = $__componentOriginalceba3334e74522ed8dd5c5a4bfe768e3; ?>
<?php unset($__componentOriginalceba3334e74522ed8dd5c5a4bfe768e3); ?>
<?php endif; ?>
            <div class="rt-service-detail-workflow">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Ablauf & Freigabe</h3>
                        <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Statuswechsel mit den bestehenden Prüfungen.</p>
                    </div>
                    <div class="block w-full sm:w-56" wire:key="order-status-<?php echo e($selectedOrder->id); ?>-<?php echo e($selectedStatus); ?>">
                        <span class="mb-1 block text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">Status ändern</span>
                        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['wire:key' => 'order-status-transition-'.e($selectedStatus).'','change' => '$wire.changeStatus($event.target.value)','disabled' => empty($transitionOptions),'ariaLabel' => 'Status ändern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:key' => 'order-status-transition-'.e($selectedStatus).'','change' => '$wire.changeStatus($event.target.value)','disabled' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(empty($transitionOptions)),'aria-label' => 'Status ändern']); ?>
                            <option value="" selected disabled><?php echo e(empty($transitionOptions) ? 'Kein Folgestatus verfügbar' : 'Folgestatus auswählen'); ?></option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $transitionOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['statusChange'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen && (!$consolidated || $detailSection === 'overview') && \App\Services\Operations\SiteSnapshotService::ready()): ?>
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.routing-site-editor', ['subjectType' => 'order','subjectId' => $selectedOrder->id]);

$__key = 'routing-order-'.$selectedOrder->id;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2273952961-0', $__key);

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
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen && (!$consolidated || $detailSection === 'planning') && \App\Support\Operations\PlanningSchema::ready()): ?>
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.order-demands', ['orderId' => $selectedOrder->id]);

$__key = 'demand-'.$selectedOrder->id;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2273952961-1', $__key);

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
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOpen && (!$consolidated || $detailSection === 'overview') && \Illuminate\Support\Facades\Schema::hasTable('commercial_offer_revisions') && auth()->user()->can('operations.manage')): ?>
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.commercial-offers', ['subjectType' => 'Order','subjectId' => $selectedOrder->id]);

$__key = 'commercial-order-'.$selectedOrder->id;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2273952961-2', $__key);

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
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((!$consolidated || $detailSection === 'overview') && ($selectedOrder->description || $selectedOrder->notes)): ?>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedOrder->description): ?>
                        <div class="rounded-xl border border-rt-border/70 p-3.5 dark:border-rt-dark-border/70">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Beschreibung</p>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted"><?php echo e($selectedOrder->description); ?></p>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedOrder->notes): ?>
                        <div class="rounded-xl border border-rt-border/70 p-3.5 dark:border-rt-dark-border/70">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Interne Notiz</p>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted"><?php echo e($selectedOrder->notes); ?></p>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['mt-5 grid gap-4','lg:grid-cols-2'=>!$consolidated]); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$consolidated || (!$detailOnly && $detailSection === 'planning')): ?>
                <section>
                    <h3 class="text-sm font-semibold text-rt-text dark:text-white">Schichten</h3>
                    <div class="mt-2 divide-y divide-rt-border/60 rounded-xl border border-rt-border/70 dark:divide-rt-dark-border/60 dark:border-rt-dark-border/70">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $selectedOrder->shifts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $shift): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <?php ($shiftStatus = $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status); ?>
                            <div class="px-3.5 py-3" wire:key="order-shift-<?php echo e($shift->id); ?>">
                                <div class="flex items-center justify-between gap-2">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detailOnly): ?>
                                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','type' => 'button','xOn:click' => '$dispatch(\'close\'); $wire.detailOpen = false; $dispatch(\'operations-shift-detail-request\', { id: '.e($shift->id).' })','class' => 'min-h-11 min-w-0 text-left']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','type' => 'button','x-on:click' => '$dispatch(\'close\'); $wire.detailOpen = false; $dispatch(\'operations-shift-detail-request\', { id: '.e($shift->id).' })','class' => 'min-h-11 min-w-0 text-left']); ?><span class="break-words"><?php echo e($shift->title); ?></span> <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','dataNoNavigate' => true,'href' => route($planningRoute, ['module' => 'shift-management', 'order' => $selectedOrder->id, 'shift' => $shift->id]),'class' => 'min-h-11 min-w-0 text-left']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route($planningRoute, ['module' => 'shift-management', 'order' => $selectedOrder->id, 'shift' => $shift->id])),'class' => 'min-h-11 min-w-0 text-left']); ?><span class="break-words"><?php echo e($shift->title); ?></span> <?php echo $__env->renderComponent(); ?>
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
                                    <?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9140b74635d19149dc471db3f05482a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.status','data' => ['value' => $shiftStatus]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($shiftStatus)]); ?>
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
                                </div>
                                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted"><?php echo e($shift->starts_at?->format('d.m.Y H:i')); ?> · <?php echo e($shift->assignments->filter(fn ($assignment) => in_array(
                                    $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                                    \App\Enums\ShiftAssignmentStatus::blockingValues(),
                                    true,
                                ))->count()); ?>/<?php echo e($shift->required_staff); ?> reserviert</p>
                                <?php ($historicalPeople = $shift->assignments->filter(fn ($assignment) => $assignment->status === \App\Enums\ShiftAssignmentStatus::Reported)->map(fn ($assignment) => $assignment->user?->name)->filter()->unique()); ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($historicalPeople->isNotEmpty()): ?>
                                    <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Historisch gemeldet: <?php echo e($historicalPeople->implode(', ')); ?></p>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <p class="px-4 py-6 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Schichten angelegt.</p>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </section>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$consolidated || $detailSection === 'history'): ?>
                <section>
                    <h3 class="text-sm font-semibold text-rt-text dark:text-white">Statusverlauf</h3>
                    <div class="mt-2 space-y-2">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $selectedOrder->statusHistory->take(6); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $history): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <div class="rounded-xl border border-rt-border/70 px-3.5 py-3 dark:border-rt-dark-border/70" wire:key="order-history-<?php echo e($history->id); ?>">
                                <p class="text-xs font-semibold text-rt-text dark:text-white"><?php echo e(method_exists($history->to_status, 'label') ? $history->to_status->label() : \Illuminate\Support\Str::headline((string) ($history->to_status instanceof \BackedEnum ? $history->to_status->value : $history->to_status))); ?></p>
                                <p class="mt-1 text-[11px] text-rt-muted dark:text-rt-dark-muted"><?php echo e($history->changed_at?->format('d.m.Y H:i') ?? $history->created_at?->format('d.m.Y H:i')); ?></p>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($history->changedBy): ?><?php if (isset($component)) { $__componentOriginalbfdf91119f0bca2d3a1da565725107f1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.public-info','data' => ['user' => $history->changedBy,'size' => 6,'showPresence' => false,'class' => 'mt-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.public-info'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($history->changedBy),'size' => 6,'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'class' => 'mt-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbfdf91119f0bca2d3a1da565725107f1)): ?>
<?php $attributes = $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1; ?>
<?php unset($__attributesOriginalbfdf91119f0bca2d3a1da565725107f1); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbfdf91119f0bca2d3a1da565725107f1)): ?>
<?php $component = $__componentOriginalbfdf91119f0bca2d3a1da565725107f1; ?>
<?php unset($__componentOriginalbfdf91119f0bca2d3a1da565725107f1); ?>
<?php endif; ?><?php else: ?><span class="ops-muted">System</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($history->note): ?><p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted"><?php echo e($history->note); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <p class="rounded-xl border border-dashed border-rt-border px-4 py-6 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">Noch keine Statusänderung protokolliert.</p>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </section>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($consolidated && $detailOpen && in_array($detailSection,['proofs','costs'],true)): ?>
                <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.operations-enhancements', ['tab' => $detailSection,'orderId' => $selectedOrder->id,'initialRecordId' => $detailSection === 'proofs' ? $fulfilmentRecordId : null,'embedded' => true]);

$__key = 'order-fulfilment-'.$selectedOrder->id.'-'.$detailSection;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2273952961-3', $__key);

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
                </div>
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
                 <?php $__env->slot('note', null, []); ?> <?php echo e($selectedOrder->service_type ?: 'Leistungsart offen'); ?> · Bedarf: <?php echo e($selectedOrder->required_staff); ?> · <?php echo e($selectedOrder->priority->label()); ?> <?php $__env->endSlot(); ?>
                <nav class="rt-service-detail-links" aria-label="Verknüpfte Bereiche">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($originInquiry): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['size' => 'sm','mode' => 'link','dataNoNavigate' => true,'href' => route('operations.page',['page'=>'cases','view'=>'inbox','inquiry'=>$originInquiry->id,'customer'=>$selectedOrder->customer_id])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['size' => 'sm','mode' => 'link','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('operations.page',['page'=>'cases','view'=>'inbox','inquiry'=>$originInquiry->id,'customer'=>$selectedOrder->customer_id]))]); ?><?php echo e($originInquiry->number); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['size' => 'sm','mode' => 'link','dataNoNavigate' => true,'href' => route('operations.page',['page'=>'customers','customer'=>$selectedOrder->customer_id])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['size' => 'sm','mode' => 'link','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('operations.page',['page'=>'customers','customer'=>$selectedOrder->customer_id]))]); ?><i class="far fa-building" aria-hidden="true"></i>Kundenakte <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['size' => 'sm','mode' => 'link','dataNoNavigate' => true,'href' => route($planningRoute, ['module'=>'shift-management','order'=>$selectedOrder->id])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['size' => 'sm','mode' => 'link','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route($planningRoute, ['module'=>'shift-management','order'=>$selectedOrder->id]))]); ?><i class="far fa-table-list" aria-hidden="true"></i>Schichtplan <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['size' => 'sm','mode' => 'link','dataNoNavigate' => true,'href' => route($planningRoute, ['module'=>'calendar','order'=>$selectedOrder->id])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['size' => 'sm','mode' => 'link','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route($planningRoute, ['module'=>'calendar','order'=>$selectedOrder->id]))]); ?><i class="far fa-calendar-days" aria-hidden="true"></i>Kalender <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                </nav>
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
        <?php else: ?>
            <div class="flex min-h-80 flex-col items-center justify-center text-center">
                <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Auftrag auswählen</h2>
                <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle einen Auftrag aus, um Details und Planungsstatus zu sehen.</p>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/partials/service-details.blade.php ENDPATH**/ ?>