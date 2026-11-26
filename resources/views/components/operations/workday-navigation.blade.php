@props([
    'tabs' => ['today' => 'Heute', 'requests' => 'Anfragen', 'schedule' => 'Kalender', 'time' => 'Zeiten'],
    'selected' => 'today',
    'requestCount' => 0,
    'id' => 'personal-workday-navigation',
    'teleport' => true,
    'disabled' => false,
])
@php
    $icons = ['today' => 'fa-sun', 'requests' => 'fa-inbox', 'schedule' => 'fa-calendar-alt', 'time' => 'fa-clock'];
    $workTabs = array_filter(array_intersect_key(is_array($tabs) ? $tabs : [], $icons), static fn ($label): bool => is_string($label) && trim($label) !== '');
    $current = is_string($selected) && array_key_exists($selected, $workTabs) ? $selected : array_key_first($workTabs);
    $count = max(0, (int) $requestCount);
    $options = [];
    foreach ($workTabs as $key => $label) {
        $options[] = [
            'value' => $key, 'label' => $key === 'requests' && $count > 0 ? $label.' · '.$count : $label,
            'compactLabel' => $label, 'icon' => $icons[$key],
        ];
    }
@endphp
<x-ui.navigation.area-menu :id="$id" :teleport="filter_var($teleport, FILTER_VALIDATE_BOOL) ? '[data-area-menu-host]' : null" label="Mein Arbeitsplatz" :value="$current" action="showTab" :options="$options" :disabled="$disabled" :show-chat="false" {{ $attributes->merge(['data-workday-navigation' => '']) }} />
