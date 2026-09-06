<?php

namespace App\Services\DeviceManagement\OpenUemFork;

use App\Services\DeviceManagement\ConnectorHttpClient;
use App\Services\DeviceManagement\DeviceManagementSettings;
use RuntimeException;

/** Opt-in native API. Never retries through the incompatible legacy connector. */
final class NativeClient
{
    public const HEALTH_PATH = '/railtime/v1/health';

    public const RUNS_PATH = '/railtime/v1/runs';

    public function __construct(
        private readonly ConnectorHttpClient $http,
        private readonly DeviceManagementSettings $settings,
    ) {}

    /** Server health does not attest enrollment or successful execution on any device. */
    public function health(): array
    {
        $response = $this->http->request('openuem', $this->configuration(), 'GET', self::HEALTH_PATH);
        if (($response['protocol'] ?? null) !== RunStatus::VERSION
            || ! is_bool($response['ready'] ?? null)
            || ! is_bool($response['storage_ready'] ?? null)
            || ! is_bool($response['broker_ready'] ?? null)
            || ($response['capabilities'] ?? null) !== ['profile_runs_v1']) {
            throw new RuntimeException('Der native OpenUEM-Server meldete einen inkompatiblen Vertrag.');
        }

        return [
            'protocol' => RunStatus::VERSION,
            'ready' => $response['ready'] && $response['storage_ready'] && $response['broker_ready'],
            'storage_ready' => $response['storage_ready'],
            'broker_ready' => $response['broker_ready'],
        ];
    }

    public function dispatch(RunReference $reference): RunStatus
    {
        $configuration = $this->configuration();
        if (! $this->settings->productionMutationsEnabledFor('openuem')) {
            throw new RuntimeException('Externe OpenUEM-Befehle sind bis zum Funktionstest und der Produktionsfreigabe gesperrt.');
        }

        return RunStatus::fromResponse(
            $this->http->request('openuem', $configuration, 'POST', self::RUNS_PATH, $reference->payload()),
            $reference,
        );
    }

    /** @param array<string, mixed> $receipt Persisted server-issued receipt from dispatch. */
    public function poll(RunReference $reference, array $receipt): RunStatus
    {
        $runId = $receipt['run_id'] ?? null;
        if (! RunReference::isUuid($runId) || ! RunReference::isDigest($receipt['payload_sha256'] ?? null)) {
            throw new RuntimeException('Für die Statusabfrage fehlt eine gültige OpenUEM-Auftragsquittung.');
        }
        foreach ($reference->payload() as $key => $expected) {
            if (($receipt[$key] ?? null) !== $expected) {
                throw new RuntimeException('Die gespeicherte OpenUEM-Auftragsquittung gehört zu einem anderen Auftrag.');
            }
        }

        // Reading an already accepted command remains possible with the global
        // mutation kill switch OFF, and never submits the command a second time.
        return RunStatus::fromResponse(
            $this->http->request('openuem', $this->configuration(), 'GET', self::RUNS_PATH.'/'.$runId),
            $reference,
            $receipt,
        );
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $configuration = $this->settings->providerRuntime('openuem', fresh: true);
        if (($configuration['enabled'] ?? false) !== true || ($configuration['configuration_error'] ?? true) !== false) {
            throw new RuntimeException('Der OpenUEM-Provider ist deaktiviert oder nicht sicher konfiguriert.');
        }
        if (($configuration['paths']['health'] ?? null) !== self::HEALTH_PATH
            || ($configuration['paths']['command'] ?? null) !== self::RUNS_PATH
            || ($configuration['adapter'] ?? null) !== 'native_fork_v1') {
            throw new RuntimeException('Der native OpenUEM-Ausführungsvertrag ist für diesen Provider nicht freigeschaltet.');
        }

        return $configuration;
    }
}
