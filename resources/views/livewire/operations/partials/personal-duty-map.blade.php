{{-- Mini-Deutschlandkarte mit den Einsatzorten der nächsten 14 Tage (gerundete Positionen, keine Adressen). --}}
@php
    $mapPoints = collect($dutyMap['points']);
@endphp
<section class="rt-today-card rt-duty-map rt-today-enter" style="--i:5" aria-labelledby="rt-duty-map-title">
    <header class="rt-today-card__head">
        <div>
            <h3 id="rt-duty-map-title">Einsatzorte</h3>
            <p>Nächste 14 Tage</p>
        </div>
        @if($mapPoints->isNotEmpty())<span class="rt-duty-map__count">{{ $mapPoints->count() }} {{ $mapPoints->count() === 1 ? 'Ort' : 'Orte' }}</span>@endif
    </header>
    <div class="rt-duty-map__body">
        <x-ui.germany-map class="rt-duty-map__canvas" :points="$mapPoints->map(fn ($point) => $point + ['title' => $point['label'].' · '.$point['date'], 'pulse' => $point['next']])->all()" />
        <div class="rt-duty-map__side">
            @if($mapPoints->isEmpty())
                <p class="rt-duty-map__empty">{{ $dutyMap['total'] ? 'Für deine nächsten Dienste ist noch kein Ort auf der Karte hinterlegt.' : 'Keine Einsätze in den nächsten 14 Tagen.' }}</p>
            @else
                <ol class="rt-duty-map__list">
                    @foreach($mapPoints->take(4) as $point)
                        <li @if($point['next']) data-next @endif>
                            <i aria-hidden="true"></i>
                            <span><strong>{{ $point['label'] }}</strong><small>{{ $point['next'] ? 'Als Nächstes · ' : '' }}{{ $point['date'] }}@if($point['count'] > 1) · {{ $point['count'] }} Dienste @endif</small></span>
                        </li>
                    @endforeach
                </ol>
                @if($mapPoints->count() > 4)<p class="rt-duty-map__note">+ {{ $mapPoints->count() - 4 }} weitere Orte</p>@endif
            @endif
            @if($dutyMap['unplaced'])<p class="rt-duty-map__note">{{ $dutyMap['unplaced'] }} {{ $dutyMap['unplaced'] === 1 ? 'Dienst' : 'Dienste' }} ohne Kartenposition</p>@endif
        </div>
    </div>
</section>
