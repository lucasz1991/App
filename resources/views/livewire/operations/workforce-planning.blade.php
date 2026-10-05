<section class="rt-ops ops-stack min-w-0" aria-label="{{ $personal ? 'Meine Planung' : 'Dispositionsprozesse' }}">
    @php
        $tabs = $personal ? ['wishes'=>'Wünsche','offers'=>'Angebote','transfers'=>'Übergabe & Tausch'] : ['pools'=>'Pools','wishes'=>'Wünsche','periods'=>'Abgabefristen','offers'=>'Angebote','transfers'=>'Übergabe & Tausch','cases'=>'Ausfall & Ablösung'];
        $columns = match($tab) {
            'pools' => [['label'=>'Pool','key'=>'name'],['label'=>'Typ / Standort','key'=>'pool'],['label'=>'Mitarbeiter','key'=>'users_count']],
            'wishes' => [['label'=>'Mitarbeiter / Wunsch','key'=>'wish'],['label'=>'Zeitfenster','key'=>'window'],['label'=>'Aktion','key'=>'actions']],
            'periods' => [['label'=>'Abgabezeitraum','key'=>'name'],['label'=>'Zeitraum / Frist','key'=>'period']],
            'offers' => [['label'=>'Dienst','key'=>'offer'],['label'=>'Frist / Status','key'=>'offer_status'],['label'=>'Rückmeldung','key'=>'actions']],
            'transfers' => [['label'=>'Dienst / Beteiligte','key'=>'transfer'],['label'=>'Status','key'=>'transfer_status'],['label'=>'Aktion','key'=>'actions']],
            'cases' => [['label'=>'Dienst / Fall','key'=>'case'],['label'=>'Frist / Status','key'=>'case_status'],['label'=>'Aktion','key'=>'actions']],
        };
    @endphp
    <header class="ops-toolbar">
        <nav class="ops-actions" aria-label="Planungsbereiche">@foreach($tabs as $key=>$label)<x-ui.buttons.button-basic type="button" :mode="$tab === $key ? 'primary' : 'secondary'" wire:click="setTab('{{ $key }}')" :aria-current="$tab === $key ? 'page' : 'false'">{{ $label }}</x-ui.buttons.button-basic>@endforeach</nav>
        @if($personal && $tab === 'wishes')<x-ui.buttons.button-basic type="button" wire:click="edit('wish')"><i class="far fa-plus" aria-hidden="true"></i>Wunsch einreichen</x-ui.buttons.button-basic>
        @elseif($personal && $tab === 'transfers')<x-ui.buttons.button-basic type="button" wire:click="edit('transfer')">Übergabe / Tausch anfragen</x-ui.buttons.button-basic>
        @elseif(!$personal && in_array($tab,['pools','periods','offers','cases']))<x-ui.buttons.button-basic type="button" wire:click="edit('{{ ['pools'=>'pool','periods'=>'period','offers'=>'offer','cases'=>'case'][$tab] }}')"><i class="far fa-plus" aria-hidden="true"></i>{{ ['pools'=>'Pool','periods'=>'Abgabefrist','offers'=>'Dienstangebot','cases'=>'Fall'][$tab] }} anlegen</x-ui.buttons.button-basic>@endif
    </header>
    <x-operations.feedback />
    @if($personal && $tab === 'wishes' && $periods->isNotEmpty())
        <x-tables.table :columns="[['label'=>'Abgabezeitraum','key'=>'name'],['label'=>'Frist','key'=>'due_at'],['label'=>'Abgabe','key'=>'submission_state']]" :items="$periods" row-view="components.tables.rows.operations.workforce-record" empty="Keine offenen Abgabefristen." />
    @endif
    <x-tables.table :columns="$columns" :items="$items" row-view="components.tables.rows.operations.workforce-record" empty="Keine Einträge." />
    {{ $items->links() }}

    <x-operations.modal wire:model="formOpen" :title="match($modal) {'wish'=>'Wunschverfügbarkeit','pool'=>'Planungspool','period'=>'Abgabefrist','offer'=>'Dienstangebot','transfer'=>'Übergabe / Tausch','review-transfer'=>'Übergabe / Tausch prüfen',default=>'Ausfall / Ablösung'}" max-width="4xl">
        <form wire:submit="save" class="ops-form">
            @if($modal === 'wish')
                <x-operations.field label="Wunsch" model="form.kind" type="select"><option value="available">Verfügbar</option><option value="preferred">Dienstwunsch</option><option value="unavailable">Freiwunsch</option></x-operations.field>
                <x-operations.field label="Abgabezeitraum" model="form.availability_period_id" type="select"><option value="">Ohne Abgabefrist</option>@foreach($periods as $period)<option value="{{ $period->id }}">{{ $period->name }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Von" model="form.from" type="date" required /><x-operations.field label="Bis" model="form.until" type="date" required />
                <fieldset class="ops-full ops-actions"><legend class="text-sm font-semibold">Wochentage</legend>@foreach([1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'] as $day=>$label)<x-ui.forms.checkbox wire:model="form.weekdays" :value="$day" :label="$label" />@endforeach</fieldset>
                <x-ui.forms.checkbox wire:model.live="form.whole_day" label="Ganztägig" />
                @if(!empty($form) && !($form['whole_day'] ?? true))<x-operations.field label="Beginn" model="form.start_time" type="time" required /><x-operations.field label="Ende" model="form.end_time" type="time" required />@endif
                <x-operations.field label="Bevorzugter Pool" model="form.preferred_pool_id" type="select"><option value="">Kein Poolwunsch</option>@foreach($pools as $pool)<option value="{{ $pool->id }}">{{ $pool->name }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Zeitzone" model="form.timezone" required /><x-operations.field label="Notiz" model="form.note" type="textarea" /><x-operations.field label="Begründung nach Abgabefrist" model="form.late_reason" type="textarea" />
                @if($recordId)<x-ui.buttons.button-basic type="button" wire:click="removeWish" wire:confirm="Wunsch entfernen?" wire:loading.attr="disabled">Wunsch entfernen</x-ui.buttons.button-basic>@endif
            @elseif($modal === 'pool')
                <x-operations.field label="Poolname" model="form.name" required /><x-operations.field label="Typ" model="form.kind" type="select"><option value="regular">Stammpool</option><option value="springer">Springer</option><option value="reserve">Reserve</option></x-operations.field>
                <x-operations.field label="Standort" model="form.location_name" /><x-operations.field label="Verantwortlich" model="form.responsible_id" type="select"><option value="">Nicht zugeordnet</option>@foreach($managers as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                <x-ui.forms.checkbox wire:model="form.is_active" label="Aktiv" />
                <fieldset class="ops-full grid gap-1 sm:grid-cols-2"><legend class="text-sm font-semibold">Mitarbeiter</legend>@foreach($people as $person)<x-ui.forms.checkbox wire:model="form.user_ids" :value="$person->id" :label="$person->name" />@endforeach</fieldset>
            @elseif($modal === 'period')
                <x-operations.field label="Name" model="form.name" required /><x-operations.field label="Abgabefrist" model="form.due_at" type="datetime-local" required />
                <x-operations.field label="Von" model="form.from" type="date" required /><x-operations.field label="Bis" model="form.until" type="date" required /><x-operations.field label="Zeitzone" model="form.timezone" required />
                @if($submissions->isNotEmpty())<div class="ops-full"><x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Abgabe','key'=>'status_label']]" :items="$submissions" row-view="components.tables.rows.operations.record" /></div>@endif
            @elseif($modal === 'offer')
                <div class="min-w-0 space-y-1.5"><x-ui.forms.label for="workforce-offer-shift" value="Veröffentlichter Dienst" /><x-ui.forms.select id="workforce-offer-shift" wire:model.live="form.shift_id"><option value="">Auswählen</option>@foreach($shifts->filter(fn($shift)=>$shift->revision === $shift->published_revision && $shift->published_revision > 0) as $shift)<option value="{{ $shift->id }}">{{ $shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i') }} · {{ $shift->title }}</option>@endforeach</x-ui.forms.select></div>
                <x-operations.field label="Angebotsfrist" model="form.expires_at" type="datetime-local" required /><x-operations.field label="Zeitzone" model="form.timezone" required />
                @if($planRevision)<fieldset class="ops-full space-y-1"><legend class="text-sm font-semibold">Geeignete Mitarbeiter</legend>@foreach($people as $person)<div wire:key="offer-person-{{ $person->id }}"><x-ui.forms.checkbox wire:model="form.user_ids" :value="$person->id" :label="$person->name" :disabled="($candidateIssues[$person->id] ?? []) !== []" />@if(($candidateWishes[$person->id] ?? 'unknown') !== 'unknown')<p class="ops-muted">{{ ['free_requested'=>'Freiwunsch','preferred'=>'Dienstwunsch','available'=>'Verfügbarkeit gemeldet'][$candidateWishes[$person->id]] ?? 'Planungswunsch' }}</p>@endif @foreach($candidateIssues[$person->id] ?? [] as $issue)<p class="ops-muted">{{ $issue['message'] }}</p>@endforeach</div>@endforeach</fieldset>@endif
            @elseif($modal === 'transfer')
                <div class="min-w-0 space-y-1.5"><x-ui.forms.label for="workforce-source-assignment" value="Mein bestätigter Dienst" /><x-ui.forms.select id="workforce-source-assignment" wire:model.live="form.source_id"><option value="">Auswählen</option>@foreach($ownAssignments as $assignment)<option value="{{ $assignment->id }}">Nr. {{ $assignment->id }} · {{ $assignment->shift->starts_at->setTimezone($assignment->shift->timezone)->format('d.m. H:i') }} · {{ $assignment->shift->title }}</option>@endforeach</x-ui.forms.select></div>
                <x-operations.field label="Übergabe an" model="form.target_user_id" type="select" required><option value="">Auswählen</option>@foreach($people->where('id','!=',auth()->id()) as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Tauschdienst-Nr. (optional)" model="form.target_id" type="number" min="1" /><x-operations.field label="Tauschdienst-Revision (optional)" model="form.target_revision" type="number" min="1" /><x-operations.field label="Notiz" model="form.note" type="textarea" />
            @elseif($modal === 'review-transfer')
                <x-operations.field label="Entscheidung" model="form.approve" type="select"><option value="1">Freigeben</option><option value="0">Ablehnen</option></x-operations.field><x-operations.field label="Begründung" model="form.note" type="textarea" />
            @elseif($modal === 'case' && !$recordId)
                <x-operations.field label="Dienst" model="form.shift_id" type="select" required><option value="">Auswählen</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}">{{ $shift->title }} · {{ $shift->starts_at->setTimezone($shift->timezone)->format('d.m. H:i') }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Betroffene Zuweisungs-Nr." model="form.shift_assignment_id" type="number" min="1" />
                <x-operations.field label="Falltyp" model="form.kind" type="select"><option value="failure">Ausfall</option><option value="relief">Ablösung</option><option value="reserve">Bereitschaft</option><option value="callout">Abruf</option></x-operations.field>
                <x-operations.field label="Verantwortlich" model="form.responsible_id" type="select">@foreach($managers as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Frist" model="form.due_at" type="datetime-local" required /><x-operations.field label="Zeitzone" model="form.timezone" required /><x-operations.field label="Meldung" model="form.note" type="textarea" required />
            @elseif($modal === 'case')
                <x-operations.field label="Aktion" model="form.action" type="select"><option value="contact">Kontaktversuch</option><option value="escalate">Eskalieren</option><option value="handover">Ablösung dokumentieren</option><option value="resolve">Mit bestätigtem Ersatz lösen</option></x-operations.field>
                <x-operations.field label="Mitarbeiter / Ersatz" model="form.user_id" type="select"><option value="">Auswählen</option>@foreach($people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Kontaktweg" model="form.channel" type="select"><option value="phone">Telefon</option><option value="email">E-Mail</option><option value="portal">Portal</option><option value="chat">Chat</option></x-operations.field>
                <x-operations.field label="Tatsächliche Ablösung" model="form.handed_over_at" type="datetime-local" /><x-operations.field label="Zeitzone" model="form.timezone" /><x-operations.field label="Ergebnis / Begründung" model="form.note" type="textarea" required />
            @endif
            <div class="ops-full ops-actions"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">{{ $modal === 'review-transfer' ? 'Entscheidung speichern' : 'Speichern' }}</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Abbrechen</x-ui.buttons.button-basic></div>
        </form>
    </x-operations.modal>
</section>
