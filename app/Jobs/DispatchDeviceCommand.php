<?php

namespace App\Jobs;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\DeviceAssignment;
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
            ->select(['id', 'device_id', 'provider', 'status'])
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
        if ((int) $preflight->device_id !== $this->deviceId) {
            throw new RuntimeException('Der Gerätebefehl gehört nicht zum angegebenen Gerät.');
        }
        if (! $providers->commandsEnabledFor((string) $preflight->provider)) {
            throw new RuntimeException('Der Gerätebefehl wurde durch den globalen Kill-Switch gestoppt.');
        }

        $command = DB::transaction(function (): ?DeviceCommand {
            // Shared lock order for every handover-sensitive path:
            // device -> active assignments -> command. Once this transaction
            // changes queued to dispatched, reassignment fails closed until
            // the external operation has reached a terminal state.
            $device = Device::withTrashed()
                ->whereKey($this->deviceId)
                ->lockForUpdate()
                ->first();
            if (! $device) {
                return null;
            }
            $activeAssignments = DeviceAssignment::query()
                ->where('device_id', $device->getKey())
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

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

            if ((int) $locked->device_id !== (int) $device->getKey()) {
                throw new RuntimeException('Der Gerätebefehl gehört nicht zum gesperrten Gerät.');
            }

            if (! in_array($locked->status, [DeviceCommandStatus::Queued, DeviceCommandStatus::Dispatched], true)) {
                throw new RuntimeException('Der Gerätebefehl ist nicht versandbereit.');
            }
            $activeAssignment = $activeAssignments->count() === 1
                ? $activeAssignments->first()
                : null;
            $assignmentMatches = $activeAssignments->count() <= 1
                && ($locked->device_assignment_id === null
                    ? $activeAssignment === null
                    : ($activeAssignment !== null
                        && (int) $locked->device_assignment_id === (int) $activeAssignment->getKey()));
            if ($device->trashed() || ! $assignmentMatches) {
                $this->cancelStaleCommand($locked, 'Die Gerätezuweisung hat sich vor dem externen Versand geändert.');

                return null;
            }
            if ($locked->type === DeviceCommandType::ApplyProfile
                && ! $this->profilePayloadIsCurrent($locked, $activeAssignment)) {
                $this->cancelStaleCommand($locked, 'Das Kontenprofil gehört nicht mehr zur aktuellen Geräteübergabe.');

                return null;
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

    private function profilePayloadIsCurrent(
        DeviceCommand $command,
        ?DeviceAssignment $activeAssignment,
    ): bool {
        if (! $activeAssignment) {
            return false;
        }

        $profiles = $command->payload['profiles'] ?? null;
        if (! is_array($profiles) || ! array_is_list($profiles) || $profiles === [] || count($profiles) > 30) {
            return false;
        }

        $ids = collect($profiles)
            ->map(fn (mixed $profile): int => is_array($profile)
                && (is_int($profile['assignment_id'] ?? null)
                    || (is_string($profile['assignment_id'] ?? null) && ctype_digit($profile['assignment_id'])))
                    ? (int) $profile['assignment_id']
                    : 0)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->count() !== count($profiles)) {
            return false;
        }

        $assignments = DeviceAccountAssignment::query()
            ->with(['provisioningProfile', 'identityAccount'])
            ->where('device_id', $command->device_id)
            ->where('user_id', $activeAssignment->user_id)
            ->where('desired_state', 'assigned')
            ->whereIn('status', ['pending', 'pending_provider', 'queued', 'error'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        if ($assignments->count() !== $ids->count()) {
            return false;
        }

        $expected = $ids->map(function (int $id) use ($assignments): array {
            $assignment = $assignments->get($id);
            $identityProvider = $assignment->identityAccount?->provider;

            return [
                'assignment_id' => $assignment->id,
                'profile_public_id' => $assignment->provisioningProfile?->public_id,
                'profile_version' => $assignment->provisioningProfile?->version,
                'identity_provider' => $identityProvider instanceof \BackedEnum
                    ? $identityProvider->value
                    : $identityProvider,
                'principal' => $assignment->identityAccount?->principal,
            ];
        })->values()->all();

        return $command->payload === ['profiles' => $expected];
    }

    private function cancelStaleCommand(DeviceCommand $command, string $reason): void
    {
        $command->forceFill([
            'status' => DeviceCommandStatus::Cancelled,
            'completed_at' => now(),
            'error' => $reason,
        ])->save();

        activity('device-management')
            ->performedOn($command)
            ->causedBy($command->requester)
            ->event('device-command.cancelled-stale-assignment')
            ->withProperties([
                'command_id' => (string) $command->public_id,
                'device_id' => (string) $command->device?->public_id,
                'provider' => (string) $command->provider,
                'type' => $command->type->value,
            ])
            ->log('Gerätebefehl wegen geänderter Zuweisung abgebrochen');
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
