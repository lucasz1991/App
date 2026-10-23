<?php
    $serviceRows = $serviceGroups->getCollection()->map(function ($group) use ($displayTimezone) {
        $order = $group['items']->first()->order;
        return (object) [
            'id' => $order?->id ?? 0,
            'order' => $order,
            'count' => $group['items']->count(),
            'shifts' => $group['items'],
            'timezone' => $displayTimezone,
            'summary' => $group['summary'],
            'staffingUsers' => $group['staffingUsers'],
            'staffingSeats' => $group['staffingSeats'],
            'from' => $group['items']->min('starts_at')?->copy()->setTimezone($displayTimezone),
            'until' => $group['items']->max('ends_at')?->copy()->setTimezone($displayTimezone),
        ];
    })->values();
    // Keep the native page owner when adapting grouped services to table rows.
    // A plain collection would opt out of the shared scroll-pagination controller.
    $serviceItems = (clone $serviceGroups)->setCollection($serviceRows);
?>
<section aria-label="Leistungsübersicht" data-service-overview data-service-detail-controller data-service-detail-panel-owner="<?php echo e($this->getId()); ?>" x-data="rtServiceDetailPanel">
    <?php if (isset($component)) { $__componentOriginal0b23ee146476c0e42abe7223c133c957 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b23ee146476c0e42abe7223c133c957 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-table','data' => ['items' => $serviceItems,'sortAction' => 'sortServices','sortBy' => $serviceSortBy,'sortDir' => $serviceSortDir,'selectedItems' => [$selectedServiceId],'bulkSelectedItems' => $selectedServiceRows,'bulkSelectionAction' => 'toggleServiceSelection','selectionAction' => 'openServiceDetails','detailAction' => 'openServiceDetails','tableKey' => 'service-overview','dataTablePaginationKey' => 'service-overview','label' => 'Leistungen im gewählten Zeitraum','empty' => 'Keine Leistungen für diese Filter im gewählten Zeitraum gefunden.']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($serviceItems),'sort-action' => 'sortServices','sort-by' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($serviceSortBy),'sort-dir' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($serviceSortDir),'selected-items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([$selectedServiceId]),'bulk-selected-items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedServiceRows),'bulk-selection-action' => 'toggleServiceSelection','selection-action' => 'openServiceDetails','detail-action' => 'openServiceDetails','table-key' => 'service-overview','data-table-pagination-key' => 'service-overview','label' => 'Leistungen im gewählten Zeitraum','empty' => 'Keine Leistungen für diese Filter im gewählten Zeitraum gefunden.']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0b23ee146476c0e42abe7223c133c957)): ?>
<?php $attributes = $__attributesOriginal0b23ee146476c0e42abe7223c133c957; ?>
<?php unset($__attributesOriginal0b23ee146476c0e42abe7223c133c957); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0b23ee146476c0e42abe7223c133c957)): ?>
<?php $component = $__componentOriginal0b23ee146476c0e42abe7223c133c957; ?>
<?php unset($__componentOriginal0b23ee146476c0e42abe7223c133c957); ?>
<?php endif; ?>
    <?php if (isset($component)) { $__componentOriginalee5b1959550127dcd3c5272f16dd7b79 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalee5b1959550127dcd3c5272f16dd7b79 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.panel','data' => ['id' => 'service-detail-panel-'.$this->getId(),'label' => 'Leistungsdetails','showExpression' => 'panelVisible']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.panel'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('service-detail-panel-'.$this->getId()),'label' => 'Leistungsdetails','show-expression' => 'panelVisible']); ?>
        <?php if (isset($component)) { $__componentOriginal54636f4dd36006617e1aa12912b55a4a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal54636f4dd36006617e1aa12912b55a4a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.operations.service-detail-shell','data' => ['ownerId' => $this->getId()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('operations.service-detail-shell'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['owner-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($this->getId())]); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedServiceId && $orderGroups->has($selectedServiceId)): ?>
                    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('admin.operations.orders', ['initialOrderId' => $selectedServiceId,'customerId' => $customerFilter !== 'all' ? (int) $customerFilter : null,'consolidated' => true,'detailOnly' => true,'embeddedDetail' => true]);

$__key = 'service-detail-'.$selectedServiceId.'-'.$serviceDetailVersion;

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3716821246-0', $__key);

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
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal54636f4dd36006617e1aa12912b55a4a)): ?>
<?php $attributes = $__attributesOriginal54636f4dd36006617e1aa12912b55a4a; ?>
<?php unset($__attributesOriginal54636f4dd36006617e1aa12912b55a4a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal54636f4dd36006617e1aa12912b55a4a)): ?>
<?php $component = $__componentOriginal54636f4dd36006617e1aa12912b55a4a; ?>
<?php unset($__componentOriginal54636f4dd36006617e1aa12912b55a4a); ?>
<?php endif; ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalee5b1959550127dcd3c5272f16dd7b79)): ?>
<?php $attributes = $__attributesOriginalee5b1959550127dcd3c5272f16dd7b79; ?>
<?php unset($__attributesOriginalee5b1959550127dcd3c5272f16dd7b79); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalee5b1959550127dcd3c5272f16dd7b79)): ?>
<?php $component = $__componentOriginalee5b1959550127dcd3c5272f16dd7b79; ?>
<?php unset($__componentOriginalee5b1959550127dcd3c5272f16dd7b79); ?>
<?php endif; ?>
</section>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/livewire/admin/operations/partials/service-overview.blade.php ENDPATH**/ ?>