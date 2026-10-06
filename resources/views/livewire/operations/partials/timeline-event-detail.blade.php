@php
    $detailAllDay = $event['kind'] === 'absence' && $detailStart->isStartOfDay() && $detailEnd->isStartOfDay();
    $detailLastDay = $detailAllDay ? $detailEnd->copy()->subSecond() : $detailEnd;
@endphp
<article class="rt-personnel-timeline-detail" data-kind="{{ $event['kind'] }}" data-state="{{ $state }}" tabindex="-1" data-timeline-detail-focus>
    <header class="rt-personnel-timeline-detail-header">
        <div class="rt-personnel-timeline-detail-heading">
            <span class="rt-personnel-timeline-detail-status"><span aria-hidden="true"></span>{{ $event['status'] }}</span>
            <h3 class="rt-personnel-timeline-detail-title">{{ $event['title'] }}</h3>
        </div>
        <x-ui.buttons.button-basic type="button" class="rt-personnel-timeline-detail-close" aria-label="Dienstdetails schließen" title="Schließen" x-on:click.stop="close(true)" data-timeline-detail-close>
            <i class="far fa-xmark" aria-hidden="true"></i>
        </x-ui.buttons.button-basic>
    </header>

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

    @if($event['shift_id'])
        <footer class="rt-personnel-timeline-detail-footer"><x-ui.buttons.button-basic type="button" x-on:click="$dispatch('operations-shift-detail-request', { id: {{ $event['shift_id'] }} }); close()" data-shift-detail-open="{{ $event['shift_id'] }}">Schicht öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic></footer>
    @elseif($absencesOnly)
        <footer class="rt-personnel-timeline-detail-footer"><x-ui.buttons.button-basic type="button" wire:click="$dispatch('operations-open-absence', {id: {{ $event['absence_id'] }}})">Abwesenheit öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic></footer>
    @endif
</article>
