<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Jobs\DispatchDeviceCommand;
use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\DeviceArtifact;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceProviderLink;
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
        $providerLink = $device->providerLinkFor($providerKey);
        if (! $providerLink
            || ($providerKey !== 'simulation' && $providerLink->status !== DeviceProviderLink::STATUS_ACTIVE)
            || ($providerKey === 'simulation' && $providerLink->status === DeviceProviderLink::STATUS_DISABLED)) {
            throw ValidationException::withMessages([
                'provider' => 'Dieses Gerät ist nicht aktiv mit dem gewählten Provider verknüpft.',
            ]);
        }
        if (! $this->providers->commandsEnabledFor($providerKey)) {
            throw ValidationException::withMessages([
                'provider' => 'Gerätebefehle sind für diesen Provider durch den Sicherheits-Schalter deaktiviert.',
            ]);
        }

        return DB::transaction(function () use ($device, $providerKey, $type, $actor, $justification, $payload): DeviceCommand {
            $lockedDevice = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $activeAssignment = $this->lockCurrentAssignment($lockedDevice);
            $providerLink = $lockedDevice->providerLinkFor($providerKey);
            if (! $providerLink
                || ($providerKey !== 'simulation' && $providerLink->status !== DeviceProviderLink::STATUS_ACTIVE)
                || ($providerKey === 'simulation' && $providerLink->status === DeviceProviderLink::STATUS_DISABLED)) {
                throw ValidationException::withMessages([
                    'provider' => 'Die aktive Provider-Verknüpfung hat sich geändert. Bitte die Ansicht neu laden.',
                ]);
            }

            if (in_array($type, [DeviceCommandType::ExecuteScript, DeviceCommandType::InstallSoftware], true)) {
                $payload = $this->authoritativeArtifactPayload($lockedDevice, $type, $payload);
            } elseif ($type === DeviceCommandType::ApplyProfile) {
                $payload = $this->authoritativeProfilePayload($lockedDevice, $payload, $activeAssignment);
            } elseif (array_intersect(array_keys($payload), [
                'artifact_public_id',
                'artifact_sha256',
                'artifact_kind',
            ]) !== []) {
                throw ValidationException::withMessages([
                    'payload' => 'Dieser Gerätebefehl darf kein Datei-Artefakt enthalten.',
                ]);
            }

            $needsApproval = $type === DeviceCommandType::Wipe;
            $command = DeviceCommand::query()->create([
                'device_id' => $lockedDevice->getKey(),
                'device_assignment_id' => $activeAssignment?->getKey(),
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
                DispatchDeviceCommand::dispatch($command->getKey(), $lockedDevice->getKey())->afterCommit();
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

        $snapshot = DeviceCommand::query()
            ->select(['id', 'device_id'])
            ->findOrFail($command->getKey());

        $approved = DB::transaction(function () use ($snapshot, $approver): ?DeviceCommand {
            $device = Device::query()->whereKey($snapshot->device_id)->lockForUpdate()->firstOrFail();
            $activeAssignment = $this->lockCurrentAssignment($device);
            $locked = DeviceCommand::query()->lockForUpdate()->findOrFail($snapshot->getKey());
            if ((int) $locked->device_id !== (int) $device->getKey()) {
                throw ValidationException::withMessages(['approval' => 'Der Gerätekontext des Befehls ist ungültig.']);
            }
            if ($locked->type !== DeviceCommandType::Wipe || $locked->status !== DeviceCommandStatus::PendingApproval) {
                throw ValidationException::withMessages(['approval' => 'Dieser Befehl wartet nicht auf eine Löschfreigabe.']);
            }
            if (! $this->assignmentContextMatches($locked, $activeAssignment)) {
                $this->cancelStaleCommand($locked, $approver);

                return null;
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
            $providerLink = $device->providerLinkFor((string) $locked->provider);
            if (! $providerLink
                || ((string) $locked->provider !== 'simulation' && $providerLink->status !== DeviceProviderLink::STATUS_ACTIVE)
                || ((string) $locked->provider === 'simulation' && $providerLink->status === DeviceProviderLink::STATUS_DISABLED)) {
                throw ValidationException::withMessages(['approval' => 'Die Provider-Verknüpfung des Geräts ist nicht mehr aktiv.']);
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

        if (! $approved) {
            throw ValidationException::withMessages([
                'approval' => 'Die Gerätezuweisung hat sich seit der Anforderung geändert. Der Löschbefehl wurde sicher abgebrochen.',
            ]);
        }

        return $approved;
    }

    /**
     * Cancel every command that has not left RailTime before a handover
     * changes. Once a command was dispatched, the assignment transition must
     * stop because an external side effect can no longer be ruled out.
     */
    public function cancelPendingCommandsForAssignmentChange(Device $device, User $actor): int
    {
        return DB::transaction(function () use ($device, $actor): int {
            $lockedDevice = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $this->lockCurrentAssignment($lockedDevice);
            $commands = DeviceCommand::query()
                ->where('device_id', $lockedDevice->getKey())
                ->whereIn('status', [
                    DeviceCommandStatus::PendingApproval->value,
                    DeviceCommandStatus::Approved->value,
                    DeviceCommandStatus::Queued->value,
                    DeviceCommandStatus::Dispatched->value,
                    DeviceCommandStatus::Running->value,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $externallyUncertain = $commands->first(fn (DeviceCommand $command): bool => in_array(
                $command->status,
                [DeviceCommandStatus::Dispatched, DeviceCommandStatus::Running],
                true,
            ));
            if ($externallyUncertain) {
                throw ValidationException::withMessages([
                    'assignment' => 'Die Gerätezuweisung kann nicht geändert werden, solange ein bereits versandter Gerätebefehl läuft.',
                ]);
            }

            foreach ($commands as $command) {
                $this->cancelStaleCommand($command, $actor);
            }

            return $commands->count();
        });
    }

    /**
     * Compatibility bridge until every inventory caller uses the broader API.
     */
    public function cancelPendingProfileCommands(Device $device, User $actor): int
    {
        return $this->cancelPendingCommandsForAssignmentChange($device, $actor);
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

    /**
     * Rebuild executable artifact input from the private RailTime record. A
     * caller cannot turn a document into a script, reuse another device's
     * upload or substitute a stale hash between approval and dispatch.
     *
     * @param  array<string, mixed>  $payload
     * @return array{artifact_public_id: string, artifact_sha256: string, artifact_kind: string}
     */
    private function authoritativeArtifactPayload(
        Device $device,
        DeviceCommandType $type,
        array $payload,
    ): array {
        $allowedKeys = ['artifact_public_id', 'artifact_sha256', 'artifact_kind'];
        if (array_diff(array_keys($payload), $allowedKeys) !== []
            || ! is_string($payload['artifact_public_id'] ?? null)
            || ! is_string($payload['artifact_sha256'] ?? null)
            || ! is_string($payload['artifact_kind'] ?? null)) {
            throw ValidationException::withMessages([
                'payload' => 'Für diese Aktion ist ein vollständig referenziertes, freigegebenes Artefakt erforderlich.',
            ]);
        }

        $artifact = DeviceArtifact::query()
            ->where('public_id', trim($payload['artifact_public_id']))
            ->where('device_id', $device->getKey())
            ->whereNotNull('approved_at')
            ->first();
        $expectedKind = $type === DeviceCommandType::ExecuteScript ? 'script' : 'software';
        $devicePlatform = $device->platform instanceof \BackedEnum
            ? (string) $device->platform->value
            : (string) $device->platform;
        $artifactPlatform = $artifact?->platform instanceof \BackedEnum
            ? (string) $artifact->platform->value
            : (string) $artifact?->platform;

        if (! $artifact
            || $artifact->kind !== $expectedKind
            || $artifactPlatform !== $devicePlatform
            || ! hash_equals((string) $artifact->sha256, strtolower(trim($payload['artifact_sha256'])))
            || ! hash_equals((string) $artifact->kind, trim($payload['artifact_kind']))) {
            throw ValidationException::withMessages([
                'payload' => 'Das freigegebene Artefakt gehört nicht zu Gerät, Plattform oder Aktion oder wurde verändert.',
            ]);
        }

        return [
            'artifact_public_id' => (string) $artifact->public_id,
            'artifact_sha256' => (string) $artifact->sha256,
            'artifact_kind' => (string) $artifact->kind,
        ];
    }

    /**
     * Rebuild profile command input from current database state. The caller
     * may select assignment IDs, but cannot supply another employee's UPN or
     * stale profile metadata to the connector.
     *
     * @param  array<string, mixed>  $payload
     * @return array{profiles: list<array<string, mixed>>}
     */
    private function authoritativeProfilePayload(
        Device $device,
        array $payload,
        ?DeviceAssignment $activeAssignment,
    ): array {
        $requested = $payload['profiles'] ?? null;
        if (! is_array($requested) || ! array_is_list($requested) || $requested === [] || count($requested) > 30) {
            throw ValidationException::withMessages([
                'payload' => 'Für die Profilanwendung sind aktuelle Profilzuordnungen erforderlich.',
            ]);
        }

        $ids = collect($requested)
            ->map(fn (mixed $profile): int => is_array($profile)
                && (is_int($profile['assignment_id'] ?? null) || (is_string($profile['assignment_id'] ?? null) && ctype_digit($profile['assignment_id'])))
                    ? (int) $profile['assignment_id']
                    : 0)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->count() !== count($requested)) {
            throw ValidationException::withMessages([
                'payload' => 'Die Profilzuordnungen sind ungültig oder doppelt enthalten.',
            ]);
        }

        $activeUserId = $activeAssignment?->user_id;
        if (! $activeUserId) {
            throw ValidationException::withMessages([
                'payload' => 'Ohne aktive Mitarbeiterzuweisung dürfen keine Kontenprofile angewendet werden.',
            ]);
        }

        $assignments = DeviceAccountAssignment::query()
            ->with(['provisioningProfile', 'identityAccount'])
            ->where('device_id', $device->getKey())
            ->where('user_id', $activeUserId)
            ->where('desired_state', 'assigned')
            ->whereIn('status', ['pending', 'pending_provider', 'queued', 'error'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        if ($assignments->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'payload' => 'Mindestens eine Profilzuordnung gehört nicht mehr zur aktuellen Geräteübergabe.',
            ]);
        }

        return [
            'profiles' => $ids->map(function (int $id) use ($assignments): array {
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
            })->values()->all(),
        ];
    }

    private function lockCurrentAssignment(Device $device): ?DeviceAssignment
    {
        $assignments = DeviceAssignment::query()
            ->where('device_id', $device->getKey())
            ->active()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($assignments->count() > 1) {
            throw ValidationException::withMessages([
                'assignment' => 'Das Gerät besitzt mehrere aktive Mitarbeiterzuweisungen. Gerätebefehle bleiben gesperrt.',
            ]);
        }

        return $assignments->first();
    }

    private function assignmentContextMatches(DeviceCommand $command, ?DeviceAssignment $activeAssignment): bool
    {
        if ($command->device_assignment_id === null) {
            return $activeAssignment === null;
        }

        return $activeAssignment !== null
            && (int) $command->device_assignment_id === (int) $activeAssignment->getKey();
    }

    private function cancelStaleCommand(DeviceCommand $command, User $actor): void
    {
        $command->forceFill([
            'status' => DeviceCommandStatus::Cancelled,
            'completed_at' => now(),
            'error' => 'Vor Versand wegen geänderter Gerätezuweisung sicher abgebrochen.',
        ])->save();
        $this->audit($command, $actor, 'cancelled-stale-assignment');
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
