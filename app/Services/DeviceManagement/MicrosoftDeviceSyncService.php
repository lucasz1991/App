<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceLifecycleStatus;
use App\Enums\DeviceManagementStatus;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeIdentityAccount;
use App\Models\MicrosoftDeviceLink;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Native inventory discovery; it never sends a device/account mutation to Microsoft. */
class MicrosoftDeviceSyncService
{
    public function __construct(
        private readonly MicrosoftDeviceSettings $settings,
        private readonly MicrosoftGraphDeviceClient $graph,
        private readonly DeviceReadinessService $readiness,
    ) {}

    public function probe(): array
    {
        ['configuration' => $configuration, 'fingerprint' => $fingerprint] = $this->settings->snapshot();
        $result = ['status' => 'success', 'entra_devices' => 0, 'intune_devices' => 0, 'checked_at' => now()->toIso8601String()];
        try {
            $this->graph->begin($configuration);
            $devices = $this->graph->devices(probe: true);
            $result['entra_devices'] = count($devices);
            if ($devices !== []) {
                $this->graph->userRelationships([$devices[0]['id'] ?? '']);
            }
            if ($configuration['intune_enabled']) {
                try {
                    $managed = $this->graph->managedDevices(probe: true);
                    $result['intune_devices'] = count($managed);
                    if ($managed !== []) {
                        $this->graph->userRelationships([$managed[0]['id'] ?? ''], intune: true);
                    }
                    $result['intune_status'] = 'success';
                } catch (MicrosoftGraphDeviceException $exception) {
                    $result['status'] = 'partial';
                    $result['intune_status'] = $exception->reason;
                }
            }
        } catch (MicrosoftGraphDeviceException $exception) {
            $result['status'] = $this->publicStatus($exception->reason);
        } catch (Throwable) {
            $result['status'] = 'failed';
        }

        $this->settings->recordDiagnostic($result, $fingerprint);

        return $result;
    }

