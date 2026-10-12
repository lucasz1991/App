<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Privacy\SessionLifetimePolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApplySessionLifetime
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('pwa/session-status')) {
            $guard = Auth::guard('web');
            if (! $request->session()->has($guard->getName())) {
                // A background probe must not silently recreate an expired login
                // from remember-me, including while the DB session is saved later.
                // The browser cookie remains available for explicit navigation.
                $request->cookies->remove($guard->getRecallerName());

                return response()->json(['message' => 'Unauthenticated.'], 401)
                    ->header('Cache-Control', 'private, no-store');
            }
        }
        $apply = function () use ($request): void {
            $user = $request->user();
            config(['session.lifetime' => SessionLifetimePolicy::minutes($user instanceof User ? $user : null)]);
        };
        $apply();
        if ($request->is('pwa/session-status') && $request->user() instanceof User && ! $request->user()->isActive()) {
            return response()->json(['message' => 'Account inactive.'], 403)
                ->header('Cache-Control', 'private, no-store');
        }
        $response = $next($request);
        // Login creates its authenticated identity inside the controller; the
        // enclosing StartSession middleware sets the cookie after this returns.
        $apply();

        return $response;
    }
}
