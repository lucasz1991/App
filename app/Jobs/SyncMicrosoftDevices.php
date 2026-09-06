<?php

namespace App\Jobs;

use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

final class SyncMicrosoftDevices implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONNECTION = 'microsoft_devices';

    // Plesk queue identifiers only allow Latin letters, digits and underscores.
    public const QUEUE = 'microsoft_devices';

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $configurationFingerprint,
        public readonly string $reservation,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    public function handle(
        MicrosoftDeviceSettings $settings,
        MicrosoftDeviceSyncService $sync,
        MicrosoftDeviceRuntime $runtime,
    ): void {
        // Old pre-ledger payloads and repeated or direct execution fail closed.
        // reservation is retained as the serialized field for deployment compatibility.
        if (! $runtime->claim($this->reservation, $this->job ? (string) $this->job->getJobId() : null, 'sync', $this->job instanceof DatabaseJob ? $this->job : null)) {
            return;
        }
        try {
            $snapshot = $settings->snapshot();
            $configuration = $snapshot['configuration'];
            if (! ($configuration['enabled'] ?? false)
                || ! hash_equals($this->tenantId, strtolower((string) ($configuration['tenant_id'] ?? '')))
                || ! hash_equals($this->configurationFingerprint, $snapshot['fingerprint'])) {
                $runtime->finish($this->reservation, 'stale_configuration');

                return;
            }

            $result = $sync->sync(expectedFingerprint: $this->configurationFingerprint);
            $runtime->finish($this->reservation, (string) ($result['status'] ?? 'failed'));
        } catch (Throwable $exception) {
            $runtime->fail($this->reservation);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MicrosoftDeviceRuntime::class)->fail($this->reservation, $exception instanceof TimeoutExceededException);
    }
}
