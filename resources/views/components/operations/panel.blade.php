@props([
    'id',
    'label' => 'Details',
    'showExpression' => null,
])
{{--
    Rechtes Arbeits-Panel der Disposition (Struktur: resources/css/operations-panel.css).
    Ein Panel kann mehrere Zustände tragen (Ansehen, Bearbeiten). Jeder Zustand bringt
    Kopf, Reiter, Rumpf und Fuß über die Bausteine x-operations.panel.* selbst mit.
    Geometrie und Einfahrt kommen aus .rt-disposition-drawer (disposition-workspace.css).
--}}
<x-modal :id="$id" placement="right" max-width="4xl" :show-expression="$showExpression" role="dialog" aria-labelledby="{{ $id }}-label" {{ $attributes }}>
    <div class="rt-ops-panel rt-ops rt-disposition" data-ops-panel>
        <span id="{{ $id }}-label" class="sr-only">{{ $label }}</span>
        {{ $slot }}
    </div>
</x-modal>
