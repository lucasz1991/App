<?php

namespace App\Services\DeviceManagement;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Database-backed runtime configuration for the RailTime device control plane.
 *
 * Provider capabilities, paths and security ceilings remain code-owned in
 * config/device_management.php. Only operational values are persisted. Secrets
 * are encrypted individually and never exposed by forForm().
 */
final class DeviceManagementSettings
{
    public const GROUP = 'device_management';

    public const KEY = 'runtime';

    public const SCHEMA_VERSION = 1;

    public const SECRET_MASK = '••••••••••••';

    public const SECRET_MIN_LENGTH = 32;

    public const SECRET_MAX_LENGTH = 512;

    private const SECRET_PREFIX = 'enc:v1:';

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    private bool $storageFailed = false;

    /**
     * Values safe to hydrate into a Livewire public property.
     *
     * @return array<string, mixed>
     */
    public function forForm(): array
    {
        $deployment = $this->deployment();
        $runtime = $this->runtime();
        $providers = [];

        foreach ($this->allProviderRuntime() as $key => $provider) {
            $providers[$key] = [
                'key' => $key,
                'label' => $provider['label'],
                'enabled' => $provider['enabled'],
                'subdomain' => $provider['subdomain'],
                'adapter_port' => $provider['adapter_port'],
                'timeout' => $provider['timeout'],
                'token' => $provider['token'] === '' ? '' : self::SECRET_MASK,
                'token_configured' => $provider['token'] !== '',
                'webhook_secret' => $provider['webhook_secret'] === '' ? '' : self::SECRET_MASK,
                'webhook_secret_configured' => $provider['webhook_secret'] !== '',
                'remote_url_template' => $provider['remote_url_template'],
                'effective_url' => $provider['effective_url'],
                'public_url' => $provider['public_url'],
                'capabilities' => $provider['capabilities'],
            ];

            if ($key === 'nanomdm') {
                $providers[$key]['mobile_commands_enabled'] = $provider['mobile_commands_enabled'];
            }
        }

        return [
            'deployment' => $deployment,
            'runtime' => $runtime,
            'identity_domains' => $this->identityDomains(),
            'providers' => $providers,
        ];
    }

    /**
     * Save Plesk topology and editable operational limits.
     *
     * The kill switch deliberately has its own method so a generic settings
     * save cannot accidentally enable real device mutations.
     *
     * @param  array<string, mixed>  $deployment
     * @param  array<string, mixed>  $runtime
     */
    public function saveDeployment(array $deployment, array $runtime = []): void
    {
        DB::transaction(function () use ($deployment, $runtime): void {
            $current = $this->lockedStoredForWrite();
            $currentDeployment = $this->deploymentFrom($current);
            $currentRuntime = $this->runtimeFrom($current);

            $nextDeployment = [
                'mode' => $this->normalizeMode($deployment['mode'] ?? $currentDeployment['mode']),
                'base_domain' => $this->normalizeBaseDomain(
                    $deployment['base_domain'] ?? $currentDeployment['base_domain'],
                ),
            ];

            $nextRuntime = $currentRuntime;
            foreach ([
                'enrollment_ttl_minutes' => 'enrollment_ttl_minutes',
                'max_sync_age_hours' => 'max_sync_age_hours',
                'artifact_max_kilobytes' => 'artifact_max_kilobytes',
            ] as $field => $limitKey) {
                if (array_key_exists($field, $runtime)) {
                    $nextRuntime[$field] = $this->boundedInteger(
                        $runtime[$field],
                        $this->limit($limitKey),
                    );
                }
            }

            // Never accept the mutation switch through a generic form save.
            $nextRuntime['production_commands_enabled'] = $currentRuntime['production_commands_enabled'];

            $changed = $this->changedFields($currentDeployment, $nextDeployment, 'deployment.');
            $changed = array_merge($changed, $this->changedFields($currentRuntime, $nextRuntime, 'runtime.'));
            $topologyChanged = $currentDeployment['mode'] !== $nextDeployment['mode']
                || $currentDeployment['base_domain'] !== $nextDeployment['base_domain'];
            $current['deployment'] = $nextDeployment;
            $current['runtime'] = $nextRuntime;
            $invalidatedProviders = $topologyChanged
                ? $this->invalidateExternalProviderCredentials($current, $changed)
                : [];

            $this->assertDeploymentTargetSafety($current);
            $this->assertAllActiveEndpointsUnique($current);
            $this->persist($current);
            $this->audit('device-settings.updated', $changed, [
                'credentials_invalidated' => $invalidatedProviders,
            ]);
        });
    }

    /** @param array<string, mixed> $domains */
    public function saveIdentityDomains(array $domains): void
    {
        DB::transaction(function () use ($domains): void {
            $current = $this->lockedStoredForWrite();
            $existing = $this->identityDomainsFrom($current);
            $next = $existing;

            foreach (array_keys($this->identityDefaults()) as $provider) {
                if (array_key_exists($provider, $domains)) {
                    $next[$provider] = $this->normalizeIdentityDomain($domains[$provider]);
                }
            }

            $current['identity_domains'] = $next;
            $changed = $this->changedFields($existing, $next, 'identity_domains.');
            $this->persist($current);
            $this->audit('device-settings.updated', $changed);
        });
    }

