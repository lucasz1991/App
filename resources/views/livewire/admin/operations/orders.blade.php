@php
    $statusClass = static fn (string $status): string => match ($status) {
        'confirmed', 'completed', 'invoiced' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:!border-emerald-800 dark:!bg-emerald-500/10 dark:!text-emerald-300',
        'planned', 'requested' => 'border-amber-200 bg-amber-50 text-amber-700 dark:!border-amber-800 dark:!bg-amber-500/10 dark:!text-amber-300',
        'in_progress' => 'border-sky-200 bg-sky-50 text-sky-700 dark:!border-sky-800 dark:!bg-sky-500/10 dark:!text-sky-300',
        'cancelled' => 'border-slate-200 bg-slate-100 text-slate-500 dark:!border-slate-700 dark:!bg-slate-800 dark:!text-slate-300',
        default => 'border-rose-200 bg-rose-50 text-rt-red dark:!border-rose-900 dark:!bg-rose-500/10 dark:!text-rose-300',
    };
@endphp

<div class="space-y-4" data-operations-orders>
    <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Auftragsübersicht">
        <article class="rounded-2xl border border-rt-border/70 bg-rt-surface p-4 shadow-rt-xs dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">Offene Aufträge</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $openCount }}</p>
        </article>
        <article class="rounded-2xl border border-rt-border/70 bg-rt-surface p-4 shadow-rt-xs dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">Nächste 7 Tage</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $startsSoonCount }}</p>
        </article>
        <article class="rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-rt-xs dark:!border-rose-900 dark:!bg-rose-500/10">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-red dark:text-rose-300">In dieser Auswahl</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $orders->count() }}</p>
        </article>
    </section>

    <section class="overflow-hidden rounded-2xl border border-rt-border/70 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
        <header class="flex flex-col gap-3 border-b border-rt-border/70 p-4 dark:border-rt-dark-border/70 sm:flex-row sm:items-center sm:justify-between">
            <div class="grid flex-1 gap-2 sm:grid-cols-[minmax(15rem,1fr)_12rem]">
                <label class="relative block">
                    <span class="sr-only">Aufträge durchsuchen</span>
                    <i class="far fa-search pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-rt-soft" aria-hidden="true"></i>
                    <input type="search" wire:model.live.debounce.300ms="search" class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control py-2.5 pl-10 pr-3.5 text-base text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft focus:border-rt-red sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white" placeholder="Nummer, Kunde oder Leistung">
                </label>
                <select wire:model.live="statusFilter" class="min-h-11 rounded-xl border border-rt-border bg-rt-control px-3.5 text-base text-rt-text shadow-rt-xs outline-none focus:border-rt-red sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white">
                    <option value="all">Alle Status</option>
                    @foreach($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" wire:click="createOrder" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2.5 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:opacity-60">
                <i class="far fa-plus" aria-hidden="true"></i>
                Neuer Auftrag
            </button>
        </header>

        <div class="grid min-h-[34rem] xl:grid-cols-[minmax(21rem,.82fr)_minmax(0,1.18fr)]">
            <div class="border-b border-rt-border/70 dark:border-rt-dark-border/70 xl:border-b-0 xl:border-r">
                <div class="max-h-[52rem] divide-y divide-rt-border/60 overflow-y-auto dark:divide-rt-dark-border/60">
                    @forelse($orders as $order)
                        @php
                            $orderStatus = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;
                            $orderStatusLabel = method_exists($order->status, 'label') ? $order->status->label() : \Illuminate\Support\Str::headline($orderStatus);
                        @endphp
                        <button
                            type="button"
                            wire:click="selectOrder({{ $order->id }})"
                            wire:key="order-list-{{ $order->id }}"
                            @class([
                                'block min-h-24 w-full border-l-4 px-4 py-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red/35',
                                'border-rt-red bg-rose-50/80 dark:bg-rose-500/10' => $selectedOrder?->id === $order->id,
                                'border-transparent hover:bg-rt-surface-muted/70 dark:hover:bg-rt-dark-surface-muted/60' => $selectedOrder?->id !== $order->id,
                            ])
                        >
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="text-xs font-semibold tabular-nums text-rt-red">{{ $order->order_number }}</span>
                                    <span class="mt-1 block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $order->title }}</span>
                                </span>
                                <span class="shrink-0 rounded-lg border px-2 py-1 text-[10px] font-semibold {{ $statusClass($orderStatus) }}">{{ $orderStatusLabel }}</span>
                            </span>
                            <span class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-rt-muted dark:text-rt-dark-muted">
                                <span class="truncate"><i class="far fa-building mr-1" aria-hidden="true"></i>{{ $order->customer?->company_name ?? 'Kein Kunde' }}</span>
                                <span><i class="far fa-calendar mr-1" aria-hidden="true"></i>{{ $order->starts_at?->format('d.m.Y H:i') }}</span>
                                <span><i class="far fa-users mr-1" aria-hidden="true"></i>{{ $order->required_staff }}</span>
                            </span>
                        </button>
                    @empty
                        <div class="px-6 py-16 text-center">
                            <i class="fad fa-clipboard-list text-3xl text-rt-soft" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Keine Aufträge gefunden</h3>
                            <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Passe die Filter an oder lege den ersten Auftrag an.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <aside class="min-w-0 p-4 sm:p-5" data-order-detail>
                @if($selectedOrder)
                    @php
                        $selectedStatus = $selectedOrder->status instanceof \BackedEnum ? $selectedOrder->status->value : (string) $selectedOrder->status;
                        $selectedPriority = $selectedOrder->priority instanceof \BackedEnum ? $selectedOrder->priority->value : (string) $selectedOrder->priority;
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-rt-red">{{ $selectedOrder->order_number }}</p>
                            <h2 class="mt-1 break-words text-xl font-semibold tracking-tight text-rt-text dark:text-white">{{ $selectedOrder->title }}</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $selectedOrder->customer?->company_name }}</p>
                        </div>
                        <button type="button" wire:click="editOrder({{ $selectedOrder->id }})" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                            <i class="far fa-pen" aria-hidden="true"></i>Bearbeiten
                        </button>
                    </div>

                    <div class="mt-5 rounded-xl border border-rt-border/70 bg-rt-surface-muted/50 p-3.5 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/40">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Aktueller Status</p>
                                <span class="mt-1.5 inline-flex rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $statusClass($selectedStatus) }}">{{ method_exists($selectedOrder->status, 'label') ? $selectedOrder->status->label() : \Illuminate\Support\Str::headline($selectedStatus) }}</span>
                            </div>
                            <label class="block w-full sm:w-56">
                                <span class="mb-1 block text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">Status ändern</span>
                                <select wire:key="order-status-transition-{{ $selectedStatus }}" wire:change="changeStatus($event.target.value)" wire:loading.attr="disabled" @disabled(empty($transitionOptions)) class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3 text-base text-rt-text outline-none focus:border-rt-red disabled:cursor-not-allowed disabled:opacity-60 sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white">
                                    <option value="" selected disabled>{{ empty($transitionOptions) ? 'Kein Folgestatus verfügbar' : 'Folgestatus auswählen' }}</option>
                                    @foreach($transitionOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        @error('statusChange') <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p> @enderror
                    </div>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl bg-rt-surface-muted/60 p-3.5 dark:bg-rt-dark-surface-muted/50">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Zeitraum</dt>
                            <dd class="mt-2 text-sm font-semibold text-rt-text dark:text-white">{{ $selectedOrder->starts_at?->format('d.m.Y H:i') }} – {{ $selectedOrder->ends_at?->format('d.m.Y H:i') }}</dd>
                        </div>
                        <div class="rounded-xl bg-rt-surface-muted/60 p-3.5 dark:bg-rt-dark-surface-muted/50">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Bedarf</dt>
                            <dd class="mt-2 text-sm font-semibold text-rt-text dark:text-white">{{ $selectedOrder->required_staff }} Mitarbeitende · {{ \Illuminate\Support\Str::headline($selectedPriority) }}</dd>
                        </div>
                        <div class="rounded-xl bg-rt-surface-muted/60 p-3.5 dark:bg-rt-dark-surface-muted/50 sm:col-span-2">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Einsatzort</dt>
                            <dd class="mt-2 text-sm text-rt-text dark:text-white">
                                {{ $selectedOrder->location_name ?: 'Ohne Ortsbezeichnung' }}
                                @if($selectedOrder->street || $selectedOrder->postal_code || $selectedOrder->city)
                                    <span class="mt-0.5 block text-rt-muted dark:text-rt-dark-muted">{{ collect([$selectedOrder->street, trim(($selectedOrder->postal_code ?? '').' '.($selectedOrder->city ?? '')), $selectedOrder->country])->filter()->join(', ') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if($selectedOrder->description || $selectedOrder->notes)
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @if($selectedOrder->description)
                                <div class="rounded-xl border border-rt-border/70 p-3.5 dark:border-rt-dark-border/70">
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Beschreibung</p>
                                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $selectedOrder->description }}</p>
                                </div>
                            @endif
                            @if($selectedOrder->notes)
                                <div class="rounded-xl border border-rt-border/70 p-3.5 dark:border-rt-dark-border/70">
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Interne Notiz</p>
                                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $selectedOrder->notes }}</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <section>
                            <h3 class="text-sm font-semibold text-rt-text dark:text-white">Schichten</h3>
                            <div class="mt-2 divide-y divide-rt-border/60 rounded-xl border border-rt-border/70 dark:divide-rt-dark-border/60 dark:border-rt-dark-border/70">
                                @forelse($selectedOrder->shifts as $shift)
                                    @php($shiftStatus = $shift->status instanceof \BackedEnum ? $shift->status->value : (string) $shift->status)
                                    <div class="px-3.5 py-3" wire:key="order-shift-{{ $shift->id }}">
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $shift->title }}</p>
                                            <span class="rounded-md border px-2 py-0.5 text-[10px] font-semibold {{ $statusClass($shiftStatus) }}">{{ method_exists($shift->status, 'label') ? $shift->status->label() : \Illuminate\Support\Str::headline($shiftStatus) }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift->starts_at?->format('d.m.Y H:i') }} · {{ $shift->assignments->filter(fn ($assignment) => in_array(
                                            $assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status,
                                            \App\Enums\ShiftAssignmentStatus::blockingValues(),
                                            true,
                                        ))->count() }}/{{ $shift->required_staff }} reserviert</p>
                                    </div>
                                @empty
                                    <p class="px-4 py-6 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Schichten angelegt.</p>
                                @endforelse
                            </div>
                        </section>
                        <section>
                            <h3 class="text-sm font-semibold text-rt-text dark:text-white">Statusverlauf</h3>
                            <div class="mt-2 space-y-2">
                                @forelse($selectedOrder->statusHistory->take(6) as $history)
                                    <div class="rounded-xl border border-rt-border/70 px-3.5 py-3 dark:border-rt-dark-border/70" wire:key="order-history-{{ $history->id }}">
                                        <p class="text-xs font-semibold text-rt-text dark:text-white">{{ method_exists($history->to_status, 'label') ? $history->to_status->label() : \Illuminate\Support\Str::headline((string) ($history->to_status instanceof \BackedEnum ? $history->to_status->value : $history->to_status)) }}</p>
                                        <p class="mt-1 text-[11px] text-rt-muted dark:text-rt-dark-muted">{{ $history->changed_at?->format('d.m.Y H:i') ?? $history->created_at?->format('d.m.Y H:i') }} · {{ $history->changedBy?->name ?? 'System' }}</p>
                                        @if($history->note)<p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $history->note }}</p>@endif
                                    </div>
                                @empty
                                    <p class="rounded-xl border border-dashed border-rt-border px-4 py-6 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">Noch keine Statusänderung protokolliert.</p>
                                @endforelse
                            </div>
                        </section>
                    </div>
                @else
                    <div class="flex min-h-80 flex-col items-center justify-center text-center">
                        <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                        <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Auftrag auswählen</h2>
                        <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle einen Auftrag aus, um Details und Planungsstatus zu sehen.</p>
                    </div>
                @endif
            </aside>
        </div>
    </section>

    <x-dialog-modal wire:model="formOpen" maxWidth="4xl">
        <x-slot:title>{{ $editingOrderId ? 'Auftrag bearbeiten' : 'Neuen Auftrag anlegen' }}</x-slot:title>
        <x-slot:content>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-ui.forms.label for="order-customer" value="Kunde" />
                    <x-ui.forms.select id="order-customer" wire:model="customerId" class="mt-1" placeholder="Kunde auswählen">
                        @foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->customer_number }} · {{ $customer->company_name }}</option>@endforeach
                    </x-ui.forms.select>
                    @error('customerId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-service-type" value="Leistungsart" />
                    <x-ui.forms.input id="order-service-type" wire:model="serviceType" class="mt-1" placeholder="z. B. Zugbegleitung" />
                    @error('serviceType') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="order-title" value="Auftragstitel" />
                    <x-ui.forms.input id="order-title" wire:model="title" class="mt-1" />
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-start" value="Beginn" />
                    <x-ui.forms.input id="order-start" type="datetime-local" wire:model="startsAt" class="mt-1" />
                    @error('startsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-end" value="Ende" />
                    <x-ui.forms.input id="order-end" type="datetime-local" wire:model="endsAt" class="mt-1" />
                    @error('endsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-priority" value="Priorität" />
                    <x-ui.forms.select id="order-priority" wire:model="priority" class="mt-1">
                        @foreach($priorityOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                    </x-ui.forms.select>
                    @error('priority') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-required-staff" value="Benötigte Mitarbeitende" />
                    <x-ui.forms.input id="order-required-staff" type="number" min="1" wire:model="requiredStaff" class="mt-1" />
                    @error('requiredStaff') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="order-location-name" value="Einsatzort / Treffpunkt" />
                    <x-ui.forms.input id="order-location-name" wire:model="locationName" class="mt-1" />
                    @error('locationName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="order-address" value="Straße und Hausnummer" />
                    <x-ui.forms.input id="order-address" wire:model="address" class="mt-1" autocomplete="street-address" />
                    @error('address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-postal-code" value="Postleitzahl" />
                    <x-ui.forms.input id="order-postal-code" wire:model="postalCode" class="mt-1" autocomplete="postal-code" />
                    @error('postalCode') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-city" value="Ort" />
                    <x-ui.forms.input id="order-city" wire:model="city" class="mt-1" autocomplete="address-level2" />
                    @error('city') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="order-country" value="Land" />
                    <x-ui.forms.select id="order-country" wire:model="country" class="mt-1">
                        <option value="DE">Deutschland</option>
                        <option value="AT">Österreich</option>
                        <option value="CH">Schweiz</option>
                        <option value="NL">Niederlande</option>
                        <option value="PL">Polen</option>
                        <option value="CZ">Tschechien</option>
                    </x-ui.forms.select>
                    @error('country') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="order-description" value="Beschreibung" />
                    <x-ui.forms.textarea id="order-description" wire:model="description" rows="3" class="mt-1" />
                    @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-requirements" value="Anforderungen (eine pro Zeile)" />
                    <x-ui.forms.textarea id="order-requirements" wire:model="requirementsText" rows="4" class="mt-1" />
                    @error('requirementsText') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-notes" value="Interne Notizen" />
                    <x-ui.forms.textarea id="order-notes" wire:model="notes" rows="4" class="mt-1" />
                    @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-slot:content>
        <x-slot:footer>
            <button type="button" x-on:click="$dispatch('close')" class="inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white">Abbrechen</button>
            <button type="button" wire:click="saveOrder" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60">
                <i wire:loading.remove wire:target="saveOrder" class="far fa-check" aria-hidden="true"></i>
                <i wire:loading wire:target="saveOrder" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                Speichern
            </button>
        </x-slot:footer>
    </x-dialog-modal>
</div>
