<?php

namespace App\Http\Middleware;

use App\Models\User;
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

        $hasRequestedRole = $user instanceof User
            && (in_array($user->role, $roles, true)
                || (in_array('admin', $roles, true) && $user->isAdmin()));

        if (! $hasRequestedRole) {
            $error = ['status' => 'Ihr Konto hat nicht die erforderliche Rolle für diesen Bereich.'];

            return $isAdminArea
                ? redirect()->route('dashboard')->withErrors($error)
                : redirect()->route('login')->withErrors($error);
        }

        return $next($request);
    }
}
