@php
    $assignment = $item;
    $shift = $assignment->shift;
@endphp
<div class="min-w-0 px-3 py-2 space-y-3">
<header class="ops-toolbar"><div><p class="ops-service-date">{{ $shift->starts_at->translatedFormat('D, d. M') }} · {{ $shift->starts_at->format('H:i') }} – {{ $shift->ends_at->format($shift->starts_at->isSameDay($shift->ends_at) ? 'H:i' : 'd.m. H:i') }}</p><h2>{{ $shift->title }}</h2></div><x-operations.status :value="$assignment->status->value" /></header><p>{{ $shift->location_name }} <span class="ops-muted">· {{ $shift->role_name }} · {{ $shift->timezone }}</span></p><p class="ops-muted">{{ $shift->order?->customer?->company_name }} · {{ $shift->order?->order_number }} · {{ $shift->planned_break_minutes }} min Pause</p>
                    @include('livewire.operations.partials.personal-shift-actions')

                
</div>
