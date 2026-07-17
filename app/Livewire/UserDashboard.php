<?php

namespace App\Livewire;

use Livewire\Component;

class UserDashboard extends Component
{
    public function mount(): void
    {
        if (in_array(auth()->user()->role, ['admin', 'staff'], true)) {
            $this->redirectRoute('admin.dashboard');
        }
    }

    public function render()
    {
        return view('livewire.user-dashboard')
            ->layout('layouts.master', ['area' => 'user']);
    }
}
