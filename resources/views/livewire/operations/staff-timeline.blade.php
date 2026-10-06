<section class="rt-staff-timeline-layout min-w-0" aria-label="Mitarbeiter-Zeitleiste"
    x-data="rtTimelinePlanning" x-on:operations-plan-changed.window="invalidate()">
@if($planningEnabled && !$absencesOnly && $searchInHeader)
    <template x-teleport="[data-shift-plan-timeline-suggestions]">
        <x-ui.buttons.button-basic type="button" size="sm" class="rt-shift-plan-control rt-timeline-suggestions-toggle" wire:click="toggleSuggestions" wire:loading.attr="disabled" wire:target="toggleSuggestions" aria-label="Besetzungsvorschläge ein-/ausblenden" aria-pressed="{{ $showSuggestions ? 'true' : 'false' }}" title="{{ $showSuggestions ? 'Besetzungsvorschläge ausblenden' : 'Besetzungsvorschläge anzeigen' }}">
            <i class="{{ $showSuggestions ? 'fas' : 'far' }} fa-lightbulb" aria-hidden="true"></i>
        </x-ui.buttons.button-basic>
    </template>
@endif
@if(!$absencesOnly)
    @if($searchInHeader)
        <template x-teleport="[data-shift-plan-timeline-search]">
            <x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" aria-label="Mitarbeiter suchen" />
        </template>
    @else
        <x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" />
    @endif
