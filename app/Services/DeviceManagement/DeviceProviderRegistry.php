<?php

namespace App\Services\DeviceManagement;

use App\Services\DeviceManagement\Contracts\DeviceProviderInterface;
use App\Services\DeviceManagement\Providers\ConnectorDeviceProvider;
use App\Services\DeviceManagement\Providers\SimulationDeviceProvider;
use InvalidArgumentException;

final class DeviceProviderRegistry
{
    /** @var array<string, DeviceProviderInterface> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $fingerprints = [];

    public function __construct(
        private readonly ConnectorHttpClient $http,
        private readonly DeviceManagementSettings $settings,
    ) {}

    public function get(string $provider, bool $fresh = false): DeviceProviderInterface
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || ! preg_match('/^[a-z0-9_-]{2,64}$/', $provider)) {
            throw new InvalidArgumentException('Ungültiger Geräteprovider.');
        }

        $configuration = $this->settings->providerRuntime($provider, $fresh);
        $fingerprint = hash('sha256', serialize($configuration));

        if (! isset($this->instances[$provider])
            || ! hash_equals($this->fingerprints[$provider] ?? '', $fingerprint)) {
            $this->instances[$provider] = $provider === 'simulation'
            ? new SimulationDeviceProvider($configuration)
            : new ConnectorDeviceProvider(
                $provider,
                $configuration,
                $this->http,
                $this->settings,
            );
            $this->fingerprints[$provider] = $fingerprint;
        }

        return $this->instances[$provider];
    }

    /** @return array<string, DeviceProviderInterface> */
    public function enabled(): array
    {
        $providers = [];
        foreach (array_keys($this->settings->providerCatalog()) as $key) {
            $provider = $this->get((string) $key);
            if ($provider->enabled()) {
                $providers[$provider->key()] = $provider;
            }
        }

        return $providers;
    }

    public function webhookSecret(string $provider): string
    {
        $provider = strtolower(trim($provider));
        $runtime = $this->settings->providerRuntime($provider, fresh: true);

        return trim((string) ($runtime['webhook_secret'] ?? ''));
    }

    public function commandsEnabledFor(string $provider): bool
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'simulation') {
            return $this->get($provider)->enabled() && app()->environment(['local', 'testing']);
        }

        // The kill switch is deliberately read uncached immediately before a
        // command is accepted or dispatched. A queued job therefore observes
        // an emergency shutdown without requiring a worker restart.
        $commandsEnabled = $this->settings->productionMutationsEnabledFor($provider);

        return $this->get($provider)->enabled() && $commandsEnabled;
    }

    public function forget(?string $provider = null): void
    {
        if ($provider === null) {
            $this->instances = [];
            $this->fingerprints = [];

            return;
        }

        $provider = strtolower(trim($provider));
        unset($this->instances[$provider], $this->fingerprints[$provider]);
    }
}
