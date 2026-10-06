@php
    $detailAllDay = $event['kind'] === 'absence' && $detailStart->isStartOfDay() && $detailEnd->isStartOfDay();
    $detailLastDay = $detailAllDay ? $detailEnd->copy()->subSecond() : $detailEnd;
    $detailTabId = 'timeline-detail-'.$row['user']->id.'-'.$event['kind'].'-'.($event['shift_id'] ?? $event['absence_id']);
@endphp
<article class="rt-personnel-timeline-detail flex h-full min-h-0 flex-col overflow-hidden" data-kind="{{ $event['kind'] }}" data-state="{{ $state }}" tabindex="-1" data-timeline-detail-focus
    x-data="{ detailTab: 'period' }"
    x-init="$watch('open', value => { if (value) detailTab = 'period'; })">
    <header class="rt-personnel-timeline-detail-header">
        <div class="rt-personnel-timeline-detail-heading">
            <span class="rt-personnel-timeline-detail-status"><span aria-hidden="true"></span>{{ $event['status'] }}</span>
            <h3 class="rt-personnel-timeline-detail-title" title="{{ $event['title'] }}">{{ $event['title'] }}</h3>
        </div>
        <x-ui.buttons.button-basic type="button" class="rt-personnel-timeline-detail-close" aria-label="Dienstdetails schließen" title="Schließen" x-on:click.stop="close(true)" data-timeline-detail-close>
            <i class="far fa-xmark" aria-hidden="true"></i>
        </x-ui.buttons.button-basic>
    </header>

    <x-operations.panel.tabs class="rt-personnel-timeline-detail-tabs" label="Bereiche der Dienstdetails" :id-prefix="$detailTabId" model="detailTab"
        :tabs="['period' => ['label' => $event['kind'] === 'shift' ? 'Zeitraum & Ort' : 'Zeitraum'], 'assignment' => ['label' => 'Einsatzdetails']]"
        data-rt-dropdown-keep-open />

    <div class="rt-personnel-timeline-detail-panels min-h-0 flex-1 overflow-hidden" data-no-sidebar-swipe>
    <section class="rt-personnel-timeline-detail-tabpanel" role="tabpanel" id="{{ $detailTabId }}-panel-period" aria-labelledby="{{ $detailTabId }}-tab-period" tabindex="0" data-detail-tab="period"
        x-show="detailTab === 'period'" :inert="detailTab !== 'period'"
        x-transition:enter="rt-timeline-detail-enter" x-transition:enter-start="rt-timeline-detail-enter-from" x-transition:enter-end="rt-timeline-detail-enter-to">
    <div class="rt-personnel-timeline-detail-period" role="group" aria-label="Zeitraum">
        @if($detailAllDay)
            <div class="rt-personnel-timeline-detail-allday">
                <strong>Ganztägig</strong>
                <span>{{ $detailStart->format('d.m.Y') }}@unless($detailStart->isSameDay($detailLastDay)) – {{ $detailLastDay->format('d.m.Y') }}@endunless</span>
            </div>
        @else
            <div>
                <span class="rt-personnel-timeline-detail-label">Beginn</span>
                <time datetime="{{ $detailStart->toIso8601String() }}"><strong>{{ $detailStart->format('H:i') }}</strong><span>{{ $detailStart->format('d.m.Y') }}</span>@if($detailDstChanged)<small>UTC {{ $detailStart->format('P') }}</small>@endif</time>
            </div>
            <i class="far fa-arrow-right rt-personnel-timeline-detail-period-arrow" aria-hidden="true"></i>
            <div>
                <span class="rt-personnel-timeline-detail-label">Ende</span>
                <time datetime="{{ $detailEnd->toIso8601String() }}"><strong>{{ $detailEnd->format('H:i') }}</strong><span>{{ $detailEnd->format('d.m.Y') }}</span>@if($detailDstChanged)<small>UTC {{ $detailEnd->format('P') }}</small>@endif</time>
            </div>
        @endif
    </div>
    @if($detailDstChanged && !$detailAllDay)
        <p class="rt-personnel-timeline-detail-note"><i class="far fa-clock" aria-hidden="true"></i> Zeitumstellung · {{ number_format(abs($detailStart->diffInMinutes($detailEnd)) / 60, 1, ',', '.') }} h tatsächliche Dauer</p>
    @endif

    {{-- Only mount the visual previews while the detail is open, not in every timeline cell. --}}
    <template x-if="open">
        <div class="rt-personnel-timeline-detail-previews" data-has-map="{{ $event['kind'] === 'shift' ? 'true' : 'false' }}">
            <x-operations.timeline-mini-calendar :start="$detailStart" :end="$detailEnd" />
            @if($event['kind'] === 'shift')
                <x-operations.timeline-mini-map :preview="$event['location_preview'] ?? ['state' => 'unknown', 'label' => 'Standort nicht verortet']" :location="$event['location_name'] ?? null" />
            @endif
        </div>
    </template>
    </section>

    <section class="rt-personnel-timeline-detail-tabpanel" role="tabpanel" id="{{ $detailTabId }}-panel-assignment" aria-labelledby="{{ $detailTabId }}-tab-assignment" tabindex="0" data-detail-tab="assignment"
        x-cloak x-show="detailTab === 'assignment'" :inert="detailTab !== 'assignment'"
        x-transition:enter="rt-timeline-detail-enter" x-transition:enter-start="rt-timeline-detail-enter-from" x-transition:enter-end="rt-timeline-detail-enter-to">
    <div class="rt-personnel-timeline-detail-person">
        <span class="rt-personnel-timeline-detail-label">Mitarbeiter</span>
        <x-user.public-info :user="$row['user']" :size="7" :show-email="false" :show-presence="false" />
    </div>

    @if(filled($event['detail']) || filled($event['role_name'] ?? null) || filled($event['location_name'] ?? null) || filled($event['shift_status_label'] ?? null) || ($event['planned_break_minutes'] ?? 0))
        <dl class="rt-personnel-timeline-detail-data">
            @if(filled($event['location_name'] ?? null))<div><dt>Einsatzort</dt><dd>{{ $event['location_name'] }}</dd></div>@endif
            @if(filled($event['detail']))<div><dt>Kunde</dt><dd>{{ $event['detail'] }}</dd></div>@endif
            @if(filled($event['role_name'] ?? null))<div><dt>Tätigkeit</dt><dd>{{ $event['role_name'] }}</dd></div>@endif
            @if(filled($event['shift_status_label'] ?? null))<div><dt>Planstatus</dt><dd>{{ $event['shift_status_label'] }}</dd></div>@endif
            @if($event['planned_break_minutes'] ?? 0)<div><dt>Geplante Pause</dt><dd>{{ $event['planned_break_minutes'] }} Minuten</dd></div>@endif
        </dl>
    @endif

    @if(!$absencesOnly)
        <dl class="rt-personnel-timeline-detail-workload">
            <div><dt>Regelarbeitszeit</dt><dd>{{ $row['weekly_working_hours'] !== null ? number_format($row['weekly_working_hours'], 1, ',', '.').' h/Woche' : 'Nicht hinterlegt' }}</dd></div>
            @if(isset($row['planned_hours_by_week'][$event['iso_week']]))<div><dt>Eingeplant · KW {{ $event['visible_start']->setTimezone($zone)->isoWeek() }}</dt><dd>{{ number_format($row['planned_hours_by_week'][$event['iso_week']], 1, ',', '.') }} h</dd></div>@endif
        </dl>
    @endif
    </section>
    </div>

    <footer class="rt-personnel-timeline-detail-footer">
    @if($event['shift_id'])
        <x-ui.buttons.button-basic type="button" mode="primary" x-on:click="$dispatch('operations-shift-detail-request', { id: {{ $event['shift_id'] }} }); close()" data-shift-detail-open="{{ $event['shift_id'] }}">Schicht öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
    @elseif($absencesOnly)
        <x-ui.buttons.button-basic type="button" mode="primary" wire:click="$dispatch('operations-open-absence', {id: {{ $event['absence_id'] }}})">Abwesenheit öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
    @endif
    </footer>
</article>
