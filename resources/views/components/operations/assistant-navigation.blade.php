@props([
    'tabs' => ['chat' => 'Chat', 'intake' => 'AI-Annahme', 'actions' => 'Aktionen', 'activity' => 'Aktivitäten'],
    'selected' => 'chat',
    'action' => 'setTab',
    'idPrefix' => 'ai-assist',
    'controls' => 'ai-assist-content',
    'reviewCount' => 0,
    'label' => 'Assistentenbereiche',
    'appearance' => 'surface',
])
@php
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
@endphp
<x-ui.floating.window-tabs {{ $attributes->class('rt-assistant-nav') }} :tabs="$windowTabs" :selected="$selected" :action="$safeAction"
    :id-prefix="$idPrefix" :controls="$controls" :label="$label" :appearance="$appearance"
    tab-data-attribute="data-assistant-nav-tab" tab-class="rt-assistant-nav__tab" count-class="rt-assistant-nav__count" data-assistant-navigation />