    /** @param array<string, mixed> $values */
    public function saveProvider(string $provider, array $values): void
    {
        $provider = $this->normalizeProviderKey($provider);
        $catalog = $this->rawProviderCatalog();
        if (! isset($catalog[$provider])) {
            throw new InvalidArgumentException("Unbekannter Geräteprovider '{$provider}'.");
        }

        DB::transaction(function () use ($provider, $values, $catalog): void {
            $current = $this->lockedStoredForWrite();
            $existing = is_array($current['providers'][$provider] ?? null)
                ? $current['providers'][$provider]
                : [];
            $next = $existing;

            if (array_key_exists('enabled', $values)) {
                $next['enabled'] = $this->strictBoolean($values['enabled'], false);
            }
            if (array_key_exists('subdomain', $values)) {
                $next['subdomain'] = $this->normalizeSubdomain($values['subdomain']);
            }
            if (array_key_exists('adapter_port', $values)) {
                $next['adapter_port'] = $this->boundedInteger($values['adapter_port'], $this->limit('adapter_port'));
            }
            if (array_key_exists('timeout', $values)) {
                $next['timeout'] = $this->boundedInteger(
                    $values['timeout'],
                    $this->limit('provider_timeout_seconds'),
                );
            }
            if (array_key_exists('remote_url_template', $values)) {
                $next['remote_url_template'] = $this->normalizeRemoteUrlTemplate($values['remote_url_template']);
            }
            if ($provider === 'nanomdm' && array_key_exists('mobile_commands_enabled', $values)) {
                $next['mobile_commands_enabled'] = $this->strictBoolean($values['mobile_commands_enabled'], false);
            }

            [$next['token'], $tokenChanged] = $this->updatedSecret(
                $existing['token'] ?? null,
                $values,
                'token',
                'clear_token',
            );
            [$next['webhook_secret'], $webhookSecretChanged] = $this->updatedSecret(
                $existing['webhook_secret'] ?? null,
                $values,
                'webhook_secret',
                'clear_webhook_secret',
            );

            if (($tokenChanged || $webhookSecretChanged)
                && ($this->strictBoolean($values['clear_token'] ?? false, false)
                    || $this->strictBoolean($values['clear_webhook_secret'] ?? false, false))) {
                // Clearing a credential always disables the provider atomically.
                $next['enabled'] = false;
                if ($provider === 'nanomdm') {
                    $next['mobile_commands_enabled'] = false;
                }
            }

            $targetChanged = $provider !== 'simulation'
                && $this->providerTargetChanged(
                    $provider,
                    $existing,
                    $next,
                    $catalog[$provider],
                    $this->deploymentFrom($current)['mode'],
                );
            $credentialsReentered = $this->hasFreshSecretSubmission($values, 'token', 'clear_token')
                && $this->hasFreshSecretSubmission($values, 'webhook_secret', 'clear_webhook_secret');
            $credentialsInvalidated = $targetChanged && ! $credentialsReentered;
            if ($credentialsInvalidated) {
                $tokenChanged = $tokenChanged || ($next['token'] ?? '') !== '';
                $webhookSecretChanged = $webhookSecretChanged || ($next['webhook_secret'] ?? '') !== '';
                $next['enabled'] = false;
                $next['token'] = '';
                $next['webhook_secret'] = '';
                if ($provider === 'nanomdm') {
                    $next['mobile_commands_enabled'] = false;
                }
            }

            [$effectiveToken, $tokenValid] = $this->runtimeSecret($next, $catalog[$provider], 'token');
            [$effectiveWebhookSecret, $webhookSecretValid] = $this->runtimeSecret($next, $catalog[$provider], 'webhook_secret');
            $enabled = $this->strictBoolean($next['enabled'] ?? $catalog[$provider]['enabled'] ?? false, false);
            if ($provider === 'simulation' && $enabled && ! app()->environment(['local', 'testing'])) {
                throw new InvalidArgumentException('Die Gerätesimulation kann nur in local/testing aktiviert werden.');
            }
            if ($provider !== 'simulation'
                && $enabled
                && (! $tokenValid || ! $webhookSecretValid || $effectiveToken === '' || $effectiveWebhookSecret === '')) {
                throw new InvalidArgumentException('Ein aktiver Connector benötigt ein gültiges Token und Webhook-Geheimnis.');
            }
            if ($provider === 'nanomdm'
                && $this->strictBoolean($next['mobile_commands_enabled'] ?? false, false)
                && (! $enabled || ! $tokenValid || ! $webhookSecretValid || $effectiveToken === '' || $effectiveWebhookSecret === '')) {
                throw new InvalidArgumentException('Mobile Apple-Befehle benötigen einen vollständig konfigurierten, aktiven NanoMDM-Connector.');
            }

            $next = array_intersect_key($next, array_flip([
                'enabled',
                'subdomain',
                'adapter_port',
                'timeout',
                'token',
                'webhook_secret',
                'remote_url_template',
                'mobile_commands_enabled',
            ]));

            $current['providers'] = is_array($current['providers'] ?? null) ? $current['providers'] : [];
            $current['providers'][$provider] = $next;
            $this->assertDeploymentTargetSafety($current);
            $this->assertAllActiveEndpointsUnique($current);
            $changed = $this->changedFields(
                $this->providerComparable($existing),
                $this->providerComparable($next),
                "providers.{$provider}.",
            );
            $this->persist($current);
            $this->audit('device-provider.settings-updated', $changed, [
                'provider' => $provider,
                'token_changed' => $tokenChanged,
                'webhook_secret_changed' => $webhookSecretChanged,
                'credentials_invalidated' => $credentialsInvalidated,
            ]);
        });
    }

