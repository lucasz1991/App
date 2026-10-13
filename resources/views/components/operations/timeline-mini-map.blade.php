@props(['preview', 'location' => null])
@php
    $points = \App\Support\Operations\GermanyMap::pointsFromPreview($preview);
    $located = $points !== [];
    $precisionLabel = ($preview['confirmed'] ?? false) ? 'bestätigter Einsatzstandort.' : (($preview['estimated'] ?? false) ? 'Ortsvorschlag, keine bestätigte Einsatzadresse.' : 'ungefähre Ortslage, keine genaue Einsatzadresse.');
    $mapLabel = $located ? 'Deutschland: '.$preview['place'].' – '.$precisionLabel : 'Deutschland: '.$preview['label'];
    $pins = array_map(fn (array $point): array => $point + ['tone' => 'open', 'marker' => true, 'title' => count($points) > 1 ? $point['label'] : ($location ?: $point['label'])], $points);
@endphp
<figure class="rt-event-mini-map" data-map-state="{{ $preview['state'] }}">
    <div class="rt-event-mini-map__heading">Deutschland</div>
    <x-ui.germany-map class="rt-event-mini-map__canvas" :height="112" :label="$mapLabel"
        :points="$pins" />
    <figcaption>
        <strong class="rt-event-mini-map__place" title="{{ $location ?: ($preview['place'] ?? 'Kein Einsatzort hinterlegt') }}">{{ $located ? $preview['place'] : ($location ?: 'Kein Einsatzort') }}</strong>
        <span class="rt-event-mini-map__precision">{{ $preview['label'] }}</span>
    </figcaption>
</figure>
