<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'tabs' => ['chat' => 'Chat', 'intake' => 'AI-Annahme', 'actions' => 'Aktionen', 'activity' => 'Aktivitäten'],
    'selected' => 'chat',
    'action' => 'setTab',
    'idPrefix' => 'ai-assist',
    'controls' => 'ai-assist-content',
    'reviewCount' => 0,
    'label' => 'Assistentenbereiche',
    'appearance' => 'surface',
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
    'tabs' => ['chat' => 'Chat', 'intake' => 'AI-Annahme', 'actions' => 'Aktionen', 'activity' => 'Aktivitäten'],
    'selected' => 'chat',
    'action' => 'setTab',
    'idPrefix' => 'ai-assist',
    'controls' => 'ai-assist-content',
    'reviewCount' => 0,
    'label' => 'Assistentenbereiche',
    'appearance' => 'surface',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $icons = [
        'chat' => 'fa-comments',
        'intake' => 'fa-inbox',
        'actions' => 'fa-sparkles',
        'activity' => 'fa-history',
    ];
    $safeTabs = array_intersect_key(is_array($tabs) ? $tabs : [], $icons);
    $safeAction = in_array($action, ['setTab', 'setOperationsTab'], true) ? $action : null;
    $tabValue = array_key_exists($selected, $safeTabs) ? $selected : array_key_first($safeTabs);
    $count = max(0, (int) $reviewCount);
    $windowTabs = [];
    foreach ($safeTabs as $key => $tabLabel) {
        $windowTabs[$key] = [
            'label' => $tabLabel,
            'icon' => $icons[$key],
            'count' => $key === 'intake' ? $count : 0,
            'accessibleLabel' => $key === 'intake' && $count > 0 ? $tabLabel.' · '.$count.' zur Prüfung' : $tabLabel,
        ];
    }
?>
<?php if (isset($component)) { $__componentOriginala98a3e21acdfa99821ac8f2b42db66a0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala98a3e21acdfa99821ac8f2b42db66a0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.floating.window-tabs','data' => ['attributes' => $attributes->class('rt-assistant-nav'),'tabs' => $windowTabs,'selected' => $selected,'action' => $safeAction,'idPrefix' => $idPrefix,'controls' => $controls,'label' => $label,'appearance' => $appearance,'tabDataAttribute' => 'data-assistant-nav-tab','tabClass' => 'rt-assistant-nav__tab','countClass' => 'rt-assistant-nav__count','dataAssistantNavigation' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.floating.window-tabs'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes->class('rt-assistant-nav')),'tabs' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($windowTabs),'selected' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selected),'action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($safeAction),'id-prefix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($idPrefix),'controls' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($controls),'label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($label),'appearance' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($appearance),'tab-data-attribute' => 'data-assistant-nav-tab','tab-class' => 'rt-assistant-nav__tab','count-class' => 'rt-assistant-nav__count','data-assistant-navigation' => true]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala98a3e21acdfa99821ac8f2b42db66a0)): ?>
<?php $attributes = $__attributesOriginala98a3e21acdfa99821ac8f2b42db66a0; ?>
<?php unset($__attributesOriginala98a3e21acdfa99821ac8f2b42db66a0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala98a3e21acdfa99821ac8f2b42db66a0)): ?>
<?php $component = $__componentOriginala98a3e21acdfa99821ac8f2b42db66a0; ?>
<?php unset($__componentOriginala98a3e21acdfa99821ac8f2b42db66a0); ?>
<?php endif; ?>
<?php /**PATH C:\xampp\htdocs\RailTime\App\resources\views/components/operations/assistant-navigation.blade.php ENDPATH**/ ?>