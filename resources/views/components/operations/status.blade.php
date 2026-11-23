@props(['value' => null, 'label' => null, 'enum' => null, 'icon' => null, 'tone' => null, 'size' => 'sm'])
@php
    // Fachliche Huelle um x-ui.badge: eine Farb- und Icon-Logik fuer alle Status.
    // Enum-Modus: :value bzw. :enum mit einem Status-Enum (label(), icon(), tone()).
    // Text-Modus: Statusschluessel als String, Text aus OperationsNavigation::status().
    // icon: true = Symbol des Enums, String = eigenes Symbol, null = ohne Symbol.
    $statusEnum = $enum instanceof \BackedEnum ? $enum : ($value instanceof \BackedEnum ? $value : null);
    $state = $statusEnum ? (string) $statusEnum->value : (string) $value;
    $text = $label ?? ($statusEnum && method_exists($statusEnum, 'label')
        ? $statusEnum->label()
        : \App\Support\Operations\OperationsNavigation::status($state));
    $color = $tone ?? ($statusEnum && method_exists($statusEnum, 'tone')
        ? $statusEnum->tone()
        : \App\Support\Operations\OperationsNavigation::tone($state));
    $iconName = $icon === true
        ? ($statusEnum && method_exists($statusEnum, 'icon') ? $statusEnum->icon() : null)
        : (is_string($icon) && $icon !== '' ? $icon : null);
@endphp
<x-ui.badge :color="$color" :size="$size" :icon="$iconName" data-state="{{ $state }}" {{ $attributes }}>{{ $text }}</x-ui.badge>
