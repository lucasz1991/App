<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Wartungsmodus-Status aus dem Cache abrufen oder Datenbank abfragen
        $maintenanceSetting = Cache::remember('maintenance_mode', 60, function () {
            return Setting::where('key', 'maintenance_mode')->first() ?? (object) ['value' => false, 'updated_at' => null];
        });

        $maintenanceMode = $maintenanceSetting ? $maintenanceSetting->value : false;
        $lastUpdated = $maintenanceSetting ? $maintenanceSetting->updated_at : null;

        // Falls Wartungsmodus aktiv ist und der Benutzer sich nicht im Admin-Bereich befindet, zeige die Wartungsseite
        if ($maintenanceMode && (! (Auth::user() instanceof User) || ! Auth::user()->isAdmin())) {
            return response()->view('errors.maintenance', ['lastUpdated' => $lastUpdated]);
        }

        return $next($request);
    }
}
