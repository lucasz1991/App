<div
    x-data="{ showModal: <?php if ((object) ('showMailModal') instanceof \Livewire\WireDirective) : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('showMailModal'->value()); ?>')<?php echo e('showMailModal'->hasModifier('live') ? '.live' : ''); ?><?php else : ?>window.Livewire.find('<?php echo e($__livewire->getId()); ?>').entangle('<?php echo e('showMailModal'); ?>')<?php endif; ?> }"
    x-init="$watch('showModal', value => { if (!value) { $wire.handleModalClosed(); } })"
>
    <?php if (isset($component)) { $__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dialog-modal','data' => ['wire:model' => 'showMailModal']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dialog-modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'showMailModal']); ?>
         <?php $__env->slot('title', null, []); ?> 
            <?php echo e(__('app.compose_message')); ?>


            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mailUserId): ?>
                <span class="mt-1 block text-sm text-rt-muted dark:text-rt-dark-muted">
                    <?php echo e(__('app.to_recipient', ['recipient' => App\Models\User::find($mailUserId)?->email ?? __('app.user_not_found')])); ?>

                </span>
            <?php elseif(! empty($directRecipients)): ?>
                <span class="mt-1 block text-sm text-rt-muted dark:text-rt-dark-muted">
                    <?php echo e(__('app.to_recipient', ['recipient' => implode(', ', $directRecipients)])); ?>

                </span>
            <?php else: ?>
                <span class="mt-1 block text-sm text-rt-muted dark:text-rt-dark-muted">
                    <?php echo e(__('app.send_to_x_users', ['count' => count($selectedUsers)])); ?>

                </span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
         <?php $__env->endSlot(); ?>

         <?php $__env->slot('content', null, []); ?> 
            <div class="mb-4 rounded-lg border-l-4 border-yellow-500 bg-yellow-50 p-4 text-yellow-800 dark:border-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-300" role="alert">
                <p class="font-bold"><?php echo e(__('app.important_notice')); ?></p>
                <p><?php echo e(__('app.compose_check_hint')); ?></p>
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($forceMailOnly): ?>
                <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                    <?php echo e(__('app.free_mail_only_hint')); ?>

                </div>
            <?php else: ?>
                <div class="mt-4 flex items-center rounded-xl bg-rt-surface-muted px-4 py-3 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                    <?php if (isset($component)) { $__componentOriginal73478c8be7272bff1d507d0522fd3d44 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal73478c8be7272bff1d507d0522fd3d44 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.checkbox','data' => ['toggle' => true,'wire:model' => 'mailWithMail','label' => __('app.also_send_as_email')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.checkbox'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['toggle' => true,'wire:model' => 'mailWithMail','label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('app.also_send_as_email'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $attributes = $__attributesOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $component = $__componentOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__componentOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="mt-4 space-y-4 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                <div>
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'mailSubject','value' => __('app.subject')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailSubject','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('app.subject'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'mailSubject','type' => 'text','wire:model' => 'mailSubject','class' => 'mt-1 block']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'mailSubject','type' => 'text','wire:model' => 'mailSubject','class' => 'mt-1 block']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $attributes = $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $component = $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginalf94ed9c5393ef72725d159fe01139746 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf94ed9c5393ef72725d159fe01139746 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.input-error','data' => ['for' => 'mailSubject','class' => 'mt-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('input-error'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailSubject','class' => 'mt-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $attributes = $__attributesOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__attributesOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $component = $__componentOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__componentOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
                </div>

                <div>
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'mailHeader','value' => __('app.heading')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailHeader','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('app.heading'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'mailHeader','type' => 'text','wire:model' => 'mailHeader','class' => 'mt-1 block']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'mailHeader','type' => 'text','wire:model' => 'mailHeader','class' => 'mt-1 block']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $attributes = $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $component = $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginalf94ed9c5393ef72725d159fe01139746 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf94ed9c5393ef72725d159fe01139746 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.input-error','data' => ['for' => 'mailHeader','class' => 'mt-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('input-error'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailHeader','class' => 'mt-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $attributes = $__attributesOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__attributesOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $component = $__componentOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__componentOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
                </div>

                <div>
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'mailBody','value' => __('app.message')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailBody','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('app.message'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal7f6d582ce7a3b2aeb0250793236761ee = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.textarea','data' => ['id' => 'mailBody','rows' => '6','wire:model' => 'mailBody','class' => 'mt-1 block']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'mailBody','rows' => '6','wire:model' => 'mailBody','class' => 'mt-1 block']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee)): ?>
<?php $attributes = $__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee; ?>
<?php unset($__attributesOriginal7f6d582ce7a3b2aeb0250793236761ee); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7f6d582ce7a3b2aeb0250793236761ee)): ?>
<?php $component = $__componentOriginal7f6d582ce7a3b2aeb0250793236761ee; ?>
<?php unset($__componentOriginal7f6d582ce7a3b2aeb0250793236761ee); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginalf94ed9c5393ef72725d159fe01139746 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf94ed9c5393ef72725d159fe01139746 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.input-error','data' => ['for' => 'mailBody','class' => 'mt-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('input-error'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailBody','class' => 'mt-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $attributes = $__attributesOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__attributesOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $component = $__componentOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__componentOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
                </div>

                <div>
                    <?php if (isset($component)) { $__componentOriginal447da01212f348036776c1867ad668f7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal447da01212f348036776c1867ad668f7 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.label','data' => ['for' => 'mailLink','value' => __('app.link_optional')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailLink','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('app.link_optional'))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $attributes = $__attributesOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__attributesOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal447da01212f348036776c1867ad668f7)): ?>
<?php $component = $__componentOriginal447da01212f348036776c1867ad668f7; ?>
<?php unset($__componentOriginal447da01212f348036776c1867ad668f7); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.input','data' => ['id' => 'mailLink','type' => 'url','wire:model' => 'mailLink','class' => 'mt-1 block']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'mailLink','type' => 'url','wire:model' => 'mailLink','class' => 'mt-1 block']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $attributes = $__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__attributesOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec)): ?>
<?php $component = $__componentOriginal8a8001505e09a2eec6e77ccabaf56bec; ?>
<?php unset($__componentOriginal8a8001505e09a2eec6e77ccabaf56bec); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginalf94ed9c5393ef72725d159fe01139746 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf94ed9c5393ef72725d159fe01139746 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.input-error','data' => ['for' => 'mailLink','class' => 'mt-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('input-error'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'mailLink','class' => 'mt-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $attributes = $__attributesOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__attributesOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $component = $__componentOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__componentOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
                </div>
            </div>

            <div class="mt-4">
                <?php if (isset($component)) { $__componentOriginalfdeece1fc71f1d30daecc0557d1d0649 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalfdeece1fc71f1d30daecc0557d1d0649 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.filepool.drop-zone','data' => ['model' => 'fileUploads','maxFilesize' => 2]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.filepool.drop-zone'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['model' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('fileUploads'),'max-filesize' => 2]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalfdeece1fc71f1d30daecc0557d1d0649)): ?>
<?php $attributes = $__attributesOriginalfdeece1fc71f1d30daecc0557d1d0649; ?>
<?php unset($__attributesOriginalfdeece1fc71f1d30daecc0557d1d0649); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalfdeece1fc71f1d30daecc0557d1d0649)): ?>
<?php $component = $__componentOriginalfdeece1fc71f1d30daecc0557d1d0649; ?>
<?php unset($__componentOriginalfdeece1fc71f1d30daecc0557d1d0649); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginalf94ed9c5393ef72725d159fe01139746 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf94ed9c5393ef72725d159fe01139746 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.input-error','data' => ['for' => 'fileUploads.*','class' => 'mt-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('input-error'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'fileUploads.*','class' => 'mt-2']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $attributes = $__attributesOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__attributesOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf94ed9c5393ef72725d159fe01139746)): ?>
<?php $component = $__componentOriginalf94ed9c5393ef72725d159fe01139746; ?>
<?php unset($__componentOriginalf94ed9c5393ef72725d159fe01139746); ?>
<?php endif; ?>
            </div>
         <?php $__env->endSlot(); ?>

         <?php $__env->slot('footer', null, []); ?> 
            <?php if (isset($component)) { $__componentOriginal3b0e04e43cf890250cc4d85cff4d94af = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.secondary-button','data' => ['wire:click' => 'resetMailModal','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('secondary-button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'resetMailModal','wire:loading.attr' => 'disabled']); ?>
                <?php echo e(__('app.cancel')); ?>

             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af)): ?>
