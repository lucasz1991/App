<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceLifecycleStatus;
use App\Enums\DeviceManagementStatus;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceInventoryService
{
    public function __construct(private readonly DeviceReadinessService $readiness) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     */
    public function create(array $attributes, User $actor): Device
    {
        Gate::forUser($actor)->authorize('devices.manage');

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
            'primary_provider' => ['nullable', 'string', 'max:64'],
        ])->validate();

        foreach ([
            'asset_tag', 'serial_number', 'hostname', 'manufacturer', 'model',
            'os_version', 'declared_location', 'primary_provider',
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

        $device = Device::query()->create([
            ...$data,
            'lifecycle_status' => DeviceLifecycleStatus::Inventory,
            'management_status' => DeviceManagementStatus::Unmanaged,
            'compliance_status' => DeviceComplianceStatus::Unknown,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

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
}
