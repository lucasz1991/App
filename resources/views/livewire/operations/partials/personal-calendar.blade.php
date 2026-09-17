<section class="rt-calendar space-y-4" data-personal-calendar data-calendar-view="{{ $viewMode }}" aria-label="Mein Kalender">
    <header class="rt-calendar-header">
        <div class="rt-calendar-heading">
            <div class="min-w-0">
                <h2 class="rt-calendar-period" aria-live="polite" aria-atomic="true">{{ $periodLabel }}</h2>
                <p class="rt-calendar-timezone">{{ $displayTimezone }}</p>
            </div>
            <div class="rt-calendar-summary" aria-label="Meine Termine im Zeitraum">
                @php($personalShiftCount = $calendarEvents->where('kind', 'shift')->count())
                @php($personalAbsenceCount = $calendarEvents->where('kind', 'absence')->count())
                <span><i class="far fa-train" aria-hidden="true"></i><strong>{{ $personalShiftCount }}</strong> {{ $personalShiftCount === 1 ? 'Dienst' : 'Dienste' }}</span>
                <span><i class="far fa-calendar-minus" aria-hidden="true"></i><strong>{{ $personalAbsenceCount }}</strong> {{ $personalAbsenceCount === 1 ? 'Abwesenheit' : 'Abwesenheiten' }}</span>
            </div>
        </div>
        <div class="rt-calendar-controls">
            <div class="rt-calendar-navigation" role="group" aria-label="Kalenderzeitraum">
                <x-ui.buttons.button-basic type="button" class="rt-calendar-nav-arrow" wire:click="previousPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Vorheriger Zeitraum" title="Vorheriger Zeitraum"><i class="far fa-chevron-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" class="rt-calendar-nav-today" wire:click="today" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today">Heute</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" class="rt-calendar-nav-arrow" wire:click="nextPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Nächster Zeitraum" title="Nächster Zeitraum"><i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
            </div>
            <div class="rt-calendar-picker"><x-ui.forms.date-field id="personal-calendar-anchor-date" wire:model.live="anchorDate" :clearable="false" aria-label="Kalenderdatum" /></div>
            <x-ui.buttons.multi-toggle id="personal-calendar-view-toggle" class="rt-calendar-view-toggle" label="Kalenderansicht" :value="$viewMode" action="switchView" :options="[
                ['value'=>'day','label'=>'Tag','icon'=>'fa-calendar-day'],
                ['value'=>'week','label'=>'Woche','icon'=>'fa-calendar-week'],
                ['value'=>'month','label'=>'Monat','icon'=>'fa-calendar-days'],
                ['value'=>'list','label'=>'Liste','icon'=>'fa-list-ul'],
            ]" />
        </div>
    </header>

    @if($viewMode === 'list')
        @php($personalCalendarRows = $calendarEvents->values()->map(fn ($event, $index) => (object) array_merge(get_object_vars($event), ['id' => $index + 1, 'eventId' => $event->id])))
        <x-tables.table :columns="[['label'=>'Mein Termin','key'=>'title','width'=>'2fr'],['label'=>'Zeitraum','key'=>'starts','width'=>'1.5fr'],['label'=>'Status','key'=>'status']]" :items="$personalCalendarRows" row-view="components.tables.rows.operations.personal-calendar" empty="Keine Termine in diesem Zeitraum." />
    @elseif($viewMode === 'month')
        <div class="rt-calendar-month" aria-label="Monatskalender">
            @foreach(['Mo','Di','Mi','Do','Fr','Sa','So'] as $weekday)<div class="rt-calendar-weekday">{{ $weekday }}</div>@endforeach
            @foreach($calendarDays as $day)
                <section @class(['rt-calendar-month-day', 'is-outside'=>!$day['in_month'], 'is-today'=>$day['is_today'], 'is-weekend'=>$day['date']->isWeekend()]) wire:key="personal-month-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                    <x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-date" wire:click="showDay('{{ $day['date']->toDateString() }}')" aria-current="{{ $day['is_today'] ? 'date' : 'false' }}" aria-label="{{ $day['date']->format('d.m.Y') }} öffnen · {{ $day['events']->count() }} {{ $day['events']->count() === 1 ? 'Termin' : 'Termine' }}">{{ $day['date']->format('j') }}</x-ui.buttons.button-basic>
                    <div class="hidden space-y-1 lg:block">
                        @foreach($day['events']->take(3) as $event)
                            <x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-month-shift" wire:click="openCalendarEvent('{{ $event->id }}')" data-personal-event="{{ $event->id }}">
                                <span class="block text-xs tabular-nums"><i class="far {{ $event->kind === 'shift' ? 'fa-train' : 'fa-calendar-minus' }}" aria-hidden="true"></i> {{ $event->starts->format('H:i') }}</span><span class="block break-words text-xs">{{ $event->title }}</span>
                                <span class="rt-personal-month-status">{{ \App\Support\Operations\OperationsNavigation::status($event->status) }}</span>
                            </x-ui.buttons.button-basic>
                        @endforeach
                        @if($day['events']->count() > 3)<x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-more" wire:click="showDay('{{ $day['date']->toDateString() }}')">+{{ $day['events']->count()-3 }} weitere</x-ui.buttons.button-basic>@endif
                    </div>
                    @if($day['events']->isNotEmpty())<span class="rt-calendar-day-count lg:hidden">{{ $day['events']->count() }} <span class="sr-only">Termine</span></span>@endif
                </section>
            @endforeach
        </div>
    @else
        @if($viewMode === 'week')
            <div class="rt-calendar-week" data-calendar-desktop-grid>
                @foreach($calendarDays as $day)
                    <section @class(['rt-calendar-week-day', 'is-today'=>$day['is_today'], 'is-weekend'=>$day['date']->isWeekend()]) wire:key="personal-week-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                        <x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-week-date" wire:click="showDay('{{ $day['date']->toDateString() }}')" aria-current="{{ $day['is_today'] ? 'date' : 'false' }}" aria-label="{{ $day['date']->format('d.m.Y') }} öffnen">
                            <span>{{ $day['date']->locale('de')->isoFormat('dd') }}</span><strong>{{ $day['date']->format('d') }}</strong><span class="rt-calendar-week-count">{{ $day['events']->count() }}<span class="sr-only"> Termine</span></span>
                        </x-ui.buttons.button-basic>
                        <div class="rt-calendar-week-shifts">@forelse($day['events'] as $event)<x-operations.personal-calendar-event :event="$event" :compact="true" />@empty<p class="rt-calendar-empty-cell">—<span class="sr-only">Keine Termine</span></p>@endforelse</div>
                    </section>
                @endforeach
            </div>
        @endif
        <div @class(['rt-calendar-agenda', 'xl:hidden'=>$viewMode === 'week']) data-calendar-mobile-agenda>
            @foreach($calendarDays as $day)
                <section @class(['rt-calendar-agenda-day', 'is-today'=>$day['is_today'], 'is-empty'=>$day['events']->isEmpty()]) wire:key="personal-agenda-{{ $day['date']->toDateString() }}">
                    <h3 class="rt-calendar-agenda-heading">
                        <span class="rt-calendar-agenda-date" aria-hidden="true"><span>{{ $day['date']->locale('de')->isoFormat('dd') }}</span><strong>{{ $day['date']->format('d') }}</strong></span>
                        <span class="rt-calendar-agenda-label">{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }}@if($day['is_today'])<span class="rt-calendar-today-label">Heute</span>@endif</span>
                        <span class="rt-calendar-agenda-count">{{ $day['events']->isEmpty() ? 'Keine Termine' : $day['events']->count().' '.($day['events']->count() === 1 ? 'Termin' : 'Termine') }}</span>
                    </h3>
                    @if($day['events']->isNotEmpty())<div class="rt-calendar-agenda-shifts">@foreach($day['events'] as $event)<x-operations.personal-calendar-event :event="$event" />@endforeach</div>@endif
                </section>
            @endforeach
        </div>
    @endif

    <x-operations.modal wire:model="calendarEventOpen" :title="$selectedCalendarEvent?->title ?? 'Mein Termin'">
        @if($selectedCalendarEvent)
            @if($selectedCalendarEvent->kind === 'shift')
                @include('components.tables.rows.operations.personal-shift', ['item'=>$selectedCalendarEvent->record])
                @if(count($selectedPlanChanges))
                    <h3 class="text-sm font-semibold">Veröffentlichte Änderungen · Revision {{ $selectedCalendarEvent->record->plan_revision }}</h3>
                    <x-tables.table :columns="[['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Aktuell','key'=>'after']]" :items="collect($selectedPlanChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key]))" row-view="components.tables.rows.operations.plan-change" />
                @endif
            @else
                <div class="ops-toolbar"><h3>{{ $selectedCalendarEvent->title }}</h3><x-operations.status :value="$selectedCalendarEvent->status" /></div>
                <dl class="rt-personal-calendar-details">
                    <div><dt>Beginn</dt><dd>{{ $selectedCalendarEvent->starts->format('d.m.Y H:i') }}</dd></div>
                    <div><dt>Ende</dt><dd>{{ $selectedCalendarEvent->ends->format('d.m.Y H:i') }}</dd></div>
                    <div><dt>Zeitzone</dt><dd>{{ $displayTimezone }}</dd></div>
                    @if($selectedCalendarEvent->record->note)<div><dt>Notiz</dt><dd>{{ $selectedCalendarEvent->record->note }}</dd></div>@endif
                    @if($selectedCalendarEvent->record->review_note)<div><dt>Rückmeldung</dt><dd>{{ $selectedCalendarEvent->record->review_note }}</dd></div>@endif
                </dl>
                @if($selectedCalendarEvent->status === 'pending')<div><x-ui.buttons.button-basic wire:click="withdraw({{ $selectedCalendarEvent->record->id }},{{ $selectedCalendarEvent->record->revision }})" wire:loading.attr="disabled">Antrag zurückziehen</x-ui.buttons.button-basic></div>@endif
            @endif
        @endif
        <x-slot:footer><x-ui.buttons.button-basic wire:click="closeCalendarEvent">Schließen</x-ui.buttons.button-basic></x-slot:footer>
    </x-operations.modal>
</section>
