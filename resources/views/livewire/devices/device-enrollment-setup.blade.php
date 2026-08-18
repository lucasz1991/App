<x-ui.page
    title="Mein Gerät einrichten"
    eyebrow="Sichere Registrierung"
    :description="'Persönliche Einrichtung für '.($enrollment->device->display_name ?: $enrollment->device->hostname ?: 'Ihr Firmen-Gerät')"
    page-key="device-enrollment-setup"
>
    @php $device = $enrollment->device; @endphp
    <div class="space-y-5" data-device-enrollment>
        <div class="rounded-2xl border border-rt-border bg-white p-5 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-rt-red/10 text-rt-red"><i data-feather="{{ in_array($device->form_factor,['phone','tablet']) ? 'smartphone' : 'monitor' }}" class="h-7 w-7"></i></span>
                    <div>
                        <h2 class="text-xl font-bold text-rt-text dark:text-white">{{ $device->display_name ?: $device->hostname }}</h2>
                        <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $device->manufacturer }} {{ $device->model }} · {{ $device->asset_tag ?: $device->serial_number }}</p>
                        <p class="mt-2 inline-flex items-center gap-1.5 text-xs text-rt-muted dark:text-rt-dark-muted"><i data-feather="clock" class="h-3.5 w-3.5"></i>Einladung gültig bis {{ $enrollment->expires_at?->format('d.m.Y, H:i') }} Uhr</p>
                    </div>
                </div>
                <span class="rounded-full bg-sky-100 px-3 py-1.5 text-xs font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-300">{{ $enrollment->provider }} · {{ str_replace('_',' ',$enrollment->mode) }}</span>
            </div>
        </div>

        @if($limitedManagement)
            <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-200">
                <p class="font-semibold">Bestehendes Gerät: eingeschränkte Verwaltung ohne sofortigen Reset</p>
                <p class="mt-1 leading-6">Ihr geschäftlicher Bereich kann jetzt registriert werden. Vollständiger Firmenmodus beziehungsweise Apple-Supervision wird bei einem später geplanten Austausch oder Zurücksetzen eingerichtet; private Daten bleiben bis dahin außerhalb des verwalteten Bereichs.</p>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-[1fr_0.7fr]">
            <section class="rounded-2xl border border-rt-border bg-white p-5 shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface" aria-labelledby="enrollment-steps-title">
                <h2 id="enrollment-steps-title" class="text-lg font-bold text-rt-text dark:text-white">Einrichtungsschritte</h2>
                @if($providerMessage)<p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $providerMessage }}</p>@endif
                <ol class="mt-5 space-y-4">
                    @foreach($steps as $step)
                        <li class="flex gap-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-rt-red text-sm font-bold text-white">{{ $loop->iteration }}</span>
                            <p class="pt-1 text-sm leading-6 text-rt-text dark:text-white">{{ $step }}</p>
                        </li>
                    @endforeach
                </ol>

                @if($enrollmentUrl)
                    <a href="{{ $enrollmentUrl }}" target="_blank" rel="noopener noreferrer" class="mt-6 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-5 text-sm font-semibold text-white shadow-rt-sm hover:bg-rt-red-dark">
                        <i data-feather="external-link" class="h-4 w-4"></i>
                        Offizielle Geräte-Einrichtung öffnen
                    </a>
                @else
                    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">Der technische Provider hat noch keinen sicheren Einrichtungslink geliefert. Bitte starten Sie nichts manuell und wenden Sie sich an den IT-Support.</div>
                @endif
            </section>

            <aside class="space-y-4">
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
                    <div class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900"><i data-feather="lock" class="h-4 w-4"></i></span><div><p class="font-semibold">Passwortschutz</p><p class="mt-1 leading-6">Geben Sie Microsoft-, Google- oder Apple-Zugangsdaten nur im offiziellen Fenster des jeweiligen Anbieters ein. RailTime fragt nicht danach.</p></div></div>
                </div>
                <div class="rounded-2xl border border-rt-border bg-white p-4 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
                    <p class="font-semibold text-rt-text dark:text-white">Was danach passiert</p>
                    <ul class="mt-3 space-y-2 text-rt-muted dark:text-rt-dark-muted">
                        <li class="flex gap-2"><i data-feather="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"></i>Provider meldet Enrollment und Pflichtprofile zurück.</li>
                        <li class="flex gap-2"><i data-feather="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"></i>Apps und Konto-Adresse werden vorbereitet.</li>
                        <li class="flex gap-2"><i data-feather="user-check" class="mt-0.5 h-4 w-4 shrink-0 text-sky-600"></i>Sie bestätigen einmal OAuth/SSO/MFA.</li>
                        <li class="flex gap-2"><i data-feather="shield" class="mt-0.5 h-4 w-4 shrink-0 text-sky-600"></i>„Einsatzbereit“ erscheint erst nach echten Belegen.</li>
                    </ul>
                </div>
                <a href="{{ route('support') }}" wire:navigate class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-rt-border bg-white px-4 text-sm font-semibold text-rt-text hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:hover:bg-rt-dark-surface-muted"><i data-feather="life-buoy" class="h-4 w-4"></i>IT-Support öffnen</a>
            </aside>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-rt-border bg-white p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface">
            <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Den belegten Fortschritt sehen Sie anschließend unter „Meine Geräte“.</p>
            <a href="{{ route('devices.mine') }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white dark:bg-white dark:text-slate-950">Zu meinen Geräten<i data-feather="arrow-right" class="h-4 w-4"></i></a>
        </div>
    </div>
</x-ui.page>

