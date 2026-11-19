@props(['model', 'values', 'timezone'])
<fieldset class="rt-employee-form__section ops-full">
    <legend>Zeitraum und Pause</legend>
    <div class="rt-employee-form__grid">
        <x-operations.field label="Beginn · Datum und Uhrzeit" :model="$model.'.starts_at'" type="datetime-local" :live="true" required />
        <x-operations.field label="Ende · Datum und Uhrzeit" :model="$model.'.ends_at'" type="datetime-local" :live="true" required />
        <x-operations.field label="Pause in Minuten" :model="$model.'.pause_minutes'" type="number" min="0" max="1440" :live="true" required />
        <p class="rt-employee-form__hint">Bei Nachtdiensten das Enddatum auf den Folgetag setzen.@if($timezone) Zeitzone: {{ $timezone }}.@endif</p>
    </div>
    <p class="rt-employee-form__summary" role="status" aria-atomic="true">
        <strong>Nettozeit</strong> {{ \App\Support\Operations\OperationsDateTime::workTimePreview($values, $timezone) }}
    </p>
</fieldset>
