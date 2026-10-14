@props(['demand' => []])
@php
    $fields = ['role_name'=>['Tätigkeit','fa-user-hard-hat'],'location_name'=>['Einsatzort','fa-map-marker-alt'],'starts_at'=>['Beginn','fa-calendar-alt'],'ends_at'=>['Ende','fa-calendar-check'],'required_staff'=>['Personalbedarf','fa-users'],'timezone'=>['Zeitzone','fa-clock']];
@endphp
<dl {{ $attributes->class('rt-intake-facts') }}>
    @foreach($fields as $key => [$label, $icon])
        @php
            $value = $demand[$key] ?? null;
            if (in_array($key, ['starts_at', 'ends_at'], true) && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
                try { $value = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value)->format('d.m.Y · H:i'); } catch (\Throwable) { /* Keep source value visible when it cannot be formatted. */ }
            }
            if ($key === 'required_staff' && $value !== null) { $value .= ((int) $value === 1 ? ' Person' : ' Personen'); }
        @endphp
        <div class="rt-intake-facts__item" data-field="{{ $key }}">
            <dt><i class="far {{ $icon }}" aria-hidden="true"></i>{{ $label }}</dt>
            <dd @class(['rt-intake-facts__missing' => $value === null || $value === ''])>{{ $value === null || $value === '' ? 'Noch offen' : $value }}</dd>
        </div>
    @endforeach
</dl>
