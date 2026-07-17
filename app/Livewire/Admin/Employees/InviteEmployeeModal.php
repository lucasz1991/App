<?php

namespace App\Livewire\Admin\Employees;

use App\Mail\StaffInvitationMail;
use App\Models\StaffInvitation;
use App\Models\User;
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

    public string $role = 'staff';

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['staff', 'admin'])],
        ];
    }

    #[On('open-invite-employee')]
    public function open(): void
    {
        Gate::authorize('employees.create');

        $this->resetValidation();
        $this->reset(['email', 'role']);
        $this->role = 'staff';
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize('employees.create');

        // Nur Admins duerfen Admin-Einladungen verschicken
        if ($this->role === 'admin' && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        $this->validate();

        // Alte offene Einladungen fuer diese Adresse verwerfen
        StaffInvitation::query()
            ->where('email', $this->email)
            ->whereNull('accepted_at')
            ->delete();

        $invitation = StaffInvitation::create([
            'email' => $this->email,
            'role' => $this->role,
            'token' => Str::random(64),
            'invited_by' => auth()->id(),
            'expires_at' => now()->addDays(7),
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
        return view('livewire.admin.employees.invite-employee-modal');
    }
}
