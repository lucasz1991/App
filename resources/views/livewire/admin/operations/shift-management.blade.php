<div class="rt-disposition rt-disposition--shifts rt-shift-plan min-w-0" data-operations-shift-management
    x-data="rtShiftDetailDrawer" x-on:operations-shift-detail-request.window="openShiftDetail($event.detail.id)">
    <template x-teleport="[data-page-header-search]">
        <div class="rt-shift-plan-header-controls" data-shift-plan-header-controls>
            @php
                $planViews = [
                    'table' => ['Tabelle', 'fa-list-alt'],
                    'day' => ['Tagesübersicht', 'fa-calendar-day'],
                    'staffing' => ['Besetzung', 'fa-users'],
                    'orders' => ['Leistungen', 'fa-briefcase'],
                    'timeline' => ['Zeitleiste', 'fa-clock'],
                ];
                [$currentViewLabel, $currentViewIcon] = $planViews[$viewMode];
                $distributionCount = $pendingShifts->total() + $unplannedOrders->total();
            @endphp
            <div class="rt-shift-plan-period-controls">
                <x-ui.dropdown.anchor-dropdown align="left" width="auto" offset="6" dropdown-id="shift-plan-period-{{ $this->getId() }}" layer-group="operations-shift-plan" content-role="dialog" content-label="Planungszeitraum anpassen" dropdown-classes="rt-shift-plan-range-dropdown" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                    <x-slot:trigger>
                        <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-shift-plan-period-trigger" aria-label="Planungszeitraum auswählen">
                            <i class="far fa-calendar-alt" aria-hidden="true"></i>
                            <span><strong class="rt-calendar-period">{{ $rangeFrom ? \Carbon\CarbonImmutable::parse($rangeFrom)->format('d.m.') : '' }} – {{ $rangeTo ? \Carbon\CarbonImmutable::parse($rangeTo)->format('d.m.Y') : '' }}</strong></span>
                            <i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i>
                        </x-ui.buttons.button-basic>
                    </x-slot:trigger>
                    <x-slot:content>
                        <x-ui.forms.date-range-picker from-model="rangeFrom" until-model="rangeTo" apply-action="applyPeriod" :max-days="94" :reset-on-open="true" x-on:date-range-applied="close(true)" x-on:date-range-cancel="close(true)" />
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
            </div>
            <x-ui.dropdown.anchor-dropdown align="left" width="56" offset="6" dropdown-id="shift-plan-view-{{ $this->getId() }}" layer-group="operations-shift-plan" content-label="Schichtplanansicht auswählen" content-classes="p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                <x-slot:trigger>
                    <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-shift-plan-view-trigger" aria-label="Ansicht ändern: {{ $currentViewLabel }}" title="Ansicht: {{ $currentViewLabel }}"><i class="far fa-eye" aria-hidden="true"></i><i class="far {{ $currentViewIcon }}" aria-hidden="true" data-current-view="{{ $viewMode }}"></i><i class="far fa-chevron-down rt-shift-plan-control__chevron" aria-hidden="true"></i></x-ui.buttons.button-basic>
                </x-slot:trigger>
                <x-slot:content>
                    @foreach($planViews as $view => [$label, $icon])
                        <button type="button" role="menuitemradio" aria-checked="{{ $viewMode === $view ? 'true' : 'false' }}" wire:click="setView('{{ $view }}')" x-on:click="close()" class="rt-shift-plan-view-option">
                            <i class="far {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>@if($viewMode === $view)<i class="far fa-check rt-shift-plan-view-option__check" aria-hidden="true"></i>@endif
                        </button>
                    @endforeach
                    <a href="{{ \App\Support\Operations\OperationsPages::moduleUrl('calendar') }}" wire:navigate role="menuitem" class="rt-shift-plan-view-option"><i class="far fa-calendar-alt" aria-hidden="true"></i><span>Kalender</span></a>
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
            <x-ui.dropdown.anchor-dropdown align="left" width="96" offset="6" dropdown-id="shift-plan-distribution-{{ $this->getId() }}" layer-group="operations-shift-plan" content-role="dialog" content-label="Noch zu verteilen" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
                <x-slot:trigger>
                    <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-shift-plan-distribution-trigger" aria-label="Noch zu verteilen: {{ $pendingShifts->total() }} Schichten und {{ $unplannedOrders->total() }} Leistungen" title="Noch zu verteilen im gewählten Zeitraum">
                        <i class="far fa-tasks" aria-hidden="true"></i>
                        <span class="rt-shift-plan-distribution-count" aria-hidden="true">{{ $distributionCount }}</span>
                    </x-ui.buttons.button-basic>
                </x-slot:trigger>
                <x-slot:content>
                    @include('livewire.admin.operations.partials.pending-distribution')
                </x-slot:content>
            </x-ui.dropdown.anchor-dropdown>
            @if($viewMode !== 'timeline')
                <div class="rt-shift-plan-header-search" data-page-list-search wire:key="shift-plan-list-search">
                    <x-tables.search-field context="page" wire:model.live.debounce.300ms="search" :results-count="$shifts->count()" placeholder="Schicht, Kunde oder Einsatzort suchen" aria-label="Schichten suchen" />
                </div>
            @elseif($nativeOperations)
                <div data-shift-plan-timeline-suggestions wire:key="shift-plan-timeline-suggestions" wire:ignore></div>
                <div class="rt-shift-plan-header-search" data-shift-plan-timeline-search wire:key="shift-plan-timeline-search" wire:ignore></div>
            @endif
        </div>
    </template>
    <template x-teleport="[data-page-header-actions]">
        @if(\App\Support\Operations\PlanningSchema::ready())
            <div class="rt-shift-plan-header-action" data-shift-plan-header-actions>
                <x-ui.buttons.button-basic type="button" size="sm" wire:click="$dispatch('open-shift-series-planner')" aria-label="Vorlagen und Serien" title="Vorlagen und Serien"><i class="far fa-repeat" aria-hidden="true"></i><span>Vorlagen &amp; Serien</span></x-ui.buttons.button-basic>
            </div>
        @endif
    </template>
    @if(\App\Support\Operations\PlanningSchema::ready())
        <livewire:operations.shift-series-planner :show-trigger="false" />
    @endif
    @if($viewMode !== 'timeline')
    <x-tables.toolbar title="Filter" id="operations-shift-management-filters" :filter-count="(int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all')">
        <details class="rt-shift-filters" x-bind:open="!desktopFilters">
            <summary class="rt-shift-filters__trigger" aria-label="Schichtfilter öffnen" title="Schichtfilter">
                <i class="far fa-sliders" aria-hidden="true"></i><span>Filter</span>
                @if((int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all') > 0)<span class="rt-shift-filters__count">{{ (int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all') }}</span>@endif
                <i class="far fa-chevron-down rt-shift-filters__chevron" aria-hidden="true"></i>
            </summary>
            <div class="rt-shift-filters__panel">
                <x-tables.filter-field label="Auftrag" for="shift-order-filter"><x-ui.forms.select id="shift-order-filter" wire:model.live="orderFilter" aria-label="Auftrag"><option value="all">Alle Aufträge</option>@foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->title }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
                <x-tables.filter-field label="Status" for="shift-status-filter"><x-ui.forms.select id="shift-status-filter" wire:model.live="statusFilter" aria-label="Schichtstatus filtern"><option value="all">Alle Status</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
                @if($nativeOperations)<x-tables.filter-field label="Handlungsbedarf" for="shift-attention"><x-ui.forms.select id="shift-attention" wire:model.live="attentionFilter" aria-label="Handlungsbedarf"><option value="all">Alle Schichten</option><option value="conflicts">Besetzung mit Konflikten</option><option value="unpublished">Unveröffentlichte Änderungen</option><option value="awaiting">Rückmeldung ausstehend</option><option value="declined">Abgelehnte Dienste</option></x-ui.forms.select></x-tables.filter-field>@endif
            </div>
        </details>
    </x-tables.toolbar>
    @endif
    @php
        $shiftColumns = [
            ['label' => 'Schicht', 'key' => 'shift', 'width' => 'minmax(0,1.5fr)', 'sortable' => true],
            ['label' => 'Kunde / Ort', 'key' => 'customer', 'width' => 'minmax(0,1.2fr)', 'sortable' => true],
            ['label' => 'Zeitfenster', 'key' => 'schedule', 'width' => 'minmax(0,1.3fr)', 'sortable' => true],
            ['label' => 'Besetzung', 'key' => 'staffing', 'width' => 'minmax(0,1.1fr)', 'sortable' => true],
            ['label' => 'Planstatus', 'key' => 'status', 'width' => 'minmax(0,1fr)', 'sortable' => true],
        ];
    @endphp
    <div @class(['space-y-6', 'rt-disposition-board' => $viewMode === 'staffing']) data-shift-view="{{ $viewMode }}" wire:loading.class="opacity-60" wire:target="tableSort,setView,search,rangeFrom,rangeTo,orderFilter,statusFilter,attentionFilter,movePeriod,currentWeek">
        @if($viewMode === 'timeline')
            @if($nativeOperations)
                <livewire:operations.staff-timeline :from="$rangeFrom" :until="$rangeTo" :search-in-header="true" :planning-enabled="true" :key="'timeline-'.$rangeFrom.'-'.$rangeTo" />
            @else
                <div class="rt-shift-plan-unavailable" role="status"><i class="far fa-clock" aria-hidden="true"></i><span>Die Zeitleiste ist verfügbar, sobald der Mitarbeiter- und Abwesenheitsbereich eingerichtet ist.</span></div>
            @endif
        @elseif($viewMode === 'table' || $shifts->isEmpty())
            <x-tables.table :columns="$shiftColumns" sort-action="tableSort" :sort-by="$sortBy" :sort-dir="$sortDir" table-key="shift-plan" class="rt-shift-plan-table" :flush-top="true" :items="$shifts" :selected-items="[$selectedShiftId]" selection-action="selectShift" detail-event="operations-shift-detail-request" row-view="components.tables.rows.operations.shift-plan" empty="Keine Schichten für diese Filter gefunden." />
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
                            <x-tables.table :columns="$shiftColumns" sort-action="tableSort" :sort-by="$sortBy" :sort-dir="$sortDir" :table-key="'shift-order-'.$groupKey" :flush-top="true" :items="$group['items']" :selected-items="[$selectedShiftId]" selection-action="selectShift" detail-event="operations-shift-detail-request" row-view="components.tables.rows.operations.shift-plan" />
                        @endif
                    </section>
                @endif
            @endforeach
        @endif
    </div>
    @php
        $shiftPanelId = 'shift-plan-detail-'.$this->getId();
        $detailIdPrefix = 'shift-detail-'.$this->getId();
        $formIdPrefix = 'shift-form-'.$this->getId();
        if ($selectedShift) {
            $selectedShiftStatus = $selectedShift->status instanceof \BackedEnum ? $selectedShift->status->value : (string) $selectedShift->status;
            $selectedAssignments = $selectedShift->assignments->filter(fn ($assignment) => in_array(
                $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                ['requested', 'confirmed'],
                true,
            ));
            $selectedReservedCount = $selectedAssignments->count();
            $shiftStart = $selectedShift->starts_at?->setTimezone($displayTimezone)->locale('de');
            $shiftEnd = $selectedShift->ends_at?->setTimezone($displayTimezone)->locale('de');
            $shiftWindow = $shiftStart && $shiftEnd
                ? ($shiftStart->isSameDay($shiftEnd)
                    ? $shiftStart->isoFormat('dd, DD.MM.').' · '.$shiftStart->format('H:i').'–'.$shiftEnd->format('H:i')
                    : $shiftStart->isoFormat('dd, DD.MM. HH:mm').' – '.$shiftEnd->isoFormat('dd, DD.MM. HH:mm'))
                : null;
            $shiftLocation = $selectedShift->location_name ?: $selectedShift->order?->location_name;
            $shiftClosed = in_array($selectedShiftStatus, ['cancelled', 'completed'], true);
            $shiftPublished = (int) $selectedShift->published_revision === (int) $selectedShift->revision;
            $canPublish = $nativeOperations && ! $shiftPublished && ! $shiftClosed;
            $detailTabs = [
                'overview' => ['label' => 'Übersicht', 'alert' => $canPublish],
                'staffing' => ['label' => $nativeOperations ? 'Besetzung & Rückmeldungen' : 'Besetzung', 'count' => $selectedReservedCount.'/'.$selectedShift->required_staff],
            ];
            if ($nativeOperations) {
                if (\App\Support\Operations\PlanningSchema::ready()) {
                    $detailTabs['activity'] = ['label' => 'Aktivität'];
                }
            }
        }
    @endphp
    {{-- Ein Panel, zwei Zustände: Schichtdetails und das Schichtformular (Anlegen/Bearbeiten).
         rtShiftDetailDrawer zeigt das Panel, sobald einer der beiden Zustände offen ist. --}}
    <x-operations.panel :id="$shiftPanelId" show-expression="detailVisible" :label="$formOpen ? ($editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht anlegen') : 'Schichtdetails'">
        {{-- Zustand 1 · Schichtdetails. Beim Bearbeiten nur ausgeblendet, damit eingebettete
             Livewire-Bereiche nach „Abbrechen“ ohne Neuaufbau wieder dastehen. --}}
        {{-- x-bind:hidden verhindert, dass nach dem Anlegen während der Ausfahrt kurz die zuletzt
             geöffnete Schicht aufblitzt: sichtbar nur bei offener Schicht, beim Laden oder bei Fehlern. --}}
        <div class="rt-ops-panel__mode" data-panel-mode="detail" @if($formOpen) hidden @endif
            x-bind:hidden="$wire.formOpen || (!$wire.detailOpen && !loading && !error)"
            x-data="{ tab: 'overview' }" wire:key="shift-panel-detail-{{ $selectedShiftId ?? 'none' }}">
            <div class="rt-ops-panel__accent" aria-hidden="true"></div>

            <header class="rt-ops-panel__header" x-show="loading || error" style="display: none" data-shift-detail-placeholder>
                <span class="rt-ops-panel__icon" aria-hidden="true"><i class="far fa-clock"></i></span>
                <div class="rt-ops-panel__heading" aria-hidden="true">
                    <span class="rt-ops-panel__eyebrow">Schichtdetails</span>
                    <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--title"></span>
                    <span class="rt-ops-panel__skeleton rt-ops-panel__skeleton--line"></span>
                </div>
                <div class="rt-ops-panel__actions">
                    <button type="button" class="rt-ops-panel__icon-button" x-on:click="$dispatch('close')" aria-label="Schichtdetails schließen" title="Schichtdetails schließen"><i class="far fa-xmark" aria-hidden="true"></i></button>
                </div>
            </header>

            @if($selectedShift)
                <x-operations.panel.header x-show="!loading && !error" eyebrow="Schichtdetails" :title="$selectedShift->title"
                    :subtitle="collect([$selectedShift->order?->order_number, $selectedShift->order?->title, $selectedShift->order?->customer?->company_name])->filter()->implode(' · ')"
                    icon="fa-clock" close-label="Schichtdetails schließen">
                    <x-slot:actions>
                        <x-ui.buttons.button-basic type="button" wire:click="editShift({{ $selectedShift->id }})" wire:loading.attr="disabled" wire:target="editShift" data-shift-detail-edit>
                            <i wire:loading.remove wire:target="editShift" class="far fa-pen" aria-hidden="true"></i>
                            <i wire:loading wire:target="editShift" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                            Bearbeiten
                        </x-ui.buttons.button-basic>
                    </x-slot:actions>
                    <x-slot:meta>
                        @if(filled($selectedShift->role_name))
                            <span class="rt-ops-panel__fact"><i class="far fa-id-badge" aria-hidden="true"></i>{{ $selectedShift->role_name }}</span>
                        @endif
                        @if($shiftWindow)
                            <span class="rt-ops-panel__fact rt-ops-panel__fact--strong"><i class="far fa-calendar" aria-hidden="true"></i><time datetime="{{ $shiftStart->toIso8601String() }}">{{ $shiftWindow }}</time></span>
                        @endif
                        @if(filled($shiftLocation))
                            <span class="rt-ops-panel__fact"><i class="far fa-map-marker-alt" aria-hidden="true"></i>{{ $shiftLocation }}</span>
                        @endif
                        <span class="rt-ops-panel__meta-end">
                            <x-operations.status :value="$selectedShiftStatus" :label="$selectedShift->status->label()" />
                            @unless($shiftClosed)
                                <span class="rt-ops-panel__pill" data-tone="{{ $selectedReservedCount >= $selectedShift->required_staff ? 'success' : 'critical' }}">{{ $selectedReservedCount }}/{{ $selectedShift->required_staff }} eingeplant</span>
                            @endunless
                        </span>
                    </x-slot:meta>
                </x-operations.panel.header>
                <x-operations.panel.tabs x-show="!loading && !error" :tabs="$detailTabs" label="Bereiche der Schichtdetails" :id-prefix="$detailIdPrefix" />
            @else
                <x-operations.panel.header x-show="!loading && !error" eyebrow="Schichtdetails" title="Keine Schicht ausgewählt" icon="fa-clock" close-label="Schichtdetails schließen" />
            @endif

            <div class="rt-ops-panel__body rt-modal-content" data-shift-detail-body>
                <div class="rt-ops-panel__feedback"><x-operations.feedback /></div>
                <div x-show="loading" x-cloak data-shift-detail-loading>
                    <p class="mb-4 flex items-center gap-2 text-sm text-rt-muted dark:text-rt-dark-muted" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i>Schichtdetails werden geladen …</p>
                    <x-ui.loading.skeleton variant="list" :rows="4" />
                </div>
                <div x-show="!loading && error" x-cloak class="space-y-4 py-6" data-shift-detail-error>
                    <p class="text-sm text-rt-muted dark:text-rt-dark-muted" x-text="error" role="alert"></p>
                    <x-ui.buttons.button-basic type="button" size="sm" x-on:click="openShiftDetail(requestedShiftId)"><i class="far fa-arrow-rotate-right" aria-hidden="true"></i>Erneut versuchen</x-ui.buttons.button-basic>
                </div>
                <div x-show="!loading && !error" x-bind:aria-busy="loading" data-shift-detail-content>
                    @if($selectedShift)
                        <x-operations.panel.tab name="overview" :id-prefix="$detailIdPrefix">
                            @if($canPublish)
                                <x-operations.panel.group tone="warning" data-shift-publish-notice>
                                    <div class="rt-ops-panel__notice">
                                        <i class="far fa-exclamation-triangle" aria-hidden="true"></i>
                                        <div class="rt-ops-panel__notice-text">
                                            <strong>Unveröffentlichte Änderungen</strong>
                                            <span>Revision {{ $selectedShift->revision }} · {{ $selectedShift->published_revision ? 'veröffentlicht ist Revision '.$selectedShift->published_revision : 'noch nie veröffentlicht' }}</span>
                                        </div>
                                        <x-ui.buttons.button-basic type="button" mode="primary" wire:click="publish({{ $selectedShift->id }},{{ $selectedShift->revision }})" wire:confirm="Diesen Dienst veröffentlichen und Bestätigungen anfordern?" wire:loading.attr="disabled">Dienst veröffentlichen</x-ui.buttons.button-basic>
                                    </div>
                                </x-operations.panel.group>
                            @endif
                            {{-- Offene Änderungen gehören zum Veröffentlichungs-Hinweis; die Liste der zuletzt
                                 veröffentlichten Änderungen ist Nachschlagewissen und steht am Ende. --}}
                            @if($nativeOperations && count($planChanges) && ! $shiftPublished)
                                <x-operations.panel.group :title="$selectedShift->published_revision ? 'Änderungen zur Veröffentlichung' : 'Erste Veröffentlichung'">
                                    <x-tables.table :columns="[['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Neu','key'=>'after']]" :items="collect($planChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key]))" row-view="components.tables.rows.operations.plan-change" />
                                </x-operations.panel.group>
                            @endif
                            @if($detailOpen)
                                <x-operations.panel.group title="Zeit & Ort" data-shift-detail-previews>
                                    <div class="rt-ops-panel__previews">
                                        @if($shiftStart && $shiftEnd)
                                            <x-operations.timeline-mini-calendar :start="$shiftStart" :end="$shiftEnd" />
                                        @else
                                            <p class="rt-ops-panel__text">Kein vollständiger Zeitraum hinterlegt.</p>
                                        @endif
                                        <x-operations.timeline-mini-map :preview="\App\Support\Operations\TimelineLocationPreview::fromShift($selectedShift)" :location="$shiftLocation" />
                                    </div>
                                </x-operations.panel.group>
                            @endif
                            <x-operations.panel.group title="Einsatz">
                                <dl class="rt-ops-panel__rows">
                                    <x-operations.panel.row label="Leistung">{{ $selectedShift->order ? $selectedShift->order->order_number.' · '.$selectedShift->order->title : 'Leistung nicht verfügbar' }}</x-operations.panel.row>
                                    <x-operations.panel.row label="Kunde">{{ $selectedShift->order?->customer?->company_name ?: '—' }}</x-operations.panel.row>
                                    <x-operations.panel.row label="Tätigkeit">{{ $selectedShift->role_name ?: '—' }}</x-operations.panel.row>
                                    <x-operations.panel.row label="Einsatzort">{{ $shiftLocation ?: 'Kein Einsatzort hinterlegt' }}</x-operations.panel.row>
                                    <x-operations.panel.row label="Zeitfenster">
                                        <span class="rt-ops-panel__num">{{ $shiftStart?->format('d.m.Y H:i') }} – {{ $shiftEnd?->format('d.m.Y H:i') }}</span>
                                        <small>{{ $displayTimezone }}</small>
                                    </x-operations.panel.row>
                                    @if($nativeOperations)
                                        <x-operations.panel.row label="Geplante Pause"><span class="rt-ops-panel__num">{{ (int) $selectedShift->planned_break_minutes }} Minuten</span></x-operations.panel.row>
                                    @endif
                                    <x-operations.panel.row label="Besetzung"><span class="rt-ops-panel__num">{{ $selectedReservedCount }} von {{ $selectedShift->required_staff }}</span> eingeplant</x-operations.panel.row>
                                    @if($nativeOperations)
                                        <x-operations.panel.row label="Nachweise">
                                            @if($selectedShift->qualifications->isNotEmpty())
                                                <span class="rt-ops-panel__chips">@foreach($selectedShift->qualifications as $qualification)<span class="ops-badge">{{ $qualification->name }}</span>@endforeach</span>
                                            @else
                                                Keine erforderlich
                                            @endif
                                        </x-operations.panel.row>
                                    @endif
                                </dl>
                            </x-operations.panel.group>
                            @if(filled($selectedShift->notes))
                                <x-operations.panel.group title="Interne Notizen" meta="nur intern">
                                    <p class="rt-ops-panel__text">{{ $selectedShift->notes }}</p>
                                </x-operations.panel.group>
                            @endif
                            @if($nativeOperations && count($planChanges) && $shiftPublished)
                                <x-operations.panel.group title="Zuletzt veröffentlichte Änderungen">
                                    <x-tables.table :columns="[['label'=>'Feld','key'=>'label'],['label'=>'Bisher','key'=>'before'],['label'=>'Neu','key'=>'after']]" :items="collect($planChanges)->map(fn ($change, $key) => (object) ($change + ['id'=>$key]))" row-view="components.tables.rows.operations.plan-change" />
                                </x-operations.panel.group>
                            @endif
                        </x-operations.panel.tab>

                        <x-operations.panel.tab name="staffing" :id-prefix="$detailIdPrefix">
                            @if($nativeOperations)
                                <x-operations.panel.group title="Besetzung & Rückmeldungen" :meta="$feedback->count() === 1 ? '1 Zuweisung' : $feedback->count().' Zuweisungen'">
                                    <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Antwort','key'=>'response'],['label'=>'Im Kalender geöffnet','key'=>'opened'],['label'=>'Aktion','key'=>'action']]" :items="$feedback" row-view="components.tables.rows.operations.plan-feedback" empty="Noch keine Rückmeldungen." />
                                </x-operations.panel.group>
                            @endif
                            @if(!$nativeOperations)
                                <x-operations.panel.group title="Eingeteilte Mitarbeitende" :meta="$selectedAssignments->count().' Zuweisungen'">
                                    <div class="rt-ops-panel__list">
                                        @forelse($selectedAssignments as $assignment)
                                            @php $assignmentValue = $assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status; @endphp
                                            <div class="flex min-h-16 items-center gap-3 px-3.5 py-2.5" wire:key="shift-assignment-{{ $assignment->id }}">
                                                <div class="min-w-0 flex-1">
                                                    @if($assignment->user)<x-user.person-anchor-preview :user="$assignment->user" :show-presence="false" :show-email="false" :size="9" />@else<span class="ops-muted">Unbekannter Mitarbeiter</span>@endif
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
                                </x-operations.panel.group>
                            @endif
                            @if($nativeOperations && $detailOpen && \App\Support\Operations\WorkforcePlanningSchema::ready())
                                <div class="ops-actions" aria-label="Planungsvarianten"><livewire:operations.plan-variants :shift-ids="[$selectedShift->id]" :key="'variants-'.$selectedShift->id" /></div>
                            @endif
                            @if($selectedShiftStatus === 'cancelled')
                                <x-operations.panel.group title="Schicht storniert">
                                    <p class="rt-ops-panel__text">Für eine stornierte Schicht können keine weiteren Mitarbeitenden reserviert werden.</p>
                                </x-operations.panel.group>
                            @else
                                <x-operations.panel.group title="Mitarbeiter zuweisen">
                                    <div class="space-y-3">
                                        @if($nativeOperations && $candidates)
                                            <x-tables.search-field wire:model.live.debounce.300ms="candidateSearch" placeholder="Mitarbeiter suchen" aria-label="Kandidaten suchen" />
                                            <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Eignung','key'=>'eligibility'],['label'=>'Auswahl','key'=>'action']]" :items="$candidates" row-view="components.tables.rows.operations.candidate" empty="Keine Mitarbeiter gefunden." />
                                            {{ $candidates->links() }}
                                        @endif
                                        <div class="rt-ops-panel__fields">
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
                                            <div class="rt-ops-panel__field--wide">
                                                <x-ui.forms.label for="assignment-note" value="Hinweis (optional)" />
                                                <x-ui.forms.input id="assignment-note" wire:model="assignmentNote" class="mt-1" />
                                                @error('assignmentNote') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                            </div>
                                        </div>
                                        @error('assignment') <p class="rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">{{ $message }}</p> @enderror
                                        <x-ui.buttons.button-basic type="button" mode="primary" wire:click="assignEmployee" wire:loading.attr="disabled">
                                            <i wire:loading.remove wire:target="assignEmployee" class="far fa-user-plus" aria-hidden="true"></i>
                                            <i wire:loading wire:target="assignEmployee" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                                            Zuweisen
                                        </x-ui.buttons.button-basic>
                                    </div>
                                </x-operations.panel.group>
                            @endif
                        </x-operations.panel.tab>

                        @if($nativeOperations)
                            @if(\App\Support\Operations\PlanningSchema::ready())
                                <x-operations.panel.tab name="activity" :id-prefix="$detailIdPrefix">
                                    @if($detailOpen)<livewire:operations.duty-activity :shift-id="$selectedShift->id" :key="'duty-'.$selectedShift->id" />@endif
                                </x-operations.panel.tab>
                            @endif
                        @endif
                    @else
                        <div class="flex min-h-80 flex-col items-center justify-center text-center">
                            <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                            <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Schicht auswählen</h2>
                            <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle eine Schicht aus, um die Besetzung zu planen.</p>
                        </div>
                    @endif
                </div>
            </div>

            <x-operations.panel.footer x-show="!loading && !error">
                @if($selectedShift && $nativeOperations)
                    <x-slot:note><i class="far fa-code-branch" aria-hidden="true"></i>Revision {{ $selectedShift->revision }} · {{ $shiftPublished ? 'veröffentlicht' : ($selectedShift->published_revision ? 'veröffentlicht ist Revision '.$selectedShift->published_revision : 'noch nicht veröffentlicht') }}</x-slot:note>
                @endif
                <kbd class="rt-ops-panel__kbd" title="Mit Esc schließen">Esc</kbd>
                <x-ui.buttons.button-basic type="button" x-on:click="$dispatch('close')">Schließen</x-ui.buttons.button-basic>
            </x-operations.panel.footer>
        </div>

        {{-- Zustand 2 · Schichtformular (Anlegen/Bearbeiten) --}}
        @if($formOpen)
            @php
                $formFieldTabs = [
                    'service' => ['orderId', 'title', 'roleName', 'requiredStaff', 'status', 'notes'],
                    'time' => ['startsAt', 'endsAt', 'timezone', 'plannedBreakMinutes', 'locationName'],
                    'proofs' => ['qualificationIds', 'qualificationIds.*'],
                ];
                $formTabs = [
                    'service' => ['label' => 'Dienst', 'alert' => $errors->hasAny($formFieldTabs['service'])],
                    'time' => ['label' => 'Zeit & Ort', 'alert' => $errors->hasAny($formFieldTabs['time'])],
                ];
                if ($nativeOperations) {
                    $formTabs['proofs'] = ['label' => 'Nachweise', 'count' => count($qualificationIds), 'countExpression' => '($wire.qualificationIds || []).length', 'alert' => $errors->hasAny($formFieldTabs['proofs'])];
                }
                $firstErrorTab = collect($formTabs)->filter(fn (array $tab): bool => (bool) ($tab['alert'] ?? false))->keys()->first();
                // Fehler ohne eigenes Feld (Planungsregeln, Zeitkonflikte, parallele Änderungen)
                // erschienen bisher nirgends im Formular – jetzt oben im Rumpf.
                $assignmentErrorKeys = ['assignment', 'employeeId', 'assignmentStatus', 'assignmentNote'];
                $generalFormErrors = collect($errors->getMessages())
                    ->reject(fn ($messages, $key) => in_array($key, array_merge($formFieldTabs['service'], $formFieldTabs['time'], $assignmentErrorKeys), true) || str_starts_with((string) $key, 'qualificationIds'))
                    ->flatten()->unique()->values();
                $editingOrder = $orderId ? $orders->firstWhere('id', $orderId) : null;
            @endphp
            <div class="rt-ops-panel__mode" data-panel-mode="form" x-data="{ tab: 'service' }" wire:key="shift-panel-form-{{ $editingShiftId ?? 'new' }}">
                <div class="rt-ops-panel__accent" aria-hidden="true"></div>
                <x-operations.panel.header
                    :eyebrow="$editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht'"
                    :title="$editingShiftId ? (filled($title) ? $title : 'Schicht') : 'Neue Schicht anlegen'"
                    :subtitle="collect([$editingOrder ? $editingOrder->order_number.' · '.$editingOrder->title : null, $editingShiftId && $editingRevision ? 'Revision '.$editingRevision : null])->filter()->implode(' · ')"
                    :icon="$editingShiftId ? 'fa-pen' : 'fa-calendar-plus'"
                    :close-action="$detailOpen ? 'closeShiftForm' : null"
                    :close-label="$editingShiftId ? 'Bearbeiten abbrechen' : 'Anlegen abbrechen'" />
                <x-operations.panel.tabs :tabs="$formTabs" label="Bereiche des Schichtformulars" :id-prefix="$formIdPrefix" />

                <div class="rt-ops-panel__body" data-shift-form-body>
                    @if($firstErrorTab)
                        {{-- Bei jedem neuen Fehlerbild neu eingefügt: Alpine führt x-init aus und
                             wechselt zum ersten Reiter, der ein fehlerhaftes Feld enthält. --}}
                        <span hidden wire:key="shift-form-errors-{{ md5(implode('|', $errors->keys())) }}" x-init="tab = @js($firstErrorTab)"></span>
                    @endif
                    @if($generalFormErrors->isNotEmpty())
                        <x-operations.panel.group tone="critical" role="alert" data-shift-form-errors>
                            <div class="rt-ops-panel__notice">
                                <i class="far fa-exclamation-circle" aria-hidden="true"></i>
                                <ul class="rt-ops-panel__errors">
                                    @foreach($generalFormErrors as $message)<li>{{ $message }}</li>@endforeach
                                </ul>
                            </div>
                        </x-operations.panel.group>
                    @endif

                    <x-operations.panel.tab name="service" :id-prefix="$formIdPrefix">
                        <x-operations.panel.group title="Auftrag & Tätigkeit">
                            <div class="rt-ops-panel__fields">
                                <div class="rt-ops-panel__field--wide">
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
                            </div>
                        </x-operations.panel.group>
                        <x-operations.panel.group title="Umfang & Status">
                            <div class="rt-ops-panel__fields">
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
                            </div>
                        </x-operations.panel.group>
                        <x-operations.panel.group>
                            <x-ui.forms.label for="shift-notes" value="Interne Notizen" />
                            <x-ui.forms.textarea id="shift-notes" wire:model="notes" rows="4" class="mt-1" />
                            <p class="rt-ops-panel__help">Nur in der Disposition sichtbar.</p>
                            @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </x-operations.panel.group>
                    </x-operations.panel.tab>

                    <x-operations.panel.tab name="time" :id-prefix="$formIdPrefix">
                        <x-operations.panel.group title="Zeitfenster" :meta="$timezone">
                            <div class="rt-ops-panel__fields">
                                <div>
                                    <x-ui.forms.label for="shift-start" value="Beginn" />
                                    <x-ui.forms.date-time-field id="shift-start" wire:model="startsAt" :aria-label="'Beginn ('.$timezone.')'" class="mt-1" required />
                                    @error('startsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <x-ui.forms.label for="shift-end" value="Ende" />
                                    <x-ui.forms.date-time-field id="shift-end" wire:model="endsAt" :aria-label="'Ende ('.$timezone.')'" class="mt-1" required />
                                    @error('endsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                                @if($nativeOperations)
                                    <x-operations.field label="Geplante Pause (min)" model="plannedBreakMinutes" type="number" min="0" max="1439" />
                                @endif
                            </div>
                            @error('timezone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </x-operations.panel.group>
                        <x-operations.panel.group>
                            <x-ui.forms.label for="shift-location" value="Einsatzort" />
                            <x-ui.forms.input id="shift-location" wire:model="locationName" class="mt-1" />
                            <p class="rt-ops-panel__help">Leer lassen, um den Einsatzort der Leistung zu übernehmen.</p>
                            @error('locationName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </x-operations.panel.group>
                    </x-operations.panel.tab>

                    @if($nativeOperations)
                        <x-operations.panel.tab name="proofs" :id-prefix="$formIdPrefix">
                            <x-operations.panel.group title="Erforderliche Nachweise">
                                <p class="rt-ops-panel__help">Bei der Zuweisung wird geprüft, ob Mitarbeitende für jeden gewählten Nachweis einen gültigen Nachweis haben.</p>
                                <fieldset class="rt-ops-panel__choices">
                                    <legend class="sr-only">Erforderliche Nachweise</legend>
                                    @forelse($qualificationTypes as $type)
                                        <x-ui.forms.checkbox wire:model="qualificationIds" value="{{ $type->id }}" :label="$type->name" />
                                    @empty
                                        <p class="rt-ops-panel__help">Noch keine Nachweisarten angelegt.</p>
                                    @endforelse
                                </fieldset>
                                @error('qualificationIds') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </x-operations.panel.group>
                        </x-operations.panel.tab>
                    @endif
                </div>

                <x-operations.panel.footer>
                    @if($nativeOperations && $editingShiftId)
                        <x-slot:note><i class="far fa-info-circle" aria-hidden="true"></i>Geänderte Angaben werden als neue Revision gespeichert und erreichen Eingeteilte erst nach dem Veröffentlichen.</x-slot:note>
                    @endif
                    @if($detailOpen)
                        <x-ui.buttons.button-basic type="button" wire:click="closeShiftForm" wire:loading.attr="disabled" wire:target="closeShiftForm,saveShift">Abbrechen</x-ui.buttons.button-basic>
                    @else
                        <x-ui.buttons.button-basic type="button" x-on:click="$dispatch('close')">Abbrechen</x-ui.buttons.button-basic>
                    @endif
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="saveShift" wire:loading.attr="disabled" wire:target="saveShift,closeShiftForm">
                        <i wire:loading.remove wire:target="saveShift" class="far fa-check" aria-hidden="true"></i>
                        <i wire:loading wire:target="saveShift" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                        Speichern
                    </x-ui.buttons.button-basic>
                </x-operations.panel.footer>
            </div>
        @endif
    </x-operations.panel>
</div>
