<?php

namespace App\Services\DeviceManagement;

use App\Jobs\DispatchDeviceIdentitySync;
use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\DeviceAssignment;
use App\Models\DeviceIdentitySync;
use App\Models\User;
use App\Services\DeviceManagement\Support\SafeProviderData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DeviceIdentitySyncService
{
    private const ACCOUNT_PROVIDERS = [
        'microsoft_365',
        'google_workspace',
        'apple_managed',
    ];

    public function __construct(private readonly DeviceManagementSettings $settings) {}

    /**
     * @param  list<int>  $accountAssignmentIds
     */
    public function queuePrepared(
        int $deviceId,
        int $employeeId,
        int $actorId,
        array $accountAssignmentIds,
    ): ?DeviceIdentitySync {
        $ids = collect($accountAssignmentIds)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        if ($ids->isEmpty() || $ids->count() > 100) {
            return null;
        }

        $release = $this->connectorRelease();
        $released = $release['released'];
        $sync = DB::transaction(function () use ($deviceId, $employeeId, $actorId, $ids, $released, $release): DeviceIdentitySync {
            $device = Device::query()->lockForUpdate()->findOrFail($deviceId);
            $employee = User::query()->lockForUpdate()->findOrFail($employeeId);
            $assignment = $this->currentAssignment($deviceId, $employeeId, lock: true);
            if (! $assignment || ! $employee->isActive()) {
                throw new RuntimeException('Der Identity-Sync benötigt eine aktuelle aktive Mitarbeiterzuweisung.');
            }

            $lines = $this->currentAccountAssignments($deviceId, $employeeId, $ids->all(), lock: true);
            if ($lines->count() !== $ids->count()) {
                throw new RuntimeException('Der Identity-Sync enthält keine vollständige aktuelle Kontenzuordnung.');
            }

            $accounts = $this->accountsFrom($lines);
            if ($accounts === []) {
                throw new RuntimeException('Der Identity-Sync enthält keine unterstützten Organisationskonten.');
            }

            $profileIds = $lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $deduplicationKey = hash('sha256', json_encode([
                'operation' => 'apply',
                'device_assignment_id' => $assignment->id,
                'accounts' => $accounts,
                'profile_assignment_ids' => $profileIds,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return DeviceIdentitySync::query()->firstOrCreate(
                ['deduplication_key' => $deduplicationKey],
                [
                    'device_id' => $device->id,
                    'device_assignment_id' => $assignment->id,
                    'user_id' => $employee->id,
                    'operation' => 'apply',
                    'status' => $released ? DeviceIdentitySync::STATUS_QUEUED : DeviceIdentitySync::STATUS_BLOCKED,
                    'correlation_id' => (string) Str::uuid(),
                    'account_assignment_ids' => $profileIds,
                    'profile_assignment_ids' => $profileIds,
                    'requested_by' => $actorId,
                    'requested_at' => now(),
                    'last_enqueued_at' => $released ? now() : null,
                    'error_code' => $released ? null : $release['code'],
                    'error_message' => $released ? null : $release['message'],
                ],
            );
        });

        if ($sync->wasRecentlyCreated) {
            $this->audit($sync, 'device-identity-sync.created', $released
                ? 'Identity-Sync zur Übertragung eingeplant'
                : 'Identity-Sync blockiert und nicht versendet');
            if ($released) {
                $this->dispatchAfterCommit($sync->getKey());
            }
        } elseif ($released && in_array($sync->status, [DeviceIdentitySync::STATUS_BLOCKED, DeviceIdentitySync::STATUS_FAILED], true)) {
            $this->retry($sync, User::query()->find($actorId));
        }

        return $sync->fresh();
    }

    /**
     * Persist removal of an old employee's organizational accounts after a
     * return or reassignment. This outbox intentionally survives a closed
     * connector gate; no credential is included and a later retry remains
     * bound to the historical returned assignment and exact profile rows.
     *
     * @param  list<int>  $accountAssignmentIds
     */
    public function queueRevocation(
        int $deviceId,
        int $deviceAssignmentId,
        int $employeeId,
        int $actorId,
        array $accountAssignmentIds,
        bool $assignmentWillReturnInCurrentTransaction = false,
    ): ?DeviceIdentitySync {
        $ids = collect($accountAssignmentIds)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        if ($ids->isEmpty() || $ids->count() > 100) {
            return null;
        }
        if ($assignmentWillReturnInCurrentTransaction && DB::transactionLevel() === 0) {
            throw new RuntimeException('Eine vorgemerkte Kontoentfernung benötigt eine laufende Rückgabe-Transaktion.');
        }

        $release = $this->connectorRelease();
        $released = $release['released'];
        $sync = DB::transaction(function () use (
            $deviceId,
            $deviceAssignmentId,
            $employeeId,
            $actorId,
            $ids,
            $release,
            $released,
            $assignmentWillReturnInCurrentTransaction,
        ): DeviceIdentitySync {
            $device = Device::query()->lockForUpdate()->findOrFail($deviceId);
            $assignment = DeviceAssignment::query()->lockForUpdate()->findOrFail($deviceAssignmentId);
            if ((int) $assignment->device_id !== $deviceId
                || (int) $assignment->user_id !== $employeeId
                || (! in_array($assignment->status, [DeviceAssignment::STATUS_RETURNED], true)
                    && ! ($assignmentWillReturnInCurrentTransaction
                        && $assignment->status === DeviceAssignment::STATUS_ACTIVE))) {
                throw new RuntimeException('Die Kontoentfernung benötigt die zugehörige zurückgegebene Gerätezuweisung.');
            }

            $lines = $this->currentAccountAssignments(
                $deviceId,
                $employeeId,
                $ids->all(),
                lock: true,
                operation: 'revoke',
            );
            if ($lines->count() !== $ids->count()) {
                throw new RuntimeException('Die Kontoentfernung enthält keine vollständige widerrufene Kontenzuordnung.');
            }

            $accounts = $this->accountsFrom($lines, 'revoke');
            if ($accounts === []) {
                throw new RuntimeException('Die Kontoentfernung enthält keine unterstützten Organisationskonten.');
            }
            $profileIds = $lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $deduplicationKey = hash('sha256', json_encode([
                'operation' => 'revoke',
                'device_assignment_id' => $assignment->id,
                'accounts' => $accounts,
                'profile_assignment_ids' => $profileIds,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return DeviceIdentitySync::query()->firstOrCreate(
                ['deduplication_key' => $deduplicationKey],
                [
                    'device_id' => $device->id,
                    'device_assignment_id' => $assignment->id,
                    'user_id' => $employeeId,
                    'operation' => 'revoke',
                    'status' => $released ? DeviceIdentitySync::STATUS_QUEUED : DeviceIdentitySync::STATUS_BLOCKED,
                    'correlation_id' => (string) Str::uuid(),
                    'account_assignment_ids' => $profileIds,
                    'profile_assignment_ids' => $profileIds,
                    'requested_by' => $actorId,
                    'requested_at' => now(),
                    'last_enqueued_at' => $released ? now() : null,
                    'error_code' => $released ? null : $release['code'],
                    'error_message' => $released ? null : $release['message'],
                ],
            );
        });

        if ($sync->wasRecentlyCreated) {
            $this->audit(
                $sync,
                'device-identity-sync.revocation-created',
                $released
                    ? 'Kontoentfernung zur Übertragung eingeplant'
                    : 'Kontoentfernung sicher vorgemerkt und noch nicht versendet',
            );
            if ($released) {
                $this->dispatchAfterCommit($sync->getKey());
            }
        } elseif ($released && in_array($sync->status, [DeviceIdentitySync::STATUS_BLOCKED, DeviceIdentitySync::STATUS_FAILED], true)) {
            $this->retry($sync, User::query()->find($actorId));
        }

        return $sync->fresh();
    }

    public function retry(DeviceIdentitySync $sync, ?User $actor = null): bool
    {
        if ($actor) {
            Gate::forUser($actor)->authorize('devices.accounts.manage');
        }
        $release = $this->connectorRelease();
        if (! $release['released']) {
            $this->block($sync->getKey(), $release['code'], $release['message']);

            return false;
        }

        $queued = DB::transaction(function () use ($sync): ?DeviceIdentitySync {
            $locked = DeviceIdentitySync::query()->lockForUpdate()->find($sync->getKey());
            if (! $locked || ! in_array($locked->status, [DeviceIdentitySync::STATUS_BLOCKED, DeviceIdentitySync::STATUS_FAILED], true)) {
                return null;
            }
            if (! $this->currentContextIsValid($locked)) {
                $locked->forceFill([
                    'status' => DeviceIdentitySync::STATUS_BLOCKED,
                    'error_code' => 'assignment_not_current',
                    'error_message' => 'Der Identity-Sync gehört nicht mehr zur aktuellen aktiven Mitarbeiterzuweisung.',
                ])->save();

                return null;
            }

            $locked->forceFill([
                'status' => DeviceIdentitySync::STATUS_QUEUED,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => null,
                'last_enqueued_at' => now(),
            ])->save();

            return $locked;
        });
        if (! $queued) {
            return false;
        }

        $this->audit($queued, 'device-identity-sync.retry-queued', 'Identity-Sync erneut eingeplant', $actor);
        $this->dispatchAfterCommit($queued->getKey());

        return true;
    }

    /**
     * When an administrator explicitly opens the verified production gate,
     * release only outbox rows that were waiting for configuration/evidence.
     * Rejected provider jobs are not retried implicitly.
     */
    public function releaseGateBlocked(User $actor): int
    {
        Gate::forUser($actor)->authorize('devices.accounts.manage');

        $released = 0;
        DeviceIdentitySync::query()
            ->where('status', DeviceIdentitySync::STATUS_BLOCKED)
            ->whereIn('error_code', [
                'identity_connector_disabled',
                'identity_connector_unavailable',
                'production_gate_closed',
            ])
            ->orderBy('id')
            ->each(function (DeviceIdentitySync $sync) use ($actor, &$released): void {
                if ($this->retry($sync, $actor)) {
                    $released++;
                }
            });

        return $released;
    }

    /**
     * Recover durable outbox rows whose original queue hand-off was lost and
     * release only configuration-blocked rows once the verified production
     * gate is open. A short persistent lease prevents every scheduler run
     * from enqueueing duplicates; the connector's correlation id remains the
     * final idempotency key if a worker was delayed beyond that lease.
     *
     * @return array{gate_released: bool, queued_recovered: int, blocked_released: int, stale_context: int}
     */
    public function recoverPending(int $limit = 100, int $staleMinutes = 10): array
    {
        $limit = max(1, min(500, $limit));
        $staleMinutes = max(1, min(1440, $staleMinutes));
        $release = $this->connectorRelease();
        if (! $release['released']) {
            return [
                'gate_released' => false,
                'queued_recovered' => 0,
                'blocked_released' => 0,
                'stale_context' => 0,
            ];
        }

        $gateErrorCodes = [
            'identity_connector_disabled',
            'identity_connector_unavailable',
            'production_gate_closed',
        ];
        $staleBefore = now()->subMinutes($staleMinutes);
        $candidateIds = DeviceIdentitySync::query()
            ->where(function ($query) use ($gateErrorCodes, $staleBefore): void {
                $query->where(function ($blocked) use ($gateErrorCodes): void {
                    $blocked->where('status', DeviceIdentitySync::STATUS_BLOCKED)
                        ->whereIn('error_code', $gateErrorCodes);
                })->orWhere(function ($queued) use ($staleBefore): void {
                    $queued->where('status', DeviceIdentitySync::STATUS_QUEUED)
                        ->where(function ($lease) use ($staleBefore): void {
                            $lease->whereNull('last_enqueued_at')
                                ->orWhere('last_enqueued_at', '<=', $staleBefore);
                        });
                });
            })
            ->orderBy('requested_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $result = [
            'gate_released' => true,
            'queued_recovered' => 0,
            'blocked_released' => 0,
            'stale_context' => 0,
        ];

        foreach ($candidateIds as $candidateId) {
            $recovered = DB::transaction(function () use (
                $candidateId,
                $gateErrorCodes,
                $staleBefore,
                &$result,
            ): ?DeviceIdentitySync {
                $sync = DeviceIdentitySync::query()->lockForUpdate()->find((int) $candidateId);
                if (! $sync) {
                    return null;
                }

                $wasBlocked = $sync->status === DeviceIdentitySync::STATUS_BLOCKED
                    && in_array($sync->error_code, $gateErrorCodes, true);
                $wasStaleQueued = $sync->status === DeviceIdentitySync::STATUS_QUEUED
                    && ($sync->last_enqueued_at === null || $sync->last_enqueued_at->lte($staleBefore));
                if (! $wasBlocked && ! $wasStaleQueued) {
                    return null;
                }

                if (! $this->currentContextIsValid($sync)) {
                    $sync->forceFill([
                        'status' => DeviceIdentitySync::STATUS_BLOCKED,
                        'error_code' => 'assignment_not_current',
                        'error_message' => 'Der Identity-Sync gehört nicht mehr zur gültigen Mitarbeiterzuweisung.',
                    ])->save();
                    $this->audit(
                        $sync,
                        'device-identity-sync.recovery-blocked',
                        'Identity-Outbox-Recovery wegen veralteter Mitarbeiterzuweisung blockiert',
                    );
                    $result['stale_context']++;

                    return null;
                }

                $sync->forceFill([
                    'status' => DeviceIdentitySync::STATUS_QUEUED,
                    'error_code' => null,
                    'error_message' => null,
                    'completed_at' => null,
                    'last_enqueued_at' => now(),
                ])->save();
                $this->audit(
                    $sync,
                    'device-identity-sync.recovered',
                    $wasBlocked
                        ? 'Blockierter Identity-Sync nach gültiger Produktionsfreigabe eingeplant'
                        : 'Verwaister Identity-Sync erneut an die Queue übergeben',
                );
                $result[$wasBlocked ? 'blocked_released' : 'queued_recovered']++;

                return $sync;
            });

            if ($recovered) {
                // This call is deliberately outside the row transaction. If
                // the process dies here, the unchanged queued row is safely
                // eligible again after the bounded recovery lease.
                $this->dispatchAfterCommit($recovered->getKey());
            }
        }

        return $result;
    }

    /**
     * @return array{sync: DeviceIdentitySync, payload: array<string, mixed>}|null
     */
    public function claimForDispatch(int $syncId): ?array
    {
        $release = $this->connectorRelease();
        if (! $release['released']) {
            $this->block($syncId, $release['code'], $release['message']);

            return null;
        }

        return DB::transaction(function () use ($syncId): ?array {
            $sync = DeviceIdentitySync::query()->lockForUpdate()->find($syncId);
            if (! $sync || ! in_array($sync->status, [DeviceIdentitySync::STATUS_QUEUED, DeviceIdentitySync::STATUS_DISPATCHED], true)) {
                return null;
            }
            if (! $this->currentContextIsValid($sync)) {
                $sync->forceFill([
                    'status' => DeviceIdentitySync::STATUS_BLOCKED,
                    'error_code' => 'assignment_not_current',
                    'error_message' => 'Der Identity-Sync gehört nicht mehr zur aktuellen aktiven Mitarbeiterzuweisung.',
                ])->save();
                $this->audit($sync, 'device-identity-sync.blocked', 'Identity-Sync wegen veralteter Mitarbeiterzuweisung blockiert');

                return null;
            }

            $assignmentIds = collect($sync->account_assignment_ids)
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $operation = $sync->operation === 'revoke' ? 'revoke' : 'apply';
            $lines = $this->currentAccountAssignments(
                (int) $sync->device_id,
                (int) $sync->user_id,
                $assignmentIds,
                lock: true,
                operation: $operation,
            );
            if ($lines->count() !== count($assignmentIds)) {
                $sync->forceFill([
                    'status' => DeviceIdentitySync::STATUS_BLOCKED,
                    'error_code' => 'account_assignments_changed',
                    'error_message' => 'Die Konten- oder Profilzuordnungen haben sich seit der Vorbereitung geändert.',
                ])->save();

                return null;
            }

            $accounts = $this->accountsFrom($lines, $operation);
            $assignment = DeviceAssignment::query()->findOrFail($sync->device_assignment_id);
            $device = Device::query()->findOrFail($sync->device_id);
            $sync->forceFill([
                'status' => DeviceIdentitySync::STATUS_DISPATCHED,
                'attempts' => ((int) $sync->attempts) + 1,
                'last_attempted_at' => now(),
                'dispatched_at' => $sync->dispatched_at ?: now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return [
                'sync' => $sync,
                'payload' => [
                    'sync_id' => (string) $sync->public_id,
                    'correlation_id' => (string) $sync->correlation_id,
                    'device_id' => (string) $device->public_id,
                    'assignment_id' => (string) $assignment->id,
                    'employee_reference' => (string) $sync->user_id,
                    'accounts' => $accounts,
                    'profile_assignment_ids' => collect($sync->profile_assignment_ids)
                        ->map(fn (mixed $id): int => (int) $id)
                        ->sort()
                        ->values()
                        ->all(),
                ],
            ];
        });
    }

    /** @param array<string, mixed> $response */
    public function recordResponse(int $syncId, array $response): void
    {
        DB::transaction(function () use ($syncId, $response): void {
            $sync = DeviceIdentitySync::query()->lockForUpdate()->find($syncId);
            if (! $sync || $sync->status !== DeviceIdentitySync::STATUS_DISPATCHED) {
                return;
            }

            $accepted = ($response['accepted'] ?? null) === true;
            $completed = ($response['completed'] ?? null) === true;
            $message = is_string($response['message'] ?? null)
                ? SafeProviderData::error($response['message'])
                : null;
            $details = SafeProviderData::summary(is_array($response['details'] ?? null) ? $response['details'] : []);
            $sync->forceFill([
                'status' => ! $accepted
                    ? DeviceIdentitySync::STATUS_FAILED
                    : ($completed ? DeviceIdentitySync::STATUS_COMPLETED : DeviceIdentitySync::STATUS_ACCEPTED),
                'provider_job_id' => is_string($response['provider_job_id'] ?? null)
                    ? $response['provider_job_id']
                    : $sync->provider_job_id,
                'result' => [
                    'accepted' => $accepted,
                    'completed' => $completed,
                    'message' => $message,
                    'details' => $details,
                ],
                'completed_at' => ($completed || ! $accepted) ? now() : null,
                'error_code' => $accepted ? null : 'connector_rejected',
                'error_message' => $accepted ? null : ($message ?: 'Der Identity-Connector hat den Sync abgelehnt.'),
            ])->save();

            $this->audit(
                $sync,
                $accepted ? 'device-identity-sync.accepted' : 'device-identity-sync.rejected',
                $accepted ? 'Identity-Sync vom Connector angenommen' : 'Identity-Sync vom Connector abgelehnt',
            );
        });
    }

    public function block(int $syncId, string $code, string $message): void
    {
        DB::transaction(function () use ($syncId, $code, $message): void {
            $sync = DeviceIdentitySync::query()->lockForUpdate()->find($syncId);
            if (! $sync || in_array($sync->status, [DeviceIdentitySync::STATUS_COMPLETED, DeviceIdentitySync::STATUS_ACCEPTED], true)) {
                return;
            }
            $sync->forceFill([
                'status' => DeviceIdentitySync::STATUS_BLOCKED,
                'error_code' => mb_substr($code, 0, 80),
                'error_message' => SafeProviderData::error($message),
            ])->save();
            $this->audit($sync, 'device-identity-sync.blocked', 'Identity-Sync blockiert und nicht versendet');
        });
    }

    public function markFailed(int $syncId, ?Throwable $exception): void
    {
        DB::transaction(function () use ($syncId, $exception): void {
            $sync = DeviceIdentitySync::query()->lockForUpdate()->find($syncId);
            if (! $sync || in_array($sync->status, [DeviceIdentitySync::STATUS_COMPLETED, DeviceIdentitySync::STATUS_ACCEPTED], true)) {
                return;
            }
            $sync->forceFill([
                'status' => DeviceIdentitySync::STATUS_FAILED,
                'completed_at' => now(),
                'error_code' => 'connector_failed',
                'error_message' => SafeProviderData::error($exception?->getMessage() ?: 'Der Identity-Sync ist fehlgeschlagen.'),
            ])->save();
            $this->audit($sync, 'device-identity-sync.failed', 'Identity-Sync fehlgeschlagen');
        });
    }

    /** @return array{released: bool, code: string, message: string} */
    private function connectorRelease(): array
    {
        try {
            $enabled = ($this->settings->providerRuntime('identity', fresh: true)['enabled'] ?? false) === true;
            if (! $enabled) {
                return [
                    'released' => false,
                    'code' => 'identity_connector_disabled',
                    'message' => 'Der Identity-Connector ist deaktiviert; der Sync wurde nicht versendet.',
                ];
            }

            if (! $this->settings->productionMutationsEnabledFor('identity')) {
                return [
                    'released' => false,
                    'code' => 'production_gate_closed',
                    'message' => 'Der Identity-Connector besitzt keinen aktuellen Funktionstest oder keine ausdrückliche Produktionsfreigabe; der Sync wurde nicht versendet.',
                ];
            }

            return [
                'released' => true,
                'code' => '',
                'message' => '',
            ];
        } catch (Throwable) {
            return [
                'released' => false,
                'code' => 'identity_connector_unavailable',
                'message' => 'Der Identity-Connector konnte nicht sicher geprüft werden; der Sync wurde nicht versendet.',
            ];
        }
    }

    private function currentContextIsValid(DeviceIdentitySync $sync): bool
    {
        if (! $sync->user_id) {
            return false;
        }

        if ($sync->operation === 'revoke') {
            return DeviceAssignment::query()
                ->whereKey($sync->device_assignment_id)
                ->where('device_id', $sync->device_id)
                ->where('user_id', $sync->user_id)
                ->where('status', DeviceAssignment::STATUS_RETURNED)
                ->whereNotNull('returned_at')
                ->exists();
        }

        $employee = User::query()->find($sync->user_id);
        $assignment = $this->currentAssignment((int) $sync->device_id, (int) $sync->user_id);

        return $employee?->isActive() === true
            && $assignment !== null
            && (int) $assignment->id === (int) $sync->device_assignment_id;
    }

    private function currentAssignment(int $deviceId, int $employeeId, bool $lock = false): ?DeviceAssignment
    {
        return DeviceAssignment::query()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->active()
            ->where('device_id', $deviceId)
            ->where('user_id', $employeeId)
            ->latest('assigned_at')
            ->first();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, DeviceAccountAssignment>
     */
    private function currentAccountAssignments(
        int $deviceId,
        int $employeeId,
        array $ids,
        bool $lock = false,
        string $operation = 'apply',
    ): Collection {
        $desiredState = $operation === 'revoke' ? 'unassigned' : 'assigned';

        return DeviceAccountAssignment::query()
            ->with(['identityAccount', 'provisioningProfile'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->whereIn('id', $ids)
            ->where('device_id', $deviceId)
            ->where('user_id', $employeeId)
            ->where('desired_state', $desiredState)
            ->orderBy('id')
            ->get()
            ->filter(function (DeviceAccountAssignment $assignment) use ($operation): bool {
                $identity = $assignment->identityAccount;
                $provider = $identity?->provider instanceof \BackedEnum
                    ? $identity->provider->value
                    : (string) $identity?->provider;

                return $identity !== null
                    && $identity->lifecycle_status === 'active'
                    && in_array($provider, self::ACCOUNT_PROVIDERS, true)
                    && trim((string) $identity->principal) !== ''
                    && $assignment->provisioningProfile !== null
                    && ($operation === 'revoke' || $assignment->provisioningProfile->is_active === true);
            })
            ->values();
    }

    /**
     * @param  Collection<int, DeviceAccountAssignment>  $lines
     * @return list<array{provider: string, principal: string, desired_state: string}>
     */
    private function accountsFrom(Collection $lines, string $operation = 'apply'): array
    {
        return $lines
            ->map(function (DeviceAccountAssignment $assignment) use ($operation): array {
                $identity = $assignment->identityAccount;
                $provider = $identity->provider instanceof \BackedEnum
                    ? $identity->provider->value
                    : (string) $identity->provider;

                return [
                    'provider' => $provider,
                    'principal' => (string) $identity->principal,
                    'desired_state' => $operation === 'revoke' ? 'revoked' : 'assigned',
                ];
            })
            ->unique(fn (array $account): string => implode('|', $account))
            ->sortBy(fn (array $account): string => $account['provider'].'|'.$account['principal'])
            ->values()
            ->all();
    }

    private function audit(
        DeviceIdentitySync $sync,
        string $event,
        string $message,
        ?User $actor = null,
    ): void {
        $logger = activity('device-management')
            ->performedOn($sync)
            ->event($event)
            ->withProperties([
                'sync_id' => (string) $sync->public_id,
                'device_id' => (int) $sync->device_id,
                'assignment_id' => (int) $sync->device_assignment_id,
                'status' => (string) $sync->status,
                'attempts' => (int) $sync->attempts,
            ]);
        $actor ??= $sync->requester;
        if ($actor) {
            $logger->causedBy($actor);
        }
        $logger->log($message);
    }

    private function dispatchAfterCommit(int $syncId): void
    {
        // Explicit transaction callbacks are used instead of relying on the
        // queue driver's after_commit option. This keeps the business outbox
        // atomic even with sync/fake queue drivers and nested transactions.
        DB::afterCommit(static function () use ($syncId): void {
            DispatchDeviceIdentitySync::dispatch($syncId);
        });
    }
}
