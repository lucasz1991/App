<section class="rt-personal-calendar" data-personal-calendar data-calendar-view="{{ $viewMode }}" aria-label="Mein Kalender">
    <header class="rt-pcal-toolbar">
        <div class="rt-pcal-period-nav" role="group" aria-label="Kalenderzeitraum">
            <x-ui.buttons.button-basic type="button" mode="link" class="rt-pcal-arrow" wire:click="previousPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Vorheriger Zeitraum"><i class="far fa-chevron-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
            <h2 class="rt-calendar-period rt-pcal-period" aria-live="polite" aria-atomic="true">{{ $periodLabel }}</h2>
            <x-ui.buttons.button-basic type="button" mode="link" class="rt-pcal-arrow" wire:click="nextPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Nächster Zeitraum"><i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="button" mode="link" class="rt-pcal-today" wire:click="today" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today">Heute</x-ui.buttons.button-basic>
        </div>
        <div class="rt-pcal-picker"><x-ui.forms.date-field id="personal-calendar-anchor-date" wire:model.live="anchorDate" :clearable="false" aria-label="Kalenderdatum" /></div>
        <x-ui.buttons.multi-toggle id="personal-calendar-view-toggle" class="rt-pcal-views" label="Kalenderansicht" :value="$viewMode" action="switchView" :show-labels="true" :options="[
            ['value'=>'day','label'=>'Tag','icon'=>'fa-calendar-day'],
            ['value'=>'week','label'=>'Woche','icon'=>'fa-calendar-week'],
            ['value'=>'month','label'=>'Monat','icon'=>'fa-calendar-days'],
            ['value'=>'list','label'=>'Liste','icon'=>'fa-list-ul'],
        ]" />
        <span class="sr-only">Zeitzone: {{ $displayTimezone }}</span>
        @error('anchorDate')<p class="rt-pcal-validation" role="alert">Bitte wähle ein gültiges Kalenderdatum.</p>@enderror
    </header>

    <div class="rt-pcal-content" wire:loading.class="is-loading" wire:target="switchView,selectCalendarDay,previousPeriod,nextPeriod,today,anchorDate">
        @if($viewMode === 'list')
            <x-operations.personal-calendar-agenda :days="$calendarLayout['days']->filter(fn ($day) => count($day['segments']) > 0)" id-prefix="personal-list" />
        @else
            <div class="rt-pcal-layout">
                <div class="rt-pcal-main">
                    @if($viewMode === 'month')
                        <div class="rt-pcal-month" role="group" aria-label="Monatskalender · Balken von 0 bis 24 Uhr">
                            @foreach(['Mo','Di','Mi','Do','Fr','Sa','So'] as $weekday)<div class="rt-pcal-weekday" aria-hidden="true">{{ $weekday }}</div>@endforeach
                            @foreach($calendarLayout['days'] as $day)
                                <button type="button" @class(['rt-pcal-month-day', 'is-outside'=>!$day['in_month'], 'is-today'=>$day['is_today'], 'is-selected'=>$day['selected'], 'is-weekend'=>$day['date']->isWeekend()])
                                    wire:key="personal-month-{{ $day['date']->toDateString() }}" wire:click="selectCalendarDay('{{ $day['date']->toDateString() }}')"
                                    data-calendar-day="{{ $day['date']->toDateString() }}" aria-pressed="{{ $day['selected'] ? 'true' : 'false' }}" aria-current="{{ $day['is_today'] ? 'date' : 'false' }}"
                                    aria-label="{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM YYYY') }} · {{ $day['summary'] }}">
                                    <span class="rt-pcal-day-number">{{ $day['date']->format('j') }}</span>
                                    <span class="rt-pcal-month-tracks" aria-hidden="true">
                                        @foreach(array_slice($day['segments'], 0, 4) as $segment)
                                            <span class="rt-pcal-month-track" data-event-tone="{{ $segment['tone'] }}" title="{{ $segment['accessible_label'] }}">
                                                @foreach($segment['time_segments'] as $part)
                                                    <span @class(['rt-pcal-mini-bar', 'continues-before'=>$segment['continues_before'], 'continues-after'=>$segment['continues_after']]) style="left:{{ $part['left'] }}%;width:{{ $part['width'] }}%"></span>
                                                @endforeach
                                            </span>
                                        @endforeach
                                    </span>
                                    @if(count($day['segments']) > 4)<span class="rt-pcal-more">+{{ count($day['segments']) - 4 }}<span class="sr-only"> weitere Termine</span></span>@endif
                                </button>
                            @endforeach
                        </div>
                    @else
                        <x-operations.personal-calendar-grid :days="$calendarLayout['days']" :axis="$calendarLayout['axis']" :view="$viewMode" />
                    @endif
                </div>
                <aside class="rt-pcal-day-detail" aria-label="Termine am ausgewählten Tag">
                    <x-operations.personal-calendar-agenda :days="collect([$calendarLayout['selected_day']])->filter()" id-prefix="personal-selected" />
                </aside>
            </div>
        @endif
    </div>
    @include('livewire.operations.partials.personal-calendar-dialog')
</section>
