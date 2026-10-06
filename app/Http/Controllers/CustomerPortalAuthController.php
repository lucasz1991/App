<?php

namespace App\Http\Controllers;

use App\Models\CustomerPortalIdentity;
use App\Services\CustomerPortal\CustomerPortalInvitationService;
use App\Services\CustomerPortal\CustomerPortalMfaService;
use App\Support\CustomerPortal\CustomerPortalSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CustomerPortalAuthController extends Controller
{
    public function showLogin()
    {
        CustomerPortalSchema::requireReady();

        return response()->view('customer-portal.auth.login')->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function login(Request $request)
    {
        CustomerPortalSchema::requireReady();
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:128']);
        $key = 'cp-login:'.hash('sha256', strtolower(trim($data['email'])).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Bitte später erneut versuchen.']);
        }
        $identity = CustomerPortalIdentity::where('email', strtolower(trim($data['email'])))->where('active', true)->whereNotNull('email_verified_at')->first();
        if (! $identity || ! Hash::check($data['password'], $identity->password) || app(CustomerPortalScope::class)->memberships($identity)->isEmpty()) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Anmeldung nicht möglich.']);
        }
        RateLimiter::clear($key);
        Auth::guard('customer_portal')->login($identity, false);
        $request->session()->regenerate();
        $request->session()->forget(['customer_portal_mfa_identity', 'customer_portal_mfa_revision', 'customer_portal_mfa_at', 'customer_portal_mfa_login_identity', 'customer_portal_mfa_login_revision', 'customer_portal_mfa_login_at']);
        $request->session()->put(['customer_portal_identity_revision' => $identity->revision, 'customer_portal_authenticated_at' => now()->timestamp]);

        return redirect($this->loginDestination($identity));
    }

    public function logout(Request $request)
    {
        Auth::guard('customer_portal')->logout();
        $request->session()->forget(['customer_portal_identity_revision', 'customer_portal_authenticated_at', 'customer_portal_mfa_identity', 'customer_portal_mfa_revision', 'customer_portal_mfa_at', 'customer_portal_mfa_login_identity', 'customer_portal_mfa_login_revision', 'customer_portal_mfa_login_at']);
        $request->session()->regenerate();

        return redirect('/kundenportal/anmelden');
    }

    public function invitation(string $token, CustomerPortalInvitationService $service)
    {
        $invitation = $service->inspect($token);
        $existing = CustomerPortalIdentity::where('email', $invitation->recipient_email)->exists();

        return response()->view('customer-portal.auth.invitation', compact('token', 'existing'))->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function accept(Request $request, string $token, CustomerPortalInvitationService $service)
    {
        $identity = $service->accept($token, $request->only(['name', 'password', 'password_confirmation', 'current_password']));
        Auth::guard('customer_portal')->login($identity, false);
        $request->session()->regenerate();
        $request->session()->forget(['customer_portal_mfa_identity', 'customer_portal_mfa_revision', 'customer_portal_mfa_at', 'customer_portal_mfa_login_identity', 'customer_portal_mfa_login_revision', 'customer_portal_mfa_login_at']);
        $request->session()->put(['customer_portal_identity_revision' => $identity->revision, 'customer_portal_authenticated_at' => now()->timestamp]);

        return redirect($this->loginDestination($identity));
    }

    public function resetRequest()
    {
        CustomerPortalSchema::requireReady();

        return view('customer-portal.auth.reset-request');
    }

    public function requestReset(Request $request, CustomerPortalInvitationService $service)
    {
        $data = $request->validate(['email' => 'required|email|max:254']);
        $service->passwordReset($data['email']);

        return back()->with('status', 'Falls ein aktiver Zugang besteht, erhalten Sie einen Link.');
    }

    public function resetForm(string $token, CustomerPortalInvitationService $service)
    {
        $service->inspect($token, 'reset');

        return response()->view('customer-portal.auth.reset', compact('token'))->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function resetPassword(Request $request, string $token, CustomerPortalInvitationService $service)
    {
        $service->resetPassword($token, $request->only(['password', 'password_confirmation']));

        return redirect('/kundenportal/anmelden')->with('status', 'Passwort aktualisiert.');
    }

    public function mfaChallenge(CustomerPortalMfaService $service)
    {
        $state = $service->state($this->portalIdentity());

        return $this->privateView('customer-portal.auth.mfa', ['enabled' => $state['enabled']]);
    }

    public function verifyMfa(Request $request, CustomerPortalMfaService $service)
    {
        $identity = $this->mfaAction($request, fn () => $service->challenge($this->portalIdentity(), $request->only(['code', 'recovery_code'])));
        if ($identity instanceof Response) {
            return $identity;
        }
        $this->bindMfaSession($request, $identity);

        return redirect('/kundenportal');
    }

    public function mfaSetup(CustomerPortalMfaService $service)
    {
        return $this->privateView('customer-portal.auth.mfa-setup', ['state' => $service->state($this->portalIdentity())]);
    }

    public function beginMfa(Request $request, CustomerPortalMfaService $service)
    {
        $result = $this->mfaAction($request, fn () => $service->begin($this->portalIdentity(), $request->only(['password', 'code', 'recovery_code'])));
        if ($result instanceof Response) {
            return $result;
        }

        return redirect('/kundenportal/sicherheit');
    }

    public function confirmMfa(Request $request, CustomerPortalMfaService $service)
    {
        $result = $this->mfaAction($request, fn () => $service->confirm($this->portalIdentity(), $request->only(['code'])));
        if ($result instanceof Response) {
            return $result;
        }
        $this->bindMfaSession($request, $result['identity']);

        return $this->privateView('customer-portal.auth.mfa-recovery-codes', ['codes' => $result['codes']]);
    }

    public function recoveryCodes(Request $request, CustomerPortalMfaService $service)
    {
        $result = $this->mfaAction($request, fn () => $service->regenerateCodes($this->portalIdentity(), $request->only(['password', 'code', 'recovery_code'])));
        if ($result instanceof Response) {
            return $result;
        }
        $this->bindMfaSession($request, $result['identity']);

        return $this->privateView('customer-portal.auth.mfa-recovery-codes', ['codes' => $result['codes']]);
    }

    public function disableMfa(Request $request, CustomerPortalMfaService $service)
    {
        $identity = $this->mfaAction($request, fn () => $service->disable($this->portalIdentity(), $request->only(['password', 'code', 'recovery_code'])));
        if ($identity instanceof Response) {
            return $identity;
        }
        Auth::guard('customer_portal')->setUser($identity);
        $request->session()->regenerate();
        $request->session()->put('customer_portal_identity_revision', $identity->revision);
        $request->session()->forget(['customer_portal_mfa_identity', 'customer_portal_mfa_revision', 'customer_portal_mfa_at', 'customer_portal_mfa_login_identity', 'customer_portal_mfa_login_revision', 'customer_portal_mfa_login_at']);

        return redirect('/kundenportal/sicherheit')->with('status', 'Zusätzliche Bestätigung deaktiviert.');
    }

    private function portalIdentity(): CustomerPortalIdentity
    {
        $identity = Auth::guard('customer_portal')->user();
        abort_unless($identity instanceof CustomerPortalIdentity, 403);

        return $identity;
    }

    private function mfaAction(Request $request, callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Eingaben prüfen.', 'errors' => $exception->errors()], 422)->header('Cache-Control', 'no-store, private');
            }

            // Laravel's generic validation redirect may flash recovery codes; never retain any MFA input.
            return back()->withErrors($exception->errors())->withInput([])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }
    }

    private function bindMfaSession(Request $request, CustomerPortalIdentity $identity): void
    {
        Auth::guard('customer_portal')->setUser($identity);
        $request->session()->regenerate();
        $request->session()->put(['customer_portal_identity_revision' => $identity->revision, 'customer_portal_mfa_identity' => $identity->id, 'customer_portal_mfa_revision' => $identity->revision, 'customer_portal_mfa_at' => now()->timestamp, 'customer_portal_mfa_login_identity' => $identity->id, 'customer_portal_mfa_login_revision' => $identity->revision, 'customer_portal_mfa_login_at' => now()->timestamp]);
    }

    private function loginDestination(CustomerPortalIdentity $identity): string
    {
        if ($identity->two_factor_confirmed_at && $identity->two_factor_secret) {
            return '/kundenportal/bestaetigung';
        }
        $scope = app(CustomerPortalScope::class);
        foreach ($scope->memberships($identity) as $membership) {
            if ($scope->membership($identity, $membership->customer_id)->setting->require_mfa) {
                return '/kundenportal/sicherheit';
            }
        }

        return '/kundenportal';
    }

    private function privateView(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Content-Security-Policy', "frame-ancestors 'none'");
    }
}
