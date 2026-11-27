                <fieldset class="ops-full rt-personnel-section"><legend>Basisdaten</legend>
                    <div class="ops-form">
                        <x-operations.field label="Bezeichnung" model="form.name" required :wide="true" />
                        <x-operations.field label="Gültig ab" model="form.starts_on" type="date" required />
                        <x-operations.field label="Gültig bis (optional)" model="form.ends_on" type="date" />
                        <x-operations.field label="Zeitzone" model="form.timezone" required :wide="true" />
                        <x-operations.field label="Wochensoll (min)" model="form.weekly_target_minutes" type="number" min="0" required />
                        <x-operations.field label="Wochenlimit (min)" model="form.maximum_weekly_minutes" type="number" min="0" />
                    </div>
                </fieldset>
                <fieldset class="ops-full rt-personnel-section">
                    <legend>Wochenverteilung</legend>
                    @foreach([1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag',6=>'Samstag',7=>'Sonntag'] as $day => $label)
                        <details class="rt-account-day" x-on:invalid.capture="$el.open = true" wire:key="work-model-day-{{ $day }}" @if($day === 1 || $errors->has('daily_minutes.'.$day) || $errors->has('work_windows.'.$day.'.*')) open @endif>
                            <summary>{{ $label }}</summary>
                            <div class="rt-personnel-day__primary">
                                <x-operations.field :label="$label.' · Soll (min)'" :model="'form.daily_minutes.'.$day" type="number" min="0" required />
                                <x-operations.field :label="$label.' · Fenster ab'" :model="'form.window_start.'.$day" type="time" />
                                <x-operations.field :label="$label.' · Fenster bis (HH:MM)'" :model="'form.window_end.'.$day" placeholder="24:00" />
                            </div>
                            <details class="rt-personnel-day__additional" x-on:invalid.capture="$el.open = true" @if(filled($form['window_start_2'][$day] ?? '') || filled($form['window_end_2'][$day] ?? '')) open @endif>
                                <summary>Zweites Zeitfenster</summary>
                                <div class="ops-form">
                                    <x-operations.field :label="$label.' · zweites Fenster ab'" :model="'form.window_start_2.'.$day" type="time" />
                                    <x-operations.field :label="$label.' · zweites Fenster bis'" :model="'form.window_end_2.'.$day" placeholder="24:00" />
                                </div>
                            </details>
                        </details>
                    @endforeach
                </fieldset>
                <fieldset class="ops-full rt-personnel-section">
                    <legend>Zeitgutschrift je Tätigkeit</legend>
                    <div class="ops-form">
                        @foreach(['work'=>'Arbeit','preparation'=>'Vorbereitung','driving'=>'Fahrt','shunting'=>'Rangieren','travel'=>'Anreise','waiting'=>'Wartezeit','on_call'=>'Bereitschaft','break'=>'Pause','internal'=>'Interne Arbeit','training'=>'Schulung'] as $kind => $label)
                            <x-operations.field :label="$label.' (%)'" :model="'form.valuation_percent.'.$kind" type="number" min="0" max="100" step="0.01" placeholder="Nicht konfiguriert" />
                        @endforeach
                    </div>
                </fieldset>
