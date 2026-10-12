{{-- One native anchored surface; detailed DOM is mounted only for the clicked badge. --}}
<div class="hidden" x-data="rtTimelineEventDetails(@js($this->getId()))" data-timeline-shared-detail
    x-on:rt-timeline-event-preview.window="openEvent($event)"
    x-on:dropdown-closed.window="detailClosed($event)"
    x-on:operations-timeline-window-pending.window="clearDetail($event)"
    x-on:operations-timeline-window-reset.window="clearDetail($event)"
    x-on:operations-plan-changed.window="clearDetail()">
    <x-ui.dropdown.anchor-dropdown align="left" width="96" :max-height="560" :open-on-hover="false" :fixed-height="true" :offset="8"
        :external-trigger="true" :dropdown-id="'timeline-event-'.$this->getId()" layer-group="staff-timeline-events"
        content-role="dialog" content-label="Dienstdetails" trigger-classes="hidden"
        class="rt-personnel-timeline-event-anchor" content-classes="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
        data-timeline-event-dropdown>
        <x-slot:trigger><button type="button" tabindex="-1" aria-hidden="true">Dienstdetails</button></x-slot:trigger>
        <x-slot:content>
        <template x-if="open && detail">
        <article class="rt-personnel-timeline-detail flex h-full min-h-0 flex-col overflow-hidden"
            x-bind:data-kind="detail.kind" x-bind:data-state="detail.state" x-bind:aria-label="'Dienstdetails: ' + detail.title"
            tabindex="-1" data-timeline-detail-focus data-dropdown-initial-focus>
            <header class="rt-personnel-timeline-detail-header">
                <div class="rt-personnel-timeline-detail-heading">
                    <span class="rt-personnel-timeline-detail-status"><span aria-hidden="true"></span><span x-text="detail.status"></span></span>
                    <h3 class="rt-personnel-timeline-detail-title" x-bind:title="detail.title" x-text="detail.title"></h3>
                    <p class="rt-personnel-timeline-detail-note truncate" x-show="detail.kind === 'shift'"
                        x-bind:title="detail.parent || 'Zugehöriger Auftrag nicht verfügbar.'" data-timeline-parent-context>
                        <strong x-show="detail.parent">Leistung:</strong> <span x-text="detail.parent || 'Zugehöriger Auftrag nicht verfügbar.'"></span>
                    </p>
                </div>
                <x-ui.buttons.button-basic type="button" class="rt-personnel-timeline-detail-close" aria-label="Dienstdetails schließen" title="Schließen" x-on:click.stop="close(true)" data-timeline-detail-close>
                    <i class="far fa-xmark" aria-hidden="true"></i>
                </x-ui.buttons.button-basic>
            </header>

            <div x-show="detail.kind === 'shift'" x-bind:inert="detail.kind !== 'shift'">
                <x-operations.panel.tabs class="rt-personnel-timeline-detail-tabs" label="Bereiche der Dienstdetails" :id-prefix="'timeline-shared-detail-'.$this->getId().'-shift'" model="detailTab"
                    :tabs="['period' => ['label' => 'Zeitraum & Ort'], 'assignment' => ['label' => 'Einsatzdetails']]" data-rt-dropdown-keep-open />
            </div>
            <div x-show="detail.kind !== 'shift'" x-bind:inert="detail.kind === 'shift'">
                <x-operations.panel.tabs class="rt-personnel-timeline-detail-tabs" label="Bereiche der Dienstdetails" :id-prefix="'timeline-shared-detail-'.$this->getId().'-absence'" model="detailTab"
                    :tabs="['period' => ['label' => 'Zeitraum'], 'assignment' => ['label' => 'Einsatzdetails']]" data-rt-dropdown-keep-open />
            </div>

            <div class="rt-personnel-timeline-detail-panels min-h-0 flex-1 overflow-hidden" data-no-sidebar-swipe>
                <section class="rt-personnel-timeline-detail-tabpanel" role="tabpanel" x-bind:id="detailTabPrefix + '-panel-period'"
                    x-bind:aria-labelledby="detailTabPrefix + '-tab-period'" tabindex="0" data-detail-tab="period"
                    x-show="detailTab === 'period'" x-bind:inert="detailTab !== 'period'"
                    x-transition:enter="rt-timeline-detail-enter" x-transition:enter-start="rt-timeline-detail-enter-from" x-transition:enter-end="rt-timeline-detail-enter-to">
                    <div class="rt-personnel-timeline-detail-period" role="group" aria-label="Zeitraum">
                        <template x-if="detail.allDay">
                            <div class="rt-personnel-timeline-detail-allday"><strong>Ganztägig</strong><span x-text="detail.allDayLabel"></span></div>
                        </template>
                        <template x-if="!detail.allDay">
                            <div>
                                <span class="rt-personnel-timeline-detail-label">Beginn</span>
                                <time x-bind:datetime="detail.start.iso"><strong x-text="detail.start.time"></strong><span x-text="detail.start.date"></span><small x-show="detail.dstChanged" x-text="'UTC ' + detail.start.offset"></small></time>
                            </div>
                        </template>
                        <i class="far fa-arrow-right rt-personnel-timeline-detail-period-arrow" x-show="!detail.allDay" aria-hidden="true"></i>
                        <template x-if="!detail.allDay">
                            <div>
                                <span class="rt-personnel-timeline-detail-label">Ende</span>
                                <time x-bind:datetime="detail.end.iso"><strong x-text="detail.end.time"></strong><span x-text="detail.end.date"></span><small x-show="detail.dstChanged" x-text="'UTC ' + detail.end.offset"></small></time>
                            </div>
                        </template>
                    </div>
                    <p class="rt-personnel-timeline-detail-note" x-show="detail.dstChanged && !detail.allDay"><i class="far fa-clock" aria-hidden="true"></i> Zeitumstellung · <span x-text="detail.elapsedHours"></span> h tatsächliche Dauer</p>

                    <div class="rt-personnel-timeline-detail-previews" x-bind:data-has-map="detail.kind === 'shift' ? 'true' : 'false'">
                        <figure class="rt-event-mini-calendar" role="img" x-bind:aria-label="detail.calendar.accessibleLabel">
                            <div class="rt-event-mini-calendar__header" aria-hidden="true"><span class="rt-event-mini-calendar__month" x-text="detail.calendar.monthLabel"></span></div>
                            <div class="rt-event-mini-calendar__grid" aria-hidden="true">
                                @foreach(['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $weekday)<span class="rt-event-mini-calendar__weekday">{{ $weekday }}</span>@endforeach
                                <template x-for="day in detailCalendar" :key="day.date">
                                    <span class="rt-event-mini-calendar__day" x-bind:data-date="day.date" x-text="day.number"
                                        x-bind:class="{ 'is-outside': day.outside, 'is-selected': day.selected, 'is-start': day.start, 'is-end': day.end }"></span>
                                </template>
                            </div>
                            <figcaption class="rt-event-mini-calendar__range" aria-hidden="true" x-text="detail.calendar.rangeLabel"></figcaption>
                        </figure>
                        <template x-if="detail.kind === 'shift'">
                            <figure class="rt-event-mini-map" x-bind:data-map-state="detail.map.state">
                                <div class="rt-event-mini-map__heading">Deutschland</div>
                                {{-- Same static native map artwork, once per timeline; never employee/GPS coordinates. --}}
                                <div class="rt-germany-map rt-event-mini-map__canvas" style="--rt-map-default-height: 112px; --rt-map-land: url({{ asset('rt-brand/illustrations/germany-outline.svg') }}); --rt-map-borders: url({{ asset('rt-brand/illustrations/germany-borders.svg') }})" role="img" x-bind:aria-label="detail.map.accessibleLabel">
                                    <div class="rt-germany-map__stage">
                                        <span class="rt-germany-map__land" aria-hidden="true"></span><span class="rt-germany-map__borders" aria-hidden="true"></span>
                                        <template x-if="detail.map.point">
                                            <span class="rt-germany-map__pin" aria-hidden="true" data-tone="open" data-location-marker
                                                x-bind:style="{ left: detail.map.point.left + '%', top: detail.map.point.top + '%' }" x-bind:title="detail.map.title"></span>
                                        </template>
                                    </div>
                                </div>
                                <figcaption><strong class="rt-event-mini-map__place" x-bind:title="detail.map.title" x-text="detail.map.place"></strong><span class="rt-event-mini-map__precision" x-text="detail.map.precision"></span></figcaption>
                            </figure>
                        </template>
                    </div>
                </section>
                <section class="rt-personnel-timeline-detail-tabpanel" role="tabpanel" x-bind:id="detailTabPrefix + '-panel-assignment'"
                    x-bind:aria-labelledby="detailTabPrefix + '-tab-assignment'" tabindex="0" data-detail-tab="assignment"
                    x-cloak x-show="detailTab === 'assignment'" x-bind:inert="detailTab !== 'assignment'"
                    x-transition:enter="rt-timeline-detail-enter" x-transition:enter-start="rt-timeline-detail-enter-from" x-transition:enter-end="rt-timeline-detail-enter-to">
                    <div class="rt-personnel-timeline-detail-person">
                        <span class="rt-personnel-timeline-detail-label">Mitarbeiter</span>
                        <div class="flex min-w-0 items-center gap-2.5"><img x-bind:src="detail.person.avatar" x-bind:alt="detail.person.name" class="h-8 w-8 shrink-0 rounded-full object-cover shadow-rt-xs ring-1 ring-rt-border/70 dark:ring-rt-dark-border/70"><span class="min-w-0 truncate text-sm font-semibold leading-5 text-rt-text dark:text-rt-dark-text" x-bind:title="detail.person.name" x-text="detail.person.name"></span></div>
                    </div>
                    <dl class="rt-personnel-timeline-detail-data" x-show="detail.metadata.length">
                        <template x-for="item in detail.metadata" :key="item.label"><div><dt x-text="item.label"></dt><dd x-text="item.value"></dd></div></template>
                    </dl>
                    <dl class="rt-personnel-timeline-detail-workload" x-show="detail.workload.length">
                        <template x-for="item in detail.workload" :key="item.label"><div><dt x-text="item.label"></dt><dd x-text="item.value"></dd></div></template>
                    </dl>
                </section>
            </div>
            <footer class="rt-personnel-timeline-detail-footer">
                @if($planningEnabled && !$absencesOnly)<x-ui.buttons.button-basic type="button" x-show="detail.shiftId" x-on:click="$dispatch('operations-shift-detail-request', { id: detail.shiftId }); close()">Fahrten & Besetzung prüfen</x-ui.buttons.button-basic>@endif
                <x-ui.buttons.button-basic type="button" mode="primary" x-show="detail.shiftId" x-on:click="$dispatch('operations-shift-detail-request', { id: detail.shiftId }); close()" x-bind:data-shift-detail-open="detail.shiftId">Schicht öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" mode="primary" x-show="!detail.shiftId && detail.absenceId" x-on:click="$dispatch('operations-open-absence', { id: detail.absenceId }); close()">Abwesenheit öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
            </footer>
        </article>
        </template>
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
</div>
