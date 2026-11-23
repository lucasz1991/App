<section class="rt-ops ops-stack min-w-0 {{ $personal ? 'rt-personal-tasks-list' : '' }}" aria-label="{{ $mode === 'monitor' ? 'Leitstelle' : ($personal ? 'Meine Aufgaben' : 'Arbeitsliste') }}">
    @if($personal)
        @include('livewire.operations.partials.personal-tasks')
    @else
    <header class="ops-toolbar">
        @if(!$embedded)<nav class="ops-actions" aria-label="Arbeitsbereiche">
            @foreach($mode === 'monitor' ? ['board'=>'Dienststand','profiles'=>'Toleranzen'] : ['inbox'=>'Arbeitsliste','reminders'=>'Erinnerungen'] as $key=>$label)
                @if(($key !== 'profiles' || ($profilesReady && auth()->user()->can('operations.rules.manage'))) && ($key !== 'reminders' || ($preferencesReady && ($personal || auth()->user()->can('employees.master-data.edit')))))<x-ui.buttons.button-basic type="button" :mode="$tab === $key ? 'primary' : 'secondary'" wire:click="setTab('{{ $key }}')">{{ $label }}</x-ui.buttons.button-basic>@endif
            @endforeach
        </nav>@endif
        @if($tab === 'profiles' && auth()->user()->can('operations.rules.manage'))<x-ui.buttons.button-basic type="button" wire:click="edit">Profil anlegen</x-ui.buttons.button-basic>
        @elseif($tab === 'reminders')<x-ui.buttons.button-basic type="button" wire:click="edit">Erinnerung anlegen</x-ui.buttons.button-basic>@endif
    </header>
    <x-operations.feedback />
    <x-tables.toolbar :title="$tab === 'board' ? 'Dienststand filtern' : 'Arbeitsliste durchsuchen'" :id="'attention-filters-'.$this->getId()">
        <x-slot:search><x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Arbeitsliste durchsuchen" /></x-slot:search>
        @if($tab === 'board')<x-operations.field label="Von" model="from" type="date" /><x-operations.field label="Bis" model="until" type="date" />@endif
    </x-tables.toolbar>
    <x-tables.table :columns="match($tab) {
        'board'=>[['label'=>'Mitarbeiter / Dienst','key'=>'duty'],['label'=>'Plan / Meldung','key'=>'period'],['label'=>'Dienststand','key'=>'state'],['label'=>'Aktion','key'=>'actions']],
        'profiles'=>[['label'=>'Profil','key'=>'name'],['label'=>'Toleranzen','key'=>'tolerances'],['label'=>'Status','key'=>'active'],['label'=>'Aktion','key'=>'actions']],
        'reminders'=>[['label'=>'Erinnerung','key'=>'preference'],['label'=>'Vorlauf / Ruhefenster','key'=>'quiet'],['label'=>'Status','key'=>'active'],['label'=>'Aktion','key'=>'actions']],
        default=>[['label'=>'Vorgang','key'=>'work_item'],['label'=>'Frist / Status','key'=>'due'],['label'=>'Aktion','key'=>'actions']],
    }" :items="$items" row-view="components.tables.rows.planning.attention-record-row" :empty="$tab === 'reminders' ? 'Noch keine Erinnerungen angelegt.' : 'Keine offenen Einträge.'" />
    <x-tables.pagination :paginator="$items" />
    @endif
    @php($attentionFormId = 'attention-form-'.$this->getId())
    <x-operations.modal wire:model="formOpen" :title="$tab === 'profiles' ? 'Leitstellenprofil' : ($recordId ? 'Erinnerung bearbeiten' : 'Erinnerung anlegen')" max-width="3xl">
        @if($formOpen)
        <form id="{{ $attentionFormId }}" wire:submit="save" class="ops-form rt-personal-form rt-employee-form" data-employee-form>
            @if($tab === 'profiles')
                <x-operations.field label="Name" model="form.name" required />
                <x-operations.field label="Starttoleranz · Minuten" model="form.start_grace_minutes" type="number" min="0" max="1440" required />
                <x-operations.field label="Endtoleranz · Minuten" model="form.end_grace_minutes" type="number" min="0" max="1440" required />
                <x-operations.field label="Zuständig" model="form.responsible_user_id" type="select"><option value="">Auswählen</option>@foreach($managers as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</x-operations.field>
                <x-ui.forms.checkbox wire:model="form.auto_cases" label="Überfällige Meldungen als Prüffall anlegen" />
            @else
                @if(!$personal)<x-operations.field label="Mitarbeiter" model="form.user_id" type="select" :disabled="$subjectId !== null"><option value="">Auswählen</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</x-operations.field>@endif
                <x-operations.field label="Bereich" model="form.kind" type="select">@foreach(\App\Services\Operations\OperationsReminderService::KINDS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Vorlauf · Minuten" model="form.lead_minutes" type="number" min="0" max="86400" required />
                <fieldset class="ops-full rt-employee-form__section"><legend>Ruhefenster (optional)</legend>
                    <div class="rt-employee-form__grid">
                        <x-operations.field label="Ruhefenster von" model="form.quiet_from" type="time" />
                        <x-operations.field label="Ruhefenster bis" model="form.quiet_until" type="time" />
                    </div>
                </fieldset>
                <x-operations.field label="Zeitzone" model="form.timezone" required />
            @endif
            <x-ui.forms.checkbox wire:model="form.is_active" label="Aktiv" />
        </form>
        @endif
        <x-slot:footer>
            <div class="rt-employee-form__actions">
                <x-ui.buttons.button-basic type="button" wire:click="$set('formOpen', false)" wire:loading.attr="disabled" wire:target="save">Abbrechen</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="submit" form="{{ $attentionFormId }}" mode="primary" wire:loading.attr="disabled" wire:target="save">Speichern</x-ui.buttons.button-basic>
            </div>
        </x-slot:footer>
    </x-operations.modal>
</section>
