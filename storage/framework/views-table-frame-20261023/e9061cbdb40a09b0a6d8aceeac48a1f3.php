<div class="rt-disposition rt-disposition--inquiries <?php echo e($consolidated && !$profileEmbedded ? 'rt-inbox-compact' : ''); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($modalOnly)): ?>
    <section class="rt-disposition-summary" aria-label="Übersicht aller offenen Anfragen">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = [
            ['value' => $summary['active'], 'label' => 'Offene Vorgänge', 'detail' => $profileEmbedded ? 'dieses Kunden' : 'im gesamten Eingang', 'tone' => 'neutral'],
            ['value' => $summary['new'], 'label' => 'Neue Anfragen', 'detail' => 'noch nicht geprüft', 'tone' => 'info'],
            ['value' => $summary['accepted'], 'label' => 'Zusage erhalten', 'detail' => 'bereit zur Übernahme', 'tone' => 'success'],
            ['value' => $summary['offered'], 'label' => 'Angebot offen', 'detail' => 'Kundenzusage ausstehend', 'tone' => 'warning'],
        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $metric): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="rt-disposition-summary__item" data-tone="<?php echo e($metric['tone']); ?>">
                <span class="rt-disposition-summary__value"><?php echo e($metric['value']); ?></span>
                <div><span class="rt-disposition-summary__label"><?php echo e($metric['label']); ?></span><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($consolidated && !$profileEmbedded)): ?><span class="rt-disposition-summary__detail"><?php echo e($metric['detail']); ?></span><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>

    <?php if (isset($component)) { $__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.feedback','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.feedback'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5)): ?>
<?php $attributes = $__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5; ?>
<?php unset($__attributesOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5)): ?>
<?php $component = $__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5; ?>
<?php unset($__componentOriginal5e9d6d82ac1beaaf1b5140b41f6bfdc5); ?>
<?php endif; ?>

    <div class="rt-disposition-workgrid">
        <section class="rt-disposition-panel" aria-labelledby="inquiries-list-heading">
            <div class="rt-disposition-section-heading">
                <h2 id="inquiries-list-heading">Posteingang <span class="rt-disposition-count"><?php echo e($inquiries->total()); ?></span></h2>
            </div>
            <?php if (isset($component)) { $__componentOriginal12eeda942b3fac8c75fb8438bfe7d5fc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal12eeda942b3fac8c75fb8438bfe7d5fc = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.toolbar','data' => ['title' => 'Anfragen filtern','id' => 'inquiry-filters','searchInHeader' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.toolbar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Anfragen filtern','id' => 'inquiry-filters','search-in-header' => true]); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($consolidated && !$profileEmbedded)): ?>
                     <?php $__env->slot('search', null, []); ?> <?php if (isset($component)) { $__componentOriginald5b59f544c04b09a98cc4b69022718ce = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald5b59f544c04b09a98cc4b69022718ce = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.search-field','data' => ['context' => 'page','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Anfrage oder Kunde suchen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.search-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['context' => 'page','wire:model.live.debounce.300ms' => 'search','placeholder' => 'Anfrage oder Kunde suchen']); ?>
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
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                 <?php $__env->slot('bulk', null, []); ?> 
                    <div class="rt-disposition-segments" role="group" aria-label="Anfragestatus" data-tables-bulk>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['all' => 'Alle Status', 'new' => 'Neu', 'accepted' => 'Zugesagt']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <button type="button" wire:click="$set('statusFilter', '<?php echo e($status); ?>')" aria-pressed="<?php echo e($statusFilter === $status ? 'true' : 'false'); ?>" wire:loading.attr="disabled" wire:target="statusFilter"><?php echo e($label); ?></button>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                 <?php $__env->endSlot(); ?>
                <?php if (isset($component)) { $__componentOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4589935c3e2b2fe6e8c3bfce3482aa32 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.filter-field','data' => ['label' => 'Vorgänge','for' => 'inquiry-status-filter']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.filter-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Vorgänge','for' => 'inquiry-status-filter']); ?>
                    <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'inquiry-status-filter','wire:model.live' => 'filter','ariaLabel' => 'Anfragen filtern']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'inquiry-status-filter','wire:model.live' => 'filter','aria-label' => 'Anfragen filtern']); ?>
                        <option value="active" data-icon-class="far fa-inbox">Offene Vorgänge</option>
                        <option value="all" data-icon-class="far fa-layer-group">Alle Vorgänge</option>
                        <option value="email" data-icon-class="far fa-envelope">E-Mail</option>
                        <option value="phone" data-icon-class="far fa-phone">Telefon</option>
                        <option value="portal" data-icon-class="far fa-globe">Portal</option>
                        <option value="manual" data-icon-class="far fa-pen-to-square">Manuell</option>
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
                 <?php echo $__env->renderComponent(); ?>
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
            <div wire:loading.class="opacity-60" wire:target="tableSort,search,filter,statusFilter,gotoPage,nextPage,previousPage">
                <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Vorgang','key'=>'title','width'=>'minmax(0,1.8fr)','sortable'=>true],['label'=>'Kunde','key'=>'customer','width'=>'minmax(0,1.15fr)','sortable'=>true],['label'=>'Einsatz','key'=>'schedule','width'=>'minmax(0,1.05fr)','sortable'=>true],['label'=>'Status','key'=>'status','width'=>'minmax(0,.8fr)','sortable'=>true]],'sortAction' => 'tableSort','sortBy' => $sortBy,'sortDir' => $sortDir,'tableKey' => 'inquiries','items' => $inquiries,'detailAction' => 'select','rowView' => 'components.tables.rows.operations.inquiries-row','actionsView' => 'components.tables.rows.operations.inquiries-actions','empty' => 'Keine Anfragen für diese Auswahl gefunden.','flushTop' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Vorgang','key'=>'title','width'=>'minmax(0,1.8fr)','sortable'=>true],['label'=>'Kunde','key'=>'customer','width'=>'minmax(0,1.15fr)','sortable'=>true],['label'=>'Einsatz','key'=>'schedule','width'=>'minmax(0,1.05fr)','sortable'=>true],['label'=>'Status','key'=>'status','width'=>'minmax(0,.8fr)','sortable'=>true]]),'sort-action' => 'tableSort','sort-by' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortBy),'sort-dir' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortDir),'table-key' => 'inquiries','items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($inquiries),'detail-action' => 'select','row-view' => 'components.tables.rows.operations.inquiries-row','actions-view' => 'components.tables.rows.operations.inquiries-actions','empty' => 'Keine Anfragen für diese Auswahl gefunden.','flush-top' => true]); ?>
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
            </div>
            <div class="rt-disposition-pagination"><?php echo e($inquiries->links()); ?></div>
        </section>

        <aside class="rt-disposition-workgrid__aside" aria-labelledby="inquiries-next-heading">
            <div class="rt-disposition-section-heading"><h2 id="inquiries-next-heading">Als Nächstes</h2><i class="far fa-clock" aria-hidden="true"></i></div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $nextInquiries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $inquiry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php
                    $nextStep = match($inquiry->status) {
                        'accepted' => 'Auftrag anlegen',
                        'offered' => 'Kundenzusage dokumentieren',
                        'verified' => 'Angebot festhalten',
                        default => 'Bedarf prüfen',
                    };
                ?>
                <button type="button" class="rt-disposition-context-item" wire:key="inquiry-next-<?php echo e($inquiry->id); ?>" wire:click="select(<?php echo e($inquiry->id); ?>)" wire:loading.attr="disabled" wire:target="select" aria-label="<?php echo e($nextStep); ?>: <?php echo e($inquiry->title); ?>">
                    <span class="rt-disposition-context-item__label"><?php echo e(\App\Support\Operations\OperationsNavigation::status($inquiry->status)); ?></span>
                    <strong class="rt-disposition-context-item__title"><?php echo e($nextStep); ?></strong>
                    <span class="rt-disposition-context-item__meta"><?php echo e($inquiry->title); ?></span>
                    <span class="rt-disposition-context-item__meta"><?php echo e($inquiry->customer?->company_name ?? 'Kunde noch zuordnen'); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($inquiry->starts_at): ?> · <?php echo e($inquiry->starts_at->format('d.m. H:i')); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                    <span class="rt-disposition-context-item__footer"><span><?php echo e($inquiry->number); ?></span><i class="far fa-arrow-right" aria-hidden="true"></i></span>
                </button>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <div class="rt-inbox-next-empty"><i class="far fa-check-circle" aria-hidden="true"></i><strong>Alles im Blick</strong><span>Keine offenen nächsten Schritte.</span></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </aside>
    </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php if (isset($component)) { $__componentOriginal3b538b67d504d6e72dbc655b66529d76 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3b538b67d504d6e72dbc655b66529d76 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.modal','data' => ['attributes' => new \Illuminate\View\ComponentAttributeBag(['wire:model'.($consolidated ? '.live' : '') => 'detailOpen']),'title' => $editing ? ($selectedId ? 'Bedarf bearbeiten' : 'Neue Anfrage') : 'Anfrage','maxWidth' => '4xl','variant' => $editing ? 'modal' : 'drawer']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(new \Illuminate\View\ComponentAttributeBag(['wire:model'.($consolidated ? '.live' : '') => 'detailOpen'])),'title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($editing ? ($selectedId ? 'Bedarf bearbeiten' : 'Neue Anfrage') : 'Anfrage'),'max-width' => '4xl','variant' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($editing ? 'modal' : 'drawer')]); ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($editing): ?>
                <form wire:submit="save" class="ops-form">
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Betreff','model' => 'form.title','required' => true,'maxlength' => '180','wide' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Betreff','model' => 'form.title','required' => true,'maxlength' => '180','wide' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$selectedId): ?>
                        <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Eingangsweg','model' => 'form.channel','type' => 'select']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Eingangsweg','model' => 'form.channel','type' => 'select']); ?><option value="manual">Manuell</option><option value="email">E-Mail</option><option value="phone">Telefon</option><option value="portal">Portal</option> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Eingangsreferenz','model' => 'form.source_reference','maxlength' => '190']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Eingangsreferenz','model' => 'form.source_reference','maxlength' => '190']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Original / Gesprächsnotiz','model' => 'form.original','type' => 'textarea','required' => true,'maxlength' => '20000','wide' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Original / Gesprächsnotiz','model' => 'form.original','type' => 'textarea','required' => true,'maxlength' => '20000','wide' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Kunde','model' => 'form.customer_id','type' => 'select','wide' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Kunde','model' => 'form.customer_id','type' => 'select','wide' => true]); ?><option value="">Bitte zuordnen</option><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $customers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $customer): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($customer->id); ?>"><?php echo e($customer->company_name); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Ansprechpartner','model' => 'form.contact_name','maxlength' => '180']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Ansprechpartner','model' => 'form.contact_name','maxlength' => '180']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'E-Mail','model' => 'form.contact_email','type' => 'email']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'E-Mail','model' => 'form.contact_email','type' => 'email']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Telefon','model' => 'form.contact_phone','type' => 'tel']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Telefon','model' => 'form.contact_phone','type' => 'tel']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Zeitzone','model' => 'form.timezone','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Zeitzone','model' => 'form.timezone','required' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Beginn','model' => 'form.starts_at','type' => 'datetime-local']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Beginn','model' => 'form.starts_at','type' => 'datetime-local']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Ende','model' => 'form.ends_at','type' => 'datetime-local']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Ende','model' => 'form.ends_at','type' => 'datetime-local']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Einsatzort','model' => 'form.location_name','maxlength' => '180']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Einsatzort','model' => 'form.location_name','maxlength' => '180']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Tätigkeit','model' => 'form.role_name','maxlength' => '160']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Tätigkeit','model' => 'form.role_name','maxlength' => '160']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Personalbedarf','model' => 'form.required_staff','type' => 'number','min' => '1','max' => '999']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Personalbedarf','model' => 'form.required_staff','type' => 'number','min' => '1','max' => '999']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
                    <div class="ops-full ops-actions"><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'submit','mode' => 'primary','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'submit','mode' => 'primary','wire:loading.attr' => 'disabled']); ?>Speichern <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => 'close']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => 'close']); ?>Abbrechen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?></div>
                </form>
            <?php elseif($selected): ?>
                <header class="ops-toolbar"><div><p class="ops-kicker"><?php echo e($selected->number); ?> · Revision <?php echo e($selected->revision); ?></p><h2><?php echo e($selected->title); ?></h2></div><?php if (isset($component)) { $__componentOriginal9140b74635d19149dc471db3f05482a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9140b74635d19149dc471db3f05482a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.status','data' => ['value' => $selected->status]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.status'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selected->status)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9140b74635d19149dc471db3f05482a0)): ?>
