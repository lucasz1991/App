@props(['start', 'end'])

@php($calendar = \App\Support\Operations\TimelineMiniCalendar::forInterval($start, $end))

<figure {{ $attributes->class(['rt-event-mini-calendar']) }} role="img" aria-label="{{ $calendar['accessible_label'] }}">
    <div class="rt-event-mini-calendar__header" aria-hidden="true">
        <span class="rt-event-mini-calendar__month">{{ $calendar['month_label'] }}</span>
    </div>
    <div class="rt-event-mini-calendar__grid" aria-hidden="true">
        @foreach(['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $weekday)
            <span class="rt-event-mini-calendar__weekday">{{ $weekday }}</span>
        @endforeach
        @foreach($calendar['days'] as $day)
            <span @class([
                'rt-event-mini-calendar__day',
                'is-outside' => $day['outside'],
                'is-selected' => $day['selected'],
                'is-start' => $day['start'],
                'is-end' => $day['end'],
            ]) data-date="{{ $day['date'] }}">{{ $day['number'] }}</span>
        @endforeach
    </div>
    <figcaption class="rt-event-mini-calendar__range" aria-hidden="true">{{ $calendar['range_label'] }}</figcaption>
</figure>
