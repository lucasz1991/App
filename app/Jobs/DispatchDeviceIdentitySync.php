<?php

namespace App\Jobs;

use App\Services\DeviceManagement\DeviceIdentityConnectorService;
use App\Services\DeviceManagement\DeviceIdentitySyncService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class DispatchDeviceIdentitySync implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 35;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $syncId)
    {
        $this->onQueue(app(DeviceManagementSettings::class)->queue());
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('device-identity-sync-'.$this->syncId))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(
        DeviceIdentitySyncService $syncs,
        DeviceIdentityConnectorService $connector,
    ): void {
        $claimed = $syncs->claimForDispatch($this->syncId);
        if ($claimed === null) {
            return;
        }

        $response = $connector->sync($claimed['payload']);
        if ($response === null) {
            $syncs->block(
                $this->syncId,
                'production_gate_closed',
                'Der Identity-Connector wurde vor dem Versand deaktiviert oder seine Produktionsfreigabe ist nicht mehr aktuell; der Sync wurde nicht versendet.',
            );

            return;
        }

        $syncs->recordResponse($this->syncId, $response);
    }

    public function failed(?Throwable $exception): void
    {
        app(DeviceIdentitySyncService::class)->markFailed($this->syncId, $exception);
    }
}
