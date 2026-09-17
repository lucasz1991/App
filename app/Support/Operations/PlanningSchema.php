<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\Schema;

final class PlanningSchema
{
    public static function ready(): bool
    {
        return Schema::hasTable('duty_reports') && Schema::hasTable('shift_templates')
            && Schema::hasTable('shift_series') && Schema::hasTable('shift_series_occurrences')
            && Schema::hasTable('shift_sections') && Schema::hasTable('order_demands')
            && Schema::hasColumn('shifts', 'order_demand_id');
    }

    public static function requireReady(): void
    {
        abort_unless(self::ready(), 503, 'Planungserweiterung nicht verfügbar.');
    }
}
