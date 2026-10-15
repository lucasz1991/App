{{-- Eigene Kennzahlen und ihre bestehenden Zielansichten. --}}
@php
    $weekEnd = now($displayTimezone)->addDays(7);
    $weekDuties = $assignments->filter(fn ($assignment) => $assignment->shift?->ends_at?->isFuture() && $assignment->shift->starts_at->lt($weekEnd));
    $openTimes = $workTimeReady ? (int) $glance['open_times'] : null;
    $tiles = [
        ['key' => 'duties', 'label' => 'Dienste', 'value' => (string) $weekDuties->count(), 'hint' => 'In den nächsten 7 Tagen', 'tab' => 'schedule'],
        ['key' => 'time', 'label' => 'Zeiten', 'value' => $openTimes === null ? '—' : ($openTimes ? $openTimes.' offen' : 'Erledigt'), 'hint' => $openTimes === null ? 'Zeiterfassung nicht verfügbar' : ($openTimes ? 'Prüfen und einreichen' : 'Alle erfassten Zeiten eingereicht'), 'tab' => 'time', 'tone' => $openTimes === 0 ? 'success' : ($openTimes ? 'attention' : 'neutral')],
        ['key' => 'tasks', 'label' => 'Aufgaben', 'value' => $taskCount === null ? '—' : $taskCount.' offen', 'hint' => 'Freigaben, Rückfragen und Angebote', 'method' => 'openTasks', 'tone' => $taskCount ? 'attention' : 'neutral'],
        ['key' => 'requests', 'label' => 'Anfragen', 'value' => $requestCount.' offen', 'hint' => 'Dienste und Zeiträume beantworten', 'tab' => 'requests', 'tone' => $requestCount ? 'attention' : 'neutral'],
    ];
@endphp
<x-operations.workday-shortcuts :items="$tiles" :compact="true" />
