{{-- Kompakte Tagesaktionen; die nativen Start-, Revisions- und Erfassungsregeln bleiben erhalten. --}}
@php
    $actionDuty = $assignments->first(fn ($assignment) => $assignment->shift?->ends_at?->isFuture());
    $actionNow = now($displayTimezone);
    $actionStart = $actionDuty?->shift->starts_at->copy()->setTimezone($displayTimezone);
    $actionEnd = $actionDuty?->shift->ends_at->copy()->setTimezone($displayTimezone);
    $actionDay = $actionStart ? match (true) {
        $actionStart->isSameDay($actionNow) => 'Heute',
        $actionStart->isSameDay($actionNow->copy()->addDay()) => 'Morgen',
        default => $actionStart->locale('de')->isoFormat('dd, D. MMM'),
    } : null;
@endphp
@if($actionDuty || $workTimeReady || $activeTime)
    <section class="rt-workday-actions" aria-label="Dienst und Arbeitszeit" data-workday-actions>
        @if($actionDuty)
            <div class="rt-workday-actions__duty">
                <div class="rt-workday-actions__summary">
                    <p>{{ $actionDay }} · {{ $actionStart->format('H:i') }} – {{ $actionEnd->format($actionStart->isSameDay($actionEnd) ? 'H:i' : 'd.m. H:i') }}</p>
                    <strong title="{{ $actionDuty->shift->title }}">{{ $actionDuty->shift->title }}</strong>
                </div>
                <div class="rt-workday-actions__duty-controls">
                    @if($actionDuty->status->value === 'requested' && ! $actionDuty->plan_is_stale)
                        <x-ui.buttons.button-basic mode="primary" wire:click="openConsentRequest('shift',{{ $actionDuty->id }},{{ $actionDuty->shift->revision }})" wire:loading.attr="disabled">Anfrage prüfen</x-ui.buttons.button-basic>
                    @else
                        @include('livewire.operations.partials.personal-shift-actions', ['assignment' => $actionDuty, 'shift' => $actionDuty->shift])
                    @endif
                </div>
            </div>
        @endif
        @if($workTimeReady || $activeTime)
            <div class="rt-workday-actions__clock">
                @include('livewire.operations.partials.personal-clock', ['compactMobileClock' => false, 'compactActionClock' => true])
            </div>
        @endif
    </section>
@endif
