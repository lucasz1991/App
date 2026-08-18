<?php

namespace App\Jobs;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Models\DeviceCommand;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProviderRegistry;
use App\Services\DeviceManagement\Support\SafeProviderData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class DispatchDeviceCommand implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 35;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $commandId,
        public readonly int $deviceId,
    ) {
        $this->onQueue(app(DeviceManagementSettings::class)->queue());
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('device-command-device-'.$this->deviceId))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(DeviceProviderRegistry $providers): void
    {
        // Perform the uncached provider/kill-switch preflight before changing
        // state or writing the "dispatched" audit entry. A job that was
        // already queued when operations were disabled must remain queued and
        // must not look as though it reached an external connector.
        $preflight = DeviceCommand::query()
            ->select(['id', 'provider', 'status'])
            ->find($this->commandId);
        if (! $preflight || in_array($preflight->status, [
            DeviceCommandStatus::Succeeded,
            DeviceCommandStatus::Failed,
            DeviceCommandStatus::Rejected,
            DeviceCommandStatus::Cancelled,
            DeviceCommandStatus::Expired,
        ], true)) {
            return;
        }
        if (! in_array($preflight->status, [DeviceCommandStatus::Queued, DeviceCommandStatus::Dispatched], true)) {
            throw new RuntimeException('Der Gerätebefehl ist nicht versandbereit.');
        }
        if (! $providers->commandsEnabledFor((string) $preflight->provider)) {
            throw new RuntimeException('Der Gerätebefehl wurde durch den globalen Kill-Switch gestoppt.');
        }

        $command = DB::transaction(function (): ?DeviceCommand {
            $locked = DeviceCommand::query()
                ->with('device')
                ->lockForUpdate()
                ->find($this->commandId);

            if (! $locked || in_array($locked->status, [
                DeviceCommandStatus::Succeeded,
                DeviceCommandStatus::Failed,
                DeviceCommandStatus::Rejected,
                DeviceCommandStatus::Cancelled,
                DeviceCommandStatus::Expired,
            ], true)) {
                return null;
            }

            if (! in_array($locked->status, [DeviceCommandStatus::Queued, DeviceCommandStatus::Dispatched], true)) {
                throw new RuntimeException('Der Gerätebefehl ist nicht versandbereit.');
            }
            if ($locked->type === DeviceCommandType::Wipe
                && (! $locked->approved_by || (int) $locked->approved_by === (int) $locked->requested_by)) {
                throw new RuntimeException('Fernlöschung hat keine gültige Vier-Augen-Freigabe.');
            }

            if ($locked->status === DeviceCommandStatus::Queued) {
                $locked->forceFill([
                    'status' => DeviceCommandStatus::Dispatched,
                    'dispatched_at' => now(),
                ])->save();

                activity('device-management')
                    ->performedOn($locked)
                    ->causedBy($locked->requester)
                    ->event('device-command.dispatched')
                    ->withProperties([
                        'command_id' => (string) $locked->public_id,
                        'device_id' => (string) $locked->device?->public_id,
                        'provider' => (string) $locked->provider,
                        'type' => $locked->type->value,
                    ])
                    ->log('Gerätebefehl an Connector übergeben');
            }

            return $locked;
        });

        if (! $command) {
            return;
        }

        if (! $providers->commandsEnabledFor((string) $command->provider)) {
            throw new RuntimeException('Der Gerätebefehl wurde durch den globalen Kill-Switch gestoppt.');
        }

        $provider = $providers->get((string) $command->provider);
        if (! $provider->supportsCommand($command->type)) {
            throw new RuntimeException('Der konfigurierte Provider unterstützt diesen Gerätebefehl nicht.');
        }

        // The stable correlation_id is the connector idempotency key. A queue
        // retry therefore repeats the same logical command, never a new one.
        $result = $provider->dispatch($command, $command->device);

        DB::transaction(function () use ($command, $result): void {
            $locked = DeviceCommand::query()->lockForUpdate()->find($command->getKey());
            if (! $locked || ! in_array($locked->status, [DeviceCommandStatus::Dispatched, DeviceCommandStatus::Running], true)) {
                return;
            }

            $resultData = $result->toSanitizedArray();
            $currentResult = is_array($locked->result) ? $locked->result : [];
            if (is_array($currentResult['processed_event_ids'] ?? null)) {
                $resultData['processed_event_ids'] = $currentResult['processed_event_ids'];
            }

            $locked->forceFill([
                'status' => $result->completed
                    ? DeviceCommandStatus::Succeeded
                    : ($locked->status === DeviceCommandStatus::Running ? DeviceCommandStatus::Running : DeviceCommandStatus::Dispatched),
                'provider_job_id' => $result->providerJobId ?: $locked->provider_job_id,
                'result' => $resultData,
                'completed_at' => $result->completed ? now() : null,
                'error' => null,
            ])->save();

            activity('device-management')
                ->performedOn($locked)
                ->causedBy($locked->requester)
                ->event($result->completed ? 'device-command.succeeded' : 'device-command.accepted')
                ->withProperties([
                    'command_id' => (string) $locked->public_id,
                    'device_id' => (string) $locked->device?->public_id,
                    'provider' => (string) $locked->provider,
                    'type' => $locked->type->value,
                    'completed' => $result->completed,
                ])
                ->log($result->completed ? 'Gerätebefehl abgeschlossen' : 'Gerätebefehl vom Connector angenommen');
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $command = DeviceCommand::query()->lockForUpdate()->find($this->commandId);
            if (! $command || in_array($command->status, [
                DeviceCommandStatus::Succeeded,
                DeviceCommandStatus::Failed,
                DeviceCommandStatus::Cancelled,
                DeviceCommandStatus::Rejected,
                DeviceCommandStatus::Expired,
            ], true)) {
                return;
            }

            $command->forceFill([
                'status' => DeviceCommandStatus::Failed,
                'completed_at' => now(),
                'error' => SafeProviderData::error($exception?->getMessage() ?: 'Der Connector-Auftrag ist fehlgeschlagen.'),
            ])->save();

            activity('device-management')
                ->performedOn($command)
                ->causedBy($command->requester)
                ->event('device-command.failed')
                ->withProperties([
                    'command_id' => (string) $command->public_id,
                    'device_id' => (string) $command->device?->public_id,
                    'provider' => (string) $command->provider,
                    'type' => $command->type->value,
                ])
                ->log('Gerätebefehl fehlgeschlagen');
        });
    }
}
