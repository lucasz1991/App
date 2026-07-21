<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class InvitedRegistrationController extends Controller
{
    /**
     * Registrierungsformular fuer eine gueltige Einladung anzeigen.
     */
    public function create(string $token): View
    {
        $invitation = $this->resolveInvitation($token);

        return view('auth.register', [
            'invitation' => $invitation,
        ]);
    }

    /**
     * Registrierung ueber eine Einladung abschliessen.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolveInvitation($token);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($validated, $invitation): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
                'role' => 'staff',
                'status' => true,
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
                'current_team_id' => $invitation->team_id,
            ])->save();

            if ($invitation->team_id) {
                $user->teams()->sync([$invitation->team_id]);
            }

            UserProfile::create([
                'user_id' => $user->id,
                'position' => $invitation->position ?: null,
            ]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return $user->isAdmin()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('dashboard');
    }

    protected function resolveInvitation(string $token): StaffInvitation
    {
        $invitation = StaffInvitation::query()->where('token', $token)->first();

        if (! $invitation || ! $invitation->isUsable() || User::where('email', $invitation->email)->exists()) {
            abort(403, __('app.invitation_invalid'));
        }

        return $invitation;
    }
}
