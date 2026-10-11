<div role="cell" class="employee-operations__cell employee-operations__cell--schedule">
    @if($item->dates_available)
        <span>{{ $item->start_date }}</span>
        <strong><time datetime="{{ $item->starts_at }}">{{ $item->start_time }}</time> – <time datetime="{{ $item->ends_at }}">{{ $item->end_time }}</time></strong>
        @if($item->end_date !== $item->start_date)<span>bis {{ $item->end_date }}</span>@endif
    @else
        <strong>Zeitraum prüfen</strong><span>Nicht vollständig hinterlegt</span>
    @endif
    <small>{{ $item->timezone }}</small>
</div>
<div role="cell" class="employee-operations__cell employee-operations__cell--shift">
    <a href="{{ $item->url }}" wire:navigate class="employee-operations__shift-title">{{ $item->title }}</a>
    <span>{{ $item->location ?: 'Einsatzort nicht hinterlegt' }}</span>
    <small>{{ $item->role ?: 'Funktion nicht hinterlegt' }} · {{ $item->customer ?: 'Kunde nicht hinterlegt' }}</small>
    <div class="employee-operations__mobile-status"><x-ui.badge :color="$item->assignment_status_tone" aria-label="Zuordnung: {{ $item->assignment_status_label }}">{{ $item->assignment_status_label }}</x-ui.badge><span>Schicht: {{ $item->shift_status_label }} · {{ $item->publication_label }}</span>@if(! empty($item->assignment_revision_note))<span class="employee-operations__response-note">{{ $item->assignment_revision_note }}</span>@endif</div>
</div>
<div role="cell" class="employee-operations__cell employee-operations__cell--status hidden md:flex">
    <x-ui.badge :color="$item->assignment_status_tone" aria-label="Zuordnung: {{ $item->assignment_status_label }}">{{ $item->assignment_status_label }}</x-ui.badge>
    <span>Schicht: {{ $item->shift_status_label }}</span>
    <small>{{ $item->publication_label }}</small>
    @if(! empty($item->assignment_revision_note))<small class="employee-operations__response-note">{{ $item->assignment_revision_note }}</small>@endif
</div>
