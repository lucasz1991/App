<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class ApplySessionLifetime
{
    public function handle(Request $request, Closure $next)
    {
        $ordinaryMinutes = (int) config('session.ordinary_lifetime', config('session.lifetime', 120));
        $apply = function () use ($request, $ordinaryMinutes): void {
            $user = $request->user();
            config(['session.lifetime' => $user instanceof User && $user->role === 'staff' && $user->isActive()
                ? (int) config('session.employee_lifetime', 4320)
                : $ordinaryMinutes]);
        };
        $apply();
        $response = $next($request);
        // Login creates its authenticated identity inside the controller; the
        // enclosing StartSession middleware sets the cookie after this returns.
        $apply();

        return $response;
    }
}
