<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class PlanningEnhancementSchema
{
    public static function ready(): bool
    {
        $required = ['qualification_bundles' => ['id', 'code', 'version', 'name', 'role_name', 'requirements', 'status', 'revision', 'created_by', 'approved_by', 'approved_at', 'created_at', 'updated_at'], 'shift_bundle_snapshots' => ['id', 'shift_id', 'qualification_bundle_id', 'requirements', 'scope', 'bundle_version', 'shift_revision', 'created_by', 'created_at', 'updated_at'], 'planning_teams' => ['id', 'name', 'user_ids', 'revision', 'is_active', 'created_by', 'created_at', 'updated_at'], 'rotation_cycles' => ['id', 'name', 'anchor', 'cycle_days', 'timezone', 'slots', 'exceptions', 'revision', 'created_by', 'created_at', 'updated_at'], 'shift_dependencies' => ['id', 'predecessor_id', 'successor_id', 'transfer_minutes', 'same_employee', 'train_code', 'vehicle_code', 'handover_location', 'revision', 'created_by', 'created_at', 'updated_at'], 'workforce_positions' => ['id', 'name', 'role_name', 'location_name', 'from', 'until', 'target_fte', 'full_time_week_minutes', 'qualification_ids', 'status', 'revision', 'created_by', 'approved_by', 'approved_at', 'created_at', 'updated_at']];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                return false;
            }
        }

        return true;
    }
}
