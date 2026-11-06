<div class="rt-ops ops-stack rt-personal-work" data-personal-workspace data-personal-work-tab="{{ $tab }}" wire:poll.60s
    @if($workTimeReady) x-data="rtWorkTimeCapture({actorId:{{ auth()->id() }},timezone:@js($displayTimezone),bootstrapUrl:@js(route('operations.capture.bootstrap')),syncUrl:@js(route('operations.capture.sync'))})" x-on:work-clock="capture($event.detail)" x-on:worktime-synced.window.debounce.300ms="$wire.$refresh()" x-on:time-sections-saved.window="$wire.closeSections()" @endif>
    @if(count($personalTabs) > 1)
        <x-operations.workday-navigation :tabs="$personalTabs" :selected="$tab" :request-count="$requestCount" />
    @endif
    <div class="ops-stack rt-workday-screen" data-workday-screen wire:key="workday-screen-{{ $tab }}" role="region" tabindex="0" aria-label="{{ $personalTabs[$tab] ?? 'Mein Arbeitstag' }}">
        <x-operations.feedback :show-offline="!$workTimeReady" />
        @if($tab === 'requests')
            @include('livewire.operations.partials.personal-requests')
        @elseif($tab === 'schedule')
            @include('livewire.operations.partials.personal-calendar')
        @elseif($tab === 'today')
            <div class="rt-today rt-workday-overview-page">
                @include('livewire.operations.partials.personal-glance')
                @include('livewire.operations.partials.personal-workday-actions')
                <x-operations.workday-overview :overview="$workdayOverview" :timezone="$displayTimezone" :request-count="$requestCount" :sickness-url="route('operations.mine', ['area' => 'sickness'])" :vacation-url="route('operations.mine', ['area' => 'absences', 'new' => 1])" />
                <div class="rt-today-install rt-today-enter" style="--i:6">@include('livewire.operations.partials.pwa-install-card')</div>
            </div>
        @elseif($tab === 'time')
            @include('livewire.operations.partials.personal-time-review')
        @elseif($tab === 'records')
            <header class="rt-personal-work-heading"><div><p class="ops-muted">Dokumente, Qualifikationen und Gültigkeit im Überblick.</p></div><x-ui.buttons.button-basic mode="primary" wire:click="openForm('qualification')" wire:loading.attr="disabled">Nachweis einreichen</x-ui.buttons.button-basic></header>
            @if(class_exists(\App\Livewire\Operations\PersonnelDocuments::class) && \Illuminate\Support\Facades\Schema::hasTable('employee_document_versions'))<livewire:operations.personnel-documents />@endif
            @include('livewire.operations.partials.personal-forms')
            <x-tables.table class="rt-personal-record-list" :columns="[['label'=>'Nachweis','key'=>'record']]" :items="$qualifications" row-view="components.tables.rows.operations.personal-qualification" empty="Keine Nachweise eingereicht. Ergänze hier deine gültigen Qualifikationen." />{{ $qualifications->links() }}
        @elseif($tab === 'absences')
            <header class="rt-personal-work-heading"><div><p class="ops-muted">Deine Abwesenheiten und Anträge.</p></div><x-ui.buttons.button-basic mode="primary" wire:click="openForm('absence')" wire:loading.attr="disabled">Abwesenheit beantragen</x-ui.buttons.button-basic></header>
            @include('livewire.operations.partials.personal-forms')
            <x-tables.table class="rt-personal-record-list" label="Meine Abwesenheiten" :columns="[['label'=>'Abwesenheit','key'=>'record']]" :items="$absences" row-view="components.tables.rows.operations.personal-absence" empty="Noch keine Abwesenheiten. Beantrage hier Urlaub oder einen anderen Zeitraum." />{{ $absences->links() }}
        @endif
    </div>
    @if($tab === 'today')
        @include('livewire.operations.partials.personal-calendar-dialog')
    @endif
    <x-operations.modal wire:model.live="tasksOpen" title="Meine Aufgaben" :id="'workday-tasks-'.$this->getId()">
        @if($tasksOpen)
            <livewire:operations.attention-center :personal="true" :key="'personal-tasks-'.auth()->id()" />
        @endif
        <x-slot:footer><x-ui.buttons.button-basic wire:click="closeTasks">Schließen</x-ui.buttons.button-basic></x-slot:footer>
    </x-operations.modal>
    <x-operations.personal-request-dialog :request="$selectedRequest" :calendar="$requestCalendar" :open="$requestOpen" :notice="$requestNotice" :requested-revision="$requestRevision" :id="'personal-request-'.$this->getId()" wire:model.live="requestOpen" />
</div>
