<div class="space-y-4" data-operations-calendar data-calendar-view="{{ $viewMode }}" data-calendar-week="{{ $weekStartDate->toDateString() }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-rt-text dark:text-rt-dark-text">{{ $periodLabel }}</h2>
            <p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $displayTimezone }} · {{ $shiftCount }} Schichten · {{ $reservedCount }}/{{ $requiredCount }} besetzt · {{ $openCount }} offen</p>
        </div>
        <div class="flex flex-wrap gap-1" role="group" aria-label="Kalenderansicht">
            @foreach(['day'=>'Tag','week'=>'Woche','month'=>'Monat','list'=>'Liste'] as $key=>$label)
                <x-ui.buttons.button-basic :mode="$viewMode === $key ? 'primary' : 'basic'" wire:click="switchView('{{ $key }}')" aria-pressed="{{ $viewMode === $key ? 'true' : 'false' }}">{{ $label }}</x-ui.buttons.button-basic>
            @endforeach
        </div>
    </div>
    <div class="flex flex-wrap items-end gap-2">
        <x-ui.buttons.button-basic wire:click="previousPeriod" aria-label="Vorheriger Zeitraum"><i class="far fa-chevron-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="today">Heute</x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="nextPeriod" aria-label="Nächster Zeitraum"><i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
        <div class="min-w-0 flex-1 sm:max-w-56">
            <x-ui.forms.input type="date" wire:model.live="anchorDate" aria-label="Kalenderdatum" />
        </div>
    </div>
    <x-operations.feedback />
    <x-tables.toolbar id="calendar-filters" title="Kalenderfilter">
        <x-slot:search><x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Schicht, Kunde oder Ort" /></x-slot:search>
        <x-tables.filter-field label="Kunde" for="calendar-customer">
            <x-ui.forms.select id="calendar-customer" wire:model.live="customerFilter" aria-label="Kalenderkunde"><option value="all">Alle Kunden</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->company_name }}</option>@endforeach</x-ui.forms.select>
        </x-tables.filter-field>
        <x-tables.filter-field label="Status" for="calendar-status">
            <x-ui.forms.select id="calendar-status" wire:model.live="statusFilter" aria-label="Kalenderstatus"><option value="active">Ohne stornierte</option><option value="all">Alle Status</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select>
        </x-tables.filter-field>
        <div class="flex min-h-11 items-center"><x-ui.forms.checkbox wire:model.live="onlyOpen" label="Nur offene Besetzung" /></div>
    </x-tables.toolbar>

    @if($viewMode === 'list')
        <x-tables.table :columns="[['label'=>'Schicht / Kunde','key'=>'title','width'=>'2fr'],['label'=>'Zeitraum','key'=>'starts_at','width'=>'1.5fr'],['label'=>'Besetzung','key'=>'required_staff'],['label'=>'Status','key'=>'status']]" :items="$shifts" detail-action="openShift" row-view="components.tables.rows.operations.calendar" empty="Keine Schichten in diesem Zeitraum." />
    @elseif($viewMode === 'month')
        <div class="rt-calendar-month" aria-label="Monatskalender">
            @foreach(['Mo','Di','Mi','Do','Fr','Sa','So'] as $weekday)<div class="rt-calendar-weekday">{{ $weekday }}</div>@endforeach
            @foreach($days as $day)
                <section @class(['rt-calendar-month-day', 'is-outside' => !$day['in_month'], 'is-today' => $day['is_today']]) wire:key="month-day-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                    <x-ui.buttons.button-basic mode="link" class="rt-calendar-date" wire:click="showDay('{{ $day['date']->toDateString() }}')" aria-label="{{ $day['date']->format('d.m.Y') }} öffnen">{{ $day['date']->format('j') }}</x-ui.buttons.button-basic>
                    <div class="hidden space-y-1 lg:block">
                        @foreach($day['shifts']->take(3) as $shift)
                            <x-ui.buttons.button-basic mode="link" class="rt-calendar-month-shift" wire:click="openShift({{ $shift->id }})" data-calendar-shift="{{ $shift->id }}">
                                <span class="block text-xs tabular-nums">{{ $shift->calendar_starts->format('H:i') }} · {{ $shift->calendar_reserved }}/{{ $shift->required_staff }}</span><span class="block break-words text-xs">{{ $shift->title }}</span>
                            </x-ui.buttons.button-basic>
                        @endforeach
                        @if($day['shifts']->count() > 3)<x-ui.buttons.button-basic mode="link" wire:click="showDay('{{ $day['date']->toDateString() }}')">+{{ $day['shifts']->count()-3 }} weitere</x-ui.buttons.button-basic>@endif
                    </div>
                    @if($day['shifts']->isNotEmpty())<span class="block text-center text-xs tabular-nums text-rt-muted lg:hidden">{{ $day['shifts']->count() }} <span class="sr-only">Schichten</span></span>@endif
                </section>
            @endforeach
        </div>
    @else
        @if($viewMode === 'week')
            <div class="rt-calendar-week" data-calendar-desktop-grid>
                @foreach($days as $day)
                    <section @class(['rt-calendar-week-day', 'is-today'=>$day['is_today']]) wire:key="week-day-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}">
                        <x-ui.buttons.button-basic mode="link" class="w-full" wire:click="showDay('{{ $day['date']->toDateString() }}')">{{ $day['date']->locale('de')->isoFormat('dd') }} {{ $day['date']->format('d.m.') }}</x-ui.buttons.button-basic>
                        <div class="space-y-2 p-2">
                            @forelse($day['shifts'] as $shift)<x-operations.calendar-shift :shift="$shift" :compact="true" />@empty<p class="py-6 text-center text-xs text-rt-muted">Keine Schichten</p>@endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
        <div @class(['space-y-4', 'lg:hidden'=>$viewMode === 'week']) data-calendar-mobile-agenda>
            @foreach($days as $day)
                <section class="space-y-3" wire:key="agenda-day-{{ $day['date']->toDateString() }}">
                    <h3 class="border-b border-rt-border pb-2 text-sm font-semibold dark:border-rt-dark-border">{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }} <span class="ml-2 text-rt-muted">{{ $day['shifts']->count() }} Schichten</span></h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        @forelse($day['shifts'] as $shift)<x-operations.calendar-shift :shift="$shift" />@empty<p class="py-4 text-sm text-rt-muted">Keine Schichten geplant.</p>@endforelse
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