<?php $attributes = $__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af; ?>
<?php unset($__attributesOriginal3b0e04e43cf890250cc4d85cff4d94af); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3b0e04e43cf890250cc4d85cff4d94af)): ?>
<?php $component = $__componentOriginal3b0e04e43cf890250cc4d85cff4d94af; ?>
<?php unset($__componentOriginal3b0e04e43cf890250cc4d85cff4d94af); ?>
<?php endif; ?>

            <?php $canSendMail = auth()->user()?->isAdmin(); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canSendMail): ?>
                <?php if (isset($component)) { $__componentOriginald0f1fd2689e4bb7060122a5b91fe8561 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.button','data' => ['wire:click' => 'sendMail','wire:loading.attr' => 'disabled','class' => 'ml-2']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'sendMail','wire:loading.attr' => 'disabled','class' => 'ml-2']); ?>
                    <?php echo e(__('app.send')); ?>

                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561)): ?>
<?php $attributes = $__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561; ?>
<?php unset($__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald0f1fd2689e4bb7060122a5b91fe8561)): ?>
<?php $component = $__componentOriginald0f1fd2689e4bb7060122a5b91fe8561; ?>
<?php unset($__componentOriginald0f1fd2689e4bb7060122a5b91fe8561); ?>
<?php endif; ?>
            <?php else: ?>
                <?php if (isset($component)) { $__componentOriginald0f1fd2689e4bb7060122a5b91fe8561 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.button','data' => ['disabled' => true,'class' => 'pointer-events-none ml-2 cursor-not-allowed opacity-60','title' => ''.e(__('app.no_permission')).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['disabled' => true,'class' => 'pointer-events-none ml-2 cursor-not-allowed opacity-60','title' => ''.e(__('app.no_permission')).'']); ?>
                    <?php echo e(__('app.send')); ?>

                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561)): ?>
<?php $attributes = $__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561; ?>
<?php unset($__attributesOriginald0f1fd2689e4bb7060122a5b91fe8561); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald0f1fd2689e4bb7060122a5b91fe8561)): ?>
<?php $component = $__componentOriginald0f1fd2689e4bb7060122a5b91fe8561; ?>
<?php unset($__componentOriginald0f1fd2689e4bb7060122a5b91fe8561); ?>
<?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
         <?php $__env->endSlot(); ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f)): ?>
<?php $attributes = $__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f; ?>
<?php unset($__attributesOriginal49bd1c1dd878e22e0fb84faabf295a3f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f)): ?>
<?php $component = $__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f; ?>
<?php unset($__componentOriginal49bd1c1dd878e22e0fb84faabf295a3f); ?>
<?php endif; ?>
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/users/messages/message-form.blade.php ENDPATH**/ ?>