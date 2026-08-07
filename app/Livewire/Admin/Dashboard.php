<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\Dashboard\SystemDashboardData;
use App\Support\Operations\OperationalPreviewCatalog;
use Livewire\Component;

class Dashboard extends Component
{
    /**
     * Anzahl der fachlichen Teams (Rechtegruppen).
     *
     * Die uebrigen Werte aus SystemDashboardData::counters() — totalUsers,
     * activeUsers, totalEmployees — wurden hier frueher ebenfalls geladen,
     * aber nie ausgegeben. Der Personalbestand kommt aus workforceSnapshot().
     */
    public int $totalTeams = 0;

    public function mount(SystemDashboardData $dashboardData): void
    {
        $this->totalTeams = $dashboardData->teamCount();
    }

    public function render(SystemDashboardData $dashboardData, OperationalPreviewCatalog $previewCatalog)
    {
        // Die Route traegt bereits role:admin — diese Pruefung schuetzt den
        // Fall, dass die Komponente anderswo eingebunden wird.
        abort_unless(auth()->user()?->isAdmin(), 403);

        $canViewSystemData = auth()->user()->canViewSystemDashboard();

        return view('livewire.admin.dashboard', [
            'recentUsers' => $dashboardData->recentUsers(),
            'recentActivity' => $dashboardData->recentActivity(),
            'operations' => $dashboardData->operations(),
            'charts' => $dashboardData->charts(),
            'workforce' => $this->workforceSnapshot(),
            'operationalModules' => $previewCatalog->dashboard(),
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
