<div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['contents' => $shiftId === null]); ?>" data-ai-planning-helper="<?php echo e($shiftId === null ? 'period' : 'shift'); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shiftId !== null): ?>
        <details class="rt-staffing-more">
            <summary><i class="far fa-sparkles" aria-hidden="true"></i> AI-Besetzungshilfe</summary>
            <div class="ops-stack pt-3">
                <?php echo $__env->make('livewire.operations.partials.ai-planning-proposal', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </details>
    <?php else: ?>
        <?php if (isset($component)) { $__componentOriginal3b538b67d504d6e72dbc655b66529d76 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3b538b67d504d6e72dbc655b66529d76 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.modal','data' => ['wire:model' => 'open','title' => 'AI-Besetzung für den Zeitraum','maxWidth' => '4xl']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'open','title' => 'AI-Besetzung für den Zeitraum','max-width' => '4xl']); ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($open): ?>
                <p class="ops-muted"><?php echo e(\Carbon\CarbonImmutable::parse($from)->format('d.m.Y')); ?> – <?php echo e(\Carbon\CarbonImmutable::parse($until)->format('d.m.Y')); ?> · bis zu 24 offene Schichten</p>
                <?php echo $__env->make('livewire.operations.partials.ai-planning-proposal', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3b538b67d504d6e72dbc655b66529d76)): ?>
<?php $attributes = $__attributesOriginal3b538b67d504d6e72dbc655b66529d76; ?>
<?php unset($__attributesOriginal3b538b67d504d6e72dbc655b66529d76); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3b538b67d504d6e72dbc655b66529d76)): ?>
<?php $component = $__componentOriginal3b538b67d504d6e72dbc655b66529d76; ?>
<?php unset($__componentOriginal3b538b67d504d6e72dbc655b66529d76); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/ai-planning-helper.blade.php ENDPATH**/ ?>