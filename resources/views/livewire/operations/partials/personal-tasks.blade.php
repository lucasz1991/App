{{-- Persönliche Arbeitsliste auf „Mein Arbeitstag“: kompakte Liste statt Verwaltungstabelle. --}}
@php
    $zone = config('operations.display_timezone', 'Europe/Berlin');
    $remindersAvailable = $preferencesReady;
@endphp
<header class="rt-today-card__head">
    <div>
        <h3>Meine Aufgaben @if($tab === 'inbox' && $items->total())<span class="rt-today-card__count">{{ $items->total() }}</span>@endif</h3>
        <p>{{ $tab === 'inbox' ? 'Freigaben, Rückfragen und Angebote für dich' : 'Wann und wie RailTime dich erinnert' }}</p>
    </div>
    @if($remindersAvailable)
        <div class="rt-today-segment" role="group" aria-label="Ansicht">
            <button type="button" wire:click="setTab('inbox')" aria-pressed="{{ $tab === 'inbox' ? 'true' : 'false' }}">Offen</button>
            <button type="button" wire:click="setTab('reminders')" aria-pressed="{{ $tab === 'reminders' ? 'true' : 'false' }}">Erinnerungen</button>
        </div>
    @endif
</header>
<x-operations.feedback />
@if($tab === 'inbox')
    @if($search !== '' || $items->total() > 8)<x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Aufgaben durchsuchen" />@endif
    @if($items->isEmpty())
        <div class="rt-today-empty">
            <span class="rt-today-empty__icon" data-tone="success" aria-hidden="true"><i class="fas fa-check"></i></span>
            <strong>{{ $search !== '' ? 'Keine Treffer' : 'Alles erledigt' }}</strong>
            <p>{{ $search !== '' ? 'Für diese Suche gibt es keine offenen Aufgaben.' : 'Nichts offen – du bist auf dem aktuellen Stand.' }}</p>
        </div>
    @else
        <ul class="rt-task-list">
            @foreach($items as $item)
                @php($due = $item->due_at?->setTimezone($zone))
                <li wire:key="personal-task-{{ $item->id }}">
                    <button type="button" class="rt-task" wire:click="openItem('{{ $item->id }}')" wire:loading.attr="disabled" wire:target="openItem('{{ $item->id }}')" @if($due && $due->isPast()) data-overdue @endif>
                        <span class="rt-task__icon" aria-hidden="true"><i class="{{ match (true) { str_contains(mb_strtolower((string) $item->kind), 'angebot') || str_contains((string) $item->id, 'offer') => 'fas fa-hand-holding', str_contains(mb_strtolower((string) $item->kind), 'erinnerung') => 'far fa-bell', str_contains(mb_strtolower((string) $item->kind), 'unterschrift') || str_contains(mb_strtolower((string) $item->kind), 'unterzeich') => 'fas fa-signature', default => 'far fa-circle' } }}"></i></span>
                        <span class="rt-task__text">
                            <strong>{{ $item->title }}</strong>
                            <span>{{ $item->kind }}@if(filled($item->subject)) · {{ $item->subject }}@endif</span>
                        </span>
                        <span class="rt-task__due">
                            @if($due)
                                {{ $due->isPast() ? 'Überfällig' : ($due->isToday() ? 'Heute '.$due->format('H:i') : $due->format('d.m.')) }}
                            @else
                                {{ \App\Support\Operations\OperationsNavigation::status($item->status) }}
                            @endif
                        </span>
                        <i class="fas fa-chevron-right rt-task__chevron" aria-hidden="true"></i>
                    </button>
                </li>
            @endforeach
        </ul>
        {{ $items->links() }}
    @endif
@else
    <div class="rt-today-card__toolbar"><x-ui.buttons.button-basic type="button" size="sm" wire:click="edit">Erinnerung anlegen</x-ui.buttons.button-basic></div>
    @if($items->isEmpty())
        <div class="rt-today-empty">
            <span class="rt-today-empty__icon" aria-hidden="true"><i class="far fa-bell"></i></span>
            <strong>Noch keine Erinnerungen</strong>
            <p>Lege fest, wie früh RailTime dich vor Diensten oder Fristen erinnert.</p>
        </div>
    @else
        <ul class="rt-task-list">
            @foreach($items as $item)
                <li wire:key="personal-reminder-{{ $item->id }}">
                    <button type="button" class="rt-task" wire:click="edit({{ $item->id }})">
                        <span class="rt-task__icon" aria-hidden="true"><i class="far fa-bell"></i></span>
                        <span class="rt-task__text">
                            <strong>{{ \App\Services\Operations\OperationsReminderService::KINDS[$item->kind] ?? $item->kind }}</strong>
                            <span>{{ $item->lead_minutes }} min vorher @if($item->quiet_from)· Ruhe {{ $item->quiet_from }}–{{ $item->quiet_until }}@endif</span>
                        </span>
                        <span class="rt-task__due">{{ $item->is_active ? 'Aktiv' : 'Pausiert' }}</span>
                        <i class="fas fa-chevron-right rt-task__chevron" aria-hidden="true"></i>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
@endif
