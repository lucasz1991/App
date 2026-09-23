<div class="rt-disposition rt-disposition--shifts rt-shift-plan min-w-0 space-y-4" data-operations-shift-management>
    <header class="rt-calendar-header rt-disposition-period">
        <div class="rt-calendar-heading">
            <div><h2 class="rt-calendar-period">{{ $rangeFrom ? \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') : '' }} – {{ $rangeTo ? \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') : '' }}</h2><p class="rt-calendar-timezone">Planungszeitraum · {{ $displayTimezone }}</p></div>
            <div class="rt-disposition-period__navigation" role="group" aria-label="Planungszeitraum wechseln">
                <x-ui.buttons.button-basic wire:click="movePeriod(-1)" aria-label="Vorheriger Zeitraum" wire:loading.attr="disabled"><i class="far fa-chevron-left" aria-hidden="true"></i></x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="currentWeek" wire:loading.attr="disabled">Diese Woche</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="movePeriod(1)" aria-label="Nächster Zeitraum" wire:loading.attr="disabled"><i class="far fa-chevron-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
            </div>
        </div>
        <div class="rt-shift-plan-controls">
            <details class="rt-disposition-range">
                <summary><i class="far fa-calendar-range" aria-hidden="true"></i>Zeitraum anpassen</summary>
                <div class="rt-shift-plan-range" role="group" aria-label="Planungszeitraum">
                <div><x-ui.forms.label for="shift-range-from" value="Von" /><x-ui.forms.date-field id="shift-range-from" wire:model.live="rangeFrom" :clearable="false" aria-label="Schichten ab" /></div>
                <span class="rt-shift-plan-range-divider" aria-hidden="true">–</span>
                <div><x-ui.forms.label for="shift-range-to" value="Bis" /><x-ui.forms.date-field id="shift-range-to" wire:model.live="rangeTo" :clearable="false" aria-label="Schichten bis" /></div>
                </div>
            </details>
            <x-ui.buttons.multi-toggle id="shift-plan-view-toggle" label="Schichtplanansicht" :value="$viewMode" action="setView" :options="[
                ['value'=>'table','label'=>'Tabelle','icon'=>'fa-table-list'],
                ['value'=>'day','label'=>'Tagesübersicht','icon'=>'fa-calendar-day'],
                ['value'=>'staffing','label'=>'Besetzung','icon'=>'fa-users'],
                ['value'=>'orders','label'=>'Leistungen','icon'=>'fa-briefcase'],
                ['value'=>'timeline','label'=>'Mitarbeiter-Zeitleiste','icon'=>'fa-clock'],
            ]" />
        </div>
    </header>
    <section class="rt-disposition-summary" aria-label="Aktive Schichten in der aktuellen Auswahl" aria-live="polite">
        <div class="rt-disposition-summary__item"><span class="rt-disposition-summary__value">{{ $shiftCount }}</span><div><span class="rt-disposition-summary__label">Aktive Schichten</span><span class="rt-disposition-summary__detail">in dieser Auswahl</span></div></div>
        <div class="rt-disposition-summary__item"><span class="rt-disposition-summary__value">{{ $reservedCount }}<small>/{{ $requiredCount }}</small></span><div><span class="rt-disposition-summary__label">Eingeplante Plätze</span><span class="rt-disposition-summary__detail">inklusive angefragter Personen</span></div></div>
        <div class="rt-disposition-summary__item" data-tone="success"><span class="rt-disposition-summary__value">{{ $confirmedCount }}</span><div><span class="rt-disposition-summary__label">Bestätigte Plätze</span><span class="rt-disposition-summary__detail">laut Rückmeldestatus</span></div></div>
        <div class="rt-disposition-summary__item" data-tone="warning"><span class="rt-disposition-summary__value">{{ $openCount }}</span><div><span class="rt-disposition-summary__label">Noch zu besetzen</span><span class="rt-disposition-summary__detail">in aktiven Schichten</span></div></div>
    </section>
    @if($viewMode !== 'timeline')
    <x-tables.toolbar title="Filter" id="operations-shift-management-filters" :search-in-header="true" :filter-count="(int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all')">
        <x-slot:bulk>
            @if(\App\Support\Operations\PlanningSchema::ready())
                <div data-tables-bulk><livewire:operations.shift-series-planner /></div>
            @endif
        </x-slot:bulk>
        <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" :results-count="$shifts->count()" placeholder="Schicht, Kunde oder Einsatzort suchen" aria-label="Schichten suchen" /></x-slot:search>
        <x-tables.filter-field label="Auftrag" for="shift-order-filter"><x-ui.forms.select id="shift-order-filter" wire:model.live="orderFilter" aria-label="Auftrag"><option value="all">Alle Aufträge</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->title }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
        <x-tables.filter-field label="Status" for="shift-status-filter"><x-ui.forms.select id="shift-status-filter" wire:model.live="statusFilter" aria-label="Schichtstatus filtern"><option value="all">Alle Status</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
        @if($nativeOperations)<x-tables.filter-field label="Handlungsbedarf" for="shift-attention"><x-ui.forms.select id="shift-attention" wire:model.live="attentionFilter" aria-label="Handlungsbedarf"><option value="all">Alle Schichten</option><option value="conflicts">Besetzung mit Konflikten</option><option value="unpublished">Unveröffentlichte Änderungen</option><option value="awaiting">Rückmeldung ausstehend</option><option value="declined">Abgelehnte Dienste</option></x-ui.forms.select></x-tables.filter-field>@endif
    </x-tables.toolbar>
    @elseif(\App\Support\Operations\PlanningSchema::ready())
        <livewire:operations.shift-series-planner />
    @endif
    @php
        $shiftColumns = [
            ['label' => 'Schicht', 'key' => 'shift', 'width' => '1.5fr'],
            ['label' => 'Kunde / Ort', 'key' => 'customer', 'width' => '1.2fr'],
            ['label' => 'Zeitfenster', 'key' => 'schedule', 'width' => '1.3fr'],
            ['label' => 'Besetzung', 'key' => 'staffing', 'width' => '1.1fr'],
            ['label' => 'Planstatus', 'key' => 'status', 'width' => '1fr'],
        ];
    @endphp
    <div @class(['space-y-6', 'rt-disposition-board' => $viewMode === 'staffing']) data-shift-view="{{ $viewMode }}" wire:loading.class="opacity-60" wire:target="setView,search,rangeFrom,rangeTo,orderFilter,statusFilter,attentionFilter,movePeriod,currentWeek">
        @if($viewMode === 'timeline')
            <livewire:operations.staff-timeline :from="$rangeFrom" :until="$rangeTo" :key="'timeline-'.$rangeFrom.'-'.$rangeTo" />
        @elseif($viewMode === 'table' || $shifts->isEmpty())
            <div class="rt-disposition-table"><x-tables.table :columns="$shiftColumns" :items="$shifts" :selected-items="[$selectedShiftId]" selection-action="selectShift" detail-action="openDetails" row-view="components.tables.rows.operations.shift-plan" empty="Keine Schichten für diese Filter gefunden." /></div>
        @else
            @foreach(match($viewMode) { 'day' => $dailyGroups, 'orders' => $orderGroups, default => $staffingGroups } as $groupKey => $group)
                @if($group['items']->isNotEmpty())
                    <section class="rt-disposition-panel min-w-0" wire:key="shift-group-{{ $viewMode }}-{{ $groupKey }}" aria-labelledby="shift-group-{{ $viewMode }}-{{ $groupKey }}" data-shift-group="{{ $groupKey }}">
                        <div class="rt-shift-plan-group">
                            <div class="min-w-0">
                                <h2 id="shift-group-{{ $viewMode }}-{{ $groupKey }}" class="break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $group['label'] }}</h2>
                                @if($viewMode === 'orders')<p class="mt-1 break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $group['customer'] }}</p>@endif
                            </div>
                            <span class="text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $group['items']->count() }} {{ $group['items']->count() === 1 ? 'Schicht' : 'Schichten' }}</span>
                            @if($viewMode === 'orders')
                                <p class="w-full text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">Aktiv: {{ $group['summary']['required'] }} Einsatzplätze · {{ $group['summary']['reserved'] }} eingeplant · {{ $group['summary']['confirmed'] }} bestätigt · {{ $group['summary']['open'] }} offen</p>
                            @endif
                        </div>
                        @if(in_array($viewMode, ['day', 'staffing'], true))
                            <div class="rt-disposition-shift-list">
                                @foreach($group['items'] as $shift)
                                    <x-operations.shift-card :shift="$shift" :timezone="$displayTimezone" :compact="$viewMode === 'staffing'" wire:key="shift-card-{{ $viewMode }}-{{ $groupKey }}-{{ $shift->id }}" />
                                @endforeach
                            </div>
                        @else
                            <div class="rt-disposition-table"><x-tables.table :columns="$shiftColumns" :items="$group['items']" :selected-items="[$selectedShiftId]" selection-action="selectShift" detail-action="openDetails" row-view="components.tables.rows.operations.shift-plan" /></div>
                        @endif
                    </section>
                @endif
            @endforeach
        @endif
    </div>
    <x-operations.modal wire:model="detailOpen" title="Schichtdetails" max-width="4xl" variant="drawer">
        @if($selectedShift)
                    @php
                        $selectedShiftStatus = $selectedShift->status instanceof \BackedEnum ? $selectedShift->status->value : (string) $selectedShift->status;
                        $selectedAssignments = $selectedShift->assignments->filter(fn ($assignment) => in_array(
                            $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                            ['requested', 'confirmed'],
                            true,
                        ));
                        $selectedReservedCount = $selectedAssignments->count();
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-rt-red">{{ $selectedShift->order?->order_number }}</p>
                            <h2 class="mt-1 break-words text-xl font-semibold tracking-tight text-rt-text dark:text-white">{{ $selectedShift->title }}</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $selectedShift->order?->customer?->company_name }} · {{ $selectedShift->role_name }}</p>
                        </div>
                        <x-ui.buttons.button-basic type="button" wire:click="editShift({{ $selectedShift->id }})" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                            <i class="far fa-pen" aria-hidden="true"></i>Bearbeiten
                        </x-ui.buttons.button-basic>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl bg-rt-surface-muted/60 p-3.5 dark:bg-rt-dark-surface-muted/50 sm:col-span-2">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Zeit &amp; Ort · {{ $displayTimezone }}</p>
                            <p class="mt-2 text-sm font-semibold text-rt-text dark:text-white">{{ $selectedShift->starts_at?->setTimezone($displayTimezone)->format('d.m.Y H:i') }} – {{ $selectedShift->ends_at?->setTimezone($displayTimezone)->format('d.m.Y H:i') }}</p>
                            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $selectedShift->location_name ?: $selectedShift->order?->location_name ?: 'Kein Einsatzort hinterlegt' }}</p>
                        </div>
                        <div class="rounded-xl bg-rt-surface-muted/60 p-3.5 dark:bg-rt-dark-surface-muted/50">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Besetzung</p>
                            <p @class(['mt-2 text-xl font-semibold tabular-nums', 'text-emerald-600 dark:text-emerald-300' => $selectedReservedCount >= $selectedShift->required_staff, 'text-rt-red' => $selectedReservedCount < $selectedShift->required_staff])>{{ $selectedReservedCount }}/{{ $selectedShift->required_staff }}</p>
                            <x-operations.status :value="$selectedShiftStatus" :label="$selectedShift->status->label()" />
                        </div>
                    </div>

                    @if(!$nativeOperations)
                    <section class="mt-5">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-rt-text dark:text-white">Eingeteilte Mitarbeitende</h3>
                            <span class="text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $selectedAssignments->count() }} Zuweisungen</span>
                        </div>
                        <div class="mt-2 divide-y divide-rt-border/60 rounded-xl border border-rt-border/70 dark:divide-rt-dark-border/60 dark:border-rt-dark-border/70">
                            @forelse($selectedAssignments as $assignment)
                                @php($assignmentValue = $assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status)
                                <div class="flex min-h-16 items-center gap-3 px-3.5 py-2.5" wire:key="shift-assignment-{{ $assignment->id }}">
                                    <img src="{{ $assignment->user?->profile_photo_url }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $assignment->user?->name ?? 'Unbekannter Mitarbeiter' }}</p>
                                        <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ method_exists($assignment->status, 'label') ? $assignment->status->label() : \Illuminate\Support\Str::headline($assignmentValue) }}@if($assignment->note) · {{ $assignment->note }}@endif</p>
                                    </div>
                                    <x-ui.buttons.button-basic type="button" wire:click="removeAssignment({{ $assignment->id }})" wire:confirm="Zuweisung wirklich entfernen?" wire:loading.attr="disabled" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-rt-red disabled:opacity-60 dark:text-rt-dark-muted dark:hover:bg-red-500/10" aria-label="{{ $assignment->user?->name }} aus der Schicht entfernen" title="Entfernen">
                                        <i class="far fa-times" aria-hidden="true"></i>
                                    </x-ui.buttons.button-basic>
                                </div>
                            @empty
                                <p class="px-4 py-7 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch niemand eingeteilt. Weise unten den ersten Mitarbeiter zu.</p>
                            @endforelse
                        </div>
                    </section>

                    @endif
                    @if($nativeOperations)
                        @if($detailOpen && \App\Support\Operations\PlanningSchema::ready())<livewire:operations.duty-activity :shift-id="$selectedShift->id" :key="'duty-'.$selectedShift->id" />@endif
                        <section class="mt-5 space-y-3" aria-label="Rückmeldungen">
                            <h3 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Rückmeldungen</h3>
                            <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Antwort','key'=>'response'],['label'=>'Im Kalender geöffnet','key'=>'opened'],['label'=>'Aktion','key'=>'action']]" :items="$feedback" row-view="components.tables.rows.operations.plan-feedback" empty="Noch keine Rückmeldungen." />
                        </section>
                        <div class="ops-panel ops-stack">
                            <div class="ops-toolbar"><span class="ops-muted">Revision {{ $selectedShift->revision }} · {{ $selectedShift->planned_break_minutes }} min Pause</span><span class="ops-badge">{{ $selectedShift->published_revision === $selectedShift->revision ? 'Veröffentlicht' : 'Entwurf' }}</span></div>
                            <div class="ops-actions">@foreach($selectedShift->qualifications as $qualification)<span class="ops-badge">{{ $qualification->name }}</span>@endforeach</div>
                            @if(count($planChanges))
                                <h3 class="text-sm font-semibold">{{ $selectedShift->published_revision === $selectedShift->revision ? 'Zuletzt veröffentlichte Änderungen' : ($selectedShift->published_revision ? 'Änderungen zur Veröffentlichung' : 'Erste Veröffentlichung') }}</h3>
                                <x-tables.table :columns="[['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Neu','key'=>'after']]" :items="collect($planChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key]))" row-view="components.tables.rows.operations.plan-change" />
                            @endif
                            @if($selectedShift->published_revision !== $selectedShift->revision && !in_array($selectedShiftStatus,['cancelled','completed']))<x-ui.buttons.button-basic mode="primary" wire:click="publish({{ $selectedShift->id }},{{ $selectedShift->revision }})" wire:confirm="Diesen Dienst veröffentlichen und Bestätigungen anfordern?" wire:loading.attr="disabled">Dienst veröffentlichen</x-ui.buttons.button-basic>@endif
                        </div>
                    @endif
                    @if($selectedShiftStatus === 'cancelled')
                        <section class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:!border-slate-700 dark:!bg-slate-800/60 dark:!text-slate-300">
                            <p class="font-semibold text-rt-text dark:text-white">Schicht storniert</p>
                            <p class="mt-1 leading-5">Für eine stornierte Schicht können keine weiteren Mitarbeitenden reserviert werden.</p>
                        </section>
                    @else
                    <section class="mt-5 space-y-3 border-t border-rt-border pt-5 dark:border-rt-dark-border">
                        <h3 class="text-sm font-semibold text-rt-text dark:text-white">Mitarbeiter zuweisen</h3>
                        @if($nativeOperations && $candidates)
                            <x-tables.search-field wire:model.live.debounce.300ms="candidateSearch" placeholder="Mitarbeiter suchen" aria-label="Kandidaten suchen" />
                            <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Eignung','key'=>'eligibility'],['label'=>'Auswahl','key'=>'action']]" :items="$candidates" row-view="components.tables.rows.operations.candidate" empty="Keine Mitarbeiter gefunden." />
                            {{ $candidates->links() }}
                        @endif
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-ui.forms.label for="assignment-employee" value="Mitarbeiter" />
                                <x-ui.forms.select id="assignment-employee" wire:model="employeeId" class="mt-1" placeholder="Mitarbeiter auswählen">
                                    @foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}@if($employee->profile?->position) · {{ $employee->profile->position }}@endif</option>@endforeach
                                </x-ui.forms.select>
                                @error('employeeId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-ui.forms.label for="assignment-status" value="Zuweisungsstatus" />
                                <x-ui.forms.select id="assignment-status" wire:model="assignmentStatus" class="mt-1">
                                    @foreach($assignmentStatusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                                </x-ui.forms.select>
                                @error('assignmentStatus') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <x-ui.forms.label for="assignment-note" value="Hinweis (optional)" />
                                <x-ui.forms.input id="assignment-note" wire:model="assignmentNote" class="mt-1" />
                                @error('assignmentNote') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        @error('assignment') <p class="mt-3 rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">{{ $message }}</p> @enderror
                        <x-ui.buttons.button-basic type="button" wire:click="assignEmployee" wire:loading.attr="disabled" class="mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:opacity-60 sm:w-auto">
                            <i wire:loading.remove wire:target="assignEmployee" class="far fa-user-plus" aria-hidden="true"></i>
                            <i wire:loading wire:target="assignEmployee" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                            Zuweisen
                        </x-ui.buttons.button-basic>
                    </section>
                    @endif
                @else
                    <div class="flex min-h-80 flex-col items-center justify-center text-center">
                        <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                        <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Schicht auswählen</h2>
                        <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle eine Schicht aus, um die Besetzung zu planen.</p>
                    </div>
                @endif
    </x-operations.modal>
    <x-dialog-modal wire:model="formOpen" maxWidth="3xl">
        <x-slot:title>{{ $editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht anlegen' }}</x-slot:title>
        <x-slot:content>
            @error('schedule')
                <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-500/10 dark:text-red-300" role="alert">{{ $message }}</p>
            @enderror
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="shift-order" value="Auftrag" />
                    <x-ui.forms.select id="shift-order" wire:model="orderId" class="mt-1" placeholder="Auftrag auswählen">
                        @foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach
                    </x-ui.forms.select>
                    @error('orderId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-title" value="Schichttitel" />
                    <x-ui.forms.input id="shift-title" wire:model="title" class="mt-1" />
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-role" value="Rolle / Funktion" />
                    <x-ui.forms.input id="shift-role" wire:model="roleName" class="mt-1" placeholder="z. B. Triebfahrzeugführer" />
                    @error('roleName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-start" :value="'Beginn ('.$timezone.')'" />
                    <x-ui.forms.date-time-field id="shift-start" wire:model="startsAt" :aria-label="'Beginn ('.$timezone.')'" class="mt-1" required />
                    @error('startsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-end" :value="'Ende ('.$timezone.')'" />
                    <x-ui.forms.date-time-field id="shift-end" wire:model="endsAt" :aria-label="'Ende ('.$timezone.')'" class="mt-1" required />
                    @error('endsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-required-staff" value="Benötigte Mitarbeitende" />
                    <div class="mt-1"><x-ui.forms.number-input id="shift-required-staff" min="1" max="999" :nullable="false" wire:model="requiredStaff" /></div>
                    @error('requiredStaff') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-status" value="Schichtstatus" />
                    <x-ui.forms.select id="shift-status" wire:model="status" class="mt-1">
                        @foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                    </x-ui.forms.select>
                    @error('status') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="shift-location" value="Einsatzort (leer = Auftrag übernehmen)" />
                    <x-ui.forms.input id="shift-location" wire:model="locationName" class="mt-1" />
                    @error('locationName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="shift-notes" value="Interne Notizen" />
                    <x-ui.forms.textarea id="shift-notes" wire:model="notes" rows="4" class="mt-1" />
                    @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @if($nativeOperations)
                    <x-operations.field label="Geplante Pause (min)" model="plannedBreakMinutes" type="number" min="0" max="1439" />
                    <fieldset class="space-y-2"><legend class="ops-muted">Erforderliche Nachweise</legend>@foreach($qualificationTypes as $type)<x-ui.forms.checkbox wire:model="qualificationIds" value="{{ $type->id }}" :label="$type->name" />@endforeach</fieldset>
                @endif
            </div>
        </x-slot:content>
        <x-slot:footer>
            <x-ui.buttons.button-basic type="button" x-on:click="$dispatch('close')" class="inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="button" wire:click="saveShift" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60">
                <i wire:loading.remove wire:target="saveShift" class="far fa-check" aria-hidden="true"></i>
                <i wire:loading wire:target="saveShift" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                Speichern
            </x-ui.buttons.button-basic>
        </x-slot:footer>
    </x-dialog-modal>
</div>
