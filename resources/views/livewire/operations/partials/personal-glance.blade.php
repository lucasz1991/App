{{-- Eigene Kennzahlen und ihre bestehenden Zielansichten. --}}
@php
    $weekEnd = now($displayTimezone)->addDays(7);
    $weekDuties = $assignments->filter(fn ($assignment) => $assignment->shift?->ends_at?->isFuture() && $assignment->shift->starts_at->lt($weekEnd));
    $timeComplete = $workTimeReady ? ($glance['time_complete'] ?? null) : null;
    $openTimes = $workTimeReady ? (int) ($glance['time_issue_count'] ?? $glance['open_times'] ?? 0) : null;
    $timeLabel = 'Zeiten';
    if ($timeComplete === false) {
        $timeLabel = match (true) {
            $openTimes === 1 && !empty($timeCompleteness['missing_shift_times']) => 'Dienstzeit fehlt',
            $openTimes === 1 && !empty($timeCompleteness['missing_training_times']) => 'Schulungszeit fehlt',
            $openTimes === 1 && ($timeCompleteness['unfinished_times'][0]['status'] ?? null) === 'returned' => 'Rückgabe',
            default => $openTimes === 1 ? 'Offene Meldung' : 'Offene Meldungen',
        };
    }
    $tiles = [
        ['key' => 'duties', 'label' => 'Dienste', 'value' => (string) $weekDuties->count(), 'hint' => 'In den nächsten 7 Tagen', 'tab' => 'schedule'],
        ['key' => 'time', 'label' => $timeLabel, 'value' => $timeComplete === null ? '–' : ($timeComplete ? 'Erledigt' : (string) $openTimes), 'hint' => $timeComplete === null ? 'Zeiten derzeit nicht geprüft' : ($timeComplete ? 'Aktueller Monat vollständig' : 'Zeitmeldungen dieses Monats ansehen'), 'method' => $timeComplete === false ? 'openCurrentTimeIssues' : null, 'tab' => 'time', 'tone' => $timeComplete === true ? 'success' : ($timeComplete === false ? 'attention' : 'neutral')],
        ['key' => 'tasks', 'label' => 'Aufgaben', 'value' => $taskCount === null ? '—' : $taskCount.' offen', 'hint' => 'Freigaben, Rückfragen und Angebote', 'method' => 'openTasks', 'tone' => $taskCount ? 'attention' : 'neutral'],
        ['key' => 'requests', 'label' => 'Anfragen', 'value' => $requestCount.' offen', 'hint' => 'Dienste und Zeiträume beantworten', 'tab' => 'requests', 'tone' => $requestCount ? 'attention' : 'neutral'],
    ];
@endphp
<x-operations.workday-shortcuts :items="$tiles" :compact="true" />
