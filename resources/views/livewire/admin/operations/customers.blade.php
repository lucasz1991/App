<div class="space-y-4" data-operations-customers>
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-3" aria-label="Kundenübersicht">
        <article class="rounded-2xl border border-rt-border/70 bg-rt-surface p-4 shadow-rt-xs dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">Aktive Kunden</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $activeCount }}</p>
        </article>
        <article class="rounded-2xl border border-rt-border/70 bg-rt-surface p-4 shadow-rt-xs dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">Gesamt</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $totalCount }}</p>
        </article>
        <article class="col-span-2 rounded-2xl border border-rose-200 bg-rose-50 p-4 shadow-rt-xs dark:border-rose-900 dark:bg-rose-500/10 lg:col-span-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rt-red dark:text-rose-300">Planungsbasis</p>
            <p class="mt-2 text-sm font-semibold text-rt-text dark:text-white">Kunden &amp; Aufträge verbunden</p>
        </article>
    </section>

    <section class="overflow-hidden rounded-2xl border border-rt-border/70 bg-rt-surface shadow-rt-sm dark:border-rt-dark-border/70 dark:bg-rt-dark-surface">
        <header class="flex flex-col gap-3 border-b border-rt-border/70 p-4 dark:border-rt-dark-border/70 sm:flex-row sm:items-center sm:justify-between">
            <div class="grid flex-1 gap-2 sm:grid-cols-[minmax(15rem,1fr)_11rem]">
                <label class="relative block">
                    <span class="sr-only">Kunden durchsuchen</span>
                    <i class="far fa-search pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-rt-soft" aria-hidden="true"></i>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        class="min-h-11 w-full rounded-xl border border-rt-border bg-rt-control py-2.5 pl-10 pr-3.5 text-base text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft focus:border-rt-red sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white"
                        placeholder="Firma, Kontakt oder E-Mail"
                    >
                </label>
                <select wire:model.live="activeFilter" class="min-h-11 rounded-xl border border-rt-border bg-rt-control px-3.5 text-base text-rt-text shadow-rt-xs outline-none focus:border-rt-red sm:text-sm dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white">
                    <option value="active">Aktiv</option>
                    <option value="inactive">Inaktiv</option>
                    <option value="all">Alle</option>
                </select>
            </div>
            <button type="button" wire:click="createCustomer" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2.5 text-sm font-semibold text-white shadow-rt-xs transition hover:bg-rt-red-dark disabled:opacity-60">
                <i class="far fa-plus" aria-hidden="true"></i>
                Neuer Kunde
            </button>
        </header>

        <div class="grid min-h-[30rem] xl:grid-cols-[minmax(19rem,.78fr)_minmax(0,1.22fr)]">
            <div class="border-b border-rt-border/70 dark:border-rt-dark-border/70 xl:border-b-0 xl:border-r">
                <div class="max-h-[46rem] divide-y divide-rt-border/60 overflow-y-auto dark:divide-rt-dark-border/60">
                    @forelse ($customers as $customer)
                        <button
                            type="button"
                            wire:click="selectCustomer({{ $customer->id }})"
                            wire:key="customer-list-{{ $customer->id }}"
                            @class([
                                'block min-h-20 w-full border-l-4 px-4 py-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red/35',
                                'border-rt-red bg-rose-50/80 dark:bg-rose-500/10' => $selectedCustomer?->id === $customer->id,
                                'border-transparent hover:bg-rt-surface-muted/70 dark:hover:bg-rt-dark-surface-muted/60' => $selectedCustomer?->id !== $customer->id,
                            ])
                        >
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $customer->company_name }}</span>
                                    <span class="mt-1 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $customer->customer_number }}@if($customer->contact_name) · {{ $customer->contact_name }}@endif</span>
                                </span>
                                <span @class([
                                    'shrink-0 rounded-lg border px-2 py-1 text-[10px] font-semibold',
                                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $customer->is_active,
                                    'border-slate-200 bg-slate-100 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300' => ! $customer->is_active,
                                ])>{{ $customer->is_active ? 'Aktiv' : 'Inaktiv' }}</span>
                            </span>
                            <span class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-rt-muted dark:text-rt-dark-muted">
                                <i class="far fa-clipboard-list" aria-hidden="true"></i>
                                {{ $customer->orders_count }} {{ $customer->orders_count === 1 ? 'Auftrag' : 'Aufträge' }}
                            </span>
                        </button>
                    @empty
                        <div class="px-6 py-14 text-center">
                            <i class="fad fa-building text-3xl text-rt-soft" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Keine Kunden gefunden</h3>
                            <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Passe die Suche an oder lege den ersten Kunden an.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <aside class="min-w-0 p-4 sm:p-5" data-customer-detail>
                @if ($selectedCustomer)
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-rt-red">{{ $selectedCustomer->customer_number }}</p>
                            <h2 class="mt-1 break-words text-xl font-semibold tracking-tight text-rt-text dark:text-white">{{ $selectedCustomer->company_name }}</h2>
                            @if($selectedCustomer->contact_name)
                                <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Ansprechpartner: {{ $selectedCustomer->contact_name }}</p>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="editCustomer({{ $selectedCustomer->id }})" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                                <i class="far fa-pen" aria-hidden="true"></i><span class="hidden sm:inline">Bearbeiten</span>
                            </button>
                            <button type="button" wire:click="toggleCustomerActive({{ $selectedCustomer->id }})" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-muted transition hover:text-rt-text disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:text-white">
                                <i class="far {{ $selectedCustomer->is_active ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i><span class="hidden sm:inline">{{ $selectedCustomer->is_active ? 'Deaktivieren' : 'Aktivieren' }}</span>
                            </button>
                        </div>
                    </div>

                    <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl bg-rt-surface-muted/70 p-3.5 dark:bg-rt-dark-surface-muted/60">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Kontakt</dt>
                            <dd class="mt-2 space-y-1 text-sm text-rt-text dark:text-white">
                                <p>{{ $selectedCustomer->email ?: 'Keine E-Mail hinterlegt' }}</p>
                                <p>{{ $selectedCustomer->phone ?: 'Keine Telefonnummer hinterlegt' }}</p>
                            </dd>
                        </div>
                        <div class="rounded-xl bg-rt-surface-muted/70 p-3.5 dark:bg-rt-dark-surface-muted/60">
                            <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Anschrift</dt>
                            <dd class="mt-2 text-sm text-rt-text dark:text-white">
                                @if($selectedCustomer->street || $selectedCustomer->postal_code || $selectedCustomer->city || $selectedCustomer->country)
                                    <span class="block">{{ $selectedCustomer->street }}</span>
                                    <span class="block">{{ trim(($selectedCustomer->postal_code ?? '').' '.($selectedCustomer->city ?? '')) }}</span>
                                    <span class="block">{{ $selectedCustomer->country }}</span>
                                @else
                                    Keine Anschrift hinterlegt
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if($selectedCustomer->notes)
                        <div class="mt-4 rounded-xl border border-rt-border/70 p-3.5 dark:border-rt-dark-border/70">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Interne Notiz</p>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $selectedCustomer->notes }}</p>
                        </div>
                    @endif

                    <div class="mt-5">
                        <h3 class="text-sm font-semibold text-rt-text dark:text-white">Letzte Aufträge</h3>
                        <div class="mt-2 divide-y divide-rt-border/60 rounded-xl border border-rt-border/70 dark:divide-rt-dark-border/60 dark:border-rt-dark-border/70">
                            @forelse($selectedCustomer->orders as $order)
                                @php($orderStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status)
                                <div class="flex items-center justify-between gap-3 px-3.5 py-3" wire:key="customer-order-{{ $order->id }}">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $order->title }}</p>
                                        <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $order->starts_at?->format('d.m.Y H:i') ?? 'Noch nicht terminiert' }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-lg bg-rt-surface-muted px-2 py-1 text-[10px] font-semibold text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">{{ method_exists($order->status, 'label') ? $order->status->label() : \Illuminate\Support\Str::headline((string) $orderStatus) }}</span>
                                </div>
                            @empty
                                <p class="px-4 py-6 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Aufträge vorhanden.</p>
                            @endforelse
                        </div>
                    </div>
                @else
                    <div class="flex min-h-72 flex-col items-center justify-center text-center">
                        <i class="fad fa-arrow-pointer text-3xl text-rt-soft" aria-hidden="true"></i>
                        <h2 class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Kunden auswählen</h2>
                        <p class="mt-1 max-w-sm text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Wähle links einen Kunden aus, um Kontaktdaten und Aufträge zu sehen.</p>
                    </div>
                @endif
            </aside>
        </div>
    </section>

    <x-dialog-modal wire:model="formOpen" maxWidth="2xl">
        <x-slot:title>{{ $editingCustomerId ? 'Kunde bearbeiten' : 'Neuen Kunden anlegen' }}</x-slot:title>
        <x-slot:content>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="customer-company-name" value="Firma / Auftraggeber" />
                    <x-ui.forms.input id="customer-company-name" wire:model="companyName" class="mt-1" autocomplete="organization" />
                    @error('companyName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="customer-contact-name" value="Ansprechpartner" />
                    <x-ui.forms.input id="customer-contact-name" wire:model="contactName" class="mt-1" autocomplete="name" />
                    @error('contactName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="customer-phone" value="Telefon" />
                    <x-ui.forms.input id="customer-phone" wire:model="phone" class="mt-1" autocomplete="tel" />
                    @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="customer-email" value="E-Mail" />
                    <x-ui.forms.input id="customer-email" type="email" wire:model="email" class="mt-1" autocomplete="email" />
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="customer-address" value="Straße und Hausnummer" />
                    <x-ui.forms.input id="customer-address" wire:model="address" class="mt-1" autocomplete="street-address" />
                    @error('address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="customer-postal-code" value="Postleitzahl" />
                    <x-ui.forms.input id="customer-postal-code" wire:model="postalCode" class="mt-1" autocomplete="postal-code" />
                    @error('postalCode') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="customer-city" value="Ort" />
                    <x-ui.forms.input id="customer-city" wire:model="city" class="mt-1" autocomplete="address-level2" />
                    @error('city') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.label for="customer-country" value="Land" />
                    <x-ui.forms.select id="customer-country" wire:model="country" class="mt-1">
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
                    <x-ui.forms.label for="customer-notes" value="Interne Notizen" />
                    <x-ui.forms.textarea id="customer-notes" wire:model="notes" rows="4" class="mt-1" />
                    @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.forms.checkbox id="customer-active" wire:model="isActive" label="Kunde ist aktiv" toggle />
                </div>
            </div>
        </x-slot:content>
        <x-slot:footer>
            <button type="button" x-on:click="$dispatch('close')" class="inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white">Abbrechen</button>
            <button type="button" wire:click="saveCustomer" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60">
                <i wire:loading.remove wire:target="saveCustomer" class="far fa-check" aria-hidden="true"></i>
                <i wire:loading wire:target="saveCustomer" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                Speichern
            </button>
        </x-slot:footer>
    </x-dialog-modal>
</div>
