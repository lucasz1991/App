<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use App\Models\Setting;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class MaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
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
        if ($maintenanceMode && (!Auth::check() || Auth::user()->role != 'admin')) {
            return response()->view('errors.maintenance', ['lastUpdated' => $lastUpdated]);
        }

        return $next($request);
    }
}
