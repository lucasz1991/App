<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * Wenn der User inaktiv ist, wird er ausgeloggt und zur Startseite weitergeleitet.
     */
    public function handle($request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();

            // Prüfe das status-Feld (0 oder false = inaktiv)
            if (empty($user->status) || $user->status == false || $user->status == 0) {
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
            Auth::guard('web')->logout();

                return redirect()->route('login')
                    ->withErrors(['status' => 'Dein Konto ist inaktiv. Bitte wende dich an die Administration.']);
            }
        }

        return $next($request);
    }
}
