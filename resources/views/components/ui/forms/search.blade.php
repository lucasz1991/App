@props([
    'resultsCount' => null,
    'placeholder' => null,
    'context' => 'table',
    'status' => null,
    'statusLabel' => null,
])

{{--
    Semantischer App-Einstieg fuer alle Suchfelder. Der bestehende Tabellen-
    Adapter bleibt absichtlich die technische Quelle, damit Topbar-, Listen-
    und Livewire-Vertraege nicht auseinanderlaufen.
--}}
<x-tables.search-field
    :results-count="$resultsCount"
    :placeholder="$placeholder"
    :context="$context"
    :status="$status"
    :status-label="$statusLabel"
    :input-attributes="$attributes"
/>
