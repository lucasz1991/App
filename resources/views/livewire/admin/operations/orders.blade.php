<div @class(['rt-disposition rt-disposition--orders', 'space-y-6' => ! $embeddedDetail]) data-operations-orders>
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
        $boundedOrdersList = $consolidated && ! $profileEmbedded && ! $detailOnly;
    @endphp
    @unless($detailOnly)
    @if($boundedOrdersList)
        <template x-teleport="[data-topbar-page-search]">
            <x-tables.search-field context="page-topbar" wire:model.live.debounce.300ms="search" :results-count="$orders->count()" placeholder="Leistungen suchen" aria-label="Leistungen suchen" />
        </template>
        <template x-teleport="[data-page-header-search]">
            <div class="rt-orders-list-controls" data-orders-list-controls>
                <x-ui.buttons.button-basic type="button" mode="secondary" class="rt-service-bulk-trigger" disabled data-tables-bulk-unavailable data-orders-bulk-trigger aria-label="Massenaktionen – keine Sammelaktionen für Leistungen verfügbar" title="Keine Sammelaktionen für Leistungen verfügbar"><i class="far fa-layer-group" aria-hidden="true"></i></x-ui.buttons.button-basic>
            </div>
        </template>
        <template x-teleport="[data-page-header-actions]">
            <div class="rt-orders-table-filters" data-orders-table-filters>
                @include('livewire.admin.operations.partials.orders-period-trigger')
                @include('livewire.admin.operations.partials.orders-filter-trigger')
            </div>
        </template>
    @else
        <div class="rt-orders-list-controls" data-orders-list-controls>
            <x-ui.buttons.button-basic type="button" mode="secondary" class="rt-service-bulk-trigger" disabled data-tables-bulk-unavailable data-orders-bulk-trigger aria-label="Massenaktionen – keine Sammelaktionen für Leistungen verfügbar" title="Keine Sammelaktionen für Leistungen verfügbar"><i class="far fa-layer-group" aria-hidden="true"></i></x-ui.buttons.button-basic>
            @include('livewire.admin.operations.partials.orders-filter-trigger')
        </div>
    @endif
    <section class="rt-orders-list" aria-label="{{ __('pagination.orders_list') }}" @if($boundedOrdersList) data-orders-list-scroll @endif>
        <x-operations.service-table :items="$orderTableItems" sort-action="tableSort" :sort-by="$sortBy" :sort-dir="$sortDir" table-key="orders" data-table-pagination-key="orders" :scroll-body="$boundedOrdersList" :selected-items="[$selectedOrderId]" selection-action="selectOrder" detail-action="openDetails" label="Aufträge und Leistungen" />
        <div class="rt-service-pagination" data-orders-pagination>
            <x-tables.pagination :paginator="$orders" table-key="orders" label="Auftragsseiten" />
        </div>
    </section>
    @endunless

    @if($embeddedDetail)
        <div class="rt-service-detail-content" data-service-detail-loaded="{{ $selectedOrder?->id }}">
            @include('livewire.admin.operations.partials.service-details')
        </div>
    @else
        <x-operations.panel :id="'order-detail-panel-'.$this->getId()" label="Leistungsdetails" wire:model.live="detailOpen">
            @include('livewire.admin.operations.partials.service-details')
        </x-operations.panel>
    @endif
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
