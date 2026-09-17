<div class="space-y-4" data-operations-shift-management>
    <x-tables.toolbar title="Filter" id="operations-shift-management-filters" :search-in-header="true" :filter-count="(int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all')">
        <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" :results-count="$shifts->count()" placeholder="Schicht, Kunde oder Einsatzort suchen" aria-label="Schichten suchen" /></x-slot:search>
        <x-tables.filter-field label="Von" for="shift-range-from"><x-ui.forms.input id="shift-range-from" type="date" wire:model.live="rangeFrom" aria-label="Schichten ab" /></x-tables.filter-field>
        <x-tables.filter-field label="Bis" for="shift-range-to"><x-ui.forms.input id="shift-range-to" type="date" wire:model.live="rangeTo" aria-label="Schichten bis" /></x-tables.filter-field>
        <x-tables.filter-field label="Auftrag" for="shift-order-filter"><x-ui.forms.select id="shift-order-filter" wire:model.live="orderFilter" aria-label="Auftrag"><option value="all">Alle Aufträge</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->title }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
        <x-tables.filter-field label="Status" for="shift-status-filter"><x-ui.forms.select id="shift-status-filter" wire:model.live="statusFilter" aria-label="Schichtstatus filtern"><option value="all">Alle Status</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
    </x-tables.toolbar>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Schichtplanansicht">
            @foreach(['table' => ['Tabelle', 'fa-table-list'], 'day' => ['Tagesübersicht', 'fa-calendar-day'], 'staffing' => ['Besetzung', 'fa-users'], 'orders' => ['Leistungen', 'fa-briefcase']] as $key => [$label, $icon])
                <x-ui.buttons.button-basic type="button" :mode="$viewMode === $key ? 'primary' : 'basic'" wire:click="setView('{{ $key }}')" wire:loading.attr="disabled" wire:target="setView" aria-pressed="{{ $viewMode === $key ? 'true' : 'false' }}" class="min-h-11" wire:key="shift-view-{{ $key }}"><i class="far {{ $icon }}" aria-hidden="true"></i>{{ $label }}</x-ui.buttons.button-basic>
            @endforeach
        </div>
        <p class="text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted" aria-live="polite">{{ $shifts->count() }} Schichten <span aria-hidden="true">·</span> {{ $openCount }} offene Plätze</p>
    </div>
    @php
        $shiftColumns = [
            ['label' => 'Schicht', 'key' => 'shift', 'width' => '1.5fr'],
            ['label' => 'Kunde / Ort', 'key' => 'customer', 'width' => '1.2fr'],
            ['label' => 'Zeitfenster', 'key' => 'schedule', 'width' => '1.3fr'],
            ['label' => 'Besetzung', 'key' => 'staffing', 'width' => '1.1fr'],
            ['label' => 'Planstatus', 'key' => 'status', 'width' => '1fr'],
        ];
    @endphp
    <div class="space-y-6" data-shift-view="{{ $viewMode }}" wire:loading.class="opacity-60" wire:target="setView,search,rangeFrom,rangeTo,orderFilter,statusFilter">
        @if($viewMode === 'table' || $shifts->isEmpty())
            <x-tables.table :columns="$shiftColumns" :items="$shifts" :selected-items="[$selectedShiftId]" selection-action="selectShift" detail-action="openDetails" row-view="components.tables.rows.operations.shift-plan" empty="Keine Schichten für diese Filter gefunden." />
        @else
            @if($viewMode === 'day')<p class="text-xs text-rt-muted dark:text-rt-dark-muted">Tageszuordnung: {{ $displayTimezone }}</p>@endif
            @foreach(match($viewMode) { 'day' => $dailyGroups, 'orders' => $orderGroups, default => $staffingGroups } as $groupKey => $group)
                @if($group['items']->isNotEmpty())
                    <section class="min-w-0" wire:key="shift-group-{{ $viewMode }}-{{ $groupKey }}" aria-labelledby="shift-group-{{ $viewMode }}-{{ $groupKey }}" data-shift-group="{{ $groupKey }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-rt-surface-muted px-3 py-3 dark:bg-rt-dark-surface-muted">
                            <div class="min-w-0">
                                <h2 id="shift-group-{{ $viewMode }}-{{ $groupKey }}" class="break-words text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $group['label'] }}</h2>
                                @if($viewMode === 'orders')<p class="mt-1 break-words text-xs text-rt-muted dark:text-rt-dark-muted">{{ $group['customer'] }}</p>@endif
                            </div>
                            <span class="text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $group['items']->count() }} {{ $group['items']->count() === 1 ? 'Schicht' : 'Schichten' }}</span>
                            @if($viewMode === 'orders')
                                <p class="w-full text-xs tabular-nums text-rt-muted dark:text-rt-dark-muted">Aktiv: {{ $group['summary']['required'] }} Einsatzplätze · {{ $group['summary']['reserved'] }} eingeplant · {{ $group['summary']['confirmed'] }} bestätigt · {{ $group['summary']['open'] }} offen</p>
                            @endif
                        </div>
                        <x-tables.table :columns="$shiftColumns" :items="$group['items']" :selected-items="[$selectedShiftId]" selection-action="selectShift" detail-action="openDetails" row-view="components.tables.rows.operations.shift-plan" />
                    </section>
                @endif
            @endforeach
        @endif
    </div>
    <x-operations.modal wire:model="detailOpen" title="Schichtdetails" max-width="4xl">
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

                    @if($nativeOperations)
                        <div class="ops-panel ops-stack">
                            <div class="ops-toolbar"><span class="ops-muted">Revision {{ $selectedShift->revision }} · {{ $selectedShift->planned_break_minutes }} min Pause</span><span class="ops-badge">{{ $selectedShift->published_revision === $selectedShift->revision ? 'Veröffentlicht' : 'Entwurf' }}</span></div>
                            <div class="ops-actions">@foreach($selectedShift->qualifications as $qualification)<span class="ops-badge">{{ $qualification->name }}</span>@endforeach</div>
                            @if($selectedShift->published_revision !== $selectedShift->revision && !in_array($selectedShiftStatus,['cancelled','completed']))<x-ui.buttons.button-basic mode="primary" wire:click="publish({{ $selectedShift->id }},{{ $selectedShift->revision }})" wire:confirm="Diesen Dienst veröffentlichen und Bestätigungen anfordern?" wire:loading.attr="disabled">Dienst veröffentlichen</x-ui.buttons.button-basic>@endif
                        </div>
                    @endif
                    @if($selectedShiftStatus === 'cancelled')
                        <section class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:!border-slate-700 dark:!bg-slate-800/60 dark:!text-slate-300">
                            <p class="font-semibold text-rt-text dark:text-white">Schicht storniert</p>
                            <p class="mt-1 leading-5">Für eine stornierte Schicht können keine weiteren Mitarbeitenden reserviert werden.</p>
                        </section>
                    @else
                    <section class="mt-5 rounded-xl border border-rose-200 bg-rose-50/60 p-4 dark:border-rose-900 dark:bg-rose-500/10">
                        <h3 class="text-sm font-semibold text-rt-text dark:text-white">Mitarbeiter zuweisen</h3>
                        <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Zeitüberschneidungen mit aktiven Einsätzen werden vor dem Speichern geprüft.</p>
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
                    <x-ui.forms.input id="shift-start" type="datetime-local" wire:model="startsAt" class="mt-1" />
                    @error('startsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-end" :value="'Ende ('.$timezone.')'" />
                    <x-ui.forms.input id="shift-end" type="datetime-local" wire:model="endsAt" class="mt-1" />
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
