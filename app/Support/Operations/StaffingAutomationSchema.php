<?php

namespace App\Support\Operations;

use App\Services\Operations\WorkforceAccountService;
use Illuminate\Support\Facades\Schema;

final class StaffingAutomationSchema
{
    public static function ready(): bool
    {
        return WorkforcePlanningSchema::ready() && app(WorkforceAccountService::class)->ready()
            && Schema::hasColumns('staffing_automation_runs', ['run_uuid', 'shift_id', 'plan_revision', 'settings_revision', 'supervising_user_id', 'state', 'reason_code', 'wave_count', 'offer_id', 'staffing_case_id', 'next_run_at', 'basis', 'revision'])
            && Schema::hasColumns('operation_audits', ['actor_kind', 'supervising_user_id'])
            && Schema::hasColumns('operations_attention_items', ['recipient_user_id', 'source_key', 'kind', 'module', 'record_id', 'source_revision', 'resolved_at']);
    }
}