<?php $attributes = $__attributesOriginal9140b74635d19149dc471db3f05482a0; ?>
<?php unset($__attributesOriginal9140b74635d19149dc471db3f05482a0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9140b74635d19149dc471db3f05482a0)): ?>
<?php $component = $__componentOriginal9140b74635d19149dc471db3f05482a0; ?>
<?php unset($__componentOriginal9140b74635d19149dc471db3f05482a0); ?>
<?php endif; ?></header>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($consolidated): ?><nav class="ops-actions" aria-label="Vorgangsdetails"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['overview'=>'Übersicht','history'=>'Verlauf']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key=>$label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'button','mode' => $detailSection === $key ? 'primary':'link','wire:click' => 'setDetailSection(\''.e($key).'\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','mode' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailSection === $key ? 'primary':'link'),'wire:click' => 'setDetailSection(\''.e($key).'\')']); ?><?php echo e($label); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></nav><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$consolidated || $detailSection === 'overview'): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$selected->duplicate_of_id): ?>
                    <?php
                        $workflowSteps = ['new' => 'Neu', 'verified' => 'Geprüft', 'offered' => 'Angebot', 'accepted' => 'Zugesagt', 'converted' => 'Beauftragt'];
                        $currentStep = array_search($selected->status, array_keys($workflowSteps), true);
                    ?>
                    <ol class="rt-disposition-workflow-steps" aria-label="Bearbeitungsstand">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $workflowSteps; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <li class="rt-disposition-workflow-step" data-state="<?php echo e($selected->status === $status ? 'current' : ($currentStep !== false && $loop->index < $currentStep ? 'done' : 'upcoming')); ?>" <?php if($selected->status === $status): ?> aria-current="step" <?php endif; ?>><?php echo e($label); ?></li>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </ol>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <dl class="ops-meta"><div><dt>Kunde</dt><dd><?php echo e($selected->customer?->company_name ?? 'Noch nicht zugeordnet'); ?></dd></div><div><dt>Kontakt</dt><dd><?php echo e($selected->contact_name ?: '—'); ?></dd></div><div><dt>Beginn</dt><dd><?php echo e($selected->starts_at?->format('d.m.Y H:i') ?? '—'); ?></dd></div><div><dt>Ende · <?php echo e($selected->timezone); ?></dt><dd><?php echo e($selected->ends_at?->format('d.m.Y H:i') ?? '—'); ?></dd></div><div><dt>Einsatzort</dt><dd><?php echo e($selected->location_name ?: '—'); ?></dd></div><div><dt>Bedarf</dt><dd><?php echo e($selected->required_staff ?? '—'); ?> × <?php echo e($selected->role_name ?: '—'); ?></dd></div></dl>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($aiIntakeId): ?>
                    <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','href' => \App\Support\Operations\OperationsPages::intakePanelUrl($aiIntakeId),'dataNoNavigate' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(\App\Support\Operations\OperationsPages::intakePanelUrl($aiIntakeId)),'data-no-navigate' => true]); ?><?php echo e($selected->order_id ? 'Weiter zur Schichtvorbereitung in der AI-Inbox' : 'Zurück zur AI-Inbox'); ?> <i class="far fa-arrow-right" aria-hidden="true"></i> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <details class="rt-disposition-detail-section"><summary>Originaleingang</summary><p class="ops-muted"><?php echo e(['email' => 'E-Mail', 'phone' => 'Telefon', 'portal' => 'Portal', 'manual' => 'Manuell'][$selected->channel] ?? $selected->channel); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selected->source_reference): ?> · <?php echo e($selected->source_reference); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></p><div class="ops-original"><?php echo e($selected->original); ?></div></details>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selected->customer_id && auth()->user()->can('operations.manage') && \Illuminate\Support\Facades\Route::has('operations.page')): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','dataNoNavigate' => true,'href' => route('operations.page',['page'=>'customers','customer'=>$selected->customer_id])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('operations.page',['page'=>'customers','customer'=>$selected->customer_id]))]); ?>Kundenakte öffnen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($customerWorkflowReady && !$portalSubmissionId): ?>
                    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.inquiry-process-panel', ['inquiryId' => $selected->id]);

