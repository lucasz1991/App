@props(['item', 'timezone' => 'Europe/Berlin', 'previewId' => null, 'detailAction' => null, 'periodLabel' => 'Im Zeitraum', 'requiredStaff' => null])
@php
    $items = $item->shifts->sortBy('starts_at')->values();
    $visibleShifts = $items->take(8);
@endphp
<x-operations.service-cell-preview label="Schichten" :preview-id="$previewId ?? 'service-shifts-'.$item->id" kind="shifts">
    <x-slot:trigger><div><span class="rt-table-value tabular-nums">{{ $item->count }} {{ $item->count === 1 ? 'Schicht' : 'Schichten' }}</span><span class="rt-table-meta">{{ $requiredStaff !== null ? 'Bedarf: '.$requiredStaff : $periodLabel }}</span></div></x-slot:trigger>
    <x-slot:content>
        <header class="rt-service-preview-heading"><i class="far fa-calendar-day" aria-hidden="true"></i><div><strong>{{ $item->count }} {{ $item->count === 1 ? 'Schicht' : 'Schichten' }}</strong><span>{{ $periodLabel }}</span></div></header>
        @if($requiredStaff !== null)<p class="rt-service-preview-note">Auftragsbedarf: {{ $requiredStaff }} {{ $requiredStaff === 1 ? 'Mitarbeiter/in' : 'Mitarbeitende' }}</p>@endif
        @if($items->isEmpty())<p class="rt-service-preview-note">Für diesen Auftrag wurden noch keine Schichten angelegt.</p>@endif
        <ul class="rt-service-preview-shifts">
            @foreach($visibleShifts as $shift)
                @php
                    $from = $shift->starts_at?->copy()->setTimezone($timezone);
                    $until = $shift->ends_at?->copy()->setTimezone($timezone);
                    $reserved = $shift->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability())->count();
                @endphp
                <li data-service-preview-shift="{{ $shift->id }}">
                    <div><strong>{{ $shift->title ?: 'Schicht' }}</strong><span>{{ $from?->format('d.m.Y H:i') }} – {{ $until?->format('d.m.Y H:i') }}</span></div>
                    <span class="rt-service-preview-shifts__count" aria-label="{{ $reserved }} von {{ $shift->required_staff }} Einsatzplätzen eingeplant">{{ $reserved }}/{{ $shift->required_staff }}</span>
                </li>
            @endforeach
        </ul>
        @if($items->count() > $visibleShifts->count())<p class="rt-service-preview-note">{{ $items->count() - $visibleShifts->count() }} weitere {{ $items->count() - $visibleShifts->count() === 1 ? 'Schicht' : 'Schichten' }} in den Leistungsdetails.</p>@endif
        @if($item->order)
            <button type="button" class="rt-service-preview-details" x-on:click="open = false; $dispatch('operations-service-detail-request', { id: {{ $item->id }}, ownerId: $wire.$id })">Alle Leistungsdetails <i class="far fa-arrow-right" aria-hidden="true"></i></button>
        @endif
    </x-slot:content>
</x-operations.service-cell-preview>
