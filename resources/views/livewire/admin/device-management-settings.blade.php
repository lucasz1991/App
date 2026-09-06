@php
    $isGerman = app()->getLocale() === 'de';
    $mode = (string) ($deployment['mode'] ?? 'subdomain');
    $commandsEnabled = (bool) ($runtime['production_commands_enabled'] ?? false);
    $providerIcons = [
        'openuem' => 'fad fa-laptop',
        'meshcentral' => 'fad fa-headset',
        'headwind' => 'fab fa-android',
        'nanomdm' => 'fab fa-apple',
        'identity' => 'fad fa-id-badge',
    ];
    $diagnosticStatusLabels = $isGerman ? [
        'healthy' => 'Verbindung und Connector-Vertrag sind bereit.',
        'unauthorized' => 'Der Connector hat die Anmeldung abgelehnt.',
        'unreachable' => 'Der Connector ist nicht erreichbar.',
        'invalid_configuration' => 'Die gespeicherte Connector-Konfiguration ist ungültig.',
        'missing_credentials' => 'Das Zugriffstoken fehlt.',
        'redirect_rejected' => 'Der Connector antwortet mit einer nicht erlaubten Weiterleitung.',
        'http_error' => 'Der Connector antwortet mit einem HTTP-Fehler.',
        'response_too_large' => 'Die Health-Antwort überschreitet die erlaubte Größe.',
        'invalid_response' => 'Der Connector liefert keine gültige Health-Antwort.',
        'contract_incompatible' => 'Die Antwort entspricht nicht dem RailTime-Connectorvertrag.',
        'contract_invalid' => 'Die Health-Antwort verletzt den RailTime-Connectorvertrag.',
        'provider_mismatch' => 'Der Connector meldet einen anderen Provider als erwartet.',
        'invalid_provider' => 'Der angeforderte Geräteprovider ist nicht zulässig.',
        'unhealthy' => 'Der Connector meldet sich als nicht betriebsbereit.',
        'probe_failed' => 'Der Verbindungstest konnte nicht abgeschlossen werden.',
    ] : [
        'healthy' => 'Connection and connector contract are ready.',
        'unauthorized' => 'The connector rejected authentication.',
        'unreachable' => 'The connector is unreachable.',
        'invalid_configuration' => 'The saved connector configuration is invalid.',
        'missing_credentials' => 'The access token is missing.',
        'redirect_rejected' => 'The connector returned a disallowed redirect.',
        'http_error' => 'The connector returned an HTTP error.',
        'response_too_large' => 'The health response exceeds the allowed size.',
        'invalid_response' => 'The connector returned an invalid health response.',
        'contract_incompatible' => 'The response does not match the RailTime connector contract.',
        'contract_invalid' => 'The health response violates the RailTime connector contract.',
        'provider_mismatch' => 'The connector reported a different provider than expected.',
        'invalid_provider' => 'The requested device provider is not allowed.',
        'unhealthy' => 'The connector reports that it is not ready.',
        'probe_failed' => 'The connection test could not be completed.',
    ];
@endphp

<div
    x-data="{
        openAccordionSection: 'device-deployment',
        toggleAccordionSection(section) {
            this.openAccordionSection = this.openAccordionSection === section ? null : section;
        },
    }"
    x-on:rt-settings-open-section.window="
        if ($event.detail.tab === 'device-management' && $event.detail.section) {
            openAccordionSection = $event.detail.section;
        }
    "
    class="flex min-w-0 flex-col gap-3"
    data-device-management-settings
    data-admin-device-settings-accordion
    wire:ignore.self
