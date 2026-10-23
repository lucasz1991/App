<?php if (isset($component)) { $__componentOriginal7db1307087545d03fcb5b0947d4de73b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7db1307087545d03fcb5b0947d4de73b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.pagination','data' => ['paginator' => $pages,'label' => 'Leistungsseiten','tableKey' => 'service-overview']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.pagination'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['paginator' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($pages),'label' => 'Leistungsseiten','table-key' => 'service-overview']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7db1307087545d03fcb5b0947d4de73b)): ?>
<?php $attributes = $__attributesOriginal7db1307087545d03fcb5b0947d4de73b; ?>
<?php unset($__attributesOriginal7db1307087545d03fcb5b0947d4de73b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7db1307087545d03fcb5b0947d4de73b)): ?>
<?php $component = $__componentOriginal7db1307087545d03fcb5b0947d4de73b; ?>
<?php unset($__componentOriginal7db1307087545d03fcb5b0947d4de73b); ?>
<?php endif; ?><?php /**PATH C:\xampp\htdocs\RailTime\App\storage\framework\views-table-frame-20261023/3de99e3a2105d8ed233fabb9e0059aa4.blade.php ENDPATH**/ ?>