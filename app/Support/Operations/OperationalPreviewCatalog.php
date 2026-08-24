<?php

namespace App\Support\Operations;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Navigation metadata and live headline figures for the operational workspace.
 *
 * The historical class name is retained because existing dashboard and sidebar
 * links use it as a stable contract. Its contents are productive database data.
 */
final class OperationalPreviewCatalog
{
    /** @return list<string> */
    public static function slugs(): array
    {
        return ['orders', 'shift-management', 'calendar', 'customers'];
    }

    /**
     * Query-free module metadata for navigation and page headings.
     *
     * Operational workspaces only need these labels. Keeping them separate
     * avoids loading every dashboard metric whenever an individual module is
     * opened or Livewire re-renders its navigation.
     *
     * @return array<string, array<string, string>>
     */
    public function definitions(): array
    {
        return [
            'orders' => [
                'slug' => 'orders',
                'title' => __('app.operational_orders'),
                'description' => __('app.operational_orders_description'),
                'icon' => 'clipboard',
                'tone' => 'red',
            ],
            'shift-management' => [
                'slug' => 'shift-management',
                'title' => __('app.shift_management'),
                'description' => __('app.shift_management_description'),
                'icon' => 'clock',
                'tone' => 'amber',
            ],
            'calendar' => [
                'slug' => 'calendar',
                'title' => __('app.operational_calendar'),
                'description' => __('app.operational_calendar_description'),
                'icon' => 'calendar',
                'tone' => 'blue',
            ],
            'customers' => [
                'slug' => 'customers',
                'title' => __('app.customer_database'),
                'description' => __('app.customer_database_description'),
                'icon' => 'briefcase',
                'tone' => 'emerald',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $definitions = $this->definitions();
        $now = now();
        $nowUtc = $now->copy()->utc();
        $activeOrderStatuses = ['requested', 'confirmed', 'planned', 'in_progress'];
        $planningShiftStatuses = ['draft', 'open', 'requested', 'staffed', 'confirmed', 'in_progress'];
        $activeAssignmentStatuses = ['requested', 'confirmed'];

        $orderMetrics = Order::query()
            ->whereIn('status', $activeOrderStatuses)
            ->selectRaw('COUNT(*) as open_orders')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN priority = ? THEN 1 ELSE 0 END), 0) as urgent_orders',
                ['urgent'],
            )
            ->first();
        $openOrders = (int) ($orderMetrics?->open_orders ?? 0);
        $urgentOrders = (int) ($orderMetrics?->urgent_orders ?? 0);

        $planningStatusPlaceholders = implode(', ', array_fill(0, count($planningShiftStatuses), '?'));
        $shiftMetrics = Shift::query()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status IN ({$planningStatusPlaceholders}) AND ends_at >= ? THEN 1 ELSE 0 END), 0) as planning_shifts",
                [...$planningShiftStatuses, $nowUtc],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN starts_at < ? AND ends_at > ? AND status != ? THEN 1 ELSE 0 END), 0) as today_shifts',
                [$now->copy()->endOfDay()->utc(), $now->copy()->startOfDay()->utc(), 'cancelled'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN starts_at < ? AND ends_at > ? AND status != ? THEN 1 ELSE 0 END), 0) as week_shifts',
                [
                    $now->copy()->endOfWeek(Carbon::SUNDAY)->utc(),
                    $now->copy()->startOfWeek(Carbon::MONDAY)->utc(),
                    'cancelled',
                ],
            )
            ->first();
        $planningShifts = (int) ($shiftMetrics?->planning_shifts ?? 0);
        $todayShifts = (int) ($shiftMetrics?->today_shifts ?? 0);
        $weekShifts = (int) ($shiftMetrics?->week_shifts ?? 0);

        $understaffedShifts = Shift::query()
            ->whereIn('status', $planningShiftStatuses)
            ->where('ends_at', '>=', $nowUtc)
            ->withCount([
                'assignments as active_assignments_count' => static fn (Builder $query): Builder => $query
                    ->whereIn('status', $activeAssignmentStatuses),
            ])
            ->get(['id', 'required_staff'])
            ->filter(static fn (Shift $shift): bool => $shift->active_assignments_count < $shift->required_staff)
            ->count();

        $customerMetrics = Customer::query()
            ->selectRaw('COUNT(*) as total_customers')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END), 0) as active_customers',
                [1],
            )
            ->first();
        $activeCustomers = (int) ($customerMetrics?->active_customers ?? 0);
        $totalCustomers = (int) ($customerMetrics?->total_customers ?? 0);

        return [
            'orders' => array_merge($definitions['orders'], [
                'metric' => (string) $openOrders,
                'metric_label' => __('app.operational_open_orders'),
                'badge' => __('app.operational_urgent_orders', ['count' => $urgentOrders]),
                'metric_value' => $openOrders,
                'alert_count' => $urgentOrders,
            ]),
            'shift-management' => array_merge($definitions['shift-management'], [
                'metric' => (string) $planningShifts,
                'metric_label' => __('app.operational_upcoming_shifts'),
                'badge' => __('app.operational_understaffed_shifts', ['count' => $understaffedShifts]),
                'metric_value' => $planningShifts,
                'alert_count' => $understaffedShifts,
            ]),
            'calendar' => array_merge($definitions['calendar'], [
                'metric' => (string) $todayShifts,
                'metric_label' => __('app.operational_shifts_today'),
                'badge' => __('app.operational_shifts_this_week', ['count' => $weekShifts]),
                'metric_value' => $todayShifts,
                'supporting_value' => $weekShifts,
                'alert_count' => 0,
            ]),
            'customers' => array_merge($definitions['customers'], [
                'metric' => (string) $activeCustomers,
                'metric_label' => __('app.operational_active_customers'),
                'badge' => __('app.operational_total_customers', ['count' => $totalCustomers]),
                'metric_value' => $activeCustomers,
                'supporting_value' => $totalCustomers,
                'alert_count' => 0,
            ]),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function dashboard(): array
    {
        return array_values($this->all());
    }
}
