{{-- Kennzahlen des Tages. Jede Kachel führt in den zuständigen Bereich; Handlungsbedarf ist farbig markiert. --}}
@php
    $weekEnd = now($displayTimezone)->addDays(7);
    $weekDuties = $assignments->filter(fn ($assignment) => $assignment->shift?->ends_at?->isFuture() && $assignment->shift->starts_at->lt($weekEnd));
    $weekMinutes = (int) $weekDuties->sum(fn ($assignment) => max(0, $assignment->shift->starts_at->diffInMinutes($assignment->shift->ends_at) - (int) $assignment->shift->planned_break_minutes));
    $requested = $assignments->filter(fn ($assignment) => $assignment->status->value === 'requested' && ! $assignment->plan_is_stale)->count();
    $hours = fn (int $minutes): string => rtrim(rtrim(number_format($minutes / 60, 1, ',', ''), '0'), ',').' h';
    $tiles = array_values(array_filter([
        ['icon' => 'far fa-calendar-check', 'label' => 'Nächste 7 Tage', 'value' => $weekDuties->count() ? $weekDuties->count().' '.($weekDuties->count() === 1 ? 'Dienst' : 'Dienste') : 'Keine Dienste', 'hint' => $requested ? $requested.' zu bestätigen' : ($weekMinutes ? $hours($weekMinutes).' geplant' : 'Frei'), 'attention' => $requested > 0, 'tab' => 'schedule'],
        $workTimeReady ? ['icon' => 'far fa-clock', 'label' => 'Zeitmeldungen', 'value' => $glance['open_times'] ? $glance['open_times'].' offen' : 'Erledigt', 'hint' => $glance['open_times'] ? 'Prüfen und einreichen' : 'Alles eingereicht', 'attention' => $glance['open_times'] > 0, 'tab' => 'time'] : null,
        ['icon' => 'fas fa-umbrella-beach', 'label' => 'Urlaub & Abwesenheit', 'value' => $glance['pending_absences'] ? $glance['pending_absences'].' in Prüfung' : ($glance['next_absence'] ? 'Ab '.$glance['next_absence']->format('d.m.') : 'Nichts geplant'), 'hint' => $glance['pending_absences'] ? 'Antrag wird bearbeitet' : ($glance['next_absence'] ? 'Genehmigt' : 'Jetzt planen'), 'attention' => false, 'area' => 'absences'],
        ['icon' => 'fas fa-award', 'label' => 'Qualifikationen', 'value' => $glance['expiring_qualifications'] ? $glance['expiring_qualifications'].' laufen ab' : 'Alle gültig', 'hint' => $glance['expiring_qualifications'] ? 'In den nächsten 30 Tagen' : 'Keine Frist offen', 'attention' => $glance['expiring_qualifications'] > 0, 'area' => 'qualifications'],
    ]));
@endphp
<nav class="rt-today-stats" aria-label="Auf einen Blick">
    @foreach($tiles as $index => $tile)
        @if(isset($tile['tab']))
            <button type="button" class="rt-today-stat rt-today-enter" style="--i:{{ $index + 1 }}" wire:click="showTab('{{ $tile['tab'] }}')" wire:loading.attr="disabled" wire:target="showTab" @if($tile['attention']) data-attention @endif>
        @else
            <a class="rt-today-stat rt-today-enter" style="--i:{{ $index + 1 }}" href="{{ route('operations.mine', ['area' => $tile['area']]) }}" wire:navigate @if($tile['attention']) data-attention @endif>
        @endif
                <span class="rt-today-stat__icon" aria-hidden="true"><i class="{{ $tile['icon'] }}"></i></span>
                <span class="rt-today-stat__text">
                    <span class="rt-today-stat__label">{{ $tile['label'] }}</span>
                    <strong class="rt-today-stat__value">{{ $tile['value'] }}</strong>
                    <span class="rt-today-stat__hint">{{ $tile['hint'] }}</span>
                </span>
                <i class="fas fa-arrow-right rt-today-stat__arrow" aria-hidden="true"></i>
        @if(isset($tile['tab']))</button>@else</a>@endif
    @endforeach
</nav>
