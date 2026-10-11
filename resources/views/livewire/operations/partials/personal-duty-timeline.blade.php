{{-- Nächste Dienste als Zeitleiste: Datum links, Zeit, Ort und Aktionen rechts. --}}
@php
    $timelineLimit = 6;
    $timeline = $assignments->filter(fn ($assignment) => $assignment->shift?->ends_at?->isFuture())->values();
    $today = now($displayTimezone);
@endphp
<section class="rt-today-card rt-today-duties rt-today-enter" style="--i:3" aria-labelledby="personal-upcoming-heading">
    <header class="rt-today-card__head">
        <div>
            <h3 id="personal-upcoming-heading">Nächste Dienste</h3>
            <p>Veröffentlichter Dienstplan · 14 Tage</p>
        </div>
        <button type="button" class="rt-today-card__link" wire:click="showTab('schedule')" wire:loading.attr="disabled" wire:target="showTab">Kalender<i class="fas fa-arrow-right" aria-hidden="true"></i></button>
    </header>
    @if($timeline->isEmpty())
        <div class="rt-today-empty">
            <span class="rt-today-empty__icon" aria-hidden="true"><i class="far fa-calendar-check"></i></span>
            <strong>Keine Dienste in den nächsten 14 Tagen</strong>
            <p>Sobald die Disposition dich einplant, erscheinen deine Dienste hier.</p>
        </div>
    @else
        <ol class="rt-duty-timeline">
            @foreach($timeline->take($timelineLimit) as $assignment)
                @php
                    $shift = $assignment->shift;
                    $start = $shift->starts_at->setTimezone($displayTimezone);
                    $end = $shift->ends_at->setTimezone($displayTimezone);
                    $isNow = $start->lte($today) && $end->gt($today);
                @endphp
                <li class="rt-duty" wire:key="personal-duty-{{ $assignment->id }}" data-personal-duty @if($isNow) data-now @elseif($start->isSameDay($today)) data-today @endif>
                    <div class="rt-duty__date" aria-hidden="true">
                        <span>{{ $start->locale('de')->isoFormat('dd') }}</span>
                        <strong>{{ $start->format('d') }}</strong>
                        <span>{{ $start->locale('de')->isoFormat('MMM') }}</span>
                    </div>
                    <div class="rt-duty__body">
                        <p class="rt-duty__time">
                            <span class="sr-only">{{ $start->locale('de')->isoFormat('dddd, D. MMMM') }},</span>
                            {{ $start->format('H:i') }} – {{ $end->format($start->isSameDay($end) ? 'H:i' : 'd.m. H:i') }}
                            @if($isNow)<span class="rt-duty__live">Läuft</span>@endif
                            <x-operations.status :value="$assignment->status->value" />
                        </p>
                        <h4>{{ $shift->title }}</h4>
                        <p class="rt-duty__meta">
                            @if($shift->location_name)<span><i class="fas fa-map-marker-alt" aria-hidden="true"></i>{{ $shift->location_name }}</span>@endif
                            @if($shift->role_name)<span><i class="fas fa-hard-hat" aria-hidden="true"></i>{{ $shift->role_name }}</span>@endif
                            @if($shift->order?->customer?->company_name)<span><i class="far fa-building" aria-hidden="true"></i>{{ $shift->order->customer->company_name }}</span>@endif
                            @if($shift->planned_break_minutes)<span><i class="fas fa-mug-hot" aria-hidden="true"></i>{{ $shift->planned_break_minutes }} min Pause</span>@endif
                        </p>
                        <div class="rt-duty__actions">@include('livewire.operations.partials.personal-shift-actions')</div>
                    </div>
                </li>
            @endforeach
        </ol>
        @if($timeline->count() > $timelineLimit)
            <button type="button" class="rt-today-card__more" wire:click="showTab('schedule')" wire:loading.attr="disabled" wire:target="showTab">{{ $timeline->count() - $timelineLimit }} weitere im Kalender</button>
        @endif
    @endif
</section>
