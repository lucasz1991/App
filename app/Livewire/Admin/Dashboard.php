<?php

namespace App\Livewire\Admin;

use App\Models\Team;
use App\Models\User;
use Livewire\Component;

class Dashboard extends Component
{
    public int $totalUsers = 0;

    public int $activeUsers = 0;

    public int $totalEmployees = 0;

    public int $totalTeams = 0;

    public function mount(): void
    {
        $this->totalUsers = User::query()->count();
        $this->activeUsers = User::query()->where('status', true)->count();
        $this->totalEmployees = User::query()->whereIn('role', ['admin', 'staff'])->count();
        $this->totalTeams = Team::query()->where('personal_team', false)->count();
    }

    public function render()
    {
        $recentUsers = User::query()
            ->latest()
            ->limit(6)
            ->get(['id', 'name', 'email', 'role', 'status', 'created_at']);

        return view('livewire.admin.dashboard', compact('recentUsers'))
            ->layout('layouts.master', ['area' => 'admin']);
    }
}
