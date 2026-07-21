<?php

namespace App\Livewire\Admin\Employees;

use App\Mail\StaffInvitationMail;
use App\Models\Setting;
use App\Models\StaffInvitation;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class InviteEmployeeModal extends Component
{
    public bool $showModal = false;

    public string $email = '';

    public ?int $teamId = null;

    public string $position = '';

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'teamId' => ['required', 'integer', 'exists:teams,id'],
            'position' => ['nullable', 'string', 'max:100'],
        ];
    }

    #[On('open-invite-employee')]
    public function open(): void
    {
        Gate::authorize('employees.create');

        $this->resetValidation();
        $this->reset(['email', 'teamId', 'position']);
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize('employees.create');

        $this->validate();

        // Alte offene Einladungen fuer diese Adresse verwerfen
        StaffInvitation::query()
            ->where('email', $this->email)
            ->whereNull('accepted_at')
            ->delete();

        // Gueltigkeitsdauer aus den Einstellungen (Fallback 7 Tage)
        $expiryDays = (int) (Setting::getValue('invitations', 'expiry_days') ?? 7);
        $expiryDays = $expiryDays > 0 ? $expiryDays : 7;

        $invitation = StaffInvitation::create([
            'email' => $this->email,
            'role' => 'staff',
            'team_id' => $this->teamId,
            'position' => $this->position ?: null,
            'token' => Str::random(64),
            'invited_by' => auth()->id(),
            'expires_at' => now()->addDays($expiryDays),
        ]);

        try {
            Mail::to($invitation->email)->send(new StaffInvitationMail($invitation));
        } catch (\Throwable $e) {
            Log::error('Einladungsmail konnte nicht versendet werden', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('swal:toast', type: 'error', text: __('app.invitation_mail_failed'));

            return;
        }

        $this->showModal = false;
        $this->dispatch('swal:toast', type: 'success', text: __('app.invitation_sent'));
    }

    public function close(): void
    {
        $this->showModal = false;
    }

    public function render()
    {
        $teams = Team::query()
            ->where('personal_team', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.employees.invite-employee-modal', compact('teams'));
    }
}
