@php
    $shiftStatusClass = static fn (string $status): string => match ($status) {
        'confirmed', 'completed', 'staffed' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:!border-emerald-800 dark:!bg-emerald-500/10 dark:!text-emerald-300',
        'open', 'requested' => 'border-amber-200 bg-amber-50 text-amber-700 dark:!border-amber-800 dark:!bg-amber-500/10 dark:!text-amber-300',
        'in_progress' => 'border-sky-200 bg-sky-50 text-sky-700 dark:!border-sky-800 dark:!bg-sky-500/10 dark:!text-sky-300',
        'cancelled' => 'border-slate-200 bg-slate-100 text-slate-500 dark:!border-slate-700 dark:!bg-slate-800 dark:!text-slate-300',
        default => 'border-rose-200 bg-rose-50 text-rt-red dark:!border-rose-900 dark:!bg-rose-500/10 dark:!text-rose-300',
    };
@endphp

<div class="space-y-4" data-operations-shifts>
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Besetzungsübersicht">
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
        <header class="flex flex-col gap-3 border-b border-rt-border/70 p-4 dark:border-rt-dark-border/70 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid flex-1 gap-2 sm:grid-cols-3">
                <label class="block">
                    <span class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.1em] text-rt-soft">Von</span>
                    <input type="date" wire:model.live="rangeFrom" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text shadow-rt-xs outline-none focus:border-rt-red sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white">
                </label>
                <label class="block">
                    <span class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.1em] text-rt-soft">Bis</span>
                    <input type="date" wire:model.live="rangeTo" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text shadow-rt-xs outline-none focus:border-rt-red sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white">
                </label>
                <label class="block">
                    <span class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.1em] text-rt-soft">Auftrag</span>
                    <select wire:model.live="orderFilter" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text shadow-rt-xs outline-none focus:border-rt-red sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white">
                        <option value="all">Alle Aufträge</option>
                        @foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach
                    </select>
                </label>
            </div>
            <button type="button" wire:click="createShift" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2.5 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:opacity-60">
                <i class="far fa-plus" aria-hidden="true"></i>
                Neue Schicht
            </button>
        </header>

        <div class="grid min-h-[35rem] xl:grid-cols-[minmax(22rem,.86fr)_minmax(0,1.14fr)]">
            <div class="border-b border-rt-border/70 dark:border-rt-dark-border/70 xl:border-b-0 xl:border-r">
                <div class="max-h-[54rem] space-y-2 overflow-y-auto p-3">
                    @forelse($shifts as $shift)
                        @php
                            $shiftStatus = $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status;
                            $activeAssignments = $shift->assignments->filter(fn ($assignment) => in_array(
                                $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                                ['requested', 'confirmed'],
                                true,
                            ));
                        @endphp
                        <button
                            type="button"
                            wire:click="selectShift({{ $shift->id }})"
                            wire:key="shift-list-{{ $shift->id }}"
                            @class([
                                'block min-h-28 w-full rounded-xl border p-3.5 text-left transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15',
                                'border-rt-red bg-rose-50/80 shadow-rt-xs dark:bg-rose-500/10' => $selectedShift?->id === $shift->id,
                                'border-rt-border/70 hover:border-rt-red/30 hover:bg-rt-surface-muted/70 dark:border-rt-dark-border/70 dark:hover:bg-rt-dark-surface-muted/60' => $selectedShift?->id !== $shift->id,
                            ])
                        >
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block truncate text-xs font-semibold text-rt-red">{{ $shift->order?->order_number }}</span>
                                    <span class="mt-1 block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $shift->title }}</span>
                                </span>
                                <span class="shrink-0 rounded-lg border px-2 py-1 text-[10px] font-semibold {{ $shiftStatusClass($shiftStatus) }}">{{ method_exists($shift->status, 'label') ? $shift->status->label() : \Illuminate\Support\Str::headline($shiftStatus) }}</span>
                            </span>
                            <span class="mt-2 grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                                <span class="font-semibold tabular-nums text-rt-text dark:text-white">{{ $shift->starts_at?->format('d.m. H:i') }}</span>
                                <span class="truncate">{{ $shift->role_name }}@if($shift->location_name) · {{ $shift->location_name }}@endif</span>
                                <span @class(['font-semibold tabular-nums', 'text-emerald-600 dark:text-emerald-300' => $activeAssignments->count() >= $shift->required_staff, 'text-rt-red' => $activeAssignments->count() < $shift->required_staff])>{{ $activeAssignments->count() }}/{{ $shift->required_staff }}</span>
                            </span>
                            @if($activeAssignments->isNotEmpty())
                                <span class="mt-2 flex -space-x-1.5" aria-label="Zugewiesene Mitarbeitende">
                                    @foreach($activeAssignments->take(5) as $assignment)
                                        <img src="{{ $assignment->user?->profile_photo_url }}" alt="{{ $assignment->user?->name }}" title="{{ $assignment->user?->name }}" class="h-7 w-7 rounded-full border-2 border-white object-cover dark:border-rt-dark-surface" wire:key="shift-list-assignee-{{ $assignment->id }}">
                                    @endforeach
                                </span>
                            @endif
                        </button>
                    @empty
                        <div class="px-6 py-16 text-center">
                            <i class="fad fa-calendar-plus text-3xl text-rt-soft" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Keine Schichten im Zeitraum</h3>
                            <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Passe Zeitraum und Auftrag an oder lege eine neue Schicht an.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <aside class="min-w-0 p-4 sm:p-5" data-shift-detail>
                @if($selectedShift)
                    @php
                        $selectedShiftStatus = $selectedShift->status instanceof \BackedEnum ? $selectedShift->status->value : (string) $selectedShift->status;
                        $selectedAssignments = $selectedShift->assignments->filter(fn ($assignment) => in_array(
                            $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                            ['requested', 'confirmed'],
                            true,
                        ));
                        $selectedReservedCount = $selectedAssignments->count();
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-rt-red">{{ $selectedShift->order?->order_number }}</p>
                            <h2 class="mt-1 break-words text-xl font-semibold tracking-tight text-rt-text dark:text-white">{{ $selectedShift->title }}</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $selectedShift->order?->customer?->company_name }} · {{ $selectedShift->role_name }}</p>
                        </div>
                        <button type="button" wire:click="editShift({{ $selectedShift->id }})" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                            <i class="far fa-pen" aria-hidden="true"></i>Bearbeiten
                        </button>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl bg-rt-surface-muted/60 p-3.5 dark:bg-rt-dark-surface-muted/50 sm:col-span-2">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Zeit &amp; Ort</p>
                            <p class="mt-2 text-sm font-semibold text-rt-text dark:text-white">{{ $selectedShift->starts_at?->format('d.m.Y H:i') }} – {{ $selectedShift->ends_at?->format('d.m.Y H:i') }}</p>
                            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $selectedShift->location_name ?: $selectedShift->order?->location_name ?: 'Kein Einsatzort hinterlegt' }}</p>
                        </div>
                        <div class="rounded-xl bg-rt-surface-muted/60 p-3.5 dark:bg-rt-dark-surface-muted/50">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Besetzung</p>
                            <p @class(['mt-2 text-xl font-semibold tabular-nums', 'text-emerald-600 dark:text-emerald-300' => $selectedReservedCount >= $selectedShift->required_staff, 'text-rt-red' => $selectedReservedCount < $selectedShift->required_staff])>{{ $selectedReservedCount }}/{{ $selectedShift->required_staff }}</p>
                            <span class="mt-1 inline-flex rounded-md border px-2 py-0.5 text-[10px] font-semibold {{ $shiftStatusClass($selectedShiftStatus) }}">{{ method_exists($selectedShift->status, 'label') ? $selectedShift->status->label() : \Illuminate\Support\Str::headline($selectedShiftStatus) }}</span>
                        </div>
                    </div>

                    <section class="mt-5">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-rt-text dark:text-white">Eingeteilte Mitarbeitende</h3>
                            <span class="text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $selectedAssignments->count() }} Zuweisungen</span>
                        </div>
                        <div class="mt-2 divide-y divide-rt-border/60 rounded-xl border border-rt-border/70 dark:divide-rt-dark-border/60 dark:border-rt-dark-border/70">
                            @forelse($selectedAssignments as $assignment)
                                @php($assignmentValue = $assignment->status instanceof \BackedEnum ? $assignment->status->value : (string) $assignment->status)
                                <div class="flex min-h-16 items-center gap-3 px-3.5 py-2.5" wire:key="shift-assignment-{{ $assignment->id }}">
                                    <img src="{{ $assignment->user?->profile_photo_url }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $assignment->user?->name ?? 'Unbekannter Mitarbeiter' }}</p>
                                        <p class="mt-0.5 truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ method_exists($assignment->status, 'label') ? $assignment->status->label() : \Illuminate\Support\Str::headline($assignmentValue) }}@if($assignment->note) · {{ $assignment->note }}@endif</p>
                                    </div>
                                    <button type="button" wire:click="removeAssignment({{ $assignment->id }})" wire:confirm="Zuweisung wirklich entfernen?" wire:loading.attr="disabled" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-rt-muted transition hover:bg-red-50 hover:text-rt-red disabled:opacity-60 dark:text-rt-dark-muted dark:hover:bg-red-500/10" aria-label="{{ $assignment->user?->name }} aus der Schicht entfernen" title="Entfernen">
                                        <i class="far fa-times" aria-hidden="true"></i>
                                    </button>
                                </div>
                            @empty
                                <p class="px-4 py-7 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch niemand eingeteilt. Weise unten den ersten Mitarbeiter zu.</p>
                            @endforelse
                        </div>
                    </section>

                    @if($selectedShiftStatus === 'cancelled')
                        <section class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:!border-slate-700 dark:!bg-slate-800/60 dark:!text-slate-300">
                            <p class="font-semibold text-rt-text dark:text-white">Schicht storniert</p>
                            <p class="mt-1 leading-5">Für eine stornierte Schicht können keine weiteren Mitarbeitenden reserviert werden.</p>
                        </section>
                    @else
                    <section class="mt-5 rounded-xl border border-rose-200 bg-rose-50/60 p-4 dark:border-rose-900 dark:bg-rose-500/10">
                        <h3 class="text-sm font-semibold text-rt-text dark:text-white">Mitarbeiter zuweisen</h3>
                        <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Zeitüberschneidungen mit aktiven Einsätzen werden vor dem Speichern geprüft.</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-ui.forms.label for="assignment-employee" value="Mitarbeiter" />
                                <x-ui.forms.select id="assignment-employee" wire:model="employeeId" class="mt-1" placeholder="Mitarbeiter auswählen">
                                    @foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}@if($employee->profile?->position) · {{ $employee->profile->position }}@endif</option>@endforeach
                                </x-ui.forms.select>
                                @error('employeeId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-ui.forms.label for="assignment-status" value="Zuweisungsstatus" />
                                <x-ui.forms.select id="assignment-status" wire:model="assignmentStatus" class="mt-1">
                                    @foreach($assignmentStatusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                                </x-ui.forms.select>
                                @error('assignmentStatus') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <x-ui.forms.label for="assignment-note" value="Hinweis (optional)" />
                                <x-ui.forms.input id="assignment-note" wire:model="assignmentNote" class="mt-1" />
                                @error('assignmentNote') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        @error('assignment') <p class="mt-3 rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">{{ $message }}</p> @enderror
                        <button type="button" wire:click="assignEmployee" wire:loading.attr="disabled" class="mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:opacity-60 sm:w-auto">
                            <i wire:loading.remove wire:target="assignEmployee" class="far fa-user-plus" aria-hidden="true"></i>
                            <i wire:loading wire:target="assignEmployee" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                            Zuweisen
                        </button>
                    </section>
                    @endif
                @else
                    <div class="flex min-h-80 flex-col items-center justify-center text-center">
                        <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                        <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Schicht auswählen</h2>
                        <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle eine Schicht aus, um die Besetzung zu planen.</p>
                    </div>
                @endif
            </aside>
        </div>
    </section>

    <x-dialog-modal wire:model="formOpen" maxWidth="3xl">
        <x-slot:title>{{ $editingShiftId ? 'Schicht bearbeiten' : 'Neue Schicht anlegen' }}</x-slot:title>
        <x-slot:content>
            @error('schedule')
                <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-500/10 dark:text-red-300" role="alert">{{ $message }}</p>
            @enderror
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="shift-order" value="Auftrag" />
                    <x-ui.forms.select id="shift-order" wire:model="orderId" class="mt-1" placeholder="Auftrag auswählen">
                        @foreach($orders as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->title }}</option>@endforeach
                    </x-ui.forms.select>
                    @error('orderId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-title" value="Schichttitel" />
                    <x-ui.forms.input id="shift-title" wire:model="title" class="mt-1" />
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-role" value="Rolle / Funktion" />
                    <x-ui.forms.input id="shift-role" wire:model="roleName" class="mt-1" placeholder="z. B. Triebfahrzeugführer" />
                    @error('roleName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-start" value="Beginn" />
                    <x-ui.forms.input id="shift-start" type="datetime-local" wire:model="startsAt" class="mt-1" />
                    @error('startsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-end" value="Ende" />
                    <x-ui.forms.input id="shift-end" type="datetime-local" wire:model="endsAt" class="mt-1" />
                    @error('endsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-required-staff" value="Benötigte Mitarbeitende" />
                    <x-ui.forms.input id="shift-required-staff" type="number" min="1" wire:model="requiredStaff" class="mt-1" />
                    @error('requiredStaff') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="shift-status" value="Schichtstatus" />
                    <x-ui.forms.select id="shift-status" wire:model="status" class="mt-1">
                        @foreach($statusOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                    </x-ui.forms.select>
                    @error('status') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="shift-location" value="Einsatzort (leer = Auftrag übernehmen)" />
                    <x-ui.forms.input id="shift-location" wire:model="locationName" class="mt-1" />
                    @error('locationName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="shift-notes" value="Interne Notizen" />
                    <x-ui.forms.textarea id="shift-notes" wire:model="notes" rows="4" class="mt-1" />
                    @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-slot:content>
        <x-slot:footer>
            <button type="button" x-on:click="$dispatch('close')" class="inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white">Abbrechen</button>
            <button type="button" wire:click="saveShift" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60">
                <i wire:loading.remove wire:target="saveShift" class="far fa-check" aria-hidden="true"></i>
                <i wire:loading wire:target="saveShift" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                Speichern
            </button>
        </x-slot:footer>
    </x-dialog-modal>
</div>
