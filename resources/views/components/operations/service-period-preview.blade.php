@props(['start' => null, 'end' => null, 'previewId' => null])
@php
    $from = $start instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($start)->locale('de') : null;
    $until = $end instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($end)->setTimezone($from?->getTimezone() ?? $end->getTimezone())->locale('de') : null;
    $validPeriod = $from && $until && $until->greaterThan($from);
    $periodLabel = $from && $until ? $from->format('d.m.Y H:i').' bis '.$until->format('d.m.Y H:i') : 'Zeitraum nicht hinterlegt';
    $calendars = [];
    $omittedMonths = 0;
    if ($validPeriod) {
        $firstDay = $from->startOfDay();
        $lastDay = $until->subMicrosecond()->startOfDay();
        $firstDate = $firstDay->toDateString();
        $lastDate = $lastDay->toDateString();
        $firstMonth = $firstDay->startOfMonth();
        $lastMonth = $lastDay->startOfMonth();
        $monthDistance = ($lastMonth->year - $firstMonth->year) * 12 + $lastMonth->month - $firstMonth->month;
        $omittedMonths = max(0, $monthDistance - 1);
        $months = $monthDistance === 0 ? [$firstMonth] : [$firstMonth, $lastMonth];
        $occupiedDates = $firstDay->format('d.m.Y').($firstDay->isSameDay($lastDay) ? '' : ' bis '.$lastDay->format('d.m.Y'));
        foreach ($months as $month) {
            // Reuse the existing Monday-first month grid, but select only the
            // aggregate [start, end) span, not the entire generated month.
            $calendar = \App\Support\Operations\TimelineMiniCalendar::forInterval($month, $month->addMonth());
            $calendar['accessible_label'] = 'Kalender '.$calendar['month_label'].'. Markierte Zeitspanne: '.$occupiedDates.'.';
            foreach ($calendar['days'] as &$day) {
                $selected = $day['date'] >= $firstDate && $day['date'] <= $lastDate;
                $day['selected'] = $selected;
                $day['start'] = $selected && $day['date'] === $firstDate;
                $day['end'] = $selected && $day['date'] === $lastDate;
            }
            unset($day);
            $calendars[] = $calendar;
        }
    }
@endphp
<x-operations.service-cell-preview :label="'Zeitraum: '.$periodLabel" :preview-id="$previewId" kind="period">
    <x-slot:trigger>
        <div>
            @if($from && $until)
                <span class="rt-table-value tabular-nums">{{ $from->format('d.m.Y H:i') }}</span>
                <span class="rt-table-meta tabular-nums">bis {{ $until->format('d.m.Y H:i') }}</span>
            @else
                <span class="rt-table-value">Zeitraum nicht hinterlegt</span>
            @endif
        </div>
    </x-slot:trigger>
    <x-slot:content>
        <section class="rt-service-cell-preview__body rt-service-period-preview" aria-label="Zeitraum der Leistung">
            <h3 class="rt-service-cell-preview__title">Zeitraum</h3>
            @if($from && $until)
                <dl class="rt-service-period-preview__range">
                    <div><dt>Beginn</dt><dd><time datetime="{{ $from->toIso8601String() }}">{{ $from->format('d.m.Y') }} <strong>{{ $from->format('H:i') }}</strong></time></dd></div>
                    <div><dt>Ende</dt><dd><time datetime="{{ $until->toIso8601String() }}">{{ $until->format('d.m.Y') }} <strong>{{ $until->format('H:i') }}</strong></time></dd></div>
                </dl>
                <p class="rt-service-cell-preview__note">{{ $from->getTimezone()->getName() }}@if($from->format('P') !== $until->format('P')) · Zeitumstellung: UTC {{ $from->format('P') }} → {{ $until->format('P') }}@endif</p>
            @endif
            @if($validPeriod)
                <template x-if="open">
                    <div class="rt-service-period-preview__calendars" data-calendar-months="{{ count($calendars) }}">
                        @foreach($calendars as $calendar)
                            <figure class="rt-event-mini-calendar" role="img" aria-label="{{ $calendar['accessible_label'] }}">
                                <div class="rt-event-mini-calendar__header" aria-hidden="true"><span class="rt-event-mini-calendar__month">{{ $calendar['month_label'] }}</span></div>
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
                            </figure>
                        @endforeach
                    </div>
                </template>
                @if($omittedMonths > 0)
                    <p class="rt-service-cell-preview__note" data-calendar-omitted-months="{{ $omittedMonths }}">{{ $omittedMonths }} {{ $omittedMonths === 1 ? 'weiterer Monat liegt' : 'weitere Monate liegen' }} zwischen den gezeigten Monaten.</p>
                @endif
            @else
                <p class="rt-service-cell-preview__note">{{ $from && $until ? 'Kein gültiger Zeitraum' : 'Zeitraum nicht hinterlegt' }}</p>
            @endif
        </section>
    </x-slot:content>
</x-operations.service-cell-preview>
