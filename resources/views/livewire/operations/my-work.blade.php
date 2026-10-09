<div class="rt-ops ops-stack rt-personal-work" data-personal-workspace data-personal-work-tab="{{ $tab }}" wire:poll.60s
    @if($workTimeReady) x-data="rtWorkTimeCapture({actorId:{{ auth()->id() }},timezone:@js($displayTimezone),bootstrapUrl:@js(route('operations.capture.bootstrap')),syncUrl:@js(route('operations.capture.sync'))})" x-on:work-clock="capture($event.detail)" x-on:worktime-synced.window.debounce.300ms="$wire.$refresh()" x-on:time-sections-saved.window="$wire.closeSections()" @endif>
    <nav class="ops-tabs ops-personal-tabs" aria-label="Mein Arbeitsplatz">
        @foreach($personalTabs as $key => $label)
            <button type="button" wire:key="personal-work-tab-{{ $key }}" wire:click="showTab('{{ $key }}')" wire:loading.attr="disabled" wire:target="showTab" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>
    @if($tab === 'today')
        <header class="rt-personal-work-heading">
            <div>
                <p class="rt-personal-work-eyebrow">{{ now($displayTimezone)->locale('de')->isoFormat('dddd, D. MMMM') }}</p>
                <h2>Dein Arbeitstag im Blick</h2>
                <p class="ops-muted">Arbeitszeit erfassen und die nächsten Dienste planen.</p>
            </div>
            <x-ui.buttons.button-basic wire:click="showTab('absences')" wire:loading.attr="disabled" wire:target="showTab" class="rt-personal-leave-shortcut">Urlaub planen</x-ui.buttons.button-basic>
        </header>
    @elseif($tab === 'time')
        <header class="rt-personal-work-heading"><div><h2>Meine Zeitmeldungen</h2><p class="ops-muted">Erfasste Zeiten prüfen, ergänzen und einreichen.</p></div></header>
    @elseif($tab === 'records')
        <header class="rt-personal-work-heading"><div><h2>Meine Nachweise</h2><p class="ops-muted">Dokumente, Qualifikationen und Gültigkeit im Überblick.</p></div><x-ui.buttons.button-basic mode="primary" wire:click="openForm('qualification')" wire:loading.attr="disabled">Nachweis einreichen</x-ui.buttons.button-basic></header>
    @elseif($tab === 'absences')
        <header class="rt-personal-work-heading"><div><h2>Urlaub & Abwesenheiten</h2><p class="ops-muted">Zeiträume beantragen und den Stand deiner Anträge verfolgen.</p></div><x-ui.buttons.button-basic mode="primary" wire:click="openForm('absence')" wire:loading.attr="disabled">Abwesenheit beantragen</x-ui.buttons.button-basic></header>
    @endif
    <x-operations.feedback :show-offline="!$workTimeReady" />
    @if(in_array($tab, ['today','time']) && $workTimeReady)
        @include('operations.partials.work-clock')
        <x-operations.modal wire:model="generalManualOpen" title="Arbeitszeit nachtragen"><form wire:submit="saveGeneralManual" class="ops-form rt-personal-form">
            <x-operations.field label="Arbeitskontext" model="generalManual.work_context" type="select"><option value="internal">Interne Arbeit</option><option value="unplanned">Ungeplante Arbeit</option><option value="training">Schulung</option></x-operations.field>
            <x-operations.field label="Tätigkeit" model="generalManual.title" required maxlength="180" />
            <x-operations.field label="Schulung (bei Schulungszeit)" model="generalManual.training_session_id" type="select"><option value="">Auswählen</option>@foreach($clockTrainings as $training)<option value="{{ $training->id }}">{{ $training->title }}</option>@endforeach</x-operations.field>
            <x-operations.field label="Zeitzone" model="generalManual.timezone" required />
            <x-operations.field label="Beginn" model="generalManual.starts_at" type="datetime-local" required />
            <x-operations.field label="Ende" model="generalManual.ends_at" type="datetime-local" required />
            <x-operations.field label="Pause (min)" model="generalManual.pause_minutes" type="number" min="0" required />
            <x-operations.field label="Grund des Nachtrags" model="generalManual.note" required minlength="5" :wide="true" />
            <div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Speichern</x-ui.buttons.button-basic></div>
        </form></x-operations.modal>
    @elseif(in_array($tab, ['today','time']) && $activeTime)
        <section class="ops-panel ops-service ops-stack" aria-label="Laufende Zeiterfassung" wire:poll.15s><header class="ops-toolbar"><div><p class="ops-kicker">Seit {{ $activeTime->starts_at->format('H:i') }} · {{ $activeTime->timezone }}</p><h2>{{ $activeTime->plan_snapshot['title'] }}</h2></div><x-operations.status :value="$activeTime->status" /></header><p class="ops-clock">{{ \App\Support\Operations\OperationsDateTime::duration($activeTime->netSeconds()) }}</p><div class="ops-actions">@if($activeTime->status === 'running')<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'pause')" wire:loading.attr="disabled">Pause starten</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic mode="primary" wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'resume')" wire:loading.attr="disabled">Weiterarbeiten</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic wire:click="clock({{ $activeTime->id }},{{ $activeTime->revision }},'stop')" wire:confirm="Zeiterfassung beenden?" wire:loading.attr="disabled">Dienst beenden</x-ui.buttons.button-basic></div></section>
    @endif
    @if($tab === 'schedule')
        @include('livewire.operations.partials.personal-calendar')
    @elseif($tab === 'today')
        <section class="rt-personal-duty-list ops-stack" aria-labelledby="personal-upcoming-heading">
            <header class="ops-toolbar"><div><h3 id="personal-upcoming-heading">Nächste Dienste</h3><p class="ops-muted">Dein veröffentlichter Dienstplan für die nächsten 14 Tage.</p></div><x-ui.buttons.button-basic mode="link" wire:click="showTab('schedule')" wire:loading.attr="disabled" wire:target="showTab">Mein Kalender</x-ui.buttons.button-basic></header>
            <x-tables.table class="rt-personal-record-list" :columns="[['label'=>'Dienstplan','key'=>'shift']]" :items="$assignments" row-view="components.tables.rows.operations.personal-shift" empty="Keine veröffentlichten Dienste in diesem Zeitraum." />
        </section>
        @if($tab === 'today' && $times->contains(fn($entry) => in_array($entry->status,['completed','returned'],true)))<button class="ops-panel ops-toolbar rt-personal-time-reminder" type="button" wire:click="showTab('time')" wire:loading.attr="disabled" wire:target="showTab"><span><strong>Zeitmeldungen bearbeiten</strong><span class="ops-muted">Erfasste Zeiten prüfen und einreichen.</span></span><span class="ops-link">Öffnen</span></button>@endif
    @elseif($tab === 'time')
        @if($workTimeReady)<livewire:operations.work-time-capture-review :personal="true" />@endif
        <x-tables.toolbar title="Zeitraum" id="personal-time-filters" class="rt-personal-time-filters">
            <x-slot:bulk><x-ui.buttons.button-basic wire:click="exportOwnTimes" wire:loading.attr="disabled">Dienstzeiten CSV v1</x-ui.buttons.button-basic>@if($workTimeReady)<x-ui.buttons.button-basic wire:click="exportOwnWorkTimes" wire:loading.attr="disabled">Arbeitszeiten CSV v2</x-ui.buttons.button-basic>@endif</x-slot:bulk>
            <div><x-ui.forms.label for="my-time-from" value="Von" /><x-ui.forms.date-field id="my-time-from" wire:model.live="timeFrom" aria-label="Meine Zeiten von" /></div>
            <div><x-ui.forms.label for="my-time-until" value="Bis" /><x-ui.forms.date-field id="my-time-until" wire:model.live="timeUntil" aria-label="Meine Zeiten bis" /></div>
        </x-tables.toolbar>
        @if($timeCompleteness && !$timeCompleteness['complete'])
            <section class="ops-panel ops-stack"><header class="ops-toolbar"><h3>Offene Zeitmeldungen</h3><span class="ops-badge">{{ count($timeCompleteness['missing_shift_times']) + count($timeCompleteness['missing_training_times'] ?? []) + count($timeCompleteness['unfinished_times']) }}</span></header>
                <x-tables.table :columns="[['label'=>'Dienst ohne Zeitmeldung','key'=>'title'],['label'=>'Datum','key'=>'starts_at']]" :items="collect($timeCompleteness['missing_shift_times'])->map(fn($row)=>(object)array_replace($row,['id'=>$row['assignment_id'],'starts_at'=>\Carbon\CarbonImmutable::parse($row['starts_at'])->setTimezone($displayTimezone)]))" row-view="components.tables.rows.operations.record" empty="Keine fehlenden Dienstzeiten." />
                @if($timeCompleteness['missing_training_times'] ?? [])<x-tables.table :columns="[['label'=>'Schulung ohne Zeitmeldung','key'=>'title'],['label'=>'Datum','key'=>'starts_at']]" :items="collect($timeCompleteness['missing_training_times'])->map(fn($row)=>(object)array_replace($row,['id'=>$row['training_session_id'],'starts_at'=>\Carbon\CarbonImmutable::parse($row['starts_at'])->setTimezone($displayTimezone)]))" row-view="components.tables.rows.operations.record" />@endif
                <x-tables.table :columns="[['label'=>'Nicht eingereicht','key'=>'title'],['label'=>'Status','key'=>'status']]" :items="collect($timeCompleteness['unfinished_times'])->map(fn($row)=>(object)$row)" row-view="components.tables.rows.operations.record" empty="Alle erfassten Zeiten eingereicht." />
            </section>
        @endif
        @if($manualAssignments->isNotEmpty())
            <x-ui.buttons.button-basic wire:click="openForm('manual')">Zeit nachtragen</x-ui.buttons.button-basic><x-operations.modal wire:model="manualOpen" title="Zeit nachtragen"><form wire:submit="saveManual" class="ops-form rt-personal-form">
                <x-operations.field label="Dienst" model="manualAssignmentId" type="select" :wide="true" required><option value="">Dienst auswählen</option>@foreach($manualAssignments as $item)<option value="{{ $item->id }}">{{ $item->shift->starts_at->format('d.m.Y') }} · {{ $item->shift->title }} · {{ $item->shift->timezone }}</option>@endforeach</x-operations.field>
                <x-operations.field label="Beginn · Dienstzeitzone" model="manualTime.starts_at" type="datetime-local" required /><x-operations.field label="Ende · Dienstzeitzone" model="manualTime.ends_at" type="datetime-local" required /><x-operations.field label="Pause (min)" model="manualTime.pause_minutes" type="number" min="0" required /><x-operations.field label="Grund des Nachtrags" model="manualTime.note" required minlength="5" /><div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Nachtrag speichern</x-ui.buttons.button-basic></div>
            </form></x-operations.modal>
        @endif
        <x-operations.modal wire:model="correctionOpen" title="Zeit korrigieren"><form wire:submit="correct" class="ops-panel ops-form rt-personal-form"><h3 class="ops-full">Zeit korrigieren</h3><x-operations.field label="Beginn" model="correction.starts_at" type="datetime-local" required /><x-operations.field label="Ende" model="correction.ends_at" type="datetime-local" required /><x-operations.field label="Pause (min)" model="correction.pause_minutes" type="number" min="0" required /><x-operations.field label="Korrekturgrund" model="correction.note" required minlength="5" /><div class="ops-full ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Korrektur speichern</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="cancelCorrection">Abbrechen</x-ui.buttons.button-basic></div></form></x-operations.modal>
        <x-tables.table class="rt-personal-record-list" :columns="[['label'=>'Zeitmeldung','key'=>'entry']]" :items="$times" row-view="components.tables.rows.operations.personal-time" empty="Noch keine Zeitmeldungen. Starte eine Zeiterfassung oder trage eine Zeit nach." />{{ $times->links() }}
        <x-operations.modal wire:model="sectionsOpen" title="Tatsächliche Arbeitsabschnitte">@if($sectionsOpen && $sectionsId)<livewire:operations.work-time-sections :entry-id="$sectionsId" :key="'time-sections-'.$sectionsId" />@endif</x-operations.modal>
    @elseif($tab === 'personnel')
        @if(class_exists(\App\Livewire\Operations\WorkforceAccounts::class))<livewire:operations.workforce-accounts :personal="true" />@endif
    @elseif($tab === 'planning')
        <livewire:operations.workforce-planning :personal="true" :tab="request()->query('planning_tab') === 'offers' ? 'offers' : 'wishes'" />
    @elseif($tab === 'records')
        @if(class_exists(\App\Livewire\Operations\PersonnelDocuments::class) && \Illuminate\Support\Facades\Schema::hasTable('employee_document_versions'))<livewire:operations.personnel-documents />@endif
        <x-operations.modal wire:model="qualificationOpen" title="Nachweis einreichen"><form wire:submit="upload" class="ops-form rt-personal-form"><x-operations.field label="Nachweisart" model="qualification.qualification_type_id" type="select" required :wide="true"><option value="">Auswählen</option>@foreach($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</x-operations.field><x-operations.field label="Gültig ab" model="qualification.valid_from" type="date" required /><x-operations.field label="Gültig bis" model="qualification.valid_until" type="date" required /><x-operations.field label="Datei · PDF, JPG oder PNG · max. 10 MB" model="evidence" type="file" accept="application/pdf,image/jpeg,image/png" required :wide="true" /><div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Einreichen</x-ui.buttons.button-basic></div></form></x-operations.modal><x-tables.table class="rt-personal-record-list" :columns="[['label'=>'Nachweis','key'=>'record']]" :items="$qualifications" row-view="components.tables.rows.operations.personal-qualification" empty="Keine Nachweise eingereicht. Ergänze hier deine gültigen Qualifikationen." />{{ $qualifications->links() }}
    @else
        <x-operations.modal wire:model="absenceOpen" title="Abwesenheit beantragen"><form wire:submit="requestAbsence" class="ops-form rt-personal-form"><x-operations.field label="Art" model="absence.kind" type="select"><option value="vacation">Urlaub</option><option value="unavailable">Nicht verfügbar</option><option value="other">Abwesenheit</option></x-operations.field><x-operations.field label="Zeitzone" model="absence.timezone" required /><x-operations.field label="Beginn" model="absence.starts_at" type="datetime-local" required /><x-operations.field label="Ende" model="absence.ends_at" type="datetime-local" required /><x-operations.field label="Notiz (optional)" model="absence.note" maxlength="1000" :wide="true" /><div class="ops-full"><p class="ops-muted">Der Antrag wird nach dem Einreichen geprüft. Den Status siehst du in deiner Übersicht.</p></div><div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Antrag einreichen</x-ui.buttons.button-basic></div></form></x-operations.modal><x-tables.table class="rt-personal-record-list" label="Meine Abwesenheiten" :columns="[['label'=>'Abwesenheit','key'=>'record']]" :items="$absences" row-view="components.tables.rows.operations.personal-absence" empty="Noch keine Abwesenheiten. Beantrage hier Urlaub oder einen anderen Zeitraum." />{{ $absences->links() }}
    @endif
</div>
