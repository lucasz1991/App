<div class="rt-ops rt-personnel-management rt-workforce-account ops-stack">
    <x-operations.feedback />
    @if(!$ready)
        <div class="rt-personnel-empty"><strong>Dieser Personalbereich ist noch nicht verfügbar</strong><p>Die Übersicht wird angezeigt, sobald dieser Bereich eingerichtet ist.</p></div>
    @else
        @if(!$embedded)<header class="rt-personnel-account-toolbar ops-toolbar">
            @if(!$personal)<div><x-ui.forms.label for="workforce-person" value="Mitarbeiter"/><x-ui.forms.select id="workforce-person" wire:model.live="userId"><option value="0">Auswählen</option>@foreach($employees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-ui.forms.select></div>@endif
            @php($viewOptions = $personal ? [['value'=>'account','label'=>'Konten','icon'=>'fa-wallet'],['value'=>'absences','label'=>'Abwesenheiten','icon'=>'fa-calendar-alt'],['value'=>'tasks','label'=>'Aufgaben','icon'=>'fa-tasks'],['value'=>'training','label'=>'Schulungen','icon'=>'fa-graduation-cap']] : [['value'=>'account','label'=>'Konten','icon'=>'fa-wallet'],['value'=>'models','label'=>'Arbeitsmodelle','icon'=>'fa-clock'],['value'=>'policies','label'=>'Urlaubsrichtlinien','icon'=>'fa-umbrella-beach'],['value'=>'rules','label'=>'Regeln','icon'=>'fa-shield'],['value'=>'checks','label'=>'Prüfungen','icon'=>'fa-clipboard-check'],['value'=>'absences','label'=>'Abwesenheiten','icon'=>'fa-calendar-alt']])
            @if(!$personal && auth()->user()->isAdmin())@php($viewOptions[] = ['value'=>'responsibilities','label'=>'Zuständigkeiten','icon'=>'fa-users-cog'])@endif
            <x-ui.buttons.multi-toggle id="workforce-account-view" label="Personalansicht" :show-labels="true" :value="$tab" action="showTab" :options="$viewOptions" />
        </header>@endif
        @if($employee)
            @include('livewire.operations.partials.workforce-accounts.account-summary')
            @php($recordHeading = ['account'=>($accountKind === 'time' ? 'Zeitbuchungen im Zeitraum' : 'Buchungen'),'models'=>'Arbeitsmodelle','policies'=>'Urlaubsrichtlinien','rules'=>'Regelzuordnungen','checks'=>'Planprüfungen','absences'=>'Abwesenheiten','tasks'=>'Aufgaben','training'=>'Schulungen','responsibilities'=>'Zuständigkeiten'][$tab] ?? 'Einträge')
            <section class="rt-personnel-records ops-stack">
            <header class="rt-personnel-section-heading"><div><h3>{{ $recordHeading }}</h3><p>{{ $records instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $records->total() : count($records) }} Einträge</p></div><div class="ops-actions">
                @if($canConfigure && $tab === 'account')@if($accountKind !== 'time')<x-ui.buttons.button-basic type="button" wire:click="openForm('credit')">Anspruch buchen</x-ui.buttons.button-basic>@endif @if($accountKind !== 'vacation')<x-ui.buttons.button-basic type="button" wire:click="openForm('adjustment')">Zeitkorrektur</x-ui.buttons.button-basic>@endif @endif
                @if($canConfigure && $tab === 'models')<x-ui.buttons.button-basic type="button" wire:click="openForm('model')">Arbeitsmodell anlegen</x-ui.buttons.button-basic>@endif
                @if($canConfigure && $tab === 'policies')<x-ui.buttons.button-basic type="button" wire:click="openForm('policy')">Urlaubsrichtlinie</x-ui.buttons.button-basic>@endif
                @if($canConfigure && $tab === 'rules')<x-ui.buttons.button-basic type="button" wire:click="openForm('rule')">Regeln zuordnen</x-ui.buttons.button-basic>@endif
                @if(!$personal && $tab === 'responsibilities' && auth()->user()->isAdmin())<x-ui.buttons.button-basic type="button" wire:click="openForm('responsibility')">Zuständigkeit</x-ui.buttons.button-basic>@endif
                @if($canProcesses && $tab === 'tasks')<x-ui.buttons.button-basic type="button" wire:click="openForm('task')">Aufgabe</x-ui.buttons.button-basic>@endif
                @if($canTraining && $tab === 'training')@if($profileUserId === null)<x-ui.buttons.button-basic type="button" wire:click="openForm('training')">Schulung</x-ui.buttons.button-basic>@else<a class="text-sm text-rt-muted underline" href="{{ \App\Support\Operations\OperationsPages::url('people', ['view' => 'training', 'user' => $profileUserId]) }}" data-no-navigate>Schulungen verwalten</a>@endif<x-ui.buttons.button-basic type="button" wire:click="openForm('enroll')">Teilnehmer zuordnen</x-ui.buttons.button-basic>@endif
                @if($personal && $tab === 'absences')<x-ui.buttons.button-basic type="button" wire:click="openForm('vacation')">Urlaub beantragen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="openForm('sick')">Krankmelden</x-ui.buttons.button-basic>@endif
            </div></header>
            @if($profileUserId !== null && $tab === 'rules')<p class="text-sm text-rt-muted">Hier ordnen Sie diesem Mitarbeiter ein Regelprofil mit Gültigkeitszeitraum zu. Arbeitsmodell und Urlaubsrichtlinie werden in den benachbarten Menüpunkten gepflegt.</p>@endif
            @if($profileUserId !== null && $tab === 'training')<p class="text-sm text-rt-muted">Hier bearbeiten Sie die Teilnahme dieses Mitarbeiters. Kursanlage und Änderungen für alle Teilnehmer finden Sie unter „Schulungen verwalten“.</p>@endif
            <x-tables.table class="rt-personnel-table" :label="$recordHeading" :columns="[['label'=>'Eintrag','key'=>'label'],['label'=>'Zeitraum / Wert','key'=>'value'],['label'=>'Status','key'=>'status'],['label'=>'Aktionen','key'=>'actions']]" :items="$records" row-view="components.tables.rows.personnel.workforce-account-row" empty="Keine Einträge in dieser Ansicht." />
            @if($records instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<x-tables.pagination :paginator="$records" />@endif
            </section>
        @else<div class="rt-personnel-empty"><strong>{{ $personal || $embedded || $employees->isEmpty() ? 'Keine Mitarbeiter vorhanden' : 'Kein Mitarbeiter ausgewählt' }}</strong>@if(!$personal && !$embedded && $employees->isNotEmpty())<p>Wählen Sie einen Mitarbeiter, um die Personalübersicht zu öffnen.</p>@endif</div>@endif
    @endif
    <x-operations.modal wire:model="formOpen" :title="['plan_review'=>'Dienst neu beurteilen','model'=>'Arbeitsmodell anlegen','end_model'=>'Arbeitsmodell beenden','policy'=>'Urlaubsrichtlinie anlegen','credit'=>'Urlaubsanspruch buchen','adjustment'=>'Zeitkorrektur buchen','rule'=>'Regeln zuordnen','task'=>'Aufgabe anlegen','training'=>'Schulung anlegen','enroll'=>'Teilnehmer zuordnen','responsibility'=>'Zuständigkeit anlegen','vacation'=>'Urlaub beantragen','sick'=>'Krankmelden','sickness_correction'=>'Krankmeldung korrigieren'][$formKind] ?? 'Personal'">
        <form id="workforce-account-form-{{ $this->getId() }}" wire:submit="save" class="rt-personnel-management rt-personnel-account-form ops-form rt-personal-form rt-employee-form" data-employee-form>
            @if($employee && in_array($formKind, ['model', 'end_model', 'rule', 'plan_review', 'adjustment', 'responsibility'], true))
                <p class="ops-full ops-muted">{{ $employee->name }}</p>
            @endif
            @if($actionRecord && $formKind === 'plan_review')
                <section class="ops-full rt-personnel-surface">
                    <h3>{{ $actionRecord->latest_snapshot['shift']['title'] ?? $actionRecord->shift?->title ?? 'Dienst' }}</h3>
                    <p class="ops-muted">Plan R{{ $actionRecord->latest_snapshot['shift']['published_revision'] ?? $actionRecord->plan_revision }} · Prüfung R{{ $actionRecord->revision }}</p>
                    @foreach($actionRecord->latest_snapshot['issues'] ?? [] as $issue)<p>{{ $issue['message'] }}</p>@endforeach
                </section>
            @elseif($actionRecord && $formKind === 'end_model')
                <p class="ops-full"><strong>{{ $actionRecord->name }}</strong> · gültig ab {{ \Carbon\CarbonImmutable::parse($actionRecord->starts_on)->format('d.m.Y') }}</p>
            @endif
            @if($formKind === 'model')
                @include('livewire.operations.partials.workforce-accounts.form-work-model')
            @elseif($formKind === 'policy')
                <x-operations.field label="Bezeichnung" model="form.name" required :wide="true"/><x-operations.field label="Gültig ab" model="form.starts_on" type="date" required/><x-operations.field label="Gültig bis" model="form.ends_on" type="date"/><x-operations.field label="Einheit" model="form.unit" type="select" required><option value="">Auswählen</option><option value="days">Tage</option><option value="minutes">Minuten</option></x-operations.field><x-operations.field label="Feiertagsregion" model="form.holiday_region"/><x-operations.field label="Kalenderstand" model="form.calendar_version" required/><x-operations.field label="Arbeitsfreie Daten (JJJJ-MM-TT)" model="form.non_working_dates_text" type="textarea" :wide="true"/>
            @elseif($formKind === 'credit')
                <x-operations.field label="Buchungsdatum" model="form.effective_on" type="date" required/><x-operations.field label="Ablauf" model="form.expires_on" type="date"/><x-operations.field label="Anspruchsjahr" model="form.entitlement_year" type="number" required/><x-operations.field label="Menge in Richtlinieneinheit" model="form.amount" type="number" min="0.01" step="0.01" required/><x-operations.field label="Art" model="form.kind" type="select"><option value="grant">Anspruch</option><option value="carry">Übertrag</option><option value="manual">Manuelle Gutschrift</option></x-operations.field><x-operations.field label="Begründung / Quelle" model="form.note" required :wide="true"/>
            @elseif($formKind === 'adjustment')
                <x-operations.field label="Buchungsdatum" model="form.effective_on" type="date" required/><x-operations.field label="Zeitkorrektur (+/− Minuten)" model="form.quantity" type="number" required/><x-operations.field label="Begründung" model="form.note" type="textarea" rows="2" required :wide="true"/>
            @elseif($formKind === 'rule')
                <x-operations.field label="Regelprofil" model="form.operations_rule_profile_id" type="select" required><option value="">Auswählen</option>@foreach($rules as $rule)<option value="{{ $rule->id }}">{{ $rule->name }}</option>@endforeach</x-operations.field><x-operations.field label="Gültig ab" model="form.starts_on" type="date" required/><x-operations.field label="Gültig bis" model="form.ends_on" type="date"/>
            @elseif($formKind === 'task')
                <x-operations.field label="Aufgabe" model="form.title" required :wide="true"/><x-operations.field label="Prozess" model="form.type" type="select"><option value="general">Personal</option><option value="onboarding">Eintritt</option><option value="offboarding">Austritt</option></x-operations.field><x-operations.field label="Verantwortlich" model="form.assigned_to" type="select" required><option value="">Auswählen</option>@foreach($assignees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field><x-operations.field label="Fällig am" model="form.due_on" type="date"/><x-operations.field label="Notiz" model="form.note" type="textarea" :wide="true"/>
            @elseif($formKind === 'training')
                <x-operations.field label="Schulung" model="form.title" required :wide="true"/><x-operations.field label="Nachweisart" model="form.qualification_type_id" type="select"><option value="">Keine Zuordnung</option>@foreach($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</x-operations.field><x-operations.field label="Beginn" model="form.starts_at" type="datetime-local" required/><x-operations.field label="Ende" model="form.ends_at" type="datetime-local" required/><x-operations.field label="Zeitzone" model="form.timezone" required/><x-operations.field label="Plätze" model="form.capacity" type="number" min="1" required/>
            @elseif($formKind === 'enroll')
                <div class="ops-full"><x-ui.forms.label for="workforce-training" value="Schulung"/><x-ui.forms.select id="workforce-training" wire:model.live="form.personnel_training_id" wire:change="selectTraining" required><option value="">Auswählen</option>@foreach($trainings as $training)<option value="{{ $training->id }}">{{ $training->title }} · {{ $training->starts_at->format('d.m.Y H:i') }}</option>@endforeach</x-ui.forms.select></div>
            @elseif($formKind === 'responsibility')
                <x-operations.field label="Verantwortlich" model="form.responsible_user_id" type="select" required><option value="">Auswählen</option>@foreach($assignees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field><x-operations.field label="Gültig ab" model="form.starts_on" type="date" required/><x-operations.field label="Gültig bis" model="form.ends_on" type="date"/>
                <fieldset class="ops-full grid gap-2 sm:grid-cols-2"><legend class="text-sm font-medium">Zuständigkeiten</legend>@foreach(['employees.master-data.view'=>'Personal anzeigen','employees.master-data.edit'=>'Personal bearbeiten','employees.compensation.view'=>'Vergütung anzeigen','employees.compensation.edit'=>'Vergütung bearbeiten','operations.rules.manage'=>'Regeln verwalten','operations.absences.review'=>'Abwesenheiten prüfen','operations.qualifications.manage'=>'Nachweise / Schulung','operations.time.review'=>'Arbeitszeit prüfen','operations.time.export'=>'Arbeitszeit exportieren'] as $ability=>$label)<x-ui.forms.checkbox wire:model="form.abilities" :value="$ability" :label="$label" />@endforeach</fieldset>
            @elseif($formKind === 'vacation')
                @include('livewire.operations.partials.workforce-accounts.form-vacation')
            @elseif(in_array($formKind, ['sick','sickness_correction']))
                @include('livewire.operations.partials.employee-sickness-form')
            @else
                @if(in_array($formKind,['end_model','end_policy']))<x-operations.field label="Gültig bis" model="form.ends_on" type="date" required/>@endif
                @if($formKind === 'reduce_credit')<x-operations.field :label="$correctionUnit === 'days' ? 'Korrektur (Tage)' : 'Korrektur (min)'" model="form.quantity" type="number" :min="$correctionUnit === 'days' ? '0.01' : '1'" :step="$correctionUnit === 'days' ? '0.01' : '1'" required/>@endif
                @if($formKind === 'participation')<x-operations.field label="Aktion" model="form.action" type="select"><option value="attend">Teilnahme bestätigen</option><option value="cancel">Teilnahme stornieren</option></x-operations.field>@endif
                <x-operations.field label="Begründung" model="form.note" required :wide="true"/>
            @endif
        </form>
        <x-slot:footer><div class="rt-employee-form__actions"><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Abbrechen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="submit" :form="'workforce-account-form-'.$this->getId()" mode="primary" wire:loading.attr="disabled" wire:target="save">{{ match($formKind) {'vacation' => 'Antrag einreichen', 'sick' => 'Krankmeldung senden', 'model' => 'Entwurf speichern', 'end_model' => 'Arbeitsmodell beenden', 'adjustment' => 'Korrektur buchen', 'rule' => 'Zuordnung speichern', 'plan_review' => 'Erneut prüfen', default => 'Speichern'} }}</x-ui.buttons.button-basic></div></x-slot:footer>
    </x-operations.modal>
</div>