    public function sync(?string $expectedFingerprint = null): array
    {
        ['configuration' => $configuration, 'fingerprint' => $fingerprint] = $this->settings->snapshot();
        $result = [
            'status' => 'disabled', 'discovered' => 0, 'created' => 0, 'updated' => 0,
            'assigned' => 0, 'skipped' => 0, 'conflicts' => 0, 'entra_devices' => 0,
            'intune_devices' => 0, 'checked_at' => now()->toIso8601String(),
        ];
        if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $fingerprint)) {
            return [...$result, 'status' => 'stale_configuration'];
        }
        if (! $configuration['enabled']) {
            return $result;
        }
        $lock = Cache::lock('microsoft-devices:sync:'.hash('sha256', $configuration['tenant_id']), 270);
        if (! $lock->get()) {
            return [...$result, 'status' => 'running'];
        }

        try {
            $this->graph->begin($configuration);
            $directoryRecords = $this->graph->devices();
            $observedDirectoryIds = [];
            foreach ($directoryRecords as $record) {
                if (! Str::isUuid($record['id'] ?? '')) {
                    throw new MicrosoftGraphDeviceException('invalid_response');
                }
                $observedDirectoryIds[] = strtolower($record['id']);
            }
            $devices = $this->windowsRecords($directoryRecords);
            $owners = $this->graph->userRelationships(array_column($devices, 'id'));
            $intuneByEntra = [];
            $primaryUsers = [];
            $intuneAvailable = true;
            $result['status'] = 'success';
            $result['discovered'] = $result['entra_devices'] = count($devices);
            if ($configuration['intune_enabled']) {
                try {
                    $managed = $this->windowsRecords($this->graph->managedDevices(), intune: true);
                    $primaryUsers = $this->graph->userRelationships(array_column($managed, 'id'), intune: true);
                    foreach ($managed as $record) {
                        $entraId = strtolower((string) ($record['azureADDeviceId'] ?? ''));
                        if (Str::isUuid($entraId) && $entraId !== '00000000-0000-0000-0000-000000000000') {
                            $intuneByEntra[$entraId][] = $record;
                        }
                    }
                    $result['intune_devices'] = count($managed);
                    $result['intune_status'] = 'success';
                } catch (MicrosoftGraphDeviceException $exception) {
                    // A missing Intune permission/license must not lose Entra
                    // inventory. It must not silently downgrade primary-user
                    // authority to the person who originally joined the PC.
                    $intuneAvailable = false;
                    $result['status'] = 'partial';
                    $result['intune_status'] = $exception->reason;
                }
            }

            $result = DB::transaction(function () use ($devices, $observedDirectoryIds, $owners, $intuneByEntra, $primaryUsers, $intuneAvailable, $configuration, $fingerprint, $result): array {
                Setting::query()->where('type', 'device_management')->where('key', 'microsoft_graph')->lockForUpdate()->first();
                if (! hash_equals($fingerprint, $this->settings->fingerprint())) {
                    throw new MicrosoftGraphDeviceException('stale_configuration');
                }
                $runId = (string) Str::uuid();
                foreach ($devices as $directory) {
                    $matches = $intuneByEntra[strtolower($directory['deviceId'])] ?? [];
                    $intune = count($matches) === 1 ? $matches[0] : null;
                    $source = $intune !== null ? 'intune_primary_user' : 'entra_registered_owner';
                    $candidateIds = $intune !== null
                        ? ($primaryUsers[$intune['id']] ?? [])
                        : ($owners[$directory['id']] ?? []);
                    $authorityStatus = ! $intuneAvailable ? 'intune_unavailable' : (count($matches) > 1 ? 'ambiguous_intune' : null);
                    $outcome = $this->importDevice($directory, $intune, $candidateIds, $source, $authorityStatus, $configuration, $runId);
                    foreach (['created', 'updated', 'assigned', 'skipped', 'conflicts'] as $counter) {
                        $result[$counter] += $outcome[$counter] ?? 0;
                    }
                }
                // Only a complete, successful Entra inventory can prove that
                // a prior directory object is absent. Keep its local asset and
                // handover history; a directory deletion is not a return.
                MicrosoftDeviceLink::query()->where('tenant_id', $configuration['tenant_id'])
                    ->whereNotIn('directory_object_id', $observedDirectoryIds)
                    ->update(['directory_status' => 'missing', 'assignment_status' => 'directory_missing']);

                return $result;
            }, 3);
        } catch (MicrosoftGraphDeviceException $exception) {
            $result = [...$result, 'status' => $this->publicStatus($exception->reason), 'created' => 0, 'updated' => 0, 'assigned' => 0, 'conflicts' => 0];
        } catch (Throwable) {
            $result = [...$result, 'status' => 'failed', 'created' => 0, 'updated' => 0, 'assigned' => 0, 'conflicts' => 0];
        } finally {
            $lock->release();
        }
        $this->settings->recordRun($result, $fingerprint);

        return $result;
    }

    private function importDevice(array $directory, ?array $intune, array $ownerIds, string $source, ?string $authorityStatus, array $configuration, string $runId): array
    {
        $tenantId = $configuration['tenant_id'];
        $objectId = strtolower($directory['id']);
        $entraId = strtolower($directory['deviceId']);
        $link = MicrosoftDeviceLink::query()->where('tenant_id', $tenantId)
            ->where('directory_object_id', $objectId)->lockForUpdate()->first();
        if ($link && $link->entra_device_id !== $entraId) {
            // Reject a contradictory identity before changing any asset data.
            $link->update(['sync_run_id' => $runId, 'assignment_status' => 'device_id_conflict']);

            return ['skipped' => 1, 'conflicts' => 1];
        }
        $serial = $this->serial($intune['serialNumber'] ?? null);
        $device = $link ? Device::withTrashed()->lockForUpdate()->find($link->device_id) : null;
        if ($device?->trashed()) {
            $link->update(['sync_run_id' => $runId, 'last_synced_at' => now(), 'assignment_status' => 'asset_archived']);

            return ['skipped' => 1];
        }
        if (! $link && MicrosoftDeviceLink::query()->where('tenant_id', $tenantId)->where('entra_device_id', $entraId)->exists()) {
            return ['skipped' => 1, 'conflicts' => 1];
        }
        if (! $device && $serial !== null) {
            $device = Device::withTrashed()->where('serial_number', $serial)->lockForUpdate()->first();
            if ($device && ($device->trashed() || $device->platform !== DevicePlatform::Windows
                || $device->microsoftLink()->exists())) {
                return ['skipped' => 1, 'conflicts' => 1];
            }
        }

        $created = ! $device;
        if (! $device) {
            $device = Device::query()->create([
                'display_name' => $this->string($directory['displayName'] ?? null, 191) ?? 'Windows-Gerät',
                'hostname' => $this->string($directory['displayName'] ?? null, 191),
                'serial_number' => $serial,
                'platform' => DevicePlatform::Windows,
                'form_factor' => 'other',
                'ownership' => match ($intune['managedDeviceOwnerType'] ?? null) {
                    'company' => 'corporate',
                    'personal' => 'byod',
                    default => ($directory['trustType'] ?? '') === 'Workplace' ? 'byod' : 'corporate',
                },
                'lifecycle_status' => DeviceLifecycleStatus::Inventory,
                'management_status' => DeviceManagementStatus::Unmanaged,
                'compliance_status' => DeviceComplianceStatus::Unknown,
                'metadata' => ['source' => 'microsoft_graph'],
            ]);
        }
        if ($serial !== null && blank($device->serial_number)) {
            $serialInUse = Device::withTrashed()->where('serial_number', $serial)->whereKeyNot($device->id)->exists();
            if ($serialInUse) {
                $authorityStatus = 'serial_conflict';
            } else {
                $device->serial_number = $serial;
                $device->save();
            }
        } elseif ($serial !== null && $device->serial_number !== $serial) {
            $authorityStatus = 'serial_conflict';
        }
        if ($created || (($device->metadata['source'] ?? '') === 'microsoft_graph' && blank($device->primary_provider))) {
            $device->fill([
                'hostname' => $this->string($directory['displayName'] ?? null, 191) ?? $device->hostname,
                'os_version' => $this->string($intune['osVersion'] ?? $directory['operatingSystemVersion'] ?? null, 100) ?? $device->os_version,
                'manufacturer' => $this->string($intune['manufacturer'] ?? $directory['manufacturer'] ?? null, 100) ?? $device->manufacturer,
                'model' => $this->string($intune['model'] ?? $directory['model'] ?? null, 150) ?? $device->model,
            ])->save();
        }
        $link ??= new MicrosoftDeviceLink(['device_id' => $device->id, 'tenant_id' => $tenantId, 'directory_object_id' => $objectId]);
        $enabled = ($directory['accountEnabled'] ?? null) === true;
        $disabled = ($directory['accountEnabled'] ?? null) === false;
        $link->fill([
            'entra_device_id' => $entraId,
            'join_type' => $this->string($directory['trustType'] ?? null, 32),
            'directory_status' => $enabled ? 'present' : ($disabled ? 'disabled' : 'unknown'),
            'owner_ids' => $ownerIds,
            'assignment_source' => $source,
            'entra_managed' => is_bool($directory['isManaged'] ?? null) ? $directory['isManaged'] : null,
            'entra_compliant' => is_bool($directory['isCompliant'] ?? null) ? $directory['isCompliant'] : null,
            'directory_activity_at' => $this->timestamp($directory['approximateLastSignInDateTime'] ?? null),
            'last_synced_at' => now(),
            'sync_run_id' => $runId,
        ]);
        if ($authorityStatus === null) {
            $link->fill([
                'intune_device_id' => $intune['id'] ?? null,
                'intune_compliance' => $this->string($intune['complianceState'] ?? null, 40),
                'intune_synced_at' => $this->timestamp($intune['lastSyncDateTime'] ?? null),
            ]);
        }
        $account = count($ownerIds) === 1
            ? EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
                ->where('tenant_id', $tenantId)->where('external_id', $ownerIds[0])->lockForUpdate()->first()
            : null;
        $employee = $account?->user_id ? User::query()->lockForUpdate()->find($account->user_id) : null;
        if (! $employee?->isActive() || $employee->email_verified_at === null
            || ! in_array($employee->role, ['admin', 'staff'], true) || $employee->isSuperAdmin()) {
            $employee = null;
        }
        $link->suggested_user_id = $employee?->id;
        $status = $authorityStatus ?? match (true) {
            ! $enabled => $disabled ? 'directory_disabled' : 'directory_unknown',
            count($ownerIds) === 0 => 'no_owner',
            count($ownerIds) > 1 => 'ambiguous_owner',
            $employee === null => 'identity_unlinked',
            default => 'matched',
        };
        $assigned = 0;
        $active = $device->assignments()->active()->lockForUpdate()->first();
        if ($status === 'matched' && $active && (int) $active->user_id !== (int) $employee->id) {
            $status = 'assignment_conflict';
        } elseif ($status === 'matched' && ! $active) {
            // Never undo a recorded return or replace an operational device's
            // pending account/enrollment/command context with a guessed owner.
            $hasContext = $device->assignments()->exists() || $device->commands()->exists()
                || $device->accountAssignments()->exists() || $device->enrollments()->exists();
            if (! in_array($device->lifecycle_status, [DeviceLifecycleStatus::Inventory, DeviceLifecycleStatus::Preparing], true) || $hasContext) {
                $status = 'manual_review';
            } elseif (! $configuration['auto_assign']) {
                $status = 'suggested';
            } else {
                $assignment = $device->assignments()->create([
                    'user_id' => $employee->id,
                    'status' => DeviceAssignment::STATUS_ACTIVE,
                    'assigned_at' => now(),
                    'handover_notes' => 'Automatische Erstzuordnung anhand von Microsoft '.($source === 'intune_primary_user' ? 'Intune Primary User.' : 'Entra Registered Owner.'),
                ]);
                $device->update(['lifecycle_status' => DeviceLifecycleStatus::Assigned]);
                activity('device-management')->performedOn($device)->withProperties([
                    'assignment_id' => $assignment->id, 'employee_id' => $employee->id, 'source' => $source,
                ])->log('microsoft_device_auto_assigned');
                $assigned = 1;
            }
        }
        $link->assignment_status = $status;
        $link->save();
        if ($created) {
            activity('device-management')->performedOn($device)->withProperties(['source' => 'microsoft_graph'])
                ->log('microsoft_device_discovered');
        }
        $this->readiness->refresh($device->fresh());

        return ['created' => $created ? 1 : 0, 'updated' => $created ? 0 : 1, 'assigned' => $assigned,
            'conflicts' => in_array($status, ['assignment_conflict', 'ambiguous_owner', 'ambiguous_intune', 'manual_review', 'serial_conflict'], true) ? 1 : 0];
    }

    private function windowsRecords(array $records, bool $intune = false): array
    {
        $result = [];
        $seen = [];
        foreach ($records as $row) {
            if (! is_string($row['operatingSystem'] ?? null) || ! str_starts_with(strtolower($row['operatingSystem']), 'windows')) {
                continue;
            }
            if (! Str::isUuid($row['id'] ?? '') || (! $intune && ! Str::isUuid($row['deviceId'] ?? '')) || isset($seen[strtolower($row['id'])])) {
                throw new MicrosoftGraphDeviceException('invalid_response');
            }
            $row['id'] = strtolower($row['id']);
            $seen[$row['id']] = true;
            $result[] = $row;
        }

        return $result;
    }

    private function serial(mixed $value): ?string
    {
        if (! is_string($value) || mb_strlen(trim($value)) > 128) {
            return null;
        }
        $serial = $this->string($value, 128);
        if ($serial === null || in_array(strtolower($serial), ['0', 'unknown', 'none', 'n/a', 'default string', 'system serial number', 'to be filled by o.e.m.'], true)) {
            return null;
        }

        return $serial;
    }

    private function string(mixed $value, int $length): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value);

            return $date->year >= 2000 && $date->lte(now()->addMinutes(5)) ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function publicStatus(string $reason): string
    {
        return in_array($reason, ['missing_configuration', 'unauthorized', 'forbidden', 'unreachable', 'invalid_response', 'stale_configuration', 'rate_limited', 'http_error'], true)
            ? $reason : 'failed';
    }
}
