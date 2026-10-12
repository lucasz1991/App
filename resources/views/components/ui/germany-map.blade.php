{{--
    Deutschlandkarte mit Bundesländergrenzen und Punkten. Positionen kommen aus
    App\Support\Operations\GermanyMap (Prozent der Kartenfläche, nie Koordinaten).

    points: [['left' => 35.0, 'top' => 28.0, 'label' => 'Hamburg', 'title' => '…',
              'tone' => 'open|awaiting|staffed|closed', 'size' => 12, 'pulse' => true, 'marker' => true], …]
    height: Grundhöhe in Pixeln; jede Stelle kann Höhe und Breite per CSS überschreiben,
            das Seitenverhältnis der Karte bleibt erhalten.
    markers: optionale interaktive Kartenpunkte auf derselben Bühne; interactive
             erhält deren Semantik als Bedienelemente innerhalb einer Gruppe.
    highlightable: keyed points werden über einen umgebenden rtMapHighlight-Zustand
                   mit separatem Rahmen hervorgehoben; andere Karten bleiben unabhängig.
--}}
@props(['points' => [], 'height' => 270, 'label' => null, 'interactive' => false, 'highlightable' => false])
@php
    $points = collect($points);
    $mapLabel = $label ?? ('Deutschlandkarte'.($points->isNotEmpty() ? ' mit '.$points->pluck('label')->filter()->unique()->implode(', ') : ''));
    $mapStyle = '--rt-map-default-height: '.max(80, (int) $height).'px; --rt-map-land: url('.asset('rt-brand/illustrations/germany-outline.svg').'); --rt-map-borders: url('.asset('rt-brand/illustrations/germany-borders.svg').')';
@endphp
<div {{ $attributes->class('rt-germany-map') }} style="{{ $mapStyle }}" role="{{ $interactive ? 'group' : 'img' }}" aria-label="{{ $mapLabel }}">
    <div class="rt-germany-map__stage">
        <span class="rt-germany-map__land" aria-hidden="true"></span>
        <span class="rt-germany-map__borders" aria-hidden="true"></span>
        @foreach($points as $point)
            @php
                $pinStyle = 'left: '.(float) $point['left'].'%; top: '.(float) $point['top'].'%'.(empty($point['size']) ? '' : '; --pin-size: '.(int) $point['size'].'px');
                $pinPulse = ! empty($point['pulse']);
                $pinMarker = ! empty($point['marker']);
                $pinKey = $highlightable && is_string($point['key'] ?? null) ? trim($point['key']) : '';
            @endphp
            @if($pinKey !== '')
                <span class="rt-map-highlight" aria-hidden="true" style="{{ $pinStyle }}" data-map-highlight="{{ $pinKey }}" data-map-active="false" x-bind:data-map-active="mapIsActive(@js($pinKey))"></span>
            @endif
            <span class="rt-germany-map__pin" aria-hidden="true" style="{{ $pinStyle }}" data-tone="{{ $point['tone'] ?? 'default' }}" @if($pinPulse) data-pulse @endif @if($pinMarker) data-location-marker @endif @if($pinKey !== '') data-map-point="{{ $pinKey }}" x-bind:data-map-active="mapIsActive(@js($pinKey))" @endif title="{{ $point['title'] ?? $point['label'] ?? '' }}"></span>
        @endforeach
        {{ $markers ?? '' }}
    </div>
    {{ $slot }}
</div>
