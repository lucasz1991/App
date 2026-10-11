@props(['item', 'detailOnly' => false, 'planningRoute' => 'operations.workspace'])
@php
    $days = $item->shiftDetails->groupBy('day');
    $closedCount = $item->shiftDetails->where('active', false)->count();
@endphp
<section class="rt-service-detail-tab rt-service-detail-shifts" data-service-shifts-tab aria-label="Schichten der gesamten Leistung">
    <header class="rt-service-detail-tab__heading">
        <div><h3>Dienstfolge</h3><p>Alle Schichten der Leistung · {{ $item->timezone }}</p></div>
        <div class="rt-service-detail-tab__total"><strong>{{ $item->count }}</strong><span>{{ $item->count === 1 ? 'Schicht' : 'Schichten' }}</span></div>
    </header>
    @if($closedCount > 0)<p class="rt-service-detail-tab__note">{{ $closedCount }} {{ $closedCount === 1 ? 'Schicht ist' : 'Schichten sind' }} abgeschlossen oder storniert und {{ $closedCount === 1 ? 'bleibt' : 'bleiben' }} zur Übersicht sichtbar.</p>@endif
    @forelse($days as $day => $entries)
        @php
            $dayDate = $entries->first()->from;
        @endphp
        <section class="rt-service-detail-day" aria-label="{{ $dayDate?->locale('de')->translatedFormat('l, d. F Y') ?? 'Datum nicht verfügbar' }}" wire:key="service-shift-day-{{ $item->id }}-{{ $day }}">
            <header class="rt-service-detail-day__heading">
                <span class="rt-service-detail-day__date" aria-hidden="true">{{ $dayDate?->format('d') ?? '–' }}</span>
                <div><h4>{{ $dayDate?->locale('de')->translatedFormat('l') ?? 'Datum offen' }}</h4><span>{{ $dayDate?->locale('de')->translatedFormat('d. F Y') ?? 'Zeitangaben fehlen' }}</span></div>
                <span class="rt-service-detail-day__count">{{ $entries->count() }} {{ $entries->count() === 1 ? 'Schicht' : 'Schichten' }}</span>
            </header>
            <ul class="rt-service-detail-shift-list">
                @foreach($entries as $entry)
                    @php
                        $shift = $entry->shift;
                        $overnight = $entry->from && $entry->until && ! $entry->from->isSameDay($entry->until);
                        $statusIcon = match ($shift->status) {
                            \App\Enums\ShiftStatus::Completed => 'fa-circle-check',
                            \App\Enums\ShiftStatus::Cancelled => 'fa-ban',
                            \App\Enums\ShiftStatus::Confirmed, \App\Enums\ShiftStatus::Staffed => 'fa-user-check',
                            \App\Enums\ShiftStatus::InProgress => 'fa-play-circle',
                            default => 'fa-calendar-day',
                        };
                    @endphp
                    <li class="rt-service-detail-shift" data-service-detail-shift="{{ $shift->id }}" data-active="{{ $entry->active ? 'true' : 'false' }}" wire:key="service-detail-shift-{{ $item->id }}-{{ $shift->id }}">
                        <div class="rt-service-detail-shift__time">
                            <time @if($entry->from) datetime="{{ $entry->from->toIso8601String() }}" @endif>{{ $entry->from?->format('H:i') ?? '–' }}</time>
                            <span aria-hidden="true">↓</span>
                            <time @if($entry->until) datetime="{{ $entry->until->toIso8601String() }}" @endif>{{ $entry->until?->format('H:i') ?? '–' }}</time>
                            @if($overnight)<small>bis {{ $entry->until->format('d.m.') }}</small>@endif
                        </div>
                        <div class="rt-service-detail-shift__body">
                            <div class="rt-service-detail-shift__heading">
                                @if($detailOnly)
                                    <x-ui.buttons.button-basic mode="link" size="sm" type="button" class="rt-service-detail-shift__link" x-on:click="$dispatch('close'); $wire.detailOpen = false; $dispatch('operations-shift-detail-request', { id: {{ $shift->id }} })" aria-label="Schichtdetails öffnen: {{ $shift->title ?: 'Schicht' }}"><span>{{ $shift->title ?: 'Schicht' }}</span><i class="far fa-arrow-up-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                                @else
                                    <x-ui.buttons.button-basic mode="link" size="sm" class="rt-service-detail-shift__link" data-no-navigate :href="route($planningRoute, ['module'=>'shift-management', 'order'=>$item->id, 'shift'=>$shift->id])" aria-label="Schichtdetails öffnen: {{ $shift->title ?: 'Schicht' }}"><span>{{ $shift->title ?: 'Schicht' }}</span><i class="far fa-arrow-up-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                                @endif
                                <span class="rt-service-detail-status"><i class="far {{ $statusIcon }}" aria-hidden="true"></i><x-operations.status :value="$shift->status->value" :label="$shift->status->label()" /></span>
                            </div>
                            <p class="rt-service-detail-shift__context"><span><i class="far fa-user-helmet-safety" aria-hidden="true"></i>{{ $shift->role_name ?: 'Rolle offen' }}</span><span><i class="far fa-location-dot" aria-hidden="true"></i>{{ $shift->location_name ?: 'Einsatzort offen' }}</span></p>
                            @if($entry->active)
                                <div class="rt-service-detail-shift__staffing"><x-operations.service-staffing-content :seats="$entry->seats" :summary="$entry->summary" :timezone="$item->timezone" /></div>
                            @else
                                <p class="rt-service-detail-tab__note">Historische Besetzung: {{ $entry->summary['reserved'] }}/{{ $entry->summary['required'] }} · keine offenen Einsatzplätze</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="rt-service-detail-tab__empty"><i class="far fa-calendar-days" aria-hidden="true"></i><h4>Noch keine Schichten</h4><p>Den bestehenden Personalbedarf kannst du unter „Bedarf & Planung“ prüfen und bearbeiten.</p></div>
    @endforelse
</section>
