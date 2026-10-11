@props(['item'])
@php
    $order = $item->order;
    $customer = $order?->relationLoaded('customer') ? $order->getRelation('customer') : null;
    $summary = $item->summary;
    $timezone = $item->timezone ?: 'Europe/Berlin';
    $from = $item->from instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($item->from)->setTimezone($timezone)->locale('de') : null;
    $until = $item->until instanceof \Carbon\CarbonInterface ? \Carbon\CarbonImmutable::instance($item->until)->setTimezone($timezone)->locale('de') : null;
    $validPeriod = $from && $until && $until->greaterThan($from);
    $calendars = [];
    $omittedMonths = 0;
    if ($validPeriod) {
        // Keep the complete service span half-open and the visualization bounded.
        $firstDay = $from->startOfDay();
        $lastDay = $until->subMicrosecond()->startOfDay();
        $firstDate = $firstDay->toDateString();
        $lastDate = $lastDay->toDateString();
        $firstMonth = $firstDay->startOfMonth();
        $lastMonth = $lastDay->startOfMonth();
        $monthDistance = ($lastMonth->year - $firstMonth->year) * 12 + $lastMonth->month - $firstMonth->month;
        $omittedMonths = max(0, $monthDistance - 1);
        $occupiedDates = $firstDay->format('d.m.Y').($firstDay->isSameDay($lastDay) ? '' : ' bis '.$lastDay->format('d.m.Y'));
        foreach ($monthDistance === 0 ? [$firstMonth] : [$firstMonth, $lastMonth] as $month) {
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
    $location = $order?->location_name ?: 'Einsatzort offen';
    $locationPreview = $order instanceof \App\Models\Order
        ? \App\Support\Operations\TimelineLocationPreview::fromOrder($order)
        : ['state' => 'unknown', 'label' => 'Standort nicht verortet'];
    $address = collect([$order?->street, trim(($order?->postal_code ?? '').' '.($order?->city ?? '')), $order?->country])->filter()->join(', ');
@endphp
<div {{ $attributes->class(['rt-service-detail-summary']) }} data-service-detail-summary data-service-summary-scope="complete" x-data="{ open: true }">
    <div class="rt-service-detail-summary__identity">
        <div class="rt-service-detail-summary__customer">
            <x-operations.company-avatar :customer="$customer" :size="48" />
            <div>
                <span class="rt-service-detail-summary__label">Kunde</span>
                <strong>{{ $customer?->company_name ?: 'Kunde nicht hinterlegt' }}</strong>
            </div>
        </div>
    </div>

    <section class="rt-service-detail-summary__staffing" aria-label="Besetzung der gesamten Leistung">
        <div class="rt-service-detail-summary__section-heading">
            <h3>Besetzung</h3>
        </div>
        <x-operations.service-staffing-content :seats="$item->staffingSeats" :summary="$summary" :timezone="$timezone" :expanded="true" :detail-id-prefix="'service-detail-'.$item->id.'-seat'" />
        <p class="rt-service-detail-summary__note"><span class="tabular-nums">{{ $summary['confirmed'] }}</span> bestätigt · Aktive Einsatzplätze</p>
        @if((int) $summary['required'] === 0)
            <p class="rt-service-detail-summary__empty">Für diese Leistung sind aktuell keine aktiven Einsatzplätze hinterlegt.</p>
        @endif
    </section>

    <div class="rt-service-detail-summary__context">
        <section class="rt-service-detail-summary__period" aria-label="Zeitraum der gesamten Leistung">
            <div class="rt-service-detail-summary__section-heading"><h3><i class="far fa-calendar-alt" aria-hidden="true"></i> Zeitraum</h3></div>
            @if($from && $until)
                <dl class="rt-service-detail-summary__range">
                    <div><dt>Beginn</dt><dd><time datetime="{{ $from->toIso8601String() }}">{{ $from->format('d.m.Y') }} <strong>{{ $from->format('H:i') }}</strong></time></dd></div>
                    <div><dt>Ende</dt><dd><time datetime="{{ $until->toIso8601String() }}">{{ $until->format('d.m.Y') }} <strong>{{ $until->format('H:i') }}</strong></time></dd></div>
                </dl>
                <p class="rt-service-detail-summary__note">{{ $timezone }}@if($from->format('P') !== $until->format('P')) · Zeitumstellung: UTC {{ $from->format('P') }} → {{ $until->format('P') }}@endif</p>
            @endif
            @if($validPeriod)
                <div class="rt-service-detail-summary__calendars" data-service-detail-calendar-months="{{ count($calendars) }}">
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
                @if($omittedMonths > 0)
                    <p class="rt-service-detail-summary__note" data-service-detail-omitted-months="{{ $omittedMonths }}">{{ $omittedMonths }} {{ $omittedMonths === 1 ? 'weiterer Monat liegt' : 'weitere Monate liegen' }} zwischen den gezeigten Monaten.</p>
                @endif
            @else
                <p class="rt-service-detail-summary__empty">{{ $from && $until ? 'Kein gültiger Zeitraum hinterlegt.' : 'Zeitraum nicht hinterlegt.' }}</p>
            @endif
        </section>

        <section class="rt-service-detail-summary__location" aria-label="Ort der Leistung">
            <div class="rt-service-detail-summary__section-heading"><h3><i class="far fa-map-marker-alt" aria-hidden="true"></i> Einsatzort</h3></div>
            <strong class="rt-service-detail-summary__location-name">{{ $location }}</strong>
            @if($address)<p class="rt-service-detail-summary__note">{{ $address }}</p>@endif
            <x-operations.timeline-mini-map :preview="$locationPreview" :location="$order?->location_name" />
        </section>
    </div>
</div>
