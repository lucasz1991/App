<x-ui.page
    title="Geräte"
    eyebrow="IT & Betrieb"
    description="Virtuelles Lager, Mitarbeiterzuweisung, sichere Bereitstellung und capability-basierte Fernverwaltung."
    :count="number_format($stats['total'], 0, ',', '.')"
    page-key="device-management"
>
    <x-slot:actions>
        @can('devices.accounts.manage')
            <livewire:devices.microsoft-employee-links />
        @endcan
        @if($microsoftEnabled)
            @can('devices.manage')
                <button type="button" wire:click="syncMicrosoftDevices" wire:loading.attr="disabled" wire:target="syncMicrosoftDevices"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-xl border border-rt-border px-3 text-sm font-semibold text-rt-text hover:bg-rt-surface-muted disabled:opacity-50 dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface-muted"
                    title="Microsoft-Geräte synchronisieren" aria-label="Microsoft-Geräte synchronisieren">
                    <i class="fab fa-microsoft" aria-hidden="true"></i><span class="hidden lg:inline">Abgleichen</span>
                </button>
            @endcan
        @endif
        <button
            type="button"
            wire:click="openProviderReadiness"
            class="relative inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border bg-white px-3 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition duration-200 hover:border-rt-accent/30 hover:bg-rt-surface-muted active:scale-[0.98] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted sm:px-4"
            aria-haspopup="dialog"
            aria-controls="device-provider-readiness-modal"
            aria-label="Systemstatus öffnen"
            title="Systemstatus"
        >
            <i data-feather="activity" class="h-4 w-4" aria-hidden="true"></i>
            <span @class([
                'absolute right-1.5 top-1.5 h-2 w-2 rounded-full ring-2 ring-white dark:ring-rt-dark-surface',
                'bg-emerald-500' => $productionCommandsEnabled,
                'bg-amber-500' => ! $productionCommandsEnabled,
            ]) aria-hidden="true"></span>
            <span class="hidden sm:inline">Systemstatus</span>
            <span class="sr-only sm:hidden">Systemstatus öffnen</span>
        </button>

        @can('devices.manage')
            <button
                type="button"
                wire:click="openCreate"
                class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-3 py-2 text-sm font-semibold text-white shadow-rt-sm transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30 sm:px-4"
                aria-label="Gerät erfassen"
                title="Gerät erfassen"
            >
                <i data-feather="plus" class="h-4 w-4" aria-hidden="true"></i>
                <span class="hidden sm:inline">Gerät erfassen</span>
            </button>
        @endcan
    </x-slot:actions>

    <div class="space-y-4" data-device-management>
        @error('microsoftSync')<p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        @if($microsoftWatchedRunId)
            <div @if($microsoftPollsRemaining > 0) wire:poll.5s.visible="refreshMicrosoftSync" @endif
                class="flex flex-wrap items-center gap-2 rounded-lg border border-rt-border px-3 py-2 text-sm dark:border-rt-dark-border" role="status" aria-live="polite">
                <i class="fab fa-microsoft" aria-hidden="true"></i>
                <span>Microsoft-Abgleich: {{ $microsoftRuntime['run']['message'] ?? 'Status im Geräte-Setup prüfen.' }}</span>
                @if($microsoftPollsRemaining === 0 && in_array($microsoftRuntime['run']['status'] ?? '', ['queued', 'running'], true))
                    <span class="text-amber-700 dark:text-amber-300">Noch offen – Hintergrundverarbeitung im Geräte-Setup prüfen.</span>
                @endif
            </div>
        @endif
        <section class="flex min-w-0 items-center gap-3 rounded-xl border border-sky-200/80 bg-sky-50/75 px-3 py-2.5 text-sky-950 dark:border-sky-900/70 dark:bg-sky-950/25 dark:text-sky-100" aria-label="Sichere Bereitstellung">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-sky-100 text-sky-700 dark:bg-sky-900/70 dark:text-sky-200">
                <i data-feather="shield" class="h-4 w-4" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">Startklar-Bereitstellung ohne Passwortspeicher</p>
                <p class="hidden truncate text-xs text-sky-800/80 md:block dark:text-sky-200/75">Microsoft 365, Google Workspace und Apple-SSO werden vorbereitet; die Anmeldung bleibt im offiziellen OAuth-/MFA-Dialog.</p>
            </div>
            <span class="hidden shrink-0 rounded-lg bg-white/70 px-2.5 py-1 text-[11px] font-semibold text-sky-800 shadow-rt-xs lg:inline dark:bg-sky-900/45 dark:text-sky-100">OAuth / SSO</span>
        </section>

        @php
            $normalizedSearch = trim((string) $search);
            $activeFilterCount = collect([$normalizedSearch, $locationFilter, $lifecycleFilter, $platformFilter, $formFactorFilter, $complianceFilter, $microsoftFilter])
                ->filter(fn ($value) => $value !== '')
                ->count();
            $lifecycleFilterLabels = [
                'inventory' => 'Im Lager',
                'preparing' => 'Vorbereitung',
                'assigned' => 'Zugewiesen',
                'in_service' => 'Im Einsatz',
                'repair' => 'Reparatur',
                'lost' => 'Verloren',
                'retired' => 'Ausgemustert',
            ];
            $formFactorFilterLabels = [
                'laptop' => 'Laptop',
                'desktop' => 'Desktop',
                'phone' => 'Smartphone',
                'tablet' => 'Tablet',
                'other' => 'Sonstiges',
            ];
            $complianceFilterLabels = [
                'compliant' => 'Konform',
                'warning' => 'Warnung',
                'non_compliant' => 'Nicht konform',
                'unknown' => 'Unbekannt',
            ];
        @endphp
        <x-tables.toolbar
            id="device-inventory-filters"
            :filter-count="$activeFilterCount"
            title="Geräte filtern"
            reset-action="clearFilters"
            search-for="device-inventory-search"
        >
            <x-slot:search>
                <x-tables.search-field
                    id="device-inventory-search"
                    :results-count="$devices->count()"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Gerät, Seriennummer oder Mitarbeiter …"
                />
            </x-slot:search>

            <x-tables.filter-field label="Microsoft-Verknüpfung" icon="fab fa-microsoft" for="device-microsoft-filter">
                <x-ui.forms.select id="device-microsoft-filter" wire:model.live="microsoftFilter" aria-label="Microsoft-Verknüpfung" class="w-full">
                    <option value="">Alle Geräte</option>
                    <option value="linked">In Entra bekannt</option>
                    <option value="intune">Mit Intune-Eintrag</option>
                    <option value="attention">Microsoft-Zuordnung prüfen</option>
                    <option value="unlinked">Ohne Microsoft-Verknüpfung</option>
                </x-ui.forms.select>
            </x-tables.filter-field>

            <x-tables.filter-field label="Standort" icon="far fa-map-marker-alt" for="device-location-filter">
                <x-ui.forms.select id="device-location-filter" wire:model.live="locationFilter" aria-label="Standort" class="w-full">
                    <option value="">Alle Standorte</option>
                    @foreach($locations as $location)
                        <option value="{{ $location }}">{{ $location }}</option>
                    @endforeach
                </x-ui.forms.select>
            </x-tables.filter-field>

            <x-tables.filter-field label="Gerätezustand" icon="far fa-layer-group" for="device-lifecycle-filter">
                <x-ui.forms.select id="device-lifecycle-filter" wire:model.live="lifecycleFilter" aria-label="Gerätezustand" class="w-full">
                    <option value="">Alle Gerätezustände</option>
                    @foreach($lifecycleFilterLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.forms.select>
            </x-tables.filter-field>

            <x-tables.filter-field label="Plattform" icon="far fa-desktop" for="device-platform-filter">
                <x-ui.forms.select id="device-platform-filter" wire:model.live="platformFilter" aria-label="Plattform" class="w-full">
                    <option value="">Alle Plattformen</option>
                    @foreach(\App\Enums\DevicePlatform::cases() as $platform)
                        <option value="{{ $platform->value }}">{{ ucfirst($platform->value) }}</option>
                    @endforeach
                </x-ui.forms.select>
            </x-tables.filter-field>

            <x-tables.filter-field label="Gerätetyp" icon="far fa-laptop" for="device-form-factor-filter">
                <x-ui.forms.select id="device-form-factor-filter" wire:model.live="formFactorFilter" aria-label="Gerätetyp" class="w-full">
                    <option value="">Alle Gerätetypen</option>
                    @foreach($formFactorFilterLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.forms.select>
            </x-tables.filter-field>

            <x-tables.filter-field label="Compliance" icon="far fa-shield-check" for="device-compliance-filter">
                <x-ui.forms.select id="device-compliance-filter" wire:model.live="complianceFilter" aria-label="Compliance" class="w-full">
                    <option value="">Alle Compliance-Stati</option>
                    @foreach($complianceFilterLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.forms.select>
            </x-tables.filter-field>

            <x-slot:chips>
                @if ($normalizedSearch !== '')
                    <x-tables.filter-chip label="Suche" :value="$normalizedSearch" wire:click="$set('search', '')" />
                @endif
                @if ($locationFilter !== '')
                    <x-tables.filter-chip label="Standort" :value="$locationFilter" wire:click="$set('locationFilter', '')" />
                @endif
                @if ($lifecycleFilter !== '')
                    <x-tables.filter-chip label="Zustand" :value="$lifecycleFilterLabels[$lifecycleFilter] ?? $lifecycleFilter" wire:click="$set('lifecycleFilter', '')" />
                @endif
                @if ($platformFilter !== '')
                    <x-tables.filter-chip label="Plattform" :value="ucfirst($platformFilter)" wire:click="$set('platformFilter', '')" />
                @endif
                @if ($formFactorFilter !== '')
                    <x-tables.filter-chip label="Gerätetyp" :value="$formFactorFilterLabels[$formFactorFilter] ?? $formFactorFilter" wire:click="$set('formFactorFilter', '')" />
                @endif
                @if ($complianceFilter !== '')
                    <x-tables.filter-chip label="Compliance" :value="$complianceFilterLabels[$complianceFilter] ?? $complianceFilter" wire:click="$set('complianceFilter', '')" />
                @endif
            </x-slot:chips>
        </x-tables.toolbar>

        <section class="grid grid-cols-2 gap-2.5 lg:grid-cols-4" aria-label="Gerätekennzahlen">
            @foreach([
                ['label' => 'Gesamt', 'value' => $stats['total'], 'hint' => 'Geräte', 'icon' => 'monitor', 'tone' => 'sky'],
                ['label' => 'Zugewiesen', 'value' => $stats['assigned'], 'hint' => 'aktive Ausgaben', 'icon' => 'user-check', 'tone' => 'emerald'],
                ['label' => 'Virtuelles Lager', 'value' => $stats['inventory'], 'hint' => 'nicht zugewiesener Bestand', 'icon' => 'package', 'tone' => 'cyan'],
                ['label' => 'Handlungsbedarf', 'value' => $stats['attention'], 'hint' => 'Warnung oder Fehler', 'icon' => 'alert-triangle', 'tone' => 'amber'],
            ] as $stat)
                @php
                    $share = $stats['total'] > 0 && $stat['label'] !== 'Gesamt'
                        ? round(($stat['value'] / $stats['total']) * 100, 1)
                        : null;
                @endphp
                <article class="rounded-xl border border-rt-border/80 bg-white/95 p-3 shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface/95 sm:p-3.5">
                    <div class="flex min-w-0 items-center gap-2.5 sm:gap-3">
                        <span @class([
                            'grid h-9 w-9 shrink-0 place-items-center rounded-full sm:h-10 sm:w-10',
                            'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' => $stat['tone'] === 'sky',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $stat['tone'] === 'emerald',
                            'bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300' => $stat['tone'] === 'cyan',
                            'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $stat['tone'] === 'amber',
                        ])>
                            <i data-feather="{{ $stat['icon'] }}" class="h-4 w-4 sm:h-5 sm:w-5" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium text-rt-muted dark:text-rt-dark-muted">{{ $stat['label'] }}</p>
                            <div class="mt-0.5 flex items-baseline gap-2">
                                <p class="text-xl font-bold tabular-nums tracking-tight text-rt-text dark:text-white sm:text-2xl">{{ number_format($stat['value'], 0, ',', '.') }}</p>
                                @if(! is_null($share))
                                    <span class="hidden text-[11px] font-medium tabular-nums text-rt-soft sm:inline dark:text-rt-dark-soft">{{ number_format($share, 1, ',', '.') }} %</span>
                                @endif
                            </div>
                            <p class="truncate text-[11px] text-rt-soft dark:text-rt-dark-soft">{{ $stat['hint'] }}</p>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
            <section class="min-w-0" aria-labelledby="device-inventory-title">
                <div class="flex items-center justify-between gap-3 px-1 pb-2">
                    <div>
                        <h2 id="device-inventory-title" class="text-sm font-bold text-rt-text dark:text-white">Gerätebestand</h2>
                        <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ number_format($devices->total(), 0, ',', '.') }} Treffer in Inventar und Ausgabe</p>
                    </div>
                </div>

                <div class="rt-device-mobile-header px-3 py-2.5 text-[10px] font-bold uppercase tracking-[0.08em] md:hidden">
                    <div class="rt-device-mobile-header-grid min-w-0">
                        <span>Gerät</span>
                        <span class="text-right">Status</span>
                    </div>
                    <span aria-hidden="true"></span>
                </div>
                <x-tables.table
                    class="rt-device-table"
                    :flush-top="true"
                    :columns="[
                        ['label' => 'Gerät', 'key' => 'device', 'width' => '29%', 'hideOn' => 'none'],
                        ['label' => 'Mitarbeiter', 'key' => 'employee', 'width' => '21%', 'hideOn' => 'md'],
                        ['label' => 'Plattform', 'key' => 'platform', 'width' => '13%', 'hideOn' => 'md'],
                        ['label' => 'Standort', 'key' => 'location', 'width' => '17%', 'hideOn' => 'md'],
                        ['label' => 'Status', 'key' => 'status', 'width' => '20%', 'hideOn' => 'none'],
                    ]"
                    :items="$devices"
                    :selected-items="$selectedDevice ? [$selectedDevice->id] : []"
                    selection-action="selectDeviceById"
                    row-view="components.tables.rows.devices.device-row"
                    actions-view="components.tables.rows.devices.device-actions"
                    empty="Noch keine passenden Geräte gefunden. Passe die Filter an oder erfasse das erste Gerät für das virtuelle Lager."
                />
                <div class="py-3">{{ $devices->links() }}</div>
            </section>

            <aside class="overflow-hidden rounded-xl border border-rt-border/80 bg-white shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface" aria-labelledby="device-locations-title">
                <div class="flex items-center justify-between gap-3 border-b border-rt-border/70 px-4 py-3 dark:border-rt-dark-border">
                    <div>
                        <h2 id="device-locations-title" class="text-sm font-bold text-rt-text dark:text-white">Gerätestandorte</h2>
                        <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">Deklarierte Einsatzorte</p>
                    </div>
                    <span class="grid h-9 w-9 place-items-center rounded-lg bg-sky-50 text-sky-700 dark:bg-sky-950/45 dark:text-sky-300"><i data-feather="map" class="h-4 w-4" aria-hidden="true"></i></span>
                </div>

                <div class="relative min-h-72 overflow-hidden bg-sky-50/45 px-3 py-3 dark:bg-sky-950/10">
                    <svg class="pointer-events-none absolute inset-0 h-full w-full text-sky-200/70 dark:text-sky-900/60" viewBox="0 0 288 360" fill="none" aria-hidden="true">
                        <path d="M-20 70C46 42 83 101 139 73C189 48 231 16 308 39M-16 168C48 124 106 197 168 151C218 114 256 127 309 105M-15 282C40 235 92 262 145 228C208 187 247 224 306 195" stroke="currentColor" stroke-width="2" stroke-dasharray="5 8" />
                        <circle cx="42" cy="69" r="5" fill="currentColor"/><circle cx="140" cy="73" r="5" fill="currentColor"/><circle cx="168" cy="151" r="5" fill="currentColor"/><circle cx="145" cy="228" r="5" fill="currentColor"/>
                    </svg>

                    <div class="relative space-y-2">
                        @forelse($locationStats as $location)
                            <button type="button" wire:click="filterByLocation({{ \Illuminate\Support\Js::from($location->declared_location) }})" class="group flex min-h-11 w-full items-center gap-2.5 rounded-lg border border-white/90 bg-white/90 px-3 text-left shadow-rt-xs transition duration-200 hover:-translate-y-0.5 hover:border-sky-200 hover:shadow-rt-sm active:translate-y-0 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-sky-500/15 dark:border-rt-dark-border dark:bg-rt-dark-surface/90 dark:hover:border-sky-800">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-sky-600 ring-4 ring-sky-100 dark:bg-sky-400 dark:ring-sky-950" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 truncate text-xs font-semibold text-rt-text dark:text-white">{{ $location->declared_location }}</span>
                                <span class="tabular-nums text-[11px] font-semibold text-rt-muted dark:text-rt-dark-muted">{{ number_format($location->device_count, 0, ',', '.') }}</span>
                            </button>
                        @empty
                            <div class="relative flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed border-sky-200 bg-white/70 px-5 text-center dark:border-sky-900 dark:bg-rt-dark-surface/70">
                                <span class="grid h-11 w-11 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"><i data-feather="map-pin" class="h-5 w-5" aria-hidden="true"></i></span>
                                <p class="mt-3 text-sm font-semibold text-rt-text dark:text-white">Noch keine Standorte</p>
                                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Standorte erscheinen hier, sobald Geräte erfasst oder zurückgegeben werden.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="border-t border-rt-border/70 px-4 py-3 text-xs text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">
                    Keine Live-Ortung · Angaben aus Inventar und Rückgabe
                </div>
            </aside>
        </div>

        @include('livewire.devices.partials.provider-readiness-modal')

        @if($selectedDevice)
            @php
                $activeAssignment = $selectedDevice->activeAssignment;
                $checks = $selectedDevice->readinessChecks->keyBy('check_key');
                $ready = collect(array_keys(\App\Services\DeviceManagement\DeviceReadinessService::REQUIRED_CHECKS))->every(fn($key) => in_array($checks->get($key)?->status, ['passed','not_applicable'], true));
                $compatibleProviders = $providerCards->filter(fn($provider) => $provider['enabled'] && in_array($selectedDevice->platform->value, $provider['capabilities']['platforms'] ?? [], true));
                $selectedCommandProvider = $compatibleProviders->firstWhere('key', $commandProvider);
                $availableCommandTypes = $selectedCommandProvider['capabilities']['commands'] ?? [];
                $selectedCommandsEnabled = (bool) ($selectedCommandProvider['commands_enabled'] ?? false);
                $providerLinksByKey = $selectedDevice->providerLinks->keyBy('provider');
                $remoteSupportProviders = $compatibleProviders->filter(function($provider) use ($providerLinksByKey) {
                    $link = $providerLinksByKey->get($provider['key']);

                    return ($provider['capabilities']['remote_support'] ?? false)
                        && $provider['remote_url_available']
                        && $link?->status === \App\Models\DeviceProviderLink::STATUS_ACTIVE
                        && filled($link?->external_device_id);
                });
            @endphp
            <section class="rounded-2xl border border-rt-red/20 bg-white p-4 shadow-rt-lg dark:border-rt-red/35 dark:bg-rt-dark-surface" x-data="{ tab: 'overview' }" aria-labelledby="device-detail-title">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-rt-border/70 pb-4 dark:border-rt-dark-border">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-rt-red/10 text-rt-red"><i data-feather="{{ in_array($selectedDevice->form_factor, ['phone','tablet']) ? 'smartphone' : 'monitor' }}" class="h-6 w-6"></i></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 id="device-detail-title" class="truncate text-xl font-bold text-rt-text dark:text-white">{{ $selectedDevice->display_name ?: $selectedDevice->hostname }}</h2>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $ready ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' }}">{{ $ready ? 'Bereit zur Übergabe' : 'Einrichtung offen' }}</span>
                            </div>
                            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $selectedDevice->asset_tag ?: 'Ohne Inventarnummer' }} · {{ $selectedDevice->manufacturer }} {{ $selectedDevice->model }} · {{ $selectedDevice->serial_number }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeDevice" class="grid min-h-11 min-w-11 shrink-0 place-items-center rounded-xl text-rt-muted hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted" aria-label="Gerätedetail schließen"><i data-feather="x" class="h-5 w-5"></i></button>
                </div>

                <div class="mt-4 flex gap-1 overflow-x-auto border-b border-rt-border/70 dark:border-rt-dark-border" role="tablist">
                    @php
                        $deviceTabs = ['overview' => 'Überblick', 'provisioning' => 'Bereitstellung & Konten'];
                        if (auth()->user()->can('devices.commands.execute')) {
                            $deviceTabs['files'] = 'Dateien & Aktionen';
                        }
                        if (auth()->user()->can('devices.audit.view')) {
                            $deviceTabs['audit'] = 'Kommandos & Audit';
                        }
                    @endphp
                    @foreach($deviceTabs as $key => $label)
                        <button
                            id="device-tab-{{ $selectedDevice->public_id }}-{{ $key }}"
                            type="button"
                            role="tab"
                            aria-controls="device-panel-{{ $selectedDevice->public_id }}-{{ $key }}"
                            :aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                            :tabindex="tab === '{{ $key }}' ? 0 : -1"
                            @click="tab='{{ $key }}'"
                            @keydown.arrow-right.prevent="(() => { const tabs = Array.from($el.parentElement.querySelectorAll('[role=tab]')); const next = tabs[(tabs.indexOf($el) + 1) % tabs.length]; next.click(); next.focus(); })()"
                            @keydown.arrow-left.prevent="(() => { const tabs = Array.from($el.parentElement.querySelectorAll('[role=tab]')); const previous = tabs[(tabs.indexOf($el) - 1 + tabs.length) % tabs.length]; previous.click(); previous.focus(); })()"
                            @keydown.home.prevent="(() => { const first = $el.parentElement.querySelector('[role=tab]'); first.click(); first.focus(); })()"
                            @keydown.end.prevent="(() => { const tabs = $el.parentElement.querySelectorAll('[role=tab]'); const last = tabs[tabs.length - 1]; last.click(); last.focus(); })()"
                            :class="tab==='{{ $key }}' ? 'border-rt-red text-rt-red' : 'border-transparent text-rt-muted'"
                            class="min-h-11 whitespace-nowrap border-b-2 px-3 text-sm font-semibold"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                <div id="device-panel-{{ $selectedDevice->public_id }}-overview" x-show.important="tab==='overview'" class="mt-5 grid gap-5 xl:grid-cols-[1.25fr_0.75fr]" role="tabpanel" aria-labelledby="device-tab-{{ $selectedDevice->public_id }}-overview" tabindex="0">
                    <div class="space-y-4">
                        @include('livewire.devices.partials.microsoft-device-detail')
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach([
                                ['label'=>'Lebenszyklus','value'=>match($selectedDevice->lifecycle_status->value){'inventory'=>'Im Lager','preparing'=>'Vorbereitung','assigned'=>'Zugewiesen','in_service'=>'Im Einsatz','repair'=>'Reparatur','lost'=>'Verloren','retired'=>'Ausgemustert',default=>$selectedDevice->lifecycle_status->value},'icon'=>'repeat'],
                                ['label'=>'Verwaltung','value'=>match($selectedDevice->management_status->value){'managed'=>'Verwaltet','limited'=>'Eingeschränkt','pending'=>'Einladung offen','error'=>'Fehler',default=>'Nicht verwaltet'},'icon'=>'shield'],
                                ['label'=>'Standort','value'=>$selectedDevice->declared_location ?: 'Nicht gemeldet','icon'=>'map-pin'],
                                ['label'=>'Letzte Synchronisierung','value'=>$selectedDevice->last_synced_at?->diffForHumans() ?? 'Noch nie','icon'=>'refresh-cw'],
                            ] as $item)
                                <div class="rounded-xl border border-rt-border p-3 dark:border-rt-dark-border"><i data-feather="{{ $item['icon'] }}" class="h-4 w-4 text-rt-red"></i><p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $item['label'] }}</p><p class="mt-1 text-sm font-semibold text-rt-text dark:text-white">{{ $item['value'] }}</p></div>
                            @endforeach
                        </div>

                        <div class="rounded-xl border border-rt-border p-4 dark:border-rt-dark-border">
                            <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-rt-text dark:text-white">Übergabebereitschaft</h3>@can('devices.manage')<button type="button" wire:click="refreshReadiness" class="text-xs font-semibold text-rt-red hover:underline">Neu bewerten</button>@endcan</div>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach(\App\Services\DeviceManagement\DeviceReadinessService::REQUIRED_CHECKS as $key => $label)
                                    @php $check = $checks->get($key); $checkStatus = $check?->status ?? 'unknown'; @endphp
                                    <div class="flex items-center gap-2 rounded-lg bg-rt-surface-muted/70 px-3 py-2 text-sm dark:bg-rt-dark-surface-muted">
                                        <span class="grid h-6 w-6 place-items-center rounded-full {{ in_array($checkStatus,['passed','not_applicable'],true) ? 'bg-emerald-100 text-emerald-700' : ($checkStatus === 'blocked' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}"><i data-feather="{{ in_array($checkStatus,['passed','not_applicable'],true) ? 'check' : ($checkStatus === 'blocked' ? 'x' : 'clock') }}" class="h-3.5 w-3.5"></i></span>
                                        <span class="min-w-0 flex-1 text-rt-text dark:text-white">{{ $label }}</span>
                                        <span class="text-[11px] font-medium uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ str_replace('_',' ',$checkStatus) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <aside class="space-y-4">
                        <div class="rounded-xl border border-rt-border p-4 dark:border-rt-dark-border">
                            <h3 class="font-semibold text-rt-text dark:text-white">Mitarbeiter & Lager</h3>
                            @if($activeAssignment?->user)
                                <div class="mt-3 rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted"><p class="font-semibold text-rt-text dark:text-white">{{ $activeAssignment->user->name }}</p><p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $activeAssignment->user->email }} · seit {{ $activeAssignment->assigned_at?->format('d.m.Y') }}</p></div>
                            @else
                                <p class="mt-3 text-sm text-rt-muted dark:text-rt-dark-muted">Das Gerät liegt im virtuellen Lager und ist niemandem zugewiesen.</p>
                            @endif
                            @can('devices.assign')
                                <label class="mt-4 block text-sm font-medium text-rt-text dark:text-white">Mitarbeiter zuweisen<select wire:model="assignmentUserId" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="">Bitte auswählen</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->email }}</option>@endforeach</select></label>
                                <textarea wire:model="assignmentNote" rows="2" placeholder="Optionale Übergabenotiz" class="mt-2 w-full rounded-xl border border-rt-border bg-white px-3 py-2 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"></textarea>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2"><button type="button" wire:click="assignDevice" class="min-h-10 rounded-xl bg-rt-red px-3 text-sm font-semibold text-white">Zuweisen</button><button type="button" wire:click="confirmHandover" @disabled(!$activeAssignment) class="min-h-10 rounded-xl border border-rt-border px-3 text-sm font-semibold disabled:opacity-40 dark:border-rt-dark-border">Übergabe bestätigen</button></div>
                                <label class="mt-3 block text-sm font-medium text-rt-text dark:text-white">Rückgabe-/Lagerstandort<input wire:model="returnLocation" type="text" class="mt-1.5 min-h-10 w-full rounded-xl border border-rt-border px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"></label>
                                <button type="button" wire:click="returnDevice" @disabled(!$activeAssignment) class="mt-2 min-h-10 w-full rounded-xl border border-amber-300 bg-amber-50 px-3 text-sm font-semibold text-amber-800 disabled:opacity-40 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">Rückgabe ins Lager erfassen</button>
                            @endcan
                        </div>
                        <div class="rounded-xl border border-rt-border p-4 text-sm dark:border-rt-dark-border"><p class="font-semibold text-rt-text dark:text-white">Standortschutz</p><p class="mt-2 leading-6 text-rt-muted dark:text-rt-dark-muted">Angezeigt wird der deklarierte Arbeits-/Lagerstandort. Eine permanente Live-Ortung ist nicht Voraussetzung und wird nicht aus der Mitarbeiter-App abgeleitet.</p></div>
                        <div class="rounded-xl border border-rt-border p-4 dark:border-rt-dark-border" data-device-provider-links>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-rt-text dark:text-white">Provider-Verknüpfungen</h3>
                                    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Jeder Provider behält seine eigene native Geräte-ID.</p>
                                </div>
                                @can('devices.manage')
                                    <button type="button" wire:click="openProviderLink" class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl border border-rt-border px-3 text-xs font-semibold text-rt-text hover:bg-rt-surface-muted dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface-muted" aria-haspopup="dialog" aria-controls="device-provider-link-modal">
                                        <i data-feather="link" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                        Verknüpfen
                                    </button>
                                @endcan
                            </div>
                            <div class="mt-3 space-y-2">
                                @forelse($selectedDevice->providerLinks as $providerLink)
                                    <div class="rounded-xl bg-rt-surface-muted px-3 py-2.5 dark:bg-rt-dark-surface-muted">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-semibold text-rt-text dark:text-white">{{ $providerCards->firstWhere('key', $providerLink->provider)['label'] ?? $providerLink->provider }}</p>
                                            <div class="flex items-center gap-1.5">
                                                <span class="rounded-full bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-rt-muted ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border">{{ $providerLink->role === 'primary' ? 'Primär' : 'Support' }}</span>
                                                <span @class([
                                                    'rounded-full px-2 py-1 text-[10px] font-semibold uppercase tracking-wide',
                                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => $providerLink->status === 'active',
                                                    'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => $providerLink->status !== 'active',
                                                ])>{{ $providerLink->status }}</span>
                                            </div>
                                        </div>
                                        <p class="mt-1 break-all font-mono text-[11px] leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $providerLink->external_device_id ?: 'Noch keine externe Geräte-ID' }}</p>
                                    </div>
                                @empty
                                    <p class="rounded-xl bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-900 dark:bg-amber-950/35 dark:text-amber-200">Noch kein technisches Backend ist mit diesem Gerät verknüpft.</p>
                                @endforelse
                            </div>
                        </div>
                        @can('devices.support')
                            @if($remoteSupportProviders->isNotEmpty())
                                <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/30">
                                    <p class="text-sm font-semibold text-sky-950 dark:text-sky-100">Fernsupport</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach($remoteSupportProviders as $remoteProvider)
                                            <button type="button" wire:click="openRemoteSupport('{{ $remoteProvider['key'] }}')" class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-sky-700 px-3 text-sm font-semibold text-white hover:bg-sky-800"><i data-feather="monitor" class="h-4 w-4"></i>{{ $remoteProvider['label'] }} öffnen</button>
                                        @endforeach
                                    </div>
                                    <p class="mt-2 text-xs leading-5 text-sky-800 dark:text-sky-200">Öffnet nur die konfigurierte HTTPS-Konsole; der Aufruf wird in RailTime protokolliert.</p>
                                </div>
                            @endif
                        @endcan
                    </aside>
                </div>

                <div id="device-panel-{{ $selectedDevice->public_id }}-provisioning" x-show.important="tab==='provisioning'" x-cloak class="mt-5 grid gap-5 lg:grid-cols-2" role="tabpanel" aria-labelledby="device-tab-{{ $selectedDevice->public_id }}-provisioning" tabindex="0">
                    <div class="rounded-xl border border-rt-border p-4 dark:border-rt-dark-border">
                        <h3 class="font-semibold text-rt-text dark:text-white">Bestehendes Gerät aus der Ferne registrieren</h3>
                        <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Der Mitarbeiter erhält einen persönlichen, auth-gebundenen Einmal-Link. Bei Bestandsmobilgeräten ist zunächst eine eingeschränkte Verwaltung möglich; Full Device Owner/Supervision benötigt meist einen geplanten Reset.</p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <label class="text-sm font-medium text-rt-text dark:text-white">Provider<select wire:model.live="enrollmentProvider" @disabled(! auth()->user()->can('devices.enrollment.manage')) class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="">Nicht verfügbar</option>@foreach($compatibleProviders as $provider)@if(in_array($provider['key'], $enrollmentProviderKeys, true))<option value="{{ $provider['key'] }}">{{ $provider['label'] }}</option>@endif @endforeach</select></label>
                            <label class="text-sm font-medium text-rt-text dark:text-white">Modus<select wire:model="enrollmentMode" @disabled(! auth()->user()->can('devices.enrollment.manage') || $enrollmentModeOptions === []) class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="">Nicht verfügbar</option>@foreach($enrollmentModeOptions as $mode)<option value="{{ $mode['value'] }}">{{ $mode['label'] }}{{ $mode['requires_reset'] ? ' · Reset nötig' : '' }}{{ $mode['limited_management'] ? ' · eingeschränkt' : '' }}</option>@endforeach</select></label>
                        </div>
                        @error('enrollmentProvider')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        @error('enrollmentMode')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        @if(($selectedEnrollmentMode = collect($enrollmentModeOptions)->firstWhere('value', $enrollmentMode)))
                            <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2.5 text-xs leading-5 text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-200">
                                <p class="font-semibold">{{ $selectedEnrollmentMode['limited_management'] ? 'Eingeschränkte Verwaltung' : 'Vollständiger Modus' }}{{ $selectedEnrollmentMode['requires_reset'] ? ' · geplante Neueinrichtung erforderlich' : '' }}</p>
                                <p class="mt-1">{{ $selectedEnrollmentMode['description'] }}</p>
                            </div>
                        @endif
                        @can('devices.enrollment.manage')<button type="button" wire:click="createEnrollment" @disabled(!$activeAssignment || !$enrollmentProvider || !$enrollmentMode) class="mt-4 min-h-11 w-full rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">Persönliche Einrichtungs-E-Mail senden</button>@endcan
                        <div class="mt-4 space-y-2">
                            @forelse($selectedDevice->enrollments->take(4) as $enrollment)
                                <div class="flex items-center justify-between gap-3 rounded-lg bg-rt-surface-muted px-3 py-2 text-sm dark:bg-rt-dark-surface-muted"><span>{{ $enrollment->provider }} · {{ str_replace('_',' ',$enrollment->mode) }}</span><span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $enrollment->status->value }} · {{ $enrollment->expires_at?->format('d.m. H:i') }}</span></div>
                            @empty<p class="text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Einladung erstellt.</p>@endforelse
                        </div>
                    </div>

                    <div class="rounded-xl border border-rt-border p-4 dark:border-rt-dark-border">
                        <h3 class="font-semibold text-rt-text dark:text-white">Microsoft 365, Outlook, Google & Apple vorbereiten</h3>
                        <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Es werden nur UPN/E-Mail, externe Referenz und sichere Modern-Auth-/SSO-Sollprofile gespeichert. Der Mitarbeiter bestätigt den offiziellen Anmeldedialog einmal selbst.</p>
                        <div class="mt-4 space-y-2">
                            @foreach(['microsoft_365' => 'Microsoft 365 / Outlook / Teams / OneDrive', 'google_workspace' => 'Google Workspace / Gmail / Drive / Chrome', 'apple_managed' => 'Apple Business Managed Account / SSO'] as $value => $label)
                                <label class="flex min-h-11 items-center gap-3 rounded-xl border border-rt-border px-3 text-sm dark:border-rt-dark-border"><input type="checkbox" wire:model="accountProviders" value="{{ $value }}" @disabled(! auth()->user()->can('devices.accounts.manage')) class="rounded border-rt-border text-rt-red focus:ring-rt-red disabled:cursor-not-allowed disabled:opacity-60"><span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                        @can('devices.accounts.manage')<button type="button" wire:click="prepareAccounts" @disabled(!$activeAssignment) class="mt-4 min-h-11 w-full rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-40 dark:bg-white dark:text-slate-950">Konten & Profile vorbereiten</button>@endcan
                        <div class="mt-4 space-y-2">
                            @forelse($selectedDevice->accountAssignments as $account)
                                <div class="rounded-lg bg-rt-surface-muted px-3 py-2 dark:bg-rt-dark-surface-muted"><p class="text-sm font-medium text-rt-text dark:text-white">{{ $account->provisioningProfile?->name }}</p><p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $account->identityAccount?->principal }} · {{ str_replace('_',' ',$account->status) }}</p></div>
                            @empty<p class="text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Kontosollprofile zugewiesen.</p>@endforelse
                        </div>
                    </div>
                </div>

                @can('devices.commands.execute')
                <div id="device-panel-{{ $selectedDevice->public_id }}-files" x-show.important="tab==='files'" x-cloak class="mt-5 grid gap-5 lg:grid-cols-[0.8fr_1.2fr]" role="tabpanel" aria-labelledby="device-tab-{{ $selectedDevice->public_id }}-files" tabindex="0">
                    <div class="rounded-xl border border-rt-border p-4 dark:border-rt-dark-border">
                        <h3 class="font-semibold text-rt-text dark:text-white">Datei, Paket oder Skript</h3>
                        <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Uploads liegen privat, werden gehasht und erst nach expliziter Adminfreigabe für eine Geräteaktion verwendet. XLSX-Dateien werden verteilt, nicht als Programm ausgeführt.</p>
                        @can('devices.commands.execute')
                            <input type="file" wire:model="artifactUpload" class="mt-4 block w-full rounded-xl border border-dashed border-rt-border p-3 text-sm dark:border-rt-dark-border">
                            @error('artifactUpload')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            <button type="button" wire:click="uploadArtifact" wire:loading.attr="disabled" @disabled(!$artifactUpload) class="mt-3 min-h-11 w-full rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:opacity-40">Privat hochladen & prüfen</button>
                        @endcan
                        <div class="mt-4 space-y-2">
                            @forelse($selectedDevice->artifacts as $artifact)
                                <div class="rounded-xl border border-rt-border p-3 dark:border-rt-dark-border"><div class="flex items-start justify-between gap-2"><div class="min-w-0"><p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $artifact->name }}</p><p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $artifact->kind }} · {{ number_format(($artifact->size_bytes ?? 0)/1024, 1, ',', '.') }} KB · SHA {{ substr($artifact->sha256,0,10) }}…</p></div><span class="rounded-full px-2 py-1 text-[11px] font-semibold {{ $artifact->approved_at ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $artifact->approved_at ? 'freigegeben' : 'Prüfung offen' }}</span></div>@can('devices.commands.execute')@if(!$artifact->approved_at)<button type="button" wire:click="approveArtifact({{ $artifact->id }})" class="mt-2 text-xs font-semibold text-rt-red hover:underline">Als Admin freigeben</button>@endif @endcan</div>
                            @empty<p class="text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine Datei hinterlegt.</p>@endforelse
                        </div>
                    </div>

                    <div class="rounded-xl border border-rt-border p-4 dark:border-rt-dark-border">
                        <h3 class="font-semibold text-rt-text dark:text-white">Protokollierte Geräteaktion</h3>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <label class="text-sm font-medium text-rt-text dark:text-white">Provider<select wire:model="commandProvider" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="">Nicht verfügbar</option>@foreach($compatibleProviders as $provider)@if(($provider['capabilities']['commands'] ?? []) !== [])<option value="{{ $provider['key'] }}">{{ $provider['label'] }}</option>@endif @endforeach</select></label>
                            <label class="text-sm font-medium text-rt-text dark:text-white">Aktion<select wire:model.live="commandType" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">@foreach(\App\Enums\DeviceCommandType::cases() as $type)@if(in_array($type->value, $availableCommandTypes, true))<option value="{{ $type->value }}">{{ match($type->value){'sync'=>'Synchronisieren','lock'=>'Sperren','unlock'=>'Entsperren','wipe'=>'Fernlöschen (Vier Augen)','restart'=>'Neustarten','execute_script'=>'Freigegebenes Skript ausführen','install_software'=>'Software/Datei installieren','uninstall_software'=>'Software deinstallieren','collect_diagnostics'=>'Diagnose erfassen','apply_profile'=>'Kontenprofil anwenden','apply_managed_profile'=>'OpenUEM-Windows-Profil ausführen','start_remote_support'=>'Fernsupport starten'} }}</option>@endif @endforeach</select></label>
                        </div>
                        @if(in_array($commandType,['execute_script','install_software'],true))
                            <label class="mt-3 block text-sm font-medium text-rt-text dark:text-white">Freigegebenes Artefakt<select wire:model="commandArtifactId" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="">Bitte auswählen</option>@foreach($selectedDevice->artifacts->whereNotNull('approved_at') as $artifact)<option value="{{ $artifact->id }}">{{ $artifact->name }} · {{ substr($artifact->sha256,0,10) }}…</option>@endforeach</select></label>
                        @endif
                        @if($commandType === 'apply_managed_profile')
                            <label class="mt-3 block text-sm font-medium text-rt-text dark:text-white">Freigegebenes OpenUEM-Profil
                                <select wire:model="commandNativeProfileId" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                                    <option value="">Bitte auswählen</option>
                                    @foreach($nativeProfileOptions as $nativeProfile)
                                        <option value="{{ $nativeProfile['profile_id'] }}">{{ $nativeProfile['label'] ?: 'Profil '.$nativeProfile['profile_id'] }} · #{{ $nativeProfile['profile_id'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <x-input-error for="commandNativeProfileId" class="mt-1" />
                            <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">Nur für dieses Gerät freigegebene Profile. Nach einem unklaren Ausführungsergebnis bleibt die Übergabe gesperrt; kein automatischer Neustart des Auftrags.</p>
                        @endif
                        <label class="mt-3 block text-sm font-medium text-rt-text dark:text-white">Begründung<textarea wire:model="commandJustification" rows="3" placeholder="Konkreter Anlass, erwartetes Ergebnis und Ticket/Notfall …" class="mt-1.5 w-full rounded-xl border border-rt-border bg-white px-3 py-2 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"></textarea></label>
                        @error('commandJustification')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        @if($commandProvider && !$selectedCommandsEnabled)<div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">Der Provider ist sichtbar, aber externe Mutationen sind über den globalen Produktionsschalter gesperrt.</div>@endif
                        @if($commandType === 'wipe')<div class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300"><strong>Irreversibel:</strong> Die Anforderung bleibt stehen, bis ein anderer globaler Administrator sie freigibt.</div>@endif
                        @can('devices.commands.execute')<button type="button" wire:click="requestCommand" @disabled(!$commandProvider || !$selectedCommandsEnabled || !$commandType) class="mt-4 min-h-11 w-full rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-40 dark:bg-white dark:text-slate-950">Aktion sicher anfordern</button>@endcan
                    </div>
                </div>
                @endcan

                @can('devices.audit.view')
                <div id="device-panel-{{ $selectedDevice->public_id }}-audit" x-show.important="tab==='audit'" x-cloak class="mt-5" role="tabpanel" aria-labelledby="device-tab-{{ $selectedDevice->public_id }}-audit" tabindex="0">
                    <div class="overflow-hidden rounded-xl border border-rt-border dark:border-rt-dark-border">
                        <table class="min-w-full divide-y divide-rt-border text-left text-sm dark:divide-rt-dark-border"><thead class="bg-rt-surface-muted text-xs uppercase tracking-wide text-rt-muted dark:bg-rt-dark-surface-muted"><tr><th class="px-3 py-3">Zeit</th><th class="px-3 py-3">Aktion</th><th class="px-3 py-3">Provider</th><th class="px-3 py-3">Angefordert von</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Freigabe</th></tr></thead><tbody class="divide-y divide-rt-border dark:divide-rt-dark-border">
                            @forelse($selectedDevice->commands->take(30) as $command)
                                <tr><td class="px-3 py-3 text-xs text-rt-muted">{{ $command->requested_at?->format('d.m.Y H:i') }}</td><td class="px-3 py-3 font-medium text-rt-text dark:text-white">{{ str_replace('_',' ',$command->type->value) }}<span class="mt-1 block max-w-md text-xs font-normal text-rt-muted dark:text-rt-dark-muted">{{ $command->justification }}</span></td><td class="px-3 py-3">{{ $command->provider }}</td><td class="px-3 py-3">{{ $command->requester?->name ?? 'System' }}</td><td class="px-3 py-3"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ str_replace('_',' ',$command->status->value) }}</span></td><td class="px-3 py-3">@if($command->status === \App\Enums\DeviceCommandStatus::PendingApproval)@can('devices.wipe')<button type="button" wire:click="approveWipe('{{ $command->public_id }}')" @disabled((int)$command->requested_by === (int)auth()->id()) class="min-h-9 rounded-lg bg-red-600 px-3 text-xs font-semibold text-white disabled:opacity-40">Als 2. Admin freigeben</button>@endcan @else<span class="text-xs text-rt-muted">{{ $command->approver?->name ?? '—' }}</span>@endif</td></tr>
                            @empty<tr><td colspan="6" class="px-3 py-10 text-center text-rt-muted">Noch keine Geräteaktion protokolliert.</td></tr>@endforelse
                        </tbody></table>
                    </div>
                </div>
                @endcan
            </section>
        @endif
    </div>

    @include('livewire.devices.partials.device-capture-modal')
    @include('livewire.devices.partials.provider-link-modal', ['compatibleProviders' => $compatibleProviders ?? collect()])
</x-ui.page>
