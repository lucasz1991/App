<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'items',
    'sortAction',
    'sortBy' => 'period',
    'sortDir' => 'asc',
    'selectedItems' => [],
    'bulkSelectedItems' => [],
    'bulkSelectionAction' => null,
    'selectionAction',
    'detailAction',
    'tableKey',
    'label' => 'Leistungen',
    'scrollBody' => true,
    'empty' => 'Keine Leistungen für diese Auswahl gefunden.',
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'items',
    'sortAction',
    'sortBy' => 'period',
    'sortDir' => 'asc',
    'selectedItems' => [],
    'bulkSelectedItems' => [],
    'bulkSelectionAction' => null,
    'selectionAction',
    'detailAction',
    'tableKey',
    'label' => 'Leistungen',
    'scrollBody' => true,
    'empty' => 'Keine Leistungen für diese Auswahl gefunden.',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    // One presentation contract; query, pagination and business actions stay
    // with the original Orders/ShiftManagement Livewire owner.
    $hasSelection = is_string($bulkSelectionAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $bulkSelectionAction);
    $columns = [
        ['label' => 'Leistung', 'key' => 'service', 'width' => 'minmax(0,1.5fr)', 'sortable' => true],
        ['label' => 'Kunde', 'key' => 'customer', 'width' => 'minmax(0,.9fr)', 'sortable' => true],
        ['label' => 'Zeitraum', 'key' => 'period', 'width' => 'minmax(0,1.1fr)', 'sortable' => true],
        ['label' => 'Ort', 'key' => 'location', 'width' => 'minmax(0,.9fr)'],
        ['label' => 'Schichten', 'key' => 'count', 'width' => 'minmax(0,.6fr)'],
        ['label' => 'Besetzung', 'key' => 'staffing', 'width' => 'minmax(0,1.15fr)'],
        ['label' => 'Status', 'key' => 'status', 'width' => 'minmax(0,.8fr)', 'sortable' => true],
    ];
    if ($hasSelection) {
        array_unshift($columns, ['label' => 'Auswahl', 'key' => 'selection', 'width' => '44px']);
        // Only the synthetic presentation row gets checkbox metadata, never an Order/Shift.
        $selectionRows = collect($items->items())->map(function ($item) use ($bulkSelectedItems, $bulkSelectionAction) {
            $row = clone $item;
            $row->bulkSelected = in_array((int) $row->id, $bulkSelectedItems, true);
            $row->bulkSelectionAction = $bulkSelectionAction;
            return $row;
        });
        $items = (clone $items)->setCollection($selectionRows);
        $selectedItems = array_values(array_unique([...$selectedItems, ...$bulkSelectedItems]));
    }
?>
<?php if (isset($component)) { $__componentOriginal7fdb3552722b7d40f4be207e2e79dc5a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7fdb3552722b7d40f4be207e2e79dc5a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.tables.table','data' => ['columns' => $columns,'items' => $items,'sortAction' => $sortAction,'sortBy' => $sortBy,'sortDir' => $sortDir,'selectedItems' => $selectedItems,'selectionAction' => $selectionAction,'detailAction' => $detailAction,'tableKey' => $tableKey,'label' => $label,'scrollBody' => $scrollBody,'flushTop' => true,'empty' => $empty,'detailOnClick' => true,'rowView' => 'components.tables.rows.operations.service-overview','class' => 'rt-service-table '.e($hasSelection ? 'rt-service-table--selectable' : '').'','attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('tables.table'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['columns' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($columns),'items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($items),'sort-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortAction),'sort-by' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortBy),'sort-dir' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($sortDir),'selected-items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedItems),'selection-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectionAction),'detail-action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($detailAction),'table-key' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($tableKey),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label),'scroll-body' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($scrollBody),'flush-top' => true,'empty' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($empty),'detail-on-click' => true,'row-view' => 'components.tables.rows.operations.service-overview','class' => 'rt-service-table '.e($hasSelection ? 'rt-service-table--selectable' : '').'','attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
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
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/service-table.blade.php ENDPATH**/ ?>