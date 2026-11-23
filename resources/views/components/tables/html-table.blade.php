@props([
    'label' => null,
    'caption' => null,
    'dense' => false,
    'tableClass' => '',
])

@php
    $dense = filter_var($dense, FILTER_VALIDATE_BOOL);
@endphp

{{--
    Schlanker Rahmen fuer echte HTML-Tabellen (Matrizen, Kennzahlen,
    Protokolle), wo x-tables.table mit Zeilen-Partial nicht passt.
    Der Rahmen scrollt horizontal (data-table-html-frame), Kopf und Zellen
    bekommen ihren Stil aus table-surface.css.

    Slots: head (Zeilen fuer <thead>), Standard-Slot (Zeilen fuer <tbody>).
    tableClass: Zusatzklassen am <table>, z. B. eine Mindestbreite.
--}}
<div {{ $attributes->class([
    'rt-html-table min-w-0 max-w-full rounded-xl border border-rt-border bg-rt-surface dark:border-rt-dark-border dark:bg-rt-dark-surface',
    'rt-html-table--dense' => $dense,
]) }} data-table-html-frame>
    <table class="rt-html-table__table w-full text-left {{ $dense ? 'text-xs' : 'text-sm' }} {{ $tableClass }}" @if(filled($label)) aria-label="{{ $label }}" @endif>
        @if(filled($caption))
            <caption class="sr-only">{{ $caption }}</caption>
        @endif
        @isset($head)
            <thead class="rt-html-table__head">{{ $head }}</thead>
        @endisset
        <tbody class="rt-html-table__body">{{ $slot }}</tbody>
    </table>
</div>
