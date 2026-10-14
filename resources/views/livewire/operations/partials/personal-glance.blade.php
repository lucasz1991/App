{{-- Bestehende eigene Statusprojektion und passende Schnellaktionen in einem Segment. --}}
@php
    $weekEnd = now($displayTimezone)->addDays(7);
    $weekDuties = $assignments->filter(fn ($assignment) => $assignment->shift?->ends_at?->isFuture() && $assignment->shift->starts_at->lt($weekEnd));
    $weekMinutes = (int) $weekDuties->sum(fn ($assignment) => max(0, $assignment->shift->starts_at->diffInMinutes($assignment->shift->ends_at) - (int) $assignment->shift->planned_break_minutes));
    $requested = $assignments->filter(fn ($assignment) => $assignment->status->value === 'requested' && ! $assignment->plan_is_stale)->count();
    $hours = fn (int $minutes): string => rtrim(rtrim(number_format($minutes / 60, 1, ',', ''), '0'), ',').' h';
    $tiles = array_values(array_filter([
        ['icon' => 'far fa-calendar-check', 'label' => 'Nächste 7 Tage', 'value' => $weekDuties->count() ? $weekDuties->count().' '.($weekDuties->count() === 1 ? 'Dienst' : 'Dienste') : 'Keine Dienste', 'hint' => $requested ? $requested.' zu bestätigen' : ($weekMinutes ? $hours($weekMinutes).' geplant' : 'Frei'), 'attention' => $requested > 0, 'tab' => 'schedule'],
        $workTimeReady ? ['icon' => 'far fa-clock', 'label' => 'Zeitmeldungen', 'value' => $glance['open_times'] ? $glance['open_times'].' offen' : 'Erledigt', 'hint' => $glance['open_times'] ? 'Prüfen und einreichen' : 'Alles eingereicht', 'attention' => $glance['open_times'] > 0, 'tab' => 'time'] : null,
        ['icon' => 'fas fa-umbrella-beach', 'label' => 'Abwesenheit', 'value' => $glance['pending_absences'] ? $glance['pending_absences'].' in Prüfung' : ($glance['next_absence'] ? 'Ab '.$glance['next_absence']->format('d.m.') : 'Nichts geplant'), 'hint' => $glance['pending_absences'] ? 'Antrag wird bearbeitet' : ($glance['next_absence'] ? 'Genehmigt' : 'Urlaub planen'), 'attention' => false, 'area' => 'absences'],
        ['icon' => 'fas fa-award', 'label' => 'Qualifikationen', 'value' => $glance['expiring_qualifications'] ? $glance['expiring_qualifications'].' laufen ab' : 'Alle gültig', 'hint' => $glance['expiring_qualifications'] ? 'In den nächsten 30 Tagen' : 'Keine Frist offen', 'attention' => $glance['expiring_qualifications'] > 0, 'area' => 'qualifications'],
    ]));
    $actions = ['schedule' => 'Kalender öffnen', 'time' => 'Zeiten ansehen', 'absences' => 'Urlaub planen', 'qualifications' => 'Nachweis einreichen'];
    foreach ($tiles as &$tile) {
        $tile['key'] = $tile['tab'] ?? $tile['area'];
        $tile['action'] = $actions[$tile['key']];
        if ($tile['key'] === 'absences' && $tile['hint'] === 'Urlaub planen') {
            $tile['hint'] = 'Deine Planung';
        }
        if (isset($tile['area'])) {
            $tile['href'] = route('operations.mine', ['area' => $tile['area']]);
            $tile['action_href'] = route('operations.mine', ['area' => $tile['area'], 'new' => 1]);
        }
    }
    unset($tile);
    $sicknessUrl = isset(\App\Support\Operations\PersonalWorkspaceAreas::all()['sickness'])
        ? route('operations.mine', ['area' => 'sickness']) : null;
@endphp
<x-operations.workday-shortcuts :items="$tiles" :sickness-url="$sicknessUrl" />