@endif
<div class="rt-personnel-timeline" style="--timeline-days:{{ $days->count() }}" x-data="rtStaffTimeline" data-no-sidebar-swipe data-rt-dropdown-scroll-root>
    <div class="rt-personnel-timeline-header">
        <div class="rt-personnel-timeline-name rt-personnel-timeline-head">Mitarbeiter</div>
        <div class="rt-personnel-timeline-header-viewport">
        <div class="rt-personnel-timeline-header-scroll" x-ref="timelineHeader">
            <div class="rt-personnel-timeline-header-days">
                @foreach($days as $day)
                    <div @class(['rt-personnel-timeline-head', 'rt-personnel-timeline-head--weekend' => $day->isWeekend()])>
                        <span class="rt-personnel-timeline-date">{{ $day->locale('de')->translatedFormat('D, d.m.') }}</span>
                        <span class="rt-personnel-timeline-hours" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></span>
                    </div>
                @endforeach
            </div>
        </div>
        <button type="button" class="rt-personnel-timeline-direction rt-personnel-timeline-direction--previous" x-cloak x-show="canScrollLeft" x-on:click="scrollDay(-1)" aria-label="Weitere Tage links anzeigen" title="Weitere Tage links"><i class="far fa-chevron-left" aria-hidden="true"></i></button>
        <button type="button" class="rt-personnel-timeline-direction rt-personnel-timeline-direction--next" x-cloak x-show="canScrollRight" x-on:click="scrollDay(1)" aria-label="Weitere Tage rechts anzeigen" title="Weitere Tage rechts"><i class="far fa-chevron-right" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="rt-personnel-timeline-body snap-x snap-mandatory" x-ref="timelineBody" x-on:scroll.passive="syncHorizontal($event.target)" tabindex="0" role="region" aria-label="Zeitfenster nach Mitarbeiter, vertikal scrollbar; weitere Tage über die Richtungspfeile oder die horizontale Bildlaufleiste">
    <div class="rt-personnel-timeline-grid" x-ref="timelineGrid"
        x-on:click="openPlanner($event)" x-on:pointerover="hoverCell($event)" x-on:pointerout="leaveCell($event)"
        x-on:focusin="hoverCell($event)" x-on:focusout="leaveCell($event)">
    @forelse($rows as $row)
        <div class="rt-personnel-timeline-name min-w-0" wire:key="staff-person-{{ $row['user']->id }}">
            <div class="rt-timeline-person-summary">
            <x-user.person-anchor-preview :user="$row['user']" trigger-classes="flex min-w-0 w-full">
                <x-slot:trigger>
                    <button type="button" class="min-h-11 min-w-0 w-full rounded-lg text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent" aria-label="{{ __('app.open_person_preview') }}: {{ $row['user']->name }}" title="{{ $row['user']->name }}">
                                    <x-user.public-info :user="$row['user']" :size="6" :show-email="false" :show-presence="false" name-format="initial-surname" />
                    </button>
                </x-slot:trigger>
            </x-user.person-anchor-preview>
            @if($workloads->has($row['user']->id))
                @include('livewire.operations.partials.timeline-workload', ['workload' => $workloads->get($row['user']->id), 'person' => $row['user']])
            @endif
            </div>
            @if(!$row['user']->status)<span class="ops-muted">Inaktiv</span>@endif
        </div>
        <div class="rt-personnel-timeline-track" data-timeline-lanes="{{ $row['lane_count'] }}" style="--timeline-lanes:{{ $row['lane_count'] }}" wire:key="staff-track-{{ $row['user']->id }}">
        @foreach($row['days'] as $cell)
            <div @class(['rt-personnel-timeline-day snap-start', 'rt-personnel-timeline-day--weekend' => $cell['date']->isWeekend(), 'rt-personnel-timeline-day--empty' => $cell['events']->isEmpty()]) style="--timeline-lanes:{{ max(1, $cell['lane_count']) }}" wire:key="staff-day-{{ $row['user']->id }}-{{ $cell['date']->toDateString() }}">
                @if($planningEnabled && !$absencesOnly && $row['user']->status)
                    <button type="button" class="rt-timeline-cell-action" data-user="{{ $row['user']->id }}" data-date="{{ $cell['date']->toDateString() }}" aria-haspopup="dialog" aria-label="Offene Schicht auswählen: {{ $row['user']->name }} · {{ $cell['date']->format('d.m.Y') }}" data-timeline-cell-action></button>
                @endif
            </div>
        @endforeach
        <div class="rt-personnel-timeline-events">
            @foreach($row['events'] as $event)
                @php
                    $state = in_array($event['shift_status'] ?? null, ['in_progress', 'completed'], true) ? $event['shift_status'] : $event['status_value'];
                    $eventKey = $row['user']->id.'-'.$event['id'];
                    $detailStart = $event['start']->copy()->setTimezone($zone);
                    $detailEnd = $event['end']->copy()->setTimezone($zone);
                    $detailDstChanged = $detailStart->offset !== $detailEnd->offset;
                @endphp
                <div class="rt-personnel-timeline-event" data-kind="{{ $event['kind'] }}" data-state="{{ $state }}" data-continues-before="{{ $event['continues_before'] ? 'true' : 'false' }}" data-continues-after="{{ $event['continues_after'] ? 'true' : 'false' }}" data-time-start="{{ $event['left_percent'] }}" data-time-width="{{ $event['width_percent'] }}" data-time-lane="{{ $event['lane'] }}" style="--event-left:{{ $event['left_percent'] }}%;--event-width:{{ $event['width_percent'] }}%;--event-lane:{{ $event['lane'] }}" wire:key="staff-event-{{ $eventKey }}">
                    <x-ui.dropdown.anchor-dropdown align="left" width="96" :max-height="560" :open-on-hover="false" :offset="8" content-role="dialog" :content-label="'Dienstdetails: '.$event['title']" layer-group="staff-timeline-events" :dropdown-id="'staff-event-'.$eventKey" trigger-classes="rt-personnel-timeline-event-trigger" class="rt-personnel-timeline-event-anchor" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text" data-timeline-event-dropdown>
                        <x-slot:trigger>
                            <button type="button" class="rt-personnel-timeline-bar" aria-label="{{ $event['local_label'] }} · {{ $event['title'] }} · {{ $event['status'] }}" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="dialog" aria-controls="rt-dropdown-staff-event-{{ $eventKey }}-content" x-on:click="$nextTick(() => { if (open) $refs.panel?.querySelector('[data-timeline-detail-focus]')?.focus({ preventScroll: true }); })" data-table-row-ignore>
                                <span class="rt-personnel-timeline-time"><span class="rt-personnel-timeline-time-text">{{ $event['timeline_label'] }}</span></span>
                                @foreach($event['time_segments'] as $segment)
                                    <span class="rt-personnel-timeline-mark" style="left:{{ ($segment['left_percent'] - $event['left_percent']) / $event['width_percent'] * 100 }}%;width:{{ $segment['width_percent'] / $event['width_percent'] * 100 }}%" aria-hidden="true"></span>
                                @endforeach
                            </button>
                        </x-slot:trigger>
                        <x-slot:content>
                            @include('livewire.operations.partials.timeline-event-detail')
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>
                </div>
            @endforeach
        </div>
        @if($planningEnabled && !$absencesOnly && $showSuggestions)
            <div class="rt-timeline-proposals" aria-label="Unverbindliche Besetzungsvorschläge" wire:key="staff-proposals-{{ $row['user']->id }}">
                @foreach($proposalRows->get($row['user']->id,collect()) as $proposal)
                    <button type="button" class="rt-timeline-proposal" style="--proposal-left:{{ $proposal['left_percent'] }}%;--proposal-width:{{ $proposal['width_percent'] }}%" wire:key="{{ $proposal['id'] }}" data-user="{{ $proposal['user_id'] }}" data-shift="{{ $proposal['shift_id'] }}" data-revision="{{ $proposal['revision'] }}" data-fit="{{ $proposal['fit'] }}" data-urgency="{{ $proposal['urgency'] }}" aria-haspopup="dialog" aria-label="Vorschlag prüfen: {{ $row['user']->name }} · {{ $proposal['title'] }} · {{ $proposal['local_label'] }} · {{ $proposal['fit_label'] }} · {{ $proposal['urgency_label'] }}" title="{{ $proposal['fit_label'] }} · {{ $proposal['urgency_label'] }} · {{ $proposal['title'] }} · {{ implode(' · ',$proposal['reasons']) }}" data-timeline-proposal>
                        <i class="far {{ $proposal['fit'] === 'review' ? 'fa-exclamation-circle' : ($proposal['fit'] === 'preferred' ? 'fa-check-circle' : 'fa-lightbulb') }}" aria-hidden="true"></i>
                        <span>{{ $proposal['timeline_label'] }}</span>
                        @if($proposal['urgency'] !== 'normal')<i class="far fa-clock rt-timeline-proposal__urgency" aria-hidden="true"></i>@endif
                    </button>
                @endforeach
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
@if($planningEnabled && !$absencesOnly)
    @if($showSuggestions)
        <div class="rt-timeline-suggestion-legend" role="status">
            <span data-fit="preferred">Wunsch / verfügbar</span><span data-fit="suitable">Konfliktfrei</span><span data-fit="review">Freiwunsch prüfen</span>
            <span><i class="far fa-clock" aria-hidden="true"></i> Orange ≤ 72 h · Rot ≤ 24 h</span>
            <small>Unverbindliche Vorschläge{{ $planningPreview['limited'] ? ' · begrenzte Vorschau' : '' }}</small>
        </div>
    @endif
    <x-ui.dropdown.anchor-dropdown align="left" width="96" :external-trigger="true" dropdown-id="timeline-planner-{{ $this->getId() }}" layer-group="staff-timeline-events" content-role="dialog" content-label="Schicht einteilen" trigger-classes="hidden" class="rt-timeline-planner-host" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text" x-on:dropdown-closed.window="if ($event.detail?.id === layerId) closePlanner()" data-timeline-planner>
        <x-slot:trigger><button type="button" tabindex="-1" aria-hidden="true">Schichtauswahl</button></x-slot:trigger>
        <x-slot:content>
        <div class="rt-timeline-planner" data-rt-dropdown-keep-open x-bind:aria-busy="plannerLoading">
        <header class="rt-timeline-planner__header"><strong tabindex="-1" data-dropdown-initial-focus>Schicht einteilen</strong><button type="button" x-on:click="close(true)" aria-label="Schichtauswahl schließen"><i class="far fa-times" aria-hidden="true"></i></button></header>
        <div x-cloak x-show.important="plannerLoading" role="status" aria-live="polite" class="rt-timeline-planner__loading">
            <i class="far fa-spinner-third" aria-hidden="true"></i>
            <strong>Schichten werden geladen</strong>
            <span>Verfügbarkeit wird geprüft …</span>
        </div>
        <p x-cloak x-show.important="plannerError" x-text="plannerError" role="alert" class="rt-timeline-planner__message"></p>
        <div x-cloak x-show.important="plannerReady && !plannerLoading && !plannerError" data-timeline-planner-results>
        @error('workflow')<p role="alert" class="rt-timeline-planner__error">{{ $message }}</p>@enderror
        @if($assignmentOpen)
        @if($planningUser)<x-user.public-info :user="$planningUser" :show-email="false" :show-presence="false" />@endif
        @if($planningDate)<p class="ops-muted">{{ \Carbon\CarbonImmutable::parse($planningDate)->format('d.m.Y') }}</p>@endif
        @if($planningShift)
            <h3>{{ $planningShift->title }}</h3>
            @php($planningTimeFormat = $planningShift->starts_at->setTimezone($zone)->offset !== $planningShift->ends_at->setTimezone($zone)->offset ? 'd.m. H:i P' : 'd.m. H:i')
            <dl class="ops-meta"><div><dt>Zeitraum</dt><dd>{{ $planningShift->starts_at->setTimezone($zone)->format($planningTimeFormat) }} – {{ $planningShift->ends_at->setTimezone($zone)->format($planningTimeFormat) }}</dd></div><div><dt>Kunde</dt><dd>{{ $planningShift->order?->customer?->company_name }}</dd></div></dl>
            @if($planningReasons)<ul class="ops-muted">@foreach($planningReasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>@endif
            <form wire:submit="confirmAssignment" class="ops-stack">
                <x-operations.field label="Zuweisung" model="assignmentStatus" type="select"><option value="requested">Angefragt</option><option value="confirmed">Bestätigt</option></x-operations.field>
                <div class="ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">Einteilen</x-ui.buttons.button-basic>@if($planningDate)<x-ui.buttons.button-basic type="button" wire:click="backToCellChoices" wire:loading.attr="disabled">Andere Schicht</x-ui.buttons.button-basic>@endif</div>
            </form>
        @else
            <div class="rt-timeline-choices" aria-label="Offene Schichten">
            @forelse($choices as $choice)
                <article class="rt-timeline-choice" data-fit="{{ $choice->eligible ? 'suitable' : 'blocked' }}" wire:key="timeline-choice-{{ $choice->id }}">
                    <div><strong>{{ $choice->title }}</strong><span>{{ $choice->period }}</span><small>{{ $choice->open }} {{ $choice->open === 1 ? 'Platz frei' : 'Plätze frei' }}</small></div>
                    @if($choice->eligible)
                        <x-ui.buttons.button-basic type="button" size="sm" wire:click="selectCellShift({{ $choice->id }},{{ $choice->revision }})" wire:loading.attr="disabled" aria-label="Auswählen: {{ $choice->title }}">Auswählen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                    @else
                        <p><i class="far fa-ban" aria-hidden="true"></i> Nicht passend</p>
                        <ul>@foreach($choice->issues as $issue)<li>{{ $issue['message'] }}</li>@endforeach</ul>
                    @endif
                </article>
            @empty<p class="rt-timeline-planner__message">Keine offenen Schichten an diesem Tag.</p>@endforelse
            </div>
            @if($choices->count() === 30)<p class="ops-muted">Erste 30 Einsätze · weitere über „Noch zu verteilen“</p>@endif
        @endif
        @endif
        </div></div>
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
@endif
</section>
