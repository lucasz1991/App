<?php

namespace App\Services\DeviceManagement;

use App\Models\DeviceAssignment;
use App\Models\DeviceReadinessCheck;
use App\Models\User;
use Throwable;

final class PersonalDeviceSnapshot
{
    /**
     * @return array{available:bool,total:int,ready:int,pending:int,blocked:int}
     */
    public function get(User $user): array
    {
        $requiredCheckKeys = array_keys(DeviceReadinessService::REQUIRED_CHECKS);

        try {
            $personalDeviceIds = DeviceAssignment::query()
                ->select('device_id')
                ->where('user_id', $user->getKey())
                ->where('status', DeviceAssignment::STATUS_ACTIVE)
                ->whereNull('returned_at');

            $readyDevices = DeviceReadinessCheck::query()
                ->select('device_id')
                ->whereIn('device_id', clone $personalDeviceIds)
                ->whereIn('check_key', $requiredCheckKeys)
                ->groupBy('device_id')
                ->havingRaw(
                    'COUNT(DISTINCT CASE WHEN status IN (?, ?) THEN check_key END) = ?',
                    ['passed', 'not_applicable', count($requiredCheckKeys)],
                );

            $blockedDevices = DeviceReadinessCheck::query()
                ->select('device_id')
                ->whereIn('device_id', clone $personalDeviceIds)
                ->whereIn('check_key', $requiredCheckKeys)
                ->whereIn('status', ['failed', 'blocked'])
                ->groupBy('device_id');

            $snapshot = DeviceAssignment::query()
                ->join('devices', 'devices.id', '=', 'device_assignments.device_id')
                ->leftJoinSub(
                    $readyDevices,
                    'ready_personal_devices',
                    'ready_personal_devices.device_id',
                    '=',
                    'devices.id',
                )
                ->leftJoinSub(
                    $blockedDevices,
                    'blocked_personal_devices',
                    'blocked_personal_devices.device_id',
                    '=',
                    'devices.id',
                )
                ->where('device_assignments.user_id', $user->getKey())
                ->where('device_assignments.status', DeviceAssignment::STATUS_ACTIVE)
                ->whereNull('device_assignments.returned_at')
                ->whereNull('devices.deleted_at')
                ->selectRaw('COUNT(DISTINCT devices.id) as aggregate_total')
                ->selectRaw('COUNT(DISTINCT CASE WHEN ready_personal_devices.device_id IS NOT NULL THEN devices.id END) as aggregate_ready')
                ->selectRaw('COUNT(DISTINCT CASE WHEN ready_personal_devices.device_id IS NULL AND blocked_personal_devices.device_id IS NULL THEN devices.id END) as aggregate_pending')
                ->selectRaw('COUNT(DISTINCT CASE WHEN blocked_personal_devices.device_id IS NOT NULL THEN devices.id END) as aggregate_blocked')
                ->first();
        } catch (Throwable) {
            return $this->unavailable();
        }

        return [
            'available' => true,
            'total' => (int) ($snapshot?->aggregate_total ?? 0),
            'ready' => (int) ($snapshot?->aggregate_ready ?? 0),
            'pending' => (int) ($snapshot?->aggregate_pending ?? 0),
            'blocked' => (int) ($snapshot?->aggregate_blocked ?? 0),
        ];
    }

    /**
     * @return array{available:false,total:0,ready:0,pending:0,blocked:0}
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'total' => 0,
            'ready' => 0,
            'pending' => 0,
            'blocked' => 0,
        ];
    }
}
