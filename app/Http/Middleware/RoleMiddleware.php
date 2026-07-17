<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $isAdminArea = $request->routeIs('admin.*') || $request->is('administrator') || $request->is('administrator/*');

        if (! Auth::check()) {
            return $isAdminArea
                ? redirect()->route('admin.login')
                : redirect()->route('login');
        }

        if (empty($roles)) {
            abort(403);
        }

        $user = Auth::user();

        if (! in_array($user->role, $roles, true)) {
            $error = ['status' => 'Dein Konto hat nicht die erforderliche Rolle fuer diesen Bereich.'];

            return $isAdminArea
                ? redirect()->route('dashboard')->withErrors($error)
                : redirect()->route('login')->withErrors($error);
        }

        return $next($request);
    }
}
