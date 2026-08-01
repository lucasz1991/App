<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\Dashboard\SystemDashboardData;
use App\Support\Operations\OperationalPreviewCatalog;
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

    public function render(SystemDashboardData $dashboardData, OperationalPreviewCatalog $previewCatalog)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $canViewSystemData = auth()->user()->canViewSystemDashboard();
        $workforce = $this->workforceSnapshot();

        return view('livewire.admin.dashboard', [
            'recentUsers' => $dashboardData->recentUsers(),
            'recentActivity' => $dashboardData->recentActivity(),
            'operations' => $dashboardData->operations(),
            'charts' => $dashboardData->charts(),
            'workforce' => $workforce,
            'operationalModules' => auth()->user()?->role === 'admin'
                ? $previewCatalog->dashboard()
                : [],
            'canViewSystemData' => $canViewSystemData,
            'system' => $canViewSystemData ? $dashboardData->system() : null,
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    /**
     * Produktive Personalkennzahlen fuer den Admin-Einstieg.
     *
     * Auftrags- und Schichtwerte werden über den getrennten Operations-Katalog
     * geladen. Dadurch bleibt die Dashboard-Abfrage kompakt, während alle
     * Kennzahlen aus denselben produktiven Modellen wie die Arbeitsbereiche
     * stammen.
     *
     * @return array{
     *     total:int,
     *     active:int,
     *     inactive:int,
     *     activeRate:int,
     *     status:array{labels:array<int, string>, values:array<int, int>}
     * }
     */
    private function workforceSnapshot(): array
    {
        $employees = User::query()->where('role', 'staff');
        $total = (clone $employees)->count();
        $active = (clone $employees)->where('status', true)->count();
        $inactive = max(0, $total - $active);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'activeRate' => $total > 0 ? (int) round(($active / $total) * 100) : 0,
            'status' => [
                'labels' => [__('app.active_users'), __('app.inactive_users')],
                'values' => [$active, $inactive],
            ],
        ];
    }
}
