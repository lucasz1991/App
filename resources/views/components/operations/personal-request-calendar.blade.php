@props(['calendar' => null, 'start', 'end', 'selectedLabel' => 'Diese Anfrage'])
@php($schedule = \App\Support\Operations\PersonalRequestCalendar::layout($calendar, $start, $end, $selectedLabel))
<section {{ $attributes->class('rt-request-schedule') }} data-request-calendar aria-label="{{ $schedule['aria_label'] }}">
    <header class="rt-request-schedule__header"><h3>{{ $schedule['month_label'] }}</h3><p>{{ $schedule['timezone'] }}</p></header>
    <div class="rt-request-schedule__weekdays" aria-hidden="true">
        @foreach(['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $weekday)
            <span>{{ $weekday }}</span>
        @endforeach
    </div>
    <div class="rt-request-schedule__weeks">
        @foreach($schedule['weeks'] as $week)
            <div class="rt-request-schedule__week" role="group" aria-label="{{ $week['week_label'] }}" style="--rt-request-schedule-lanes: {{ max(1, $week['lane_count']) }}">
                @foreach($week['days'] as $day)
                    <div @class(['rt-request-schedule__day', 'is-outside' => $day['outside'], 'has-request' => $day['has_request']]) data-request-schedule-date="{{ $day['date'] }}"><time datetime="{{ $day['date'] }}" aria-label="{{ $day['day_label'] }}">{{ $day['number'] }}</time></div>
                @endforeach
                <div class="rt-request-schedule__tracks">
                    @foreach($week['events'] as $event)
                        <span @class(['rt-request-schedule__bar', 'rt-request-schedule__bar--'.$event['kind'], 'is-request' => $event['is_request'], 'continues-before' => $event['continues_before'], 'continues-after' => $event['continues_after']]) role="img" aria-label="{{ $event['aria_label'] }}" title="{{ $event['aria_label'] }}" data-request-context-event="{{ $event['id'] }}" data-request-event-kind="{{ $event['kind'] }}" style="--rt-request-schedule-left: {{ $event['left_percent'] }}%; --rt-request-schedule-width: {{ $event['width_percent'] }}%; --rt-request-schedule-lane: {{ $event['lane'] }}"></span>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    @if($schedule['event_count'])
        <div class="rt-request-schedule__legend" aria-hidden="true">
            @if($schedule['has_request'])
                <span><i class="rt-request-schedule__key is-request"></i>{{ $schedule['selected_label'] }}</span>
            @endif
            @foreach(['shift' => 'Dienste', 'absence' => 'Abwesenheit', 'training' => 'Schulung'] as $kind => $label)
                @if(in_array($kind, $schedule['kinds'], true))
                    <span><i class="rt-request-schedule__key rt-request-schedule__bar--{{ $kind }}"></i>{{ $label }}</span>
                @endif
            @endforeach
        </div>
    @else
        <p class="rt-request-schedule__empty">{{ $schedule['source_available'] ? 'Keine eigenen Termine in diesem Monatsraster.' : 'Kalenderdaten sind für diesen Vorgang nicht verfügbar.' }}</p>
    @endif
</section>
