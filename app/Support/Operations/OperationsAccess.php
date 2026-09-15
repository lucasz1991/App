<?php

namespace App\Support\Operations;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class OperationsAccess
{
    public static function ready(): bool
    {
        $required = ['operation_inquiries', 'operation_audits', 'qualification_types', 'employee_qualifications', 'shift_qualification_requirements', 'absence_requests', 'operations_rule_profiles', 'work_time_entries', 'work_time_events', 'work_time_revisions', 'work_time_exports', 'work_time_export_items'];
        $prefix = Schema::getConnection()->getTablePrefix();
        $required = array_map(fn (string $table) => $prefix.$table, $required);

        return array_diff($required, Schema::getTableListing(null, false)) === []
            && Schema::hasColumns('shifts', ['revision', 'published_revision', 'published_at', 'published_snapshot', 'planned_break_minutes'])
            && Schema::hasColumn('shift_assignments', 'plan_revision');
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Arbeitsbereich nicht verfügbar.');
    }

    public static function authorize(User $actor, string $ability): void
    {
        abort_unless($actor->status, 403);
        Gate::forUser($actor)->authorize($ability);
    }

    public static function isEmployee(?User $actor): bool
    {
        return $actor && $actor->status && $actor->role === 'staff'
            && in_array($actor->dashboardAudience(), ['employee', 'management', 'administration'], true);
    }

    public static function own(User $actor, int $userId): void
    {
        abort_unless(self::isEmployee($actor) && (int) $actor->id === $userId, 403);
    }
}
