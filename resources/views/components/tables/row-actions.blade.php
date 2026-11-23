{{--
    Einziges Mehrfach-Aktionsmenue einer Tabellenzeile (actionsView von x-tables.table).

    - label: aria-label des Drei-Punkte-Knopfs (Standard: "Aktionen").
    - width/align: Breite und Ausrichtung des Menues (wie anchor-dropdown).
    - dropdown-id/layer-group: werden an anchor-dropdown durchgereicht.
    - Alle weiteren Attribute (class, x-on:*, data-*) landen am Ausloeser,
      z. B. x-on:keydown.enter.stop, damit Enter nicht die Zeile oeffnet.
    - Slot: x-ui.dropdown.dropdown-link-Eintraege; Icons ohne mr-2, den
      Abstand setzt der Eintrag selbst (gap).
--}}
@props([
    'label' => null,
    'width' => '48',
    'align' => 'right',
    'dropdownId' => null,
    'layerGroup' => null,
])

<x-ui.dropdown.anchor-dropdown :align="$align" :width="$width" :dropdown-id="$dropdownId" :layer-group="$layerGroup">
    <x-slot:trigger>
        <x-ui.dropdown.action-trigger orientation="vertical" :aria-label="$label" {{ $attributes }} />
    </x-slot:trigger>
    <x-slot:content>
        {{ $slot }}
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
