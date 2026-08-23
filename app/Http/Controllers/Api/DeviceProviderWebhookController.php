<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceEnrollmentStatus;
use App\Enums\DeviceManagementStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\DeviceIdentitySync;
use App\Models\DeviceProviderEvent;
use App\Models\DeviceProviderLink;
use App\Models\DeviceReadinessCheck;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\DeviceEnrollmentModeCatalog;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProviderRegistry;
use App\Services\DeviceManagement\DeviceReadinessService;
use App\Services\DeviceManagement\Support\SafeProviderData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

final class DeviceProviderWebhookController extends Controller
{
    public function __construct(private readonly DeviceEnrollmentModeCatalog $enrollmentModes) {}

    public function __invoke(
        Request $request,
        string $provider,
        DeviceProviderRegistry $providers,
        DeviceManagementSettings $settings,
    ): JsonResponse {
        try {
            $providerDriver = $providers->get($provider, fresh: true);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Provider nicht verfügbar.'], 404);
        }

        $provider = $providerDriver->key();

        $secret = $providers->webhookSecret($provider);
        if (! $providerDriver->enabled() || $secret === '') {
            return response()->json(['message' => 'Webhook nicht verfügbar.'], 404);
        }

        $maximumBytes = $settings->maximumWebhookBytes();
        $contentLength = (int) $request->headers->get('Content-Length', 0);
        $rawBody = $request->getContent();
        if ($contentLength > $maximumBytes || strlen($rawBody) > $maximumBytes) {
            return response()->json(['message' => 'Payload zu groß.'], 413);
        }

        $timestamp = $request->headers->get('X-RailTime-Timestamp');
        $signature = $request->headers->get('X-RailTime-Signature');
        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($signature)) {
            return response()->json(['message' => 'Signatur ungültig.'], 401);
        }

