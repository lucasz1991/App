<?php

namespace App\Services\DeviceManagement\OpenUemFork;

use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Services\DeviceManagement\Contracts\DeviceProviderInterface;
use App\Services\DeviceManagement\Data\CommandResult;
use App\Services\DeviceManagement\Data\EnrollmentResult;
use App\Services\DeviceManagement\Data\HealthResult;
use RuntimeException;

final class NativeDeviceProvider implements DeviceProviderInterface
{
    public function __construct(
        private readonly array $configuration,
        private readonly NativeClient $client,
        private readonly CommandBinding $binding,
    ) {}

    public function key(): string
    {
        return 'openuem';
    }

    public function label(): string
    {
        return 'OpenUEM RailTime Fork';
    }

    public function enabled(): bool
    {
        return ($this->configuration['enabled'] ?? false) === true
            && ($this->configuration['adapter'] ?? null) === 'native_fork_v1';
    }

    public function capabilities(): array
    {
        return [
            'platforms' => ['windows'], 'inventory' => false, 'enrollment' => false,
            'remote_support' => false, 'unattended_remote_support' => false,
            'commands' => [DeviceCommandType::ApplyManagedProfile->value], 'readiness_checks' => [],
        ];
    }

    public function supportsPlatform(string $platform): bool
    {
        return $platform === 'windows';
    }

    public function supportsCommand(DeviceCommandType $type): bool
    {
        return $type === DeviceCommandType::ApplyManagedProfile;
    }

    public function health(): HealthResult
    {
        $status = $this->client->health();

        return new HealthResult($status['ready'], $status['ready'] ? 'server_ready' : 'server_unavailable', [
            'version' => $status['protocol'],
            'message' => 'Serverstatus; kein Nachweis einer erfolgreichen Geräteausführung.',
        ]);
    }

    public function enrollment(DeviceEnrollment $enrollment, Device $device): EnrollmentResult
    {
        throw new RuntimeException('Der native OpenUEM-Auftragsadapter führt kein Geräte-Enrollment durch.');
    }

    public function dispatch(DeviceCommand $command, Device $device): CommandResult
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (! is_string($driver) || in_array($driver, ['sync', 'null'], true)) {
            throw new RuntimeException('Native OpenUEM-Aufträge benötigen einen echten asynchronen Geräte-Worker.');
        }
        $reference = $this->binding->reference($command, $device);
        $receipt = $command->result['details'] ?? null;
        if (filled($command->provider_job_id)
            && (! is_array($receipt) || ($receipt['run_id'] ?? null) !== $command->provider_job_id)) {
            throw new RuntimeException('Die native OpenUEM-Auftragsquittung passt nicht zur gespeicherten Run-ID.');
        }
        $status = is_array($receipt) && filled($command->provider_job_id)
            ? $this->client->poll($reference, $receipt)
            : $this->client->dispatch($reference);

        // Always persist the native receipt before the result job reconciles
        // terminal success/failure/uncertainty. Never map accepted -> succeeded.
        return new CommandResult(true, false, $status->runId, 'Nativer OpenUEM-Auftrag gespeichert; Geräteergebnis wird geprüft.', $status->summary());
    }

    public function remoteSupportUrl(Device $device): ?string
    {
        return null;
    }
}
