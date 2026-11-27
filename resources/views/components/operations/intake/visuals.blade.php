@props(['demand' => [], 'order' => null, 'identity' => 'intake'])
@php
    $visual = \App\Support\Operations\AiIntakeVisualContext::forDemand($demand, $order);
    $mapKey = 'intake-location-'.hash('sha256', $identity.'|'.json_encode([$visual['location'], $visual['point'], $visual['mapLabel']]));
@endphp
<div {{ $attributes->class('rt-intake-visuals') }} data-intake-visuals>
    <div class="rt-intake-visuals__calendar">
        @if($visual['start'] && $visual['end'])
            <x-operations.timeline-mini-calendar :start="$visual['start']" :end="$visual['end']" variant="intake" :time-title="$visual['timeTitle']" />
        @else
            <div class="rt-intake-visuals__empty" role="status"><i class="far fa-calendar-alt" aria-hidden="true"></i><span>Zeitraum offen</span></div>
        @endif
    </div>
    @if($visual['start'] && $visual['end'])
        @php
            $start = $visual['start'];
            $end = $visual['end'];
            $startMinute = ($start->hour % 12) * 60 + $start->minute;
            $endMinute = ($end->hour % 12) * 60 + $end->minute;
            // Draw local clock time, including periods crossing midnight or a change of offset.
            $wallMinutes = ($end->timestamp - $start->timestamp + $end->offset - $start->offset) / 60;
            $arcMinutes = min(720, max(0, $wallMinutes));
        @endphp
        <figure class="rt-intake-visuals__clock" aria-label="Einsatzzeitraum {{ $visual['timeTitle'] }}">
            <svg class="rt-intake-visuals__dial" viewBox="-60 -60 120 120" aria-hidden="true">
                <circle r="54" fill="none" stroke="var(--intake-line)" />
                @foreach(range(0, 11) as $hour)
                    <path d="M0 -52v4" transform="rotate({{ $hour * 30 }})" stroke="currentColor" />
                @endforeach
                <g text-anchor="middle" fill="currentColor" font-size="10">
                    <text y="-36">12</text><text x="40" y="3">3</text><text y="43">6</text><text x="-40" y="3">9</text>
                </g>
                <circle r="30" fill="none" stroke="var(--intake-line)" stroke-width="5" />
                <circle r="30" pathLength="720" fill="none" stroke="var(--intake-accent)" stroke-width="5"
                    stroke-dasharray="{{ $arcMinutes }} 720" transform="rotate({{ $startMinute / 2 - 90 }})" />
                <path d="M0 0V-28" transform="rotate({{ $startMinute / 2 }})" stroke="currentColor" stroke-width="2.5" />
                <path d="M0 0V-32" transform="rotate({{ $endMinute / 2 }})" stroke="var(--intake-accent)" stroke-width="2" />
                <circle r="3" fill="currentColor" />
            </svg>
            <figcaption class="rt-intake-visuals__time" title="{{ $visual['timeTitle'] }}">{{ $visual['timeLabel'] }}</figcaption>
        </figure>
    @endif
    <figure class="rt-intake-visuals__map" data-map-state="{{ $visual['preview']['state'] }}" aria-label="{{ $visual['mapLabel'] }}" wire:key="{{ $mapKey }}">
        @if($visual['point'])
            <div class="rt-intake-visuals__map-stage" x-data="rtIntakeLocationMap(@js($visual['point']))">
                <div class="rt-intake-visuals__map-canvas" x-ref="canvas" wire:ignore role="region" aria-label="{{ $visual['mapLabel'] }}"></div>
                <span x-cloak x-show="status === 'error'" class="text-xs">{{ $visual['point']['latitude'] }}, {{ $visual['point']['longitude'] }}</span>
                <span class="rt-intake-visuals__map-status" x-show="status !== 'ready'" x-text="status === 'error' ? 'Karte nicht verfügbar' : 'Karte wird geladen'" role="status">Karte wird geladen</span>
            </div>
        @else
            <div class="rt-intake-visuals__empty" role="status"><i class="far fa-map-marker-alt" aria-hidden="true"></i><span>{{ ($visual['preview']['state'] ?? '') === 'multiple' ? 'Mehrere Einsatzorte' : (($visual['preview']['lookupDisabled'] ?? false) ? 'Kartenposition offen · TomTom nicht eingerichtet' : 'Kartenposition offen') }}</span></div>
        @endif
        <x-operations.worksite-lookup :preview="($visual['point'] && ($visual['preview']['state'] ?? '') === 'outside') ? [] : $visual['preview']" />
        <figcaption class="rt-intake-visuals__caption" title="{{ $visual['mapLabel'] }}"><i class="far fa-map-marker-alt" aria-hidden="true"></i><span>{{ $visual['location'] }}</span></figcaption>
    </figure>
</div>
