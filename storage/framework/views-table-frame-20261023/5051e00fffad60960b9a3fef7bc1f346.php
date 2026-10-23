<section class="rt-disposition rt-unified-inbox" data-disposition-viewport="inbox" aria-label="Gemeinsamer Eingang" wire:poll.visible.15s x-data="{}">
    <template x-teleport="[data-page-header-search]" wire:key="unified-inbox-filters-<?php echo e($this->getId()); ?>">
        <div class="ops-actions rt-unified-inbox__actions">
                <?php if (isset($component)) { $__componentOriginalbaa1fc05fcc54094c9903c90e072b81f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.navigation.status-menu','data' => ['class' => 'rt-unified-inbox__statuses','variant' => 'dropdown','dropdownId' => 'unified-inbox-status-'.$this->getId(),'options' => $this::FILTERS,'value' => $status,'action' => 'setStatus','counts' => collect($this::FILTERS)->map(fn ($label, $key) => $key === 'all' ? $counts->sum() : ($counts[$key] ?? 0))->all()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.navigation.status-menu'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-unified-inbox__statuses','variant' => 'dropdown','dropdown-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('unified-inbox-status-'.$this->getId()),'options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($this::FILTERS),'value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($status),'action' => 'setStatus','counts' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(collect($this::FILTERS)->map(fn ($label, $key) => $key === 'all' ? $counts->sum() : ($counts[$key] ?? 0))->all())]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f)): ?>
