<section class="rt-ops ops-stack min-w-0" aria-label="{{ $mode === 'monitor' ? 'Leitstelle' : 'Arbeitsliste' }}">
    <header class="ops-toolbar">
        <nav class="ops-actions" aria-label="Arbeitsbereiche">
            @foreach($mode === 'monitor' ? ['board'=>'Dienststand','profiles'=>'Toleranzen'] : ['inbox'=>'Arbeitsliste','reminders'=>'Erinnerungen'] as $key=>$label)
                @if(($key !== 'profiles' || ($profilesReady && auth()->user()->can('operations.rules.manage'))) && ($key !== 'reminders' || ($preferencesReady && ($personal || auth()->user()->can('employees.master-data.edit')))))<x-ui.buttons.button-basic type="button" :mode="$tab === $key ? 'primary' : 'secondary'" wire:click="setTab('{{ $key }}')">{{ $label }}</x-ui.buttons.button-basic>@endif
            @endforeach
        </nav>
        @if($tab === 'profiles' && auth()->user()->can('operations.rules.manage'))<x-ui.buttons.button-basic type="button" wire:click="edit">Profil anlegen</x-ui.buttons.button-basic>
        @elseif($tab === 'reminders')<x-ui.buttons.button-basic type="button" wire:click="edit">Erinnerung anlegen</x-ui.buttons.button-basic>@endif
    </header>
    <x-operations.feedback />
    @if($tab === 'board')<div class="ops-form"><x-operations.field label="Von" model="from" type="date" /><x-operations.field label="Bis" model="until" type="date" /></div>@endif
    <x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Arbeitsliste durchsuchen" />
    <x-tables.table :columns="match($tab) {
        'board'=>[['label'=>'Mitarbeiter / Dienst','key'=>'duty'],['label'=>'Plan / Meldung','key'=>'period'],['label'=>'Dienststand','key'=>'state'],['label'=>'Aktion','key'=>'actions']],
        'profiles'=>[['label'=>'Profil','key'=>'name'],['label'=>'Toleranzen','key'=>'tolerances'],['label'=>'Status','key'=>'active'],['label'=>'Aktion','key'=>'actions']],
        'reminders'=>[['label'=>'Erinnerung','key'=>'preference'],['label'=>'Vorlauf / Ruhefenster','key'=>'quiet'],['label'=>'Status','key'=>'active'],['label'=>'Aktion','key'=>'actions']],
        default=>[['label'=>'Vorgang','key'=>'work_item'],['label'=>'Frist / Status','key'=>'due'],['label'=>'Aktion','key'=>'actions']],
    }" :items="$items" row-view="components.tables.rows.operations.attention-record" empty="Keine offenen Einträge." />
    {{ $items->links() }}
    <x-operations.modal wire:model="formOpen" :title="$tab === 'profiles' ? 'Leitstellenprofil' : 'Erinnerung'" max-width="3xl">
        @if($formOpen)
        <form wire:submit="save" class="ops-form">
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
                <x-operations.field label="Ruhefenster von" model="form.quiet_from" type="time" /><x-operations.field label="Ruhefenster bis" model="form.quiet_until" type="time" />
                <x-operations.field label="Zeitzone" model="form.timezone" required />
            @endif
            <x-ui.forms.checkbox wire:model="form.is_active" label="Aktiv" />
            <div class="ops-actions ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Abbrechen</x-ui.buttons.button-basic></div>
        </form>
        @endif
    </x-operations.modal>
</section>
