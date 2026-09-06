<?php

namespace App\Services\DeviceManagement\OpenUemFork;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Services\DeviceManagement\DeviceManagementSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Read-only remote polling; this job has no path that submits a device command. */
final class PollOpenUemRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 35;

    public array $backoff = [30, 60, 120];

    public function __construct(public readonly int $commandId, public readonly int $deviceId, public readonly int $pollNumber = 0)
    {
        $this->onQueue(app(DeviceManagementSettings::class)->queue());
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('device-command-device-'.$this->deviceId))->releaseAfter(15)->expireAfter(65)];
    }

    public function handle(NativeClient $client, CommandBinding $binding): void
    {
        $command = DeviceCommand::query()->with('device')->find($this->commandId);
        if (! $this->pending($command)) {
            return;
        }
        if (($command->result['details']['provider_status'] ?? null) === 'uncertain') {
            $this->markUncertain();

            return;
        }
        if ($this->pollNumber >= 2880 || $command->requested_at?->isBefore(now()->subDay())) {
            $this->markUncertain();

            return;
        }
        $reference = $binding->reference($command, $command->device, requireAllowedProfile: false);
        $receipt = $command->result['details'] ?? null;
        if (! is_array($receipt) || ($receipt['run_id'] ?? null) !== $command->provider_job_id) {
            throw new RuntimeException('Die native OpenUEM-Auftragsquittung fehlt oder passt nicht zum gespeicherten Auftrag.');
        }
        $status = $client->poll($reference, $receipt);

        $pollAgain = DB::transaction(function () use ($binding, $receipt, $status): bool {
            $device = Device::withTrashed()->whereKey($this->deviceId)->lockForUpdate()->firstOrFail();
            DeviceAssignment::query()->where('device_id', $device->getKey())->active()->orderBy('id')->lockForUpdate()->get();
            $locked = DeviceCommand::query()->lockForUpdate()->find($this->commandId);
            if (! $this->pending($locked)) {
                return false;
            }
            $binding->reference($locked, $device, requireAllowedProfile: false);
            if (($locked->result['details'] ?? null) !== $receipt || $locked->provider_job_id !== $status->runId) {
                throw new RuntimeException('Die gespeicherte OpenUEM-Auftragsquittung wurde während der Statusabfrage geändert.');
            }
            $target = match ($status->status) {
                'succeeded' => DeviceCommandStatus::Succeeded,
                'failed' => DeviceCommandStatus::Failed,
                'accepted', 'uncertain' => DeviceCommandStatus::Running,
                default => $locked->status,
            };
            $error = match ($status->status) {
                'failed' => 'Das native OpenUEM-Profil ist auf dem gebundenen Gerät fehlgeschlagen.',
                'uncertain' => self::uncertainMessage(),
                default => null,
            };
            $locked->forceFill([
                'status' => $target,
                'result' => ['accepted' => true, 'completed' => $status->succeeded(), 'details' => $status->summary()],
                'error' => $error,
                'completed_at' => in_array($target, [DeviceCommandStatus::Succeeded, DeviceCommandStatus::Failed], true) ? now() : null,
            ])->save();
            if ($status->terminal()) {
                activity('device-management')->performedOn($locked)->event('device-command.native-'.$status->status)
                    ->withProperties(['command_id' => (string) $locked->public_id, 'provider' => 'openuem', 'provider_status' => $status->status])
                    ->log('Natives OpenUEM-Geräteergebnis abgeglichen');
            }

            return ! $status->terminal();
        });

        if ($pollAgain) {
            $driver = config('queue.connections.'.config('queue.default').'.driver');
            if (! is_string($driver) || in_array($driver, ['sync', 'null'], true)) {
                throw new RuntimeException('Weitere OpenUEM-Statusabfragen benötigen einen asynchronen Geräte-Worker.');
            }
            self::dispatch($this->commandId, $this->deviceId, $this->pollNumber + 1)->delay(30)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Never copy exception/network bodies into the user-visible command.
        $this->markUncertain();
    }

    private function pending(?DeviceCommand $command): bool
    {
        return $command !== null && (int) $command->device_id === $this->deviceId
            && $command->provider === 'openuem' && $command->type === DeviceCommandType::ApplyManagedProfile
            && in_array($command->status, [DeviceCommandStatus::Dispatched, DeviceCommandStatus::Running], true);
    }

    private function markUncertain(): void
    {
        DB::transaction(function (): void {
            $command = DeviceCommand::query()->lockForUpdate()->find($this->commandId);
            if (! $this->pending($command)) {
                return;
            }
            $alreadyMarked = $command->status === DeviceCommandStatus::Running
                && $command->error === self::uncertainMessage();
            $result = is_array($command->result) ? $command->result : [];
            $result['details'] = is_array($result['details'] ?? null) ? $result['details'] : [];
            $result['details']['provider_status'] = 'uncertain';
            $result['completed'] = false;
            $command->forceFill(['status' => DeviceCommandStatus::Running, 'result' => $result, 'completed_at' => null, 'error' => self::uncertainMessage()])->save();
            if (! $alreadyMarked) {
                activity('device-management')->performedOn($command)->event('device-command.native-uncertain')
                    ->withProperties(['command_id' => (string) $command->public_id, 'provider' => 'openuem', 'provider_status' => 'uncertain'])
                    ->log('Native OpenUEM-Ausführung zur manuellen Prüfung gesperrt');
            }
        });
    }

    private static function uncertainMessage(): string
    {
        return 'OpenUEM-Ausführung unklar. Manuelle Prüfung des bestehenden Auftrags erforderlich; Wiederholung und Geräteübergabe bleiben gesperrt.';
    }
}
