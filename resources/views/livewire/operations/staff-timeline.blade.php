<section class="rt-staff-timeline-layout min-w-0" aria-label="Mitarbeiter-Zeitleiste"
    x-data="rtTimelinePlanning(@js($this->getId()))" x-on:operations-plan-changed.window="invalidate()"
    x-on:resize.window="refreshFocusPreview()"
    x-on:operations-timeline-window-reset.window="if ($event.detail.timelineId === @js($this->getId())) invalidate()"
    x-on:operations-timeline-window-pending.window="if ($event.detail.timelineId === @js($this->getId())) invalidate()">
@php
    $windowNavigation = (bool) ($periodNavigationOwner && $planningEnabled && !$absencesOnly);
    $focusStart = $focus['shift']?->starts_at->copy()->setTimezone($zone);
    $focusEnd = $focus['shift']?->ends_at->copy()->setTimezone($zone);
@endphp
@if($planningEnabled && !$absencesOnly && $showSuggestions && !$suggestionsReady)
    {{-- Plan zuerst, Besetzungsvorschläge im Folgeaufruf --}}
    <span hidden wire:key="timeline-suggestions-load-{{ $previewVersion }}-{{ $from }}-{{ $until }}" wire:init="loadSuggestions" data-timeline-suggestions-pending></span>
@endif
@if(!$absencesOnly)
    @if($searchInHeader)
        <template x-teleport="[data-topbar-page-search]" wire:key="timeline-topbar-search-{{ $this->getId() }}">
            <x-tables.search-field context="page-topbar" wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" aria-label="Mitarbeiter suchen" />
        </template>
    @else
        <x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" />
    @endif
