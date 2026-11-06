<div class="rt-time-review" data-personal-time-review>
    <header class="rt-time-review__heading">
        <div><h2>Meine Zeiten</h2><p class="ops-muted">Erfassen, prüfen und einreichen.</p></div>
        <details class="rt-time-review__export">
            <summary><i class="far fa-arrow-down-to-line" aria-hidden="true"></i> Export</summary>
            <div><x-ui.buttons.button-basic wire:click="exportOwnTimes" wire:loading.attr="disabled">Dienstzeiten CSV</x-ui.buttons.button-basic>@if($workTimeReady)<x-ui.buttons.button-basic wire:click="exportOwnWorkTimes" wire:loading.attr="disabled">Arbeitszeiten CSV</x-ui.buttons.button-basic>@endif</div>
        </details>
    </header>
    @include('livewire.operations.partials.personal-clock', ['compactActionClock' => true])
    <section class="rt-time-review__records">
        <div class="rt-time-review__filters">
            <x-ui.buttons.multi-toggle id="personal-time-status" label="Zeitmeldungen filtern" :show-labels="true" :value="$timeFilter" action="showTimeFilter" :options="[['value'=>'all','label'=>'Alle','icon'=>'fa-list'],['value'=>'open','label'=>'Offen','icon'=>'fa-clock'],['value'=>'submitted','label'=>'Eingereicht','icon'=>'fa-check']]" />
            <details class="rt-time-review__period"><summary><i class="far fa-calendar" aria-hidden="true"></i>@if(filled($timeFrom) && filled($timeUntil)){{ \Carbon\CarbonImmutable::parse($timeFrom)->format('d.m.') }} – {{ \Carbon\CarbonImmutable::parse($timeUntil)->format('d.m.Y') }}@else Zeitraum wählen @endif</summary>
                <div><div><x-ui.forms.label for="employee-time-from" value="Von"/><x-ui.forms.date-field id="employee-time-from" aria-label="Meine Zeiten von" wire:model.live="timeFrom"/><x-ui.forms.input-error for="timeFrom"/></div><div><x-ui.forms.label for="employee-time-until" value="Bis"/><x-ui.forms.date-field id="employee-time-until" aria-label="Meine Zeiten bis" wire:model.live="timeUntil"/><x-ui.forms.input-error for="timeUntil"/></div></div>
            </details>
        </div>
        @if($timeFilter !== 'submitted' && $timeCompleteness && !$timeCompleteness['complete'])
            <section class="rt-time-review__missing" aria-label="Offene Zeitmeldungen">
                <header><h3>Offene Zeitmeldungen</h3><span class="ops-badge">{{ count($timeCompleteness['missing_shift_times']) + count($timeCompleteness['missing_training_times'] ?? []) + count($timeCompleteness['unfinished_times']) }}</span></header>
                @foreach($timeCompleteness['missing_shift_times'] as $missing)
                    <div class="rt-time-review__missing-row" wire:key="missing-shift-{{ $missing['assignment_id'] }}"><span class="rt-time-review__dot" aria-hidden="true"></span><div><strong>{{ $missing['title'] }}</strong><p>{{ \Carbon\CarbonImmutable::parse($missing['starts_at'])->setTimezone($displayTimezone)->format('d.m. · H:i') }} · Zeitmeldung fehlt</p>@unless($manualAssignments->contains('id', $missing['assignment_id']))<p>Bitte den veröffentlichten Dienststand mit der Disposition prüfen.</p>@endunless</div>@if($manualAssignments->contains('id', $missing['assignment_id']))<x-ui.buttons.button-basic wire:click="openManualAssignment({{ $missing['assignment_id'] }})" wire:loading.attr="disabled">Nachtragen</x-ui.buttons.button-basic>@endif</div>
                @endforeach
                @foreach($timeCompleteness['missing_training_times'] ?? [] as $missing)
                    <div class="rt-time-review__missing-row" wire:key="missing-training-{{ $missing['training_session_id'] }}"><span class="rt-time-review__dot" aria-hidden="true"></span><div><strong>{{ $missing['title'] }}</strong><p>{{ \Carbon\CarbonImmutable::parse($missing['starts_at'])->setTimezone($displayTimezone)->format('d.m.Y') }} · Schulungszeit fehlt</p></div><x-ui.buttons.button-basic wire:click="openTrainingManual({{ $missing['training_session_id'] }})" wire:loading.attr="disabled">Nachtragen</x-ui.buttons.button-basic></div>
                @endforeach
                @if(!$timeCompleteness['missing_shift_times'] && !($timeCompleteness['missing_training_times'] ?? []))<p class="ops-muted">Prüfe die offenen oder laufenden Erfassungen in deiner Liste.</p>@endif
            </section>
        @endif
        <div class="rt-time-review__list" aria-label="Meine Zeitmeldungen">
            @forelse($times as $item)
                <article wire:key="personal-time-{{ $item->id }}" class="rt-time-review__item">@include('components.tables.rows.operations.personal-time')</article>
            @empty
                <div class="rt-time-review__empty"><i class="far fa-clock" aria-hidden="true"></i><h3>{{ $timeFilter === 'all' ? 'Noch keine Zeiten in diesem Zeitraum' : 'Keine passenden Zeitmeldungen' }}</h3><p>Wähle einen anderen Zeitraum oder trage eine Arbeitszeit nach.</p></div>
            @endforelse
        </div>
        {{ $times->links() }}
    </section>
    @if($workTimeReady)<livewire:operations.work-time-capture-review :personal="true" />@endif
    @if($manualAssignments->isNotEmpty())<x-ui.buttons.button-basic class="rt-time-review__legacy" wire:click="openForm('manual')">Andere Dienstzeit nachtragen</x-ui.buttons.button-basic>@endif
    @include('livewire.operations.partials.personal-time-forms')
    <x-operations.modal wire:model="sectionsOpen" title="Tatsächliche Arbeitsabschnitte">@if($sectionsOpen && $sectionsId)<livewire:operations.work-time-sections :entry-id="$sectionsId" :key="'time-sections-'.$sectionsId" />@endif</x-operations.modal>
</div>
