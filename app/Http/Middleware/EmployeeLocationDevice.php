<?php

namespace App\Http\Middleware;

use App\Models\MobileLocationInstallation;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeLocationDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(EmployeeLocationSettings::ready(), 503);
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 40 && strlen($token) <= 200, 401);
        $installation = MobileLocationInstallation::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        abort_unless($installation, 401);
        $request->attributes->set('employee_location_installation', $installation);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
