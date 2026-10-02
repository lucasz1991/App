<section class="rt-staff-timeline-layout min-w-0" aria-label="Mitarbeiter-Zeitleiste">
@if(!$absencesOnly)
    @if($searchInHeader)
        <template x-teleport="[data-shift-plan-timeline-search]">
            <x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" aria-label="Mitarbeiter suchen" />
        </template>
    @else
        <x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" />
    @endif
@endif
<div class="rt-personnel-timeline" style="--timeline-days:{{ $days->count() }}" x-data="rtStaffTimeline" data-no-sidebar-swipe>
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
    <div class="rt-personnel-timeline-grid" x-ref="timelineGrid">
    @forelse($rows as $row)
        <div class="rt-personnel-timeline-name min-w-0" wire:key="staff-person-{{ $row['user']->id }}">
            <x-user.person-anchor-preview :user="$row['user']" trigger-classes="flex min-w-0 w-full">
                <x-slot:trigger>
                    <button type="button" class="min-h-11 min-w-0 w-full rounded-lg text-left outline-none transition-colors hover:text-rt-red focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:text-rt-dark-accent" aria-label="{{ __('app.open_person_preview') }}: {{ $row['user']->name }}" title="{{ $row['user']->name }}">
                        <x-user.public-info :user="$row['user']" :size="6" :show-email="false" :show-presence="false" />
                    </button>
                </x-slot:trigger>
            </x-user.person-anchor-preview>
            @if(!$row['user']->status)<span class="ops-muted">Inaktiv</span>@endif
        </div>
        @foreach($row['days'] as $cell)
            <div @class(['rt-personnel-timeline-day snap-start', 'rt-personnel-timeline-day--weekend' => $cell['date']->isWeekend(), 'rt-personnel-timeline-day--empty' => $cell['events']->isEmpty()]) style="--timeline-lanes:{{ max(1, $cell['lane_count']) }}" wire:key="staff-day-{{ $row['user']->id }}-{{ $cell['date']->toDateString() }}">
            @foreach($cell['events'] as $event)
                @php
                    $state = in_array($event['shift_status'] ?? null, ['in_progress', 'completed'], true) ? $event['shift_status'] : $event['status_value'];
                    $eventKey = $row['user']->id.'-'.$cell['date']->format('Ymd').'-'.$event['id'];
                    $detailStart = $event['start']->copy()->setTimezone($zone);
                    $detailEnd = $event['end']->copy()->setTimezone($zone);
                    $detailDstChanged = $detailStart->offset !== $detailEnd->offset;
                @endphp
                <div class="rt-personnel-timeline-event" data-kind="{{ $event['kind'] }}" data-state="{{ $state }}" data-time-start="{{ $event['left_percent'] }}" data-time-width="{{ $event['width_percent'] }}" data-time-lane="{{ $event['lane'] }}" style="--event-left:{{ $event['left_percent'] }}%;--event-width:{{ $event['width_percent'] }}%;--event-lane:{{ $event['lane'] }}" wire:key="staff-event-{{ $eventKey }}">
                    <x-ui.dropdown.anchor-dropdown align="left" width="72" :open-on-hover="true" :offset="6" content-role="dialog" content-label="Dienstdetails" layer-group="staff-timeline-events" :dropdown-id="'staff-event-'.$eventKey" trigger-classes="rt-personnel-timeline-event-trigger" class="rt-personnel-timeline-event-anchor" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text" data-timeline-event-dropdown>
                        <x-slot:trigger>
                            <button type="button" class="rt-personnel-timeline-bar" aria-label="{{ $event['local_label'] }} · {{ $event['title'] }} · {{ $event['status'] }}" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="dialog" aria-controls="rt-dropdown-staff-event-{{ $eventKey }}-content" data-table-row-ignore>
                                <span class="rt-personnel-timeline-time">{{ $event['local_label'] }}</span>
                                @foreach($event['time_segments'] as $segment)
                                    <span class="rt-personnel-timeline-mark" style="left:{{ ($segment['left_percent'] - $event['left_percent']) / $event['width_percent'] * 100 }}%;width:{{ $segment['width_percent'] / $event['width_percent'] * 100 }}%" aria-hidden="true"></span>
                                @endforeach
                            </button>
                        </x-slot:trigger>
                        <x-slot:content>
                            <article class="rt-personnel-timeline-detail" data-kind="{{ $event['kind'] }}" data-state="{{ $state }}">
                                <header class="rt-personnel-timeline-detail-header">
                                    <span class="rt-personnel-timeline-detail-status"><span aria-hidden="true"></span>{{ $event['status'] }}</span>
                                    <p class="rt-personnel-timeline-detail-title">{{ $event['title'] }}</p>
                                    <p class="rt-personnel-timeline-detail-person">{{ $row['user']->name }}</p>
                                </header>
                                <dl class="rt-personnel-timeline-detail-data">
                                    <div><dt>Zeitraum</dt><dd>{{ $detailStart->format($detailDstChanged ? 'd.m. H:i P' : 'd.m. H:i') }} – {{ $detailEnd->format($detailDstChanged ? 'd.m. H:i P' : 'd.m. H:i') }}</dd></div>
                                    @if(filled($event['detail']))<div><dt>Kunde</dt><dd>{{ $event['detail'] }}</dd></div>@endif
                                    @if(filled($event['role_name'] ?? null))<div><dt>Tätigkeit</dt><dd>{{ $event['role_name'] }}</dd></div>@endif
                                    @if(filled($event['shift_status_label'] ?? null))<div><dt>Planstatus</dt><dd>{{ $event['shift_status_label'] }}</dd></div>@endif
                                    @if(filled($event['location_name'] ?? null))<div><dt>Einsatzort</dt><dd>{{ $event['location_name'] }}</dd></div>@endif
                                    @if(!$absencesOnly)
                                        <div><dt>Regelarbeitszeit</dt><dd>{{ $row['weekly_working_hours'] !== null ? number_format($row['weekly_working_hours'], 1, ',', '.').' h/Woche' : 'Nicht hinterlegt' }}</dd></div>
                                        @if(isset($row['planned_hours_by_week'][$event['iso_week']]))<div><dt>Eingeplant · KW {{ $cell['date']->isoWeek() }}</dt><dd>{{ number_format($row['planned_hours_by_week'][$event['iso_week']], 1, ',', '.') }} h</dd></div>@endif
                                    @endif
                                    @if($event['planned_break_minutes'] ?? 0)<div><dt>Pause</dt><dd>{{ $event['planned_break_minutes'] }} Minuten</dd></div>@endif
                                    @if($detailDstChanged)<div><dt>Zeitumstellung</dt><dd>{{ number_format(abs($detailStart->diffInMinutes($detailEnd)) / 60, 1, ',', '.') }} h tatsächliche Dauer</dd></div>@endif
                                </dl>
                                @if($event['shift_id'])
                                    <footer class="rt-personnel-timeline-detail-footer"><x-ui.buttons.button-basic size="sm" href="{{ route('operations.workspace',['module'=>'shift-management','shift'=>$event['shift_id']]) }}">Schicht öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic></footer>
                                @elseif($absencesOnly)
                                    <footer class="rt-personnel-timeline-detail-footer"><x-ui.buttons.button-basic size="sm" type="button" wire:click="$dispatch('operations-open-absence', {id: {{ $event['absence_id'] }}})">Abwesenheit öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic></footer>
                                @endif
                            </article>
                        </x-slot:content>
                    </x-ui.dropdown.anchor-dropdown>
                </div>
            @endforeach
            </div>
        @endforeach
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
</section>
