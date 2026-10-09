<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class WorkforcePlanningSchema
{
    public static function ready(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            if (! OperationsAccess::ready() || ! PlanningSchema::ready()) {
                return false;
            }
            foreach (['workforce_pools', 'workforce_pool_user', 'availability_periods', 'employee_availabilities', 'shift_offers', 'shift_offer_responses', 'shift_transfer_requests', 'plan_variants', 'staffing_cases'] as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            return self::demandsReady() && Schema::hasColumn('shifts', 'disposition_details');
        });
    }

    public static function demandsReady(): bool
    {
        return SchemaReadiness::remember(__METHOD__, function (): bool {
            // Hard requirements must not disappear if an unrelated optional process table is unavailable.
            return Schema::hasTable('order_demands') && Schema::hasColumn('shifts', 'order_demand_id')
                && Schema::hasColumns('order_demands', ['staffing_mode', 'maximum_staff', 'qualification_ids', 'workforce_pool_id']);
        });
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Dispositionsprozess nicht verfügbar.');
    }
}
