{{--
    Gemeinsame Huelle der Dashboard-Diagramme (x-dashboard.chart.*).
    Alle Diagramme der Familie teilen denselben Vertrag:
      - type:      sparkline | columns | donut (landet als data-dashboard-chart)
      - ariaLabel: Kurzbeschreibung fuer Screenreader; mit Text wird die Huelle
                   role="img", ohne bleibt sie neutral (sichtbarer Wert lesbar)
      - weitere Attribute (class, style, wire:key ...) gehen an das Wurzelelement.
    Die Datenprops (values, labels ...) bleiben je Diagramm, heissen aber gleich.
--}}
@props(['type', 'ariaLabel' => ''])
<div {{ $attributes->merge([
    'role' => filled($ariaLabel) ? 'img' : null,
    'aria-label' => filled($ariaLabel) ? $ariaLabel : null,
    'data-dashboard-chart' => $type,
]) }}>{{ $slot }}</div>
