{{-- Begrüßung mit dem, was heute zählt: der heutige oder der nächste veröffentlichte Dienst. --}}
@php
    $now = now($displayTimezone);
    $greeting = match (true) { $now->hour < 11 => 'Guten Morgen', $now->hour < 18 => 'Guten Tag', default => 'Guten Abend' };
    $firstName = auth()->user()->profile?->first_name ?: \Illuminate\Support\Str::before(trim((string) auth()->user()->name), ' ');
    $upcoming = $assignments->filter(fn ($assignment) => $assignment->shift?->ends_at?->isFuture())
        ->sortBy(fn ($assignment) => $assignment->shift->starts_at->getTimestamp());
    $nextDuty = $upcoming->first();
    $nextStart = $nextDuty?->shift->starts_at->setTimezone($displayTimezone);
    $nextEnd = $nextDuty?->shift->ends_at->setTimezone($displayTimezone);
    $dutyLabel = match (true) {
        $nextDuty === null => 'Keine Dienste in den nächsten 14 Tagen',
        $nextStart->lte($now) => 'Jetzt im Dienst',
        $nextStart->isSameDay($now) => 'Heute',
        $nextStart->isSameDay($now->copy()->addDay()) => 'Morgen',
        default => $nextStart->locale('de')->isoFormat('dd, D. MMM'),
    };
@endphp
<header class="rt-personal-today-head">
    <div class="rt-personal-today-head__text">
        <p class="rt-personal-work-eyebrow">{{ $now->locale('de')->isoFormat('dddd, D. MMMM') }}</p>
        <h2>{{ $greeting }}{{ $firstName !== '' ? ', '.$firstName : '' }}</h2>
        <p class="rt-personal-today-head__duty" @if($nextDuty) data-has-duty @endif>
            <span class="rt-personal-today-head__duty-label">{{ $dutyLabel }}</span>
            @if($nextDuty)
                <span>{{ $nextStart->format('H:i') }} – {{ $nextEnd->format($nextStart->isSameDay($nextEnd) ? 'H:i' : 'd.m. H:i') }} · {{ $nextDuty->shift->title }}@if($nextDuty->shift->location_name) · {{ $nextDuty->shift->location_name }}@endif</span>
            @else
                <span class="ops-muted">Interne Arbeit oder Schulungen erfasst du unten direkt.</span>
            @endif
        </p>
    </div>
    <x-ui.buttons.button-basic type="button" data-href="{{ route('operations.mine', ['area' => 'absences', 'new' => 1]) }}" x-on:click="Livewire.navigate($el.dataset.href)" class="rt-personal-leave-shortcut"><i class="fas fa-umbrella-beach" aria-hidden="true"></i>Urlaub planen</x-ui.buttons.button-basic>
</header>
