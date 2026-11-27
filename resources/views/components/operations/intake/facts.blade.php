@props(['demand' => [], 'variant' => 'grid', 'assumptions' => [], 'confirmed' => false, 'activities' => [], 'schedule' => []])
@php
    $fields = ['role_name'=>['Tätigkeit','fa-user-hard-hat'],'location_name'=>['Einsatzort','fa-map-marker-alt'],'starts_at'=>['Beginn','fa-calendar-alt'],'ends_at'=>['Ende','fa-calendar-check'],'required_staff'=>['Personalbedarf','fa-users'],'timezone'=>['Zeitzone','fa-clock']];
    $parseLocal = static function ($raw) {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $raw)) return null;
        try {
            $date = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
            return $date && $date->format('Y-m-d\TH:i') === $raw ? $date : null;
        } catch (\Throwable) { return null; }
    };
    $reviewRows = $variant === 'review';
    $drawerRows = $variant === 'drawer';
    if ($drawerRows) $fields = ['role_name' => ['Tätigkeit', 'fa-user-hard-hat'], 'required_staff' => ['Personalbedarf', 'fa-users'], 'schedule' => ['Einsatzzeit', 'fa-clock']];
    $isConfirmed = filter_var($confirmed, FILTER_VALIDATE_BOOL);
    $starts = $parseLocal($demand['starts_at'] ?? null);
    $ends = $parseLocal($demand['ends_at'] ?? null);
    $dateLabel = $starts?->format('d.m.Y') ?? $ends?->format('d.m.Y');
    if ($starts && $ends && $starts->toDateString() !== $ends->toDateString()) $dateLabel .= ' – '.$ends->format('d.m.Y');
    $defaultStaff = ($assumptions['required_staff']['basis'] ?? '') === 'default';
    if ($reviewRows) {
        $fields = ['role_name'=>['Tätigkeit','fa-user-hard-hat'],'date'=>['Datum','fa-calendar-alt'],'location_name'=>['Einsatzort','fa-map-marker-alt'],'starts_at'=>['Beginn','fa-clock'],'ends_at'=>['Ende','fa-clock'],'timezone'=>['Zeitzone','fa-globe-europe'],'required_staff'=>['Personalbedarf','fa-users']];
    }
@endphp
<dl {{ $attributes->class(['rt-intake-facts', 'rt-intake-facts--review' => $reviewRows, 'rt-intake-facts--drawer' => $drawerRows]) }} data-facts-layout="{{ $drawerRows ? 'drawer' : ($reviewRows ? 'review' : 'grid') }}">
    @foreach($fields as $key => [$label, $icon])
        @php
            $value = $demand[$key] ?? null;
            if ($key === 'role_name' && is_string($value)) {
                $activity = $activities[mb_strtolower(preg_replace('~\s*/\s*~u', '/', trim($value)))] ?? null;
                if ($activity) $value = $drawerRows ? $activity->displayLabel() : ($activity->title ?: $value);
            }
            if ($key === 'schedule') {
                $value = $schedule['timeLabel'] ?? null;
                if ($value && $schedule['start']->isSameDay($schedule['end'])) $value = $schedule['start']->format('d.m.Y').' · '.$value;
            }
            if ($key === 'date') $value = $dateLabel;
            if (in_array($key, ['starts_at', 'ends_at'], true)) {
                $date = $key === 'starts_at' ? $starts : $ends;
                if ($date) $value = $date->format($reviewRows ? 'H:i' : 'd.m.Y · H:i');
            }
            if ($key === 'required_staff' && $value !== null && $value !== '') $value .= ((int) $value === 1 ? ' Person' : ' Personen');
            $drawerStaffOpen = $drawerRows && $key === 'required_staff' && ($value === null || $value === '' || ($defaultStaff && !$isConfirmed));
        @endphp
        <div class="rt-intake-facts__item" data-field="{{ $key }}">
            <dt><i class="far {{ $icon }}" aria-hidden="true"></i>{{ $label }}</dt>
            <dd @class(['rt-intake-facts__missing' => $value === null || $value === '' || $drawerStaffOpen])>
                <span>{{ $drawerStaffOpen ? 'Bitte ergänzen' : ($value === null || $value === '' ? 'Noch offen' : $value) }}</span>
                @if($reviewRows && $key === 'required_staff' && $defaultStaff)
                    <span class="rt-intake-facts__assumption">{{ $isConfirmed ? 'Bestätigt' : 'Vorschlag' }}</span>
                    <small class="rt-intake-facts__staff-note" title="In der Mail fehlt eine Personalanzahl." role="status">Standardvorschlag: {{ $value === null || $value === '' ? 'Bitte prüfen' : $value }} · {{ $isConfirmed ? 'Bestätigt.' : 'Bitte bestätigen oder anpassen.' }}</small>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
