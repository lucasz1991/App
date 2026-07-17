<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Models\UserProfile as ProfileModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class UserProfile extends Component
{
    public $userId;
    public $user;

    public function mount($userId)
    {
        Gate::authorize('users.profiles.view');

        $this->userId = $userId;
        $this->loadUser();
    }

    public function loadUser(): void
    {
        $this->user = User::findOrFail($this->userId);
    }

    /**
     * Admin-Konten duerfen nur von Admins verwaltet werden
     * (gleiche Regel wie in Admin\Employees).
     */
    protected function guardAdminAccount(User $user): void
    {
        if ($user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }
    }

    public function activateUser(): void
    {
        Gate::authorize('users.edit');

        $this->loadUser();
        $this->guardAdminAccount($this->user);

        if ($this->user && ! $this->user->status) {
            $this->user->update(['status' => true]);
            $this->dispatch('swal:toast', type: 'success', text: __('app.user_activated'));
        } else {
            $this->dispatch('swal:toast', type: 'info', text: __('app.user_already_active'));
        }

        $this->loadUser();
    }

    public function deactivateUser(): void
    {
        Gate::authorize('users.edit');

        $this->loadUser();
        $this->guardAdminAccount($this->user);

        if ($this->user && $this->user->status) {
            $this->user->update(['status' => false]);
            $this->dispatch('swal:toast', type: 'success', text: __('app.user_deactivated'));
        } else {
            $this->dispatch('swal:toast', type: 'info', text: __('app.user_already_inactive'));
        }

        $this->loadUser();
    }

    public function deleteUser()
    {
        Gate::authorize('users.edit');

        $user = User::find($this->userId);

        if (! $user) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.user_not_found'));

            return $this->redirectRoute('admin.employees');
        }

        $this->guardAdminAccount($user);

        if ($user->id === auth()->id()) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.cannot_delete_self'));

            return;
        }

        try {
            DB::transaction(function () use ($user) {
                $user->tokens()->delete();
                $user->deleteProfilePhoto();
                $user->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Benutzer konnte nicht geloescht werden.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('swal:toast', type: 'error', text: __('app.user_delete_failed'));

            return;
        }

        $this->dispatch('swal:toast', type: 'success', text: __('app.user_deleted'));

        return $this->redirectRoute('admin.employees');
    }

    public function render()
    {
        $profile = ProfileModel::firstWhere('user_id', $this->userId);

        return view('livewire.admin.user-profile', [
            'user' => $this->user,
            'profile' => $profile,
        ])->layout('layouts.master');
    }
}
