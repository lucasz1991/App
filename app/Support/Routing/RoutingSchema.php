<?php

namespace App\Support\Routing;

use Illuminate\Support\Facades\Schema;

final class RoutingSchema
{
    public static function ready(): bool
    {
        return Schema::hasTable('routing_monthly_usage');
    }
}