$__key = 'inquiry-process-'.$selected->id;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-665426274-0', $__key);

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
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($commercialReady && !$portalSubmissionId): ?>
                    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.commercial-offers', ['subjectType' => 'OperationInquiry','subjectId' => $selected->id]);

$__key = 'inquiry-offers-'.$selected->id;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-665426274-1', $__key);

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
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($portalSubmissionId): ?>
                    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('customers.portal.manage')): ?>
                        <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'primary','dataNoNavigate' => true,'href' => route('operations.page',['page'=>'cases','view'=>'inbox','section'=>'portal','customer'=>$selected->customer_id,'source'=>'submission','record'=>$portalSubmissionId])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'primary','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('operations.page',['page'=>'cases','view'=>'inbox','section'=>'portal','customer'=>$selected->customer_id,'source'=>'submission','record'=>$portalSubmissionId]))]); ?>Portalvorgang öffnen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                    <?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selected->order_id): ?>
                        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('operations.manage')): ?>
                            <?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'link','dataNoNavigate' => true,'href' => route('operations.page',['page'=>'cases','view'=>'orders','order'=>$selected->order_id,'inquiry'=>$selected->id])]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'link','data-no-navigate' => true,'href' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('operations.page',['page'=>'cases','view'=>'orders','order'=>$selected->order_id,'inquiry'=>$selected->id]))]); ?>Auftrag <?php echo e($selected->order?->order_number); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php elseif(!$selected->order_id && !$selected->duplicate_of_id && $selected->status !== 'rejected'): ?>
                    <div class="ops-actions"><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['wire:click' => '$set(\'editing\', true)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:click' => '$set(\'editing\', true)']); ?>Bedarf bearbeiten <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selected->status === 'new'): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'primary','wire:click' => 'transition(\'verify\')','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'primary','wire:click' => 'transition(\'verify\')','wire:loading.attr' => 'disabled']); ?>Bedarf bestätigen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$commercialReady && in_array($selected->status, ['verified','offered'])): ?>
                        <form wire:submit="transition('offer')" class="ops-form"><?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Angebotsbetrag netto (€)','model' => 'amount','type' => 'number','min' => '0','step' => '0.01','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Angebotsbetrag netto (€)','model' => 'amount','type' => 'number','min' => '0','step' => '0.01','required' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?><?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Leistungsumfang und Konditionen','model' => 'terms','type' => 'textarea','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Leistungsumfang und Konditionen','model' => 'terms','type' => 'textarea','required' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?><div class="ops-full"><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'submit','mode' => 'primary','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'submit','mode' => 'primary','wire:loading.attr' => 'disabled']); ?>Angebot festhalten <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?></div></form>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selected->offer && !$commercialReady): ?><div class="ops-panel"><p class="ops-kicker">Angebot · Revision <?php echo e($selected->offer['revision']); ?></p><h2><?php echo e(number_format($selected->offer['amount_cents']/100,2,',','.')); ?> € netto</h2><p><?php echo e($selected->offer['terms']); ?></p></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selected->status === 'offered' && !$commercialReady): ?>
                        <form wire:submit="transition('accept')" class="ops-stack"><?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Kundenzusage · Person, Zeitpunkt, Referenz','model' => 'acceptance','type' => 'textarea','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Kundenzusage · Person, Zeitpunkt, Referenz','model' => 'acceptance','type' => 'textarea','required' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?><?php if (isset($component)) { $__componentOriginal73478c8be7272bff1d507d0522fd3d44 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal73478c8be7272bff1d507d0522fd3d44 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.checkbox','data' => ['wire:model' => 'authorized','required' => true,'label' => 'Beauftragung durch berechtigten Kundenkontakt bestätigt']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.checkbox'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['wire:model' => 'authorized','required' => true,'label' => 'Beauftragung durch berechtigten Kundenkontakt bestätigt']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $attributes = $__attributesOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__attributesOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal73478c8be7272bff1d507d0522fd3d44)): ?>