@endif
<div class="rt-personnel-timeline" style="--timeline-days:{{ $days->count() }};--timeline-selected-days:{{ $selectedDays->count() }}" x-data="rtStaffTimeline" x-effect="applyPersonnelMode()"
    x-on:operations-timeline-distribution-focus.window="requestDistributionFocus($event.detail)"
    data-no-sidebar-swipe data-rt-dropdown-scroll-root data-timeline-motion="{{ $planningEnabled && !$absencesOnly ? 'true' : 'false' }}"
    data-timeline-window-navigation="{{ $periodNavigationOwner && $planningEnabled && !$absencesOnly ? 'true' : 'false' }}"
    data-timeline-period-owner="{{ $periodNavigationOwner }}" data-timeline-from="{{ $from }}" data-timeline-until="{{ $until }}"
    data-timeline-id="{{ $this->getId() }}" data-timeline-focus-date="{{ $focus['date'] }}" data-timeline-focus-shift-id="{{ $focus['shift']?->id }}"
    data-timeline-axis-from="{{ $axisFrom->toDateString() }}" data-timeline-axis-until="{{ $axisUntil->subDay()->toDateString() }}"
    data-timeline-selected-days="{{ $selectedDays->count() }}"
    data-timeline-day-attention="{{ json_encode($dayAttention, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}"
    data-timeline-buffer-before="{{ (int) $axisFrom->diffInDays($selectedFrom) }}" data-timeline-buffer-after="{{ (int) $selectedUntil->diffInDays($axisUntil) }}"
    x-bind:style="{ '--timeline-display-days': windowDisplayDayCount, '--timeline-selected-days': windowDisplaySelectedDayCount, '--timeline-content-offset': windowContentOffset }"
    x-bind:data-content-loading="windowContentLoading ? 'true' : 'false'"
    x-on:operations-timeline-period-request.window="requestWindowPeriod($event)"
    x-bind:aria-busy="windowContentLoading ? 'true' : 'false'">
    <div class="rt-timeline-window-feedback" x-show="windowError" x-cloak role="status" aria-live="polite">
        <span x-text="windowError"></span>
        <button type="button" x-on:click="retryWindow()" class="rt-timeline-window-retry">Erneut laden</button>
    </div>
    <div class="rt-timeline-loading-indicator" style="display: none" wire:loading.delay.flex wire:target="search,loadMore,refreshPlanning,loadSuggestions" role="status" aria-live="polite">
        <i class="far fa-spinner-third" aria-hidden="true"></i><span>Lädt …</span>
    </div>
    <div class="rt-personnel-timeline-header">
        <div class="rt-personnel-timeline-name rt-personnel-timeline-head rt-personnel-timeline-person-heading" data-timeline-person-column>
            <button type="button" class="rt-personnel-timeline-person-toggle" data-timeline-person-toggle x-on:click="togglePersonnelColumn()"
                x-bind:aria-expanded="personnelCompact ? 'false' : 'true'"
                x-bind:aria-label="personnelCompact ? 'Mitarbeiterspalte erweitern' : 'Mitarbeiterspalte kompakt anzeigen'"
                x-bind:title="personnelCompact ? 'Mitarbeiterspalte erweitern' : 'Mitarbeiterspalte kompakt anzeigen'"
                aria-label="Mitarbeiterspalte kompakt anzeigen" aria-expanded="true">
                <i class="far fa-users" aria-hidden="true"></i><span class="rt-personnel-timeline-person-label">Mitarbeiter</span>
                <span class="rt-personnel-timeline-person-cue" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" focusable="false"><path d="m6 4 4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg></span>
            </button>
        </div>
        <div class="rt-personnel-timeline-header-viewport">
        <div class="rt-personnel-timeline-header-scroll" x-ref="timelineHeader">
            <div class="rt-timeline-header-axis">
            @if($windowNavigation)
            {{-- The date structure belongs to the browser; only confirmed contents depend on Livewire. --}}
            <div class="rt-personnel-timeline-header-days" wire:ignore data-timeline-scaffold-header>
                <template x-for="day in windowScaffoldDays" :key="day.date">
                    <div class="rt-personnel-timeline-head" x-bind:class="{ 'rt-personnel-timeline-head--weekend': day.weekend }" x-bind:data-timeline-date="day.date" x-bind:data-focus="day.focus ? 'true' : 'false'" x-bind:data-pending="day.pending ? 'true' : 'false'" x-bind:role="day.pending ? 'status' : null" aria-live="polite">
                        <span class="rt-personnel-timeline-date-row">
                            <span class="rt-personnel-timeline-date"><span x-text="day.label"></span><i class="far fa-spinner-third fa-spin rt-timeline-day-spinner" x-show="day.pending && !windowError" aria-hidden="true"></i></span>
                            <span class="rt-timeline-day-attention" x-cloak x-show="!day.pending && day.attention?.shifts > 0" x-bind:title="day.attentionLabel" x-bind:aria-label="day.attentionLabel">
                                <span class="rt-timeline-day-attention__dot" aria-hidden="true"></span><span x-text="day.attention?.shifts" aria-hidden="true"></span><span class="rt-timeline-day-attention__caption" aria-hidden="true">offen</span>
                            </span>
                        </span>
                        <span class="sr-only" x-show="day.pending" x-text="'Daten für ' + day.label + (windowError ? ' konnten nicht geladen werden.' : ' werden geladen.')"></span>
                        <span class="rt-personnel-timeline-hours" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></span>
                    </div>
                </template>
            </div>
            @else
            <div class="rt-personnel-timeline-header-days">
                @foreach($days as $day)
                    <div @class(['rt-personnel-timeline-head', 'rt-personnel-timeline-head--weekend' => $day->isWeekend()]) data-timeline-date="{{ $day->toDateString() }}" wire:key="staff-head-{{ $day->toDateString() }}" data-focus="{{ $focus['date'] === $day->toDateString() ? 'true' : 'false' }}">
                        <span class="rt-personnel-timeline-date-row">
                            <span class="rt-personnel-timeline-date">{{ $day->locale('de')->translatedFormat('D, d.m.') }}</span>
                            @if(($dayAttention[$day->toDateString()]['shifts'] ?? 0) > 0)
                                @php
                                    $attention = $dayAttention[$day->toDateString()];
                                @endphp
                                <span class="rt-timeline-day-attention" title="{{ $attention['shifts'] }} Schichten mit Bearbeitungsbedarf · {{ $attention['open_places'] }} offene Plätze · {{ $attention['requested_places'] }} ausstehende Zusagen" aria-label="{{ $attention['shifts'] }} Schichten mit Bearbeitungsbedarf · {{ $attention['open_places'] }} offene Plätze · {{ $attention['requested_places'] }} ausstehende Zusagen"><span class="rt-timeline-day-attention__dot" aria-hidden="true"></span>{{ $attention['shifts'] }}<span class="rt-timeline-day-attention__caption">offen</span></span>
                            @endif
                        </span>
                        <span class="rt-personnel-timeline-hours" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></span>
                    </div>
                @endforeach
            </div>
            @endif
            </div>
        </div>
        <button type="button" class="rt-personnel-timeline-direction rt-personnel-timeline-direction--previous" x-cloak x-show="canScrollLeft || windowNavigationEnabled" x-on:click="scrollDay(-1)" aria-label="Weitere Tage links anzeigen" title="Weitere Tage links"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
        <button type="button" class="rt-personnel-timeline-direction rt-personnel-timeline-direction--next" x-cloak x-show="canScrollRight || windowNavigationEnabled" x-on:click="scrollDay(1)" aria-label="Weitere Tage rechts anzeigen" title="Weitere Tage rechts"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="rt-personnel-timeline-body snap-x snap-mandatory" x-ref="timelineBody" x-on:pointerdown="cancelDistributionFocus()" x-on:keydown="cancelDistributionFocus()" x-on:scroll.passive="syncHorizontal($event.target); refreshFocusPreview()" x-on:wheel="wheelPersonnel($event)" x-on:scrollend.passive="finishHorizontalIntent()" x-on:keydown.left="if ($event.target === $event.currentTarget) { $event.preventDefault(); scrollDay(-1); }" x-on:keydown.right="if ($event.target === $event.currentTarget) { $event.preventDefault(); scrollDay(1); }" tabindex="0" role="region" aria-label="Zeitfenster nach Mitarbeiter, vertikal scrollbar; weitere Tage über die Richtungspfeile, Pfeiltasten oder horizontales Scrollen">
    <div class="rt-personnel-timeline-grid" x-ref="timelineGrid"
        x-on:click="if (!windowContentLoading) openPlanner($event)" x-on:pointerover="if (!windowContentLoading) hoverCell($event)" x-on:pointerout="leaveCell($event)"
        x-on:focusin="if (!windowContentLoading) hoverCell($event)" x-on:focusout="leaveCell($event)">
    @if($windowNavigation)
        <div class="rt-timeline-scaffold-columns" wire:ignore aria-hidden="true" data-timeline-scaffold-body>
            <template x-for="day in windowScaffoldDays" :key="day.date">
                <div class="rt-timeline-scaffold-day" x-bind:class="{ 'rt-personnel-timeline-day--weekend': day.weekend }" x-bind:data-timeline-date="day.date" x-bind:data-pending="day.pending ? 'true' : 'false'"></div>
            </template>
        </div>
    @endif
    @forelse($rows as $row)
        <div class="rt-personnel-timeline-name min-w-0" data-timeline-person-column wire:key="staff-person-{{ $row['user']->id }}" @if($focus['shift']) data-focus-fit="{{ $focus['candidates']->has($row['user']->id) ? 'eligible' : 'blocked' }}" @endif>
            <div class="rt-timeline-person-summary">
            <x-user.person-anchor-preview :user="$row['user']" trigger-classes="flex min-w-0 w-full">
                <x-slot:trigger>
                    <button type="button" class="min-h-11 min-w-0 w-full rounded-lg text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent" aria-label="{{ __('app.open_person_preview') }}: {{ $row['user']->name }}" title="{{ $row['user']->name }}">
                                    <x-user.public-info :user="$row['user']" :size="6" :show-email="false" :show-presence="false" name-format="initial-surname" class="rt-timeline-person-identity" />
                    </button>
                </x-slot:trigger>
            </x-user.person-anchor-preview>
            @if($workloads->has($row['user']->id))
                @include('livewire.operations.partials.timeline-workload', ['workload' => $workloads->get($row['user']->id), 'person' => $row['user']])
            @endif
            </div>
            @if(!$row['user']->status)<span class="ops-muted rt-timeline-person-status">Inaktiv</span>@endif
        </div>
        <div class="rt-personnel-timeline-track" data-timeline-lanes="{{ $row['lane_count'] }}" data-timeline-density="{{ $row['lane_count'] > 1 ? 'compact' : 'normal' }}" style="--timeline-lanes:{{ $row['lane_count'] }}" wire:key="staff-track-{{ $row['user']->id }}">
        @if($windowNavigation)
        <div class="rt-timeline-loaded-track">
        @endif
        @foreach($row['days'] as $cell)
            @php
                $focusEligible = $focus['event'] && $focus['candidates']->has($row['user']->id)
                    && $cell['date']->lt($focusEnd) && $cell['date']->addDay()->gt($focusStart);
            @endphp
            <div @class(['rt-personnel-timeline-day snap-start', 'rt-personnel-timeline-day--weekend' => $cell['date']->isWeekend(), 'rt-personnel-timeline-day--empty' => $cell['events']->isEmpty()]) data-timeline-date="{{ $cell['date']->toDateString() }}" @if($focusEligible) data-focus-fit="eligible" @endif wire:key="staff-day-{{ $row['user']->id }}-{{ $cell['date']->toDateString() }}">
                @if($focusEligible)
                    {{-- Die ganze passende Zelle ist das Ziel; die Zeitvorschau bleibt bis Hover/Fokus verborgen. --}}
                    <button type="button" class="rt-timeline-cell-action rt-timeline-focus-target" x-bind:disabled="windowContentLoading"
                        data-timeline-proposal data-focus="true" data-user="{{ $row['user']->id }}" data-date="{{ $cell['date']->toDateString() }}" data-shift="{{ $focus['shift']->id }}" data-revision="{{ $focus['shift']->revision }}"
                        aria-haspopup="dialog" aria-label="{{ $row['user']->name }} für {{ $focus['shift']->title }} einteilen · {{ $focus['event']['timeline_label'] }}" title="{{ collect($focus['candidates']->get($row['user']->id)['reasons'] ?? [])->implode(' · ') }}"></button>
                @elseif($planningEnabled && !$absencesOnly && $row['user']->status && $cell['date']->gte($selectedFrom) && $cell['date']->lt($selectedUntil))
                    @php
                        $cellStatusId = 'timeline-cell-status-'.$this->getId().'-'.$row['user']->id.'-'.$cell['date']->toDateString();
                    @endphp
                    <button type="button" class="rt-timeline-cell-action" data-user="{{ $row['user']->id }}" data-date="{{ $cell['date']->toDateString() }}" x-bind:disabled="windowContentLoading" aria-haspopup="dialog" aria-label="Offene Schicht auswählen: {{ $row['user']->name }} · {{ $cell['date']->format('d.m.Y') }}" aria-describedby="{{ $cellStatusId }}" data-timeline-cell-action>
                        <span class="rt-timeline-cell-status" aria-hidden="true"><i class="far fa-circle" data-timeline-cell-status-icon></i></span>
                        <span id="{{ $cellStatusId }}" class="sr-only" data-timeline-cell-status-text></span>
                    </button>
                @endif
            </div>
        @endforeach
        <div class="rt-personnel-timeline-events">
            @foreach($row['events'] as $event)
                @php
                    $state = in_array($event['shift_status'] ?? null, ['in_progress', 'completed'], true) ? $event['shift_status'] : $event['status_value'];
                    $eventKey = $row['user']->id.'-'.$event['id'];
                    $detailFacts = \App\Support\Operations\TimelineEventDetail::forEvent($event, $row, $zone, $absencesOnly, $state);
                @endphp
                <div class="rt-personnel-timeline-event" data-kind="{{ $event['kind'] }}" data-state="{{ $state }}" data-continues-before="{{ $event['continues_before'] ? 'true' : 'false' }}" data-continues-after="{{ $event['continues_after'] ? 'true' : 'false' }}" data-time-start="{{ $event['left_percent'] }}" data-time-width="{{ $event['width_percent'] }}" data-time-lane="{{ $event['lane'] }}" style="--event-left:{{ $event['left_percent'] }}%;--event-width:{{ $event['width_percent'] }}%;--event-lane:{{ $event['lane'] }}" wire:key="staff-event-{{ $eventKey }}">
                            <button type="button" class="rt-personnel-timeline-bar" aria-label="{{ $event['local_label'] }} · {{ $event['title'] }} · {{ $event['status'] }}" aria-expanded="false" aria-haspopup="dialog" aria-controls="rt-dropdown-timeline-event-{{ $this->getId() }}-content"
                                data-timeline-detail="{{ json_encode($detailFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}"
                                x-on:click="$dispatch('rt-timeline-event-preview', { anchor: $el })" data-table-row-ignore>
                                <span class="rt-personnel-timeline-time"><span class="rt-personnel-timeline-time-text">{{ $event['timeline_label'] }}</span></span>
                                <span class="rt-personnel-timeline-duration-clip" aria-hidden="true">
                                    @foreach($event['time_segments'] as $segment)
                                        <span class="rt-personnel-timeline-mark" style="left:{{ ($segment['left_percent'] - $event['left_percent']) / $event['width_percent'] * 100 }}%;width:{{ $segment['width_percent'] / $event['width_percent'] * 100 }}%" aria-hidden="true"></span>
                                    @endforeach
                                </span>
                            </button>
                </div>
            @endforeach
        </div>
        @if($focus['event'] && $focus['candidates']->has($row['user']->id))
            {{-- Eine rein visuelle Vorschau über der Zeitachse, ohne zweite Klickfläche oder eigene Lane. --}}
            <div class="rt-timeline-focus" aria-hidden="true" data-time-start="{{ $focus['event']['left_percent'] }}" data-time-width="{{ $focus['event']['width_percent'] }}" wire:key="staff-focus-{{ $row['user']->id }}-{{ $focus['shift']->id }}" style="--time-label-offset:clamp(55px, {{ $focus['event']['left_percent'] + $focus['event']['width_percent'] / 2 }}%, calc(100% - 55px));--time-badge-width:max(110px, {{ $focus['event']['width_percent'] }}%)">
                <span class="rt-personnel-timeline-time rt-timeline-focus-preview">
                    <span class="rt-personnel-timeline-time-text">{{ $focus['event']['timeline_label'] }}</span>
                </span>
                <span class="rt-personnel-timeline-duration-clip">
                    @foreach($focus['event']['time_segments'] as $segment)
                        <span class="rt-timeline-focus-duration" style="left:{{ $segment['left_percent'] }}%;width:{{ $segment['width_percent'] }}%"></span>
                    @endforeach
                </span>
            </div>
        @endif
        @if($planningEnabled && !$absencesOnly && $showSuggestions)
            <div class="rt-timeline-proposals" aria-label="Unverbindliche Besetzungsvorschläge" wire:key="staff-proposals-{{ $row['user']->id }}">
                @foreach($proposalRows->get($row['user']->id,collect()) as $proposal)
                    @continue($focus['shift'] && $proposal['shift_id'] === $focus['shift']->id)
                    <div class="rt-timeline-proposal-shell" style="--proposal-left:{{ $proposal['left_percent'] }}%;--proposal-center:{{ $proposal['left_percent'] + ($proposal['width_percent'] / 2) }}%;--proposal-width:{{ $proposal['width_percent'] }}%;--proposal-duration-width:calc(var(--timeline-days) * var(--timeline-day-width) * {{ $proposal['width_percent'] / 100 }})" data-fit="{{ $proposal['fit'] }}" wire:key="{{ $proposal['id'] }}">
                        <button type="button" class="rt-timeline-proposal" data-user="{{ $proposal['user_id'] }}" data-shift="{{ $proposal['shift_id'] }}" data-revision="{{ $proposal['revision'] }}" x-bind:disabled="windowContentLoading" data-fit="{{ $proposal['fit'] }}" data-urgency="{{ $proposal['urgency'] }}" aria-haspopup="dialog" aria-label="Vorschlag prüfen: {{ $row['user']->name }} · {{ $proposal['title'] }} · {{ $proposal['local_label'] }} · {{ $proposal['fit_label'] }} · {{ $proposal['urgency_label'] }}" title="{{ $proposal['fit_label'] }} · {{ $proposal['urgency_label'] }} · {{ $proposal['title'] }} · {{ implode(' · ',$proposal['reasons']) }}" data-timeline-proposal>
                            <i class="far {{ $proposal['fit'] === 'review' ? 'fa-exclamation-circle' : ($proposal['fit'] === 'preferred' ? 'fa-check-circle' : 'fa-lightbulb') }}" aria-hidden="true"></i>
                            <span>{{ $proposal['timeline_label'] }}</span>
                            @if($proposal['urgency'] !== 'normal')<i class="far fa-clock rt-timeline-proposal__urgency" aria-hidden="true"></i>@endif
                            <span class="rt-timeline-proposal-duration" aria-hidden="true"></span>
                        </button>
                        <div class="rt-timeline-proposal-ping animate-ping motion-reduce:animate-none" aria-hidden="true"></div>
                    </div>
                @endforeach
            </div>
        @endif
        @if($windowNavigation)
        </div>
        @endif
        </div>
    @empty<div class="rt-personnel-timeline-empty">Keine Mitarbeiter gefunden.</div>@endforelse
    @if($users->hasMorePages())
        <div class="rt-personnel-timeline-load-more" style="grid-column: 1 / -1" wire:key="staff-timeline-load-more-{{ $this->getPage('staffPage') }}" x-data x-intersect.once="$wire.loadMore()" aria-live="polite" role="status">
            <span wire:loading.remove wire:target="loadMore">Weitere Mitarbeitende laden</span>
            <span wire:loading wire:target="loadMore">Mitarbeitende werden geladen …</span>
        </div>
    @endif
    </div>
    </div>
    <div class="rt-personnel-timeline-scrollbar-row" aria-hidden="true">
        <span class="rt-personnel-timeline-scrollbar-corner"></span>
        <div class="rt-personnel-timeline-scrollbar" x-ref="timelineScrollbar" x-on:scroll="syncHorizontal($event.target)"><div class="rt-personnel-timeline-scrollbar-width"></div></div>
    </div>
