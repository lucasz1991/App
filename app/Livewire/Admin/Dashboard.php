<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\DeviceManagement\DeviceFleetSnapshot;
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

    /** @var array<string, mixed> */
    public array $system = [];

    public bool $systemLoaded = false;

    public function mount(SystemDashboardData $dashboardData): void
    {
        $this->totalTeams = $dashboardData->teamCount();
    }

    public function render(
        SystemDashboardData $dashboardData,
        OperationalPreviewCatalog $previewCatalog,
        DeviceFleetSnapshot $fleetSnapshot,
    ) {
        // Die Route traegt bereits role:admin — diese Pruefung schuetzt den
        // Fall, dass die Komponente anderswo eingebunden wird.
        abort_unless(auth()->user()?->isAdmin(), 403);

        $canViewSystemData = auth()->user()->canViewSystemDashboard();

        return view('livewire.admin.dashboard', [
            'recentUsers' => $dashboardData->recentUsers(),
            'recentActivity' => $dashboardData->recentActivity(),
            'charts' => $dashboardData->charts(),
            'workforce' => $this->workforceSnapshot(),
            'deviceSnapshot' => auth()->user()->can('devices.view') ? $fleetSnapshot->get() : null,
            'operationalModules' => $previewCatalog->dashboard(),
            'canViewSystemData' => $canViewSystemData,
            'system' => $this->systemLoaded ? $this->system : null,
            'systemLoaded' => $this->systemLoaded,
        ])->layout('layouts.master', ['area' => 'admin']);
    }

    /**
     * Load protected diagnostics only when the administrator opens the panel.
     */
    public function loadSystemData(SystemDashboardData $dashboardData): void
    {
        $user = auth()->user();
        abort_unless($user?->isAdmin() && $user->canViewSystemDashboard(), 403);

        if ($this->systemLoaded) {
            return;
        }

        $system = $dashboardData->system();
        $system['lastActivity'] = $system['lastActivityAt']?->diffForHumans() ?? '—';
        unset($system['lastActivityAt']);

        $this->system = $system;
        $this->systemLoaded = true;
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
        $snapshot = User::query()
            ->where('role', 'staff')
            ->selectRaw('COUNT(*) as aggregate_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as aggregate_active', [1])
            ->first();

        $total = (int) ($snapshot?->aggregate_total ?? 0);
        $active = (int) ($snapshot?->aggregate_active ?? 0);
        $inactive = max(0, $total - $active);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'activeRate' => $total > 0 ? (int) round(($active / $total) * 100) : 0,
            'status' => [
                'labels' => [__('app.enabled_accounts'), __('app.disabled_accounts')],
                'values' => [$active, $inactive],
            ],
        ];
    }
}
