{{-- Einstellungen > E-Mail > E-Mails. Wurzel ist die bisherige Abschnittskarte. --}}
<section
    class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
    data-autosave-scope
>
    <x-ui.autosave-status event="mail-settings-saved" target="saveMails" dirty-target="adminEmail" />
    <div class="space-y-5 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
    <div class="w-full sm:max-w-md">
        <label for="admin_email" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
            {{ __('app.admin_email_address') }}
        </label>
        <div class="mt-1.5">
            <x-ui.forms.input
                type="email"
                id="admin_email"
                placeholder="admin@rail-time.de"
                wire:model="adminEmail"
            />
        </div>
        @error('adminEmail')
            <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
        @enderror
        <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">
            {{ __('app.admin_email_hint') }}
        </p>
    </div>

    @php
        $outlookAddinManifest = app(\App\Support\OutlookAddin\OutlookAddinManifest::class);
        $outlookAddinConfiguration = app(\App\Support\OutlookAddin\OutlookAddinConfiguration::class);
        $outlookAddinConfigured = $outlookAddinManifest->ready();
        $outlookAddinDeployed = $outlookAddinConfiguration->deployed();
    @endphp
    <div
        class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-outlook-addin-deployment
    >
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span
                        class="h-2.5 w-2.5 shrink-0 rounded-full {{ $outlookAddinDeployed ? 'bg-emerald-500' : ($outlookAddinConfigured ? 'bg-sky-500' : 'bg-amber-500') }}"
                        aria-hidden="true"
                    ></span>
                    <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                        Outlook-Zentralbereitstellung
                    </p>
                </div>
                <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                    @if ($outlookAddinDeployed)
                        Als zentral bereitgestellt bestätigt. Signatur und Nachrichtenvorlage stammen aus der aktuellen Veröffentlichung.
                    @elseif ($outlookAddinConfigured)
                        Lokal bereit. Manifest zuerst im Microsoft 365 Admin Center einer Pilotgruppe zuweisen und prüfen; danach OUTLOOK_ADDIN_DEPLOYED aktivieren.
                    @else
                        Noch nicht aktiv. Tenant-ID und Client-ID werden einmalig in der Serverkonfiguration ergänzt.
                    @endif
                </p>
            </div>

            <div class="grid shrink-0 gap-2 sm:grid-cols-2">
                @if ($outlookAddinConfigured)
                    <a
                        href="{{ route('admin.outlook-addin.manifest') }}"
                        data-no-navigate
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-text ring-1 ring-inset ring-rt-border/80 transition hover:bg-rt-surface dark:text-rt-dark-text dark:ring-rt-dark-border/80 dark:hover:bg-rt-dark-surface"
                    >
                        <i class="far fa-file-code" aria-hidden="true"></i>
                        Manifest
                    </a>
                    <a
                        href="{{ route('admin.outlook-addin.package') }}"
                        data-no-navigate
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-3 py-2 text-xs font-semibold text-white transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                    >
                        <i class="far fa-download" aria-hidden="true"></i>
                        Bereitstellungspaket
                    </a>
                @else
                    <span class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-muted ring-1 ring-inset ring-rt-border/60 opacity-70 dark:text-rt-dark-muted dark:ring-rt-dark-border/60 sm:col-span-2">
                        <i class="far fa-lock" aria-hidden="true"></i>
                        Serverkonfiguration ausstehend
                    </span>
                @endif
            </div>
        </div>
    </div>
    </div>
</section>
