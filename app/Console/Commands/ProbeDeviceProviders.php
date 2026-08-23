<?php

namespace App\Console\Commands;

use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProviderDiagnosticsService;
use Illuminate\Console\Command;
use Throwable;

final class ProbeDeviceProviders extends Command
{
    protected $signature = 'devices:probe-providers {--provider= : Nur einen konfigurierten Provider prüfen}';

    protected $description = 'Refresh the read-only health evidence for enabled external device connectors';

    public function handle(
        DeviceManagementSettings $settings,
        DeviceProviderDiagnosticsService $diagnostics,
    ): int {
        $selected = strtolower(trim((string) $this->option('provider')));
        $providers = collect($settings->allProviderRuntime(fresh: true))
            ->reject(fn (array $runtime, string $key): bool => $key === 'simulation')
            ->filter(fn (array $runtime, string $key): bool => ($runtime['enabled'] ?? false) === true
                && ($selected === '' || $key === $selected));

        if ($selected !== '' && $providers->isEmpty()) {
            $this->components->error('Der gewählte Provider ist unbekannt oder nicht aktiviert.');

            return self::INVALID;
        }

        $failed = 0;
        foreach ($providers as $provider => $runtime) {
            try {
                $result = $diagnostics->probe((string) $provider);
                $healthy = ($result['healthy'] ?? false) === true;
                $this->components->twoColumnDetail(
                    (string) ($runtime['label'] ?? $provider),
                    $healthy ? '<fg=green>healthy</>' : '<fg=red>'.(string) ($result['status'] ?? 'unhealthy').'</>',
                );
                if (! $healthy) {
                    $failed++;
                }
            } catch (Throwable) {
                // Diagnostics already redact and persist only bounded,
                // secret-free evidence. Console output stays equally terse.
                $this->components->twoColumnDetail(
                    (string) ($runtime['label'] ?? $provider),
                    '<fg=red>probe_failed</>',
                );
                $failed++;
            }
        }

        if ($providers->isEmpty()) {
            $this->components->info('Keine aktiven externen Geräteconnectoren konfiguriert.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
