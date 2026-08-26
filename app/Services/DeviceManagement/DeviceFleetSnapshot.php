<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceLifecycleStatus;
use App\Enums\DeviceManagementStatus;
use App\Models\Device;
use App\Models\DeviceAssignment;
use Throwable;

final class DeviceFleetSnapshot
{
    /**
     * @return array{available:bool,total:int,assigned:int,inventory:int,attention:int}
     */
    public function get(): array
    {
        try {
            $activeAssignments = DeviceAssignment::query()
                ->select('device_id')
                ->active()
                ->groupBy('device_id');

            $snapshot = Device::query()
                ->leftJoinSub(
                    $activeAssignments,
                    'active_device_assignments',
                    'active_device_assignments.device_id',
                    '=',
                    'devices.id',
                )
                ->selectRaw('COUNT(devices.id) as aggregate_total')
                ->selectRaw('COALESCE(SUM(CASE WHEN active_device_assignments.device_id IS NOT NULL THEN 1 ELSE 0 END), 0) as aggregate_assigned')
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN devices.lifecycle_status = ? THEN 1 ELSE 0 END), 0) as aggregate_inventory',
                    [DeviceLifecycleStatus::Inventory->value],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN devices.compliance_status IN (?, ?) OR devices.management_status = ? THEN 1 ELSE 0 END), 0) as aggregate_attention',
                    [
                        DeviceComplianceStatus::Warning->value,
                        DeviceComplianceStatus::NonCompliant->value,
                        DeviceManagementStatus::Error->value,
                    ],
                )
                ->first();
        } catch (Throwable) {
            return $this->unavailable();
        }

        return [
            'available' => true,
            'total' => (int) ($snapshot?->aggregate_total ?? 0),
            'assigned' => (int) ($snapshot?->aggregate_assigned ?? 0),
            'inventory' => (int) ($snapshot?->aggregate_inventory ?? 0),
            'attention' => (int) ($snapshot?->aggregate_attention ?? 0),
        ];
    }

    /**
     * @return array{available:false,total:0,assigned:0,inventory:0,attention:0}
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'total' => 0,
            'assigned' => 0,
            'inventory' => 0,
            'attention' => 0,
        ];
    }
}
