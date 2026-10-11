{{-- Schichtplan als Karte: gefilterte Schichten je Einsatzort, Farbe = dringlichster Besetzungsstand. --}}
@php
    $mapPoints = collect($planMap['points']);
    $toneLabels = ['open' => 'Offene Plätze', 'awaiting' => 'Rückmeldung offen', 'staffed' => 'Besetzt', 'closed' => 'Abgeschlossen'];
    $maxCount = max(1, (int) $mapPoints->max('count'));
    $pins = $mapPoints->map(fn (array $point): array => $point + [
        'size' => 9 + (int) round(9 * $point['count'] / $maxCount),
        'title' => $point['label'].' · '.$point['count'].' '.($point['count'] === 1 ? 'Schicht' : 'Schichten').($point['open'] ? ' · '.$point['open'].' offen' : ''),
    ])->all();
@endphp
<section class="rt-shift-plan-map" aria-label="Schichten auf der Karte" data-shift-plan-map>
    <div class="rt-shift-plan-map__canvas">
        <x-ui.germany-map :height="560" :points="$pins" :label="'Deutschlandkarte mit '.$mapPoints->count().' Einsatzorten'" />
        <ul class="rt-shift-plan-map__legend" aria-label="Legende">
            @foreach($toneLabels as $tone => $toneLabel)
                <li><i data-tone="{{ $tone }}" aria-hidden="true"></i>{{ $toneLabel }}</li>
            @endforeach
        </ul>
    </div>
    <div class="rt-shift-plan-map__side">
        <header class="rt-shift-plan-map__head">
            <h2>Einsatzorte</h2>
            <p>{{ $mapPoints->count() }} {{ $mapPoints->count() === 1 ? 'Ort' : 'Orte' }} · {{ $planMap['total'] }} {{ $planMap['total'] === 1 ? 'Schicht' : 'Schichten' }} im Zeitraum</p>
        </header>
        @if($mapPoints->isEmpty())
            <p class="rt-shift-plan-map__empty">{{ $planMap['total'] ? 'Für die Schichten in diesem Zeitraum ist kein Einsatzort eindeutig auf der Karte verortet.' : 'Keine Schichten für diese Filter.' }}</p>
        @else
            <ol class="rt-shift-plan-map__list">
                @foreach($mapPoints as $point)
                    <li wire:key="shift-map-place-{{ md5($point['label']) }}">
                        <button type="button" class="rt-shift-plan-map__place" data-place="{{ $point['label'] }}" x-on:click="$wire.showLocation($el.dataset.place)" title="Schichten in {{ $point['label'] }} als Tabelle zeigen">
                            <i data-tone="{{ $point['tone'] }}" aria-hidden="true"></i>
                            <span>
                                <strong>{{ $point['label'] }}</strong>
                                <small>{{ $point['count'] }} {{ $point['count'] === 1 ? 'Schicht' : 'Schichten' }}@if($point['open']) · <em>{{ $point['open'] }} offen</em>@endif</small>
                            </span>
                            <i class="far fa-chevron-right rt-shift-plan-map__chevron" aria-hidden="true"></i>
                        </button>
                    </li>
                @endforeach
            </ol>
        @endif
        @if($planMap['unplaced'])
            <p class="rt-shift-plan-map__note"><i class="far fa-info-circle" aria-hidden="true"></i>{{ $planMap['unplaced'] }} {{ $planMap['unplaced'] === 1 ? 'Schicht hat' : 'Schichten haben' }} keinen eindeutig verorteten Einsatzort.</p>
        @endif
    </div>
</section>
