<?php

namespace App\Services\Routing;

use App\Support\Routing\RoutingSchema;
use App\Support\Routing\TomTomSettings;
use Illuminate\Support\Facades\DB;

class RoutingUsageService
{
    public function current(): array
    {
        $month = now()->setTimezone('Europe/Berlin')->format('Y-m');
        $row = RoutingSchema::ready() ? DB::table('routing_monthly_usage')->where('month', $month)->first() : null;

        return ['month' => $month, 'routing_transactions' => (int) ($row?->routing_transactions ?? 0), 'map_tiles' => (int) ($row?->map_tiles ?? 0)];
    }

    /** Conditional UPDATE is the atomic cap, including concurrent first-month requests. */
    public function reserve(string $kind, int $amount = 1): bool
    {
        if (! in_array($kind, ['routing_transactions', 'map_tiles'], true) || $amount < 1 || ! RoutingSchema::ready()) {
            return false;
        }
        $limit = (int) TomTomSettings::all()[$kind === 'map_tiles' ? 'tile_monthly_limit' : 'routing_monthly_limit'];
        if ($amount > $limit) {
            return false;
        }
        $month = now()->setTimezone('Europe/Berlin')->format('Y-m');
        DB::table('routing_monthly_usage')->insertOrIgnore(['month' => $month, 'routing_transactions' => 0, 'map_tiles' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('routing_monthly_usage')->where('month', $month)->where($kind, '<=', $limit - $amount)
            ->update([$kind => DB::raw($kind.' + '.$amount), 'updated_at' => now()]) === 1;
    }
}
