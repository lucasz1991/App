@props(['preview', 'location' => null])
@php
    $located = ($preview['state'] ?? null) === 'located' && isset($preview['x'], $preview['y']);
    $mapLabel = $located ? 'Deutschland: '.$preview['place'].' – ungefähre Ortslage, keine genaue Einsatzadresse.' : 'Deutschland: '.$preview['label'];
@endphp
<figure class="rt-event-mini-map" data-map-state="{{ $preview['state'] }}">
    <div class="rt-event-mini-map__heading">Deutschland</div>
    <svg class="rt-event-mini-map__canvas" viewBox="{{ \App\Support\Operations\TimelineLocationPreview::viewBox() }}" role="img" aria-label="{{ $mapLabel }}">
        <path class="rt-event-mini-map__outline" d="{{ \App\Support\Operations\TimelineLocationPreview::outlinePath() }}" />
        @if($located)
            <g class="rt-event-mini-map__marker" transform="translate({{ $preview['x'] }} {{ $preview['y'] }})" data-location-marker>
                <circle r="10" class="rt-event-mini-map__halo" />
                <circle r="4" class="rt-event-mini-map__dot" />
            </g>
        @endif
    </svg>
    <figcaption>
        <strong class="rt-event-mini-map__place" title="{{ $location ?: ($preview['place'] ?? 'Kein Einsatzort hinterlegt') }}">{{ $located ? $preview['place'] : ($location ?: 'Kein Einsatzort') }}</strong>
        <span class="rt-event-mini-map__precision">{{ $preview['label'] }}</span>
    </figcaption>
</figure>
