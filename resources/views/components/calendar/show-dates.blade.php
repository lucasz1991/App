@props([
    'dates' => collect(),
    'eventTitle' => 'Kurstermin',
    'dateField' => 'date',
    'startTimeField' => null,
    'endTimeField' => null,
])

@php
    use Illuminate\Support\Carbon;

    $events = collect($dates)->map(function ($item, $idx) use ($eventTitle, $dateField, $startTimeField, $endTimeField) {
        $dateValue = is_object($item) || is_array($item) ? data_get($item, $dateField) : $item;
        $dateStr = $dateValue instanceof \DateTimeInterface
            ? $dateValue->format('Y-m-d')
            : Carbon::parse($dateValue)->format('Y-m-d');

        $startTime = $startTimeField ? data_get($item, $startTimeField) : null;
        $endTime   = $endTimeField   ? data_get($item, $endTimeField)   : null;

        if ($startTime) {
            return [
                'id'     => $idx + 1,
                'title'  => $eventTitle,
                'start'  => trim($dateStr.' '.$startTime),
                'end'    => $endTime ? trim($dateStr.' '.$endTime) : null,
                'allDay' => false,
            ];
        }

        return [
            'id'     => $idx + 1,
            'title'  => $eventTitle,
            'start'  => $dateStr,
            'allDay' => true,
        ];
    })->values();
@endphp

<div
    {{ $attributes->merge(['class' => 'show-dates-calendar opacity-70 ']) }}
    x-data="(() => {
        const eventsFromServer = @js($events);
        const waitFor = (cond, cb, tries = 0) => {
            if (cond()) return cb();
            if (tries > 200) return console.error('FullCalendar konnte nicht geladen werden.');
            setTimeout(() => waitFor(cond, cb, tries + 1), 25);
        };
        return {
            calendar: null,
            events: eventsFromServer,
            options: {
                initialView: 'dayGridMonth',
                locale: 'de',
                timeZone: 'Europe/Berlin',
                firstDay: 1,
                height: 'auto',
                headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
                buttonText: { today: 'Heute', month: 'Monat', week: 'Woche', day: 'Tag' },
                businessHours: { daysOfWeek: [1,2,3,4,5], startTime: '08:00', endTime: '18:00' },
            },
            init() {
                setTimeout(() => {
                    waitFor(() => window.FullCalendar && this.$refs.calendar, () => {
                        this.calendar = new FullCalendar.Calendar(this.$refs.calendar, {
                            ...this.options,
                            events: this.events,
                            eventClick: (info) => {
                                const { title, start } = info.event;
                                const dt = start ? start.toLocaleString('de-DE') : '';
                                alert(`${title}\n${dt}`);
                            },
                        });
                        this.calendar.render();
                        window.addEventListener('calendar:update', (e) => {
                            if (e?.detail?.events) this.setEvents(e.detail.events);
                        });
                    });
                }, 50);
            },
            setEvents(newEvents) {
                if (!this.calendar) return;
                this.events = newEvents || [];
                this.calendar.removeAllEvents();
                this.calendar.addEventSource(this.events);
            },
        };
    })()"
    x-init="init()"
>
    <div class="fc-container" x-ref="calendar" ></div>
</div>

