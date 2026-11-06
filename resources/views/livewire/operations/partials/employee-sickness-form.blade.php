<p class="rt-employee-form__hint ops-full">Melde hier den Zeitraum deiner Abwesenheit.</p>
<fieldset class="rt-employee-form__section ops-full">
    <legend>Zeitraum</legend>
    <div class="rt-employee-form__grid">
        <x-operations.field label="Beginn" model="form.starts_at" type="datetime-local" required />
        <x-operations.field label="Voraussichtliches Ende" model="form.ends_at" type="datetime-local" required />
    </div>
</fieldset>
<details class="rt-employee-form__more ops-full" @if($errors->has('form.timezone') || $errors->has('timezone')) open @endif>
    <summary>Zeitzone · {{ $form['timezone'] ?? config('operations.display_timezone') }}</summary>
    <div class="rt-employee-form__grid"><x-operations.field label="Zeitzone" model="form.timezone" required /></div>
</details>
@if(($formKind ?? '') === 'sickness_correction')
    <x-operations.field label="Korrekturgrund" model="form.note" type="textarea" required :wide="true" />
@else
    <p class="rt-employee-form__hint ops-full">Nachweisstatus und Rückmeldungen findest du unter Krankmeldungen.</p>
@endif
