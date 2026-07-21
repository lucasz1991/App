<?php

namespace App\Livewire\Admin;

use App\Support\Dashboard\SystemDashboardData;
use Livewire\Component;

class Dashboard extends Component
{
    public int $totalUsers = 0;

    public int $activeUsers = 0;

    public int $totalEmployees = 0;

    public int $totalTeams = 0;

    public function mount(SystemDashboardData $dashboardData): void
    {
        foreach ($dashboardData->counters() as $property => $value) {
            $this->{$property} = $value;
        }
    }

    public function render(SystemDashboardData $dashboardData)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('livewire.admin.dashboard', [
            'recentUsers' => $dashboardData->recentUsers(),
            'recentActivity' => $dashboardData->recentActivity(),
            'operations' => $dashboardData->operations(),
            'system' => $dashboardData->system(),
        ])->layout('layouts.master', ['area' => 'admin']);
    }
}
