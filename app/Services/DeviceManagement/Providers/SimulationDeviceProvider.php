<?php

namespace App\Services\DeviceManagement\Providers;

use App\Enums\DeviceCommandType;
use App\Enums\DeviceEnrollmentStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Services\DeviceManagement\Contracts\DeviceProviderInterface;
use App\Services\DeviceManagement\Data\CommandResult;
use App\Services\DeviceManagement\Data\EnrollmentResult;
use App\Services\DeviceManagement\Data\HealthResult;
use RuntimeException;

final class SimulationDeviceProvider implements DeviceProviderInterface
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private readonly array $configuration) {}

    public function key(): string
    {
        return 'simulation';
    }

    public function label(): string
    {
        return (string) ($this->configuration['label'] ?? 'RailTime simulation');
    }

    public function enabled(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) ($this->configuration['enabled'] ?? false);
    }

    public function capabilities(): array
    {
        return is_array($this->configuration['capabilities'] ?? null)
            ? $this->configuration['capabilities']
            : [];
    }

    public function supportsPlatform(string $platform): bool
    {
        return in_array(strtolower($platform), $this->capabilities()['platforms'] ?? [], true);
    }

    public function supportsCommand(DeviceCommandType $type): bool
    {
        return in_array($type->value, $this->capabilities()['commands'] ?? [], true);
    }

    public function health(): HealthResult
    {
        $this->assertEnabled();

        return new HealthResult(true, 'simulated', ['version' => 'railtime-simulation-v1']);
    }

    public function enrollment(DeviceEnrollment $enrollment, Device $device): EnrollmentResult
    {
        $this->assertEnabled();
        if ($enrollment->status !== DeviceEnrollmentStatus::Claimed || ! $enrollment->claimed_at) {
            throw new RuntimeException('Enrollment-Anweisungen werden erst nach einem atomischen Claim ausgegeben.');
        }

        return new EnrollmentResult(
            status: 'simulated',
            steps: [
                'RailTime-Testregistrierung öffnen.',
                'Simuliertes Verwaltungsprofil bestätigen.',
                'Zur Geräteübersicht zurückkehren.',
            ],
            enrollmentUrl: route('devices.mine', ['simulation' => $enrollment->public_id]),
            expiresAt: $enrollment->expires_at?->toIso8601String(),
            limitedManagement: false,
            message: 'Es wurde kein externes System angesprochen.',
        );
    }

    public function dispatch(DeviceCommand $command, Device $device): CommandResult
    {
        $this->assertEnabled();
        if (! $this->supportsCommand($command->type)) {
            throw new RuntimeException('Der Befehl wird von der Simulation nicht unterstützt.');
        }

        return new CommandResult(
            accepted: true,
            completed: true,
            providerJobId: 'sim-'.substr(hash('sha256', (string) $command->correlation_id), 0, 20),
            message: 'Befehl deterministisch simuliert; kein Gerät wurde verändert.',
            details: ['status' => 'simulated'],
        );
    }

    public function remoteSupportUrl(Device $device): ?string
    {
        return null;
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Die Simulation ist nur aktiviert in APP_ENV=local/testing verfügbar.');
        }
    }
}
