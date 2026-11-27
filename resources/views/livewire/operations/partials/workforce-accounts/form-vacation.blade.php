                <fieldset class="rt-employee-form__section ops-full">
                    <legend>Urlaubszeitraum</legend>
                    <x-ui.forms.date-range-picker :inline="true" from-model="form.starts_on" until-model="form.ends_on" :from="$form['starts_on'] ?? ''" :until="$form['ends_on'] ?? ''" from-label="Erster Urlaubstag" until-label="Letzter Urlaubstag" :preset-keys="[]" />
                    <x-ui.forms.input-error for="form.starts_on" /><x-ui.forms.input-error for="form.ends_on" />
                </fieldset>
                <div class="ops-full"><x-ui.forms.label :for="'workforce-vacation-portion-'.$this->getId()" value="Umfang" /><x-ui.forms.select :id="'workforce-vacation-portion-'.$this->getId()" wire:model.live="form.vacation_fraction"><option value="1">Ganze Tage</option><option value="0.5">Halber Tag</option>@if($vacationWindowAvailable)<option value="window">Zeitfenster</option>@endif</x-ui.forms.select><x-ui.forms.input-error for="form.vacation_fraction" /></div>
                @if(($form['vacation_fraction'] ?? '1') !== '1')
                    <fieldset class="rt-employee-form__section ops-full"><legend>Zeitfenster am ausgewählten Tag</legend><div class="rt-employee-form__grid rt-employee-form__grid--pair"><x-operations.field label="Von (Uhrzeit)" model="form.starts_time" type="time" required /><x-operations.field label="Bis (Uhrzeit)" model="form.ends_time" type="time" required /></div><p class="rt-employee-form__hint">Für einen halben Tag oder ein Zeitfenster müssen erster und letzter Urlaubstag übereinstimmen.</p></fieldset>
                @endif
                <x-operations.field label="Notiz (optional)" model="form.note" type="textarea" maxlength="1000" :wide="true" />
                <p class="rt-employee-form__hint ops-full">Der letzte ausgewählte Tag ist eingeschlossen. Der tatsächliche Urlaubsabzug richtet sich nach deinem hinterlegten Arbeitsmodell und der Urlaubsrichtlinie.</p>
