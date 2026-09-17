<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\DashboardLayout;
use App\Support\Dashboard\SystemDashboardData;
use App\Support\Dashboard\WidgetDataProvider;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Das individuelle Dashboard: pro Person eine eigene Auswahl, Reihenfolge
 * und Groesse der Widgets, die WidgetRegistry ihr laut Rolle/Rechten erlaubt.
 * dashboard_widget_placements traegt nur Abweichungen von den
 * Registry-Vorgaben (siehe DashboardLayout).
 */
class WidgetGrid extends Component
{
    public bool $editing = false;

    #[Locked]
    public array $systemStatus = [];

    public bool $systemStatusLoaded = false;

    public function toggleEditing(): void
    {
        $this->editing = ! $this->editing;
    }

    public function reorder(array $orderedKeys): void
    {
        DashboardLayout::reorder(auth()->user(), array_map('strval', $orderedKeys));
    }

    /**
     * Tastatur-/Touch-Alternative zum Ziehen: tauscht ein Widget mit seinem
     * Nachbarn. $direction ist -1 (nach oben/links) oder 1 (nach unten/rechts).
     */
    public function moveWidget(string $key, int $direction): void
    {
        $user = auth()->user();
        $keys = array_column(array_filter(DashboardLayout::forUser($user), static fn (array $item) => ! $item['hidden']), 'key');
        $index = array_search($key, $keys, true);
        $target = $index === false ? null : $index + $direction;

        if ($target === null || $target < 0 || $target >= count($keys)) {
            return;
        }

        [$keys[$index], $keys[$target]] = [$keys[$target], $keys[$index]];
        DashboardLayout::reorder($user, $keys);
    }

    public function hideWidget(string $key): void
    {
        DashboardLayout::setHidden(auth()->user(), $key, true);
    }

    public function showWidget(string $key): void
    {
        DashboardLayout::setHidden(auth()->user(), $key, false);
    }

    public function setWidgetSize(string $key, string $size): void
    {
        DashboardLayout::setSize(auth()->user(), $key, $size);
    }

    public function setWidgetRows(string $key, int $rows): void
    {
        DashboardLayout::setRows(auth()->user(), $key, $rows);
    }

    public function loadSystemStatus(SystemDashboardData $data): void
    {
        abort_unless(auth()->user()?->canViewSystemDashboard(), 403);

        $system = $data->system();
        $system['lastActivity'] = $system['lastActivityAt']?->diffForHumans() ?? '—';
        unset($system['lastActivityAt']);
        $this->systemStatus = $system;
        $this->systemStatusLoaded = true;
    }

    public function render(WidgetDataProvider $provider)
    {
        $user = auth()->user();
        $layout = DashboardLayout::forUser($user);
        $visible = array_values(array_filter($layout, static fn (array $item) => ! $item['hidden']));
        $hiddenBySection = collect(array_filter($layout, static fn (array $item) => $item['hidden']))
            ->groupBy('section');

        $widgetData = [];
        foreach ($visible as $item) {
            $widgetData[$item['key']] = $item['key'] === 'system_status'
                ? []
                : $provider->data($item['key'], $user, $item['size'], $item['rows']);
        }

        return view('livewire.dashboard.widget-grid', [
            'visible' => $visible,
            'hiddenBySection' => $hiddenBySection,
            'widgetData' => $widgetData,
            'hasHidden' => $hiddenBySection->isNotEmpty(),
        ]);
    }
}
