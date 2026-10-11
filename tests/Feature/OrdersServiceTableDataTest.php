<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Operations\OrderLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OrdersServiceTableDataTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->startOfDay());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Mittlere Firma', 'is_active' => true]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $this->customer->id, 'title' => 'Leistung', 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-01 06:00:00', 'ends_at' => '2027-05-31 18:00:00',
            'required_staff' => 7, 'location_name' => 'Hamburg', 'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function orders(int $count): array
    {
        $orders = [];
        for ($index = 1; $index <= $count; $index++) {
            $orders[] = $this->order(['title' => sprintf('Leistung %03d', $index)]);
        }

        return $orders;
    }

    private function shift(Order $order, array $attributes = []): Shift
    {
        return Shift::create(array_merge([
            'order_id' => $order->id, 'title' => 'Frühdienst', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12 06:00:00',
            'ends_at' => '2027-05-12 14:00:00', 'required_staff' => 2, 'status' => 'open',
            'location_name' => 'Hamburg', 'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function assign(Shift $shift, User $user, string $status = 'confirmed'): ShiftAssignment
    {
        return ShiftAssignment::create([
            'shift_id' => $shift->id, 'user_id' => $user->id,
            'status' => $status, 'assigned_by' => $this->admin->id,
        ]);
    }

    private function listing(array $attributes = [])
    {
        return Livewire::actingAs($this->admin)->test(Orders::class, array_merge(['consolidated' => true], $attributes));
    }

    public function test_page_projection_clones_native_paginator_without_replacing_order_models(): void
    {
        $orders = $this->orders(51);
        $component = $this->listing()->assertSet('perPage', 50);
        $original = $component->viewData('orders');
        $items = $component->viewData('orderTableItems');

        $this->assertInstanceOf(LengthAwarePaginator::class, $original);
        $this->assertInstanceOf(LengthAwarePaginator::class, $items);
        $this->assertNotSame($original, $items);
        $this->assertSame('ordersPage', $items->getPageName());
        $this->assertSame(51, $items->total());
        $this->assertSame(50, $items->perPage());
        $this->assertSame(1, $items->currentPage());
        $this->assertCount(50, $items);
        $this->assertInstanceOf(Order::class, $original->first());
        $this->assertSame($original->first(), $items->first()->order);
        $this->assertSame($orders[0]->id, $items->first()->id);

        $component->call('nextPage', 'ordersPage')->assertViewHas('orderTableItems', fn ($page): bool => $page->currentPage() === 2
            && $page->total() === 51 && $page->count() === 1 && $page->first()->id === $orders[50]->id);
    }

    public function test_allowed_page_sizes_reset_only_the_orders_paginator(): void
    {
        $this->orders(101);
        $component = $this->listing()->call('gotoPage', 2, 'ordersPage')->call('gotoPage', 3, 'servicesPage')
            ->set('perPage', 25)->assertHasNoErrors()->assertSet('paginators.ordersPage', 1)
            ->assertSet('paginators.servicesPage', 3)
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->perPage() === 25 && $page->count() === 25 && $page->lastPage() === 5)
            ->call('gotoPage', 4, 'ordersPage')->set('perPage', 100)->assertHasNoErrors()
            ->assertSet('paginators.ordersPage', 1)->assertSet('paginators.servicesPage', 3)
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->perPage() === 100 && $page->count() === 100 && $page->lastPage() === 2);
        $component->set('perPage', 50)->assertViewHas('orderTableItems', fn ($page): bool => $page->perPage() === 50 && $page->count() === 50);
    }

    public function test_invalid_page_sizes_fail_validation_and_cannot_load_unbounded_rows(): void
    {
        $this->orders(51);
        foreach ([0, 1, 9999] as $size) {
            $this->listing()->set('perPage', $size)->assertHasErrors(['perPage' => 'in'])
                ->assertViewHas('orderTableItems', fn ($page): bool => $page->perPage() === 50 && $page->count() === 50);
        }
    }

    public function test_stale_page_clamps_before_relations_load_and_empty_results_return_to_first_page(): void
    {
        $orders = $this->orders(51);
        $component = $this->listing()->call('gotoPage', 999, 'ordersPage')->assertSet('paginators.ordersPage', 2)
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->currentPage() === 2 && $page->first()->id === $orders[50]->id);
        Order::query()->delete();
        $component->call('$refresh')->assertSet('paginators.ordersPage', 1)
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->currentPage() === 1 && $page->total() === 0 && $page->isEmpty());
    }

    public function test_search_status_and_reset_filters_reset_named_page_without_changing_detail_selection(): void
    {
        $orders = $this->orders(51);
        $orders[50]->update(['status' => 'cancelled']);
        $component = $this->listing()->call('selectOrder', $orders[0]->id)->call('gotoPage', 2, 'ordersPage')
            ->set('search', 'Leistung')->assertSet('paginators.ordersPage', 1)->assertSet('selectedOrderId', $orders[0]->id)
            ->call('gotoPage', 2, 'ordersPage')->set('statusFilter', 'cancelled')->assertSet('paginators.ordersPage', 1)
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->total() === 1 && $page->first()->id === $orders[50]->id)
            ->call('resetFilters')->assertSet('search', '')->assertSet('statusFilter', 'all')->assertSet('paginators.ordersPage', 1);
        $this->assertSame(51, $component->viewData('orderTableItems')->total());
    }

    public function test_service_and_legacy_title_sort_globally_before_page_slice(): void
    {
        $this->orders(51);
        $first = $this->order(['title' => 'A zuerst']);
        $last = $this->order(['title' => 'Z zuletzt']);
        $component = $this->listing()->set('perPage', 25)->call('gotoPage', 2, 'ordersPage')
            ->call('tableSort', 'service', 'asc')->assertSet('paginators.ordersPage', 1)
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->first()->id === $first->id)
            ->call('tableSort', 'title', 'desc')->assertViewHas('orderTableItems', fn ($page): bool => $page->first()->id === $last->id);
        $component->call('tableSort', 'service', 'desc')->call('tableSort', 'service')->assertSet('sortDir', 'asc');
    }

    public function test_customer_sort_is_authorized_sql_global_order_not_page_local_order(): void
    {
        $zulu = Customer::create(['company_name' => 'Zulu Firma', 'is_active' => true]);
        $alpha = Customer::create(['company_name' => 'Alpha Firma', 'is_active' => true]);
        $last = $this->order(['customer_id' => $zulu->id]);
        $this->orders(51);
        $first = $this->order(['customer_id' => $alpha->id]);
        $this->listing()->set('perPage', 25)->call('tableSort', 'customer', 'asc')
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->first()->id === $first->id)
            ->call('tableSort', 'customer', 'desc')
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->first()->id === $last->id);
    }

    public function test_period_status_and_legacy_staff_sort_remain_server_validated(): void
    {
        $early = $this->order(['starts_at' => '2027-04-01 06:00:00', 'status' => 'cancelled', 'required_staff' => 1]);
        $late = $this->order(['starts_at' => '2027-05-01 06:00:00', 'status' => 'planned', 'required_staff' => 9]);
        $component = $this->listing()->call('tableSort', 'period', 'asc')
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->first()->id === $early->id)
            ->call('tableSort', 'status', 'desc')->assertViewHas('orderTableItems', fn ($page): bool => $page->first()->id === $late->id)
            ->call('tableSort', 'staff', 'desc')->assertViewHas('orderTableItems', fn ($page): bool => $page->first()->id === $late->id)
            ->call('tableSort', 'untrusted')->assertStatus(422);
        $this->listing()->call('tableSort', 'service', 'invalid')->assertStatus(422);
    }

    public function test_no_shift_order_keeps_contractual_period_and_demand_but_has_no_invented_vacancies(): void
    {
        $order = $this->order();
        $item = $this->listing()->viewData('orderTableItems')->first();
        $this->assertSame($order->id, $item->id);
        $this->assertSame('Europe/Berlin', $item->timezone);
        $this->assertSame('2027-05-01 06:00', $item->from->format('Y-m-d H:i'));
        $this->assertSame('2027-05-31 18:00', $item->until->format('Y-m-d H:i'));
        $this->assertSame(7, $item->requiredStaff);
        $this->assertSame(0, $item->count);
        $this->assertSame(['shifts' => 0, 'required' => 0, 'reserved' => 0, 'confirmed' => 0, 'open' => 0], $item->summary);
        $this->assertCount(0, $item->staffingSeats);
        $this->assertCount(0, $item->staffingUsers);
    }

    public function test_list_contractual_dates_are_not_replaced_by_shift_dates_and_seats_exclude_closed_history(): void
    {
        $order = $this->order();
        $employee = User::factory()->create(['role' => 'staff']);
        $first = $this->shift($order, ['required_staff' => 4]);
        $this->assign($first, $employee);
        $this->assign($first, User::factory()->create(['role' => 'staff']), 'requested');
        $declined = $this->assign($first, User::factory()->create(['role' => 'staff']), 'declined');
        $cancelled = $this->assign($first, User::factory()->create(['role' => 'staff']), 'cancelled');
        $second = $this->shift($order, ['required_staff' => 2, 'starts_at' => '2027-05-25 20:00:00', 'ends_at' => '2027-05-26 04:00:00']);
        $this->assign($second, $employee);
        foreach (['completed', 'cancelled'] as $status) {
            $this->assign($this->shift($order, ['status' => $status, 'required_staff' => 8]), $employee);
        }
        $deleted = $this->shift($order);
        $deleted->delete();
        $component = $this->listing();
        $item = $component->viewData('orderTableItems')->first();
        $this->assertSame('2027-05-01 06:00', $item->from->format('Y-m-d H:i'));
        $this->assertSame('2027-05-31 18:00', $item->until->format('Y-m-d H:i'));
        $this->assertSame(4, $item->count);
        $this->assertSame(['shifts' => 2, 'required' => 6, 'reserved' => 3, 'confirmed' => 2, 'open' => 3], $item->summary);
        $this->assertCount(3, $item->staffingSeats);
        $this->assertCount(2, $item->staffingUsers);
        $this->assertSame(2, $item->staffingSeats->where('user.id', $employee->id)->count());
        $this->assertFalse($item->shifts->flatMap->assignments->contains('id', $declined->id));
        $this->assertFalse($item->shifts->flatMap->assignments->contains('id', $cancelled->id));

        // Detail rendering still loads the complete, nonblocking history independently.
        $component->call('openDetails', $order->id);
        $detail = $component->viewData('serviceDetailItem');
        $this->assertTrue($detail->shifts->flatMap->assignments->contains('id', $declined->id));
        $this->assertTrue($detail->shifts->flatMap->assignments->contains('id', $cancelled->id));
        $this->assertSame('2027-05-12 06:00', $detail->from->format('Y-m-d H:i'));
        $this->assertSame('2027-05-26 04:00', $detail->until->format('Y-m-d H:i'));
    }

    public function test_open_counts_are_per_shift_and_never_net_overstaffing_against_other_vacancies(): void
    {
        $order = $this->order();
        $over = $this->shift($order, ['required_staff' => 1]);
        for ($seat = 0; $seat < 2; $seat++) {
            $this->assign($over, User::factory()->create(['role' => 'staff']));
        }
        $this->shift($order, ['required_staff' => 3]);
        $item = $this->listing()->viewData('orderTableItems')->first();
        $this->assertSame(4, $item->summary['required']);
        $this->assertSame(2, $item->summary['reserved']);
        $this->assertSame(3, $item->summary['open']);
    }

    public function test_relation_batches_are_page_bounded_and_query_count_is_constant_for_one_or_twenty_five_rows(): void
    {
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Lokales Team', 'personal_team' => false]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);
        UserProfile::create(['user_id' => $employee->id, 'first_name' => 'Lokale', 'last_name' => 'Person']);
        $this->assign($this->shift($this->order()), $employee);
        $component = $this->listing()->instance();
        $component->perPage = 25;
        $component->render(app(OrderLifecycleService::class));
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $component->render(app(OrderLifecycleService::class));
            $one = DB::getQueryLog();
            foreach ($this->orders(25) as $order) {
                $this->assign($this->shift($order), $employee);
            }
            DB::flushQueryLog();
            $view = $component->render(app(OrderLifecycleService::class));
            $many = DB::getQueryLog();
            $items = $view->getData()['orderTableItems'];
            $this->assertCount(25, $items);
            $this->assertSame(26, $items->total());
            $this->assertCount(count($one), $many);
            foreach (['shifts', 'shift_assignments', 'users', 'user_profiles', 'teams'] as $table) {
                $reads = array_filter($many, fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select * from "'.$table.'"'));
                $this->assertCount(1, $reads, 'One batched '.$table.' read per current page.');
            }
            foreach ($items as $item) {
                $this->assertTrue($item->order->relationLoaded('customer'));
                $this->assertTrue($item->order->relationLoaded('shifts'));
                $this->assertTrue($item->shifts->first()->relationLoaded('assignments'));
                $this->assertTrue($item->staffingSeats->first()['user']->relationLoaded('profile'));
                $this->assertTrue($item->staffingSeats->first()['user']->relationLoaded('currentTeam'));
            }
            $offPageId = Order::latest('id')->value('id');
            $this->assertFalse($items->contains('id', $offPageId));
            $this->assertFalse($items->flatMap(fn ($item) => $item->shifts)->contains('order_id', $offPageId));

            DB::flushQueryLog();
            $project = new \ReflectionMethod(Orders::class, 'serviceDetailProjection');
            foreach ($items as $item) {
                $project->invoke($component, $item->order);
            }
            $this->assertSame([], DB::getQueryLog(), 'Loaded display projection must not query per row.');
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_customer_scope_and_management_permission_cannot_be_bypassed_by_sort_or_page(): void
    {
        $own = $this->order();
        $otherCustomer = Customer::create(['company_name' => 'Andere Firma', 'is_active' => true]);
        $foreign = $this->order(['customer_id' => $otherCustomer->id]);
        $component = $this->listing(['customerId' => $this->customer->id])->call('tableSort', 'customer', 'desc')
            ->call('gotoPage', 999, 'ordersPage')->assertViewHas('orderTableItems', fn ($page): bool => $page->total() === 1 && $page->first()->id === $own->id);
        Livewire::actingAs(User::factory()->create(['role' => 'staff', 'status' => true]))->test(Orders::class)->assertForbidden();
        Livewire::actingAs(User::factory()->create(['role' => 'admin', 'status' => false]))->test(Orders::class)->assertForbidden();
        $this->actingAs($this->admin);
        $this->expectException(ModelNotFoundException::class);
        $component->call('openDetails', $foreign->id);
    }

    public function test_sort_and_customer_scope_remain_locked_against_public_property_updates(): void
    {
        $this->order();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->listing()->set('sortBy', 'customer');
    }

    public function test_customer_filter_cannot_be_replaced_through_public_updates(): void
    {
        $this->order();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->listing(['customerId' => $this->customer->id])->set('customerFilterId', null);
    }

    public function test_detail_only_stays_empty_and_ordinary_order_tabs_and_selection_actions_are_unchanged(): void
    {
        $order = $this->order();
        $this->shift($order);
        $before = $order->fresh()->getAttributes();
        $component = $this->listing()->call('selectOrder', $order->id)->assertSet('selectedOrderId', $order->id)
            ->assertSet('detailOpen', false)->call('openDetails', $order->id)->assertSet('detailOpen', true)
            ->call('setDetailSection', 'planning')->assertDispatched('rt-workspace-url')
            ->call('closeDetails')->assertSet('detailOpen', false)->assertSet('selectedOrderId', null);
        $this->assertSame(['overview', 'planning', 'history'], array_keys($component->instance()->detailSections()));
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
        $this->listing(['initialOrderId' => $order->id, 'detailOnly' => true, 'embeddedDetail' => true])
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->total() === 0 && $page->isEmpty())
            ->assertNotDispatched('rt-workspace-url');
    }
}
