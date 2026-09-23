<div class="rt-disposition rt-disposition--orders space-y-6" data-operations-orders>
    @php
        $statusFilterIcons = [
            'all' => 'far fa-list text-rt-muted dark:text-rt-dark-muted',
            'requested' => 'far fa-inbox text-amber-600 dark:text-amber-400',
            'confirmed' => 'far fa-check-circle text-emerald-600 dark:text-emerald-400',
            'planned' => 'far fa-calendar-check text-amber-600 dark:text-amber-400',
            'in_progress' => 'far fa-spinner text-sky-600 dark:text-sky-400',
            'completed' => 'far fa-check-double text-emerald-600 dark:text-emerald-400',
            'invoiced' => 'far fa-file-invoice text-emerald-600 dark:text-emerald-400',
            'cancelled' => 'far fa-ban text-slate-500 dark:text-slate-400',
        ];
    @endphp
    <template x-teleport="[data-page-header-metrics]">
        <section class="rt-disposition-summary" aria-label="Leistungen im gesamten Bestand">
            <div class="rt-disposition-summary__item">
                <span class="rt-disposition-summary__value">{{ number_format($openCount, 0, ',', '.') }}</span>
                <span class="rt-disposition-summary__label">Aktiv</span>
            </div>
            <div class="rt-disposition-summary__item">
                <span class="rt-disposition-summary__value">{{ number_format($startsSoonCount, 0, ',', '.') }}</span>
                <span class="rt-disposition-summary__label">Demnächst</span>
            </div>
            <div class="rt-disposition-summary__item">
                <span class="rt-disposition-summary__value">{{ number_format($inProgressCount, 0, ',', '.') }}</span>
                <span class="rt-disposition-summary__label">Laufend</span>
            </div>
            <div class="rt-disposition-summary__item">
                <span class="rt-disposition-summary__value">{{ number_format($withoutShiftsCount, 0, ',', '.') }}</span>
                <span class="rt-disposition-summary__label">Ohne Schichten</span>
            </div>
        </section>
    </template>

        <section class="rt-disposition-panel" aria-label="{{ __('pagination.orders_list') }}">
        <x-tables.toolbar title="Leistungen filtern" id="operations-orders-filters" :search-in-header="true" :filter-count="(int) ($statusFilter !== 'all') + (int) (trim($search) !== '')" reset-action="resetFilters">
            <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="Leistungen suchen" aria-label="Leistungen suchen" /></x-slot:search>
            <x-tables.filter-field label="Leistungsstatus" for="order-status-filter" label-hidden><x-ui.forms.select id="order-status-filter" wire:model.live="statusFilter" aria-label="Leistungsstatus"><option value="all" data-icon-class="{{ $statusFilterIcons['all'] }}">Alle Leistungen</option>@foreach($statusOptions as $option)<option value="{{ $option['value'] }}" data-icon-class="{{ $statusFilterIcons[$option['value']] ?? 'far fa-circle text-rt-muted dark:text-rt-dark-muted' }}">{{ $option['label'] }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
        </x-tables.toolbar>
        <div wire:loading.class="opacity-60" wire:target="tableSort,search,statusFilter,resetFilters,gotoPage,nextPage,previousPage">
            <x-tables.table :columns="[['label'=>'Leistung / Kunde', 'key'=>'title', 'width'=>'minmax(0, 1.7fr)','sortable'=>true], ['label'=>'Zeitraum / Ort', 'key'=>'period', 'width'=>'minmax(0, 1.2fr)','sortable'=>true], ['label'=>'Auftragsbedarf', 'key'=>'staff', 'width'=>'minmax(0, 1fr)','sortable'=>true], ['label'=>'Status', 'key'=>'status', 'width'=>'minmax(0, .85fr)','sortable'=>true]]" sort-action="tableSort" :sort-by="$sortBy" :sort-dir="$sortDir" table-key="orders" :flush-top="true" :items="$orders" :selected-items="[$selectedOrderId]" selection-action="selectOrder" detail-action="openDetails" row-view="components.tables.rows.operations.order" actions-view="components.tables.rows.operations.order-actions" empty="Keine Leistungen für diese Auswahl gefunden." />
        </div>
        @if($orders->hasPages())
            <div class="mt-3">{{ $orders->links(data: ['scrollTo' => '[data-operations-orders]']) }}</div>
        @endif
    </section>

    <x-operations.modal wire:model="detailOpen" title="Leistungsdetails" max-width="4xl" variant="drawer">
        @if($selectedOrder)
                    @php
                        $selectedStatus = $selectedOrder->status instanceof \BackedEnum ? $selectedOrder->status->value : (string) $selectedOrder->status;
                        $selectedPriority = $selectedOrder->priority instanceof \BackedEnum ? $selectedOrder->priority->value : (string) $selectedOrder->priority;
                        $planningRoute = \App\Support\Operations\OperationsAccess::ready() ? 'operations.workspace' : 'admin.operations.preview';
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-rt-red">{{ $selectedOrder->order_number }}</p>
                            <h2 class="mt-1 break-words text-xl font-semibold tracking-tight text-rt-text dark:text-white">{{ $selectedOrder->title }}</h2>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $selectedOrder->customer?->company_name }}</p>
                        </div>
                        <x-ui.buttons.button-basic type="button" wire:click="editOrder({{ $selectedOrder->id }})" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted">
                            <i class="far fa-pen" aria-hidden="true"></i>Bearbeiten
                        </x-ui.buttons.button-basic>
                    </div>

                    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Planung dieser Leistung">
                        <x-ui.buttons.button-basic :href="route($planningRoute, ['module' => 'shift-management', 'order' => $selectedOrder->id])" class="min-h-11">
                            <i class="far fa-table-list" aria-hidden="true"></i>Schichtplan
                        </x-ui.buttons.button-basic>
                        <x-ui.buttons.button-basic :href="route($planningRoute, ['module' => 'calendar', 'order' => $selectedOrder->id])" class="min-h-11">
                            <i class="far fa-calendar-days" aria-hidden="true"></i>Kalender
                        </x-ui.buttons.button-basic>
                    </nav>

                    <div class="mt-5 rounded-xl border border-rt-border/70 bg-rt-surface-muted/50 p-3.5 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/40">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft">Aktueller Status</p>
                                <x-operations.status :value="$selectedStatus" :label="$selectedOrder->status->label()" />
                            </div>
                            <div class="block w-full sm:w-56" wire:key="order-status-{{ $selectedOrder->id }}-{{ $selectedStatus }}">
                                <span class="mb-1 block text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">Status ändern</span>
                                <x-ui.forms.select wire:key="order-status-transition-{{ $selectedStatus }}" change="$wire.changeStatus($event.target.value)" :disabled="empty($transitionOptions)" aria-label="Status ändern">
                                    <option value="" selected disabled>{{ empty($transitionOptions) ? 'Kein Folgestatus verfügbar' : 'Folgestatus auswählen' }}</option>
                                    @foreach($transitionOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </x-ui.forms.select>
                            </div>
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

                    @if($detailOpen && \App\Support\Operations\PlanningSchema::ready())<livewire:operations.order-demands :order-id="$selectedOrder->id" :key="'demand-'.$selectedOrder->id" />@endif
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
                                            <x-ui.buttons.button-basic mode="link" :href="route($planningRoute, ['module' => 'shift-management', 'order' => $selectedOrder->id, 'shift' => $shift->id])" class="min-h-11 min-w-0 text-left"><span class="break-words">{{ $shift->title }}</span></x-ui.buttons.button-basic>
                                            <x-operations.status :value="$shiftStatus" />
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
                                        <p class="mt-1 text-[11px] text-rt-muted dark:text-rt-dark-muted">{{ $history->changed_at?->format('d.m.Y H:i') ?? $history->created_at?->format('d.m.Y H:i') }}</p>
                                        @if($history->changedBy)<x-user.public-info :user="$history->changedBy" :size="6" :show-presence="false" class="mt-2" />@else<span class="ops-muted">System</span>@endif
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
    </x-operations.modal>
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
                    <x-ui.forms.date-time-field id="order-start" wire:model="startsAt" :aria-label="'Beginn ('.$timezone.')'" class="mt-1" required />
                    @error('startsAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.forms.label for="order-end" value="Ende" />
                    <x-ui.forms.date-time-field id="order-end" wire:model="endsAt" :aria-label="'Ende ('.$timezone.')'" class="mt-1" required />
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
                    <x-ui.forms.number-input id="order-required-staff" min="1" max="999" :nullable="false" wire:model="requiredStaff" />
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
            <x-ui.buttons.button-basic type="button" x-on:click="$dispatch('close')" class="inline-flex min-h-11 items-center rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text dark:border-rt-dark-border dark:text-white">Abbrechen</x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="button" wire:click="saveOrder" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-60">
                <i wire:loading.remove wire:target="saveOrder" class="far fa-check" aria-hidden="true"></i>
                <i wire:loading wire:target="saveOrder" class="far fa-spinner-third fa-spin" aria-hidden="true"></i>
                Speichern
            </x-ui.buttons.button-basic>
        </x-slot:footer>
    </x-dialog-modal>
</div>
