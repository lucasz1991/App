<div x-data="rtTravelPreview($wire)">
@if($displayAssessments !== [])
<section class="rt-travel" aria-label="Fahrzeiten und Routen" x-intersect.once="if (!@js($loaded) && !@js($parentManaged)) $wire.load()">
    <header class="rt-travel__header">
        <h3>Fahrten</h3>
        <button type="button" class="rt-travel__icon" wire:click="load" wire:loading.attr="disabled" aria-label="Fahrzeiten neu prüfen" title="Neu prüfen">
            <i class="far fa-redo" aria-hidden="true" wire:loading.class="fa-spin" wire:target="load"></i>
        </button>
    </header>
    <span class="sr-only" wire:loading wire:target="load">Fahrzeiten werden geprüft.</span>
    @error('travel')
        <p role="status" class="rt-travel__notice">{{ $message }}</p>
    @enderror
    @foreach($displayAssessments as $employeeId => $assessment)
        @php
            $state = $assessment['display_state'];
            $stateLabels = ['known' => 'Übergänge geprüft', 'conflict' => 'Fahrzeitkonflikt', 'unknown' => 'Fahrt ungeprüft', 'pending' => 'Fahrt noch nicht geprüft', 'no_transition' => 'Keine benachbarten Einsätze', 'disabled' => 'Fahrzeitprüfung deaktiviert'];
        @endphp
        <div class="rt-travel__person" wire:key="travel-{{ $shiftId }}-{{ $revision }}-{{ $employeeId }}">
            @if(count($displayAssessments) > 1)
                <strong class="rt-travel__name">{{ $names[$employeeId] ?? 'Mitarbeiter' }}</strong>
            @endif
            @if(in_array($state, ['disabled', 'no_transition'], true))
                <p class="rt-travel__empty">{{ $stateLabels[$state] }}</p>
            @else
                <span class="sr-only">{{ $stateLabels[$state] }}</span>
                @foreach($assessment['travel'] as $direction => $leg)
                    @include('livewire.operations.partials.travel-route-preview', ['arrivalMode' => false])
                @endforeach
            @endif
            @if(isset($assessment['arrival']))
                @include('livewire.operations.partials.travel-route-preview', ['arrivalMode' => true, 'direction' => 'arrival', 'leg' => $assessment['arrival']])
            @endif
        </div>
    @endforeach
    @if(!$loaded && $assessments === [])
        <p role="status" class="rt-travel__empty">Fahrten werden geladen …</p>
    @endif
</section>
@endif
</div>