<?php $attributes = $__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f; ?>
<?php unset($__attributesOriginalbaa1fc05fcc54094c9903c90e072b81f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalbaa1fc05fcc54094c9903c90e072b81f)): ?>
<?php $component = $__componentOriginalbaa1fc05fcc54094c9903c90e072b81f; ?>
<?php unset($__componentOriginalbaa1fc05fcc54094c9903c90e072b81f); ?>
<?php endif; ?>
                <label class="sr-only" for="unified-channel-<?php echo e($this->getId()); ?>">Quelle oder Verarbeitung</label>
                <?php if (isset($component)) { $__componentOriginal6f1786746f05efe88dc6b44bb19ef823 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6f1786746f05efe88dc6b44bb19ef823 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.forms.select','data' => ['id' => 'unified-channel-'.e($this->getId()).'','class' => 'rt-unified-inbox__select','wire:model.live' => 'channel','ariaLabel' => 'Quelle oder Verarbeitung']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'unified-channel-'.e($this->getId()).'','class' => 'rt-unified-inbox__select','wire:model.live' => 'channel','aria-label' => 'Quelle oder Verarbeitung']); ?>
                    <option value="all" data-icon-class="far fa-layer-group">Alle Quellen</option>
                    <option value="email" data-icon-class="far fa-envelope">E-Mail</option>
                    <option value="portal" data-icon-class="far fa-globe">Portal</option>
                    <option value="phone" data-icon-class="far fa-phone">Telefon</option>
                    <option value="manual" data-icon-class="far fa-pen-to-square">Manuell</option>
                    <option value="ai" data-icon-class="far fa-sparkles">AI-verarbeitet</option>
                    <option value="import" data-icon-class="far fa-file-import">Import</option>
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
    <template x-teleport="[data-page-header-action-list]" wire:key="unified-inbox-actions-<?php echo e($this->getId()); ?>">
        <div class="ops-actions rt-unified-inbox__actions">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($sections['ai-intake']) || isset($sections['imports'])): ?>
                    <details class="rt-unified-inbox__more" wire:ignore.self x-on:click="if ($event.target.closest('button')) $el.open = false" x-on:click.outside="$el.open = false" x-on:keydown.escape.stop="$el.open = false"><summary aria-label="Weitere Eingangsaktionen"><i class="far fa-ellipsis" aria-hidden="true"></i></summary><div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($sections['ai-intake'])): ?><button type="button" wire:click="create('ai')">Text / Datei mit AI erfassen</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($sections['imports'])): ?><button type="button" wire:click="create('import')">CSV / Text prüfen</button><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div></details>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mailboxAvailable): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['mode' => 'secondary','type' => 'button','wire:click' => 'openMailbox','wire:loading.attr' => 'disabled','wire:target' => 'openMailbox','ariaLabel' => 'Dispositionspostfach öffnen','title' => 'Dispositionspostfach öffnen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['mode' => 'secondary','type' => 'button','wire:click' => 'openMailbox','wire:loading.attr' => 'disabled','wire:target' => 'openMailbox','aria-label' => 'Dispositionspostfach öffnen','title' => 'Dispositionspostfach öffnen']); ?><i class="far fa-envelope" aria-hidden="true"></i><span>Postfach</span> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($sections['overview'])): ?><?php if (isset($component)) { $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.buttons.button-basic','data' => ['class' => 'rt-unified-inbox__create','mode' => 'primary','type' => 'button','wire:click' => 'create','ariaLabel' => 'Eingang erfassen','title' => 'Eingang erfassen']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.buttons.button-basic'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'rt-unified-inbox__create','mode' => 'primary','type' => 'button','wire:click' => 'create','aria-label' => 'Eingang erfassen','title' => 'Eingang erfassen']); ?><i class="far fa-plus" aria-hidden="true"></i> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $attributes = $__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__attributesOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab)): ?>
<?php $component = $__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab; ?>
<?php unset($__componentOriginalb06edb1e3f5a4b538fb61a5d382585ab); ?>
<?php endif; ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </template>
    <section class="rt-disposition-panel" data-disposition-list>
        <div data-disposition-list-content wire:loading.class="opacity-60" wire:target="setStatus,channel,gotoPage,nextPage,previousPage">
            <?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => [['label'=>'Vorgang / Kunde','key'=>'title','width'=>'minmax(0,2fr)'],['label'=>'Quelle','key'=>'channel','width'=>'minmax(0,.8fr)'],['label'=>'Bearbeitung','key'=>'status','width'=>'minmax(0,1fr)'],['label'=>'Nächster Schritt','key'=>'next','width'=>'minmax(0,1.3fr)']],'items' => $rows,'tableKey' => 'unified-inbox','selectionAction' => 'openRow','detailAction' => 'openRow','rowView' => 'components.tables.rows.operations.unified-inbox-row','flushTop' => true,'scrollBody' => true,'label' => 'Gemeinsamer Eingang','dataDispositionListTable' => true,'empty' => $status !== 'all' || $channel !== 'all' ? 'Keine Eingänge für diese Auswahl. Wähle einen anderen Filter.' : 'Keine Eingänge vorhanden. Neue Anfragen und offene Prüfungen erscheinen hier.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['label'=>'Vorgang / Kunde','key'=>'title','width'=>'minmax(0,2fr)'],['label'=>'Quelle','key'=>'channel','width'=>'minmax(0,.8fr)'],['label'=>'Bearbeitung','key'=>'status','width'=>'minmax(0,1fr)'],['label'=>'Nächster Schritt','key'=>'next','width'=>'minmax(0,1.3fr)']]),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($rows),'table-key' => 'unified-inbox','selection-action' => 'openRow','detail-action' => 'openRow','row-view' => 'components.tables.rows.operations.unified-inbox-row','flush-top' => true,'scroll-body' => true,'label' => 'Gemeinsamer Eingang','data-disposition-list-table' => true,'empty' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($status !== 'all' || $channel !== 'all' ? 'Keine Eingänge für diese Auswahl. Wähle einen anderen Filter.' : 'Keine Eingänge vorhanden. Neue Anfragen und offene Prüfungen erscheinen hier.')]); ?>
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
        <div class="rt-disposition-pagination"><?php echo e($rows->links()); ?></div>
    </section>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mailboxAvailable): ?><?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.disposition-mailbox', []);

$__key = 'disposition-mailbox-'.$this->getId();

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3441782013-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detail): ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($detail['kind'] === 'inquiry'): ?>
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.inquiry-inbox', ['initialInquiryId' => $detail['id'],'customerId' => $detail['customer'],'modalOnly' => true]);

$__key = 'unified-detail-'.$detailRevision;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3441782013-1', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
        <?php elseif($detail['kind'] === 'import'): ?>
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.operations-enhancements', ['tab' => 'imports','initialRecordId' => $detail['id'],'embedded' => true,'modalOnly' => true]);

$__key = 'unified-detail-'.$detailRevision;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3441782013-2', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key);

echo $__html;

unset($__html);
unset($__key);
unset($__name);
unset($__params);
unset($__split);
if (isset($__slots)) unset($__slots);
?>
        <?php else: ?>
            <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('operations.customer-portal-management', ['tab' => 'requests','customerId' => $detail['customer'],'initialRecordId' => $detail['id'],'initialSource' => $detail['kind'],'embedded' => true,'modalOnly' => true]);

$__key = 'unified-detail-'.$detailRevision;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3441782013-3', $__key);

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
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/operations/unified-inbox.blade.php ENDPATH**/ ?>