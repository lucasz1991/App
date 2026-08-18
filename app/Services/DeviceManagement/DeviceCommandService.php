<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Jobs\DispatchDeviceCommand;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DeviceCommandService
{
    /** @var list<string> */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'authorization',
        'credential',
        'credentials',
        'password',
        'private_key',
        'recovery_key',
        'secret',
        'token',
    ];

    public function __construct(
        private readonly DeviceProviderRegistry $providers,
        private readonly DeviceManagementSettings $settings,
    ) {}

    /** @param array<string, mixed> $payload */
    public function request(
        Device $device,
        string $providerKey,
        DeviceCommandType|string $type,
        User $actor,
        string $justification,
        array $payload = [],
    ): DeviceCommand {
        $type = $type instanceof DeviceCommandType ? $type : DeviceCommandType::tryFrom($type);
        if (! $type) {
            throw ValidationException::withMessages(['type' => 'Der Gerätebefehl ist ungültig.']);
        }
        $providerKey = strtolower(trim($providerKey));
        Gate::forUser($actor)->authorize('devices.commands.execute');
        $this->authorizeSpecificCommand($type, $actor);
        $this->validateJustification($justification);
        $this->assertSafePayload($payload);

        try {
            $provider = $this->providers->get($providerKey);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['provider' => 'Der gewählte Geräteprovider ist ungültig.']);
        }
        if (! $provider->enabled()) {
            throw ValidationException::withMessages(['provider' => 'Der gewählte Geräteprovider ist deaktiviert.']);
        }

        $platform = $device->platform instanceof \BackedEnum
            ? (string) $device->platform->value
            : (string) $device->platform;
        if (! $provider->supportsPlatform($platform)) {
            throw ValidationException::withMessages(['provider' => 'Der Provider unterstützt die Plattform dieses Geräts nicht.']);
        }
        if (! $provider->supportsCommand($type)) {
            throw ValidationException::withMessages(['type' => 'Dieser Provider unterstützt den gewählten Gerätebefehl nicht.']);
        }
        if (! $this->providers->commandsEnabledFor($providerKey)) {
            throw ValidationException::withMessages([
                'provider' => 'Gerätebefehle sind für diesen Provider durch den Sicherheits-Schalter deaktiviert.',
            ]);
        }

        return DB::transaction(function () use ($device, $providerKey, $type, $actor, $justification, $payload): DeviceCommand {
            Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

            $needsApproval = $type === DeviceCommandType::Wipe;
            $command = DeviceCommand::query()->create([
                'device_id' => $device->getKey(),
                'provider' => $providerKey,
                'type' => $type,
                'status' => $needsApproval ? DeviceCommandStatus::PendingApproval : DeviceCommandStatus::Queued,
                'is_destructive' => $needsApproval,
                'justification' => trim($justification),
                'payload' => $payload === [] ? null : $payload,
                'correlation_id' => (string) Str::uuid(),
                'requested_by' => $actor->getKey(),
                'requested_at' => now(),
            ]);

            $this->audit($command, $actor, $needsApproval ? 'requested-approval' : 'queued');

            if (! $needsApproval) {
                DispatchDeviceCommand::dispatch($command->getKey(), $device->getKey())->afterCommit();
            }

            return $command;
        });
    }

    public function approveWipe(DeviceCommand $command, User $approver): DeviceCommand
    {
        Gate::forUser($approver)->authorize('devices.wipe');
        if (! $approver->isAdmin()) {
            throw ValidationException::withMessages(['approval' => 'Fernlöschung darf nur ein globaler Administrator freigeben.']);
        }

        return DB::transaction(function () use ($command, $approver): DeviceCommand {
            $locked = DeviceCommand::query()->lockForUpdate()->findOrFail($command->getKey());
            if ($locked->type !== DeviceCommandType::Wipe || $locked->status !== DeviceCommandStatus::PendingApproval) {
                throw ValidationException::withMessages(['approval' => 'Dieser Befehl wartet nicht auf eine Löschfreigabe.']);
            }
            if ((int) $locked->requested_by === (int) $approver->getKey()) {
                throw ValidationException::withMessages(['approval' => 'Anforderung und Freigabe müssen von zwei verschiedenen Administratoren stammen.']);
            }
            if (! $locked->requester?->isAdmin()) {
                throw ValidationException::withMessages(['approval' => 'Die Anforderung stammt nicht mehr von einem globalen Administrator.']);
            }
            if (! $this->providers->commandsEnabledFor((string) $locked->provider)) {
                throw ValidationException::withMessages(['approval' => 'Der Provider oder globale Befehls-Schalter ist deaktiviert.']);
            }

            $provider = $this->providers->get((string) $locked->provider);
            if (! $provider->supportsCommand(DeviceCommandType::Wipe)) {
                throw ValidationException::withMessages(['approval' => 'Der Provider unterstützt keine Fernlöschung.']);
            }

            $locked->forceFill([
                'status' => DeviceCommandStatus::Queued,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit($locked, $approver, 'approved-and-queued');
            DispatchDeviceCommand::dispatch($locked->getKey(), $locked->device_id)->afterCommit();

            return $locked;
        });
    }

    private function authorizeSpecificCommand(DeviceCommandType $type, User $actor): void
    {
        if (in_array($type, [DeviceCommandType::Lock, DeviceCommandType::Unlock], true)) {
            Gate::forUser($actor)->authorize('devices.lock');
        }
        if ($type === DeviceCommandType::StartRemoteSupport) {
            Gate::forUser($actor)->authorize('devices.support');
        }
        if ($type === DeviceCommandType::Wipe) {
            Gate::forUser($actor)->authorize('devices.wipe');
            if (! $actor->isAdmin()) {
                throw ValidationException::withMessages(['type' => 'Fernlöschung ist globalen Administratoren vorbehalten.']);
            }
        }
    }

    private function validateJustification(string $justification): void
    {
        $length = mb_strlen(trim($justification));
        if ($length < 10 || $length > 2000) {
            throw ValidationException::withMessages([
                'justification' => 'Die Begründung muss zwischen 10 und 2.000 Zeichen lang sein.',
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertSafePayload(array $payload): void
    {
        $this->assertNoSecretKeys($payload);

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['payload' => 'Die Befehlsdaten sind nicht gültig serialisierbar.']);
        }

        $maximum = $this->settings->maximumRequestBytes();
        if (strlen($json) > $maximum) {
            throw ValidationException::withMessages(['payload' => 'Die Befehlsdaten überschreiten die zulässige Größe.']);
        }
    }

    /** @param array<mixed> $values */
    private function assertNoSecretKeys(array $values, int $depth = 0): void
    {
        if ($depth > 8) {
            throw ValidationException::withMessages(['payload' => 'Die Befehlsdaten sind zu tief verschachtelt.']);
        }

        foreach ($values as $key => $value) {
            $snakeKey = preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key) ?? (string) $key;
            $normalized = strtolower(str_replace(['-', ' '], '_', $snakeKey));
            $forbidden = in_array($normalized, self::FORBIDDEN_PAYLOAD_KEYS, true)
                || preg_match('/(?:^|_)(?:password|secret|token|credentials?|private_key|recovery_key|authorization)(?:$|_)/', $normalized);
            if ($forbidden) {
                throw ValidationException::withMessages([
                    'payload' => 'Zugangsdaten und Wiederherstellungsschlüssel dürfen nicht als Gerätebefehl gespeichert werden.',
                ]);
            }
            if (is_array($value)) {
                $this->assertNoSecretKeys($value, $depth + 1);
            } elseif (! is_scalar($value) && $value !== null) {
                throw ValidationException::withMessages(['payload' => 'Die Befehlsdaten enthalten einen nicht erlaubten Datentyp.']);
            }
        }
    }

    private function audit(DeviceCommand $command, User $actor, string $event): void
    {
        activity('device-management')
            ->performedOn($command)
            ->causedBy($actor)
            ->event('device-command.'.$event)
            ->withProperties([
                'command_id' => (string) $command->public_id,
                'device_id' => (string) $command->device?->public_id,
                'provider' => (string) $command->provider,
                'type' => $command->type->value,
                'status' => $command->status->value,
            ])
            ->log('Gerätebefehl '.$event);
    }
}