</div>
    @include('livewire.operations.partials.timeline-shared-event-detail')
@if($planningEnabled && !$absencesOnly)
    <x-ui.dropdown.anchor-dropdown align="left" width="96" :external-trigger="true" dropdown-id="timeline-planner-{{ $this->getId() }}" layer-group="staff-timeline-events" content-role="dialog" content-label="Schicht einteilen" trigger-classes="hidden" class="rt-timeline-planner-host" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text" x-on:dropdown-closed.window="if ($event.detail?.id === layerId) closePlanner()" data-timeline-planner>
        <x-slot:trigger><button type="button" tabindex="-1" aria-hidden="true">Schichtauswahl</button></x-slot:trigger>
        <x-slot:content>
        <div class="rt-timeline-planner" data-rt-dropdown-keep-open x-bind:aria-busy="plannerLoading">
        <header class="rt-timeline-planner__header">
            <strong tabindex="-1" data-dropdown-initial-focus>Schicht einteilen</strong>
            <button type="button" class="rt-timeline-planner__close" x-on:click="close(true)" aria-label="Schichtauswahl schließen" title="Schließen"><i class="far fa-times" aria-hidden="true"></i></button>
        </header>
        <div x-cloak x-show.important="plannerLoading" role="status" aria-live="polite" class="rt-timeline-planner__loading">
            <i class="far fa-spinner-third" aria-hidden="true"></i>
            <strong>Schichten werden geladen</strong>
            <span>Verfügbarkeit wird geprüft …</span>
        </div>
        <p x-cloak x-show.important="plannerError" x-text="plannerError" role="alert" class="rt-timeline-planner__message"></p>
        <div x-cloak x-show.important="plannerReady && !plannerLoading && !plannerError" class="rt-timeline-planner__results" data-timeline-planner-results>
        @if($workflowError = $this->getErrorBag()->first('workflow'))<p role="alert" class="rt-timeline-planner__error">{{ $workflowError }}</p>@endif
        @if($assignmentOpen)
        @if($planningUser)
            <div class="rt-timeline-planner__person">
                <x-user.public-info :user="$planningUser" :show-email="false" :show-presence="false" />
            </div>
        @endif
        @if($planningShift)
            @php
                $planningTimeFormat = $planningShift->starts_at->setTimezone($zone)->offset !== $planningShift->ends_at->setTimezone($zone)->offset ? 'd.m. H:i P' : 'd.m. H:i';
                $planningStatusHintId = 'timeline-assignment-hint-'.$this->getId();
            @endphp
            <section class="rt-timeline-planner__shift" aria-label="Gewählte Schicht">
                <h3>{{ $planningShift->title }}</h3>
                <dl class="rt-timeline-planner__facts">
                    <div class="rt-timeline-planner__period"><dt class="sr-only">Zeitraum</dt><dd><i class="far fa-clock" aria-hidden="true"></i><span>{{ $planningShift->starts_at->setTimezone($zone)->format($planningTimeFormat) }} – {{ $planningShift->ends_at->setTimezone($zone)->format($planningTimeFormat) }}</span></dd></div>
                    @if(filled($planningShift->order?->customer?->company_name))<div><dt>Kunde</dt><dd>{{ $planningShift->order->customer->company_name }}</dd></div>@endif
                </dl>
            </section>
            @if($planningReasons)
                <section class="rt-timeline-planner__reasons" aria-label="Prüfungshinweise">
                    <span class="rt-timeline-planner__eyebrow">Prüfungshinweise</span>
                    <ul>@foreach($planningReasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                </section>
            @endif
            <form wire:submit="confirmAssignment" class="rt-timeline-planner__form">
                @if(\App\Support\Routing\TomTomSettings::all()['enabled'] && $planningUser)
                    @php($timelineTravelInitial = $planningTravel[$planningShift->id] ?? [])
                    @if(!$timelineTravelInitial)<div x-data x-intersect.once="$wire.loadPlanningTravel({{ $planningShift->id }})" wire:key="timeline-travel-load-{{ $planningShift->id }}-{{ $planningUser->id }}-{{ $planningShift->revision }}" role="status">Fahrzeiten werden nachgeladen …</div>@endif
                    <livewire:operations.shift-travel-panel :shift-id="$planningShift->id" :employee-ids="[$planningUser->id]" :initial-assessments="$timelineTravelInitial ?: null" :parent-managed="true" :key="'timeline-travel-'.$planningShift->id.'-'.$planningUser->id.'-'.$planningShift->revision.'-'.md5(json_encode($timelineTravelInitial))" />
                    @if(!empty($planningAssessment['issues']))<div role="alert"><ul>@foreach($planningAssessment['issues'] as $issue)<li>{{ $issue['message'] }}</li>@endforeach</ul><button type="button" class="underline min-h-11" x-on:click="$dispatch('operations-shift-detail-request', { id: {{ $planningShift->id }} })">In Schichtdetails prüfen</button></div>@endif
                @endif
                <fieldset class="rt-timeline-planner__status" aria-describedby="{{ $planningStatusHintId }}" wire:loading.attr="disabled" wire:target="confirmAssignment,backToCellChoices">
                    <legend>Zuweisung</legend>
                    <div class="rt-timeline-planner__status-options">
                        <label class="rt-timeline-planner__status-option">
                            <input type="radio" name="timeline-assignment-status-{{ $this->getId() }}" value="requested" wire:model="assignmentStatus" @checked($assignmentStatus === 'requested') required>
                            <span><strong>Angefragt</strong><small>Zusage steht noch aus</small></span>
                        </label>
                        <label class="rt-timeline-planner__status-option">
                            <input type="radio" name="timeline-assignment-status-{{ $this->getId() }}" value="confirmed" wire:model="assignmentStatus" @checked($assignmentStatus === 'confirmed') required>
                            <span><strong>Bestätigt</strong><small>Bestätigt einplanen</small></span>
                        </label>
                    </div>
                    <p id="{{ $planningStatusHintId }}">Erst mit „Einteilen“ wird die Schicht zugewiesen.</p>
                    @if($assignmentError = $this->getErrorBag()->first('assignmentStatus'))<p role="alert" class="rt-timeline-planner__error">{{ $assignmentError }}</p>@endif
                </fieldset>
                <footer class="rt-timeline-planner__footer">
                    @if($planningDate)<x-ui.buttons.button-basic type="button" mode="secondary" class="rt-timeline-planner__back" wire:click="backToCellChoices" wire:loading.attr="disabled"><i class="far fa-arrow-left" aria-hidden="true"></i> Andere Schicht</x-ui.buttons.button-basic>@endif
                    <x-ui.buttons.button-basic type="submit" mode="primary" class="rt-timeline-planner__confirm" wire:loading.attr="disabled" :disabled="!empty($planningAssessment['issues']) ? true : null">
                        <span wire:loading.remove wire:target="confirmAssignment">Einteilen</span>
                        <span wire:loading.inline-flex wire:target="confirmAssignment"><i class="far fa-spinner-third" aria-hidden="true"></i> Wird eingeteilt</span>
                    </x-ui.buttons.button-basic>
                </footer>
            </form>
        @else
            <div class="rt-timeline-planner__choice-heading"><strong>Offene Schichten</strong>@if($planningDate)<span>{{ \Carbon\CarbonImmutable::parse($planningDate)->format('d.m.Y') }}</span>@endif</div>
            <div class="rt-timeline-choices" aria-label="Offene Schichten">
            @forelse($choices as $choice)
                <article class="rt-timeline-choice" data-fit="{{ $choice->eligible ? 'suitable' : 'blocked' }}" wire:key="timeline-choice-{{ $choice->id }}">
                    <div class="rt-timeline-choice__main"><strong>{{ $choice->title }}</strong><span class="rt-timeline-choice__period">{{ $choice->period }}</span><small class="rt-timeline-choice__places">{{ $choice->open }} {{ $choice->open === 1 ? 'Platz frei' : 'Plätze frei' }}</small><span data-routing-state="{{ $choice->travel['route_state'] }}">{{ $choice->travel['route_label'] }}</span>@foreach($choice->travel['warnings'] ?? [] as $warning)<small>{{ $warning['message'] }}</small>@endforeach</div>
                    @if($choice->eligible)
                        <x-ui.buttons.button-basic type="button" size="sm" mode="secondary" class="rt-timeline-choice__select" wire:click="selectCellShift({{ $choice->id }},{{ $choice->revision }})" wire:loading.attr="disabled" aria-label="Auswählen: {{ $choice->title }}">Auswählen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                    @else
                        <p class="rt-timeline-choice__blocked"><i class="far fa-ban" aria-hidden="true"></i> Nicht passend</p>
                        <ul class="rt-timeline-choice__issues">@foreach($choice->issues as $issue)<li>{{ $issue['message'] }}</li>@endforeach</ul>
                    @endif
                </article>
            @empty<p class="rt-timeline-planner__message">Keine offenen Schichten an diesem Tag.</p>@endforelse
            </div>
            @if($choices->count() === 30)<p class="rt-timeline-planner__limit">Erste 30 Einsätze · weitere über „Noch zu verteilen“</p>@endif
        @endif
        @endif
        </div></div>
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
@endif
</section>
