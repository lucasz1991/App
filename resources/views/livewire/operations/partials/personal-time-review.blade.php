@php
    $periodCaption = \Carbon\CarbonImmutable::parse($timeFrom)->format('d.m.Y').' – '.\Carbon\CarbonImmutable::parse($timeUntil)->format('d.m.Y');
@endphp
<div class="rt-time-review" x-data data-personal-time-review>
    <header class="rt-time-review__heading">
        <h2>Meine Zeiten</h2>
        <details class="rt-time-review__export">
            <summary><i class="far fa-download" aria-hidden="true"></i> Export</summary>
            <div>
                <p class="rt-time-entry__meta">{{ $periodCaption }} · alle Status</p>
                <x-ui.buttons.button-basic wire:click="exportOwnTimes" wire:loading.attr="disabled" aria-label="Dienstzeiten CSV herunterladen">Eigene Dienstzeiten (CSV v1)</x-ui.buttons.button-basic>
                @if($workTimeReady)
                    <x-ui.buttons.button-basic wire:click="exportOwnWorkTimes" wire:loading.attr="disabled" aria-label="Arbeitszeiten CSV herunterladen">Alle eigenen Arbeitszeiten (CSV v2)</x-ui.buttons.button-basic>
                @endif
            </div>
        </details>
    </header>
    <div id="personal-time-clock" tabindex="-1">
        @include('livewire.operations.partials.personal-clock', ['compactActionClock' => true])
    </div>
    <section class="rt-time-review__records" id="personal-time-issues" tabindex="-1"
        x-on:personal-time-issues-opened.window="$nextTick(() => $el.focus())">
        @if($timeCompleteness && !$timeCompleteness['complete'])
            <section class="rt-time-review__missing" aria-label="Offene Zeitmeldungen">
                <header><h3>Offene Zeitmeldungen</h3><x-ui.badge size="sm">{{ count($timeCompleteness['missing_shift_times']) + count($timeCompleteness['missing_training_times'] ?? []) + count($timeCompleteness['unfinished_times']) }}</x-ui.badge></header>
                @foreach($timeCompleteness['missing_shift_times'] as $missing)
                    @php($manualDuty = $manualAssignments->firstWhere('id', $missing['assignment_id']))
                    <div class="rt-time-review__missing-row" wire:key="missing-shift-{{ $missing['assignment_id'] }}">
                        <div><strong>{{ $missing['title'] }}</strong><p>{{ \Carbon\CarbonImmutable::parse($missing['starts_at'])->setTimezone($displayTimezone)->format('d.m.Y · H:i') }} · Dienst ohne Zeitmeldung</p>
                            @unless($manualDuty)<p>Bitte den veröffentlichten Dienststand mit der Disposition prüfen.</p>@endunless
                        </div>
                        @if($manualDuty)
                            <x-ui.buttons.button-basic wire:click="openManualAssignment({{ $manualDuty->id }}, {{ $manualDuty->plan_revision }})" wire:loading.attr="disabled">Zeit nachtragen</x-ui.buttons.button-basic>
                        @endif
                    </div>
                @endforeach
                @foreach($timeCompleteness['missing_training_times'] ?? [] as $missing)
                    <div class="rt-time-review__missing-row" wire:key="missing-training-{{ $missing['training_session_id'] }}">
                        <div><strong>{{ $missing['title'] }}</strong><p>{{ \Carbon\CarbonImmutable::parse($missing['starts_at'])->setTimezone($displayTimezone)->format('d.m.Y') }} · Schulung ohne Zeitmeldung</p></div>
                        <x-ui.buttons.button-basic wire:click="openTrainingManual({{ $missing['training_session_id'] }})" wire:loading.attr="disabled">Zeit nachtragen</x-ui.buttons.button-basic>
                    </div>
                @endforeach
                @foreach($timeCompleteness['unfinished_times'] as $unfinished)
                    <div class="rt-time-review__missing-row" wire:key="unfinished-time-{{ $unfinished['id'] }}">
                        <div><strong>{{ $unfinished['title'] }}</strong>
                            <p>{{ \Carbon\CarbonImmutable::parse($unfinished['starts_at'])->setTimezone($displayTimezone)->format('d.m.Y · H:i') }} · {{ $timeFilterOptions[$unfinished['status']] }}</p>
                            @if(filled($unfinished['review_note']))<p>{{ $unfinished['review_note'] }}</p>@endif
                        </div>
                        @if($unfinished['status'] === 'returned')
                            <x-ui.buttons.button-basic wire:click="editTime({{ $unfinished['id'] }}, {{ $unfinished['revision'] }})">Korrigieren</x-ui.buttons.button-basic>
                        @elseif($unfinished['status'] === 'completed')
                            @if($unfinished['capture_id'])
                                @php($submitAction = '$dispatch(\'work-clock\', '.\Illuminate\Support\Js::from(['action' => 'submit', 'entry_capture_id' => $unfinished['capture_id'], 'revision' => $unfinished['revision'], 'timezone' => $unfinished['timezone']]).')')
                                <x-ui.buttons.button-basic :x-on:click="$submitAction" x-bind:disabled="busy || !ready || conflicts > 0">Zur Prüfung einreichen</x-ui.buttons.button-basic>
                            @else
                                <x-ui.buttons.button-basic wire:click="clock({{ $unfinished['id'] }}, {{ $unfinished['revision'] }}, 'submit')" wire:loading.attr="disabled">Zur Prüfung einreichen</x-ui.buttons.button-basic>
                            @endif
                        @else
                            <a href="#personal-time-clock" class="rt-time-review__clock-link">Zur Arbeitsuhr</a>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
        <div class="rt-time-review__filters">
            <h3>Zeitmeldungen</h3>
            <div class="rt-time-review__status" wire:key="time-status-{{ $timeFilter }}">
                <x-ui.forms.label for="personal-time-status" value="Status" class="sr-only"/>
                <x-ui.forms.select id="personal-time-status" :value="$timeFilter" action="showTimeFilter" aria-label="Zeitstatus">
                    @foreach($timeFilterOptions as $status => $label)<option value="{{ $status }}">{{ $label }}</option>@endforeach
                </x-ui.forms.select>
            </div>
            <details class="rt-time-review__period" @if($errors->has('timePeriod.*')) open @endif
                x-on:personal-time-period-applied.window="$el.open = false; $el.querySelector('summary').focus()">
                <summary><i class="far fa-calendar" aria-hidden="true"></i> {{ $periodCaption }}</summary>
                <form wire:submit="applyTimePeriod" class="rt-time-review__period-form">
                    @foreach(['from' => 'Von', 'until' => 'Bis'] as $key => $label)
                        <div>
                            <x-ui.forms.label :for="'employee-time-'.$key" :value="$label"/>
                            <x-ui.forms.date-field :id="'employee-time-'.$key" :aria-label="'Meine Zeiten '.$label" wire:model="timePeriod.{{ $key }}"
                                :aria-invalid="$errors->has('timePeriod.'.$key) ? 'true' : 'false'" :aria-describedby="$errors->has('timePeriod.'.$key) ? \Illuminate\Support\Str::slug('timePeriod.'.$key).'-error' : null"/>
                            <x-ui.forms.input-error :for="'timePeriod.'.$key"/>
                        </div>
                    @endforeach
                    <div class="ops-actions">
                        <x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Anwenden</x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic type="button" wire:click="cancelTimePeriod">Abbrechen</x-ui.buttons.button-basic>
                    </div>
                </form>
            </details>
        </div>
        <div class="rt-time-review__list" aria-label="Meine Zeitmeldungen">
            @forelse($times as $item)
                <article wire:key="personal-time-{{ $item->id }}" class="rt-time-review__item">@include('components.tables.rows.operations.personal-time')</article>
            @empty
                <div class="rt-time-review__empty"><i class="far fa-clock" aria-hidden="true"></i><h3>{{ $timeFilter === 'all' ? 'Noch keine Zeiten in diesem Zeitraum' : 'Keine passenden Zeitmeldungen' }}</h3><p>Wähle einen anderen Zeitraum oder Status.</p>
                    @if($workTimeReady)<x-ui.buttons.button-basic wire:click="openGeneralManual">Zeit nachtragen</x-ui.buttons.button-basic>@endif
                </div>
            @endforelse
        </div>
        {{ $times->links() }}
    </section>
    @if($workTimeReady)<livewire:operations.work-time-capture-review :personal="true" />@endif
    @if($manualAssignments->isNotEmpty())<x-ui.buttons.button-basic class="rt-time-review__legacy" wire:click="openForm('manual')">Andere Dienstzeit nachtragen</x-ui.buttons.button-basic>@endif
    @include('livewire.operations.partials.personal-time-forms')
    <x-operations.modal wire:model="sectionsOpen" title="Tatsächliche Arbeitsabschnitte">@if($sectionsOpen && $sectionsId)<livewire:operations.work-time-sections :entry-id="$sectionsId" :key="'time-sections-'.$sectionsId" />@endif</x-operations.modal>
</div>
