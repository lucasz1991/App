<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Symfony\Component\HttpFoundation\Response;

class EmployeeAccountInvitationController extends Controller
{
    use PasswordValidationRules;

    public function create(Request $request, string $token): View
    {
        $user = $this->target($request);
        abort_unless(Password::broker('users')->tokenExists($user, $token), 403, 'Diese Einladung ist nicht mehr gültig. Bitte fordern Sie eine neue Einladung bei der Administration an.');

        return view('auth.employee-account-invitation', ['request' => $request, 'email' => $user->email]);
    }

    public function store(Request $request, string $token): Response
    {
        $validated = $request->validate(['password' => $this->passwordRules()]);
        $verified = false;
        $changedUser = null;
        $status = DB::transaction(function () use ($request, $token, $validated, &$verified, &$changedUser) {
            // Serialize acceptance with deactivation, address changes and other invitations.
            $user = $this->target($request, lock: true);

            return Password::broker('users')->reset([
                'email' => $user->email,
                'id' => $user->id,
                'status' => true,
                'role' => 'staff',
                'token' => $token,
                'password' => $validated['password'],
            ], function (User $target, string $password) use (&$verified, &$changedUser): void {
                $verified = ! $target->hasVerifiedEmail();
                $target->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $target->email_verified_at ?? now(),
                ])->save();
                $changedUser = $target;
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        event(new PasswordReset($changedUser));
        if ($verified) {
            event(new Verified($changedUser));
        }

        // Use the normal login actions, including the existing two-factor challenge.
        $request->merge(['email' => $changedUser->email, 'admin_login' => false, 'remember' => false]);

        return app(Pipeline::class)->send($request)->through([
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ])->then(fn () => redirect()->route('dashboard')->with('status', 'Ihr Zugang ist eingerichtet.'));
    }

    private function target(Request $request, bool $lock = false): User
    {
        $email = $request->query('email');
        abort_unless(is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL), 403);
        $query = User::query()->where('email', $email)->where('role', 'staff')->where('status', true);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($user, 403, 'Diese Einladung ist nicht mehr gültig. Bitte wenden Sie sich an die Administration.');

        return $user;
    }
}
