@php
    $workloadLabel = $workload['percent'] !== null ? $workload['percent'].' % verplant' : ($workload['target'] === 0 ? 'Kein Soll im Zeitraum' : 'Auslastung ohne Sollquote');
    $hours = static fn ($minutes) => number_format($minutes / 60, 1, ',', '.').' h';
@endphp
<x-ui.dropdown.anchor-dropdown align="left" width="80" :open-on-hover="true" :hover-open-delay="180" content-role="dialog" content-label="Auslastung von {{ $person->name }}" dropdown-id="timeline-workload-{{ $this->getId() }}-{{ $person->id }}" layer-group="staff-timeline-events" class="rt-timeline-workload-anchor" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
    <x-slot:trigger>
        <button type="button" class="rt-timeline-workload" data-state="{{ $workload['state'] }}" data-target="{{ $workload['target'] === null ? 'unknown' : ($workload['target'] === 0 ? 'zero' : 'known') }}" aria-label="Auslastung {{ $person->name }}: {{ $workloadLabel }}. Details anzeigen">
            @if($workload['target'] === null)
                <span class="rt-timeline-workload__unknown" aria-hidden="true">–</span>
            @else
                <svg viewBox="0 0 32 32" aria-hidden="true"><circle class="rt-timeline-workload__track" cx="16" cy="16" r="12" /><circle class="rt-timeline-workload__fill" cx="16" cy="16" r="12" pathLength="100" stroke-dasharray="{{ $workload['ratio'] !== null ? min(100, max(0, $workload['ratio'] * 100)) : 0 }} 100" /></svg>
                <span aria-hidden="true">{{ $workload['state'] === 'over' ? '!' : ($workload['percent'] === null ? '–' : '') }}</span>
            @endif
        </button>
    </x-slot:trigger>
    <x-slot:content>
        <article class="rt-timeline-workload-detail" data-rt-dropdown-keep-open>
            <header><strong>{{ $person->name }}</strong><span>{{ $days->first()->format('d.m.') }} – {{ $days->last()->format('d.m.Y') }}</span></header>
            <div class="rt-timeline-workload-detail__total"><strong>{{ $hours($workload['planned']) }}</strong><span>{{ $workloadLabel }}</span></div>
            <dl>
                <div><dt>Bestätigte Schichten</dt><dd>{{ $hours($workload['confirmed']) }}</dd></div>
                <div><dt>Angefragte Schichten</dt><dd>{{ $hours($workload['requested']) }}</dd></div>
                <div><dt>Schulungen</dt><dd>{{ $hours($workload['training']) }}</dd></div>
                <div><dt>Soll im Zeitraum</dt><dd>{{ $workload['target'] !== null ? $hours($workload['target']) : 'Nicht vollständig hinterlegt' }}</dd></div>
                @if($workload['target'] !== null)<div><dt>{{ $workload['planned'] > $workload['target'] ? 'Über Soll' : 'Noch bis Soll' }}</dt><dd>{{ $hours(abs($workload['target'] - $workload['planned'])) }}</dd></div>@endif
                <div><dt>Schichten / Abwesenheiten</dt><dd>{{ $workload['shift_count'] }} / {{ $workload['absence_count'] }}</dd></div>
            </dl>
            <p>Planungsstand, keine Ist-Zeiten. Soll aus freigegebenen Tageswerten; Abwesenheiten sind nicht abgezogen. Freie Stunden bedeuten nicht automatisch Verfügbarkeit.</p>
            @if($workload['clipped_breaks'])<p>Bei angeschnittenen Schichten ist die Pausenlage unbekannt; diese Stunden enthalten die Pause.</p>@endif
        </article>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
