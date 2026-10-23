    <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Vorgang','key'=>'title','sortable'=>true], ['label'=>'Status','key'=>'status']],'sortAction' => 'tableSort','sortBy' => 'title','sortDir' => 'asc','tableKey' => 'inquiries','items' => collect([(object)['id'=>7]])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Vorgang','key'=>'title','sortable'=>true], ['label'=>'Status','key'=>'status']]),'sort-action' => 'tableSort','sort-by' => 'title','sort-dir' => 'asc','table-key' => 'inquiries','items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect([(object)['id'=>7]]))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a)): ?>
<?php $attributes = $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a; ?>
<?php unset($__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a)): ?>
<?php $component = $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a; ?>
<?php unset($__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a); ?>
<?php endif; ?><?php /**PATH C:\xampp\htdocs\RailTime\App\storage\framework\views-table-frame-20261023/1115f39ffbc6ee29b8902c26d899f0d4.blade.php ENDPATH**/ ?>