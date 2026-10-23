
<?php if (isset($component)) { $__componentOriginal7db1307087545d03fcb5b0947d4de73b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7db1307087545d03fcb5b0947d4de73b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.pagination','data' => ['paginator' => $paginator,'tableKey' => 'employee-directory','class' => 'employee-directory__pager']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.pagination'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['paginator' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($paginator),'table-key' => 'employee-directory','class' => 'employee-directory__pager']); ?>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/employees/directory-pagination.blade.php ENDPATH**/ ?>