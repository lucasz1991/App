<article class="employee-operations__duty {{ $featured ? 'employee-operations__duty--featured' : '' }}" data-employee-shift-preview="{{ $shift['id'] }}">
    <div class="employee-operations__duty-date">
        @if($shift['dates_available'])
            <span>{{ $shift['start_date'] }}</span><strong>{{ $shift['start_time'] }}</strong><span>bis {{ $shift['end_date'] === $shift['start_date'] ? '' : $shift['end_date'].' · ' }}{{ $shift['end_time'] }}</span>
        @else
            <span>Zeitraum prüfen</span><strong>—</strong><span>Nicht vollständig hinterlegt</span>
        @endif
    </div>
    <div class="employee-operations__duty-main">
        <div class="employee-operations__duty-labels">
            <x-ui.badge :color="$shift['assignment_status_tone']" aria-label="Zuordnung: {{ $shift['assignment_status_label'] }}">{{ $shift['assignment_status_label'] }}</x-ui.badge>
            @if($shift['ongoing'] && ! $shift['closed'])<span class="employee-operations__time-state">Aktuell geplant</span>@endif
        </div>
        <h4><a href="{{ $shift['url'] }}" wire:navigate>{{ $shift['title'] }}</a></h4>
        <p class="employee-operations__location"><i class="far fa-location-dot" aria-hidden="true"></i>{{ $shift['location'] ?: 'Einsatzort nicht hinterlegt' }}</p>
        <p class="employee-operations__duty-meta">{{ $shift['role'] ?: 'Funktion nicht hinterlegt' }}<span aria-hidden="true"> · </span>{{ $shift['customer'] ?: 'Kunde nicht hinterlegt' }}</p>
        <p class="employee-operations__publication">Schicht: {{ $shift['shift_status_label'] }} · {{ $shift['publication_label'] }} · {{ $shift['timezone'] }}</p>
        @if(! empty($shift['assignment_revision_note']))<p class="employee-operations__response-note">{{ $shift['assignment_revision_note'] }}</p>@endif
    </div>
    <a class="employee-operations__open" href="{{ $shift['url'] }}" wire:navigate aria-label="Schicht öffnen: {{ $shift['title'] }}"><i class="far fa-arrow-right" aria-hidden="true"></i></a>
</article>
