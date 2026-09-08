<x-ui.page title="Desktopclients" eyebrow="IT & Betrieb" description="Windows-Pilot: Geräte verbinden, lokale Berechtigungen vorbereiten und bestätigte Softwareaufträge verfolgen." page-key="device-desktop-clients">
    <x-slot:actions><a href="{{ route('devices.index') }}" class="rounded-xl border border-rt-border px-4 py-3 text-sm font-semibold">Zur Geräteübersicht</a></x-slot:actions>
    @php
        $field = 'mt-1 w-full rounded-xl border border-rt-border bg-white px-3 py-2 text-sm dark:border-rt-dark-border dark:bg-rt-dark-surface';
        $button = 'min-h-11 rounded-xl bg-rt-red px-4 py-2 text-sm font-semibold text-white disabled:opacity-50';
        $panel = 'rounded-xl border border-rt-border bg-white p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface';
        $statusLabels = ['unpaired' => 'Code ausgestellt', 'active' => 'Verbunden', 'revoked' => 'Widerrufen', 'queued' => 'Vorgemerkt', 'offered' => 'Angeboten / lokal unbestätigt', 'expired' => 'Abgelaufen / Ergebnis unbestätigt', 'cancelled' => 'Abgebrochen / laufender Prozess ggf. unbestätigt', 'succeeded' => 'Client meldet Erfolg', 'failed' => 'Client meldet Fehler', 'declined' => 'Lokal abgelehnt', 'unsupported' => 'Nicht unterstützt', 'requires_admin' => 'Erhöhte Rechte erforderlich'];
    @endphp
    <div class="space-y-4">
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/20 dark:text-amber-100">
            <strong>{{ $commandsEnabled ? 'Globaler Aktionsschalter freigegeben.' : 'Auftragsausgabe sicher blockiert.' }}</strong>
            Registrierung und Statusabgleich funktionieren unabhängig davon. Dies ist ein benutzerbestätigter Desktop-Pilot, kein vollständiges MDM, kein Fernsteuerungsdienst und keine automatische Microsoft-Installation. Der Administrator-Haupt-PC bleibt nur Verwaltungsstation.
        </div>
        @if($notice)<p role="status" class="rounded-xl border border-emerald-200 p-3 text-sm">{{ $notice }}</p>@endif
        @if($errors->any())<ul role="alert" class="rounded-xl border border-red-200 p-3 text-sm text-red-700">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif

        @can('devices.enrollment.manage')
            <details class="{{ $panel }}" @if($pairingToken) open @endif>
                <summary class="cursor-pointer text-sm font-semibold">Mitarbeiter-Endgerät verbinden</summary>
                <form wire:submit="issuePairing" class="mt-4 space-y-3">
                    <label class="block text-sm">Vorhandenes Windows-Gerät mit Mitarbeiterzuweisung
                        <select wire:model="devicePublicId" class="{{ $field }}"><option value="">Gerät auswählen</option>@foreach($devices as $device)<option value="{{ $device->public_id }}">{{ $device->display_name }} · {{ $device->activeAssignment?->user?->name ?? 'Zuweisung prüfen' }}</option>@endforeach</select>
                    </label>
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="endpointConfirmed" class="mt-1">Ich bestätige ein Mitarbeiter-Endgerät. Dies ist nicht der Administrator-Haupt-PC oder eine reine Verwaltungsstation.</label>
                    <button type="submit" wire:loading.attr="disabled" class="{{ $button }}">Einmalcode erstellen</button>
                    <p class="text-xs text-rt-muted">Der Mitarbeiter installiert den freigegebenen RailTime-Client selbst und gibt den Code dort ein. Die vorhandene Gerätezuweisung bleibt maßgeblich; eine Microsoft-Anmeldung allein installiert keinen Client.</p>
                </form>
                @if($pairingToken)
                    <div class="mt-4 rounded-xl border border-amber-300 p-3" data-desktop-pairing>
                        <label class="block text-sm font-semibold">Vertraulicher Einmalcode – gültig bis {{ $pairingExpiresAt }}<input readonly autocomplete="off" spellcheck="false" value="{{ $pairingToken }}" class="{{ $field }} font-mono" aria-label="Vertraulicher Einmalcode"></label>
                        <p class="mt-2 text-xs">Nur einmal nutzbar, 15 Minuten gültig; nach Schließen nicht erneut abrufbar. Kein Kontopasswort.</p>
                        <button type="button" wire:click="dismissPairing" class="mt-2 text-sm underline">Code ausblenden</button>
                    </div>
                @endif
            </details>
        @endcan

        <x-tables.table :columns="[['label'=>'Gerät','width'=>'35%'],['label'=>'Mitarbeiter','width'=>'30%'],['label'=>'Clientstatus','width'=>'35%']]" :items="$clients" :selected-items="$selected ? [$selected->id] : []" row-view="components.tables.rows.devices.desktop-client-row" empty="Noch keine Desktopclients verbunden. Die Geräte müssen zuerst im Inventar eindeutig einem aktiven Mitarbeiter zugewiesen sein." />

        @if($selected)
            <section class="{{ $panel }} space-y-4" aria-label="Ausgewählter Desktopclient">
                <div><h2 class="font-semibold">{{ $selected->device?->display_name ?? 'Gerät entfernt' }} · {{ $selected->assignment?->user?->name }}</h2><p class="mt-1 text-xs text-rt-muted">{{ $statusLabels[$selected->status] ?? $selected->status }} · Letzter Clientkontakt: {{ $selected->last_seen_at?->format('d.m.Y H:i:s') ?? 'noch keiner' }} · Richtlinie {{ $selected->policy_revision }}</p></div>
                @if($setupBlocked)
                    <p role="status" class="rounded-lg border border-amber-200 p-3 text-sm">Arbeitsbereich gesperrt: Nicht alle für diese Clientbindung vorgemerkten Softwareaufträge haben eine Erfolgsrückmeldung. Auch abgelaufene oder abgelehnte Aufträge bleiben offen. IT muss diese prüfen; im Pilot ist eine bewusste Neuregistrierung nach Widerruf nötig, wenn kein tatsächlicher Erfolgsnachweis mehr möglich ist.</p>
                @endif
                @if(in_array($selected->status, ['active','unpaired'], true))
                    @can('devices.manage')
                        <form wire:submit="savePolicy" class="space-y-3">
                            <h3 class="text-sm font-semibold">Angeforderte Clientberechtigungen</h3>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach(['allow_camera'=>'Kamera', 'allow_location'=>'Standort', 'allow_notifications'=>'Benachrichtigungen', 'allow_software_install'=>'Bestätigte Softwareinstallation', 'kiosk_enabled'=>'RailTime-Kioskbrowser'] as $key=>$label)
                                    <label class="flex gap-2 text-sm"><input type="checkbox" wire:model="policy.{{ $key }}">{{ $label }}</label>
                                @endforeach
                            </div>
                            <p class="text-xs text-rt-muted">Dies erteilt keine Betriebssystemrechte. Mitarbeiter können lokal ablehnen. Kamera/Standort werden hier nicht aufgezeichnet; der Kioskbrowser ist regulär schließbar, kein Windows-Lockdown.</p>
                            <button type="submit" wire:loading.attr="disabled" class="{{ $button }}">Richtlinie speichern</button>
                        </form>
                    @endcan
                    @if($selected->status === 'active')
                        @can('devices.commands.execute')
                            <div class="border-t border-rt-border pt-4">
                                <label class="block text-sm">Begründung für den nächsten Auftrag<textarea wire:model="justification" maxlength="1000" rows="2" class="{{ $field }}" placeholder="Mindestens 10 Zeichen; keine Geheimnisse oder personenbezogenen Problemdetails."></textarea></label>
                                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                                    <form wire:submit="queueSoftware" class="space-y-3">
                                        <h3 class="text-sm font-semibold">Software über WinGet</h3>
                                        <label class="block text-sm">Paket-ID<input wire:model="packageId" maxlength="128" class="{{ $field }}" placeholder="Hersteller.Paketname"></label>
                                        <label class="block text-sm">Exakte freigegebene Version<input wire:model="packageVersion" maxlength="40" class="{{ $field }}" placeholder="Beispielsweise 1.2.3"></label>
                                        <p class="text-xs text-rt-muted">Nur Installation im Benutzerkontext, keine automatische Rechteerhöhung. Paket und Version müssen im freigegebenen WinGet-Katalog vorhanden sein; lokale Bestätigung erforderlich.</p>
                                        <button type="submit" wire:loading.attr="disabled" class="{{ $button }}">Installation vormerken</button>
                                    </form>
                                    <form wire:submit="queueNotification" class="space-y-3">
                                        <h3 class="text-sm font-semibold">Clientmitteilung</h3>
                                        <label class="block text-sm">Titel<input wire:model="notificationTitle" maxlength="100" class="{{ $field }}"></label>
                                        <label class="block text-sm">Mitteilung<textarea wire:model="notificationBody" maxlength="500" rows="3" class="{{ $field }}"></textarea></label>
                                        <button type="submit" wire:loading.attr="disabled" class="{{ $button }}">Mitteilung vormerken</button>
                                    </form>
                                </div>
                            </div>
                        @endcan
                    @endif
                    @can('devices.enrollment.manage')
                        <form wire:submit="revoke" class="space-y-2 border-t border-rt-border pt-4">
                            <label class="flex gap-2 text-sm"><input type="checkbox" wire:model="revokeConfirmed">Clientzugang und offene Einladungen ausdrücklich widerrufen.</label>
                            <button type="submit" wire:loading.attr="disabled" class="min-h-11 rounded-xl border border-red-300 px-4 py-2 text-sm font-semibold text-red-700">Zugang widerrufen</button>
                        </form>
                    @endcan
                @endif
                <div class="border-t border-rt-border pt-4">
                    <h3 class="text-sm font-semibold">Letzte 20 Aufträge</h3>
                    <p class="mt-1 text-xs text-rt-muted">Erfolg bedeutet eine Client-Rückmeldung, keinen unabhängigen MDM-Nachweis. Nach Widerruf kann das Ergebnis eines bereits gestarteten lokalen Prozesses unbestätigt bleiben.</p>
                    <div class="mt-3 space-y-2">@forelse($jobs as $job)<div class="flex flex-wrap items-start justify-between gap-2 rounded-lg bg-rt-surface-muted p-3 text-sm dark:bg-rt-dark-surface-muted"><div>{{ $job->type === 'install_software' ? 'Softwareinstallation' : 'Mitteilung' }}<p class="text-xs text-rt-muted">{{ $job->public_id }} · {{ $job->created_at->format('d.m.Y H:i') }}</p></div><span>{{ $statusLabels[$job->status] ?? $job->status }}</span></div>@empty<p class="text-sm text-rt-muted">Noch keine Aufträge.</p>@endforelse</div>
                </div>
            </section>
        @endif
    </div>
</x-ui.page>
