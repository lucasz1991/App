@php
    $calendarStatusClass = static fn (string $status): string => match ($status) {
        'confirmed', 'completed', 'staffed' => 'border-emerald-500 bg-emerald-50 text-emerald-800 dark:!bg-emerald-500/10 dark:!text-emerald-200',
        'open', 'requested' => 'border-amber-500 bg-amber-50 text-amber-800 dark:!bg-amber-500/10 dark:!text-amber-200',
        'in_progress' => 'border-sky-500 bg-sky-50 text-sky-800 dark:!bg-sky-500/10 dark:!text-sky-200',
        'cancelled' => 'border-slate-400 bg-slate-100 text-slate-500 opacity-70 dark:!bg-slate-800 dark:!text-slate-300',
        default => 'border-rt-red bg-rose-50 text-rose-800 dark:!bg-rose-500/10 dark:!text-rose-200',
    };
@endphp

<div class="space-y-4" data-operations-calendar data-calendar-week="{{ $weekStartDate->toDateString() }}">
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Wochenübersicht">
        <article class="rounded-2xl border border-rt-border/70 bg-rt-surface p-4 shadow-rt-xs dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">Schichten</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $shiftCount }}</p>
        </article>
        <article class="rounded-2xl border border-rt-border/70 bg-rt-surface p-4 shadow-rt-xs dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">Benötigt</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $requiredCount }}</p>
        </article>
        <article class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-rt-xs dark:!border-emerald-800 dark:!bg-emerald-500/10">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700 dark:text-emerald-300">Reserviert</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $reservedCount }}</p>
        </article>
        <article class="rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-rt-xs dark:!border-rose-900 dark:!bg-rose-500/10">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-red dark:text-rose-300">Offene Plätze</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $openCount }}</p>
        </article>
    </section>

    <section class="overflow-hidden rounded-2xl border border-rt-border/70 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
        <header class="flex flex-col gap-3 border-b border-rt-border/70 p-4 dark:border-rt-dark-border/70 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-red">Einsatzwoche</p>
                <h2 class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-white">{{ $weekStartDate->format('d.m.') }} – {{ $weekEndDate->format('d.m.Y') }}</h2>
            </div>
            <div class="grid grid-cols-[2.75rem_minmax(7rem,1fr)_2.75rem] gap-2 sm:flex" role="group" aria-label="Kalenderwoche wechseln">
                <button type="button" wire:click="previousWeek" wire:loading.attr="disabled" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-rt-border bg-rt-surface text-rt-text transition hover:border-rt-red/30 hover:text-rt-red disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white" aria-label="Vorherige Woche">
                    <i class="far fa-chevron-left" aria-hidden="true"></i>
                </button>
                <button type="button" wire:click="today" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-rt-border bg-rt-surface px-4 text-sm font-semibold text-rt-text transition hover:border-rt-red/30 hover:text-rt-red disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white">Heute</button>
                <button type="button" wire:click="nextWeek" wire:loading.attr="disabled" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-rt-border bg-rt-surface text-rt-text transition hover:border-rt-red/30 hover:text-rt-red disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white" aria-label="Nächste Woche">
                    <i class="far fa-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <div class="hidden grid-cols-7 divide-x divide-rt-border/60 lg:grid dark:divide-rt-dark-border/60" data-calendar-desktop-grid>
            @foreach($days as $day)
                <article
                    wire:key="calendar-day-desktop-{{ $day['date']->toDateString() }}"
                    data-calendar-day="{{ $day['date']->toDateString() }}"
                    @class(['min-w-0', 'bg-rose-50/30 dark:bg-rose-500/5' => $day['is_today']])
                >
                    <header class="border-b border-rt-border/60 px-2.5 py-3 text-center dark:border-rt-dark-border/60">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">{{ $day['date']->locale('de')->isoFormat('dd') }}</p>
                        <p @class(['mt-1 text-sm font-semibold tabular-nums', 'mx-auto flex h-8 w-8 items-center justify-center rounded-full bg-rt-red text-white' => $day['is_today'], 'text-rt-text dark:text-white' => ! $day['is_today']])>{{ $day['date']->format('d.m.') }}</p>
                    </header>
                    <div class="min-h-[32rem] space-y-2 p-2">
                        @forelse($day['shifts'] as $shift)
                            @php
                                $calendarStatus = $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status;
                                $calendarReserved = $shift->assignments->filter(fn ($assignment) => in_array(
                                    $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                                    \App\Enums\ShiftAssignmentStatus::blockingValues(),
                                    true,
                                ))->count();
                            @endphp
                            <article class="rounded-lg border-l-4 p-2.5 shadow-rt-xs {{ $calendarStatusClass($calendarStatus) }}" wire:key="calendar-shift-desktop-{{ $day['date']->toDateString() }}-{{ $shift->id }}" data-calendar-shift="{{ $shift->id }}">
                                <p class="text-[10px] font-bold tabular-nums">{{ $shift->starts_at?->format('H:i') }}–{{ $shift->ends_at?->format('H:i') }}</p>
                                <h3 class="mt-1 break-words text-xs font-semibold leading-4">{{ $shift->title }}</h3>
                                <p class="mt-1 truncate text-[10px] opacity-80">{{ $shift->order?->order_number }}</p>
                                <p class="mt-1 text-[10px] font-semibold tabular-nums"><i class="far fa-users mr-1" aria-hidden="true"></i>{{ $calendarReserved }}/{{ $shift->required_staff }}</p>
                            </article>
                        @empty
                            <p class="px-1 py-6 text-center text-[11px] text-rt-soft dark:text-rt-dark-soft">Keine Schichten</p>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>

        <div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60 lg:hidden" data-calendar-mobile-agenda>
            @foreach($days as $day)
                <section wire:key="calendar-day-mobile-{{ $day['date']->toDateString() }}" data-calendar-day="{{ $day['date']->toDateString() }}" class="p-4">
                    <header class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span @class(['flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sm font-semibold tabular-nums', 'bg-rt-red text-white' => $day['is_today'], 'bg-rt-surface-muted text-rt-text dark:bg-rt-dark-surface-muted dark:text-white' => ! $day['is_today']])>{{ $day['date']->format('d') }}</span>
                            <div>
                                <h3 class="text-sm font-semibold text-rt-text dark:text-white">{{ $day['date']->locale('de')->isoFormat('dddd') }}</h3>
                                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $day['date']->format('d.m.Y') }}</p>
                            </div>
                        </div>
                        <span class="text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $day['shifts']->count() }}</span>
                    </header>
                    <div class="mt-3 space-y-2">
                        @forelse($day['shifts'] as $shift)
                            @php
                                $mobileStatus = $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status;
                                $mobileReserved = $shift->assignments->filter(fn ($assignment) => in_array(
                                    $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                                    \App\Enums\ShiftAssignmentStatus::blockingValues(),
                                    true,
                                ))->count();
                            @endphp
                            <article class="rounded-xl border-l-4 p-3.5 {{ $calendarStatusClass($mobileStatus) }}" wire:key="calendar-shift-mobile-{{ $day['date']->toDateString() }}-{{ $shift->id }}" data-calendar-shift="{{ $shift->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold tabular-nums">{{ $shift->starts_at?->format('H:i') }}–{{ $shift->ends_at?->format('H:i') }}</p>
                                        <h4 class="mt-1 break-words text-sm font-semibold">{{ $shift->title }}</h4>
                                        <p class="mt-1 truncate text-xs opacity-80">{{ $shift->order?->order_number }} · {{ $shift->order?->customer?->company_name }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-lg bg-white/70 px-2 py-1 text-xs font-semibold tabular-nums dark:bg-black/15"><i class="far fa-users mr-1" aria-hidden="true"></i>{{ $mobileReserved }}/{{ $shift->required_staff }}</span>
                                </div>
                                <p class="mt-2 text-xs opacity-80"><i class="far fa-map-marker-alt mr-1" aria-hidden="true"></i>{{ $shift->location_name ?: $shift->order?->location_name ?: 'Kein Einsatzort' }}</p>
                            </article>
                        @empty
                            <p class="rounded-xl border border-dashed border-rt-border px-4 py-5 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">Keine Schichten geplant.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </section>
</div>
