<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $columnsMeta; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $column): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<div class="rt-table-cell <?php echo e($column['key'] === 'name' ? 'rt-table-cell--primary' : ''); ?>" data-rt-table-label="<?php echo e($column['label']); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($column['key'] === 'name'): ?>
        <div class="min-w-0"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->user): ?><?php if (isset($component)) { $__componentOriginalc07b0998277cb6d4345f54b3047a566a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc07b0998277cb6d4345f54b3047a566a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.person-anchor-preview','data' => ['user' => $item->user,'showPresence' => false,'showEmail' => false,'size' => 8]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.person-anchor-preview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->user),'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'show-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'size' => 8]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc07b0998277cb6d4345f54b3047a566a)): ?>
<?php $attributes = $__attributesOriginalc07b0998277cb6d4345f54b3047a566a; ?>
<?php unset($__attributesOriginalc07b0998277cb6d4345f54b3047a566a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc07b0998277cb6d4345f54b3047a566a)): ?>
<?php $component = $__componentOriginalc07b0998277cb6d4345f54b3047a566a; ?>
<?php unset($__componentOriginalc07b0998277cb6d4345f54b3047a566a); ?>
<?php endif; ?><?php else: ?><span>Mitarbeiter nicht verfügbar</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->exception_review): ?><details class="rt-staffing-reasons rt-staffing-reasons--warning"><summary><i class="far fa-shield-exclamation" aria-hidden="true"></i> Ausnahme dokumentiert</summary><p><?php echo e($item->exception_review->data['reason'] ?? ''); ?></p><p><?php echo e($item->exception_review->actor?->name ?? 'Ehemalige Verwaltung'); ?> · <?php echo e($item->exception_review->created_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i')); ?> · Revision <?php echo e($item->exception_review->data['plan_revision'] ?? '–'); ?></p><p>Historischer Prüfstand; Änderungen werden erneut geprüft.</p></details><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($item->planning_issues)): ?><details class="rt-staffing-reasons rt-staffing-reasons--warning"><summary><?php echo e(count($item->planning_issues)); ?> Planungshinweis(e)</summary><ul><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $item->planning_issues; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $issue): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($issue['message']); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></ul><p>Eine dokumentierte Ausnahme hebt diese Hinweise nicht auf.</p><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->exception_review): ?><p>Ist der Prüfstand inzwischen verändert, entfernen Sie die Zuweisung und prüfen Sie sie bei erneuter Auswahl vollständig neu.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></details><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php elseif($column['key'] === 'response'): ?>
        <div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->planning_travel): ?><span class="rt-table-meta" data-routing-state="<?php echo e($item->planning_travel['route_state']); ?>"><?php echo e($item->planning_travel['route_label']); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $item->planning_travel['warnings'] ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $warning): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><span class="rt-table-meta text-amber-700 dark:text-amber-300"><?php echo e($warning['message']); ?></span><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->status->blocksAvailability() && ($item->planning_travel['route_state'] ?? 'disabled') !== 'disabled'): ?><button type="button" class="underline min-h-11" wire:click="inspectAssignedTravel(<?php echo e($item->shift_id); ?>, <?php echo e($item->user_id); ?>)" wire:loading.attr="disabled">Fahrten prüfen</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->plan_revision > 0 && $item->plan_revision === $item->shift->published_revision): ?>
                <span class="rt-table-meta">Revision <?php echo e($item->plan_revision); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->responded_at): ?> · <?php echo e($item->responded_at->setTimezone($displayTimezone ?? config('operations.display_timezone'))->format('d.m. H:i')); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                <span class="rt-table-meta"><i class="far <?php echo e($item->plan_opened_at ? 'fa-eye' : 'fa-envelope'); ?>" aria-hidden="true"></i> <?php echo e($item->plan_opened_at ? 'Geöffnet am '.$item->plan_opened_at->setTimezone(config('operations.display_timezone'))->format('d.m. H:i') : 'Noch nicht geöffnet'); ?></span>
            <?php else: ?><span class="rt-table-meta">Aktueller Stand noch nicht veröffentlicht</span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php else: ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->status->blocksAvailability()): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','wire:click' => 'removeAssignment('.e($item->id).')','wire:confirm' => 'Zuweisung wirklich entfernen?','wire:loading.attr' => 'disabled','ariaLabel' => $item->user?->name.' aus der Schicht entfernen','title' => 'Zuweisung entfernen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','wire:click' => 'removeAssignment('.e($item->id).')','wire:confirm' => 'Zuweisung wirklich entfernen?','wire:loading.attr' => 'disabled','aria-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($item->user?->name.' aus der Schicht entfernen'),'title' => 'Zuweisung entfernen']); ?><i class="far fa-user-minus" aria-hidden="true"></i> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/tables/rows/operations/staffing-feedback.blade.php ENDPATH**/ ?>