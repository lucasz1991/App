<?php
    $previewPrefix = $tableKey === 'service-overview' ? 'service' : $tableKey;
    $isOrderRow = isset($item->requiredStaff);
?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $columnsMeta; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $column): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div class="rt-table-cell <?php echo e($column['key'] === 'selection' ? 'rt-service-selection-cell' : ''); ?> <?php echo e($column['key'] === 'service' ? 'rt-table-cell--primary' : ''); ?> <?php echo e($column['key'] === 'status' ? 'rt-table-cell--status' : ''); ?> <?php echo e(in_array($column['key'], ['customer', 'staffing', 'period', 'location', 'count'], true) ? 'rt-table-cell--service-staffing' : ''); ?> <?php echo e($hideClass($column['hideOn'])); ?>" <?php if($column['key'] === 'selection'): ?> data-service-selection-column data-table-row-ignore <?php else: ?> data-service-column="<?php echo e($column['key']); ?>" <?php endif; ?> data-rt-table-label="<?php echo e($column['label']); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php switch($column['key']):
            case ('selection'): ?>
                <?php if (isset($component)) { $__componentOriginal73478c8be7272bff1d507d0522fd3d44 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal73478c8be7272bff1d507d0522fd3d44 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.checkbox','data' => ['id' => $tableKey.'-select-'.$item->id,'checked' => $item->bulkSelected,'xBind:checked' => '$wire.selectedServiceRows.includes('.e((int) $item->id).')','wire:click' => ''.e($item->bulkSelectionAction).'('.e($item->id).')','wire:loading.attr' => 'disabled','wire:target' => ''.e($item->bulkSelectionAction).',toggleSelectAllServices,clearServiceSelection','ariaLabel' => 'Leistung auswählen: '.($item->order?->title ?: $item->id),'dataServiceRowCheckbox' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.checkbox'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($tableKey.'-select-'.$item->id),'checked' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->bulkSelected),'x-bind:checked' => '$wire.selectedServiceRows.includes('.e((int) $item->id).')','wire:click' => ''.e($item->bulkSelectionAction).'('.e($item->id).')','wire:loading.attr' => 'disabled','wire:target' => ''.e($item->bulkSelectionAction).',toggleSelectAllServices,clearServiceSelection','aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('Leistung auswählen: '.($item->order?->title ?: $item->id)),'data-service-row-checkbox' => true]); ?>
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
                <?php break; ?>
            <?php case ('service'): ?>
                <div class="rt-table-record">
                    <div class="rt-table-record__body">
                        <span class="rt-table-record__eyebrow"><?php echo e($item->order?->order_number); ?></span>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->order): ?>
                            <button type="button" wire:click="<?php echo e($safeDetailAction); ?>(<?php echo e($item->id); ?>)" wire:loading.attr="disabled" wire:target="<?php echo e($safeDetailAction); ?>" class="rt-table-record__title text-left" aria-label="Leistungsdetails: <?php echo e($item->order->title); ?>"><?php echo e($item->order->title); ?></button>
                        <?php else: ?>
                            <span class="rt-table-value">Leistung nicht verfügbar</span>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
                <?php break; ?>
            <?php case ('customer'): ?>
                <?php if (isset($component)) { $__componentOriginalaa9d3eb66282399de5bfcbb5e88c013c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalaa9d3eb66282399de5bfcbb5e88c013c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-customer-preview','data' => ['customer' => $item->order?->customer,'previewId' => $previewPrefix.'-customer-'.$item->id]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-customer-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['customer' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->order?->customer),'preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewPrefix.'-customer-'.$item->id)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalaa9d3eb66282399de5bfcbb5e88c013c)): ?>
<?php $attributes = $__attributesOriginalaa9d3eb66282399de5bfcbb5e88c013c; ?>
<?php unset($__attributesOriginalaa9d3eb66282399de5bfcbb5e88c013c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalaa9d3eb66282399de5bfcbb5e88c013c)): ?>
<?php $component = $__componentOriginalaa9d3eb66282399de5bfcbb5e88c013c; ?>
<?php unset($__componentOriginalaa9d3eb66282399de5bfcbb5e88c013c); ?>
<?php endif; ?>
                <?php break; ?>
            <?php case ('period'): ?>
                <?php if (isset($component)) { $__componentOriginal9b53ef04c07b9ec7ea00dadadd8c8cba = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9b53ef04c07b9ec7ea00dadadd8c8cba = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-period-preview','data' => ['start' => $item->from,'end' => $item->until,'previewId' => $previewPrefix.'-period-'.$item->id]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-period-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['start' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->from),'end' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->until),'preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewPrefix.'-period-'.$item->id)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9b53ef04c07b9ec7ea00dadadd8c8cba)): ?>