<?php $component = $__componentOriginal73478c8be7272bff1d507d0522fd3d44; ?>
<?php unset($__componentOriginal73478c8be7272bff1d507d0522fd3d44); ?>
<?php endif; ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'submit','mode' => 'primary','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'submit','mode' => 'primary','wire:loading.attr' => 'disabled']); ?>Zusage dokumentieren <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?></form>
                    <?php elseif($selected->status === 'accepted'): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'primary','wire:click' => 'transition(\'convert\')','wire:loading.attr' => 'disabled']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'primary','wire:click' => 'transition(\'convert\')','wire:loading.attr' => 'disabled']); ?>Auftrag anlegen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$selected->offer): ?><details><summary>Dublette zuordnen</summary><form wire:submit="transition('duplicate')" class="ops-actions"><?php if (isset($component)) { $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.field','data' => ['label' => 'Nummer des älteren Originals','model' => 'duplicateId','type' => 'number','min' => '1','required' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Nummer des älteren Originals','model' => 'duplicateId','type' => 'number','min' => '1','required' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $attributes = $__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__attributesOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b)): ?>
<?php $component = $__componentOriginal529cec49c66a8b16eba39fad2e8ee81b; ?>
<?php unset($__componentOriginal529cec49c66a8b16eba39fad2e8ee81b); ?>
<?php endif; ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['type' => 'submit']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'submit']); ?>Verknüpfen <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?></form></details><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php elseif($selected->order): ?><?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('operations.manage')): ?><a class="ops-link" data-no-navigate href="<?php echo e(\Illuminate\Support\Facades\Route::has('operations.page') ? route('operations.page',['page'=>'cases','view'=>'orders','order'=>$selected->order_id,'inquiry'=>$selected->id]) : route('operations.workspace',['module'=>'orders','order'=>$selected->order_id])); ?>">Auftrag <?php echo e($selected->order->order_number); ?> →</a><?php else: ?><span>Auftrag <?php echo e($selected->order->order_number); ?></span><?php endif; ?>
                <?php else: ?><p>Original: <?php echo e($selected->duplicateOf?->number); ?></p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$consolidated || $detailSection === 'history'): ?>
                <details class="rt-disposition-detail-section"><summary>Verlauf</summary><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $history; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><div class="ops-row" wire:key="inquiry-event-<?php echo e($event->id); ?>"><span><?php echo e(\App\Support\Operations\OperationsNavigation::auditLabel($event->action)); ?></span><div class="min-w-0 space-y-1"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($event->actor): ?><?php if (isset($component)) { $__componentOriginalbfdf91119f0bca2d3a1da565725107f1 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.user.public-info','data' => ['user' => $event->actor,'size' => 6,'showPresence' => false]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('user.public-info'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($event->actor),'size' => 6,'show-presence' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbfdf91119f0bca2d3a1da565725107f1)): ?>
<?php $attributes = $__attributesOriginalbfdf91119f0bca2d3a1da565725107f1; ?>
<?php unset($__attributesOriginalbfdf91119f0bca2d3a1da565725107f1); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbfdf91119f0bca2d3a1da565725107f1)): ?>
<?php $component = $__componentOriginalbfdf91119f0bca2d3a1da565725107f1; ?>
<?php unset($__componentOriginalbfdf91119f0bca2d3a1da565725107f1); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><span class="ops-muted"><?php echo e($event->created_at->setTimezone(config('operations.display_timezone'))->format('d.m. H:i')); ?></span></div></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><p class="ops-muted">Noch keine Einträge.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></details>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php else: ?><div class="ops-empty">Anfrage auswählen.</div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

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
</div>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/inquiry-inbox.blade.php ENDPATH**/ ?>