<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => Hash::make($validated['password']),
            'role' => $invitation->role,
            'status' => true,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $invitation->forceFill(['accepted_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return in_array($user->role, ['admin', 'staff'], true)
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