>
    <div
        class="flex items-start gap-3 rounded-2xl border-l-4 border-rt-accent bg-rt-accent-soft/40 px-4 py-3 text-sm text-rt-text shadow-rt-sm ring-1 ring-rt-accent/15 dark:bg-rt-dark-accent-soft/25 dark:text-rt-dark-text dark:ring-rt-dark-accent/20"
        data-anim="fade-up"
    >
        <i class="fad fa-shield-check mt-0.5 fa-lg shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
        <div class="min-w-0">
            <p class="font-semibold">
                {{ $isGerman ? 'Nur für den RailTime-Superadmin' : 'RailTime super administrator only' }}
            </p>
            <p class="mt-0.5 leading-5 text-rt-muted dark:text-rt-dark-muted">
                {{ $isGerman
                    ? 'Die Werte liegen verschlüsselt in RailTime statt in der .env; Zugangsdaten werden hier nur maskiert dargestellt. Verbindungstests lesen ausschließlich den Health-Endpunkt und führen keine Geräteaktion aus.'
                    : 'Values are stored encrypted in RailTime instead of .env; credentials are only shown as masks. Connection tests only read the health endpoint and never execute a device action.' }}
            </p>
        </div>
    </div>

    <x-admin.settings-accordion-section
        section="device-microsoft"
        :label="$isGerman ? 'Microsoft Entra und Windows-Geräte' : 'Microsoft Entra and Windows devices'"
        :description="$isGerman ? 'Vorhandene Windows-Geräte regelmäßig aus Microsoft übernehmen und Mitarbeitern zuordnen.' : 'Regularly import existing Windows devices from Microsoft and link employees.'"
        icon="fab fa-microsoft"
    >
        <livewire:admin.microsoft-device-settings />
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-deployment"
        :label="$isGerman ? 'Plesk-Adresse und Betriebswerte' : 'Plesk address and runtime'"
        :description="$isGerman ? 'Öffentliche Subdomains oder private lokale Adapter-Ports mit sicheren Standardwerten festlegen.' : 'Choose public subdomains or private local adapter ports with safe defaults.'"
        icon="fad fa-server"
        data-anim="fade-up"
        data-anim-delay="0.04"
    >
        <section class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5">
            <div class="space-y-5 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6">
                <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                    <fieldset class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <legend class="px-1 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                            {{ $isGerman ? 'Adressmodus' : 'Address mode' }}
                        </legend>
                        <div class="mt-2 space-y-2">
                            @foreach ([
                                'subdomain' => [
                                    'label' => $isGerman ? 'Plesk-Subdomains (empfohlen)' : 'Plesk subdomains (recommended)',
                                    'hint' => $isGerman ? 'Öffentliche Connector-Adressen über HTTPS und Port 443.' : 'Public connector addresses over HTTPS and port 443.',
                                ],
                                'port' => [
                                    'label' => $isGerman ? 'Private Adapter-Ports' : 'Private adapter ports',
                                    'hint' => $isGerman ? 'RailTime spricht die Adapter nur serverintern über http://127.0.0.1 an.' : 'RailTime reaches adapters server-side only through http://127.0.0.1.',
                                ],
                            ] as $value => $option)
                                <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl bg-rt-surface px-3 py-2.5 ring-1 ring-rt-border/60 transition hover:ring-rt-accent/40 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                                    <input
                                        type="radio"
                                        value="{{ $value }}"
                                        wire:model.live="deployment.mode"
                                        class="mt-1 h-4 w-4 border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface-muted"
                                    >
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $option['label'] }}</span>
                                        <span class="mt-0.5 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $option['hint'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error for="deployment.mode" class="mt-2" />
                    </fieldset>

                    <div class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <x-ui.forms.label for="device-base-domain" :value="$isGerman ? 'Gemeinsame Basisdomain' : 'Shared base domain'" />
                        <x-ui.forms.input
                            id="device-base-domain"
                            type="text"
                            inputmode="url"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            placeholder="rail-time.de"
                            wire:model="deployment.base_domain"
                            aria-describedby="device-base-domain-hint"
                            class="mt-1.5 font-mono"
                        />
                        <x-input-error for="deployment.base_domain" class="mt-1.5" />
                        <p id="device-base-domain-hint" class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $isGerman
                                ? 'Nur eine öffentliche FQDN ohne https://, Port oder Pfad eintragen. Im Produktivbetrieb sind localhost sowie private oder reservierte Domains gesperrt.'
                                : 'Enter only a public FQDN without https://, port or path. Production blocks localhost and private or reserved domains.' }}
                        </p>
                    </div>
                </div>

                <div class="flex items-start gap-2.5 rounded-xl bg-amber-50/80 p-3.5 text-xs leading-5 text-amber-950 ring-1 ring-amber-200/80 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                    <i class="fad fa-key mt-0.5 shrink-0" aria-hidden="true"></i>
                    <p>
                        {{ $isGerman
                            ? 'Eine Änderung von Adressmodus oder Basisdomain deaktiviert aktive externe Provider und entfernt gespeicherte Zugangsdaten auch bei derzeit inaktiven externen Providern. Trage Token und Webhook-Geheimnis danach bewusst neu in den Provider-Karten ein.'
                            : 'Changing the address mode or base domain disables active external providers and removes saved credentials even from currently inactive external providers. Re-enter tokens and webhook secrets deliberately in the provider cards afterwards.' }}
                    </p>
                </div>

                <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="min-w-0">
                        <x-ui.forms.label for="device-enrollment-ttl" :value="$isGerman ? 'Einrichtungslink gültig' : 'Enrollment link lifetime'" />
                        <x-ui.forms.number-input
                            id="device-enrollment-ttl"
                            :min="15"
                            :max="10080"
                            step="15"
                            :decimals="0"
                            separator="."
                            :unit="$isGerman ? 'Minuten' : 'minutes'"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="runtime.enrollment_ttl_minutes"
                        />
                        <x-input-error for="runtime.enrollment_ttl_minutes" class="mt-1" />
                    </div>
                    <div class="min-w-0">
                        <x-ui.forms.label for="device-max-sync-age" :value="$isGerman ? 'Maximales Synchronisierungsalter' : 'Maximum sync age'" />
                        <x-ui.forms.number-input
                            id="device-max-sync-age"
                            :min="1"
                            :max="720"
                            step="1"
                            :decimals="0"
                            separator="."
                            :unit="$isGerman ? 'Stunden' : 'hours'"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="runtime.max_sync_age_hours"
                        />
                        <x-input-error for="runtime.max_sync_age_hours" class="mt-1" />
                    </div>
                    <div class="min-w-0">
                        <x-ui.forms.label for="device-artifact-limit" :value="$isGerman ? 'Maximale Datei- und Skriptgröße' : 'Maximum file and script size'" />
                        <x-ui.forms.number-input
                            id="device-artifact-limit"
                            :min="1024"
                            :max="1048576"
                            step="1024"
                            :decimals="0"
                            separator="."
                            unit="KiB"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="runtime.artifact_max_kilobytes"
                        />
                        <x-input-error for="runtime.artifact_max_kilobytes" class="mt-1" />
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-rt-border/60 pt-4 dark:border-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $isGerman ? 'Das Speichern ändert keine Plesk-Konfiguration und startet keinen externen Dienst.' : 'Saving does not change Plesk configuration or start an external service.' }}
                        </p>
                        <x-input-error for="deployment" class="mt-1.5" />
                    </div>
                    <button
                        type="button"
                        wire:click="saveDeployment"
                        wire:loading.attr="disabled"
                        wire:target="saveDeployment"
                        class="rt-ui-button rt-ui-button-primary min-h-11 w-full shrink-0 sm:w-auto"
                    >
                        <i class="fad fa-save" aria-hidden="true"></i>
                        <span wire:loading.remove wire:target="saveDeployment">{{ $isGerman ? 'Betriebswerte speichern' : 'Save runtime settings' }}</span>
                        <span wire:loading wire:target="saveDeployment">{{ __('app.loading') }}</span>
                    </button>
                </div>
            </div>
        </section>
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-providers"
        :label="$isGerman ? 'Geräteprovider und Funktionstests' : 'Device providers and connection tests'"
        :description="$isGerman ? 'RailTime-Connectoren einrichten und ihren gespeicherten, rein lesenden Health-Endpunkt prüfen.' : 'Configure RailTime connectors and probe their saved read-only health endpoint.'"
        icon="fad fa-network-wired"
        data-anim="fade-up"
        data-anim-delay="0.08"
    >
        <div class="grid min-w-0 gap-4 xl:grid-cols-2">
            @foreach ($providers as $providerKey => $provider)
                @php
                    $result = (array) ($diagnostics[$providerKey] ?? []);
                    $hasResult = $result !== [];
                    $healthy = (bool) ($result['healthy'] ?? false);
                    $diagnosticStatus = (string) ($result['status'] ?? 'probe_failed');
                    $diagnosticStatusLabel = $diagnosticStatusLabels[$diagnosticStatus]
                        ?? ($isGerman ? 'Der Verbindungstest lieferte keinen bekannten Status.' : 'The connection test returned an unknown status.');
                @endphp
                <section
                    wire:key="device-provider-settings-{{ $providerKey }}"
                    class="min-w-0 overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                    aria-labelledby="device-provider-{{ $providerKey }}-title"
                    data-device-provider-card="{{ $providerKey }}"
                >
                    <div class="flex min-w-0 flex-col gap-4 border-b border-rt-border/60 bg-rt-surface-muted/70 p-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted/55 sm:flex-row sm:items-start sm:justify-between sm:p-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="{{ $providerIcons[$providerKey] ?? 'fad fa-plug' }} fa-lg" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <h3 id="device-provider-{{ $providerKey }}-title" class="break-words text-base font-semibold text-rt-text dark:text-rt-dark-text">
                                    {{ $provider['label'] ?? $providerKey }}
                                </h3>
                                <p class="mt-0.5 font-mono text-xs text-rt-muted dark:text-rt-dark-muted">{{ $providerKey }}</p>
                            </div>
                        </div>
                        <x-ui.forms.toggle-button
                            :id="'device-provider-'.$providerKey.'-enabled'"
                            :model="'providers.'.$providerKey.'.enabled'"
                            :label="$isGerman ? 'Aktiviert' : 'Enabled'"
                            class="shrink-0"
                        />
                    </div>

                    <div class="space-y-5 p-4 sm:p-5">
                        <div class="rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <p class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">
                                {{ $isGerman ? 'Aktuell wirksame Adressen' : 'Currently effective addresses' }}
                            </p>
                            <dl class="mt-2 space-y-2 text-xs">
                                <div>
                                    <dt class="font-semibold text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'RailTime → Adapter' : 'RailTime → adapter' }}</dt>
                                    <dd class="mt-0.5 break-all font-mono text-rt-text dark:text-rt-dark-text">{{ $provider['effective_url'] ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-rt-muted dark:text-rt-dark-muted">{{ $isGerman ? 'Öffentliche Adresse' : 'Public address' }}</dt>
                                    <dd class="mt-0.5 break-all font-mono text-rt-text dark:text-rt-dark-text">{{ $provider['public_url'] ?: '—' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-subdomain'" :value="$isGerman ? 'Subdomain-Präfix' : 'Subdomain prefix'" />
                                <x-ui.forms.input
                                    :id="'device-provider-'.$providerKey.'-subdomain'"
                                    type="text"
                                    autocomplete="off"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    wire:model="providers.{{ $providerKey }}.subdomain"
                                    class="mt-1.5 font-mono"
                                />
                                <x-input-error :for="'providers.'.$providerKey.'.subdomain'" class="mt-1.5" />
                            </div>
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-port'" :value="$isGerman ? 'Privater Adapter-Port' : 'Private adapter port'" />
                                <x-ui.forms.number-input
                                    :id="'device-provider-'.$providerKey.'-port'"
                                    :min="1024"
                                    :max="65535"
                                    step="1"
                                    :decimals="0"
                                    separator="."
                                    :nullable="false"
                                    class="mt-1 w-full"
                                    wire:model="providers.{{ $providerKey }}.adapter_port"
                                />
                                <x-input-error :for="'providers.'.$providerKey.'.adapter_port'" class="mt-1" />
                            </div>
                        </div>

                        <p class="rounded-lg bg-amber-50/70 px-3 py-2 text-xs leading-5 text-amber-900 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                            {{ $isGerman
                                ? 'Bei einer Änderung des aktuell verwendeten Ziels kannst du Token und Webhook-Geheimnis gemeinsam neu eingeben. Ohne vollständige Neueingabe beider Werte deaktiviert RailTime den Provider und entfernt beide Zugangsdaten sicher. Das inaktive Fallback-Feld löst keine Neu-Anmeldung aus.'
                                : 'When changing the currently used target, you can re-enter the token and webhook secret together. Without a complete re-entry of both values, RailTime disables the provider and safely removes both credentials. Changing the inactive fallback field does not require re-authentication.' }}
                        </p>

                        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-token'" :value="$isGerman ? 'Connector-Zugriffstoken' : 'Connector access token'" />
                                <x-ui.forms.input
                                    :id="'device-provider-'.$providerKey.'-token'"
                                    type="password"
                                    autocomplete="new-password"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    :placeholder="($provider['token_configured'] ?? false) ? ($isGerman ? 'Gespeichert – zum Ersetzen neu eingeben' : 'Stored — enter a new value to replace') : ($isGerman ? 'Mindestens 32 Zeichen' : 'At least 32 characters')"
                                    wire:model="providers.{{ $providerKey }}.token"
                                    class="mt-1.5 font-mono"
                                />
                                <x-input-error :for="'providers.'.$providerKey.'.token'" class="mt-1.5" />
                                @if ($provider['token_configured'] ?? false)
                                    <label class="mt-2 flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg px-2 text-xs font-medium text-rt-muted transition hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted">
                                        <input
                                            type="checkbox"
                                            wire:model="providers.{{ $providerKey }}.clear_token"
                                            class="h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface-muted"
                                        >
                                        <span>{{ $isGerman ? 'Gespeichertes Token beim Speichern entfernen' : 'Remove the saved token when saving' }}</span>
                                    </label>
                                @endif
                            </div>
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-webhook'" :value="$isGerman ? 'Webhook-Geheimnis' : 'Webhook secret'" />
                                <x-ui.forms.input
                                    :id="'device-provider-'.$providerKey.'-webhook'"
                                    type="password"
                                    autocomplete="new-password"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    :placeholder="($provider['webhook_secret_configured'] ?? false) ? ($isGerman ? 'Gespeichert – zum Ersetzen neu eingeben' : 'Stored — enter a new value to replace') : ($isGerman ? 'Mindestens 32 Zeichen' : 'At least 32 characters')"
                                    wire:model="providers.{{ $providerKey }}.webhook_secret"
                                    class="mt-1.5 font-mono"
                                />
                                <x-input-error :for="'providers.'.$providerKey.'.webhook_secret'" class="mt-1.5" />
                                @if ($provider['webhook_secret_configured'] ?? false)
                                    <label class="mt-2 flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg px-2 text-xs font-medium text-rt-muted transition hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted">
                                        <input
                                            type="checkbox"
                                            wire:model="providers.{{ $providerKey }}.clear_webhook_secret"
                                            class="h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface-muted"
                                        >
                                        <span>{{ $isGerman ? 'Gespeichertes Webhook-Geheimnis beim Speichern entfernen' : 'Remove the saved webhook secret when saving' }}</span>
                                    </label>
                                @endif
                            </div>
                        </div>

                        <details class="rounded-xl bg-rt-surface-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3.5 py-2.5 text-sm font-semibold text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/45 dark:text-rt-dark-text">
                                <span>{{ $isGerman ? 'Erweiterte Verbindung' : 'Advanced connection' }}</span>
                                <i class="fad fa-chevron-down text-xs text-rt-muted" aria-hidden="true"></i>
                            </summary>
                            <div class="grid min-w-0 gap-4 border-t border-rt-border/60 p-3.5 dark:border-rt-dark-border/60 sm:grid-cols-2">
                                <div class="min-w-0">
                                    <x-ui.forms.label :for="'device-provider-'.$providerKey.'-timeout'" :value="$isGerman ? 'Zeitlimit' : 'Timeout'" />
                                    <x-ui.forms.number-input
                                        :id="'device-provider-'.$providerKey.'-timeout'"
                                        :min="1"
                                        :max="30"
                                        step="1"
                                        :decimals="0"
                                        separator="."
                                        :unit="$isGerman ? 'Sekunden' : 'seconds'"
                                        :nullable="false"
                                        class="mt-1 w-full"
                                        wire:model="providers.{{ $providerKey }}.timeout"
                                    />
                                    <x-input-error :for="'providers.'.$providerKey.'.timeout'" class="mt-1" />
                                </div>
                                @if ($providerKey === 'meshcentral')
                                    <div class="min-w-0">
                                        <x-ui.forms.label :for="'device-provider-'.$providerKey.'-remote-url'" :value="$isGerman ? 'Öffentliche Fernwartungsadresse' : 'Public remote support URL'" />
                                        <x-ui.forms.input
                                            :id="'device-provider-'.$providerKey.'-remote-url'"
                                            type="url"
                                            inputmode="url"
                                            autocomplete="off"
                                            autocapitalize="none"
                                            spellcheck="false"
                                            placeholder="https://remote.example.test/device/{provider_device_id}"
                                            wire:model="providers.{{ $providerKey }}.remote_url_template"
                                            class="mt-1.5 font-mono"
                                        />
                                        <x-input-error :for="'providers.'.$providerKey.'.remote_url_template'" class="mt-1.5" />
                                    </div>
                                @endif
                            </div>
                        </details>

                        @if ($providerKey === 'nanomdm')
                            @php $mobileCommandsEnabled = (bool) ($provider['mobile_commands_enabled'] ?? false); @endphp
                            <div class="flex flex-col gap-3 rounded-xl bg-amber-50/80 p-3.5 text-amber-950 ring-1 ring-amber-200/80 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold">{{ $isGerman ? 'Mobile Apple-Kommandos' : 'Mobile Apple commands' }}</p>
                                        <span class="rounded-full bg-white/65 px-2 py-0.5 text-[0.7rem] font-semibold ring-1 ring-current/10 dark:bg-black/10">
                                            {{ $mobileCommandsEnabled ? ($isGerman ? 'freigegeben' : 'enabled') : ($isGerman ? 'blockiert' : 'blocked') }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 opacity-85">
                                        {{ $isGerman
                                            ? 'Dieser separate Schalter belegt nur die geprüfte NanoMDM-Kommandokette. Der globale Produktionsschutz gilt weiterhin zusätzlich.'
                                            : 'This separate switch confirms only the reviewed NanoMDM command chain. The global production guard still applies as well.' }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="setNanoMdmMobileCommandsEnabled({{ $mobileCommandsEnabled ? 'false' : 'true' }})"
                                    wire:confirm="{{ $mobileCommandsEnabled
                                        ? ($isGerman ? 'Mobile NanoMDM-Kommandos jetzt blockieren?' : 'Block mobile NanoMDM commands now?')
                                        : ($isGerman ? 'Mobile NanoMDM-Kommandos wirklich freigeben? Apple-Testorganisation, APNs/ADE und ein Laborgerät müssen zuvor geprüft sein.' : 'Enable mobile NanoMDM commands? The Apple test organisation, APNs/ADE and a lab device must be verified first.') }}"
                                    wire:loading.attr="disabled"
                                    wire:target="setNanoMdmMobileCommandsEnabled"
                                    class="rt-ui-button rt-ui-button-secondary min-h-11 w-full shrink-0 sm:w-auto"
                                >
                                    <i class="fad {{ $mobileCommandsEnabled ? 'fa-lock' : 'fa-unlock-alt' }}" aria-hidden="true"></i>
                                    <span wire:loading.remove wire:target="setNanoMdmMobileCommandsEnabled">
                                        {{ $mobileCommandsEnabled
                                            ? ($isGerman ? 'Mobile Kommandos blockieren' : 'Block mobile commands')
                                            : ($isGerman ? 'Kontrolliert freigeben' : 'Enable deliberately') }}
                                    </span>
                                    <span wire:loading wire:target="setNanoMdmMobileCommandsEnabled">{{ __('app.loading') }}</span>
                                </button>
                            </div>
                            <x-input-error for="providers.nanomdm.mobile_commands_enabled" />
                        @endif

                        @if ($hasResult)
                            <div
                                class="rounded-xl p-3.5 ring-1 {{ $healthy ? 'bg-emerald-50 text-emerald-900 ring-emerald-200/80 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/25' : 'bg-rose-50 text-rose-900 ring-rose-200/80 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25' }}"
                                role="status"
                                aria-live="polite"
                                aria-atomic="true"
                                data-device-provider-diagnostic="{{ $providerKey }}"
                            >
                                <div class="flex items-start gap-2.5">
                                    <i class="fad {{ $healthy ? 'fa-check-circle' : 'fa-exclamation-circle' }} mt-0.5" aria-hidden="true"></i>
                                    <div class="min-w-0 flex-1">
                                        <p class="break-words text-sm font-semibold">{{ $diagnosticStatusLabel }}</p>
                                        <p class="mt-1 text-xs opacity-80">
                                            {{ $result['checked_at'] ?? '—' }}
                                            @if (is_numeric($result['latency_ms'] ?? null))
                                                · {{ (int) $result['latency_ms'] }} ms
                                            @endif
                                        </p>
                                        <ul class="mt-2 flex flex-wrap gap-1.5 text-[0.7rem] font-semibold">
                                            @foreach ([
                                                'configured' => $isGerman ? 'Konfiguration' : 'Configuration',
                                                'reachable' => $isGerman ? 'Erreichbar' : 'Reachable',
                                                'authenticated' => $isGerman ? 'Anmeldung' : 'Authentication',
                                                'contract_valid' => $isGerman ? 'Vertrag' : 'Contract',
                                            ] as $check => $label)
                                                <li class="rounded-md bg-white/55 px-2 py-1 ring-1 ring-current/10 dark:bg-black/10">
                                                    <i class="fad {{ ($result[$check] ?? false) ? 'fa-check' : 'fa-times' }} me-1" aria-hidden="true"></i>{{ $label }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <x-input-error :for="'providers.'.$providerKey" />
                        <div class="flex flex-col gap-2.5 border-t border-rt-border/60 pt-4 dark:border-rt-dark-border/60 sm:flex-row">
                            <button
                                type="button"
                                wire:click="saveProvider('{{ $providerKey }}')"
                                wire:loading.attr="disabled"
                                wire:target="saveProvider('{{ $providerKey }}')"
                                class="rt-ui-button rt-ui-button-primary min-h-11 w-full sm:flex-1"
                            >
                                <i class="fad fa-save" aria-hidden="true"></i>
                                <span wire:loading.remove wire:target="saveProvider('{{ $providerKey }}')">{{ $isGerman ? 'Provider speichern' : 'Save provider' }}</span>
                                <span wire:loading wire:target="saveProvider('{{ $providerKey }}')">{{ __('app.loading') }}</span>
                            </button>
                            <button
                                type="button"
                                wire:click="testProvider('{{ $providerKey }}')"
                                wire:loading.attr="disabled"
                                wire:target="testProvider('{{ $providerKey }}')"
                                class="rt-ui-button rt-ui-button-secondary min-h-11 w-full sm:flex-1"
                            >
                                <i class="fad fa-stethoscope" aria-hidden="true"></i>
                                <span wire:loading.remove wire:target="testProvider('{{ $providerKey }}')">{{ $isGerman ? 'Gespeicherte Verbindung testen' : 'Test saved connection' }}</span>
                                <span wire:loading wire:target="testProvider('{{ $providerKey }}')">{{ __('app.loading') }}</span>
                            </button>
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-identities"
        :label="$isGerman ? 'Microsoft-, Google- und Apple-Domänen' : 'Microsoft, Google and Apple domains'"
        :description="$isGerman ? 'Nur Organisationsdomänen vorbereiten; Benutzerkennwörter und OAuth-Tokens werden nicht in RailTime gespeichert.' : 'Prepare organisation domains only; user passwords and OAuth tokens are never stored in RailTime.'"
        icon="fad fa-id-card"
        data-anim="fade-up"
        data-anim-delay="0.12"
    >
        <section class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5">
            <div class="space-y-5 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6">
                <div class="grid min-w-0 gap-4 lg:grid-cols-3">
                    @foreach ([
                        'microsoft_365' => ['label' => 'Microsoft 365 / Outlook', 'placeholder' => 'rail-time.de', 'icon' => 'fab fa-microsoft'],
                        'google_workspace' => ['label' => 'Google Workspace', 'placeholder' => 'rail-time.de', 'icon' => 'fab fa-google'],
                        'apple_managed' => ['label' => 'Apple Managed IDs', 'placeholder' => 'rail-time.de', 'icon' => 'fab fa-apple'],
                    ] as $field => $meta)
                        <div class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <label for="device-identity-{{ $field }}" class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                <i class="{{ $meta['icon'] }} text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                                {{ $meta['label'] }}
                            </label>
                            <x-ui.forms.input
                                id="device-identity-{{ $field }}"
                                type="text"
                                inputmode="url"
                                autocomplete="off"
                                autocapitalize="none"
                                spellcheck="false"
                                :placeholder="$meta['placeholder']"
                                wire:model="identityDomains.{{ $field }}"
                                class="mt-1.5 font-mono"
                            />
                            <x-input-error :for="'identityDomains.'.$field" class="mt-1.5" />
                        </div>
                    @endforeach
                </div>
                <x-input-error for="identityDomains" />
                <div class="flex justify-end">
                    <button
                        type="button"
                        wire:click="saveIdentityDomains"
                        wire:loading.attr="disabled"
                        wire:target="saveIdentityDomains"
                        class="rt-ui-button rt-ui-button-primary min-h-11 w-full sm:w-auto"
                    >
                        <i class="fad fa-save" aria-hidden="true"></i>
                        <span wire:loading.remove wire:target="saveIdentityDomains">{{ $isGerman ? 'Kontendomänen speichern' : 'Save identity domains' }}</span>
                        <span wire:loading wire:target="saveIdentityDomains">{{ __('app.loading') }}</span>
                    </button>
                </div>
            </div>
        </section>
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-safety"
        :label="$isGerman ? 'Produktionsschutz' : 'Production safety'"
        :description="$isGerman ? 'Enrollment, Konto-Synchronisationen und Gerätebefehle bleiben unabhängig von Providerzugängen standardmäßig blockiert.' : 'Enrollment, identity synchronization and device commands remain blocked by default, independently of provider credentials.'"
        icon="fad fa-shield-alt"
        data-anim="fade-up"
        data-anim-delay="0.16"
    >
        <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70">
            <div class="flex flex-col gap-5 p-4 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-700 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25">
                        <i class="fad fa-power-off fa-lg" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">
                                {{ $isGerman ? 'Globaler Aktions-Schalter' : 'Global action switch' }}
                            </h3>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $commandsEnabled ? 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25' : 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25' }}">
                                {{ $commandsEnabled ? ($isGerman ? 'Befehle freigegeben' : 'Commands enabled') : ($isGerman ? 'Sicher blockiert' : 'Safely blocked') }}
                            </span>
                        </div>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                            {{ $isGerman
                                ? 'Ein Verbindungstest schaltet diesen Schutz niemals automatisch frei. Die Freigabe betrifft ausschließlich künftig eingereihte, weiterhin berechtigte und protokollierte Befehle.'
                                : 'A connection test never enables this protection automatically. Enabling affects only future commands that remain authorised and audited.' }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="setProductionCommandsEnabled({{ $commandsEnabled ? 'false' : 'true' }})"
                    wire:confirm="{{ $commandsEnabled
                        ? ($isGerman ? 'Externe Geräteaktionen und Konto-Synchronisationen jetzt global blockieren?' : 'Block external device actions and identity synchronization globally now?')
                        : ($isGerman ? 'Externe Geräteaktionen und Konto-Synchronisationen wirklich global freigeben? Provider, Laborgeräte und Rollback müssen zuvor geprüft sein.' : 'Enable external device actions and identity synchronization globally? Providers, lab devices and rollback must be verified first.') }}"
                    wire:loading.attr="disabled"
                    wire:target="setProductionCommandsEnabled"
                    class="rt-ui-button min-h-11 w-full shrink-0 {{ $commandsEnabled ? 'rt-ui-button-secondary' : 'bg-rose-600 text-white hover:bg-rose-700 focus-visible:ring-rose-500' }} lg:w-auto"
                >
                    <i class="fad {{ $commandsEnabled ? 'fa-lock' : 'fa-unlock-alt' }}" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="setProductionCommandsEnabled">
                        {{ $commandsEnabled
                            ? ($isGerman ? 'Befehle global blockieren' : 'Block commands globally')
                            : ($isGerman ? 'Befehle kontrolliert freigeben' : 'Enable commands deliberately') }}
                    </span>
                    <span wire:loading wire:target="setProductionCommandsEnabled">{{ __('app.loading') }}</span>
                </button>
            </div>
            <x-input-error for="runtime.production_commands_enabled" class="mx-4 mb-4 sm:mx-6 sm:mb-6" />
        </section>
    </x-admin.settings-accordion-section>
</div>
