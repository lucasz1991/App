{{--
    Einzelne Primaeraktion einer Tabellenzeile (Oeffnen-Chevron, Download …)
    im actionsView von x-tables.table.

    - href: rendert einen Link; ohne href einen <button type="button">, die
      Aktion kommt dann per wire:click o. Ae. als Attribut.
    - label: aria-label und title (Pflicht, sonst ist der Knopf stumm).
    - icon: Font-Awesome-Klassen (Standard far fa-chevron-right).
    - navigate: null = automatisch (interne Links per wire:navigate),
      true = immer wire:navigate, false = normale Navigation (data-no-navigate).
    Weitere Attribute (wire:click, wire:loading.*, class …) werden durchgereicht.
--}}
@props([
    'href' => null,
    'label',
    'icon' => 'far fa-chevron-right',
    'navigate' => null,
])

@php
    $linkAttributes = [];
    if ($href !== null) {
        $linkAttributes['href'] = $href;
        if ($navigate === true) {
            $linkAttributes['wire:navigate'] = true;
        } elseif ($navigate === false) {
            $linkAttributes['data-no-navigate'] = true;
        }
    } else {
        $linkAttributes['type'] = 'button';
    }
@endphp

<x-ui.buttons.button-basic
    mode="link"
    {{ $attributes->merge($linkAttributes)->merge(['class' => 'rt-table-row-action', 'aria-label' => $label, 'title' => $label]) }}
><i class="{{ $icon }}" aria-hidden="true"></i></x-ui.buttons.button-basic>
