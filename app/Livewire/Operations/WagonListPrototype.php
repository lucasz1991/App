<?php

namespace App\Livewire\Operations;

use Livewire\Component;

class WagonListPrototype extends Component
{
    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array($user->dashboardAudience(), [
            'admin',
            'administration',
            'management',
            'employee',
        ], true), 403);

    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.operations.wagon-list-prototype', [
            'storageKey' => sprintf('rt-wagon-list-prototype:v1:%d', $user->id),
        ])->layout('layouts.master', ['area' => $user->usesAdminLayout() ? 'admin' : 'user']);
    }
}
