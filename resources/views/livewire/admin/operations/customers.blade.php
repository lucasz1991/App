<div class="space-y-4" data-operations-customers>
    <x-tables.toolbar title="Filter" id="operations-customers-filters">
        <x-slot:search><x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Kunden suchen" /></x-slot:search>
        <x-tables.filter-field label="Kundenstatus" for="customer-status-filter"><x-ui.forms.select id="customer-status-filter" wire:model.live="activeFilter" aria-label="Kundenstatus"><option value="active">Aktiv</option><option value="inactive">Inaktiv</option><option value="all">Alle</option></x-ui.forms.select></x-tables.filter-field>
    </x-tables.toolbar>
    <x-tables.table :columns="[['label'=>'Kunde', 'key'=>'company_name', 'width'=>'2fr'], ['label'=>'Kontakt', 'key'=>'contact_name', 'width'=>'1.3fr'], ['label'=>'E-Mail', 'key'=>'email', 'width'=>'1.5fr'], ['label'=>'Aufträge', 'key'=>'orders_count', 'width'=>'.6fr'], ['label'=>'Status', 'key'=>'is_active', 'width'=>'.7fr']]" :items="$customers" :selected-items="[$selectedCustomerId]" selection-action="selectCustomer" detail-action="openDetails" row-view="components.tables.rows.operations.record" empty="Keine Einträge gefunden." />
    <x-operations.modal wire:model="detailOpen" title="Kundendetails" max-width="4xl">
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
                            <x-ui.buttons.button-basic type="button" wire:click="editCustomer({{ $selectedCustomer->id }})" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                                <i class="far fa-pen" aria-hidden="true"></i><span class="hidden sm:inline">Bearbeiten</span>
                            </x-ui.buttons.button-basic>
                            <x-ui.buttons.button-basic type="button" wire:click="toggleCustomerActive({{ $selectedCustomer->id }})" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-muted transition hover:text-rt-text disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:text-white">
                                <i class="far {{ $selectedCustomer->is_active ? 'fa-eye-slash' : 'fa-eye' }}" aria-hidden="true"></i><span class="hidden sm:inline">{{ $selectedCustomer->is_active ? 'Deaktivieren' : 'Aktivieren' }}</span>
                            </x-ui.buttons.button-basic>
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
    </x-operations.modal>
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
            <x-ui.buttons.button-basic type="button" x-on:click="$dispatch('close')" class="inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="button" wire:click="saveCustomer" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60">
                <i wire:loading.remove wire:target="saveCustomer" class="far fa-check" aria-hidden="true"></i>
                <i wire:loading wire:target="saveCustomer" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                Speichern
            </x-ui.buttons.button-basic>
        </x-slot:footer>
    </x-dialog-modal>
</div>
