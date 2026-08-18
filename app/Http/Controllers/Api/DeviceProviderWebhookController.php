<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceEnrollmentStatus;
use App\Enums\DeviceManagementStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\DeviceReadinessCheck;
use App\Models\EmployeeIdentityAccount;
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

        $handled = match ($eventType) {
            'command.running', 'command.succeeded', 'command.failed' => $this->handleCommand($provider, $eventId, $eventType, $payload),
            'enrollment.completed' => $this->handleEnrollment($provider, $payload),
            'device.seen' => $this->handleDeviceSeen($provider, $payload),
            'readiness.updated' => $this->handleReadiness($provider, $providerDriver->capabilities(), $payload),
            'identity.updated' => $this->handleIdentity($provider, $payload),
            'profiles.applied' => $this->handleProfilesApplied($provider, $providerDriver->capabilities(), $payload),
            default => false,
        };

        if (! $handled) {
            return response()->json(['message' => 'Ereignis nicht gefunden oder nicht unterstützt.'], 422);
        }

        return response()->json(['accepted' => true, 'event_id' => $eventId]);
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
        if ($enrollmentId === null) {
            return false;
        }

        return DB::transaction(function () use ($provider, $enrollmentId): bool {
            $enrollment = DeviceEnrollment::query()
                ->where('public_id', $enrollmentId)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();
            if (! $enrollment) {
                return false;
            }
            if ($enrollment->status === DeviceEnrollmentStatus::Completed) {
                return true;
            }
            if ($enrollment->status !== DeviceEnrollmentStatus::Claimed) {
                return false;
            }

            $enrollment->forceFill([
                'status' => DeviceEnrollmentStatus::Completed,
                'completed_at' => now(),
            ])->save();
            $enrollment->device()->update([
                'management_status' => DeviceManagementStatus::Managed->value,
                'last_synced_at' => now(),
            ]);

            activity('device-management')
                ->performedOn($enrollment)
                ->event('device-enrollment.completed')
                ->withProperties([
                    'enrollment_id' => (string) $enrollment->public_id,
                    'device_id' => (string) $enrollment->device?->public_id,
                    'provider' => $provider,
                ])
                ->log('Geräteregistrierung durch Connector abgeschlossen');

            return true;
        });
    }

    /** @param array<string, mixed> $payload */
    private function handleDeviceSeen(string $provider, array $payload): bool
    {
        $deviceId = $this->boundedIdentifier($payload['device_id'] ?? null, 64);
        if ($deviceId === null) {
            return false;
        }

        // Deliberately mirror only the heartbeat. Raw inventories, coordinates,
        // recovery keys and account data from provider payloads are discarded.
        return Device::query()
            ->where('public_id', $deviceId)
            ->where('primary_provider', $provider)
            ->update([
                'last_seen_at' => now(),
                'last_synced_at' => now(),
            ]) === 1;
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

        // Der primäre MDM-Connector darf nur seine eigenen Geräte belegen.
        // MeshCentral ist bewusst ein sekundärer Desktop-Supportkanal und darf
        // ausschließlich den in seinen Capabilities erlaubten Supportcheck
        // liefern.
        if ($provider !== 'meshcentral' && $device->primary_provider !== $provider) {
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

        DB::transaction(function () use ($checks, $device, $provider, $allowedKeys, $allowedStatuses, &$accepted): void {
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
    private function handleIdentity(string $provider, array $payload): bool
    {
        if ($provider !== 'identity') {
            return false;
        }

        $deviceId = $this->boundedIdentifier($payload['device_id'] ?? null, 64);
        $accounts = $payload['accounts'] ?? null;
        if ($deviceId === null || ! is_array($accounts) || ! array_is_list($accounts) || count($accounts) > 4) {
            return false;
        }

        $device = Device::query()->with('activeAssignment')->where('public_id', $deviceId)->first();
        $userId = $device?->activeAssignment?->user_id;
        if (! $device || ! $userId) {
            return false;
        }

        $allowedProviders = ['microsoft_365', 'google_workspace', 'apple_managed'];
        $allowedProvisioning = ['pending_provider', 'applied', 'ready', 'error', 'revoked'];
        $allowedLicenses = ['unknown', 'assigned', 'active', 'not_required', 'missing', 'error'];
        $updated = 0;
        $allSignedIn = true;

        DB::transaction(function () use ($accounts, $userId, $allowedProviders, $allowedProvisioning, $allowedLicenses, &$updated, &$allSignedIn): void {
            foreach ($accounts as $account) {
                if (! is_array($account) || count($account) > 7) {
                    continue;
                }
                $accountProvider = $this->boundedIdentifier($account['provider'] ?? null, 40);
                $provisioning = $this->boundedIdentifier($account['provisioning_status'] ?? null, 32);
                $license = $this->boundedIdentifier($account['license_status'] ?? null, 32);
                if (! in_array($accountProvider, $allowedProviders, true)
                    || ! in_array($provisioning, $allowedProvisioning, true)
                    || ! in_array($license, $allowedLicenses, true)) {
                    continue;
                }

                $identity = EmployeeIdentityAccount::query()
                    ->where('user_id', $userId)
                    ->where('provider', $accountProvider)
                    ->lockForUpdate()
                    ->first();
                if (! $identity) {
                    continue;
                }

                $externalId = $this->boundedIdentifier($account['external_id'] ?? null, 191);
                $identity->forceFill([
                    'external_id' => $externalId ?: $identity->external_id,
                    'provisioning_status' => $provisioning,
                    'license_status' => $license,
                    'last_synced_at' => now(),
                ])->save();
                $updated++;
                $allSignedIn = $allSignedIn && filter_var($account['signed_in'] ?? false, FILTER_VALIDATE_BOOL);
            }
        });

        if ($updated === 0) {
            return false;
        }

        $identities = EmployeeIdentityAccount::query()->where('user_id', $userId)->get();
        $identitiesReady = $identities->isNotEmpty() && $identities->every(
            fn (EmployeeIdentityAccount $identity): bool => $identity->lifecycle_status === 'active'
                && in_array($identity->provisioning_status, ['applied', 'ready'], true)
                && in_array($identity->license_status, ['assigned', 'active', 'not_required'], true),
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
                    'evidence' => ['account_count' => $updated, 'signed_in' => $allSignedIn],
                    'checked_at' => now(),
                ],
            );
        }

        activity('device-management')
            ->performedOn($device)
            ->event('device-identity.updated')
            ->withProperties([
                'device_id' => (string) $device->public_id,
                'account_count' => $updated,
                'ready' => $identitiesReady,
                'signed_in' => $allSignedIn,
            ])
            ->log('Identitätsbereitschaft durch Connector belegt');

        return true;
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

        $device = Device::query()
            ->where('public_id', $deviceId)
            ->where('primary_provider', $provider)
            ->first();
        if (! $device) {
            return false;
        }

        $updated = DeviceAccountAssignment::query()
            ->where('device_id', $device->id)
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

    private function boundedIdentifier(mixed $value, int $maximum): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (mb_strlen($value) > $maximum || ! preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return null;
        }

        return $value;
    }
}
