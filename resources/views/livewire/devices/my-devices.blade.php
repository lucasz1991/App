<x-ui.page
    title="Meine Geräte"
    eyebrow="Mein Bereich"
    description="Zugeordnete Firmen-Geräte, Einrichtungsstatus und sichere nächste Schritte."
    :count="$assignments->count()"
    page-key="my-devices"
>
    <div class="space-y-5" data-my-devices>
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-900"><i data-feather="shield" class="h-5 w-5"></i></span>
                <div>
                    <p class="font-semibold">Ihre Zugangsdaten bleiben bei Microsoft, Google und Apple</p>
                    <p class="mt-1 leading-6 text-sky-800 dark:text-sky-200/80">RailTime zeigt den Einrichtungsweg und den belegten Gerätestatus. Passwörter, MFA-Codes, Recovery Codes und private Schlüssel werden weder abgefragt noch gespeichert.</p>
                </div>
            </div>
        </div>

        @forelse($assignments as $assignment)
            @php
                $device = $assignment->device;
                $checks = $device->readinessChecks->keyBy('check_key');
                $required = array_keys(\App\Services\DeviceManagement\DeviceReadinessService::REQUIRED_CHECKS);
                $passed = collect($required)->filter(fn($key) => in_array($checks->get($key)?->status, ['passed','not_applicable'], true))->count();
                $latestEnrollment = $device->enrollments->first();
            @endphp
            <article class="overflow-hidden rounded-2xl border border-rt-border bg-white shadow-rt-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
                <div class="grid gap-5 p-5 lg:grid-cols-[auto_1fr_auto] lg:items-center">
                    <span class="grid h-16 w-16 place-items-center rounded-2xl bg-rt-red/10 text-rt-red"><i data-feather="{{ in_array($device->form_factor, ['phone','tablet']) ? 'smartphone' : 'monitor' }}" class="h-8 w-8"></i></span>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-bold text-rt-text dark:text-white">{{ $device->display_name ?: $device->hostname ?: 'Firmen-Gerät' }}</h2>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $passed === count($required) ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' }}">{{ $passed === count($required) ? 'Einsatzbereit' : $passed.'/'.count($required).' Voraussetzungen' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $device->manufacturer }} {{ $device->model }} · {{ $device->asset_tag ?: $device->serial_number }} · {{ match($device->platform->value) {'macos'=>'macOS','ios'=>'iOS','ipados'=>'iPadOS',default=>ucfirst($device->platform->value)} }}</p>
                        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                            <span class="inline-flex items-center gap-1.5"><i data-feather="map-pin" class="h-3.5 w-3.5"></i>{{ $device->declared_location ?: 'Standort nicht gemeldet' }}</span>
                            <span class="inline-flex items-center gap-1.5"><i data-feather="calendar" class="h-3.5 w-3.5"></i>Zugewiesen seit {{ $assignment->assigned_at?->format('d.m.Y') }}</span>
                            <span class="inline-flex items-center gap-1.5"><i data-feather="refresh-cw" class="h-3.5 w-3.5"></i>Sync {{ $device->last_synced_at?->diffForHumans() ?? 'noch nicht erfolgt' }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:flex-col">
                        @if($latestEnrollment)
                            <span class="inline-flex min-h-10 items-center justify-center rounded-xl border border-rt-border px-3 text-xs font-semibold text-rt-text dark:border-rt-dark-border dark:text-white">Einrichtung: {{ str_replace('_',' ',$latestEnrollment->status->value) }}</span>
                        @else
                            <span class="inline-flex min-h-10 items-center justify-center rounded-xl border border-amber-300 bg-amber-50 px-3 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">Einladung folgt durch IT</span>
                        @endif
                        <a href="{{ route('support') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-rt-red px-3 text-xs font-semibold text-white"><i data-feather="life-buoy" class="h-4 w-4"></i>Support anfordern</a>
                    </div>
                </div>

                <div class="border-t border-rt-border bg-rt-surface-muted/50 px-5 py-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40">
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                        @foreach(\App\Services\DeviceManagement\DeviceReadinessService::REQUIRED_CHECKS as $key => $label)
                            @php $status = $checks->get($key)?->status ?? 'unknown'; @endphp
                            <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-xs dark:bg-rt-dark-surface">
                                <span class="grid h-5 w-5 place-items-center rounded-full {{ in_array($status,['passed','not_applicable'],true) ? 'bg-emerald-100 text-emerald-700' : ($status === 'blocked' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}"><i data-feather="{{ in_array($status,['passed','not_applicable'],true) ? 'check' : ($status === 'blocked' ? 'x' : 'clock') }}" class="h-3 w-3"></i></span>
                                <span class="text-rt-text dark:text-white">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-rt-border bg-white p-10 text-center shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface">
                <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted"><i data-feather="package" class="h-6 w-6"></i></span>
                <h2 class="mt-4 text-lg font-bold text-rt-text dark:text-white">Noch kein Gerät zugewiesen</h2>
                <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">Sobald die Verwaltung Ihnen ein Gerät aus dem virtuellen Lager zuweist, erscheint es hier mit dem persönlichen Einrichtungsstatus.</p>
            </div>
        @endforelse

        <div class="rounded-2xl border border-rt-border bg-white p-4 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
            <p class="font-semibold text-rt-text dark:text-white">Standort und Privatsphäre</p>
            <p class="mt-1 leading-6 text-rt-muted dark:text-rt-dark-muted">Die Ansicht verwendet den gemeldeten Arbeits- oder Lagerstandort. Sie aktiviert keine permanente Live-Ortung Ihres Geräts. Bei einem BYOD-/Privatgerät beschränkt sich die Verwaltung auf den geschäftlichen Bereich.</p>
        </div>
    </div>
</x-ui.page>

