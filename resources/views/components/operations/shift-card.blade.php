@props(['shift', 'timezone', 'compact' => false])
@php
    $activeAssignments = $shift->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability());
    $reserved = $activeAssignments->count();
    $confirmed = $activeAssignments->where('status', \App\Enums\ShiftAssignmentStatus::Confirmed)->count();
    $closed = in_array($shift->status, [\App\Enums\ShiftStatus::Completed, \App\Enums\ShiftStatus::Cancelled], true);
    $missing = $closed ? 0 : max(0, $shift->required_staff - $reserved);
    $start = $shift->starts_at->copy()->setTimezone($timezone);
    $end = $shift->ends_at->copy()->setTimezone($timezone);
    $coverage = min(100, (int) round($reserved / max(1, $shift->required_staff) * 100));
@endphp
<button type="button" {{ $attributes->class(['rt-disposition-shift', 'rt-disposition-shift--compact' => $compact]) }} wire:click="openDetails({{ $shift->id }})" wire:loading.attr="disabled" wire:target="openDetails" aria-label="Schichtdetails: {{ $shift->title }}, {{ $start->format('d.m. H:i') }}" data-open="{{ $missing > 0 ? 'true' : 'false' }}">
    <span class="rt-disposition-shift__time"><strong>{{ $start->format('H:i') }}–{{ $end->format('H:i') }}</strong><span>{{ $start->format('d.m.') }} @if(!$start->isSameDay($end))–{{ $end->format('d.m.') }}@endif</span></span>
    <span class="rt-disposition-shift__task"><span class="rt-disposition-record-code">{{ $shift->order?->order_number }}</span><strong>{{ $shift->title }}</strong><span class="rt-disposition-record-meta">{{ $shift->order?->customer?->company_name }} · {{ $shift->role_name ?: 'Tätigkeit offen' }}</span><span class="rt-disposition-record-meta"><i class="far fa-location-dot" aria-hidden="true"></i> {{ $shift->location_name ?: $shift->order?->location_name ?: 'Einsatzort offen' }}</span></span>
    <span class="rt-disposition-shift__staff"><strong>{{ $reserved }}/{{ $shift->required_staff }} eingeplant</strong><span class="rt-disposition-coverage" aria-hidden="true"><span style="width: {{ $coverage }}%"></span></span><span class="rt-disposition-record-meta">{{ $confirmed }} bestätigt @if($missing > 0) · <span class="rt-disposition-warning">{{ $missing }} {{ $missing === 1 ? 'Platz offen' : 'Plätze offen' }}</span>@endif</span>
        @if($shift->planning_conflict_count > 0)<span class="rt-disposition-warning">{{ $shift->planning_conflict_count }} mit Konflikt</span>@endif
        @if($shift->feedback_pending > 0)<span class="rt-disposition-record-meta">{{ $shift->feedback_pending }} Rückmeldung ausstehend</span>@endif
        @if($shift->feedback_declined > 0)<span class="rt-disposition-warning">{{ $shift->feedback_declined }} abgelehnt</span>@endif
    </span>
    <span class="rt-disposition-shift__state"><x-operations.status :value="$shift->status->value" :label="$shift->status->label()" />@if($shift->revision !== null)<span class="rt-disposition-record-meta">{{ $shift->published_revision === $shift->revision ? 'Veröffentlicht' : ($shift->published_revision ? 'Änderung unveröffentlicht' : 'Unveröffentlicht') }}</span>@endif</span>
    <i class="far fa-chevron-right rt-disposition-shift__arrow" aria-hidden="true"></i>
</button>
