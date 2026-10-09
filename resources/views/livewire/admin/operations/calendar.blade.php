<div class="rt-calendar rt-disposition rt-disposition--calendar" data-operations-calendar data-calendar-view="{{ $viewMode }}" data-calendar-week="{{ $weekStartDate->toDateString() }}" x-data="{}">
    <template x-teleport="[data-topbar-page-search]" wire:key="calendar-topbar-search-{{ $this->getId() }}">
        <x-tables.search-field context="page-topbar" wire:model.live.debounce.300ms="search" :results-count="$shifts->count()" placeholder="Schicht, Kunde oder Ort" aria-label="Kalenderschichten suchen" />
    </template>
    @php
        $calendarViews = [
            'month' => ['Monat', 'fa-calendar-alt', 'Der ganze Monat mit offenen Plätzen'],
            'week' => ['Woche', 'fa-calendar-week', 'Sieben Tage mit allen Schichten'],
            'day' => ['Tag', 'fa-calendar-day', 'Ein Tag im Detail'],
            'list' => ['Liste', 'fa-list-ul', 'Sortierbare Tabelle des Zeitraums'],
        ];
        [$currentViewLabel, $currentViewIcon] = $calendarViews[$viewMode];
        $periodHint = match ($viewMode) {
            'week', 'list' => 'KW '.$weekStartDate->isoWeek(),
            'month' => $shiftCount.' '.($shiftCount === 1 ? 'Schicht' : 'Schichten'),
            default => $shiftCount.' '.($shiftCount === 1 ? 'Schicht' : 'Schichten'),
        };
    @endphp
    {{-- Eine Steuerzeile im Seitenkopf: Zeitraum, Ansicht, Filter und offene Plätze als schlanke Auswahlfelder. --}}
    <template x-teleport="[data-page-header-search]" wire:key="calendar-header-controls-{{ $this->getId() }}">
        <div class="rt-shift-plan-header-controls rt-calendar-header-controls" data-calendar-header-controls>
            <div class="rt-calendar-period-group" role="group" aria-label="Kalenderzeitraum">
                <button type="button" class="rt-calendar-step" wire:click="previousPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Vorheriger Zeitraum" title="Vorheriger Zeitraum"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
                <x-ui.dropdown.anchor-dropdown align="left" width="auto" offset="6" dropdown-id="calendar-period-{{ $this->getId() }}" layer-group="operations-calendar" content-role="dialog" content-label="Datum wählen" dropdown-classes="rt-calendar-period-dropdown" content-classes="p-3 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                    <x-slot:trigger>
                        <button type="button" class="rt-calendar-period-trigger" aria-label="Datum wählen: {{ $periodLabel }}" title="Zu einem Datum springen">
                            <span><strong class="rt-calendar-period" aria-live="polite" aria-atomic="true">{{ $periodLabel }}</strong><small>{{ $periodHint }} · {{ $displayTimezone }}</small></span>
                            <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
                        </button>
                    </x-slot:trigger>
                    <x-slot:content>
                        <div class="rt-calendar-jump">
                            <label class="rt-calendar-jump__label" for="calendar-anchor-date">Springe zu Datum</label>
                            <x-ui.forms.date-field id="calendar-anchor-date" wire:model.live="anchorDate" :clearable="false" :keep-dropdown-open="true" aria-label="Kalenderdatum" />
                            @error('anchorDate')<p class="rt-calendar-jump__error" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
                <button type="button" class="rt-calendar-step" wire:click="nextPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Nächster Zeitraum" title="Nächster Zeitraum"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
            </div>
            <button type="button" class="rt-calendar-today" wire:click="today" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today">Heute</button>

            <x-ui.dropdown.anchor-dropdown align="left" width="72" offset="6" dropdown-id="calendar-view-{{ $this->getId() }}" layer-group="operations-calendar" content-label="Kalenderansicht auswählen" content-classes="rt-shift-plan-view-menu p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                <x-slot:trigger>
                    <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-calendar-view-trigger" aria-label="Kalenderansicht ändern: {{ $currentViewLabel }}" title="Ansicht: {{ $currentViewLabel }}">
                        <i class="far {{ $currentViewIcon }}" aria-hidden="true"></i>
                        <span class="rt-shift-plan-control__label"><strong>{{ $currentViewLabel }}</strong></span>
                        <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
                    </x-ui.buttons.button-basic>
                </x-slot:trigger>
                <x-slot:content>
                    <p class="rt-shift-plan-menu-heading" role="presentation">Kalender anzeigen als</p>
                    @foreach($calendarViews as $view => [$label, $icon, $description])
                        <button type="button" role="menuitemradio" aria-checked="{{ $viewMode === $view ? 'true' : 'false' }}" wire:click="switchView('{{ $view }}')" x-on:click="close()" class="rt-shift-plan-view-option" data-calendar-view-option="{{ $view }}">
                            <i class="far {{ $icon }}" aria-hidden="true"></i><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span>@if($viewMode === $view)<i class="far fa-check rt-shift-plan-view-option__check" aria-hidden="true"></i>@endif
                        </button>
                    @endforeach
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>

            <x-ui.dropdown.anchor-dropdown align="left" width="80" offset="6" dropdown-id="calendar-filter-{{ $this->getId() }}" layer-group="operations-calendar" content-role="dialog" content-label="Kalender filtern" content-classes="p-3 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                <x-slot:trigger>
                    <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-calendar-filter-trigger" aria-label="Filter{{ $filterCount ? ': '.$filterCount.' aktiv' : '' }}" title="Kunde, Leistung und Status filtern">
                        <i class="far fa-filter" aria-hidden="true"></i>
                        <span class="rt-shift-plan-control__label"><strong>Filter</strong></span>
                        @if($filterCount > 0)<span class="rt-calendar-filter-count" aria-hidden="true">{{ $filterCount }}</span>@endif
                        <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
                    </x-ui.buttons.button-basic>
                </x-slot:trigger>
                <x-slot:content>
                    <div class="rt-calendar-filters" id="calendar-filters">
                        <label class="rt-calendar-filters__field" for="calendar-customer"><span>Kunde</span>
                            <x-ui.forms.select id="calendar-customer" wire:model.live="customerFilter" aria-label="Kalenderkunde"><option value="all">Alle Kunden</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->company_name }}</option>@endforeach</x-ui.forms.select>
                        </label>
                        <label class="rt-calendar-filters__field" for="calendar-order"><span>Leistung / Auftrag</span>
                            <x-ui.forms.select id="calendar-order" wire:model.live="orderFilter" aria-label="Kalenderauftrag"><option value="all">Alle Leistungen</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach</x-ui.forms.select>
                        </label>
                        <label class="rt-calendar-filters__field" for="calendar-status"><span>Status</span>
                            <x-ui.forms.select id="calendar-status" wire:model.live="statusFilter" aria-label="Kalenderstatus"><option value="active">Ohne stornierte</option><option value="all">Alle Status</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select>
                        </label>
                        <div class="rt-calendar-filters__toggle"><x-ui.forms.checkbox wire:model.live="onlyOpen" label="Nur offene Besetzung" /></div>
                        <div class="rt-calendar-filters__foot">
                            <button type="button" class="rt-calendar-filters__reset" wire:click="resetFilters" x-on:click="close()" @disabled($filterCount === 0 && blank($search))><i class="far fa-undo" aria-hidden="true"></i>Zurücksetzen</button>
                        </div>
                    </div>
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>

            {{-- Offene Plätze: Zahl und Schnellfilter in einem --}}
            <button type="button" class="rt-calendar-open-toggle" wire:click="$toggle('onlyOpen')" aria-pressed="{{ $onlyOpen ? 'true' : 'false' }}" data-has-open="{{ $openCount > 0 ? 'true' : 'false' }}"
                aria-label="{{ $openCount }} offene Plätze · {{ $onlyOpen ? 'alle Schichten zeigen' : 'nur offene zeigen' }}" title="{{ $reservedCount }}/{{ $requiredCount }} Plätze eingeplant">
                <span class="rt-calendar-open-toggle__dot" aria-hidden="true"></span><strong>{{ $openCount }}</strong> offen
            </button>
        </div>
    </template>
    <x-operations.feedback />

    @if($viewMode === 'list')
        <div class="rt-disposition-table">
            <x-tables.table :columns="[['label'=>'Schicht / Kunde','key'=>'title','width'=>'2fr'],['label'=>'Zeitraum','key'=>'starts_at','width'=>'1.5fr'],['label'=>'Besetzung','key'=>'required_staff'],['label'=>'Status','key'=>'status']]" :items="$shifts" detail-action="openShift" row-view="components.tables.rows.operations.calendar" empty="Keine Schichten in diesem Zeitraum." />
        </div>
    @elseif($viewMode === 'month')
        @php
            $statePriority = ['open' => 0, 'pending' => 1, 'staffed' => 2, 'closed' => 3];
        @endphp
        <div class="rt-calendar-month" aria-label="Monatskalender {{ $periodLabel }}" style="--calendar-weeks: {{ (int) ceil($days->count() / 7) }}">
            @foreach(['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'] as $weekday)
                <div @class(['rt-calendar-weekday', 'is-weekend' => $loop->index > 4])><span class="rt-calendar-weekday__long">{{ $weekday }}</span><span class="rt-calendar-weekday__short" aria-hidden="true">{{ mb_substr($weekday, 0, 2) }}</span></div>
            @endforeach
            @foreach($days as $day)
                @php
                    // Monat: je Tag nur Schichten, die an diesem Tag beginnen; Lücken zuerst, dann nach Beginn.
                    $dayShifts = $day['shifts']->filter(fn ($shift) => $shift->calendar_starts->isSameDay($day['date']))
                        ->sortBy(fn ($shift) => [$statePriority[$shift->calendar_state] ?? 9, $shift->calendar_starts->getTimestamp()])->values();
                    $dayOpen = (int) $dayShifts->sum('calendar_open');
                @endphp
                <section @class(['rt-calendar-month-day', 'is-outside' => !$day['in_month'], 'is-today' => $day['is_today'], 'is-weekend' => $day['date']->isWeekend(), 'has-open' => $dayOpen > 0]) wire:key="month-day-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                    <div class="rt-calendar-month-day__head">
                        <button type="button" class="rt-calendar-month-date" wire:click="showDay('{{ $day['date']->toDateString() }}')" aria-current="{{ $day['is_today'] ? 'date' : 'false' }}" aria-label="{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }} öffnen">{{ $day['date']->format('j') }}</button>
                        @if($dayOpen > 0)
                            <span class="rt-calendar-day-open" title="{{ $dayOpen }} {{ $dayOpen === 1 ? 'Platz' : 'Plätze' }} offen">{{ $dayOpen }} offen</span>
                        @elseif($dayShifts->isNotEmpty())
                            <span class="rt-calendar-day-total">{{ $dayShifts->count() }}</span>
                        @endif
                    </div>
                    <div class="rt-calendar-month-day__shifts">
                        @foreach($dayShifts->take(3) as $shift)
                            <button type="button" class="rt-calendar-chip" wire:click="openShift({{ $shift->id }})" data-calendar-shift="{{ $shift->id }}" data-calendar-state="{{ $shift->calendar_state }}" data-calendar-staffing="{{ $shift->calendar_open > 0 ? 'open' : 'staffed' }}" @if((int) $shift->published_revision === 0) data-calendar-draft="true" @endif
                                title="{{ $shift->calendar_starts->format('H:i') }}–{{ $shift->calendar_ends->format('H:i') }} · {{ $shift->title }} · {{ $shift->calendar_reserved }}/{{ $shift->required_staff }} eingeplant">
                                <span class="rt-calendar-chip__dot" aria-hidden="true"></span>
                                <span class="rt-calendar-chip__time">{{ $shift->calendar_starts->format('H:i') }}</span>
                                <span class="rt-calendar-chip__title">{{ $shift->title }}</span>
                                <span class="rt-calendar-chip__staff">@if($shift->calendar_state === 'open'){{ $shift->calendar_open }} offen @elseif($shift->calendar_state === 'pending'){{ $shift->calendar_requested }} angefr. @else{{ $shift->calendar_reserved }}/{{ $shift->required_staff }}@endif</span>
                            </button>
                        @endforeach
                        @if($dayShifts->count() > 3)
                            <button type="button" class="rt-calendar-month-more" wire:click="showDay('{{ $day['date']->toDateString() }}')">+{{ $dayShifts->count() - 3 }} weitere</button>
                        @endif
                    </div>
                    @if($dayShifts->isNotEmpty())
                        <span class="rt-calendar-day-dots" aria-hidden="true">
                            @foreach($dayShifts->take(4) as $shift)<i data-calendar-state="{{ $shift->calendar_state }}"></i>@endforeach
                        </span>
                    @endif
                </section>
            @endforeach
        </div>
    @else
        @if($viewMode === 'week')
            <div class="rt-calendar-week" data-calendar-desktop-grid aria-label="Wochenkalender">
                @foreach($days as $day)
                    <section @class(['rt-calendar-week-day', 'is-today'=>$day['is_today'], 'is-weekend' => $day['date']->isWeekend()]) wire:key="week-day-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                        <x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-week-date" wire:click="showDay('{{ $day['date']->toDateString() }}')" aria-current="{{ $day['is_today'] ? 'date' : 'false' }}" aria-label="{{ $day['date']->format('d.m.Y') }} öffnen">
                            <span>{{ $day['date']->locale('de')->isoFormat('dd') }}</span><strong>{{ $day['date']->format('d') }}</strong>
                            <span class="rt-calendar-week-count">@if($day['is_today'])Heute · @endif{{ $day['shifts']->count() }} {{ $day['shifts']->count() === 1 ? 'Schicht' : 'Schichten' }}</span>
                        </x-ui.buttons.button-basic>
                        <div class="rt-calendar-week-shifts">
                            @forelse($day['shifts'] as $shift)<x-operations.calendar-shift :shift="$shift" :compact="true" />@empty<p class="rt-calendar-empty-cell">Keine Schichten</p>@endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
        <div @class(['rt-calendar-agenda', 'xl:hidden'=>$viewMode === 'week']) data-calendar-mobile-agenda>
            @foreach($days as $day)
                <section @class(['rt-calendar-agenda-day', 'is-today' => $day['is_today'], 'is-empty' => $day['shifts']->isEmpty()]) wire:key="agenda-day-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                    <h3 class="rt-calendar-agenda-heading">
                        <span class="rt-calendar-agenda-date" aria-hidden="true"><span>{{ $day['date']->locale('de')->isoFormat('dd') }}</span><strong>{{ $day['date']->format('d') }}</strong></span>
                        <span class="rt-calendar-agenda-label">{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }}@if($day['is_today'])<span class="rt-calendar-today-label">Heute</span>@endif</span>
                        <span class="rt-calendar-agenda-count">{{ $day['shifts']->isEmpty() ? 'Keine Schichten' : $day['shifts']->count().' '.($day['shifts']->count() === 1 ? 'Schicht' : 'Schichten') }}</span>
                    </h3>
                    @if($day['shifts']->isNotEmpty())
                        <div class="rt-calendar-agenda-shifts">
                            @foreach($day['shifts'] as $shift)<x-operations.calendar-shift :shift="$shift" />@endforeach
                        </div>
                    @else
                        <p class="rt-calendar-agenda-empty" aria-hidden="true">Keine Schichten</p>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
