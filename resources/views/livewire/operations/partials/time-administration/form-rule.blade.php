                    <x-operations.field label="Bezeichnung" model="form.name" required />
                    <div class="space-y-1.5">
                        <x-ui.forms.label for="enhancement-rule-kind-{{ $this->getId() }}" value="Regelart" />
                        <x-ui.forms.select id="enhancement-rule-kind-{{ $this->getId() }}" wire:model.live="form.kind">
                            <option value="planning">Planungsgrenze</option><option value="remuneration">Vergütungsbewertung</option>
                        </x-ui.forms.select>
                    </div>
                    <x-operations.field label="Gültig ab" model="form.starts_on" type="date" required />
                    <x-operations.field label="Gültig bis (optional)" model="form.ends_on" type="date" />
                    @if(($form['kind'] ?? 'planning') === 'planning')
                        <fieldset class="ops-full rt-personnel-management rt-personnel-section" wire:key="rule-planning">
                            <legend>Planungsgrenze</legend>
                            <div class="ops-form">
                                <div class="min-w-0 space-y-1.5">
                                    <x-ui.forms.label for="rule-check-{{ $this->getId() }}" value="Prüfung" />
                                    <x-ui.forms.select id="rule-check-{{ $this->getId() }}" wire:model.live="form.configuration.type">
                                        <option value="rolling_minutes">Minuten im Prüfzeitraum</option>
                                        <option value="consecutive_days">Diensttage in Folge</option>
                                        <option value="night_count">Nachtdienste im Prüfzeitraum</option>
                                    </x-ui.forms.select>
                                    <x-ui.forms.input-error for="form.configuration.type" />
                                </div>
                                <x-operations.field label="Prüfzeitraum (Tage)" model="form.configuration.days" type="number" min="1" max="90" required />
                                <x-operations.field :label="match($form['configuration']['type'] ?? 'rolling_minutes') {'night_count' => 'Obergrenze (Nachtdienste)', 'consecutive_days' => 'Obergrenze (Diensttage)', default => 'Obergrenze (min)'}" model="form.configuration.limit" type="number" min="1" required />
                                <x-operations.field label="Zeitzone" model="form.configuration.timezone" required />
                                <x-operations.field label="Abschnitt" model="form.configuration.activity" type="select">
                                    <option value="all">Gesamter Dienst</option><option value="on_call">Bereitschaft</option><option value="travel">Reise</option>
                                </x-operations.field>
                            </div>
                        </fieldset>
                    @else
                        <fieldset class="ops-full rt-personnel-management rt-personnel-section" wire:key="rule-remuneration">
                            <legend>Vergütungsbewertung</legend>
                            <div class="ops-form">
                                <x-operations.field label="Tätigkeit" model="form.configuration.activity" type="select" required>
                                    <option value="">Tätigkeit auswählen</option>
                                    @foreach(\App\Services\Operations\WorkTimeActivityService::KINDS as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach
                                </x-operations.field>
                                <x-operations.field label="Lohnart" model="form.configuration.wage_code" required />
                                <x-operations.field label="Bewertung (Basispunkte)" model="form.configuration.multiplier_bps" type="number" min="0" required />
                                <x-ui.forms.checkbox wire:model="form.configuration.additive" label="Zusätzliche Zuschlagsmenge" />
                                <x-operations.field label="Rundung (Minuten)" model="form.configuration.rounding_minutes" type="number" min="0" max="60" required />
                                <x-operations.field label="Rundungsart" model="form.configuration.rounding_mode" type="select">
                                    <option value="none">Keine</option><option value="nearest">Kaufmännisch</option><option value="up">Aufrunden</option><option value="down">Abrunden</option>
                                </x-operations.field>
                            </div>
                        </fieldset>
                        <fieldset class="ops-full rt-personnel-management rt-personnel-section">
                            <legend>Wochentage</legend>
                            <div class="flex flex-wrap gap-x-4">
                                @foreach([1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'] as $day => $label)
                                    <x-ui.forms.checkbox wire:model="form.configuration.weekdays" :value="$day" :label="$label" />
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                    <fieldset class="ops-full rt-personnel-management rt-personnel-section">
                        <legend>Zeitfenster</legend>
                        <div class="ops-form">
                            <x-operations.field label="Zeitfenster ab" type="time" model="form.configuration.window_start" :required="($form['kind'] ?? 'planning') === 'planning' && ($form['configuration']['type'] ?? '') === 'night_count'" />
                            <x-operations.field label="Zeitfenster bis" type="time" model="form.configuration.window_end" :required="($form['kind'] ?? 'planning') === 'planning' && ($form['configuration']['type'] ?? '') === 'night_count'" />
                        </div>
                    </fieldset>
                    <div class="ops-full"><x-ui.forms.checkbox wire:model="form.confirmed" required label="Betrieblich geprüfte Regel freigeben" /><x-ui.forms.input-error for="form.confirmed" /></div>
