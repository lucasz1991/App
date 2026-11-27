<?php

namespace App\Livewire\Concerns\Operations;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Operations\PlanningCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Gemeinsamer Filterkern von Schichtplan (ShiftManagement) und Kalender (Calendar):
 * Kunde/Leistung/Zeitraum aus der Adresse (?customer, ?order, ?from/?until),
 * die Suchfelder sowie die beiden Planungsereignisse fuer AI-Assist und Topbar.
 *
 * Bewusst NICHT hier: Statusfilter (Kalender blendet Abgesagtes aus, der
 * Schichtplan nicht), die Suchspalten, die Zeitraumlogik (Wochenanker gegen
 * von/bis) und der Schicht-Query – diese unterscheiden sich fachlich.
 *
 * Erwartet in der Komponente: public string $customerFilter ('all' oder Kunden-ID;
 * im Schichtplan gesperrt, im Kalender frei waehlbar).
 */
trait FiltersShiftPlanning
{
    public string $orderFilter = 'all';

    public string $search = '';

    public string $orderSearch = '';

    protected function displayTimezone(): string
    {
        return (string) config('operations.display_timezone', 'Europe/Berlin');
    }

    /**
     * ?customer=… uebernehmen. Unbekannte oder ungueltige Kunden enden mit 404.
     *
     * @return bool ob die Adresse einen Kunden vorgibt
     */
    protected function applyPlanningCustomerQuery(): bool
    {
        if (! request()->has('customer')) {
            return false;
        }
        $value = request()->query('customer');
        $customerId = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        abort_unless($customerId && Customer::whereKey($customerId)->exists(), 404);
        $this->customerFilter = (string) $customerId;

        return true;
    }

    /**
     * ?order=… uebernehmen; die Leistung muss zum gewaehlten Kunden gehoeren (sonst 404).
     * Zeitraum und Ansicht leitet jede Komponente selbst aus der Leistung ab.
     */
    protected function applyPlanningOrderQuery(): ?Order
    {
        if (! request()->has('order')) {
            return null;
        }
        $value = request()->query('order');
        $orderId = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        abort_unless($orderId, 404);
        $order = Order::query()->when($this->customerFilter !== 'all', fn (Builder $query) => $query->where('customer_id', (int) $this->customerFilter))->find($orderId);
        abort_unless($order, 404);
        $this->orderFilter = (string) $order->id;

        return $order;
    }

    /**
     * ?from=…&until=… als Planungszeitraum; unvollstaendig oder ungueltig endet mit 422.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null Beginn und (exklusives) Ende
     */
    protected function planningRangeFromQuery(): ?array
    {
        if (! request()->hasAny(['from', 'until'])) {
            return null;
        }
        abort_unless(is_string(request()->query('from')) && is_string(request()->query('until')), 422);
        try {
            [$from, $until] = app(PlanningCapacityService::class)->range(request()->query('from'), request()->query('until'));
        } catch (ValidationException) {
            abort(422, 'Ungültiger Planungszeitraum.');
        }

        return [$from, $until];
    }

    /** 'all' → null, gueltige ID → int, alles andere → false. */
    protected function planningFilterId(string $filter): int|false|null
    {
        return $filter === 'all' ? null : filter_var($filter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    /** Meldet Kunde und Leistung; die Leistung muss existieren und zum Kunden gehoeren. */
    protected function dispatchPlanningFilters(?int $customer, ?int $order): void
    {
        if ($order !== null) {
            Order::query()->when($customer !== null, fn (Builder $query) => $query->where('customer_id', $customer))->findOrFail($order);
        }
        $this->dispatch('operations-planning-filters-changed', customer: $customer, order: $order);
    }

    protected function dispatchPlanningPeriod(string $from, string $until): void
    {
        $this->dispatch('operations-period-changed', from: $from, until: $until);
    }
}
