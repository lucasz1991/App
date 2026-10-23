<?php if (isset($component)) { $__componentOriginalbaa1fc05fcc54094c9903c90e072b81f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.navigation.status-menu','data' => ['variant' => 'dropdown','options' => ['all' => 'Alle'],'value' => 'all','action' => 'invalid.action']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.navigation.status-menu'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'dropdown','options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(['all' => 'Alle']),'value' => 'all','action' => 'invalid.action']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f)): ?>
<?php $attributes = $__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f; ?>
<?php unset($__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbaa1fc05fcc54094c9903c90e072b81f)): ?>
<?php $component = $__componentOriginalbaa1fc05fcc54094c9903c90e072b81f; ?>
<?php unset($__componentOriginalbaa1fc05fcc54094c9903c90e072b81f); ?>
<?php endif; ?><?php /**PATH C:\xampp\htdocs\RailTime\App\storage\framework\views-table-frame-20261023/710fbf6a09cf53c584ef94532c310bd9.blade.php ENDPATH**/ ?>