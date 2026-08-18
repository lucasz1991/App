<x-ui.page
    title="Geräteverwaltung"
    eyebrow="IT & Betrieb"
    description="Virtuelles Lager, Mitarbeiterzuweisung, sichere Bereitstellung und capability-basierte Fernverwaltung."
    :count="number_format($stats['total'], 0, ',', '.')"
    page-key="device-management"
>
    <x-slot:actions>
        @can('devices.manage')
            <button
                type="button"
                wire:click="openCreate"
                class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-3 py-2 text-sm font-semibold text-white shadow-rt-sm transition hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30 min-[430px]:px-4"
                aria-label="Gerät erfassen"
                title="Gerät erfassen"
            >
                <i data-feather="plus" class="h-4 w-4" aria-hidden="true"></i>
                <span class="hidden min-[430px]:inline">Gerät erfassen</span>
            </button>
        @endcan
    </x-slot:actions>

    <div class="space-y-5" data-device-management>
        <div class="rounded-2xl border border-sky-200 bg-sky-50/90 p-4 text-sm text-sky-950 shadow-rt-xs dark:border-sky-900/70 dark:bg-sky-950/30 dark:text-sky-100">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-900/70 dark:text-sky-200">
                    <i data-feather="shield" class="h-4 w-4" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="font-semibold">Sichere Startklar-Bereitstellung ohne Passwortspeicher</p>
                    <p class="mt-1 leading-6 text-sky-800 dark:text-sky-200/80">
                        RailTime bereitet Microsoft 365/Outlook, Google Workspace, Apple-SSO, Apps und Profile vor. Mitarbeitende bestätigen einmal den offiziellen OAuth-/SSO-/MFA-Dialog. Nicht belegte Providerfunktionen bleiben gesperrt.
                    </p>
                </div>
            </div>
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Gerätekennzahlen">
            @foreach([
                ['label' => 'Gesamt', 'value' => $stats['total'], 'hint' => 'Geräte', 'icon' => 'monitor', 'tone' => 'sky'],
                ['label' => 'Zugewiesen', 'value' => $stats['assigned'], 'hint' => 'aktive Ausgaben', 'icon' => 'user-check', 'tone' => 'emerald'],
                ['label' => 'Virtuelles Lager', 'value' => $stats['inventory'], 'hint' => 'nicht zugewiesener Bestand', 'icon' => 'package', 'tone' => 'cyan'],
                ['label' => 'Handlungsbedarf', 'value' => $stats['attention'], 'hint' => 'Warnung oder Fehler', 'icon' => 'alert-triangle', 'tone' => 'amber'],
            ] as $stat)
                <article class="rounded-2xl border border-rt-border/80 bg-white/90 p-4 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface/90">
                    <div class="flex items-center gap-3">
                        <span @class([
                            'grid h-11 w-11 place-items-center rounded-2xl',
                            'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' => $stat['tone'] === 'sky',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $stat['tone'] === 'emerald',
                            'bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300' => $stat['tone'] === 'cyan',
                            'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $stat['tone'] === 'amber',
                        ])>
                            <i data-feather="{{ $stat['icon'] }}" class="h-5 w-5" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted">{{ $stat['label'] }}</p>
                            <p class="mt-1 text-2xl font-bold tracking-tight text-rt-text dark:text-white">{{ number_format($stat['value'], 0, ',', '.') }}</p>
                            <p class="text-xs text-rt-soft dark:text-rt-dark-soft">{{ $stat['hint'] }}</p>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        @can('devices.manage')
            <section class="rounded-2xl border border-rt-border/80 bg-white p-4 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface" aria-labelledby="device-import-title">
                <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <h2 id="device-import-title" class="text-base font-bold text-rt-text dark:text-white">Bestehende Geräteflotte importieren</h2>
                        <p class="mt-1 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Bis zu 500 vorhandene Geräte per CSV übernehmen und über die Mitarbeiter-E-Mail direkt zuordnen. Die Datei wird vollständig validiert; bei einem Fehler wird keine Teilmenge gespeichert.</p>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <input type="file" wire:model="inventoryImport" accept=".csv,text/csv,text/plain" class="block min-h-11 max-w-xl flex-1 rounded-xl border border-dashed border-rt-border p-2 text-sm dark:border-rt-dark-border">
                            <a href="{{ route('devices.import-template') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rt-border px-3 text-sm font-semibold text-rt-text hover:bg-rt-surface-muted dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface-muted"><i data-feather="download" class="h-4 w-4"></i>CSV-Vorlage</a>
                        </div>
                        @error('inventoryImport')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        @if($lastImportSummary)
                            <p class="mt-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ $lastImportSummary['created'] }} neu · {{ $lastImportSummary['updated'] }} aktualisiert · {{ $lastImportSummary['assigned'] }} zugewiesen</p>
                        @endif
                    </div>
                    <button type="button" wire:click="importInventory" wire:loading.attr="disabled" @disabled(!$inventoryImport) class="min-h-11 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-950">Bestand prüfen & importieren</button>
                </div>
            </section>
        @endcan

        @if($showCreateForm)
            <section class="rounded-2xl border border-rt-red/25 bg-white p-5 shadow-rt-md dark:border-rt-red/40 dark:bg-rt-dark-surface" aria-labelledby="device-create-title">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 id="device-create-title" class="text-lg font-bold text-rt-text dark:text-white">Gerät im virtuellen Lager erfassen</h2>
                        <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Mindestens Inventar- oder Seriennummer ist erforderlich.</p>
                    </div>
                    <button type="button" wire:click="closeCreate" class="rounded-xl p-2 text-rt-muted hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted" aria-label="Formular schließen">
                        <i data-feather="x" class="h-5 w-5" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach([
                        ['key' => 'asset_tag', 'label' => 'Inventarnummer', 'placeholder' => 'RT-WIN-0142'],
                        ['key' => 'serial_number', 'label' => 'Seriennummer / IMEI', 'placeholder' => 'Hersteller-Seriennummer'],
                        ['key' => 'display_name', 'label' => 'Gerätename', 'placeholder' => 'Dienstlaptop Windows'],
                        ['key' => 'hostname', 'label' => 'Hostname', 'placeholder' => 'RT-NB-0142'],
                        ['key' => 'manufacturer', 'label' => 'Hersteller', 'placeholder' => 'Dell, Apple, Samsung …'],
                        ['key' => 'model', 'label' => 'Modell', 'placeholder' => 'Latitude 5440'],
                        ['key' => 'os_version', 'label' => 'Betriebssystemstand', 'placeholder' => 'Windows 11 23H2'],
                        ['key' => 'declared_location', 'label' => 'Deklarierter Standort', 'placeholder' => 'Köln Hbf / Hauptlager'],
                    ] as $field)
                        <label class="block text-sm font-medium text-rt-text dark:text-white">
                            {{ $field['label'] }}
                            <input
                                type="text"
                                wire:model="deviceForm.{{ $field['key'] }}"
                                placeholder="{{ $field['placeholder'] }}"
                                class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm text-rt-text outline-none transition focus:border-rt-red focus:ring-2 focus:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"
                            >
                            @error('deviceForm.'.$field['key']) <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endforeach

                    <label class="block text-sm font-medium text-rt-text dark:text-white">
                        Formfaktor
                        <select wire:model="deviceForm.form_factor" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                            <option value="laptop">Laptop</option><option value="desktop">Desktop</option><option value="phone">Handy</option><option value="tablet">Tablet</option><option value="other">Sonstiges</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-rt-text dark:text-white">
                        Plattform
                        <select wire:model="deviceForm.platform" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                            @foreach(\App\Enums\DevicePlatform::cases() as $platform)
                                <option value="{{ $platform->value }}">{{ match($platform->value) {'macos' => 'macOS', 'ios' => 'iOS', 'ipados' => 'iPadOS', default => ucfirst($platform->value)} }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-rt-text dark:text-white">
                        Eigentum
                        <select wire:model="deviceForm.ownership" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                            <option value="corporate">Firmengerät</option><option value="byod">Privat / BYOD</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-rt-text dark:text-white">
                        Primärprovider (optional)
                        <select wire:model="deviceForm.primary_provider" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                            <option value="">Noch nicht verbunden</option>
                            @foreach($providerCards as $provider)
                                @if($provider['key'] !== 'simulation')<option value="{{ $provider['key'] }}">{{ $provider['label'] }}</option>@endif
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="closeCreate" class="min-h-11 rounded-xl border border-rt-border px-4 text-sm font-semibold text-rt-text hover:bg-rt-surface-muted dark:border-rt-dark-border dark:text-white">Abbrechen</button>
                    <button type="button" wire:click="createDevice" wire:loading.attr="disabled" class="min-h-11 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white hover:bg-rt-red-dark disabled:opacity-50">Im Lager erfassen</button>
                </div>
            </section>
        @endif

        <section class="overflow-hidden rounded-2xl border border-rt-border/80 bg-white shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
            <div class="border-b border-rt-border/70 p-4 dark:border-rt-dark-border">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <label class="relative xl:col-span-2">
                        <span class="sr-only">Geräte suchen</span>
                        <i data-feather="search" class="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-rt-muted" aria-hidden="true"></i>
                        <input type="search" wire:model.live.debounce.350ms="search" placeholder="Gerät, Seriennummer oder Mitarbeiter …" class="min-h-11 w-full rounded-xl border border-rt-border bg-white pl-10 pr-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                    </label>
                    <select wire:model.live="platformFilter" aria-label="Plattform filtern" class="min-h-11 rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                        <option value="">Alle Plattformen</option>
                        @foreach(\App\Enums\DevicePlatform::cases() as $platform)<option value="{{ $platform->value }}">{{ ucfirst($platform->value) }}</option>@endforeach
                    </select>
                    <select wire:model.live="lifecycleFilter" aria-label="Lebenszyklus filtern" class="min-h-11 rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                        <option value="">Alle Gerätezustände</option>
                        <option value="inventory">Im Lager</option><option value="preparing">Vorbereitung</option><option value="assigned">Zugewiesen</option><option value="in_service">Im Einsatz</option><option value="repair">Reparatur</option><option value="lost">Verloren</option><option value="retired">Ausgemustert</option>
                    </select>
                    <select wire:model.live="complianceFilter" aria-label="Compliance filtern" class="min-h-11 rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                        <option value="">Alle Compliance-Stati</option><option value="compliant">Konform</option><option value="warning">Warnung</option><option value="non_compliant">Nicht konform</option><option value="unknown">Unbekannt</option>
                    </select>
                    <select wire:model.live="locationFilter" aria-label="Standort filtern" class="min-h-11 rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                        <option value="">Alle Standorte</option>@foreach($locations as $location)<option value="{{ $location }}">{{ $location }}</option>@endforeach
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-rt-border/70 text-left text-sm dark:divide-rt-dark-border">
                    <thead class="bg-rt-surface-muted/80 text-xs uppercase tracking-[0.08em] text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Gerät</th><th class="px-4 py-3 font-semibold">Mitarbeiter</th><th class="px-4 py-3 font-semibold">Plattform</th><th class="px-4 py-3 font-semibold">Standort</th><th class="px-4 py-3 font-semibold">Compliance</th><th class="px-4 py-3 font-semibold">Verwaltung</th><th class="px-4 py-3 font-semibold">Sync</th><th class="px-4 py-3"><span class="sr-only">Aktion</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/70">
                        @forelse($devices as $device)
                            @php
                                $assignment = $device->activeAssignment;
                                $compliance = $device->compliance_status->value;
                                $management = $device->management_status->value;
                            @endphp
                            <tr class="transition hover:bg-rt-surface-muted/60 dark:hover:bg-rt-dark-surface-muted/60">
                                <td class="px-4 py-3">
                                    <button type="button" wire:click="selectDevice('{{ $device->public_id }}')" class="text-left font-semibold text-rt-text hover:text-rt-red dark:text-white dark:hover:text-rt-red-light">
                                        {{ $device->display_name ?: $device->hostname ?: 'Unbenanntes Gerät' }}
                                        <span class="mt-0.5 block text-xs font-normal text-rt-muted dark:text-rt-dark-muted">{{ $device->asset_tag ?: $device->serial_number }}</span>
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-rt-text dark:text-white">{{ $assignment?->user?->name ?? 'Im Lager' }}</td>
                                <td class="px-4 py-3"><span class="rounded-lg border border-rt-border px-2 py-1 text-xs font-medium dark:border-rt-dark-border">{{ match($device->platform->value) {'macos' => 'macOS', 'ios' => 'iOS', 'ipados' => 'iPadOS', default => ucfirst($device->platform->value)} }}</span></td>
                                <td class="px-4 py-3 text-rt-muted dark:text-rt-dark-muted">{{ $device->declared_location ?: 'Nicht gemeldet' }}</td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => $compliance === 'compliant',
                                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => $compliance === 'warning',
                                        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' => $compliance === 'non_compliant',
                                        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' => !in_array($compliance, ['compliant','warning','non_compliant'], true),
                                    ])>{{ match($compliance) {'compliant' => 'Konform', 'warning' => 'Warnung', 'non_compliant' => 'Nicht konform', 'exempt' => 'Ausgenommen', default => 'Unbekannt'} }}</span>
                                </td>
                                <td class="px-4 py-3 text-rt-muted dark:text-rt-dark-muted">{{ match($management) {'managed' => 'Verwaltet', 'limited' => 'Eingeschränkt', 'pending' => 'Einladung offen', 'error' => 'Fehler', default => 'Nicht verwaltet'} }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $device->last_synced_at?->diffForHumans() ?? 'Noch nie' }}</td>
                                <td class="px-4 py-3 text-right"><button type="button" wire:click="selectDevice('{{ $device->public_id }}')" class="rounded-lg p-2 text-rt-muted hover:bg-rt-surface-muted hover:text-rt-red dark:hover:bg-rt-dark-surface-muted" aria-label="Details zu {{ $device->display_name }}"><i data-feather="chevron-right" class="h-4 w-4"></i></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Noch keine passenden Geräte gefunden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-rt-border/70 px-4 py-3 dark:border-rt-dark-border">{{ $devices->links() }}</div>
        </section>

        <section class="rounded-2xl border border-rt-border/80 bg-white p-4 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface" aria-labelledby="provider-readiness-title">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 id="provider-readiness-title" class="text-base font-bold text-rt-text dark:text-white">Provider- und Produktionsbereitschaft</h2>
                    <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Konfiguration allein aktiviert keine Geräteaktion. Der globale Mutationsschalter und echte Connectorfähigkeiten bleiben zusätzliche Gates.</p>
                </div>
                <span @class([
                    'rounded-full px-3 py-1 text-xs font-semibold',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => $productionCommandsEnabled,
                    'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => !$productionCommandsEnabled,
                ])>{{ $productionCommandsEnabled ? 'Mutationen freigegeben' : 'Mutationen sicher gesperrt' }}</span>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach($providerCards as $provider)
                    <article class="rounded-xl border border-rt-border p-3 dark:border-rt-dark-border">
                        <div class="flex items-start justify-between gap-2">
                            <div><p class="text-sm font-semibold text-rt-text dark:text-white">{{ $provider['label'] }}</p><p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ implode(', ', $provider['capabilities']['platforms'] ?? []) }}</p></div>
                            <span class="mt-0.5 h-2.5 w-2.5 rounded-full {{ $provider['enabled'] ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600' }}" title="{{ $provider['enabled'] ? 'aktiviert' : 'deaktiviert' }}"></span>
                        </div>
                        <p class="mt-3 text-xs text-rt-muted dark:text-rt-dark-muted">{{ count($provider['capabilities']['commands'] ?? []) }} belegte Kommandotypen · {{ ($provider['capabilities']['remote_support'] ?? false) ? 'Remote-Support' : 'kein Remote-Support' }}</p>
                        @if(auth()->user()?->isSuperAdmin())
                            <a href="{{ route('admin.settings') }}" class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-rt-border px-2 text-xs font-semibold text-rt-text hover:bg-rt-surface-muted dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface-muted">Im Geräte-Setup prüfen</a>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        @if($selectedDevice)
            @php
                $activeAssignment = $selectedDevice->activeAssignment;
                $checks = $selectedDevice->readinessChecks->keyBy('check_key');
                $ready = collect(array_keys(\App\Services\DeviceManagement\DeviceReadinessService::REQUIRED_CHECKS))->every(fn($key) => in_array($checks->get($key)?->status, ['passed','not_applicable'], true));
                $compatibleProviders = $providerCards->filter(fn($provider) => $provider['enabled'] && in_array($selectedDevice->platform->value, $provider['capabilities']['platforms'] ?? [], true));
                $selectedCommandProvider = $compatibleProviders->firstWhere('key', $commandProvider);
                $availableCommandTypes = $selectedCommandProvider['capabilities']['commands'] ?? [];
                $selectedCommandsEnabled = (bool) ($selectedCommandProvider['commands_enabled'] ?? false);
                $remoteSupportProviders = $compatibleProviders->filter(fn($provider) => ($provider['capabilities']['remote_support'] ?? false) && $provider['remote_url_available']);
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
                            <label class="text-sm font-medium text-rt-text dark:text-white">Provider<select wire:model="enrollmentProvider" @disabled(! auth()->user()->can('devices.enrollment.manage')) class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="">Nicht verfügbar</option>@foreach($compatibleProviders as $provider)@if($provider['capabilities']['enrollment'] ?? false)<option value="{{ $provider['key'] }}">{{ $provider['label'] }}</option>@endif @endforeach</select></label>
                            <label class="text-sm font-medium text-rt-text dark:text-white">Modus<select wire:model="enrollmentMode" @disabled(! auth()->user()->can('devices.enrollment.manage')) class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="agent">Agent</option><option value="work_profile">Android-Arbeitsprofil</option><option value="profile">Apple-Profil</option><option value="ade">Apple ADE (Neugerät/Reset)</option><option value="fully_managed">Android Fully Managed (Reset)</option></select></label>
                        </div>
                        @error('enrollmentProvider')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        @can('devices.enrollment.manage')<button type="button" wire:click="createEnrollment" @disabled(!$activeAssignment || !$enrollmentProvider) class="mt-4 min-h-11 w-full rounded-xl bg-rt-red px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">Persönliche Einrichtungs-E-Mail senden</button>@endcan
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
                            <label class="text-sm font-medium text-rt-text dark:text-white">Aktion<select wire:model.live="commandType" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">@foreach(\App\Enums\DeviceCommandType::cases() as $type)@if(in_array($type->value, $availableCommandTypes, true))<option value="{{ $type->value }}">{{ match($type->value){'sync'=>'Synchronisieren','lock'=>'Sperren','unlock'=>'Entsperren','wipe'=>'Fernlöschen (Vier Augen)','restart'=>'Neustarten','execute_script'=>'Freigegebenes Skript ausführen','install_software'=>'Software/Datei installieren','uninstall_software'=>'Software deinstallieren','collect_diagnostics'=>'Diagnose erfassen','apply_profile'=>'Profil anwenden','start_remote_support'=>'Fernsupport starten'} }}</option>@endif @endforeach</select></label>
                        </div>
                        @if(in_array($commandType,['execute_script','install_software'],true))
                            <label class="mt-3 block text-sm font-medium text-rt-text dark:text-white">Freigegebenes Artefakt<select wire:model="commandArtifactId" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"><option value="">Bitte auswählen</option>@foreach($selectedDevice->artifacts->whereNotNull('approved_at') as $artifact)<option value="{{ $artifact->id }}">{{ $artifact->name }} · {{ substr($artifact->sha256,0,10) }}…</option>@endforeach</select></label>
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
</x-ui.page>
