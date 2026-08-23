<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceEnrollmentStatus;
use App\Enums\DeviceLifecycleStatus;
use App\Enums\DeviceManagementStatus;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\DeviceAssignment;
use App\Models\DeviceEnrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceInventoryService
{
    public function __construct(
        private readonly DeviceReadinessService $readiness,
        private readonly DeviceCommandService $commands,
        private readonly DeviceIdentitySyncService $identitySyncs,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     */
    public function create(array $attributes, User $actor): Device
    {
        Gate::forUser($actor)->authorize('devices.manage');

        if (is_string($attributes['primary_provider'] ?? null)) {
            $attributes['primary_provider'] = strtolower(trim($attributes['primary_provider']));
        }
        if (is_string($attributes['primary_provider_device_id'] ?? null)) {
            $attributes['primary_provider_device_id'] = trim($attributes['primary_provider_device_id']);
        }

        $data = validator($attributes, [
            'asset_tag' => ['nullable', 'string', 'max:64', 'unique:devices,asset_tag'],
            'serial_number' => ['nullable', 'string', 'max:128', 'unique:devices,serial_number'],
            'hostname' => ['nullable', 'string', 'max:191'],
            'display_name' => ['required', 'string', 'max:191'],
            'form_factor' => ['required', Rule::in(['laptop', 'desktop', 'phone', 'tablet', 'other'])],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'ownership' => ['required', Rule::in(['corporate', 'byod'])],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:150'],
            'os_version' => ['nullable', 'string', 'max:100'],
            'declared_location' => ['nullable', 'string', 'max:191'],
            'primary_provider' => ['nullable', 'required_with:primary_provider_device_id', 'string', 'regex:/^[a-z0-9_-]{2,64}$/'],
            // MeshCentral node IDs contain path separators and may contain
            // @, $, + or =. Keep this aligned with the 191-character
            // connector contract and the normalized provider-link column.
            'primary_provider_device_id' => ['nullable', 'string', 'max:191', 'regex:/\A[A-Za-z0-9._:@$+=\/-]+\z/'],
        ])->validate();

        foreach ([
            'asset_tag', 'serial_number', 'hostname', 'manufacturer', 'model',
            'os_version', 'declared_location', 'primary_provider', 'primary_provider_device_id',
        ] as $nullableField) {
            if (blank($data[$nullableField] ?? null)) {
                $data[$nullableField] = null;
            }
        }

        if (blank($data['asset_tag'] ?? null) && blank($data['serial_number'] ?? null)) {
            throw ValidationException::withMessages([
                'asset_tag' => 'Mindestens Inventarnummer oder Seriennummer ist erforderlich.',
            ]);
        }

        $device = DB::transaction(function () use ($data, $actor): Device {
            $device = Device::query()->create([
                ...$data,
                'lifecycle_status' => DeviceLifecycleStatus::Inventory,
                'management_status' => DeviceManagementStatus::Unmanaged,
                'compliance_status' => DeviceComplianceStatus::Unknown,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $device->syncPrimaryProviderLink();

            return $device;
        });

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($device)
            ->withProperties([
                'device_public_id' => $device->public_id,
                'asset_tag' => $device->asset_tag,
                'platform' => $device->platform->value,
            ])
            ->log('device_created');

        $this->readiness->refresh($device, $actor);

        return $device->fresh();
    }

    /** @throws AuthorizationException */
    public function assign(Device $device, User $employee, User $actor, ?string $note = null): DeviceAssignment
    {
        Gate::forUser($actor)->authorize('devices.assign');

        $assignment = DB::transaction(function () use ($device, $employee, $actor, $note): DeviceAssignment {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);
            $activeAssignments = DeviceAssignment::query()
                ->where('device_id', $lockedDevice->id)
                ->active()
                ->lockForUpdate()
                ->get();

            $sameAssignment = $activeAssignments->firstWhere('user_id', $employee->id);
            if ($sameAssignment) {
                return $sameAssignment;
            }

            $this->retireAssignmentContext($lockedDevice, $actor);

            foreach ($activeAssignments as $activeAssignment) {
                $activeAssignment->update([
                    'status' => DeviceAssignment::STATUS_RETURNED,
                    'returned_by' => $actor->id,
                    'returned_at' => now(),
                ]);
            }

            $created = DeviceAssignment::query()->create([
                'device_id' => $lockedDevice->id,
                'user_id' => $employee->id,
                'status' => DeviceAssignment::STATUS_ACTIVE,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'handover_notes' => $note,
            ]);

            $lockedDevice->update([
                'lifecycle_status' => DeviceLifecycleStatus::Assigned,
                'updated_by' => $actor->id,
            ]);

            return $created;
        });

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($device)
            ->withProperties([
                'device_public_id' => $device->public_id,
                'assignment_id' => $assignment->id,
                'employee_id' => $employee->id,
            ])
            ->log('device_assigned');

        $this->readiness->refresh($device->fresh(), $actor);

        return $assignment;
    }

    /** @throws AuthorizationException */
    public function returnToInventory(
        Device $device,
        User $actor,
        ?string $location = null,
        ?string $note = null,
    ): void {
        Gate::forUser($actor)->authorize('devices.assign');

        DB::transaction(function () use ($device, $actor, $location, $note): void {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);
            $assignment = DeviceAssignment::query()
                ->where('device_id', $lockedDevice->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($assignment) {
                $assignment->update([
                    'status' => DeviceAssignment::STATUS_RETURNED,
                    'returned_by' => $actor->id,
                    'returned_at' => now(),
                    'handover_notes' => $note ?: $assignment->handover_notes,
                ]);
            }

            $this->retireAssignmentContext($lockedDevice, $actor);

            $lockedDevice->update([
                'lifecycle_status' => DeviceLifecycleStatus::Inventory,
                'declared_location' => $location ?: $lockedDevice->declared_location,
                'updated_by' => $actor->id,
            ]);
        });

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($device)
            ->withProperties([
                'device_public_id' => $device->public_id,
                'declared_location' => $location,
            ])
            ->log('device_returned_to_inventory');

        $this->readiness->refresh($device->fresh(), $actor);
    }

    /** @throws AuthorizationException */
    public function confirmHandover(Device $device, User $actor): void
    {
        Gate::forUser($actor)->authorize('devices.assign');

        DB::transaction(function () use ($device, $actor): void {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->id);
            $assignment = DeviceAssignment::query()
                ->where('device_id', $lockedDevice->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                throw ValidationException::withMessages([
                    'handover' => 'Ohne aktive Mitarbeiterzuweisung kann keine Übergabe bestätigt werden.',
                ]);
            }

            if (! $this->readiness->isReady($lockedDevice)) {
                throw ValidationException::withMessages([
                    'handover' => 'Die Übergabe ist erst möglich, wenn alle Pflichtprüfungen vollständig belegt sind.',
                ]);
            }

            $assignment->update(['handover_at' => now()]);
            $lockedDevice->update([
                'lifecycle_status' => DeviceLifecycleStatus::InService,
                'updated_by' => $actor->id,
            ]);
        });

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($device)
            ->withProperties(['device_public_id' => $device->public_id])
            ->log('device_handover_confirmed');

        $this->readiness->refresh($device->fresh(), $actor);
    }

    /**
     * Preserve historical rows while making all user-bound desired state from
     * the previous handover inert. Commands that have not left RailTime are
     * cancelled; an externally dispatched/running command blocks the handover
     * change because its side effect can no longer be ruled out.
     */
    private function retireAssignmentContext(Device $device, User $actor): void
    {
        $revokedEnrollments = DeviceEnrollment::query()
            ->where('device_id', $device->getKey())
            ->whereIn('status', [
                DeviceEnrollmentStatus::Invited->value,
                DeviceEnrollmentStatus::Claimed->value,
            ])
            ->lockForUpdate()
            ->get();
        foreach ($revokedEnrollments as $enrollment) {
            $enrollment->forceFill([
                'status' => DeviceEnrollmentStatus::Revoked,
                'revoked_at' => now(),
                // Token hashes stay non-null/unique but are rotated so a
                // leaked clear invitation cannot locate a historical row.
                'token_hash' => hash('sha256', random_bytes(32)),
            ])->save();
        }

        $retiredProfiles = DeviceAccountAssignment::query()
            ->where('device_id', $device->getKey())
            ->where(function ($query): void {
                $query->where('desired_state', '!=', 'unassigned')
                    ->orWhere('status', '!=', 'revoked');
            })
            ->lockForUpdate()
            ->get();
        foreach ($retiredProfiles as $profile) {
            $profile->forceFill([
                'desired_state' => 'unassigned',
                'status' => 'revoked',
                'last_attempted_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();
        }

        foreach ($retiredProfiles->whereNotNull('user_id')->groupBy('user_id') as $employeeId => $profiles) {
            $assignment = DeviceAssignment::query()
                ->where('device_id', $device->getKey())
                ->where('user_id', $employeeId)
                ->latest('assigned_at')
                ->first();
            if (! $assignment) {
                continue;
            }

            $profileIds = $profiles->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
            $this->identitySyncs->queueRevocation(
                (int) $device->getKey(),
                (int) $assignment->getKey(),
                (int) $employeeId,
                (int) $actor->getKey(),
                $profileIds,
                assignmentWillReturnInCurrentTransaction: $assignment->status === DeviceAssignment::STATUS_ACTIVE,
            );
        }

        $cancelledCommands = $this->commands->cancelPendingCommandsForAssignmentChange($device, $actor);

        if ($revokedEnrollments->isNotEmpty() || $retiredProfiles->isNotEmpty() || $cancelledCommands > 0) {
            activity('device-management')
                ->causedBy($actor)
                ->performedOn($device)
                ->withProperties([
                    'device_public_id' => $device->public_id,
                    'revoked_enrollment_count' => $revokedEnrollments->count(),
                    'retired_profile_count' => $retiredProfiles->count(),
                    'cancelled_command_count' => $cancelledCommands,
                ])
                ->log('device_assignment_context_retired');
        }
    }
}
