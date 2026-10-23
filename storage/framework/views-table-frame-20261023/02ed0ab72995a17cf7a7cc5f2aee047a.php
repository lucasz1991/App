<section class="ops-stack min-w-0" <?php if($headerInPage): ?> data-disposition-viewport="offers" <?php endif; ?> aria-label="Angebote" x-data="{}">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($headerInPage): ?>
        <template x-teleport="[data-topbar-page-search]" wire:key="offers-topbar-search-<?php echo e($this->getId()); ?>">
            <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','resultsCount' => $offers->count(),'placeholder' => 'Angebot oder Kunde suchen','ariaLabel' => 'Angebote suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'page-topbar','wire:model.live.debounce.300ms' => 'search','results-count' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($offers->count()),'placeholder' => 'Angebot oder Kunde suchen','aria-label' => 'Angebote suchen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $attributes = $__attributesOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $component = $__componentOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__componentOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
        </template>
        <template x-teleport="[data-page-header-search]" wire:key="offers-header-controls-<?php echo e($this->getId()); ?>">
            <div class="rt-offers-header-controls">
                <label class="sr-only" for="case-offer-status-<?php echo e($this->getId()); ?>">Angebotsstatus</label>
                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'case-offer-status-'.e($this->getId()).'','wire:model.live' => 'status','ariaLabel' => 'Angebotsstatus']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'case-offer-status-'.e($this->getId()).'','wire:model.live' => 'status','aria-label' => 'Angebotsstatus']); ?>
                    <option value="all" data-icon-class="far fa-layer-group">Alle Stände</option>
                    <option value="draft" data-icon-class="far fa-pen-to-square">Entwürfe</option>
                    <option value="offered" data-icon-class="far fa-paper-plane">Angeboten</option>
                    <option value="accepted" data-icon-class="far fa-check">Zugesagt</option>
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
            </div>
        </template>
    <?php else: ?>
    <?php if (isset($component)) { $__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.toolbar','data' => ['id' => 'commercial-offer-index','title' => 'Angebote','searchInHeader' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.toolbar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'commercial-offer-index','title' => 'Angebote','search-in-header' => true]); ?>
         <?php $__env->slot('search', null, []); ?> <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'page','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Angebot oder Kunde suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'page','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Angebot oder Kunde suchen']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $attributes = $__attributesOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__attributesOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald5b59f544c04b09a98cc4b69022718ce)): ?>
<?php $component = $__componentOriginald5b59f544c04b09a98cc4b69022718ce; ?>
<?php unset($__componentOriginald5b59f544c04b09a98cc4b69022718ce); ?>
<?php endif; ?> <?php $__env->endSlot(); ?>
        <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Status','for' => 'case-offer-status']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Status','for' => 'case-offer-status']); ?><?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'case-offer-status','wire:model.live' => 'status']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'case-offer-status','wire:model.live' => 'status']); ?><option value="all">Alle Stände</option><option value="draft">Entwürfe</option><option value="offered">Angeboten</option><option value="accepted">Zugesagt</option> <?php echo $__env->renderComponent(); ?>
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
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc)): ?>
<?php $attributes = $__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc; ?>
<?php unset($__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc)): ?>
<?php $component = $__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc; ?>
<?php unset($__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc); ?>
<?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Vorgang / Kunde','key'=>'case'],['label'=>'Angebotsstand','key'=>'revision'],['label'=>'Betrag netto','key'=>'total'],['label'=>'Status','key'=>'status']],'items' => $offers,'flushTop' => $headerInPage,'scrollBody' => $headerInPage,'label' => 'Angebote','dataDispositionListTable' => true,'detailAction' => 'select','rowView' => 'components.tables.rows.operations.case-offer','tableKey' => 'case-offers','empty' => 'Keine Angebote für diese Auswahl.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Vorgang / Kunde','key'=>'case'],['label'=>'Angebotsstand','key'=>'revision'],['label'=>'Betrag netto','key'=>'total'],['label'=>'Status','key'=>'status']]),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($offers),'flush-top' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($headerInPage),'scroll-body' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($headerInPage),'label' => 'Angebote','data-disposition-list-table' => true,'detail-action' => 'select','row-view' => 'components.tables.rows.operations.case-offer','table-key' => 'case-offers','empty' => 'Keine Angebote für diese Auswahl.']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a)): ?>
<?php $attributes = $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a; ?>
<?php unset($__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a)): ?>
<?php $component = $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a; ?>
<?php unset($__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a); ?>
<?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($headerInPage): ?><div data-disposition-list-pagination><?php echo e($offers->links()); ?></div><?php else: ?><?php echo e($offers->links()); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedId): ?>
        <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.commercial-offers', ['subjectType' => $selectedSubjectType,'subjectId' => $selectedSubjectId,'initialOfferId' => $selectedId,'showList' => false]);

$__key = 'case-offer-detail-'.$selectedId.'-'.$detailGeneration;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-2031773006-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/commercial-offer-index.blade.php ENDPATH**/ ?>