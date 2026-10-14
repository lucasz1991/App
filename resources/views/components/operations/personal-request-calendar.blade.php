@props(['calendar' => null, 'start', 'end'])
<div class="rt-request-calendar" data-request-calendar>
    <div class="rt-request-calendar__month">
        <p class="rt-request-dialog__eyebrow">Monat des Einsatzbeginns</p>
        <x-operations.timeline-mini-calendar :start="$start" :end="$end" />
        <p class="rt-request-dialog__muted">Markiert ist der angefragte Zeitraum.</p>
    </div>
    <section class="rt-request-calendar__agenda" aria-label="Eigene Termine im Umfeld">
        <header><h4>Meine Termine im Umfeld</h4><p>{{ $calendar['timezone'] ?? $start->getTimezone()->getName() }}</p></header>
        @if($calendar && count($calendar['events']))
            <ol class="rt-request-calendar__events">
                @foreach($calendar['events'] as $event)
                    <li @class(['rt-request-calendar__event', 'is-request' => $event['is_request']]) data-request-context-event="{{ $event['id'] }}">
                        <div class="rt-request-calendar__event-head"><span>{{ ['shift' => 'Dienst', 'capacity' => 'Kapazität', 'absence' => 'Abwesenheit', 'training' => 'Schulung'][$event['kind']] ?? 'Termin' }}</span>@if($event['is_request'])<strong>Diese Anfrage</strong>@endif</div>
                        <h5>{{ $event['title'] }}</h5>
                        <p class="rt-request-calendar__period">{{ $event['starts_at']->format('d.m. H:i') }} – {{ $event['ends_at']->format($event['starts_at']->isSameDay($event['ends_at']) ? 'H:i' : 'd.m. H:i') }}</p>
                        @if($event['location'])<p class="rt-request-dialog__muted">{{ $event['location'] }}</p>@endif
                        <x-operations.status :value="$event['status']" />
                    </li>
                @endforeach
            </ol>
        @else
            <p class="rt-request-dialog__empty">Keine weiteren eigenen Termine in diesem Monatsraster.</p>
        @endif
    </section>
</div>
