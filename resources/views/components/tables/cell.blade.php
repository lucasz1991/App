{{--
    Eine Zelle in einem rowView-Partial von x-tables.table.

    - column: Spaltendefinition aus $columnsMeta; liefert label und hideOn.
    - label / hideOn: ueberschreiben die Werte aus column (oder ersetzen sie,
      wenn das Partial keine columnsMeta nutzt).
    - primary: Hauptzelle der Zeile; mobil ohne Label, volle Breite.
    - status: Statuszelle (rt-table-cell--status).
    - dense: kompakteres Padding (6px oben/unten) fuer dichte Fachlisten.
    - bare: ohne .rt-table-cell, also ohne Standard-Padding und Standard-
      Kartenlayout; fuer Zellen mit eigener Seiten-CSS (z. B. Posteingang).
    - mobileLabel: false unterdrueckt data-rt-table-label, wenn die Liste
      mobil bewusst ohne "Label vor Wert" auskommt (eigene Kartenoptik,
      einspaltige Listen).
    Weitere Attribute (class, wire:key, data-* …) werden durchgereicht.
--}}
@props([
    'column' => null,
    'label' => null,
    'hideOn' => null,
    'primary' => false,
    'status' => false,
    'dense' => false,
    'bare' => false,
    'mobileLabel' => true,
])

@php
    $label ??= is_array($column) ? ($column['label'] ?? null) : null;
    $hideOn ??= is_array($column) ? ($column['hideOn'] ?? 'none') : 'none';
    $hideClass = match ($hideOn) {
        'sm' => 'hidden sm:block',
        'md' => 'hidden md:block',
        'lg' => 'hidden lg:block',
        'xl' => 'hidden xl:block',
        default => '',
    };
    $showLabel = filter_var($mobileLabel, FILTER_VALIDATE_BOOL) && filled($label);
@endphp

<div role="cell" @if($showLabel) data-rt-table-label="{{ $label }}" @endif {{ $attributes->class([
    'rt-table-cell' => ! $bare,
    'rt-table-cell--primary' => $primary,
    'rt-table-cell--status' => $status,
    'rt-table-cell--dense' => $dense,
    'min-w-0',
    $hideClass,
]) }}>{{ $slot }}</div>
