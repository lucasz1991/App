<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CustomerPortalAuthController;
use App\Models\CustomerPortalIdentity;
use App\Support\CustomerPortal\CustomerPortalScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCustomerPortalAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        $identity = Auth::guard('customer_portal')->user();
        $valid = $identity instanceof CustomerPortalIdentity && $identity->active && $identity->email_verified_at && (int) $request->session()->get('customer_portal_identity_revision', 0) === $identity->revision && (int) $request->session()->get('customer_portal_authenticated_at', 0) >= now()->subMinutes(config('customer_portal.session_minutes', 120))->timestamp;
        $memberships = collect();
        if ($valid) {
            $scope = app(CustomerPortalScope::class);
            $memberships = $scope->memberships($identity)->map(fn ($member) => $scope->membership($identity, $member->customer_id));
            $valid = $memberships->isNotEmpty();
        }
        if (! $valid) {
            Auth::guard('customer_portal')->logout();
            $request->session()->forget(['customer_portal_identity_revision', 'customer_portal_authenticated_at', 'customer_portal_mfa_identity', 'customer_portal_mfa_revision', 'customer_portal_mfa_at', 'customer_portal_mfa_login_identity', 'customer_portal_mfa_login_revision', 'customer_portal_mfa_login_at']);
            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect('/kundenportal/anmelden');
        }
        $fresh = $memberships->first()->identity;
        $enabled = (bool) ($fresh->two_factor_confirmed_at && $fresh->two_factor_secret);
        $required = $memberships->contains(fn ($member) => $member->setting->require_mfa);
        $loginVerified = $enabled && (int) $request->session()->get('customer_portal_mfa_login_identity', 0) === $fresh->id && (int) $request->session()->get('customer_portal_mfa_login_revision', 0) === $fresh->revision && (int) $request->session()->get('customer_portal_mfa_login_at', 0) >= (int) $request->session()->get('customer_portal_authenticated_at', 0);
        $method = $request->route()?->getActionMethod();
        $securityAction = $request->route()?->getActionName() === CustomerPortalAuthController::class.'@'.$method && in_array($method, ['logout', 'mfaChallenge', 'verifyMfa', 'mfaSetup', 'beginMfa', 'confirmMfa', 'recoveryCodes', 'disableMfa'], true);
        if (($enabled || $required) && ! $loginVerified && ! $securityAction) {
            if ($request->expectsJson()) {
                abort(403, 'Zusätzliche Bestätigung erforderlich.');
            }

            return redirect($enabled ? '/kundenportal/bestaetigung' : '/kundenportal/sicherheit');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