        $tolerance = $settings->webhookToleranceSeconds();
        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return response()->json(['message' => 'Signatur abgelaufen.'], 401);
        }

        $signature = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (strlen($signature) !== 64 || ! hash_equals($expected, strtolower($signature))) {
            return response()->json(['message' => 'Signatur ungültig.'], 401);
        }

        try {
            $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'JSON ungültig.'], 422);
        }

        if (! is_array($payload) || array_is_list($payload) || count($payload) > 20) {
            return response()->json(['message' => 'Webhook-Schema ungültig.'], 422);
        }

        $eventId = $this->boundedIdentifier($payload['event_id'] ?? null, 128);
        $eventType = $this->boundedIdentifier($payload['event_type'] ?? null, 64);
        if ($eventId === null || $eventType === null) {
            return response()->json(['message' => 'Webhook-Schema ungültig.'], 422);
        }

        $payloadHash = hash('sha256', $rawBody);
        try {
            $duplicate = DB::transaction(function () use (
                $provider,
                $providerDriver,
                $eventId,
                $eventType,
                $payload,
                $payloadHash,
            ): bool {
                $event = DeviceProviderEvent::query()->firstOrCreate(
                    ['provider' => $provider, 'event_id' => $eventId],
                    [
                        'event_type' => $eventType,
                        'payload_hash' => $payloadHash,
                        'status' => DeviceProviderEvent::STATUS_PROCESSING,
                    ],
                );

                if (! $event->wasRecentlyCreated) {
                    $event = DeviceProviderEvent::query()
                        ->whereKey($event->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    if (! hash_equals((string) $event->payload_hash, $payloadHash)
                        || ! hash_equals((string) $event->event_type, $eventType)) {
                        throw new DeviceProviderEventCollision;
                    }
                    if ($event->status !== DeviceProviderEvent::STATUS_ACCEPTED) {
                        throw new DeviceProviderEventInProgress;
                    }

                    // No handler, audit logger or readiness writer is called on
                    // an identical retry. Its original evidence timestamps stay
                    // immutable.
                    return true;
                }

                $handled = match ($eventType) {
                    'command.running', 'command.succeeded', 'command.failed' => $this->handleCommand($provider, $eventId, $eventType, $payload),
                    'enrollment.completed' => $this->handleEnrollment($provider, $payload),
                    'device.seen' => $this->handleDeviceSeen($provider, $payload),
                    'readiness.updated' => $this->handleReadiness($provider, $providerDriver->capabilities(), $payload),
                    'identity.updated' => $this->handleIdentity($provider, $eventId, $payload),
                    'profiles.applied' => $this->handleProfilesApplied($provider, $providerDriver->capabilities(), $payload),
                    default => false,
                };
                if (! $handled) {
                    throw new InvalidDeviceProviderEvent;
                }

                $event->forceFill([
                    'status' => DeviceProviderEvent::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                ])->save();

                return false;
            }, 3);
        } catch (DeviceProviderEventCollision) {
            return response()->json([
                'message' => 'Die event_id wurde bereits mit einem anderen Payload verwendet.',
            ], 409);
        } catch (InvalidDeviceProviderEvent) {
            return response()->json(['message' => 'Ereignis nicht gefunden oder nicht unterstützt.'], 422);
        }

        return response()->json([
            'accepted' => true,
            'event_id' => $eventId,
            'duplicate' => $duplicate,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function handleCommand(string $provider, string $eventId, string $eventType, array $payload): bool
    {
        $correlationId = $this->boundedIdentifier($payload['correlation_id'] ?? null, 128);
        $providerJobId = $this->boundedIdentifier($payload['provider_job_id'] ?? null, 191);
        if ($correlationId === null && $providerJobId === null) {
            return false;
        }

        return DB::transaction(function () use ($provider, $eventId, $eventType, $payload, $correlationId, $providerJobId): bool {
            $command = DeviceCommand::query()
                ->where('provider', $provider)
                ->when($correlationId !== null, fn ($query) => $query->where('correlation_id', $correlationId))
                ->when($correlationId === null, fn ($query) => $query->where('provider_job_id', $providerJobId))
                ->lockForUpdate()
                ->first();

            if (! $command) {
                return false;
            }

            // Terminal commands make webhook retries idempotent. A late or
            // conflicting provider event cannot overwrite the first terminal
            // state accepted by RailTime.
            if (in_array($command->status, [
                DeviceCommandStatus::Succeeded,
                DeviceCommandStatus::Failed,
                DeviceCommandStatus::Cancelled,
                DeviceCommandStatus::Rejected,
                DeviceCommandStatus::Expired,
            ], true)) {
                return true;
            }
            if (! in_array($command->status, [DeviceCommandStatus::Dispatched, DeviceCommandStatus::Running], true)) {
                return false;
            }
            if ($command->provider_job_id
                && $providerJobId
                && ! hash_equals((string) $command->provider_job_id, $providerJobId)) {
                return false;
            }

            $currentResult = is_array($command->result) ? $command->result : [];
            $processedEventIds = array_values(array_filter(
                is_array($currentResult['processed_event_ids'] ?? null) ? $currentResult['processed_event_ids'] : [],
                fn (mixed $value): bool => is_string($value) && $this->boundedIdentifier($value, 128) !== null,
            ));
            if (in_array($eventId, $processedEventIds, true)) {
                return true;
            }
            $processedEventIds[] = $eventId;
            $processedEventIds = array_slice(array_values(array_unique($processedEventIds)), -20);

            $details = SafeProviderData::summary(is_array($payload['details'] ?? null) ? $payload['details'] : []);
            $attributes = [
                'provider_job_id' => $providerJobId ?: $command->provider_job_id,
                'result' => array_merge($currentResult, ['processed_event_ids' => $processedEventIds]),
            ];
            if ($eventType === 'command.running') {
                $attributes['status'] = DeviceCommandStatus::Running;
            } elseif ($eventType === 'command.succeeded') {
                $attributes['status'] = DeviceCommandStatus::Succeeded;
                $attributes['result'] = array_merge($details, ['processed_event_ids' => $processedEventIds]);
                $attributes['completed_at'] = now();
                $attributes['error'] = null;
            } else {
                $attributes['status'] = DeviceCommandStatus::Failed;
                $attributes['completed_at'] = now();
                $attributes['error'] = SafeProviderData::error((string) ($payload['message'] ?? 'Der Geräteconnector meldete einen Fehler.'));
            }

            $command->forceFill($attributes)->save();

            activity('device-management')
                ->performedOn($command)
                ->event('device-command.webhook-'.$eventType)
                ->withProperties([
                    'command_id' => (string) $command->public_id,
                    'device_id' => (string) $command->device?->public_id,
                    'provider' => $provider,
                    'status' => $command->status->value,
                ])
                ->log('Geräteconnector-Status aktualisiert');

            return true;
        });
    }

    /** @param array<string, mixed> $payload */
    private function handleEnrollment(string $provider, array $payload): bool
    {
        $enrollmentId = $this->boundedIdentifier($payload['enrollment_id'] ?? null, 64);
        $providerDeviceId = $this->optionalProviderDeviceId($payload);
        if ($enrollmentId === null || $providerDeviceId === false) {
            return false;
        }

        return DB::transaction(function () use ($provider, $enrollmentId, $payload, $providerDeviceId): bool {
            $enrollment = DeviceEnrollment::query()
                ->with('device')
                ->where('public_id', $enrollmentId)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();
            if (! $enrollment) {
                return false;
            }

            if (! in_array($enrollment->status, [DeviceEnrollmentStatus::Claimed, DeviceEnrollmentStatus::Completed], true)) {
                return false;
            }

            $activeAssignmentId = $enrollment->device?->activeAssignment()->value('id');
            if (! $enrollment->device
                || ! $enrollment->device_assignment_id
                || (int) $activeAssignmentId !== (int) $enrollment->device_assignment_id) {
                return false;
            }

            $reportedLimited = filter_var(
                $payload['limited_management'] ?? false,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            );
            if ($reportedLimited === null) {
                return false;
            }
            $metadata = is_array($enrollment->metadata) ? $enrollment->metadata : [];
            $limited = (bool) ($metadata['limited_management'] ?? false)
                || $reportedLimited
                || $this->enrollmentModes->isLimited(
                    $enrollment->device,
                    $provider,
                    (string) $enrollment->mode,
                );

            $providerLink = $this->bindExistingProviderLink(
                $enrollment->device,
                $provider,
                $providerDeviceId,
            );
            if (! $providerLink) {
                return false;
            }

            $enrollment->forceFill([
                'status' => DeviceEnrollmentStatus::Completed,
                'completed_at' => $enrollment->completed_at ?: now(),
                'metadata' => array_merge($metadata, ['limited_management' => $limited]),
            ])->save();
            $enrollment->device()->update([
                'management_status' => $limited
                    ? DeviceManagementStatus::Limited->value
                    : DeviceManagementStatus::Managed->value,
                'last_synced_at' => now(),
            ]);
            DeviceReadinessCheck::query()->updateOrCreate(
                ['device_id' => $enrollment->device_id, 'check_key' => 'enrollment'],
                [
                    'label' => DeviceReadinessService::REQUIRED_CHECKS['enrollment'],
                    'status' => 'passed',
                    'source' => 'provider_receipt',
                    'evidence' => [
                        'provider' => $provider,
                        'mode' => (string) $enrollment->mode,
                        'limited_management' => $limited,
                        'device_assignment_id' => (int) $enrollment->device_assignment_id,
                        'provider_link_id' => (int) $providerLink->getKey(),
                    ],
                    'checked_at' => now(),
                ],
            );

            activity('device-management')
                ->performedOn($enrollment)
                ->event('device-enrollment.completed')
                ->withProperties([
                    'enrollment_id' => (string) $enrollment->public_id,
                    'device_id' => (string) $enrollment->device?->public_id,
                    'provider' => $provider,
                    'provider_link_id' => (int) $providerLink->getKey(),
                    'limited_management' => $limited,
                ])
                ->log('Geräteregistrierung durch Connector abgeschlossen');

            return true;
        });
    }

    /** @param array<string, mixed> $payload */
    private function handleDeviceSeen(string $provider, array $payload): bool
    {
        $deviceId = $this->boundedIdentifier($payload['device_id'] ?? null, 64);
        $providerDeviceId = $this->optionalProviderDeviceId($payload);
        if ($deviceId === null || $providerDeviceId === false) {
            return false;
        }

        // Deliberately mirror only the heartbeat. Raw inventories, coordinates,
        // recovery keys and account data from provider payloads are discarded.
        return DB::transaction(function () use ($deviceId, $provider, $providerDeviceId): bool {
            $device = Device::query()
                ->where('public_id', $deviceId)
                ->lockForUpdate()
                ->first();
            if (! $device) {
                return false;
            }

            $seenAt = now();
            $providerLink = $this->bindExistingProviderLink(
                $device,
                $provider,
                $providerDeviceId,
                $seenAt,
            );
            if (! $providerLink) {
                return false;
            }

            $device->forceFill([
                'last_seen_at' => $seenAt,
                'last_synced_at' => $seenAt,
            ])->save();
            if ($providerLink->role === DeviceProviderLink::ROLE_PRIMARY) {
                DeviceReadinessCheck::query()->updateOrCreate(
                    ['device_id' => $device->id, 'check_key' => 'provider_sync'],
                    [
                        'label' => DeviceReadinessService::REQUIRED_CHECKS['provider_sync'],
                        'status' => 'passed',
                        'source' => $provider,
                        'evidence' => [
                            'last_seen_at' => $seenAt->toIso8601String(),
                            'provider_link_id' => (int) $providerLink->getKey(),
                        ],
                        'checked_at' => $seenAt,
                    ],
                );
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $payload
     */
    private function handleReadiness(string $provider, array $capabilities, array $payload): bool
    {
        $deviceId = $this->boundedIdentifier($payload['device_id'] ?? null, 64);
        $checks = $payload['checks'] ?? null;
        if ($deviceId === null || ! is_array($checks) || ! array_is_list($checks) || count($checks) > 12) {
            return false;
        }

        $device = Device::query()->where('public_id', $deviceId)->first();
        if (! $device) {
            return false;
        }

        $providerLink = $device->providerLinks()
            ->forProvider($provider)
            ->available()
            ->first();
        if (! $providerLink) {
            return false;
        }

        $platform = $device->platform instanceof \BackedEnum ? $device->platform->value : (string) $device->platform;
        if (! in_array($platform, $capabilities['platforms'] ?? [], true)) {
            return false;
        }

        $allowedKeys = array_values(array_intersect(
            $capabilities['readiness_checks'] ?? [],
            array_keys(DeviceReadinessService::REQUIRED_CHECKS),
        ));
        $allowedStatuses = ['passed', 'warning', 'blocked', 'unknown', 'pending', 'not_applicable', 'stale'];
        $accepted = 0;

        DB::transaction(function () use ($checks, $device, $provider, $providerLink, $allowedKeys, $allowedStatuses, &$accepted): void {
            foreach ($checks as $check) {
                if (! is_array($check) || count($check) > 5) {
                    continue;
                }
                $key = $this->boundedIdentifier($check['key'] ?? null, 80);
                $status = $this->boundedIdentifier($check['status'] ?? null, 32);
                if ($key === null || $status === null
                    || ! in_array($key, $allowedKeys, true)
                    || ! in_array($status, $allowedStatuses, true)) {
                    continue;
                }

                DeviceReadinessCheck::query()->updateOrCreate(
                    ['device_id' => $device->id, 'check_key' => $key],
                    [
                        'label' => DeviceReadinessService::REQUIRED_CHECKS[$key],
                        'status' => $status,
                        'source' => $provider,
                        'evidence' => SafeProviderData::summary(is_array($check['evidence'] ?? null) ? $check['evidence'] : []),
                        'checked_at' => now(),
                    ],
                );
                $accepted++;
            }

            if ($accepted > 0) {
                $device->forceFill(['last_synced_at' => now()])->save();
                $providerLink->forceFill([
                    'status' => DeviceProviderLink::STATUS_ACTIVE,
                    'last_synced_at' => now(),
                ])->save();
            }
        });

        if ($accepted === 0) {
            return false;
        }

        activity('device-management')
            ->performedOn($device)
            ->event('device-readiness.updated')
            ->withProperties([
                'device_id' => (string) $device->public_id,
                'provider' => $provider,
                'check_count' => $accepted,
            ])
            ->log('Gerätebereitschaft durch Connector belegt');

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function handleIdentity(string $provider, string $eventId, array $payload): bool
    {
        if ($provider !== 'identity') {
            return false;
        }

        $syncId = $this->boundedIdentifier($payload['sync_id'] ?? null, 64);
        $correlationId = $this->boundedIdentifier($payload['correlation_id'] ?? null, 64);
        $assignmentId = $this->boundedIdentifier($payload['assignment_id'] ?? null, 20);
        $deviceId = $this->boundedIdentifier($payload['device_id'] ?? null, 64);
        $accounts = $payload['accounts'] ?? null;
        if ($syncId === null
            || $correlationId === null
            || $assignmentId === null
            || ! ctype_digit($assignmentId)
            || (int) $assignmentId < 1
            || $deviceId === null
            || ! is_array($accounts)
            || ! array_is_list($accounts)
            || count($accounts) < 1
            || count($accounts) > 4) {
            return false;
        }

        $allowedProviders = ['microsoft_365', 'google_workspace', 'apple_managed'];
        $allowedProvisioning = ['pending_provider', 'applied', 'ready', 'error', 'revoked'];
        $allowedLicenses = ['unknown', 'assigned', 'active', 'not_required', 'missing', 'error'];
        $allowedAccountKeys = ['provider', 'external_id', 'provisioning_status', 'license_status', 'signed_in'];

        return DB::transaction(function () use (
            $syncId,
            $correlationId,
            $assignmentId,
            $deviceId,
            $eventId,
            $accounts,
            $allowedProviders,
            $allowedProvisioning,
            $allowedLicenses,
            $allowedAccountKeys,
        ): bool {
            $sync = DeviceIdentitySync::query()
                ->where('public_id', $syncId)
                ->where('correlation_id', $correlationId)
                ->lockForUpdate()
                ->first();
            if (! $sync
                || ! $sync->user_id
                || (int) $sync->device_assignment_id !== (int) $assignmentId
                || ! in_array($sync->status, [
                    DeviceIdentitySync::STATUS_DISPATCHED,
                    DeviceIdentitySync::STATUS_ACCEPTED,
                    DeviceIdentitySync::STATUS_COMPLETED,
                ], true)) {
                return false;
            }

            $currentResult = is_array($sync->result) ? $sync->result : [];
            if (filled($currentResult['receipt_event_id'] ?? null)) {
                return false;
            }

            $device = Device::query()
                ->whereKey($sync->device_id)
                ->where('public_id', $deviceId)
                ->lockForUpdate()
                ->first();
            $assignment = DeviceAssignment::query()
                ->active()
                ->whereKey($sync->device_assignment_id)
                ->where('device_id', $sync->device_id)
                ->where('user_id', $sync->user_id)
                ->lockForUpdate()
                ->first();
            $user = User::query()->whereKey($sync->user_id)->lockForUpdate()->first();
            if (! $device || ! $assignment || $user?->isActive() !== true) {
                return false;
            }

            $accountAssignmentIds = collect($sync->account_assignment_ids)
                ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn (int|string $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();
            if ($accountAssignmentIds->isEmpty()
                || $accountAssignmentIds->count() !== count((array) $sync->account_assignment_ids)) {
                return false;
            }

            $accountAssignments = DeviceAccountAssignment::query()
                ->whereIn('id', $accountAssignmentIds)
                ->where('device_id', $device->id)
                ->where('user_id', $user->id)
                ->where('desired_state', 'assigned')
                ->where('status', '!=', 'revoked')
                ->lockForUpdate()
                ->get();
            if ($accountAssignments->count() !== $accountAssignmentIds->count()
                || $accountAssignments->contains(fn (DeviceAccountAssignment $line): bool => ! $line->employee_identity_account_id)) {
                return false;
            }

            $identityIds = $accountAssignments
                ->pluck('employee_identity_account_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $identities = EmployeeIdentityAccount::query()
                ->whereIn('id', $identityIds)
                ->where('user_id', $user->id)
                ->where('lifecycle_status', 'active')
                ->lockForUpdate()
                ->get();
            if ($identities->count() !== $identityIds->count()) {
                return false;
            }

            /** @var array<string, EmployeeIdentityAccount> $expectedByProvider */
            $expectedByProvider = [];
            foreach ($identities as $identity) {
                $identityProvider = $identity->provider instanceof \BackedEnum
                    ? (string) $identity->provider->value
                    : (string) $identity->provider;
                if (! in_array($identityProvider, $allowedProviders, true)
                    || isset($expectedByProvider[$identityProvider])) {
                    return false;
                }
                $expectedByProvider[$identityProvider] = $identity;
            }
            if ($expectedByProvider === [] || count($expectedByProvider) !== count($accounts)) {
                return false;
            }

            /** @var array<string, array{identity: EmployeeIdentityAccount, external_id: ?string, provisioning_status: string, license_status: string, signed_in: bool}> $receiptByProvider */
            $receiptByProvider = [];
            foreach ($accounts as $account) {
                if (! is_array($account)
                    || array_is_list($account)
                    || array_diff(array_keys($account), $allowedAccountKeys) !== []
                    || ! array_key_exists('signed_in', $account)
                    || ! is_bool($account['signed_in'])) {
                    return false;
                }
                $accountProvider = $this->boundedIdentifier($account['provider'] ?? null, 40);
                $provisioning = $this->boundedIdentifier($account['provisioning_status'] ?? null, 32);
                $license = $this->boundedIdentifier($account['license_status'] ?? null, 32);
                if ($accountProvider === null
                    || ! in_array($accountProvider, $allowedProviders, true)
                    || ! in_array($provisioning, $allowedProvisioning, true)
                    || ! in_array($license, $allowedLicenses, true)
                    || ! isset($expectedByProvider[$accountProvider])
                    || isset($receiptByProvider[$accountProvider])) {
                    return false;
                }

                $externalId = null;
                if (array_key_exists('external_id', $account) && $account['external_id'] !== null) {
                    $externalId = $this->boundedIdentifier($account['external_id'], 191);
                    if ($externalId === null
                        || EmployeeIdentityAccount::query()
                            ->where('provider', $accountProvider)
                            ->where('external_id', $externalId)
                            ->whereKeyNot($expectedByProvider[$accountProvider]->getKey())
                            ->exists()) {
                        return false;
                    }
                }

                $receiptByProvider[$accountProvider] = [
                    'identity' => $expectedByProvider[$accountProvider],
                    'external_id' => $externalId,
                    'provisioning_status' => $provisioning,
                    'license_status' => $license,
                    'signed_in' => $account['signed_in'],
                ];
            }
            if (count($receiptByProvider) !== count($expectedByProvider)) {
                return false;
            }

            $allSignedIn = true;
            foreach ($receiptByProvider as $receipt) {
                $identity = $receipt['identity'];
                $identity->forceFill([
                    'external_id' => $receipt['external_id'] ?: $identity->external_id,
                    'provisioning_status' => $receipt['provisioning_status'],
                    'license_status' => $receipt['license_status'],
                    'last_synced_at' => now(),
                ])->save();
                $allSignedIn = $allSignedIn && $receipt['signed_in'];
            }

            $identitiesReady = collect($receiptByProvider)->every(
                fn (array $receipt): bool => in_array($receipt['provisioning_status'], ['applied', 'ready'], true)
                    && in_array($receipt['license_status'], ['assigned', 'active', 'not_required'], true),
            );

            foreach ([
                'identity' => $identitiesReady ? 'passed' : 'pending',
                'user_sign_in' => ($identitiesReady && $allSignedIn) ? 'passed' : 'pending',
            ] as $key => $status) {
                DeviceReadinessCheck::query()->updateOrCreate(
                    ['device_id' => $device->id, 'check_key' => $key],
                    [
                        'label' => DeviceReadinessService::REQUIRED_CHECKS[$key],
                        'status' => $status,
                        'source' => 'identity',
                        'evidence' => [
                            'sync_id' => (string) $sync->public_id,
                            'assignment_id' => (int) $assignment->id,
                            'account_count' => count($receiptByProvider),
                            'signed_in' => $allSignedIn,
                        ],
                        'checked_at' => now(),
                    ],
                );
            }

            $sync->forceFill([
                'status' => DeviceIdentitySync::STATUS_COMPLETED,
                'completed_at' => now(),
                'result' => array_merge($currentResult, [
                    'receipt_event_id' => $eventId,
                    'receipt_account_count' => count($receiptByProvider),
                    'receipt_signed_in' => $allSignedIn,
                ]),
                'error_code' => null,
                'error_message' => null,
            ])->save();

            activity('device-management')
                ->performedOn($device)
                ->event('device-identity.updated')
                ->withProperties([
                    'device_id' => (string) $device->public_id,
                    'sync_id' => (string) $sync->public_id,
                    'assignment_id' => (int) $assignment->id,
                    'account_count' => count($receiptByProvider),
                    'ready' => $identitiesReady,
                    'signed_in' => $allSignedIn,
                ])
                ->log('Identitätsbereitschaft durch Connector belegt');

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $payload
     */
    private function handleProfilesApplied(string $provider, array $capabilities, array $payload): bool
    {
        if (! in_array('profiles', $capabilities['readiness_checks'] ?? [], true)) {
            return false;
        }

        $deviceId = $this->boundedIdentifier($payload['device_id'] ?? null, 64);
        $assignmentIds = $payload['assignment_ids'] ?? null;
        if ($deviceId === null || ! is_array($assignmentIds) || ! array_is_list($assignmentIds) || count($assignmentIds) > 30) {
            return false;
        }

        $ids = collect($assignmentIds)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return false;
        }

        $device = Device::query()->where('public_id', $deviceId)->first();
        $providerLink = $device?->providerLinks()
            ->forProvider($provider)
            ->available()
            ->first();
        if (! $device || ! $providerLink) {
            return false;
        }

        $activeUserId = $device->activeAssignment()->value('user_id');
        if (! $activeUserId) {
            return false;
        }

        $updated = DeviceAccountAssignment::query()
            ->where('device_id', $device->id)
            ->where('user_id', $activeUserId)
            ->where('desired_state', 'assigned')
            ->where('status', '!=', 'revoked')
            ->whereIn('id', $ids)
            ->update([
                'status' => 'applied',
                'configured_at' => now(),
                'last_attempted_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);
        if ($updated === 0) {
            return false;
        }

        $allApplied = ! DeviceAccountAssignment::query()
            ->where('device_id', $device->id)
            ->where('user_id', $activeUserId)
            ->where('desired_state', 'assigned')
            ->whereNotIn('status', ['applied', 'ready'])
            ->exists();
        DeviceReadinessCheck::query()->updateOrCreate(
            ['device_id' => $device->id, 'check_key' => 'profiles'],
            [
                'label' => DeviceReadinessService::REQUIRED_CHECKS['profiles'],
                'status' => $allApplied ? 'passed' : 'pending',
                'source' => $provider,
                'evidence' => ['applied_count' => $updated, 'all_applied' => $allApplied],
                'checked_at' => now(),
            ],
        );
        $syncedAt = now();
        $providerLink->forceFill([
            'status' => DeviceProviderLink::STATUS_ACTIVE,
            'last_synced_at' => $syncedAt,
        ])->save();
        $device->forceFill(['last_synced_at' => $syncedAt])->save();

        activity('device-management')
            ->performedOn($device)
            ->event('device-profiles.applied')
            ->withProperties([
                'device_id' => (string) $device->public_id,
                'provider' => $provider,
                'applied_count' => $updated,
                'all_applied' => $allApplied,
            ])
            ->log('Konten- und Geräteprofile durch Connector angewendet');

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string|null|false False means the explicitly supplied value was invalid.
     */
    private function optionalProviderDeviceId(array $payload): string|null|false
    {
        if (! array_key_exists('provider_device_id', $payload) || $payload['provider_device_id'] === null) {
            return null;
        }

        return $this->boundedIdentifier($payload['provider_device_id'], 191) ?? false;
    }

    /**
     * Bind only an already-declared link. A webhook is never allowed to create
     * a provider relationship for an unknown or merely guessed RailTime device.
     */
    private function bindExistingProviderLink(
        Device $device,
        string $provider,
        ?string $providerDeviceId,
        ?\DateTimeInterface $seenAt = null,
    ): ?DeviceProviderLink {
        $link = DeviceProviderLink::query()
            ->where('device_id', $device->getKey())
            ->where('provider', $provider)
            ->lockForUpdate()
            ->first();
        if (! $link || $link->status === DeviceProviderLink::STATUS_DISABLED) {
            return null;
        }

        if ($providerDeviceId !== null) {
            if (filled($link->external_device_id)
                && ! hash_equals((string) $link->external_device_id, $providerDeviceId)) {
                return null;
            }
            if (DeviceProviderLink::query()
                ->where('provider', $provider)
                ->where('external_device_id', $providerDeviceId)
                ->whereKeyNot($link->getKey())
                ->exists()) {
                return null;
            }

            $legacyProvider = strtolower(trim((string) $device->primary_provider));
            $legacyId = trim((string) $device->primary_provider_device_id);
            if ($link->role === DeviceProviderLink::ROLE_PRIMARY
                && (($legacyProvider !== '' && $legacyProvider !== $provider)
                    || ($legacyId !== '' && ! hash_equals($legacyId, $providerDeviceId)))) {
                return null;
            }

            $link->external_device_id = $providerDeviceId;
            if ($link->role === DeviceProviderLink::ROLE_PRIMARY) {
                $device->forceFill([
                    'primary_provider' => $provider,
                    // Both the normalized link and compatibility mirror now
                    // use the connector contract's 191-character width.
                    'primary_provider_device_id' => $providerDeviceId,
                ])->save();
            }
        }

        $link->forceFill([
            'status' => DeviceProviderLink::STATUS_ACTIVE,
            'last_seen_at' => $seenAt ?: $link->last_seen_at,
            'last_synced_at' => now(),
        ])->save();

        return $link;
    }

    private function boundedIdentifier(mixed $value, int $maximum): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (mb_strlen($value) > $maximum || ! preg_match('/\A[A-Za-z0-9._:@$+=\/-]+\z/', $value)) {
            return null;
        }

        return $value;
    }
}

final class InvalidDeviceProviderEvent extends \RuntimeException {}

final class DeviceProviderEventCollision extends \RuntimeException {}

final class DeviceProviderEventInProgress extends \RuntimeException {}