    public function productionCommandsEnabled(bool $fresh = false): bool
    {
        $stored = $this->stored($fresh);
        if ($this->storageFailed) {
            return false;
        }

        return $this->strictBoolean(
            $stored['runtime']['production_commands_enabled']
                ?? config('device_management.defaults.runtime.production_commands_enabled', false),
            false,
        );
    }

    public function setProductionCommandsEnabled(bool $enabled): void
    {
        DB::transaction(function () use ($enabled): void {
            $current = $this->lockedStoredForWrite();
            $runtime = $this->runtimeFrom($current);
            $previous = $runtime['production_commands_enabled'];
            $runtime['production_commands_enabled'] = $enabled;
            $current['runtime'] = $runtime;

            $changed = $previous === $enabled ? [] : ['runtime.production_commands_enabled'];
            $this->persist($current);
            $this->audit('device-settings.kill-switch-updated', $changed);
        });
    }

    /** @return array<string, mixed> */
    public function providerRuntime(string $provider, bool $fresh = false): array
    {
        $provider = $this->normalizeProviderKey($provider);
        $catalog = $this->rawProviderCatalog();
        if (! isset($catalog[$provider])) {
            throw new InvalidArgumentException("Unbekannter Geräteprovider '{$provider}'.");
        }

        $stored = $this->stored($fresh);
        $definition = $catalog[$provider];
        $hasSavedProvider = is_array($stored['providers'][$provider] ?? null);
        $saved = $hasSavedProvider
            ? $stored['providers'][$provider]
            : [];
        $deployment = $this->deploymentFrom($stored);
        $configurationError = $this->storageFailed;

        [$token, $tokenValid] = $this->runtimeSecret($saved, $definition, 'token');
        [$webhookSecret, $webhookSecretValid] = $this->runtimeSecret($saved, $definition, 'webhook_secret');
        $configurationError = $configurationError || ! $tokenValid || ! $webhookSecretValid;

        $subdomain = $this->safeSubdomain(
            $saved['subdomain'] ?? $definition['subdomain'] ?? $provider,
            (string) ($definition['subdomain'] ?? $provider),
        );
        $adapterPort = $this->safeBoundedInteger(
            $saved['adapter_port'] ?? $definition['adapter_port'] ?? 9443,
            $this->limit('adapter_port'),
            (int) ($definition['adapter_port'] ?? 9443),
        );
        $timeout = $this->safeBoundedInteger(
            $saved['timeout'] ?? $definition['timeout'] ?? 10,
            $this->limit('provider_timeout_seconds'),
            (int) ($definition['timeout'] ?? 10),
        );
        $remoteUrlTemplate = $this->safeRemoteUrlTemplate(
            $saved['remote_url_template'] ?? $definition['remote_url_template'] ?? '',
        );

        $requestedEnabled = $this->strictBoolean($saved['enabled'] ?? $definition['enabled'] ?? false, false);
        $configurationError = $configurationError || ! $this->providerTargetIsSafe(
            $provider,
            $deployment,
            $subdomain,
        );
        $credentialsReady = $tokenValid && $webhookSecretValid && $token !== '' && $webhookSecret !== '';
        $enabled = ! $configurationError
            && $requestedEnabled
            && ($provider !== 'simulation' || app()->environment(['local', 'testing']))
            && ($provider === 'simulation' || ! $hasSavedProvider || $credentialsReady);

        $capabilities = is_array($definition['capabilities'] ?? null)
            ? $definition['capabilities']
            : [];
        $mobileCommandsEnabled = $provider === 'nanomdm'
            && $enabled
            && $this->strictBoolean(
                $saved['mobile_commands_enabled'] ?? $definition['mobile_commands_enabled'] ?? false,
                false,
            );
        if ($provider === 'nanomdm') {
            $capabilities['commands'] = $mobileCommandsEnabled
                ? array_values(array_filter(
                    is_array($definition['command_ceiling'] ?? null) ? $definition['command_ceiling'] : [],
                    'is_string',
                ))
                : [];
        }

        $publicUrl = $provider === 'simulation'
            ? null
            : $this->publicUrl($subdomain, $deployment['base_domain']);
        $effectiveUrl = $provider === 'simulation'
            ? null
            : ($deployment['mode'] === 'port'
                ? "http://127.0.0.1:{$adapterPort}"
                : $publicUrl);

        return [
            'key' => $provider,
            'label' => (string) ($definition['label'] ?? $provider),
            'enabled' => $enabled,
            'mode' => $deployment['mode'],
            'base_domain' => $deployment['base_domain'],
            'subdomain' => $subdomain,
            'adapter_port' => $adapterPort,
            'timeout' => $timeout,
            'token' => $configurationError ? '' : $token,
            'webhook_secret' => $configurationError ? '' : $webhookSecret,
            'remote_url_template' => $remoteUrlTemplate,
            'effective_url' => $effectiveUrl,
            'public_url' => $publicUrl,
            // Compatibility key consumed by ConnectorDeviceProvider.
            'connector_base_url' => $effectiveUrl,
            'paths' => is_array($definition['paths'] ?? null) ? $definition['paths'] : [],
            'capabilities' => $capabilities,
            'same_server' => $deployment['mode'] === 'port',
            'configuration_error' => $configurationError,
            'mobile_commands_enabled' => $mobileCommandsEnabled,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function allProviderRuntime(bool $fresh = false): array
    {
        if ($fresh) {
            $this->stored(true);
        }

        $providers = [];
        foreach (array_keys($this->providerCatalog()) as $provider) {
            $providers[$provider] = $this->providerRuntime($provider);
        }

        return $providers;
    }

    /** @return array<string, array<string, mixed>> */
    public function providerCatalog(): array
    {
        return collect($this->rawProviderCatalog())
            ->map(function (array $provider): array {
                unset($provider['token'], $provider['webhook_secret'], $provider['command_ceiling']);

                return $provider;
            })
            ->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function rawProviderCatalog(): array
    {
        return collect((array) config('device_management.providers', []))
            ->filter(fn (mixed $provider, mixed $key): bool => is_string($key) && is_array($provider))
            ->map(fn (array $provider): array => $provider)
            ->all();
    }

    public function effectiveProviderUrl(string $provider, bool $fresh = false): ?string
    {
        $url = $this->providerRuntime($provider, $fresh)['effective_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function providerFingerprint(string $provider, bool $fresh = false): string
    {
        $runtime = $this->providerRuntime($provider, $fresh);

        return hash('sha256', json_encode([
            'key' => $runtime['key'],
            'enabled' => $runtime['enabled'],
            'effective_url' => $runtime['effective_url'],
            'timeout' => $runtime['timeout'],
            'token' => $runtime['token'] === '' ? '' : hash('sha256', $runtime['token']),
            'webhook_secret' => $runtime['webhook_secret'] === '' ? '' : hash('sha256', $runtime['webhook_secret']),
            'remote_url_template' => $runtime['remote_url_template'],
            'mobile_commands_enabled' => $runtime['mobile_commands_enabled'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function enrollmentTtlMinutes(): int
    {
        return $this->runtime()['enrollment_ttl_minutes'];
    }

    public function maxSyncAgeHours(): int
    {
        return $this->runtime()['max_sync_age_hours'];
    }

    public function artifactMaxKilobytes(): int
    {
        return $this->runtime()['artifact_max_kilobytes'];
    }

    public function artifactDisk(): string
    {
        return (string) config('device_management.artifact_disk', 'private');
    }

    public function queue(): string
    {
        return (string) config('device_management.queue', 'devices');
    }

    public function maximumRequestBytes(): int
    {
        return max(1024, (int) config('device_management.security.maximum_request_bytes', 32768));
    }

    public function maximumResponseBytes(): int
    {
        return max(1024, (int) config('device_management.security.maximum_response_bytes', 65536));
    }

    public function maximumWebhookBytes(): int
    {
        return max(1024, (int) config('device_management.security.maximum_webhook_bytes', 65536));
    }

    public function webhookToleranceSeconds(): int
    {
        return min(300, max(30, (int) config('device_management.security.webhook_tolerance_seconds', 300)));
    }

    public function identityDomain(string $provider): string
    {
        return $this->identityDomains()[$provider] ?? '';
    }

    public function forget(): void
    {
        $this->stored = null;
        $this->storageFailed = false;
    }

    /** @return array{mode: string, base_domain: string} */
    private function deployment(): array
    {
        return $this->deploymentFrom($this->stored());
    }

    /** @param array<string, mixed> $stored
     * @return array{mode: string, base_domain: string}
     */
    private function deploymentFrom(array $stored): array
    {
        $defaults = (array) config('device_management.defaults.deployment', []);
        $saved = is_array($stored['deployment'] ?? null) ? $stored['deployment'] : [];
        $fallbackDomain = $this->trustedApplicationHost();

        return [
            'mode' => $this->safeMode($saved['mode'] ?? $defaults['mode'] ?? 'subdomain'),
            'base_domain' => $this->safeBaseDomain(
                $saved['base_domain'] ?? $defaults['base_domain'] ?? $fallbackDomain,
                $fallbackDomain,
            ),
        ];
    }

    /** @return array<string, int|bool> */
    private function runtime(): array
    {
        return $this->runtimeFrom($this->stored());
    }

    /** @param array<string, mixed> $stored
     * @return array<string, int|bool>
     */
    private function runtimeFrom(array $stored): array
    {
        $defaults = (array) config('device_management.defaults.runtime', []);
        $saved = is_array($stored['runtime'] ?? null) ? $stored['runtime'] : [];

        return [
            'production_commands_enabled' => $this->storageFailed
                ? false
                : $this->strictBoolean(
                    $saved['production_commands_enabled'] ?? $defaults['production_commands_enabled'] ?? false,
                    false,
                ),
            'enrollment_ttl_minutes' => $this->safeBoundedInteger(
                $saved['enrollment_ttl_minutes'] ?? $defaults['enrollment_ttl_minutes'] ?? 1440,
                $this->limit('enrollment_ttl_minutes'),
                1440,
            ),
            'max_sync_age_hours' => $this->safeBoundedInteger(
                $saved['max_sync_age_hours'] ?? $defaults['max_sync_age_hours'] ?? 24,
                $this->limit('max_sync_age_hours'),
                24,
            ),
            'artifact_max_kilobytes' => $this->safeBoundedInteger(
                $saved['artifact_max_kilobytes'] ?? $defaults['artifact_max_kilobytes'] ?? 102400,
                $this->limit('artifact_max_kilobytes'),
                102400,
            ),
        ];
    }

    /** @return array<string, string> */
    private function identityDomains(): array
    {
        return $this->identityDomainsFrom($this->stored());
    }

    /** @param array<string, mixed> $stored
     * @return array<string, string>
     */
    private function identityDomainsFrom(array $stored): array
    {
        $saved = is_array($stored['identity_domains'] ?? null) ? $stored['identity_domains'] : [];
        $result = [];
        foreach ($this->identityDefaults() as $provider => $default) {
            $result[$provider] = $this->safeIdentityDomain($saved[$provider] ?? $default);
        }

        return $result;
    }

    /** @return array<string, string> */
    private function identityDefaults(): array
    {
        return collect((array) config('device_management.defaults.identity_domains', []))
            ->mapWithKeys(fn (mixed $value, mixed $key): array => [(string) $key => trim((string) $value)])
            ->all();
    }

    /** @return array<string, mixed> */
    private function stored(bool $fresh = false): array
    {
        if ($fresh) {
            $this->forget();
        }
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            $value = Setting::getValueUncached(self::GROUP, self::KEY);
            $this->storageFailed = ($value !== null && ! is_array($value))
                || (is_array($value) && ! $this->schemaVersionSupported($value));
            $this->stored = is_array($value) ? $value : [];
        } catch (Throwable) {
            $this->storageFailed = true;
            $this->stored = [];
        }

        return $this->stored;
    }

    /** @return array<string, mixed> */
    private function lockedStoredForWrite(): array
    {
        try {
            $model = new Setting;
            $now = now();
            DB::table($model->getTable())->insertOrIgnore([
                'type' => self::GROUP,
                'key' => self::KEY,
                'value' => json_encode(['schema_version' => self::SCHEMA_VERSION], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $setting = Setting::query()
                ->where('type', self::GROUP)
                ->where('key', self::KEY)
                ->lockForUpdate()
                ->first();
            $value = $setting?->value;
        } catch (Throwable $exception) {
            throw new RuntimeException('Die Geräteverwaltungseinstellungen sind derzeit nicht beschreibbar.', previous: $exception);
        }

        if (! is_array($value)) {
            throw new RuntimeException('Die Geräteverwaltungseinstellungen besitzen kein gültiges Datenformat.');
        }
        if (! $this->schemaVersionSupported($value)) {
            throw new RuntimeException('Die Version der Geräteverwaltungseinstellungen wird nicht unterstützt.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function schemaVersionSupported(array $values): bool
    {
        $version = $values['schema_version'] ?? null;

        return $version === null || $version === '' || in_array($version, [self::SCHEMA_VERSION, (string) self::SCHEMA_VERSION], true);
    }

    /** @param array<string, mixed> $values */
    private function persist(array $values): void
    {
        $values['schema_version'] = self::SCHEMA_VERSION;
        Setting::setValue(self::GROUP, self::KEY, $values);
        $this->forget();
    }

    /** @return array{0: string, 1: bool} */
    private function runtimeSecret(array $saved, array $definition, string $field): array
    {
        if (array_key_exists($field, $saved)) {
            return $this->decodeSecret($saved[$field]);
        }

        // A code default is empty in production. This fallback intentionally
        // keeps config()->set test seams without accepting plaintext from DB.
        return [trim((string) ($definition[$field] ?? '')), true];
    }

    /** @return array{0: string, 1: bool} */
    private function decodeSecret(mixed $stored): array
    {
        if ($stored === null || $stored === '') {
            return ['', true];
        }
        if (! is_string($stored) || ! str_starts_with($stored, self::SECRET_PREFIX)) {
            return ['', false];
        }

        try {
            return [trim(Crypt::decryptString(substr($stored, strlen(self::SECRET_PREFIX)))), true];
        } catch (Throwable) {
            return ['', false];
        }
    }

    /** @param array<string, mixed> $values
     * @return array{0: mixed, 1: bool}
     */
    private function updatedSecret(
        mixed $existing,
        array $values,
        string $field,
        string $clearField,
    ): array {
        if ($this->strictBoolean($values[$clearField] ?? false, false)) {
            return ['', $existing !== '' && $existing !== null];
        }

        if (! array_key_exists($field, $values)) {
            return [$existing ?? '', false];
        }

        $submitted = trim((string) $values[$field]);
        if ($submitted === '' || hash_equals(self::SECRET_MASK, $submitted)) {
            return [$existing ?? '', false];
        }
        if (mb_strlen($submitted) < self::SECRET_MIN_LENGTH || mb_strlen($submitted) > self::SECRET_MAX_LENGTH) {
            throw new InvalidArgumentException('Connector-Geheimnisse müssen zwischen 32 und 512 Zeichen lang sein.');
        }

        return [self::SECRET_PREFIX.Crypt::encryptString($submitted), true];
    }

    /** @param array<string, mixed> $values */
    private function hasFreshSecretSubmission(
        array $values,
        string $field,
        string $clearField,
    ): bool {
        if ($this->strictBoolean($values[$clearField] ?? false, false) || ! array_key_exists($field, $values)) {
            return false;
        }

        $submitted = trim((string) $values[$field]);

        return $submitted !== '' && ! hash_equals(self::SECRET_MASK, $submitted);
    }

    /** @return array{0: int, 1: int} */
    private function limit(string $key): array
    {
        $limit = config("device_management.limits.{$key}", [1, PHP_INT_MAX]);
        if (! is_array($limit) || count($limit) !== 2) {
            return [1, PHP_INT_MAX];
        }

        return [(int) $limit[0], (int) $limit[1]];
    }

    /** @param array{0: int, 1: int} $limit */
    private function boundedInteger(mixed $value, array $limit): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Der numerische Einstellungswert ist ungültig.');
        }

        $integer = (int) $value;
        if ($integer < $limit[0] || $integer > $limit[1]) {
            throw new InvalidArgumentException('Der numerische Einstellungswert liegt außerhalb des erlaubten Bereichs.');
        }

        return $integer;
    }

    /** @param array{0: int, 1: int} $limit */
    private function safeBoundedInteger(mixed $value, array $limit, int $fallback): int
    {
        try {
            return $this->boundedInteger($value, $limit);
        } catch (InvalidArgumentException) {
            return max($limit[0], min($limit[1], $fallback));
        }
    }

    private function strictBoolean(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
            return (bool) $value;
        }

        return $fallback;
    }

    private function normalizeMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        if (! in_array($mode, ['subdomain', 'port'], true)) {
            throw new InvalidArgumentException('Die Plesk-Betriebsart ist ungültig.');
        }

        return $mode;
    }

    private function safeMode(mixed $mode): string
    {
        try {
            return $this->normalizeMode($mode);
        } catch (InvalidArgumentException) {
            return 'subdomain';
        }
    }

    private function normalizeBaseDomain(mixed $domain): string
    {
        $domain = strtolower(rtrim(trim((string) $domain), '.'));
        if ($domain === '' || mb_strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Die Basisdomain ist ungültig.');
        }
        if ($domain !== 'localhost') {
            foreach (explode('.', $domain) as $label) {
                if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                    throw new InvalidArgumentException('Die Basisdomain ist ungültig.');
                }
            }
        }

        return $domain;
    }

    private function safeBaseDomain(mixed $domain, string $fallback): string
    {
        try {
            return $this->normalizeBaseDomain($domain);
        } catch (InvalidArgumentException) {
            return $fallback;
        }
    }

    private function normalizeSubdomain(mixed $subdomain): string
    {
        $subdomain = strtolower(trim((string) $subdomain));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain)) {
            throw new InvalidArgumentException('Das Connector-Subdomainpräfix ist ungültig.');
        }

        return $subdomain;
    }

    private function safeSubdomain(mixed $subdomain, string $fallback): string
    {
        try {
            return $this->normalizeSubdomain($subdomain);
        } catch (InvalidArgumentException) {
            return $this->normalizeSubdomain($fallback);
        }
    }

    private function normalizeIdentityDomain(mixed $domain): string
    {
        $domain = ltrim(trim((string) $domain), '@');

        return $domain === '' ? '' : $this->normalizeBaseDomain($domain);
    }

    private function safeIdentityDomain(mixed $domain): string
    {
        try {
            return $this->normalizeIdentityDomain($domain);
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    private function normalizeRemoteUrlTemplate(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }
        if (mb_strlen($url) > 2000) {
            throw new InvalidArgumentException('Die Fernwartungs-URL ist zu lang.');
        }
        if (! str_contains($url, '{device_id}') && ! str_contains($url, '{provider_device_id}')) {
            throw new InvalidArgumentException('Die Fernwartungs-URL benötigt einen Geräteplatzhalter.');
        }
        $withoutAllowedPlaceholders = str_replace(['{device_id}', '{provider_device_id}'], '', $url);
        if (str_contains($withoutAllowedPlaceholders, '{') || str_contains($withoutAllowedPlaceholders, '}')) {
            throw new InvalidArgumentException('Die Fernwartungs-URL enthält einen unbekannten Platzhalter.');
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Die Fernwartungs-URL muss eine sichere HTTPS-URL sein.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === 'localhost' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Die Fernwartungs-URL benötigt einen öffentlichen DNS-Namen.');
        }
        $this->normalizeBaseDomain($host);
        if (isset($parts['query']) && preg_match('/(?:token|secret|signature|password|key)=/i', (string) $parts['query'])) {
            throw new InvalidArgumentException('Die Fernwartungs-URL darf keine Zugangsdaten enthalten.');
        }

        return $url;
    }

    private function safeRemoteUrlTemplate(mixed $value): string
    {
        try {
            return $this->normalizeRemoteUrlTemplate($value);
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    private function trustedApplicationHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $this->safeBaseDomain(is_string($host) ? $host : '', 'localhost');
    }

    private function publicUrl(string $subdomain, string $baseDomain): string
    {
        return "https://{$subdomain}.{$baseDomain}";
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $next
     * @param  array<string, mixed>  $definition
     */
    private function providerTargetChanged(
        string $provider,
        array $existing,
        array $next,
        array $definition,
        string $mode,
    ): bool {
        $defaultSubdomain = (string) ($definition['subdomain'] ?? $provider);
        $existingSubdomain = $this->safeSubdomain(
            $existing['subdomain'] ?? $defaultSubdomain,
            $defaultSubdomain,
        );
        $nextSubdomain = $this->safeSubdomain(
            $next['subdomain'] ?? $defaultSubdomain,
            $defaultSubdomain,
        );
        $defaultPort = (int) ($definition['adapter_port'] ?? 9443);
        $existingPort = $this->safeBoundedInteger(
            $existing['adapter_port'] ?? $defaultPort,
            $this->limit('adapter_port'),
            $defaultPort,
        );
        $nextPort = $this->safeBoundedInteger(
            $next['adapter_port'] ?? $defaultPort,
            $this->limit('adapter_port'),
            $defaultPort,
        );

        return $mode === 'port'
            ? $existingPort !== $nextPort
            : $existingSubdomain !== $nextSubdomain;
    }

    /**
     * Topology changes must never carry connector credentials to another
     * effective target. Active providers are disabled, and every external
     * provider carrying credentials is cleared even when currently disabled.
     *
     * @param  array<string, mixed>  $stored
     * @param  list<string>  $changedFields
     * @return list<string>
     */
    private function invalidateExternalProviderCredentials(array &$stored, array &$changedFields): array
    {
        $stored['providers'] = is_array($stored['providers'] ?? null) ? $stored['providers'] : [];
        $invalidated = [];

        foreach ($this->rawProviderCatalog() as $provider => $definition) {
            if ($provider === 'simulation') {
                continue;
            }

            $saved = is_array($stored['providers'][$provider] ?? null)
                ? $stored['providers'][$provider]
                : [];
            $wasEnabled = $this->strictBoolean($saved['enabled'] ?? $definition['enabled'] ?? false, false);
            $tokenWasConfigured = ($saved['token'] ?? '') !== '';
            $webhookWasConfigured = ($saved['webhook_secret'] ?? '') !== '';
            if (! $wasEnabled && ! $tokenWasConfigured && ! $webhookWasConfigured) {
                continue;
            }

            $before = $this->providerComparable($saved);
            $saved['enabled'] = false;
            $saved['token'] = '';
            $saved['webhook_secret'] = '';
            if ($provider === 'nanomdm') {
                $saved['mobile_commands_enabled'] = false;
            }
            $stored['providers'][$provider] = $saved;

            $changedFields = array_merge(
                $changedFields,
                $this->changedFields($before, $this->providerComparable($saved), "providers.{$provider}."),
            );
            if ($tokenWasConfigured) {
                $changedFields[] = "providers.{$provider}.token";
            }
            if ($webhookWasConfigured) {
                $changedFields[] = "providers.{$provider}.webhook_secret";
            }
            $invalidated[] = $provider;
        }

        $changedFields = array_values(array_unique($changedFields));

        return $invalidated;
    }

    /** @param array<string, mixed> $stored */
    private function assertDeploymentTargetSafety(array $stored): void
    {
        $deployment = $this->deploymentFrom($stored);
        if ($deployment['mode'] !== 'subdomain' || app()->environment(['local', 'testing'])) {
            return;
        }

        $this->assertProductionPublicDomainName($deployment['base_domain']);
        foreach ($this->rawProviderCatalog() as $provider => $definition) {
            if ($provider === 'simulation') {
                continue;
            }
            $saved = is_array($stored['providers'][$provider] ?? null)
                ? $stored['providers'][$provider]
                : [];
            if (! $this->strictBoolean($saved['enabled'] ?? $definition['enabled'] ?? false, false)) {
                continue;
            }
            $subdomain = $this->safeSubdomain(
                $saved['subdomain'] ?? $definition['subdomain'] ?? $provider,
                (string) ($definition['subdomain'] ?? $provider),
            );
            $this->assertProductionPublicDomainName($subdomain.'.'.$deployment['base_domain']);
        }
    }

    /**
     * @param  array{mode: string, base_domain: string}  $deployment
     */
    private function providerTargetIsSafe(
        string $provider,
        array $deployment,
        string $subdomain,
    ): bool {
        if ($provider === 'simulation'
            || $deployment['mode'] !== 'subdomain'
            || app()->environment(['local', 'testing'])) {
            return true;
        }

        try {
            $this->assertProductionPublicDomainName($deployment['base_domain']);
            $this->assertProductionPublicDomainName($subdomain.'.'.$deployment['base_domain']);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function assertProductionPublicDomainName(string $host): void
    {
        $host = $this->normalizeBaseDomain($host);
        if (! str_contains($host, '.')) {
            throw new InvalidArgumentException('Produktive Connectoren benötigen einen öffentlichen DNS-Namen.');
        }

        $blockedSuffixes = (array) config('device_management.security.blocked_public_domain_suffixes', []);
        foreach ($blockedSuffixes as $suffix) {
            $suffix = strtolower(ltrim(trim((string) $suffix), '.'));
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                throw new InvalidArgumentException('Lokale oder reservierte Connector-Ziele sind im Produktivbetrieb nicht erlaubt.');
            }
        }
    }

    private function normalizeProviderKey(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || ! preg_match('/^[a-z0-9_-]{2,64}$/', $provider)) {
            throw new InvalidArgumentException('Ungültiger Geräteprovider.');
        }

        return $provider;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function providerComparable(array $values): array
    {
        return array_intersect_key($values, array_flip([
            'enabled',
            'subdomain',
            'adapter_port',
            'timeout',
            'remote_url_template',
            'mobile_commands_enabled',
        ]));
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changedFields(array $before, array $after, string $prefix = ''): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));

        return array_values(array_filter(array_map(
            fn (string $key): ?string => ($before[$key] ?? null) !== ($after[$key] ?? null)
                ? $prefix.$key
                : null,
            $keys,
        )));
    }

    /** @param list<string> $changedFields
     * @param  array<string, mixed>  $properties
     */
    private function audit(string $event, array $changedFields, array $properties = []): void
    {
        if ($changedFields === [] && ! ($properties['token_changed'] ?? false) && ! ($properties['webhook_secret_changed'] ?? false)) {
            return;
        }

        $logger = activity('device-management')->event($event);
        if ($actor = auth()->user()) {
            $logger->causedBy($actor);
        }
        $logger
            ->withProperties(array_merge($properties, ['changed_fields' => $changedFields]))
            ->log('Geräteverwaltungseinstellungen geändert');
    }

    /** @param array<string, mixed> $stored */
    private function assertAllActiveEndpointsUnique(array $stored): void
    {
        $deployment = $this->deploymentFrom($stored);
        $endpoints = [];
        foreach ($this->rawProviderCatalog() as $provider => $definition) {
            if ($provider === 'simulation') {
                continue;
            }
            $saved = is_array($stored['providers'][$provider] ?? null) ? $stored['providers'][$provider] : [];
            if (! $this->strictBoolean($saved['enabled'] ?? $definition['enabled'] ?? false, false)) {
                continue;
            }
            if ($deployment['mode'] === 'port') {
                $port = $this->safeBoundedInteger(
                    $saved['adapter_port'] ?? $definition['adapter_port'] ?? 0,
                    $this->limit('adapter_port'),
                    (int) ($definition['adapter_port'] ?? 9443),
                );
                $endpoint = "http://127.0.0.1:{$port}";
                $message = 'Aktive Connectoren müssen unterschiedliche lokale Adapter-Ports verwenden.';
            } else {
                $subdomain = $this->safeSubdomain(
                    $saved['subdomain'] ?? $definition['subdomain'] ?? $provider,
                    (string) ($definition['subdomain'] ?? $provider),
                );
                $endpoint = $this->publicUrl($subdomain, $deployment['base_domain']);
                $message = 'Aktive Connectoren müssen unterschiedliche HTTPS-Subdomains verwenden.';
            }

            if (isset($endpoints[$endpoint])) {
                throw new InvalidArgumentException($message);
            }
            $endpoints[$endpoint] = $provider;
        }
    }
}
