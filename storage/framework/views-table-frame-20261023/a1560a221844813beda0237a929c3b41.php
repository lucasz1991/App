<div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rt-disposition rt-disposition--orders', 'space-y-6' => ! $embeddedDetail]); ?>" data-operations-orders
    <?php if (! ($embeddedDetail)): ?> data-service-detail-controller data-service-detail-panel-owner="<?php echo e($this->getId()); ?>" x-data="rtServiceDetailPanel({ mode: 'orders' })" <?php endif; ?>>
    <?php
        $statusFilterIcons = [
            'all' => 'far fa-list text-rt-muted dark:text-rt-dark-muted',
            'requested' => 'far fa-inbox text-amber-600 dark:text-amber-400',
            'confirmed' => 'far fa-check-circle text-emerald-600 dark:text-emerald-400',
            'planned' => 'far fa-calendar-check text-amber-600 dark:text-amber-400',
            'in_progress' => 'far fa-spinner text-sky-600 dark:text-sky-400',
            'completed' => 'far fa-check-double text-emerald-600 dark:text-emerald-400',
            'invoiced' => 'far fa-file-invoice text-emerald-600 dark:text-emerald-400',
            'cancelled' => 'far fa-ban text-slate-500 dark:text-slate-400',
        ];
        $boundedOrdersList = $consolidated && ! $profileEmbedded && ! $detailOnly;
    ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($detailOnly)): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($boundedOrdersList): ?>
        <template x-teleport="[data-topbar-page-search]">
            <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','resultsCount' => $orders->count(),'placeholder' => 'Leistungen suchen','ariaLabel' => 'Leistungen suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','results-count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orders->count()),'placeholder' => 'Leistungen suchen','aria-label' => 'Leistungen suchen']); ?>
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
        <template x-teleport="[data-page-header-search]">
            <div class="rt-orders-list-controls" data-orders-list-controls>
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
            </div>
        </template>
        <template x-teleport="[data-page-header-actions]">
            <div class="rt-orders-table-filters" data-orders-table-filters>
                <?php echo $__env->make('livewire.admin.operations.partials.orders-period-trigger', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <?php echo $__env->make('livewire.admin.operations.partials.orders-filter-trigger', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </template>
    <?php else: ?>
        <div class="rt-orders-list-controls" data-orders-list-controls>
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
            <?php echo $__env->make('livewire.admin.operations.partials.orders-filter-trigger', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <section class="rt-orders-list" aria-label="<?php echo e(__('pagination.orders_list')); ?>" <?php if($boundedOrdersList): ?> data-orders-list-scroll <?php endif; ?>>
        <?php if (isset($component)) { $__componentOriginal0b23ee146476c0e42abe7223c133c957 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b23ee146476c0e42abe7223c133c957 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-table','data' => ['items' => $orderTableItems,'sortAction' => 'tableSort','sortBy' => $sortBy,'sortDir' => $sortDir,'tableKey' => 'orders','dataTablePaginationKey' => 'orders','scrollBody' => $boundedOrdersList,'selectedItems' => [$selectedOrderId],'bulkSelectedItems' => $selectedServiceRows,'bulkSelectionAction' => 'toggleServiceSelection','selectionAction' => 'selectOrder','detailAction' => 'openDetails','label' => 'Aufträge und Leistungen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderTableItems),'sort-action' => 'tableSort','sort-by' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortBy),'sort-dir' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortDir),'table-key' => 'orders','data-table-pagination-key' => 'orders','scroll-body' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($boundedOrdersList),'selected-items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([$selectedOrderId]),'bulk-selected-items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedServiceRows),'bulk-selection-action' => 'toggleServiceSelection','selection-action' => 'selectOrder','detail-action' => 'openDetails','label' => 'Aufträge und Leistungen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0b23ee146476c0e42abe7223c133c957)): ?>
