{{-- Mini-Monatskalender: Punkte für Dienste, offene Bestätigungen und Abwesenheiten; ein Tag öffnet die Tagesansicht. --}}
@php
    $absenceLabels = ['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'sick' => 'Krank', 'other' => 'Abwesend'];
@endphp
<section class="rt-today-card rt-mini-cal rt-today-enter" style="--i:4" aria-labelledby="rt-mini-cal-title">
    <header class="rt-today-card__head">
        <div>
            <h3 id="rt-mini-cal-title">Kalender</h3>
            <p>{{ $miniCalendar['label'] }}</p>
        </div>
        <div class="rt-mini-cal__nav">
            <button type="button" wire:click="shiftMiniMonth(-1)" wire:loading.attr="disabled" wire:target="shiftMiniMonth" aria-label="Vorheriger Monat"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
            @if($miniCalendar['offset'] !== 0)<button type="button" class="rt-mini-cal__today" wire:click="shiftMiniMonth(0)">Heute</button>@endif
            <button type="button" wire:click="shiftMiniMonth(1)" wire:loading.attr="disabled" wire:target="shiftMiniMonth" aria-label="Nächster Monat"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
        </div>
    </header>
    <div class="rt-mini-cal__grid">
        @foreach(['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $weekday)<span class="rt-mini-cal__weekday" aria-hidden="true">{{ $weekday }}</span>@endforeach
        @foreach($miniCalendar['days'] as $day)
            @php
                $parts = array_filter([$day['duties'] ? $day['duties'].' '.($day['duties'] === 1 ? 'Dienst' : 'Dienste') : null, $day['open'] ? 'Bestätigung offen' : null, $day['absence'] ? ($absenceLabels[$day['absence']] ?? 'Abwesend') : null]);
            @endphp
            <button type="button" class="rt-mini-cal__day" wire:key="mini-day-{{ $day['date']->toDateString() }}" wire:click="openDay('{{ $day['date']->toDateString() }}')"
                @if(! $day['in_month']) data-out @endif @if($day['is_today']) data-today aria-current="date" @endif @if($day['duties']) data-duty @endif @if($day['open']) data-open @endif @if($day['absence']) data-absence @endif
                aria-label="{{ $day['date']->locale('de')->isoFormat('dddd, D. MMMM') }}{{ $parts ? ': '.implode(', ', $parts) : '' }}">
                <span>{{ $day['date']->day }}</span>
                <span class="rt-mini-cal__marks" aria-hidden="true">@if($day['duties'])<i data-kind="duty"></i>@endif @if($day['absence'])<i data-kind="absence"></i>@endif</span>
            </button>
        @endforeach
    </div>
    <footer class="rt-mini-cal__legend" aria-hidden="true">
        <span><i data-kind="duty"></i>Dienst</span>
        <span><i data-kind="open"></i>Zu bestätigen</span>
        <span><i data-kind="absence"></i>Abwesenheit</span>
    </footer>
</section>
