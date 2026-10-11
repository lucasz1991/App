{{--
    Deutschlandkarte mit Bundesländergrenzen und Punkten. Positionen kommen aus
    App\Support\Operations\GermanyMap (Prozent der Kartenfläche, nie Koordinaten).

    points: [['left' => 35.0, 'top' => 28.0, 'label' => 'Hamburg', 'title' => '…',
              'tone' => 'open|awaiting|staffed|closed', 'size' => 12, 'pulse' => true, 'marker' => true], …]
    height: Grundhöhe in Pixeln; jede Stelle kann Höhe und Breite per CSS überschreiben,
            das Seitenverhältnis der Karte bleibt erhalten.
--}}
@props(['points' => [], 'height' => 270, 'label' => null])
@php
    $points = collect($points);
    $mapLabel = $label ?? ('Deutschlandkarte'.($points->isNotEmpty() ? ' mit '.$points->pluck('label')->filter()->unique()->implode(', ') : ''));
    $mapStyle = '--rt-map-default-height: '.max(80, (int) $height).'px; --rt-map-land: url('.asset('rt-brand/illustrations/germany-outline.svg').'); --rt-map-borders: url('.asset('rt-brand/illustrations/germany-borders.svg').')';
@endphp
<div {{ $attributes->class('rt-germany-map') }} style="{{ $mapStyle }}" role="img" aria-label="{{ $mapLabel }}">
    <div class="rt-germany-map__stage">
        <span class="rt-germany-map__land" aria-hidden="true"></span>
        <span class="rt-germany-map__borders" aria-hidden="true"></span>
        @foreach($points as $point)
            @php
                $pinStyle = 'left: '.(float) $point['left'].'%; top: '.(float) $point['top'].'%'.(empty($point['size']) ? '' : '; --pin-size: '.(int) $point['size'].'px');
                $pinPulse = ! empty($point['pulse']);
                $pinMarker = ! empty($point['marker']);
            @endphp
            <span class="rt-germany-map__pin" aria-hidden="true" style="{{ $pinStyle }}" data-tone="{{ $point['tone'] ?? 'default' }}" @if($pinPulse) data-pulse @endif @if($pinMarker) data-location-marker @endif title="{{ $point['title'] ?? $point['label'] ?? '' }}"></span>
        @endforeach
    </div>
    {{ $slot }}
</div>
