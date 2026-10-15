@props(['days', 'axis', 'view' => 'week'])
@php($isWeek = $view === 'week')
<div class="rt-pcal-schedule" data-calendar-time-grid="{{ $view }}" style="--pcal-days:{{ $days->count() }};--pcal-hours:{{ ($axis['end'] - $axis['start']) / 60 }}">
    <div class="rt-pcal-grid-heading">
        <span class="rt-pcal-grid-corner" aria-hidden="true"><i class="far fa-clock"></i></span>
        @foreach($days as $day)
            <button type="button" @class(['rt-pcal-grid-date', 'is-selected'=>$day['selected'], 'is-today'=>$day['is_today'], 'is-weekend'=>$day['date']->isWeekend()]) wire:click="selectCalendarDay('{{ $day['date']->toDateString() }}')" aria-pressed="{{ $day['selected'] ? 'true' : 'false' }}" aria-current="{{ $day['is_today'] ? 'date' : 'false' }}" aria-label="{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }} · {{ $day['summary'] }}">
                <span>{{ $day['date']->locale('de')->isoFormat($isWeek ? 'dd' : 'dddd') }}</span><strong>{{ $day['date']->format('d') }}</strong>
            </button>
        @endforeach
    </div>
    @if($days->contains(fn ($day) => count($day['all_day']) > 0))
        <div class="rt-pcal-all-day-row">
            <span class="rt-pcal-all-day-label">Ganz-<br>tägig</span>
            @foreach($days as $day)
                <div class="rt-pcal-all-day-cell">
                    @foreach($day['all_day'] as $segment)
                        <button type="button" class="rt-pcal-all-day-event" data-event-tone="{{ $segment['tone'] }}" wire:click="openCalendarEvent('{{ $segment['event']->id }}')" aria-label="{{ $segment['accessible_label'] }}" title="{{ $segment['accessible_label'] }}"><span>{{ $segment['event']->title }}</span></button>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
    <div class="rt-pcal-grid-body">
        <div class="rt-pcal-time-axis" aria-hidden="true">
            @foreach($axis['ticks'] as $tick)<span style="top:{{ $tick['percent'] }}%">{{ $tick['label'] }}</span>@endforeach
        </div>
        @foreach($days as $day)
            <div @class(['rt-pcal-time-column', 'is-selected'=>$day['selected'], 'is-weekend'=>$day['date']->isWeekend()]) data-calendar-day="{{ $day['date']->toDateString() }}">
                @foreach($axis['ticks'] as $tick)<span class="rt-pcal-hour-line" style="top:{{ $tick['percent'] }}%" aria-hidden="true"></span>@endforeach
                @if($isWeek)<button type="button" class="rt-pcal-column-select" wire:click="selectCalendarDay('{{ $day['date']->toDateString() }}')" aria-label="{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }} · {{ $day['summary'] }} · Termine anzeigen"></button>@endif
                @foreach($day['segments'] as $segment)
                    @continue($segment['all_day'])
                    @php($segment['lane'] = $segment['display_lane'])
                    @php($segment['lanes'] = $segment['display_lanes'])
                    @foreach($segment['time_segments'] as $part)
                        @php($duration = round($part['height'] * ($axis['end'] - $axis['start']) / 100, 4))
                        @if($isWeek)
                            <span class="rt-pcal-time-bar" aria-hidden="true" data-event-tone="{{ $segment['tone'] }}" data-calendar-bar="{{ $segment['event']->id }}" style="top:{{ $part['top'] }}%;height:{{ $part['height'] }}%;--pcal-lane:{{ $segment['lane'] }};--pcal-lanes:{{ $segment['lanes'] }}" title="{{ $segment['accessible_label'] }}"></span>
                        @else
                            <button type="button" @class(['rt-pcal-time-event', 'is-short'=>$duration < 75, 'is-tiny'=>$duration < 30]) data-event-tone="{{ $segment['tone'] }}" data-calendar-bar="{{ $segment['event']->id }}" style="top:{{ $part['top'] }}%;height:{{ $part['height'] }}%;--pcal-lane:{{ $segment['lane'] }};--pcal-lanes:{{ $segment['lanes'] }}" wire:click="openCalendarEvent('{{ $segment['event']->id }}')" aria-label="{{ $segment['accessible_label'] }} · Details öffnen" title="{{ $segment['accessible_label'] }}">
                                <span>{{ $segment['time_label'] }}</span><strong>{{ $segment['event']->title }}</strong>@if($segment['place'])<small>{{ $segment['place'] }}</small>@endif
                            </button>
                        @endif
                    @endforeach
                @endforeach
            </div>
        @endforeach
    </div>
</div>
