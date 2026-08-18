<?php

namespace App\Livewire\Admin;

use App\Services\DeviceManagement\DeviceManagementSettings as DeviceManagementSettingsService;
use App\Services\DeviceManagement\DeviceProviderDiagnosticsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;
use Throwable;

class DeviceManagementSettings extends Component
{
    /** @var array<int, string> */
    private const EDITABLE_PROVIDERS = [
        'openuem',
        'meshcentral',
        'headwind',
        'nanomdm',
        'identity',
    ];

    /** @var array<string, mixed> */
    public array $deployment = [];

    /** @var array<string, mixed> */
    public array $runtime = [];

    /** @var array<string, string> */
    public array $identityDomains = [];

    /** @var array<string, array<string, mixed>> */
    public array $providers = [];

    /** @var array<string, array<string, mixed>> */
    public array $diagnostics = [];

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
        $this->reloadForm();
    }

    public function saveDeployment(): void
    {
        $this->authorizeSuperAdmin();

        $validated = $this->validate([
            'deployment.mode' => ['required', 'string', Rule::in(['subdomain', 'port'])],
            'deployment.base_domain' => [
                'required',
                'string',
                'max:253',
                'regex:/\A(?:localhost|(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\z/i',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (app()->environment('production')
                        && strtolower((string) ($this->deployment['mode'] ?? 'subdomain')) === 'subdomain'
                        && ! $this->isPublicProductionDomain((string) $value)) {
                        $fail('Im Produktivbetrieb ist eine öffentlich auflösbare FQDN erforderlich; lokale, private und reservierte Domains sind nicht zulässig.');
                    }
                },
            ],
            'runtime.enrollment_ttl_minutes' => ['required', 'integer', 'min:15', 'max:10080'],
            'runtime.max_sync_age_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'runtime.artifact_max_kilobytes' => ['required', 'integer', 'min:1024', 'max:1048576'],
        ], [], [
            'deployment.mode' => 'Betriebsart',
            'deployment.base_domain' => 'Basisdomain',
            'runtime.enrollment_ttl_minutes' => 'Gültigkeit der Einrichtungslinks',
            'runtime.max_sync_age_hours' => 'maximales Synchronisierungsalter',
            'runtime.artifact_max_kilobytes' => 'maximale Dateigröße',
        ]);

        $storedForm = $this->currentSettingsForm('deployment');
        $storedDeployment = (array) ($storedForm['deployment'] ?? []);
        $targetChanged = strtolower((string) ($validated['deployment']['mode'] ?? ''))
                !== strtolower((string) ($storedDeployment['mode'] ?? ''))
            || strtolower(rtrim((string) ($validated['deployment']['base_domain'] ?? ''), '.'))
                !== strtolower(rtrim((string) ($storedDeployment['base_domain'] ?? ''), '.'));

        $this->runSettingsMutation(
            fn (): mixed => app(DeviceManagementSettingsService::class)->saveDeployment(
                $validated['deployment'],
                $validated['runtime'],
            ),
            'deployment',
            'Die Betriebsart kann mit den gespeicherten Provider-Einstellungen nicht verwendet werden. Prüfen Sie insbesondere doppelte Adapter-Ports.',
            'Die Gerätebetriebseinstellungen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );

        $this->reloadForm();
        $this->dispatch(
            'swal:toast',
            type: $targetChanged ? 'warning' : 'success',
            text: $targetChanged
                ? 'Das Connector-Ziel wurde geändert. Aktive externe Provider wurden deaktiviert und gespeicherte Zugangsdaten aller externen Provider entfernt; diese müssen jetzt neu eingetragen werden.'
                : 'Plesk- und Gerätebetriebseinstellungen wurden gespeichert.',
        );
    }

    public function saveIdentityDomains(): void
    {
        $this->authorizeSuperAdmin();

        $domainPattern = '/\A@?(?:localhost|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)\z/i';
        $validated = $this->validate([
            'identityDomains.microsoft_365' => ['nullable', 'string', 'max:254', "regex:{$domainPattern}"],
            'identityDomains.google_workspace' => ['nullable', 'string', 'max:254', "regex:{$domainPattern}"],
            'identityDomains.apple_managed' => ['nullable', 'string', 'max:254', "regex:{$domainPattern}"],
        ], [], [
            'identityDomains.microsoft_365' => 'Microsoft-365-Domain',
            'identityDomains.google_workspace' => 'Google-Workspace-Domain',
            'identityDomains.apple_managed' => 'Apple-Domain',
        ]);

        $this->runSettingsMutation(
            fn (): mixed => app(DeviceManagementSettingsService::class)->saveIdentityDomains($validated['identityDomains']),
            'identityDomains',
            'Mindestens eine Kontendomäne ist ungültig. Bitte tragen Sie ausschließlich gültige Organisationsdomains ein.',
            'Die Kontendomänen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );

        $this->reloadForm();
        $this->dispatch('swal:toast', type: 'success', text: 'Kontendomänen wurden gespeichert.');
    }

    public function saveProvider(string $provider): void
    {
        $this->authorizeSuperAdmin();
        $provider = $this->editableProvider($provider);

        $rules = [
            "providers.{$provider}.enabled" => ['required', 'boolean'],
            "providers.{$provider}.subdomain" => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i',
            ],
            "providers.{$provider}.adapter_port" => ['required', 'integer', 'min:1024', 'max:65535'],
            "providers.{$provider}.timeout" => ['required', 'integer', 'min:1', 'max:30'],
            "providers.{$provider}.token" => $this->secretRules(),
            "providers.{$provider}.webhook_secret" => $this->secretRules(),
            "providers.{$provider}.remote_url_template" => [
                'nullable',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $url = trim((string) $value);
                    if ($url === '') {
                        return;
                    }

                    $withoutAllowedPlaceholders = str_replace(['{device_id}', '{provider_device_id}'], '', $url);
                    $parts = parse_url($url);
                    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
                    if ((! str_contains($url, '{device_id}') && ! str_contains($url, '{provider_device_id}'))
                        || str_contains($withoutAllowedPlaceholders, '{')
                        || str_contains($withoutAllowedPlaceholders, '}')
                        || ! is_array($parts)
                        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                        || ! isset($parts['host'])
                        || isset($parts['user'])
                        || isset($parts['pass'])
                        || isset($parts['fragment'])
                        || $host === 'localhost'
                        || ! str_contains($host, '.')
                        || filter_var($host, FILTER_VALIDATE_IP) !== false
                        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                        || (isset($parts['query'])
                            && preg_match('/(?:token|secret|signature|password|key)=/i', (string) $parts['query']))) {
                        $fail('Die öffentliche Fernwartungsadresse benötigt HTTPS, einen öffentlichen DNS-Namen und den Platzhalter {device_id} oder {provider_device_id}.');
                    }
                },
            ],
            "providers.{$provider}.clear_token" => ['sometimes', 'boolean'],
            "providers.{$provider}.clear_webhook_secret" => ['sometimes', 'boolean'],
        ];

        if ($provider === 'nanomdm') {
            $rules["providers.{$provider}.mobile_commands_enabled"] = ['required', 'boolean'];
        }

        $validated = $this->validate($rules, [], [
            "providers.{$provider}.subdomain" => 'Subdomain',
            "providers.{$provider}.adapter_port" => 'Adapter-Port',
            "providers.{$provider}.timeout" => 'Zeitlimit',
            "providers.{$provider}.token" => 'Zugriffstoken',
            "providers.{$provider}.webhook_secret" => 'Webhook-Geheimnis',
            "providers.{$provider}.remote_url_template" => 'öffentliche Fernwartungsadresse',
        ]);

        $values = $validated['providers'][$provider];
        $storedForm = $this->currentSettingsForm("providers.{$provider}");
        $storedProvider = (array) data_get($storedForm, "providers.{$provider}", []);
        $deploymentMode = strtolower((string) data_get($storedForm, 'deployment.mode', 'subdomain'));
        $targetChanged = $deploymentMode === 'port'
            ? (int) ($values['adapter_port'] ?? 0) !== (int) ($storedProvider['adapter_port'] ?? 0)
            : strtolower((string) ($values['subdomain'] ?? ''))
                !== strtolower((string) ($storedProvider['subdomain'] ?? ''));
        $tokenReentered = ! (bool) ($values['clear_token'] ?? false)
            && $this->isNewSecret($values['token'] ?? null);
        $webhookSecretReentered = ! (bool) ($values['clear_webhook_secret'] ?? false)
            && $this->isNewSecret($values['webhook_secret'] ?? null);
        $credentialsReentered = $tokenReentered && $webhookSecretReentered;

        if ((bool) ($values['enabled'] ?? false) && (! $targetChanged || $credentialsReentered)) {
            $credentialErrors = [];
            if (! $this->credentialWillBeAvailable($provider, 'token', $values)) {
                $credentialErrors["providers.{$provider}.token"] = 'Ein aktiver Connector benötigt ein gespeichertes Zugriffstoken.';
            }
            if (! $this->credentialWillBeAvailable($provider, 'webhook_secret', $values)) {
                $credentialErrors["providers.{$provider}.webhook_secret"] = 'Ein aktiver Connector benötigt ein gespeichertes Webhook-Geheimnis.';
            }
            if ($credentialErrors !== []) {
                throw ValidationException::withMessages($credentialErrors);
            }
        }

        if (! $targetChanged || $credentialsReentered) {
            $this->validateEndpointCollision($provider, $values, $storedForm);
        }

        $this->runSettingsMutation(
            fn (): mixed => app(DeviceManagementSettingsService::class)->saveProvider($provider, $values),
            "providers.{$provider}",
            'Die Provider-Einstellungen sind noch nicht betriebsbereit. Prüfen Sie Zugangsdaten, Fernwartungsadresse und doppelte Connector-Ziele.',
            'Die Provider-Einstellungen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );

        unset($this->diagnostics[$provider]);
        $this->reloadForm();
        $this->dispatch(
            'swal:toast',
            type: $targetChanged && ! $credentialsReentered ? 'warning' : 'success',
            text: match (true) {
                $targetChanged && $credentialsReentered => 'Das neue Connector-Ziel und die ausdrücklich neu eingegebenen Zugangsdaten wurden gespeichert.',
                $targetChanged => 'Das Connector-Ziel wurde gespeichert. Ohne vollständige Neueingabe beider Zugangsdaten wurde der Provider sicher deaktiviert und beide Geheimnisse wurden entfernt.',
                default => 'Provider-Einstellungen wurden gespeichert.',
            },
        );
    }

    public function setNanoMdmMobileCommandsEnabled(bool $enabled): void
    {
        $this->authorizeSuperAdmin();
        $provider = $this->editableProvider('nanomdm');

        // Der separate Sicherheitsknopf darf keine noch ungespeicherten
        // Formularänderungen der Provider-Karte nebenbei übernehmen.
        $form = app(DeviceManagementSettingsService::class)->forForm();
        $values = (array) data_get($form, "providers.{$provider}", []);
        $values['mobile_commands_enabled'] = $enabled;

        $this->runSettingsMutation(
            fn (): mixed => app(DeviceManagementSettingsService::class)->saveProvider($provider, $values),
            'providers.nanomdm.mobile_commands_enabled',
            'Mobile Apple-Kommandos benötigen einen aktiven NanoMDM-Connector mit vollständigen Zugangsdaten.',
            'Die Freigabe für mobile Apple-Kommandos konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );

        unset($this->diagnostics[$provider]);
        $this->reloadForm();
        $this->dispatch(
            'swal:toast',
            type: $enabled ? 'warning' : 'success',
            text: $enabled
                ? 'Mobile NanoMDM-Kommandos wurden kontrolliert freigeschaltet.'
                : 'Mobile NanoMDM-Kommandos wurden deaktiviert.',
        );
    }

    public function testProvider(string $provider, DeviceProviderDiagnosticsService $diagnostics): void
    {
        $this->authorizeSuperAdmin();
        $provider = $this->editableProvider($provider);

        try {
            $result = $diagnostics->probe($provider);
            $this->diagnostics[$provider] = is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->diagnostics[$provider] = [
                'configured' => false,
                'url' => null,
                'reachable' => false,
                'authenticated' => false,
                'contract_valid' => false,
                'healthy' => false,
                'status' => 'probe_failed',
                'latency_ms' => null,
                'checked_at' => now()->toIso8601String(),
                'details' => [],
            ];
        }

        $result = $this->diagnostics[$provider];
        activity('device-management')
            ->causedBy(auth()->user())
            ->withProperties([
                'provider' => $provider,
                'status' => mb_substr((string) ($result['status'] ?? 'unknown'), 0, 80),
                'healthy' => (bool) ($result['healthy'] ?? false),
                'checked_at' => (string) ($result['checked_at'] ?? now()->toIso8601String()),
            ])
            ->log('device_provider_diagnostic');
    }

    public function setProductionCommandsEnabled(bool $enabled): void
    {
        $this->authorizeSuperAdmin();

        $this->runSettingsMutation(
            fn (): mixed => app(DeviceManagementSettingsService::class)->setProductionCommandsEnabled($enabled),
            'runtime.production_commands_enabled',
            'Der globale Befehls-Schalter kann mit der aktuellen Gerätekonfiguration nicht geändert werden.',
            'Der globale Befehls-Schalter konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );

        $this->reloadForm();
        $this->dispatch(
            'swal:toast',
            type: $enabled ? 'warning' : 'success',
            text: $enabled
                ? 'Externe Gerätebefehle wurden freigeschaltet.'
                : 'Externe Gerätebefehle wurden durch den globalen Schutzschalter deaktiviert.',
        );
    }

    public function render()
    {
        return view('livewire.admin.device-management-settings');
    }

    private function reloadForm(): void
    {
        $form = app(DeviceManagementSettingsService::class)->forForm();

        $this->deployment = (array) ($form['deployment'] ?? []);
        $this->runtime = (array) ($form['runtime'] ?? []);
        $this->identityDomains = (array) ($form['identity_domains'] ?? []);
        $this->providers = Arr::only((array) ($form['providers'] ?? []), self::EDITABLE_PROVIDERS);

        foreach ($this->providers as $provider => $values) {
            $this->providers[$provider] = array_merge((array) $values, [
                'clear_token' => false,
                'clear_webhook_secret' => false,
            ]);
        }

        if (isset($this->providers['nanomdm'])) {
            $this->providers['nanomdm']['mobile_commands_enabled'] = (bool) (
                $this->providers['nanomdm']['mobile_commands_enabled'] ?? false
            );
        }
    }

    private function editableProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, self::EDITABLE_PROVIDERS, true), 404);
        abort_unless(array_key_exists($provider, $this->providers), 404);

        return $provider;
    }

    /** @return array<int, mixed> */
    private function secretRules(): array
    {
        $minimum = DeviceManagementSettingsService::SECRET_MIN_LENGTH;
        $maximum = DeviceManagementSettingsService::SECRET_MAX_LENGTH;

        return [
            'nullable',
            'string',
            function (string $attribute, mixed $value, \Closure $fail) use ($minimum, $maximum): void {
                $secret = trim((string) $value);
                if ($secret === '' || hash_equals(DeviceManagementSettingsService::SECRET_MASK, $secret)) {
                    return;
                }

                if (mb_strlen($secret) < $minimum || mb_strlen($secret) > $maximum) {
                    $fail("Neue Connector-Geheimnisse müssen zwischen {$minimum} und {$maximum} Zeichen lang sein.");
                }
            },
        ];
    }

    /** @param array<string, mixed> $values */
    private function credentialWillBeAvailable(string $provider, string $field, array $values): bool
    {
        if ((bool) ($values['clear_'.$field] ?? false)) {
            return false;
        }

        $submitted = trim((string) ($values[$field] ?? ''));
        if ($submitted !== '' && ! hash_equals(DeviceManagementSettingsService::SECRET_MASK, $submitted)) {
            return true;
        }

        return (bool) ($this->providers[$provider][$field.'_configured'] ?? false);
    }

    /** @return array<string, mixed> */
    private function currentSettingsForm(string $errorKey): array
    {
        try {
            return app(DeviceManagementSettingsService::class)->forForm();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $errorKey => 'Die gespeicherte Gerätekonfiguration konnte nicht sicher gelesen werden. Bitte versuchen Sie es erneut.',
            ]);
        }
    }

    private function isNewSecret(mixed $value): bool
    {
        $secret = trim((string) $value);

        return $secret !== '' && ! hash_equals(DeviceManagementSettingsService::SECRET_MASK, $secret);
    }

    /** @param array<string, mixed> $values
     * @param  array<string, mixed>  $storedForm
     */
    private function validateEndpointCollision(string $provider, array $values, array $storedForm): void
    {
        if (! (bool) ($values['enabled'] ?? false)) {
            return;
        }

        $mode = strtolower((string) data_get($storedForm, 'deployment.mode', 'subdomain'));
        $field = $mode === 'port' ? 'adapter_port' : 'subdomain';
        $candidate = $mode === 'port'
            ? (string) ((int) ($values[$field] ?? 0))
            : strtolower((string) ($values[$field] ?? ''));

        foreach ((array) ($storedForm['providers'] ?? []) as $otherKey => $otherProvider) {
            if ($otherKey === $provider || $otherKey === 'simulation' || ! (bool) ($otherProvider['enabled'] ?? false)) {
                continue;
            }

            $other = $mode === 'port'
                ? (string) ((int) ($otherProvider[$field] ?? 0))
                : strtolower((string) ($otherProvider[$field] ?? ''));
            if ($candidate === $other) {
                $label = trim((string) ($otherProvider['label'] ?? $otherKey));
                throw ValidationException::withMessages([
                    "providers.{$provider}.{$field}" => $mode === 'port'
                        ? "Dieser lokale Adapter-Port wird bereits vom aktiven Provider {$label} verwendet."
                        : "Dieses aktive Connector-Ziel wird bereits vom Provider {$label} verwendet.",
                ]);
            }
        }
    }

    private function isPublicProductionDomain(string $domain): bool
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === ''
            || ! str_contains($domain, '.')
            || filter_var($domain, FILTER_VALIDATE_IP) !== false
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        $reservedSuffixes = [
            'localhost',
            'local',
            'localdomain',
            'internal',
            'intranet',
            'home',
            'home.arpa',
            'lan',
            'test',
            'invalid',
            'example',
            'onion',
            'alt',
            'arpa',
            'example.com',
            'example.net',
            'example.org',
        ];

        foreach ($reservedSuffixes as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return false;
            }
        }

        return true;
    }

    private function runSettingsMutation(
        callable $mutation,
        string $errorKey,
        string $invalidMessage,
        string $failureMessage,
    ): void {
        try {
            $mutation();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$errorKey => $invalidMessage]);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([$errorKey => $failureMessage]);
        } catch (Throwable) {
            throw ValidationException::withMessages([$errorKey => $failureMessage]);
        }
    }

    private function authorizeSuperAdmin(): void
    {
        Gate::authorize('settings.manage');
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }
}
