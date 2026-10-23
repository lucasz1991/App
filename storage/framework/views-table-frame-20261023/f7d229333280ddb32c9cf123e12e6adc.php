<?php
    $reserved = $item->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability())->count();
    $confirmed = $item->assignments->where('status', \App\Enums\ShiftAssignmentStatus::Confirmed)->count();
    $missing = max(0, $item->required_staff - $reserved);
    $closed = in_array($item->status, [\App\Enums\ShiftStatus::Completed, \App\Enums\ShiftStatus::Cancelled], true);
    $displayTimezone = (string) config('operations.display_timezone', 'Europe/Berlin');
    $displayStart = $item->starts_at?->setTimezone($displayTimezone);
    $displayEnd = $item->ends_at?->setTimezone($displayTimezone);
?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $columnsMeta; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $column): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div class="rt-table-cell <?php echo e($column['key'] === 'shift' ? 'rt-table-cell--primary' : ''); ?> <?php echo e($column['key'] === 'status' ? 'rt-table-cell--status' : ''); ?> <?php echo e($hideClass($column['hideOn'])); ?>" data-rt-table-label="<?php echo e($column['label']); ?>">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php switch($column['key']):
            case ('shift'): ?>
                <div class="rt-table-record">
                    <span class="rt-table-record__icon" aria-hidden="true"><i class="far fa-calendar-day"></i></span>
                    <div class="rt-table-record__body">
                        <span class="rt-table-record__eyebrow"><?php echo e($item->order?->order_number); ?></span>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => 'link','xOn:click' => '$dispatch(\'operations-shift-detail-request\', { id: '.e($item->id).' })','class' => 'rt-table-record__title']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => 'link','x-on:click' => '$dispatch(\'operations-shift-detail-request\', { id: '.e($item->id).' })','class' => 'rt-table-record__title']); ?><?php echo e($item->title); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                        <span class="rt-table-record__meta"><?php echo e($item->role_name); ?></span>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->order?->title && $item->order->title !== $item->title): ?><span class="rt-table-record__meta"><?php echo e($item->order->title); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
                <?php break; ?>
            <?php case ('customer'): ?>
                <div>
                    <span class="rt-table-value"><?php echo e($item->order?->customer?->company_name ?? 'Kein Kunde hinterlegt'); ?></span>
                    <span class="rt-table-meta"><?php echo e($item->location_name ?: $item->order?->location_name ?: 'Einsatzort offen'); ?></span>
                </div>
                <?php break; ?>
            <?php case ('schedule'): ?>
                <div>
                    <span class="rt-table-value tabular-nums"><?php echo e($displayStart?->format('d.m.Y H:i')); ?></span>
                    <span class="rt-table-meta tabular-nums">bis <?php echo e($displayEnd?->format('d.m.Y H:i')); ?></span>
                    <span class="rt-table-meta"><?php echo e($displayTimezone); ?></span>
                </div>
                <?php break; ?>
            <?php case ('staffing'): ?>
                <div>
                    <span class="rt-table-value tabular-nums"><?php echo e($reserved); ?> / <?php echo e($item->required_staff); ?> <span class="rt-table-label">eingeplant</span></span>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$closed && $item->required_staff > 0): ?>
                        <progress class="rt-table-coverage" max="<?php echo e($item->required_staff); ?>" value="<?php echo e(min($reserved, $item->required_staff)); ?>" aria-label="Besetzung: <?php echo e($reserved); ?> von <?php echo e($item->required_staff); ?> eingeplant"></progress>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <span class="rt-table-meta tabular-nums"><?php echo e($confirmed); ?> bestätigt <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($reserved > $confirmed): ?> · <?php echo e($reserved - $confirmed); ?> angefragt <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$closed && $missing > 0): ?><p class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-300"><?php echo e($missing); ?> <?php echo e($missing === 1 ? 'Platz offen' : 'Plätze offen'); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->planning_conflict_count): ?><p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300"><i class="far fa-triangle-exclamation" aria-hidden="true"></i> <?php echo e($item->planning_conflict_count); ?> mit Konflikt</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->planning_route_unchecked_count): ?><p class="mt-1 text-xs text-amber-700 dark:text-amber-300"><?php echo e($item->planning_route_unchecked_count); ?> Fahrt(en) ungeprüft <button type="button" wire:click.stop="loadShiftTravel(<?php echo e($item->id); ?>)" wire:loading.attr="disabled" class="underline min-h-11">Fahrten prüfen</button></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->feedback_pending): ?><span class="rt-table-meta"><?php echo e($item->feedback_pending); ?> <?php echo e($item->feedback_pending === 1 ? 'Rückmeldung offen' : 'Rückmeldungen offen'); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->feedback_declined): ?><p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300"><?php echo e($item->feedback_declined); ?> abgelehnt</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <?php break; ?>
            <?php case ('status'): ?>
                <div>
                    <?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9140b74635d19149dc471db3f05482a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.status','data' => ['value' => $item->status->value,'label' => $item->status->label()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->status->value),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->status->label())]); ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->revision !== null): ?>
                        <span class="rt-table-meta"><?php echo e($item->published_revision === $item->revision ? 'Veröffentlicht' : ($item->published_revision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht')); ?></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <?php break; ?>
        <?php endswitch; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/rows/operations/shift-plan.blade.php ENDPATH**/ ?>