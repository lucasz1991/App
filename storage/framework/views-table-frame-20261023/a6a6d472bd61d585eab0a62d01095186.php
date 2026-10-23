<?php ($ordersFilterCount = (int) ($statusFilter !== 'all') + (int) (trim($search) !== '')); ?>
<?php if (isset($component)) { $__componentOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb4e2b4a4527c8eaa7f4a64f0ca218492 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.dropdown.anchor-dropdown','data' => ['width' => '80','align' => 'right','dropdownId' => 'orders-filters-'.e($this->getId()).'','contentRole' => 'dialog','contentLabel' => 'Aufträge filtern','layerGroup' => 'operations-orders','contentClasses' => 'rt-service-filter-panel p-4 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.dropdown.anchor-dropdown'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['width' => '80','align' => 'right','dropdown-id' => 'orders-filters-'.e($this->getId()).'','content-role' => 'dialog','content-label' => 'Aufträge filtern','layer-group' => 'operations-orders','content-classes' => 'rt-service-filter-panel p-4 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text']); ?>
     <?php $__env->slot('trigger', null, []); ?> 
        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'secondary','type' => 'button','class' => 'rt-service-filter-trigger','ariaLabel' => 'Aufträge filtern','title' => 'Aufträge filtern','dataOrdersFilterTrigger' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'secondary','type' => 'button','class' => 'rt-service-filter-trigger','aria-label' => 'Aufträge filtern','title' => 'Aufträge filtern','data-orders-filter-trigger' => true]); ?>
            <i class="far fa-sliders-h" aria-hidden="true"></i>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ordersFilterCount > 0): ?><span class="rt-service-filter-count" aria-label="<?php echo e($ordersFilterCount); ?> aktive Filter"><?php echo e($ordersFilterCount); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
        <h2 class="mb-3 text-sm font-semibold">Aufträge filtern</h2>
        <div class="rt-service-filter-fields" data-orders-filter-fields>
            <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Suche','for' => 'orders-filter-search-'.e($this->getId()).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Suche','for' => 'orders-filter-search-'.e($this->getId()).'']); ?>
                <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'table','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Leistungen suchen','ariaLabel' => 'Leistungen suchen','id' => 'orders-filter-search-'.e($this->getId()).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'table','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Leistungen suchen','aria-label' => 'Leistungen suchen','id' => 'orders-filter-search-'.e($this->getId()).'']); ?>
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
             <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Leistungsstatus','for' => 'order-status-filter-'.e($this->getId()).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Leistungsstatus','for' => 'order-status-filter-'.e($this->getId()).'']); ?>
                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'order-status-filter-'.e($this->getId()).'','wire:model.live' => 'statusFilter','ariaLabel' => 'Leistungsstatus']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'order-status-filter-'.e($this->getId()).'','wire:model.live' => 'statusFilter','aria-label' => 'Leistungsstatus']); ?>
                    <option value="all" data-icon-class="<?php echo e($statusFilterIcons['all']); ?>">Alle Leistungen</option>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $statusOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>" data-icon-class="<?php echo e($statusFilterIcons[$option['value']] ?? 'far fa-circle text-rt-muted dark:text-rt-dark-muted'); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
             <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Anzeigeanzahl','for' => 'orders-per-page-'.e($this->getId()).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Anzeigeanzahl','for' => 'orders-per-page-'.e($this->getId()).'']); ?>
                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'orders-per-page-'.e($this->getId()).'','wire:model.live' => 'perPage','ariaLabel' => 'Leistungen pro Seite']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'orders-per-page-'.e($this->getId()).'','wire:model.live' => 'perPage','aria-label' => 'Leistungen pro Seite']); ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = [25, 50, 100]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $size): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($size); ?>" data-icon-class="far fa-list"><?php echo e($size); ?> pro Seite</option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $attributes = $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32)): ?>
<?php $component = $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32; ?>
<?php unset($__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32); ?>
<?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($ordersFilterCount > 0): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','size' => 'sm','wire:click' => 'resetFilters','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','size' => 'sm','wire:click' => 'resetFilters','wire:loading.attr' => 'disabled']); ?><i class="far fa-rotate-left" aria-hidden="true"></i>Filter zurücksetzen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/partials/orders-filter-trigger.blade.php ENDPATH**/ ?>