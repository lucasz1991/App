<div class="rt-ops ops-stack rt-personal-work" data-personal-workspace data-personal-work-tab="{{ $tab }}" wire:poll.visible.60s
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
            @include('livewire.operations.my-work.today')
        @elseif($tab === 'time')
            @include('livewire.operations.partials.personal-time-review')
        @elseif($tab === 'records')
            @include('livewire.operations.my-work.records-heading')
            {{-- Kindkomponenten bleiben in dieser Datei: ihr Livewire-Schlüssel hängt an Datei und Reihenfolge. --}}
            @if($showPersonnelDocuments)<livewire:operations.personnel-documents />@endif
            @include('livewire.operations.my-work.records')
        @elseif($tab === 'absences')
            @include('livewire.operations.my-work.absences')
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
