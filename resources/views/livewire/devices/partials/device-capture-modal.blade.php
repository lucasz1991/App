@can('devices.manage')
    <x-dialog-modal id="device-capture-modal" wire:model.live="showCreateForm" maxWidth="4xl">
        <x-slot name="title">
            Geräte erfassen
        </x-slot>

        <x-slot name="content">
            <div class="space-y-5" data-device-capture-modal>
                <div class="flex items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50/90 p-4 text-sky-950 dark:border-sky-900/70 dark:bg-sky-950/30 dark:text-sky-100">
                    <span class="mt-0.5 grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-900/70 dark:text-sky-200">
                        <i data-feather="package" class="h-4 w-4" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold">Einzelgerät oder vorhandenen Bestand übernehmen</p>
                        <p class="mt-1 text-sm leading-6 text-sky-800 dark:text-sky-200/80">
                            Erfasse ein Gerät vollständig im virtuellen Lager oder importiere bis zu 500 bestehende Geräte transaktionssicher per CSV.
                        </p>
                    </div>
                </div>

                <div
                    class="grid grid-cols-2 gap-1 rounded-2xl bg-rt-surface-muted p-1.5 dark:bg-rt-dark-surface-muted"
                    role="group"
                    aria-label="Erfassungsart"
                >
                    <button
                        id="device-capture-tab-manual"
                        type="button"
                        wire:click="setCaptureMode('manual')"
                        aria-pressed="{{ $captureMode === 'manual' ? 'true' : 'false' }}"
                        @class([
                            'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15',
                            'bg-white text-rt-red shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-red-light dark:ring-rt-dark-border' => $captureMode === 'manual',
                            'text-rt-muted hover:bg-white/65 hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface/65 dark:hover:text-white' => $captureMode !== 'manual',
                        ])
                    >
                        <i data-feather="plus-circle" class="h-4 w-4" aria-hidden="true"></i>
                        <span>Einzelgerät</span>
                    </button>
                    <button
                        id="device-capture-tab-import"
                        type="button"
                        wire:click="setCaptureMode('import')"
                        aria-pressed="{{ $captureMode === 'import' ? 'true' : 'false' }}"
                        @class([
                            'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15',
                            'bg-white text-rt-red shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-red-light dark:ring-rt-dark-border' => $captureMode === 'import',
                            'text-rt-muted hover:bg-white/65 hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface/65 dark:hover:text-white' => $captureMode !== 'import',
                        ])
                    >
                        <i data-feather="upload-cloud" class="h-4 w-4" aria-hidden="true"></i>
                        <span>CSV-Bestand</span>
                    </button>
                </div>

                @error('captureMode')
                    <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                @if ($captureMode === 'manual')
                    <form
                        id="device-capture-manual-form"
                        wire:submit="createDevice"
                        class="space-y-5"
                    >
                        <section class="space-y-5" aria-label="Einzelgerät erfassen">
                            <div class="rounded-2xl border border-rt-border/80 p-4 dark:border-rt-dark-border sm:p-5">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 class="font-semibold text-rt-text dark:text-white">Identifikation</h3>
                                        <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Mindestens Inventar- oder Seriennummer und ein Gerätename sind erforderlich.</p>
                                    </div>
                                    <span class="rounded-full bg-rt-red/10 px-2.5 py-1 text-xs font-semibold text-rt-red dark:bg-rt-red/20 dark:text-rt-red-light">Pflichtangaben</span>
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    @foreach([
                                        ['key' => 'asset_tag', 'label' => 'Inventarnummer', 'placeholder' => 'RT-WIN-0142'],
                                        ['key' => 'serial_number', 'label' => 'Seriennummer / IMEI', 'placeholder' => 'Hersteller-Seriennummer'],
                                        ['key' => 'display_name', 'label' => 'Gerätename', 'placeholder' => 'Dienstlaptop Windows'],
                                        ['key' => 'hostname', 'label' => 'Hostname', 'placeholder' => 'RT-NB-0142'],
                                    ] as $field)
                                        <div>
                                            <label for="device-capture-{{ $field['key'] }}" class="block text-sm font-medium text-rt-text dark:text-white">
                                                {{ $field['label'] }}
                                            </label>
                                            <input
                                                id="device-capture-{{ $field['key'] }}"
                                                type="text"
                                                wire:model="deviceForm.{{ $field['key'] }}"
                                                placeholder="{{ $field['placeholder'] }}"
                                                class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm text-rt-text outline-none transition focus:border-rt-red focus:ring-2 focus:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"
                                            >
                                            @error('deviceForm.'.$field['key'])
                                                <p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="rounded-2xl border border-rt-border/80 p-4 dark:border-rt-dark-border sm:p-5">
                                <h3 class="font-semibold text-rt-text dark:text-white">Hardware und Einsatzort</h3>
                                <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Diese Angaben verbessern Inventarsuche, Übergabe und Support.</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    @foreach([
                                        ['key' => 'manufacturer', 'label' => 'Hersteller', 'placeholder' => 'Dell, Apple, Samsung …'],
                                        ['key' => 'model', 'label' => 'Modell', 'placeholder' => 'Latitude 5440'],
                                        ['key' => 'os_version', 'label' => 'Betriebssystemstand', 'placeholder' => 'Windows 11 23H2'],
                                        ['key' => 'declared_location', 'label' => 'Deklarierter Standort', 'placeholder' => 'Köln Hbf / Hauptlager'],
                                    ] as $field)
                                        <div>
                                            <label for="device-capture-{{ $field['key'] }}" class="block text-sm font-medium text-rt-text dark:text-white">
                                                {{ $field['label'] }}
                                            </label>
                                            <input
                                                id="device-capture-{{ $field['key'] }}"
                                                type="text"
                                                wire:model="deviceForm.{{ $field['key'] }}"
                                                placeholder="{{ $field['placeholder'] }}"
                                                class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm text-rt-text outline-none transition focus:border-rt-red focus:ring-2 focus:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white"
                                            >
                                            @error('deviceForm.'.$field['key'])
                                                <p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="rounded-2xl border border-rt-border/80 p-4 dark:border-rt-dark-border sm:p-5">
                                <h3 class="font-semibold text-rt-text dark:text-white">Gerätekategorie und Verwaltung</h3>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <label for="device-capture-form-factor" class="block text-sm font-medium text-rt-text dark:text-white">Formfaktor</label>
                                        <select id="device-capture-form-factor" wire:model="deviceForm.form_factor" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                                            <option value="laptop">Laptop</option>
                                            <option value="desktop">Desktop</option>
                                            <option value="phone">Handy</option>
                                            <option value="tablet">Tablet</option>
                                            <option value="other">Sonstiges</option>
                                        </select>
                                        @error('deviceForm.form_factor')<p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label for="device-capture-platform" class="block text-sm font-medium text-rt-text dark:text-white">Plattform</label>
                                        <select id="device-capture-platform" wire:model="deviceForm.platform" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                                            @foreach(\App\Enums\DevicePlatform::cases() as $platform)
                                                <option value="{{ $platform->value }}">{{ match($platform->value) {'macos' => 'macOS', 'ios' => 'iOS', 'ipados' => 'iPadOS', default => ucfirst($platform->value)} }}</option>
                                            @endforeach
                                        </select>
                                        @error('deviceForm.platform')<p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label for="device-capture-ownership" class="block text-sm font-medium text-rt-text dark:text-white">Eigentum</label>
                                        <select id="device-capture-ownership" wire:model="deviceForm.ownership" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                                            <option value="corporate">Firmengerät</option>
                                            <option value="byod">Privat / BYOD</option>
                                        </select>
                                        @error('deviceForm.ownership')<p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label for="device-capture-provider" class="block text-sm font-medium text-rt-text dark:text-white">Primärprovider</label>
                                        <select id="device-capture-provider" wire:model="deviceForm.primary_provider" class="mt-1.5 min-h-11 w-full rounded-xl border border-rt-border bg-white px-3 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-white">
                                            <option value="">Noch nicht verbunden</option>
                                            @foreach($providerCards as $provider)
                                                @if($provider['key'] !== 'simulation')
                                                    <option value="{{ $provider['key'] }}">{{ $provider['label'] }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        @error('deviceForm.primary_provider')<p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                    </div>
                                </div>
                            </div>
                        </section>
                    </form>
                @else
                    <form
                        id="device-capture-import-form"
                        wire:submit="importInventory"
                    >
                        <section class="space-y-5" aria-label="Gerätebestand importieren">
                            <div class="rounded-2xl border border-rt-border/80 p-4 dark:border-rt-dark-border sm:p-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <h3 class="font-semibold text-rt-text dark:text-white">Bestehende Geräteflotte importieren</h3>
                                        <p class="mt-1 max-w-2xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                            Die CSV kann vorhandene Geräte aktualisieren und über die Mitarbeiter-E-Mail direkt zuordnen. Die gesamte Datei wird vor dem Speichern geprüft; bei einem Fehler wird keine Teilmenge übernommen.
                                        </p>
                                    </div>
                                    <a
                                        href="{{ route('devices.import-template') }}"
                                        class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-rt-border px-3 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface-muted"
                                    >
                                        <i data-feather="download" class="h-4 w-4" aria-hidden="true"></i>
                                        CSV-Vorlage
                                    </a>
                                </div>

                                <div class="mt-5 rounded-2xl border-2 border-dashed border-rt-border bg-rt-surface-muted/45 p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/45 sm:p-5">
                                    <label for="device-capture-inventory-import" class="block text-sm font-semibold text-rt-text dark:text-white">CSV-Datei auswählen</label>
                                    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">CSV oder TXT, maximal 2 MB und 500 Gerätezeilen.</p>
                                    <input
                                        id="device-capture-inventory-import"
                                        type="file"
                                        wire:model="inventoryImport"
                                        accept=".csv,text/csv,text/plain"
                                        class="mt-3 block min-h-11 w-full rounded-xl border border-rt-border bg-white p-2 text-sm text-rt-text file:mr-3 file:rounded-lg file:border-0 file:bg-rt-red/10 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-rt-red dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:file:bg-rt-red/20 dark:file:text-rt-red-light"
                                    >
                                    <p wire:loading wire:target="inventoryImport" class="mt-2 text-sm font-medium text-sky-700 dark:text-sky-300">Datei wird sicher eingelesen …</p>
                                    @error('inventoryImport')
                                        <p class="mt-2 text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            @if($lastImportSummary)
                                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/35 dark:text-emerald-100" role="status">
                                    <p class="font-semibold">Import erfolgreich abgeschlossen</p>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-4">
                                        @foreach([
                                            'rows' => 'Geprüft',
                                            'created' => 'Neu',
                                            'updated' => 'Aktualisiert',
                                            'assigned' => 'Zugewiesen',
                                        ] as $key => $label)
                                            <div class="rounded-xl bg-white/75 px-3 py-2 dark:bg-emerald-950/55">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ $label }}</p>
                                                <p class="mt-1 text-xl font-bold">{{ $lastImportSummary[$key] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </section>
                    </form>
                @endif
            </div>
        </x-slot>

        <x-slot name="footer">
            <button
                type="button"
                wire:click="closeCreate"
                class="inline-flex min-h-11 items-center justify-center rounded-xl border border-rt-border bg-white px-4 text-sm font-semibold text-rt-text transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/15 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted"
            >
                Abbrechen
            </button>

            @if($captureMode === 'manual')
                <button
                    type="submit"
                    form="device-capture-manual-form"
                    wire:loading.attr="disabled"
                    wire:target="createDevice"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-semibold text-white transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/25 disabled:cursor-wait disabled:opacity-50"
                >
                    <i data-feather="plus" class="h-4 w-4" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="createDevice">Im Lager erfassen</span>
                    <span wire:loading wire:target="createDevice">Wird erfasst …</span>
                </button>
            @else
                <button
                    type="submit"
                    form="device-capture-import-form"
                    wire:loading.attr="disabled"
                    wire:target="inventoryImport,importInventory"
                    @disabled(!$inventoryImport)
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-900/20 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-100"
                >
                    <i data-feather="upload" class="h-4 w-4" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="importInventory">Bestand prüfen & importieren</span>
                    <span wire:loading wire:target="importInventory">Import wird geprüft …</span>
                </button>
            @endif
        </x-slot>
    </x-dialog-modal>
@endcan
