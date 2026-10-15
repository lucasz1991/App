{{-- Begrüßung und Zeiterfassung mit gemeinsamem Status- und Aktionsbereich. --}}
@php
    $now = now($displayTimezone);
    $greeting = match (true) { $now->hour < 11 => 'Guten Morgen', $now->hour < 18 => 'Guten Tag', default => 'Guten Abend' };
    $firstName = auth()->user()->profile?->first_name ?: \Illuminate\Support\Str::before(trim((string) auth()->user()->name), ' ');
    $nextDuty = $assignments->first(fn ($assignment) => $assignment->shift?->ends_at?->isFuture());
    $nextStart = $nextDuty?->shift->starts_at->setTimezone($displayTimezone);
    $nextEnd = $nextDuty?->shift->ends_at->setTimezone($displayTimezone);
    [$dutyState, $dutyLabel] = match (true) {
        $nextDuty === null => ['none', 'Keine Dienste in den nächsten 14 Tagen'],
        $nextStart->lte($now) => ['now', 'Jetzt im Dienst'],
        $nextStart->isSameDay($now) => ['today', 'Heute ab '.$nextStart->format('H:i')],
        $nextStart->isSameDay($now->copy()->addDay()) => ['soon', 'Morgen ab '.$nextStart->format('H:i')],
        default => ['later', 'Nächster Dienst '.$nextStart->locale('de')->isoFormat('dd, D. MMM')],
    };
@endphp
<section class="rt-today-hero rt-today-enter" style="--i:0" aria-labelledby="rt-today-greeting">
    <div class="rt-today-hero__intro">
        <p class="rt-today-hero__date"><i class="far fa-calendar" aria-hidden="true"></i>{{ $now->locale('de')->isoFormat('dddd, D. MMMM') }}</p>
        <h2 id="rt-today-greeting">{{ $greeting }}{{ $firstName !== '' ? ', '.$firstName : '' }}</h2>
        <div class="rt-today-hero__duty" data-duty-state="{{ $dutyState }}">
            <span class="rt-today-hero__duty-dot" aria-hidden="true"></span>
            <div>
                <strong>{{ $dutyLabel }}@if($nextDuty && $nextDuty->status->value === 'requested' && ! $nextDuty->plan_is_stale) <em class="rt-today-hero__confirm">Bitte bestätigen</em>@endif</strong>
                @if($nextDuty)
                    <span>{{ $nextStart->format('H:i') }} – {{ $nextEnd->format($nextStart->isSameDay($nextEnd) ? 'H:i' : 'd.m. H:i') }} · {{ $nextDuty->shift->title }}@if($nextDuty->shift->location_name) · {{ $nextDuty->shift->location_name }}@endif</span>
                @else
                    <span>Interne Arbeit oder Schulungen erfasst du direkt hier.</span>
                @endif
            </div>
        </div>
    </div>
    <div class="rt-today-hero__clock">
        @include('livewire.operations.partials.personal-clock', ['withLocation' => false, 'compactMobileClock' => true])
    </div>
    @include('livewire.operations.partials.personal-glance')
</section>