<?php $attributes = $__attributesOriginal0b23ee146476c0e42abe7223c133c957; ?>
<?php unset($__attributesOriginal0b23ee146476c0e42abe7223c133c957); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0b23ee146476c0e42abe7223c133c957)): ?>
<?php $component = $__componentOriginal0b23ee146476c0e42abe7223c133c957; ?>
<?php unset($__componentOriginal0b23ee146476c0e42abe7223c133c957); ?>
<?php endif; ?>
        <div class="rt-service-pagination" data-orders-pagination>
            <?php if (isset($component)) { $__componentOriginal7db1307087545d03fcb5b0947d4de73b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7db1307087545d03fcb5b0947d4de73b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.pagination','data' => ['paginator' => $orders,'tableKey' => 'orders','label' => 'Auftragsseiten']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.pagination'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['paginator' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orders),'table-key' => 'orders','label' => 'Auftragsseiten']); ?>
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
    </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($embeddedDetail): ?>
        <div class="rt-service-detail-content" data-service-detail-loaded="<?php echo e($selectedOrder?->id); ?>">
            <?php echo $__env->make('livewire.admin.operations.partials.service-details', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        </div>
    <?php else: ?>
        <?php if (isset($component)) { $__componentOriginalee5b1959550127dcd3c5272f16dd7b79 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalee5b1959550127dcd3c5272f16dd7b79 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel','data' => ['id' => 'order-detail-panel-'.$this->getId(),'label' => 'Leistungsdetails','showExpression' => 'panelVisible']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('order-detail-panel-'.$this->getId()),'label' => 'Leistungsdetails','show-expression' => 'panelVisible']); ?>
            <?php if (isset($component)) { $__componentOriginal54636f4dd36006617e1aa12912b55a4a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal54636f4dd36006617e1aa12912b55a4a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-detail-shell','data' => ['ownerId' => $this->getId()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-detail-shell'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['owner-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($this->getId())]); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedOrder): ?>
                    <div class="rt-service-detail-content" data-service-detail-loaded="<?php echo e($selectedOrder->id); ?>">
                        <?php echo $__env->make('livewire.admin.operations.partials.service-details', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal54636f4dd36006617e1aa12912b55a4a)): ?>
<?php $attributes = $__attributesOriginal54636f4dd36006617e1aa12912b55a4a; ?>
<?php unset($__attributesOriginal54636f4dd36006617e1aa12912b55a4a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal54636f4dd36006617e1aa12912b55a4a)): ?>
<?php $component = $__componentOriginal54636f4dd36006617e1aa12912b55a4a; ?>
<?php unset($__componentOriginal54636f4dd36006617e1aa12912b55a4a); ?>
<?php endif; ?>
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
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if (isset($component)) { $__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dialog-modal','data' => ['wire:model' => 'formOpen','maxWidth' => '4xl']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dialog-modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'formOpen','maxWidth' => '4xl']); ?>
         <?php $__env->slot('title', null, []); ?> <?php echo e($editingOrderId ? 'Auftrag bearbeiten' : 'Neuen Auftrag anlegen'); ?> <?php $__env->endSlot(); ?>
         <?php $__env->slot('content', null, []); ?> 
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-customer','value' => 'Kunde']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-customer','value' => 'Kunde']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'order-customer','wire:model' => 'customerId','class' => 'mt-1','placeholder' => 'Kunde auswählen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-customer','wire:model' => 'customerId','class' => 'mt-1','placeholder' => 'Kunde auswählen']); ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $customers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $customer): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($customer->id); ?>"><?php echo e($customer->customer_number); ?> · <?php echo e($customer->company_name); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['customerId'];
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-service-type','value' => 'Leistungsart']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-service-type','value' => 'Leistungsart']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'order-service-type','wire:model' => 'serviceType','class' => 'mt-1','placeholder' => 'z. B. Zugbegleitung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-service-type','wire:model' => 'serviceType','class' => 'mt-1','placeholder' => 'z. B. Zugbegleitung']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['serviceType'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="sm:col-span-2">
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-title','value' => 'Auftragstitel']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-title','value' => 'Auftragstitel']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'order-title','wire:model' => 'title','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-title','wire:model' => 'title','class' => 'mt-1']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-start','value' => 'Beginn']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-start','value' => 'Beginn']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-time-field','data' => ['id' => 'order-start','wire:model' => 'startsAt','ariaLabel' => 'Beginn ('.$timezone.')','class' => 'mt-1','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-time-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-start','wire:model' => 'startsAt','aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('Beginn ('.$timezone.')'),'class' => 'mt-1','required' => true]); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-end','value' => 'Ende']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-end','value' => 'Ende']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.date-time-field','data' => ['id' => 'order-end','wire:model' => 'endsAt','ariaLabel' => 'Ende ('.$timezone.')','class' => 'mt-1','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.date-time-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-end','wire:model' => 'endsAt','aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('Ende ('.$timezone.')'),'class' => 'mt-1','required' => true]); ?>
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
                <div>
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-priority','value' => 'Priorität']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-priority','value' => 'Priorität']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'order-priority','wire:model' => 'priority','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-priority','wire:model' => 'priority','class' => 'mt-1']); ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $priorityOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['priority'];
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-required-staff','value' => 'Benötigte Mitarbeitende']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-required-staff','value' => 'Benötigte Mitarbeitende']); ?>
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
                    <?php if (isset($component)) { $__componentOriginalf93e6b313b87342ec474caffc76d3c54 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf93e6b313b87342ec474caffc76d3c54 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.number-input','data' => ['id' => 'order-required-staff','min' => '1','max' => '999','nullable' => false,'wire:model' => 'requiredStaff']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.number-input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-required-staff','min' => '1','max' => '999','nullable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'wire:model' => 'requiredStaff']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf93e6b313b87342ec474caffc76d3c54)): ?>
<?php $attributes = $__attributesOriginalf93e6b313b87342ec474caffc76d3c54; ?>
<?php unset($__attributesOriginalf93e6b313b87342ec474caffc76d3c54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf93e6b313b87342ec474caffc76d3c54)): ?>
<?php $component = $__componentOriginalf93e6b313b87342ec474caffc76d3c54; ?>
<?php unset($__componentOriginalf93e6b313b87342ec474caffc76d3c54); ?>
<?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['requiredStaff'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="sm:col-span-2">
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-location-name','value' => 'Einsatzort / Treffpunkt']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-location-name','value' => 'Einsatzort / Treffpunkt']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'order-location-name','wire:model' => 'locationName','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-location-name','wire:model' => 'locationName','class' => 'mt-1']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['locationName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="sm:col-span-2">
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-address','value' => 'Straße und Hausnummer']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-address','value' => 'Straße und Hausnummer']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'order-address','wire:model' => 'address','class' => 'mt-1','autocomplete' => 'street-address']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-address','wire:model' => 'address','class' => 'mt-1','autocomplete' => 'street-address']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['address'];
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-postal-code','value' => 'Postleitzahl']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-postal-code','value' => 'Postleitzahl']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'order-postal-code','wire:model' => 'postalCode','class' => 'mt-1','autocomplete' => 'postal-code']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-postal-code','wire:model' => 'postalCode','class' => 'mt-1','autocomplete' => 'postal-code']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['postalCode'];
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-city','value' => 'Ort']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-city','value' => 'Ort']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'order-city','wire:model' => 'city','class' => 'mt-1','autocomplete' => 'address-level2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-city','wire:model' => 'city','class' => 'mt-1','autocomplete' => 'address-level2']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['city'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="sm:col-span-2">
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-country','value' => 'Land']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-country','value' => 'Land']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'order-country','wire:model' => 'country','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-country','wire:model' => 'country','class' => 'mt-1']); ?>
                        <option value="DE">Deutschland</option>
                        <option value="AT">Österreich</option>
                        <option value="CH">Schweiz</option>
                        <option value="NL">Niederlande</option>
                        <option value="PL">Polen</option>
                        <option value="CZ">Tschechien</option>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['country'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="sm:col-span-2">
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-description','value' => 'Beschreibung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-description','value' => 'Beschreibung']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.textarea','data' => ['id' => 'order-description','wire:model' => 'description','rows' => '3','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-description','wire:model' => 'description','rows' => '3','class' => 'mt-1']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['description'];
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-requirements','value' => 'Anforderungen (eine pro Zeile)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-requirements','value' => 'Anforderungen (eine pro Zeile)']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.textarea','data' => ['id' => 'order-requirements','wire:model' => 'requirementsText','rows' => '4','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-requirements','wire:model' => 'requirementsText','rows' => '4','class' => 'mt-1']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['requirementsText'];
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'order-notes','value' => 'Interne Notizen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'order-notes','value' => 'Interne Notizen']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.textarea','data' => ['id' => 'order-notes','wire:model' => 'notes','rows' => '4','class' => 'mt-1']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-notes','wire:model' => 'notes','rows' => '4','class' => 'mt-1']); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['notes'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
         <?php $__env->endSlot(); ?>
         <?php $__env->slot('footer', null, []); ?> 
            <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','xOn:click' => '$dispatch(\'close\')','class' => 'inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','x-on:click' => '$dispatch(\'close\')','class' => 'inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white']); ?>Abbrechen <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','wire:click' => 'saveOrder','wire:loading.attr' => 'disabled','class' => 'inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','wire:click' => 'saveOrder','wire:loading.attr' => 'disabled','class' => 'inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60']); ?>
                <i wire:loading.remove wire:target="saveOrder" class="far fa-check" aria-hidden="true"></i>
                <i wire:loading wire:target="saveOrder" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
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
         <?php $__env->endSlot(); ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f)): ?>
<?php $attributes = $__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f; ?>
<?php unset($__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f)): ?>
<?php $component = $__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f; ?>
<?php unset($__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f); ?>
<?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/orders.blade.php ENDPATH**/ ?>