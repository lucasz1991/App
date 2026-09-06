@php
    $lastRun = $connectionStatus['last_run'] ?? [];
    $diagnostic = $connectionStatus['diagnostic'] ?? [];
    $active = (bool) ($connectionStatus['enabled'] ?? false);
@endphp

<div class="min-w-0 space-y-4" wire:poll.visible.15s="$refresh" data-microsoft-device-settings>
    <div class="rounded-xl bg-rt-accent-soft/50 p-4 text-sm leading-6 text-rt-text ring-1 ring-rt-accent/15 dark:bg-rt-dark-accent-soft/35 dark:text-rt-dark-text">
        <p class="font-semibold">Windows-Geräte aus Microsoft Entra übernehmen</p>
        <p class="mt-1 text-rt-muted dark:text-rt-dark-muted">RailTime übernimmt bereits in Ihrem Tenant registrierte Windows-Geräte regelmäßig und ordnet sie eindeutig verknüpften Mitarbeitern zu. Eine verifizierte Microsoft-Anmeldung im RailTime-Outlook-Add-in kann den nächsten Abruf vorziehen. Eine reine Anmeldung an einer Website registriert den Laptop noch nicht in Entra.</p>
        <p class="mt-2 text-rt-muted dark:text-rt-dark-muted">Microsoft-Konten verknüpfen Sie über „Microsoft-Konto“ in der Geräteverwaltung mit Tenant- und Entra-Objekt-ID. Die E-Mail-Adresse allein reicht für die automatische Zuordnung nicht aus. Vorhandene manuelle Gerätezuweisungen bleiben geschützt.</p>
    </div>

    <form wire:submit="save" class="space-y-4">
        <div class="grid min-w-0 gap-4 lg:grid-cols-2">
            <div class="min-w-0">
                <x-ui.forms.label for="microsoft-device-tenant-id" value="Verzeichnis-ID (Tenant-ID)" />
                <x-ui.forms.input id="microsoft-device-tenant-id" type="text" wire:model="form.tenant_id" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" class="mt-1.5 font-mono" />
                <x-input-error for="form.tenant_id" class="mt-1.5" />
            </div>
            <div class="min-w-0">
                <x-ui.forms.label for="microsoft-device-client-id" value="Anwendungs-ID (Client-ID)" />
                <x-ui.forms.input id="microsoft-device-client-id" type="text" wire:model="form.client_id" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" class="mt-1.5 font-mono" />
                <x-input-error for="form.client_id" class="mt-1.5" />
            </div>
            <div class="min-w-0">
                <x-ui.forms.label for="microsoft-device-client-secret" value="Client-Geheimnis (Wert)" />
                <x-ui.forms.input id="microsoft-device-client-secret" type="password" wire:model="form.client_secret" autocomplete="new-password" spellcheck="false" class="mt-1.5" aria-describedby="microsoft-device-secret-hint" />
                <x-input-error for="form.client_secret" class="mt-1.5" />
                <p id="microsoft-device-secret-hint" class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ ($form['secret_configured'] ?? false) ? 'Ein Geheimnis ist verschlüsselt gespeichert. Leer oder unverändert lassen, um es zu behalten.' : 'Tragen Sie den geheimen Wert der App-Registrierung ein, nicht dessen Geheimnis-ID.' }} Bei Änderung von Tenant oder Anwendung wird das alte Geheimnis entfernt, sofern Sie kein neues eintragen.</p>
                @if ($form['secret_configured'] ?? false)
                    <label class="mt-2 flex min-h-11 items-center gap-2 text-sm text-rt-muted dark:text-rt-dark-muted">
                        <input type="checkbox" wire:model="form.clear_client_secret" class="h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface" />
                        Gespeichertes Geheimnis entfernen und Verbindung deaktivieren
                    </label>
                @endif
            </div>
            <div class="min-w-0">
                <x-ui.forms.label for="microsoft-device-sync-interval" value="Automatischer Abruf alle … Minuten" />
                <x-ui.forms.input id="microsoft-device-sync-interval" type="number" min="5" max="1440" step="1" wire:model="form.sync_interval_minutes" class="mt-1.5" />
                <x-input-error for="form.sync_interval_minutes" class="mt-1.5" />
                <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">Standard: 15 Minuten. Der Laravel-Scheduler und der separate Microsoft-Geräte-Worker müssen laufen.</p>
            </div>
        </div>

        <div class="grid min-w-0 gap-2 sm:grid-cols-2">
            @foreach ([
                'enabled' => ['Microsoft-Gerätesynchronisierung aktivieren', 'Inventar aus dem gespeicherten Tenant regelmäßig abrufen.'],
                'auto_assign' => ['Mitarbeiter automatisch zuordnen', 'Nur bei eindeutig verknüpfter Microsoft-Identität und eindeutigem Gerätebenutzer.'],
                'sync_on_sign_in' => ['Nach Microsoft-Anmeldung aktualisieren', 'Verifizierte Anmeldung im RailTime-Outlook-Add-in stößt einen asynchronen Abruf an.'],
                'intune_enabled' => ['Intune-Gerätedaten ergänzen', 'Optional: Seriennummer, letzter Kontakt und Verwaltungsstatus aus Intune übernehmen.'],
            ] as $key => [$label, $hint])
                <label class="flex cursor-pointer items-start gap-3 rounded-xl bg-rt-surface px-3 py-3 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70">
                    <input type="checkbox" wire:model="form.{{ $key }}" class="mt-1 h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface" />
                    <span class="min-w-0"><span class="block text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $label }}</span><span class="mt-1 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $hint }}</span></span>
                </label>
            @endforeach
        </div>
        <x-input-error for="form" />
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.buttons.button-basic mode="primary" type="submit" class="min-h-11" wire:loading.attr="disabled" wire:target="save,testConnection,syncNow"><i class="far fa-save" aria-hidden="true"></i>Microsoft-Einstellungen speichern</x-ui.buttons.button-basic>
            <span class="text-xs text-rt-muted dark:text-rt-dark-muted">Gilt nach dem Speichern. Zusätzliche ENV-Variablen sind nicht erforderlich.</span>
        </div>
    </form>

    <div class="space-y-3 rounded-xl bg-rt-surface p-4 ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-live="polite">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">Verbindung und Synchronisierung</h3>
                <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $active ? 'Automatischer Abruf aktiviert' : 'Automatischer Abruf deaktiviert' }} · Tests und Abrufe verwenden die gespeicherte Konfiguration.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.buttons.button-basic type="button" mode="secondary" class="min-h-11" wire:click="testConnection" wire:loading.attr="disabled" wire:target="save,testConnection,syncNow"><i class="far fa-plug" aria-hidden="true"></i>Verbindung testen</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic type="button" mode="secondary" class="min-h-11" wire:click="syncNow" wire:loading.attr="disabled" wire:target="save,testConnection,syncNow"><i class="far fa-sync" aria-hidden="true"></i>Jetzt synchronisieren</x-ui.buttons.button-basic>
            </div>
        </div>
        <x-input-error for="connection" />
        <p wire:loading wire:target="testConnection,syncNow,save" class="text-sm text-rt-muted dark:text-rt-dark-muted">Die Anfrage wird verarbeitet …</p>
        @if ($diagnostic !== [])
            <p class="text-sm text-rt-text dark:text-rt-dark-text"><span class="font-medium">Verbindungstest:</span> {{ $diagnostic['message'] }} @if ($connectionStatus['last_diagnostic_at'])<span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ \Illuminate\Support\Carbon::parse($connectionStatus['last_diagnostic_at'])->timezone(config('app.timezone'))->format('d.m.Y. H:i') }}</span>@endif</p>
            @if (isset($diagnostic['intune_message']) && $diagnostic['intune_status'] !== 'success')
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted"><span class="font-medium">Intune:</span> {{ $diagnostic['intune_message'] }}</p>
            @endif
        @endif
        @if ($lastRun !== [])
            <p class="text-sm text-rt-text dark:text-rt-dark-text"><span class="font-medium">Letzter Abruf:</span> {{ $lastRun['message'] }} @if ($connectionStatus['last_sync_at'])<span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ \Illuminate\Support\Carbon::parse($connectionStatus['last_sync_at'])->timezone(config('app.timezone'))->format('d.m.Y. H:i') }}</span>@endif</p>
            @if (isset($lastRun['intune_message']) && $lastRun['intune_status'] !== 'success')
                <p class="text-sm text-rt-muted dark:text-rt-dark-muted"><span class="font-medium">Intune:</span> {{ $lastRun['intune_message'] }}</p>
            @endif
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach (['discovered' => 'Gefunden', 'created' => 'Neu erfasst', 'updated' => 'Aktualisiert', 'assigned' => 'Zugeordnet', 'conflicts' => 'Zu prüfen', 'skipped' => 'Übersprungen'] as $key => $label)
                    @if (array_key_exists($key, $lastRun))
                        <div class="rounded-lg bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted"><dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-rt-text dark:text-rt-dark-text">{{ $lastRun[$key] }}</dd></div>
                    @endif
                @endforeach
            </dl>
        @else
            <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Für diese Konfiguration wurde noch kein Geräteabruf abgeschlossen.</p>
        @endif
    </div>

    <details class="rounded-xl bg-rt-surface p-4 text-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70">
        <summary class="cursor-pointer font-semibold text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent dark:text-rt-dark-text">Microsoft-Einrichtung und benötigte Rechte</summary>
        <div class="mt-3 space-y-3 text-rt-muted dark:text-rt-dark-muted">
            <p>Erstellen Sie in Entra eine App-Registrierung für den serverseitigen Geräteabruf. Erteilen Sie Microsoft Graph das Anwendungsrecht <code class="break-all">Device.Read.All</code> und die organisationsweite Administratorzustimmung. Für die optionale Intune-Ergänzung ist zusätzlich <code class="break-all">DeviceManagementManagedDevices.Read.All</code> sowie ein passend lizenzierter Intune-Tenant erforderlich.</p>
            <p>Windows-Geräte müssen dem Unternehmen in Entra bereits beigetreten, hybrid beigetreten oder registriert sein. Microsoft-365-Postfach oder Office-Anmeldung allein garantieren keinen Geräteeintrag. Entra liefert das Inventar; Fernwartung und Gerätebefehle benötigen weiterhin eine eingerichtete Verwaltung beziehungsweise den Remote-Support-Agenten.</p>
            <p>Der Abruf liest Microsoft-Daten. Er legt keine Microsoft-Konten an, liest keine E-Mails und verändert keine Tenant- oder Geräterichtlinien. Microsoft Graph wird direkt per HTTPS angesprochen; hierfür ist kein weiterer Connector-Port oder eine Plesk-Subdomain nötig.</p>
            <p>In Plesk muss der Laravel-Scheduler jede Minute laufen. Starten Sie den eigenen Datenbank-Worker mit <code class="break-all">php artisan queue:work microsoft_devices --queue=microsoft-devices --timeout=240 --tries=1</code>. Die Microsoft-Warteschlange ist vorkonfiguriert und benötigt keine zusätzlichen ENV-Variablen.</p>
            <div class="flex flex-wrap gap-x-4 gap-y-2 pt-1">
                <a href="https://entra.microsoft.com/" target="_blank" rel="noopener noreferrer" class="text-rt-accent underline decoration-rt-accent/30 underline-offset-4 dark:text-rt-dark-accent">Microsoft Entra Admin Center <i class="far fa-external-link text-xs" aria-hidden="true"></i></a>
                <a href="https://admin.microsoft.com/" target="_blank" rel="noopener noreferrer" class="text-rt-accent underline decoration-rt-accent/30 underline-offset-4 dark:text-rt-dark-accent">Microsoft 365 Admin Center <i class="far fa-external-link text-xs" aria-hidden="true"></i></a>
                <a href="https://intune.microsoft.com/" target="_blank" rel="noopener noreferrer" class="text-rt-accent underline decoration-rt-accent/30 underline-offset-4 dark:text-rt-dark-accent">Microsoft Intune Admin Center <i class="far fa-external-link text-xs" aria-hidden="true"></i></a>
            </div>
        </div>
    </details>
</div>
