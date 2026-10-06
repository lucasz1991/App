                @if($formKind === 'workflow_template')
                    <x-operations.field label="Prozessart" model="form.type" type="select"><option value="general">Personal</option><option value="onboarding">Eintritt</option><option value="offboarding">Austritt</option></x-operations.field>
                    @foreach($form['steps'] ?? [] as $i=>$step)
                        <fieldset class="ops-full ops-form border-t border-rt-border pt-4" wire:key="personnel-template-step-{{ $i }}"><legend class="text-sm font-semibold">Schritt {{ $i+1 }}</legend>
                            <x-operations.field label="Aufgabe" :model="'form.steps.'.$i.'.title'" required :wide="true"/>
                            <x-operations.field label="Tage zum Stichtag" :model="'form.steps.'.$i.'.offset_days'" type="number" min="-365" max="365" required/>
                            <x-operations.field label="Verantwortlich" :model="'form.steps.'.$i.'.assigned_to'" type="select" required><option value="">Auswählen</option>@foreach($assignees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                            <x-operations.field label="Vertretung" :model="'form.steps.'.$i.'.delegate_id'" type="select"><option value="">Keine</option>@foreach($assignees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                            <x-operations.field label="Nach Schritt" :model="'form.steps.'.$i.'.depends_on'" type="select"><option value="">Ohne Abhängigkeit</option>@for($n=1;$n<=$i;$n++)<option value="{{ $n }}">Schritt {{ $n }}</option>@endfor</x-operations.field>
                            <x-operations.field label="Eskalation an" :model="'form.steps.'.$i.'.escalate_to'" type="select"><option value="">Keine</option>@foreach($assignees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                            <x-operations.field label="Eskalation nach Tagen" :model="'form.steps.'.$i.'.escalation_days'" type="number" min="0" max="90" required/>
                        </fieldset>
                    @endforeach
                    <div class="ops-full ops-actions"><x-ui.buttons.button-basic type="button" wire:click="addStep">Schritt hinzufügen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="removeLastStep">Letzten entfernen</x-ui.buttons.button-basic></div>
                @elseif($formKind === 'workflow_run')
                    <x-operations.field label="Stichtag" model="form.anchor_on" type="date" required/>
                    @foreach($display['steps'] ?? [] as $i=>$step)<p class="ops-full text-sm">{{ $i+1 }} · {{ $step['title'] }} · {{ $step['offset_days'] }} Tage</p>@endforeach
                @elseif($formKind === 'report')
                    <x-operations.field label="Von" model="form.from" type="date" required/><x-operations.field label="Bis" model="form.until" type="date" required/><x-operations.field label="Ausgabe alle Tage (0 = manuell)" model="form.interval_days" type="number" min="0" max="366" required/>
                    <fieldset class="ops-full grid gap-2 sm:grid-cols-2"><legend class="text-sm font-medium">Mitarbeiter</legend>@foreach($employees as $person)<label class="flex items-center gap-2"><x-ui.forms.checkbox wire:model="form.user_ids" :value="$person->id"/><span class="text-sm">{{ $person->name }}</span></label>@endforeach</fieldset>
                @endif

