<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $columnsMeta; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $column): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div class="rt-table-cell <?php echo e($column['key'] === 'title' ? 'rt-table-cell--primary' : ''); ?> <?php echo e($column['key'] === 'status' ? 'rt-table-cell--status' : ''); ?> <?php echo e($hideClass($column['hideOn'])); ?>" data-rt-table-label="<?php echo e($column['label']); ?>" wire:key="inquiry-<?php echo e($item->id); ?>-<?php echo e($column['key']); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php switch($column['key']):
            case ('title'): ?>
                <div class="rt-table-record">
                    <span class="rt-table-record__icon" aria-hidden="true"><i class="far fa-inbox"></i></span>
                    <div class="rt-table-record__body">
                        <span class="rt-table-record__eyebrow"><?php echo e($item->number); ?></span>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'link','class' => 'rt-table-record__title','wire:click' => 'select('.e($item->id).')','wire:loading.attr' => 'disabled','wire:target' => 'select']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'link','class' => 'rt-table-record__title','wire:click' => 'select('.e($item->id).')','wire:loading.attr' => 'disabled','wire:target' => 'select']); ?><?php echo e($item->title); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                        <span class="rt-table-record__meta"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->required_staff): ?><?php echo e($item->required_staff); ?> × <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><?php echo e($item->role_name ?: 'Tätigkeit noch offen'); ?></span>
                    </div>
                </div>
                <?php break; ?>
            <?php case ('customer'): ?>
                <div>
                    <span class="rt-table-value"><?php echo e($item->customer?->company_name ?? 'Noch nicht zugeordnet'); ?></span>
                    <span class="rt-table-meta"><?php echo e(['email' => 'E-Mail', 'phone' => 'Telefon', 'portal' => 'Portal', 'manual' => 'Manuell'][$item->channel] ?? $item->channel); ?></span>
                </div>
                <?php break; ?>
            <?php case ('schedule'): ?>
                <div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->starts_at): ?>
                        <span class="rt-table-value tabular-nums"><?php echo e($item->starts_at->format('d.m.Y')); ?></span>
                        <span class="rt-table-meta tabular-nums"><?php echo e($item->starts_at->format('H:i')); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->ends_at): ?>–<?php echo e($item->ends_at->format($item->starts_at->isSameDay($item->ends_at) ? 'H:i' : 'd.m. H:i')); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                        <span class="rt-table-meta"><?php echo e($item->timezone); ?></span>
                    <?php else: ?>
                        <span class="rt-table-meta">Termin noch offen</span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->location_name): ?><span class="rt-table-meta"><?php echo e($item->location_name); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <?php break; ?>
            <?php case ('status'): ?>
                <?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9140b74635d19149dc471db3f05482a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.status','data' => ['value' => $item->status]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->status)]); ?>
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
                <?php break; ?>
        <?php endswitch; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/rows/operations/inquiries-row.blade.php ENDPATH**/ ?>