<?php $attributes = $__attributesOriginal9b53ef04c07b9ec7ea00dadadd8c8cba; ?>
<?php unset($__attributesOriginal9b53ef04c07b9ec7ea00dadadd8c8cba); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9b53ef04c07b9ec7ea00dadadd8c8cba)): ?>
<?php $component = $__componentOriginal9b53ef04c07b9ec7ea00dadadd8c8cba; ?>
<?php unset($__componentOriginal9b53ef04c07b9ec7ea00dadadd8c8cba); ?>
<?php endif; ?>
                <?php break; ?>
            <?php case ('location'): ?>
                <?php if (isset($component)) { $__componentOriginal9f0148c638a30a8dfc24d7dd50d085e6 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9f0148c638a30a8dfc24d7dd50d085e6 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-location-preview','data' => ['order' => $item->order,'previewId' => $previewPrefix.'-location-'.$item->id]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-location-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['order' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->order),'preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewPrefix.'-location-'.$item->id)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9f0148c638a30a8dfc24d7dd50d085e6)): ?>
<?php $attributes = $__attributesOriginal9f0148c638a30a8dfc24d7dd50d085e6; ?>
<?php unset($__attributesOriginal9f0148c638a30a8dfc24d7dd50d085e6); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9f0148c638a30a8dfc24d7dd50d085e6)): ?>
<?php $component = $__componentOriginal9f0148c638a30a8dfc24d7dd50d085e6; ?>
<?php unset($__componentOriginal9f0148c638a30a8dfc24d7dd50d085e6); ?>
<?php endif; ?>
                <?php break; ?>
            <?php case ('count'): ?>
                <?php if (isset($component)) { $__componentOriginal673a76c9db5bc292a0628bb73ea9418c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal673a76c9db5bc292a0628bb73ea9418c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-shifts-preview','data' => ['item' => $item,'timezone' => $item->timezone,'previewId' => $previewPrefix.'-shifts-'.$item->id,'detailAction' => $isOrderRow ? $safeDetailAction : null,'periodLabel' => $isOrderRow ? 'Gesamter Auftrag' : 'Im Zeitraum','requiredStaff' => $isOrderRow ? $item->requiredStaff : null]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-shifts-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['item' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item),'timezone' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->timezone),'preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewPrefix.'-shifts-'.$item->id),'detail-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($isOrderRow ? $safeDetailAction : null),'period-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($isOrderRow ? 'Gesamter Auftrag' : 'Im Zeitraum'),'required-staff' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($isOrderRow ? $item->requiredStaff : null)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal673a76c9db5bc292a0628bb73ea9418c)): ?>
<?php $attributes = $__attributesOriginal673a76c9db5bc292a0628bb73ea9418c; ?>
<?php unset($__attributesOriginal673a76c9db5bc292a0628bb73ea9418c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal673a76c9db5bc292a0628bb73ea9418c)): ?>
<?php $component = $__componentOriginal673a76c9db5bc292a0628bb73ea9418c; ?>
<?php unset($__componentOriginal673a76c9db5bc292a0628bb73ea9418c); ?>
<?php endif; ?>
                <?php break; ?>
            <?php case ('staffing'): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isOrderRow && $item->count === 0): ?>
                    <span class="rt-table-meta" data-order-staffing-unplanned>Noch keine Schichten</span>
                <?php elseif($isOrderRow && $item->summary['shifts'] === 0): ?>
                    <span class="rt-table-meta" data-order-staffing-history>Keine aktiven Schichten</span>
                <?php else: ?>
                    <?php if (isset($component)) { $__componentOriginal171f89cfebb94010f346c6fe426e5a2d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal171f89cfebb94010f346c6fe426e5a2d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-staffing','data' => ['seats' => $item->staffingSeats,'summary' => $item->summary,'timezone' => $item->timezone,'previewId' => $previewPrefix.'-staffing-'.$item->id]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-staffing'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['seats' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->staffingSeats),'summary' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->summary),'timezone' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->timezone),'preview-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($previewPrefix.'-staffing-'.$item->id)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal171f89cfebb94010f346c6fe426e5a2d)): ?>
<?php $attributes = $__attributesOriginal171f89cfebb94010f346c6fe426e5a2d; ?>
<?php unset($__attributesOriginal171f89cfebb94010f346c6fe426e5a2d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal171f89cfebb94010f346c6fe426e5a2d)): ?>
<?php $component = $__componentOriginal171f89cfebb94010f346c6fe426e5a2d; ?>
<?php unset($__componentOriginal171f89cfebb94010f346c6fe426e5a2d); ?>
<?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php break; ?>
            <?php case ('status'): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->order): ?><?php if (isset($component)) { $__componentOriginalda64f642c383ba8bc276b70e81e47b77 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalda64f642c383ba8bc276b70e81e47b77 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-status','data' => ['status' => $item->order->status]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->order->status)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalda64f642c383ba8bc276b70e81e47b77)): ?>
<?php $attributes = $__attributesOriginalda64f642c383ba8bc276b70e81e47b77; ?>
<?php unset($__attributesOriginalda64f642c383ba8bc276b70e81e47b77); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalda64f642c383ba8bc276b70e81e47b77)): ?>
<?php $component = $__componentOriginalda64f642c383ba8bc276b70e81e47b77; ?>
<?php unset($__componentOriginalda64f642c383ba8bc276b70e81e47b77); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php break; ?>
        <?php endswitch; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/rows/operations/service-overview.blade.php ENDPATH**/ ?>