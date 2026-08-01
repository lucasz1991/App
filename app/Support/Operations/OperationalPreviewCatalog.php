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

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $now = now();
        $activeOrderStatuses = ['requested', 'confirmed', 'planned', 'in_progress'];
        $planningShiftStatuses = ['draft', 'open', 'requested', 'staffed', 'confirmed', 'in_progress'];
        $activeAssignmentStatuses = ['requested', 'confirmed'];

        $openOrders = Order::query()->whereIn('status', $activeOrderStatuses)->count();
        $urgentOrders = Order::query()
            ->whereIn('status', $activeOrderStatuses)
            ->where('priority', 'urgent')
            ->count();

        $planningShifts = Shift::query()
            ->whereIn('status', $planningShiftStatuses)
            ->where('ends_at', '>=', $now)
            ->count();
        $understaffedShifts = Shift::query()
            ->whereIn('status', $planningShiftStatuses)
            ->where('ends_at', '>=', $now)
            ->withCount([
                'assignments as active_assignments_count' => static fn (Builder $query): Builder => $query
                    ->whereIn('status', $activeAssignmentStatuses),
            ])
            ->get(['id', 'required_staff'])
            ->filter(static fn (Shift $shift): bool => $shift->active_assignments_count < $shift->required_staff)
            ->count();

        $todayShifts = Shift::query()
            ->where('starts_at', '<', $now->copy()->endOfDay())
            ->where('ends_at', '>', $now->copy()->startOfDay())
            ->where('status', '!=', 'cancelled')
            ->count();
        $weekShifts = Shift::query()
            ->where('starts_at', '<', $now->copy()->endOfWeek(Carbon::SUNDAY))
            ->where('ends_at', '>', $now->copy()->startOfWeek(Carbon::MONDAY))
            ->where('status', '!=', 'cancelled')
            ->count();

        $activeCustomers = Customer::query()->where('is_active', true)->count();
        $totalCustomers = Customer::query()->count();

        return [
            'orders' => [
                'slug' => 'orders',
                'title' => __('app.operational_orders'),
                'description' => __('app.operational_orders_description'),
                'icon' => 'clipboard',
                'tone' => 'red',
                'metric' => (string) $openOrders,
                'metric_label' => __('app.operational_open_orders'),
                'badge' => __('app.operational_urgent_orders', ['count' => $urgentOrders]),
            ],
            'shift-management' => [
                'slug' => 'shift-management',
                'title' => __('app.shift_management'),
                'description' => __('app.shift_management_description'),
                'icon' => 'clock',
                'tone' => 'amber',
                'metric' => (string) $planningShifts,
                'metric_label' => __('app.operational_upcoming_shifts'),
                'badge' => __('app.operational_understaffed_shifts', ['count' => $understaffedShifts]),
            ],
            'calendar' => [
                'slug' => 'calendar',
                'title' => __('app.operational_calendar'),
                'description' => __('app.operational_calendar_description'),
                'icon' => 'calendar',
                'tone' => 'blue',
                'metric' => (string) $todayShifts,
                'metric_label' => __('app.operational_shifts_today'),
                'badge' => __('app.operational_shifts_this_week', ['count' => $weekShifts]),
            ],
            'customers' => [
                'slug' => 'customers',
                'title' => __('app.customer_database'),
                'description' => __('app.customer_database_description'),
                'icon' => 'briefcase',
                'tone' => 'emerald',
                'metric' => (string) $activeCustomers,
                'metric_label' => __('app.operational_active_customers'),
                'badge' => __('app.operational_total_customers', ['count' => $totalCustomers]),
            ],
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
