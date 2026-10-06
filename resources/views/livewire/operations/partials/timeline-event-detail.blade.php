@php
    $detailAllDay = $event['kind'] === 'absence' && $detailStart->isStartOfDay() && $detailEnd->isStartOfDay();
    $detailLastDay = $detailAllDay ? $detailEnd->copy()->subSecond() : $detailEnd;
@endphp
<article class="rt-personnel-timeline-detail flex h-full min-h-0 flex-col overflow-hidden" data-kind="{{ $event['kind'] }}" data-state="{{ $state }}" tabindex="-1" data-timeline-detail-focus
    x-data="{
        detailPage: 0,
        goToDetailPage(index, animate = true) {
            const scroller = $refs.detailPages;
            if (!scroller) return;
            const page = Math.max(0, Math.min(1, index));
            const smooth = animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            scroller.children[page]?.scrollTo({ top: 0, behavior: 'instant' });
            scroller.scrollTo({ top: page * scroller.clientHeight, behavior: smooth ? 'smooth' : 'instant' });
            this.detailPage = page;
        },
    }"
    x-init="$watch('open', value => { if (value) $nextTick(() => goToDetailPage(0, false)); })">
    <header class="rt-personnel-timeline-detail-header">
        <div class="rt-personnel-timeline-detail-heading">
            <span class="rt-personnel-timeline-detail-status"><span aria-hidden="true"></span>{{ $event['status'] }}</span>
            <h3 class="rt-personnel-timeline-detail-title" title="{{ $event['title'] }}">{{ $event['title'] }}</h3>
        </div>
        <x-ui.buttons.button-basic type="button" class="rt-personnel-timeline-detail-close" aria-label="Dienstdetails schließen" title="Schließen" x-on:click.stop="close(true)" data-timeline-detail-close>
            <i class="far fa-xmark" aria-hidden="true"></i>
        </x-ui.buttons.button-basic>
    </header>

    <div class="rt-personnel-timeline-detail-pages min-h-0 flex-1 overflow-y-auto overscroll-contain snap-y snap-mandatory"
        x-ref="detailPages" tabindex="0" role="region" aria-label="Dienstdetails – zwei Seiten zum Scrollen" data-no-sidebar-swipe
        @scroll.passive="detailPage = Math.min(1, Math.max(0, Math.round($el.scrollTop / ($el.clientHeight || 1))))"
        @keydown.page-down.self.prevent="goToDetailPage(1)" @keydown.page-up.self.prevent="goToDetailPage(0)"
        @keydown.home.self.prevent="goToDetailPage(0)" @keydown.end.self.prevent="goToDetailPage(1)">
    <section class="rt-personnel-timeline-detail-page h-full min-h-0 snap-start snap-always overflow-y-auto" aria-label="Zeitraum und Ort" data-detail-page="0">
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

    <section class="rt-personnel-timeline-detail-page h-full min-h-0 snap-start snap-always overflow-y-auto" aria-label="Einsatzdetails" data-detail-page="1">
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
        <nav class="rt-personnel-timeline-detail-pagination" aria-label="Seiten der Dienstdetails" data-rt-dropdown-keep-open>
            <button type="button" :aria-current="detailPage === 0 ? 'page' : null" @click.stop="goToDetailPage(0)"><span aria-hidden="true">01</span> {{ $event['kind'] === 'shift' ? 'Zeitraum & Ort' : 'Zeitraum' }}</button>
            <button type="button" :aria-current="detailPage === 1 ? 'page' : null" @click.stop="goToDetailPage(1)"><span aria-hidden="true">02</span> Einsatzdetails</button>
        </nav>
    @if($event['shift_id'])
        <x-ui.buttons.button-basic type="button" x-on:click="$dispatch('operations-shift-detail-request', { id: {{ $event['shift_id'] }} }); close()" data-shift-detail-open="{{ $event['shift_id'] }}">Schicht öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
    @elseif($absencesOnly)
        <x-ui.buttons.button-basic type="button" wire:click="$dispatch('operations-open-absence', {id: {{ $event['absence_id'] }}})">Abwesenheit öffnen <i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
    @endif
    </footer>
</article>
