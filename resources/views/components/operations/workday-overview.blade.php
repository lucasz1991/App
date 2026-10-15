@props(['overview', 'timezone' => 'Europe/Berlin', 'sicknessUrl' => null, 'preview' => false])
@php
    $selected = $overview['selected_date'];
    $duties = $overview['assignments'];
    $points = collect($overview['map']['points'])->map(fn ($point) => [...$point, 'tone' => $point['next'] ? 'open' : 'closed', 'pulse' => $point['next'], 'title' => $point['label'].($point['next'] ? ' · Nächster Dienst' : '')])->all();
@endphp
<section class="rt-workday-plan" aria-label="Dein Dienstplan" x-data="rtWorkdayOverview({ preview: @js($preview) })" data-selected-date="{{ $selected->toDateString() }}" data-no-sidebar-swipe>
    <header class="rt-workday-plan__header">
        <h2>Dein Dienstplan</h2>
        <div class="rt-workday-plan__tools">
            @if($sicknessUrl)
                @if($preview)<span class="rt-workday-plan__sickness">Krankmeldung</span>
                @else<a class="rt-workday-plan__sickness" href="{{ $sicknessUrl }}" wire:navigate>Krankmeldung <i class="fal fa-chevron-right" aria-hidden="true"></i></a>@endif
            @endif
            <button type="button" class="rt-workday-plan__icon" aria-label="Vollständigen Kalender öffnen" @if($preview) disabled @else wire:click="showTab('schedule')" @endif><i class="fal fa-calendar-alt" aria-hidden="true"></i></button>
        </div>
    </header>
    <div class="rt-workday-plan__date">
        <button type="button" class="rt-workday-plan__icon" aria-label="Vorherige Woche" @if($preview) x-on:click="previewMove(-7)" @else wire:click="moveOverviewDays(-1)" wire:loading.attr="disabled" wire:target="moveOverviewDays" @endif><i class="fal fa-chevron-left" aria-hidden="true"></i></button>
        <div>
            <h3 @if($preview) x-text="previewLabel || @js($overview['label'])" @endif>{{ $overview['label'] }}</h3>
            <p>{{ $selected->locale('de')->isoFormat('MMMM YYYY') }}<span aria-hidden="true"> · </span><button type="button" @if($preview) x-on:click="previewSelect($el.closest('[data-selected-date]').dataset.selectedDate)" @else x-on:click="queueAlign()" wire:click="resetOverviewDay" @endif>Heute</button></p>
        </div>
        <button type="button" class="rt-workday-plan__icon" aria-label="Nächste Woche" @if($preview) x-on:click="previewMove(7)" @else wire:click="moveOverviewDays(1)" wire:loading.attr="disabled" wire:target="moveOverviewDays" @endif><i class="fal fa-chevron-right" aria-hidden="true"></i></button>
    </div>
    <div class="rt-workday-plan__days" x-ref="days" data-workday-days role="group" aria-label="Tag auswählen" x-on:keydown="dayKeydown($event)">
        @foreach($overview['days'] as $day)
            <button type="button" class="rt-workday-plan__day" wire:key="overview-day-{{ $day['key'] }}" data-date="{{ $day['key'] }}" data-selected="{{ $day['is_selected'] ? 'true' : 'false' }}" @if($day['is_today']) data-today @endif aria-pressed="{{ $day['is_selected'] ? 'true' : 'false' }}" aria-label="{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }}{{ $day['is_today'] ? ', heute' : '' }}, {{ $day['duties'] }} {{ $day['duties'] === 1 ? 'Dienst' : 'Dienste' }}{{ $day['open'] ? ', Antwort offen' : '' }}" tabindex="{{ $day['is_selected'] ? '0' : '-1' }}" @if($preview) x-on:click="previewSelect(@js($day['key']))" @else wire:click="selectOverviewDay('{{ $day['key'] }}')" @endif>
                <span>{{ $day['date']->locale('de')->isoFormat('dd') }}</span>
                <strong>{{ $day['date']->format('j') }}</strong>
                <i aria-hidden="true" data-count="{{ $day['duties'] }}" @if($day['open']) data-open @endif></i>
            </button>
        @endforeach
    </div>
    <div class="rt-workday-plan__body">
        <div class="rt-workday-plan__map">
            <x-ui.germany-map :points="$points" :height="330" :highlightable="true" label="Einsatzorte deiner Dienste im ausgewählten Zeitraum" />
            @if(count($points) === 0)<p>Kein Einsatzort auf der Karte</p>@endif
        </div>
        <div class="rt-workday-plan__services">
            <div class="rt-workday-plan__list-heading"><h3>Dienste</h3><span>{{ $overview['from']->format('d.m.') }} – {{ $overview['to']->subDay()->format('d.m.') }}</span></div>
            @if($duties->isEmpty())
                <div class="rt-workday-plan__empty"><i class="fal fa-calendar-check" aria-hidden="true"></i><strong>Keine Dienste geplant</strong><p>Für diesen Zeitraum ist noch kein Dienst veröffentlicht.</p></div>
            @else
                <ol class="rt-workday-plan__list">
                    @foreach($duties->take(12) as $assignment)
                        @php
                            $shift = $assignment->shift;
                            $start = $shift->starts_at->copy()->setTimezone($timezone)->locale('de');
                            $end = $shift->ends_at->copy()->setTimezone($timezone);
                            $next = $assignment->id === $overview['next_id'];
                            $keys = $overview['point_keys'][$assignment->id] ?? [];
                            $rowKey = 'shift-'.$assignment->id;
                            $time = $start->format('H:i').' – '.$end->format($start->isSameDay($end) ? 'H:i' : 'd.m. H:i');
                            $place = $shift->location_name ?: 'Einsatzort folgt';
                        @endphp
                        <li wire:key="overview-shift-{{ $assignment->id }}">
                            <button type="button" class="rt-workday-plan__service" @if($next) data-next @endif aria-label="{{ $start->isoFormat('dddd, D. MMMM') }}, {{ $time }}, {{ $shift->title }}, {{ $place }}{{ $next ? ', nächster Dienst' : '' }}{{ $assignment->status->value === 'requested' ? ', Antwort offen' : '' }}" title="{{ $shift->title }} · {{ $place }}" @if($preview) aria-disabled="true" @else wire:click="openCalendarEvent('{{ $rowKey }}')" @endif x-on:mouseenter="mapHover(@js($rowKey), @js($keys))" x-on:mouseleave="mapUnhover(@js($rowKey))" x-on:focus="mapFocus(@js($rowKey), @js($keys))" x-on:blur="mapBlur(@js($rowKey))">
                                <span class="rt-workday-plan__service-date"><span>{{ $start->isToday() ? 'Heute' : $start->isoFormat('dd, D. MMM') }}</span><strong>{{ $time }}</strong></span>
                                <span class="rt-workday-plan__service-main"><strong>{{ $place }}</strong><span>{{ $shift->title }}</span><small>{{ $shift->role_name }}@if($shift->role_name && $shift->order?->customer?->company_name) · @endif{{ $shift->order?->customer?->company_name }}</small></span>
                                <span class="rt-workday-plan__service-state">@if($next)<em>Nächster</em>@endif @if($assignment->plan_is_stale)<small>Plan geändert</small>@elseif($assignment->status->value === 'requested')<small>Antwort offen</small>@endif</span>
                                <i class="fal fa-chevron-right rt-workday-plan__chevron" aria-hidden="true"></i>
                            </button>
                        </li>
                    @endforeach
                </ol>
                @if($duties->count() > 12)<button type="button" class="rt-workday-plan__more" @if($preview) disabled @else wire:click="showTab('schedule')" @endif>{{ $duties->count() - 12 }} weitere im Kalender <i class="fal fa-arrow-right" aria-hidden="true"></i></button>@endif
            @endif
        </div>
    </div>
</section>
