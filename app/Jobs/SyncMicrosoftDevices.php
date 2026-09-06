<?php

namespace App\Jobs;

use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SyncMicrosoftDevices implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONNECTION = 'microsoft_devices';

    public const QUEUE = 'microsoft-devices';

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

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('microsoft-device-sync:'.hash('sha256', $this->tenantId)))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(
        MicrosoftDeviceSettings $settings,
        MicrosoftDeviceSyncService $sync,
        MicrosoftDeviceSyncScheduler $scheduler,
    ): void {
        try {
            $snapshot = $settings->snapshot();
            $configuration = $snapshot['configuration'];
            if (! ($configuration['enabled'] ?? false)
                || ! hash_equals($this->tenantId, strtolower((string) ($configuration['tenant_id'] ?? '')))
                || ! hash_equals($this->configurationFingerprint, $snapshot['fingerprint'])) {
                return;
            }

            $sync->sync();
        } finally {
            $scheduler->release($this->tenantId, $this->reservation);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MicrosoftDeviceSyncScheduler::class)->release($this->tenantId, $this->reservation);
    }
}
