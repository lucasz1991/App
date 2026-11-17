@props(['demand' => [], 'order' => null, 'identity' => 'intake'])
@php
    $visual = \App\Support\Operations\AiIntakeVisualContext::forDemand($demand, $order);
    $mapKey = 'intake-location-'.hash('sha256', $identity.'|'.json_encode([$visual['location'], $visual['point'], $visual['mapLabel']]));
@endphp
<div {{ $attributes->class('rt-intake-visuals') }} data-intake-visuals>
    <div class="rt-intake-visuals__calendar">
        @if($visual['start'] && $visual['end'])
            <x-operations.timeline-mini-calendar :start="$visual['start']" :end="$visual['end']" variant="intake" :time-label="$visual['timeLabel']" :time-title="$visual['timeTitle']" />
        @else
            <div class="rt-intake-visuals__empty" role="status"><i class="far fa-calendar-alt" aria-hidden="true"></i><span>Zeitraum offen</span></div>
        @endif
    </div>
    <figure class="rt-intake-visuals__map" data-map-state="{{ $visual['preview']['state'] }}" aria-label="{{ $visual['mapLabel'] }}" wire:key="{{ $mapKey }}">
        @if($visual['point'])
            <div class="rt-intake-visuals__map-stage" x-data="rtIntakeLocationMap(@js($visual['point']))">
                <div class="rt-intake-visuals__map-canvas" x-ref="canvas" wire:ignore role="img" aria-label="{{ $visual['mapLabel'] }}"></div>
                <span class="rt-intake-visuals__map-status" x-show="status !== 'ready'" x-text="status === 'error' ? 'Karte nicht verfügbar' : 'Karte wird geladen'" role="status">Karte wird geladen</span>
            </div>
        @else
            <div class="rt-intake-visuals__empty" role="status"><i class="far fa-map-marker-alt" aria-hidden="true"></i><span>{{ ($visual['preview']['state'] ?? '') === 'multiple' ? 'Mehrere Einsatzorte' : 'Kartenposition offen' }}</span></div>
        @endif
        <figcaption class="rt-intake-visuals__caption" title="{{ $visual['mapLabel'] }}"><i class="far fa-map-marker-alt" aria-hidden="true"></i><span>{{ $visual['location'] }}</span></figcaption>
    </figure>
</div>
