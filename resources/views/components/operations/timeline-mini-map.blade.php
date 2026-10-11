@props(['preview', 'location' => null])
@php
    $point = \App\Support\Operations\GermanyMap::fromPreview($preview);
    $located = $point !== null;
    $precisionLabel = ($preview['confirmed'] ?? false) ? 'bestätigter Einsatzstandort.' : 'ungefähre Ortslage, keine genaue Einsatzadresse.';
    $mapLabel = $located ? 'Deutschland: '.$preview['place'].' – '.$precisionLabel : 'Deutschland: '.$preview['label'];
@endphp
<figure class="rt-event-mini-map" data-map-state="{{ $preview['state'] }}">
    <div class="rt-event-mini-map__heading">Deutschland</div>
    <x-ui.germany-map class="rt-event-mini-map__canvas" :height="112" :label="$mapLabel"
        :points="$located ? [$point + ['tone' => 'open', 'marker' => true, 'title' => $location ?: $point['label']]] : []" />
    <figcaption>
        <strong class="rt-event-mini-map__place" title="{{ $location ?: ($preview['place'] ?? 'Kein Einsatzort hinterlegt') }}">{{ $located ? $preview['place'] : ($location ?: 'Kein Einsatzort') }}</strong>
        <span class="rt-event-mini-map__precision">{{ $preview['label'] }}</span>
    </figcaption>
</figure>
