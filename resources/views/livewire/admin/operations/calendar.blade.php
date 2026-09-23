<div class="rt-calendar rt-disposition rt-disposition--calendar space-y-4" data-operations-calendar data-calendar-view="{{ $viewMode }}" data-calendar-week="{{ $weekStartDate->toDateString() }}">
    <header class="rt-calendar-header">
        <div class="rt-calendar-heading">
            <div class="min-w-0">
                <h2 class="rt-calendar-period" aria-live="polite" aria-atomic="true">{{ $periodLabel }}</h2>
                <p class="rt-calendar-timezone">{{ $displayTimezone }}@if(in_array($viewMode, ['week', 'list'], true)) · KW {{ $weekStartDate->isoWeek() }}@endif</p>
            </div>
            <div class="rt-calendar-summary" aria-label="Besetzungsübersicht">
                <span><i class="far fa-calendar-check" aria-hidden="true"></i><strong>{{ $shiftCount }}</strong> {{ $shiftCount === 1 ? 'Schicht' : 'Schichten' }}</span>
                <span><i class="far fa-users" aria-hidden="true"></i><strong>{{ $reservedCount }}/{{ $requiredCount }}</strong> eingeplant</span>
                <span @class(['rt-calendar-open', 'has-open' => $openCount > 0])><span class="rt-calendar-summary-dot" aria-hidden="true"></span><strong>{{ $openCount }}</strong> offen</span>
            </div>
        </div>
        <div class="rt-calendar-controls">
            <div class="rt-calendar-navigation" role="group" aria-label="Kalenderzeitraum">
                <x-ui.buttons.button-basic type="button" class="rt-calendar-nav-arrow" wire:click="previousPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Vorheriger Zeitraum" title="Vorheriger Zeitraum"><i class="far fa-chevron-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" class="rt-calendar-nav-today" wire:click="today" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today">Heute</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" class="rt-calendar-nav-arrow" wire:click="nextPeriod" wire:loading.attr="disabled" wire:target="previousPeriod,nextPeriod,today" aria-label="Nächster Zeitraum" title="Nächster Zeitraum"><i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
            </div>
            <div class="rt-calendar-picker">
                <x-ui.forms.date-field id="calendar-anchor-date" wire:model.live="anchorDate" :clearable="false" aria-label="Kalenderdatum" />
            </div>
            <x-ui.buttons.multi-toggle
                id="calendar-view-toggle"
                class="rt-calendar-view-toggle"
                label="Kalenderansicht"
                :value="$viewMode"
                action="switchView"
                :options="[
                    ['value' => 'day', 'label' => 'Tag', 'icon' => 'fa-calendar-day'],
                    ['value' => 'week', 'label' => 'Woche', 'icon' => 'fa-calendar-week'],
                    ['value' => 'month', 'label' => 'Monat', 'icon' => 'fa-calendar-days'],
                    ['value' => 'list', 'label' => 'Liste', 'icon' => 'fa-list-ul'],
                ]"
            />
        </div>
    </header>
    <x-operations.feedback />
    <x-tables.toolbar id="calendar-filters" class="rt-disposition-toolbar" title="Kalenderfilter" :search-in-header="true" reset-action="resetFilters" :filter-count="(int) ($customerFilter !== 'all') + (int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'active') + (int) $onlyOpen + (int) filled($search)">
        <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="Schicht, Kunde oder Ort" /></x-slot:search>
        <x-tables.filter-field label="Kunde" for="calendar-customer">
            <x-ui.forms.select id="calendar-customer" wire:model.live="customerFilter" aria-label="Kalenderkunde"><option value="all">Alle Kunden</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->company_name }}</option>@endforeach</x-ui.forms.select>
        </x-tables.filter-field>
        <x-tables.filter-field label="Leistung / Auftrag" for="calendar-order">
            <x-ui.forms.select id="calendar-order" wire:model.live="orderFilter" aria-label="Kalenderauftrag"><option value="all">Alle Leistungen</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach</x-ui.forms.select>
        </x-tables.filter-field>
        <x-tables.filter-field label="Status" for="calendar-status">
            <x-ui.forms.select id="calendar-status" wire:model.live="statusFilter" aria-label="Kalenderstatus"><option value="active">Ohne stornierte</option><option value="all">Alle Status</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select>
        </x-tables.filter-field>
        <div class="flex min-h-11 items-center"><x-ui.forms.checkbox wire:model.live="onlyOpen" label="Nur offene Besetzung" /></div>
    </x-tables.toolbar>

    @if($viewMode === 'list')
        <div class="rt-disposition-table">
            <x-tables.table :columns="[['label'=>'Schicht / Kunde','key'=>'title','width'=>'2fr'],['label'=>'Zeitraum','key'=>'starts_at','width'=>'1.5fr'],['label'=>'Besetzung','key'=>'required_staff'],['label'=>'Status','key'=>'status']]" :items="$shifts" detail-action="openShift" row-view="components.tables.rows.operations.calendar" empty="Keine Schichten in diesem Zeitraum." />
        </div>
    @elseif($viewMode === 'month')
        <div class="rt-calendar-month" aria-label="Monatskalender">
            @foreach(['Mo','Di','Mi','Do','Fr','Sa','So'] as $weekday)<div class="rt-calendar-weekday">{{ $weekday }}</div>@endforeach
            @foreach($days as $day)
                <section @class(['rt-calendar-month-day', 'is-outside' => !$day['in_month'], 'is-today' => $day['is_today'], 'is-weekend' => $day['date']->isWeekend()]) wire:key="month-day-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                    <x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-date" wire:click="showDay('{{ $day['date']->toDateString() }}')" aria-current="{{ $day['is_today'] ? 'date' : 'false' }}" aria-label="{{ $day['date']->format('d.m.Y') }} öffnen">{{ $day['date']->format('j') }}</x-ui.buttons.button-basic>
                    <div class="hidden space-y-1 lg:block">
                        @foreach($day['shifts']->take(3) as $shift)
                            <x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-month-shift" wire:click="openShift({{ $shift->id }})" data-calendar-shift="{{ $shift->id }}" data-calendar-staffing="{{ $shift->calendar_open > 0 ? 'open' : 'staffed' }}">
                                <span class="block text-xs tabular-nums">{{ $shift->calendar_starts->format('H:i') }} · {{ $shift->calendar_reserved }}/{{ $shift->required_staff }}</span><span class="block break-words text-xs">{{ $shift->title }}</span>
                            </x-ui.buttons.button-basic>
                        @endforeach
                        @if($day['shifts']->count() > 3)<x-ui.buttons.button-basic mode="link" type="button" class="rt-calendar-more" wire:click="showDay('{{ $day['date']->toDateString() }}')">+{{ $day['shifts']->count()-3 }} weitere</x-ui.buttons.button-basic>@endif
                    </div>
                    @if($day['shifts']->isNotEmpty())<span class="rt-calendar-day-count lg:hidden">{{ $day['shifts']->count() }} <span class="sr-only">{{ $day['shifts']->count() === 1 ? 'Schicht' : 'Schichten' }}</span></span>@endif
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
