<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($viewMode === 'orders'): ?>
    <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Anzeige','icon' => 'far fa-list-ol','for' => 'service-page-size']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Anzeige','icon' => 'far fa-list-ol','for' => 'service-page-size']); ?>
        <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'service-page-size','wire:model.live' => 'servicePerPage','ariaLabel' => 'Leistungen pro Seite']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'service-page-size','wire:model.live' => 'servicePerPage','aria-label' => 'Leistungen pro Seite']); ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = [25, 50, 100]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $size): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($size); ?>"><?php echo e($size); ?> pro Seite</option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['servicePerPage'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><span class="text-xs text-rt-red"><?php echo e($message); ?></span><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Auftrag','for' => 'shift-order-filter']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Auftrag','for' => 'shift-order-filter']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'shift-order-filter','wire:model.live' => 'orderFilter','searchModel' => 'orderSearch','ariaLabel' => 'Auftrag']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-order-filter','wire:model.live' => 'orderFilter','search-model' => 'orderSearch','aria-label' => 'Auftrag']); ?><option value="all">Alle Aufträge</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $order): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($order->id); ?>"><?php echo e($order->title); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Status','for' => 'shift-status-filter']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Status','for' => 'shift-status-filter']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'shift-status-filter','wire:model.live' => 'statusFilter','ariaLabel' => 'Schichtstatus filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-status-filter','wire:model.live' => 'statusFilter','aria-label' => 'Schichtstatus filtern']); ?><option value="all">Alle Status</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $statusOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($option['value']); ?>"><?php echo e($option['label']); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
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
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nativeOperations): ?><?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Handlungsbedarf','for' => 'shift-attention']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Handlungsbedarf','for' => 'shift-attention']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'shift-attention','wire:model.live' => 'attentionFilter','ariaLabel' => 'Handlungsbedarf']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'shift-attention','wire:model.live' => 'attentionFilter','aria-label' => 'Handlungsbedarf']); ?><option value="all">Alle Schichten</option><option value="conflicts">Besetzung mit Konflikten</option><option value="unpublished">Unveröffentlichte Änderungen</option><option value="awaiting">Rückmeldung ausstehend</option><option value="declined">Abgelehnte Dienste</option> <?php echo $__env->renderComponent(); ?>
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
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/partials/shift-filter-fields.blade.php ENDPATH**/ ?>