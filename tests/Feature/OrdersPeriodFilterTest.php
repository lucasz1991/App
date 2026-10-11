<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\Orders;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OrdersPeriodFilterTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12 08:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->customer = Customer::create(['company_name' => 'Zeitraum Firma', 'is_active' => true]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $this->customer->id, 'title' => 'Zeitraumleistung', 'service_type' => 'Tf',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-12 06:00:00', 'ends_at' => '2027-05-12 14:00:00',
            'required_staff' => 2, 'location_name' => 'Hamburg', 'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function listing(array $attributes = [])
    {
        return Livewire::actingAs($this->admin)->test(Orders::class, array_merge(['consolidated' => true], $attributes));
    }

    private function period(string $from, string $until, array $attributes = [])
    {
        return Livewire::actingAs($this->admin)->withQueryParams(compact('from', 'until'))
            ->test(Orders::class, array_merge(['consolidated' => true], $attributes));
    }

    private function ids($component): array
    {
        return $component->viewData('orderTableItems')->pluck('id')->all();
    }

    public function test_default_is_thirty_local_calendar_days_back_and_ahead_and_filters_contracts(): void
    {
        $this->order(['title' => 'Vor dem Fenster', 'starts_at' => '2027-04-10 08:00:00', 'ends_at' => '2027-04-11 23:59:59']);
        $inside = $this->order();
        $this->order(['title' => 'Nach dem Fenster', 'starts_at' => '2027-06-12 00:00:00', 'ends_at' => '2027-06-12 08:00:00']);
        $component = $this->listing()->assertSet('rangeFrom', '2027-04-12')->assertSet('rangeTo', '2027-06-11')
            ->assertDispatched('operations-period-changed', from: '2027-04-12', until: '2027-06-11');
        $this->assertSame([$inside->id], $this->ids($component));
        $this->assertSame('Europe/Berlin', $component->viewData('displayTimezone'));
    }

    public function test_default_today_uses_display_timezone_rather_than_utc_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-05-11 22:30:00', 'UTC'));
        $this->listing()->assertSet('rangeFrom', '2027-04-12')->assertSet('rangeTo', '2027-06-11');
        config(['operations.display_timezone' => 'America/New_York']);
        $this->listing()->assertSet('rangeFrom', '2027-04-11')->assertSet('rangeTo', '2027-06-10')
            ->assertViewHas('displayTimezone', 'America/New_York');
    }

    public function test_invalid_display_timezone_falls_back_to_valid_berlin_day_boundaries(): void
    {
        config(['operations.display_timezone' => 'not/an/iana/timezone']);
        $order = $this->order(['timezone' => 'UTC', 'starts_at' => '2027-05-11 22:00:00', 'ends_at' => '2027-05-11 23:00:00']);
        $component = $this->period('2027-05-12', '2027-05-12')->assertViewHas('displayTimezone', 'Europe/Berlin');
        $this->assertSame([$order->id], $this->ids($component));
    }

    public function test_explicit_period_keeps_existing_search_status_and_native_detail_deep_link(): void
    {
        $order = $this->order(['title' => 'Deep-Link Leistung', 'status' => 'planned']);
        $component = Livewire::actingAs($this->admin)->withQueryParams([
            'from' => '2027-05-10', 'until' => '2027-05-20', 'search' => 'Deep-Link',
            'status' => 'planned', 'order' => (string) $order->id,
        ])->test(Orders::class, ['consolidated' => true, 'initialSection' => 'planning'])
            ->assertSet('rangeFrom', '2027-05-10')->assertSet('rangeTo', '2027-05-20')
            ->assertSet('search', 'Deep-Link')->assertSet('statusFilter', 'planned')
            ->assertSet('selectedOrderId', $order->id)->assertSet('detailOpen', true)->assertSet('detailSection', 'planning');
        $this->assertSame([$order->id], $this->ids($component));
    }

    public function test_optional_query_date_contract_retains_legacy_no_date_shape(): void
    {
        $this->assertSame(['search' => '', 'status' => 'all'], Orders::listState([]));
        $this->assertSame(['search' => 'Dienst', 'status' => 'confirmed', 'from' => '2027-05-10', 'until' => '2027-05-20'],
            Orders::listState(['search' => 'Dienst', 'status' => 'confirmed', 'from' => '2027-05-10', 'until' => '2027-05-20']));
    }

    public function test_invalid_mounted_date_pairs_are_rejected_with_422_instead_of_silent_normalization(): void
    {
        foreach ([
            ['from' => '2027-05-10'], ['until' => '2027-05-20'],
            ['from' => [], 'until' => '2027-05-20'],
            ['from' => '2027-05-10', 'until' => '2027-02-30'],
            ['from' => '2027-5-10', 'until' => '2027-05-20'],
            ['from' => '2027-05-10T00:00', 'until' => '2027-05-20'],
            ['from' => "2027-05-10\0", 'until' => '2027-05-20'],
            ['from' => '0000-01-01', 'until' => '0000-01-02'],
            ['from' => '2027-05-20', 'until' => '2027-05-10'],
            ['from' => '2027-01-01', 'until' => '2027-04-05'],
        ] as $query) {
            Livewire::actingAs($this->admin)->withQueryParams($query)->test(Orders::class, ['consolidated' => true])->assertStatus(422);
        }
    }

    public function test_invalid_apply_keeps_the_previous_period_page_and_emits_no_success_url(): void
    {
        foreach ([
            ['2027-02-30', '2027-05-20', 'rangeFrom'],
            ['2027-05-20', '2027-05-10', 'rangeTo'],
            ['2027-01-01', '2027-04-05', 'rangeTo'],
            ['2027-5-10', '2027-05-20', 'rangeFrom'],
            ["2027-05-10\0", '2027-05-20', 'rangeFrom'],
        ] as [$from, $until, $field]) {
            $this->listing()->call('applyPeriod', $from, $until)->assertHasErrors([$field])
                ->assertSet('rangeFrom', '2027-04-12')->assertSet('rangeTo', '2027-06-11')
                ->assertNotDispatched('rt-workspace-url');
        }
    }

    public function test_maximum_94_calendar_days_is_inclusive_and_independent_of_dst(): void
    {
        $this->listing()->call('applyPeriod', '2027-01-01', '2027-04-04')->assertHasNoErrors()
            ->assertSet('rangeFrom', '2027-01-01')->assertSet('rangeTo', '2027-04-04');
        $this->listing()->call('applyPeriod', '2027-09-01', '2027-12-03')->assertHasNoErrors()
            ->assertSet('rangeFrom', '2027-09-01')->assertSet('rangeTo', '2027-12-03');
        $this->listing()->call('applyPeriod', '2027-09-01', '2027-12-04')->assertHasErrors(['rangeTo']);
    }

    public function test_overlap_includes_both_day_boundaries_spanning_contracts_and_not_nearby_nonoverlaps(): void
    {
        $left = $this->order(['starts_at' => '2027-05-11 22:00:00', 'ends_at' => '2027-05-12 00:00:00']);
        $right = $this->order(['starts_at' => '2027-05-12 23:59:59', 'ends_at' => '2027-05-13 08:00:00']);
        $span = $this->order(['starts_at' => '2027-05-01 00:00:00', 'ends_at' => '2027-05-31 23:59:59']);
        $inside = $this->order();
        $this->order(['starts_at' => '2027-05-11 08:00:00', 'ends_at' => '2027-05-11 23:59:59']);
        $this->order(['starts_at' => '2027-05-13 00:00:00', 'ends_at' => '2027-05-13 08:00:00']);
        $actual = $this->ids($this->period('2027-05-12', '2027-05-12'));
        $this->assertEqualsCanonicalizing([$left->id, $right->id, $span->id, $inside->id], $actual);
    }

    public function test_spring_forward_day_is_23_hours_and_uses_correct_utc_overlap(): void
    {
        $left = $this->order(['timezone' => 'UTC', 'starts_at' => '2027-03-27 22:00:00', 'ends_at' => '2027-03-27 23:00:00']);
        $right = $this->order(['timezone' => 'UTC', 'starts_at' => '2027-03-28 21:59:59', 'ends_at' => '2027-03-28 23:00:00']);
        $this->order(['timezone' => 'UTC', 'starts_at' => '2027-03-27 20:00:00', 'ends_at' => '2027-03-27 22:59:59']);
        $this->order(['timezone' => 'UTC', 'starts_at' => '2027-03-28 22:00:00', 'ends_at' => '2027-03-28 23:00:00']);
        $this->assertEqualsCanonicalizing([$left->id, $right->id], $this->ids($this->period('2027-03-28', '2027-03-28')));
    }

    public function test_fall_back_day_is_25_hours_and_uses_correct_utc_overlap(): void
    {
        $left = $this->order(['timezone' => 'UTC', 'starts_at' => '2027-10-30 21:00:00', 'ends_at' => '2027-10-30 22:00:00']);
        $right = $this->order(['timezone' => 'UTC', 'starts_at' => '2027-10-31 22:59:59', 'ends_at' => '2027-11-01 00:00:00']);
        $this->order(['timezone' => 'UTC', 'starts_at' => '2027-10-30 20:00:00', 'ends_at' => '2027-10-30 21:59:59']);
        $this->order(['timezone' => 'UTC', 'starts_at' => '2027-10-31 23:00:00', 'ends_at' => '2027-11-01 00:00:00']);
        $this->assertEqualsCanonicalizing([$left->id, $right->id], $this->ids($this->period('2027-10-31', '2027-10-31')));
    }

    public function test_utc_display_timezone_uses_utc_midnights_not_berlin_midnights(): void
    {
        config(['operations.display_timezone' => 'UTC']);
        $inside = $this->order(['timezone' => 'UTC', 'starts_at' => '2027-05-12 23:30:00', 'ends_at' => '2027-05-12 23:59:59']);
        $this->order(['timezone' => 'UTC', 'starts_at' => '2027-05-11 22:00:00', 'ends_at' => '2027-05-11 23:00:00']);
        $this->assertSame([$inside->id], $this->ids($this->period('2027-05-12', '2027-05-12')));
    }

    public function test_period_is_contractual_not_derived_from_constituent_shifts(): void
    {
        $inside = $this->order();
        $outside = $this->order(['starts_at' => '2027-04-01 00:00:00', 'ends_at' => '2027-04-10 00:00:00']);
        foreach ([[$inside, '2027-04-01 06:00:00'], [$outside, '2027-05-12 06:00:00']] as [$order, $start]) {
            Shift::create(['order_id' => $order->id, 'title' => 'Abweichende Schicht', 'role_name' => 'Tf',
                'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => CarbonImmutable::parse($start)->addHours(8),
                'required_staff' => 1, 'status' => 'open', 'created_by' => $this->admin->id]);
        }
        $this->assertSame([$inside->id], $this->ids($this->period('2027-05-12', '2027-05-12')));
    }

    public function test_apply_resets_only_orders_page_and_preserves_search_status_selection_and_url_dates(): void
    {
        $orders = [];
        for ($index = 1; $index <= 51; $index++) {
            $orders[] = $this->order(['title' => 'Treffer '.$index, 'status' => 'planned']);
        }
        $component = $this->listing()->set('search', 'Treffer')->set('statusFilter', 'planned')
            ->call('selectOrder', $orders[0]->id)->call('gotoPage', 2, 'ordersPage')->call('gotoPage', 3, 'servicesPage')
            ->call('applyPeriod', '2027-05-10', '2027-05-20')->assertHasNoErrors()
            ->assertSet('paginators.ordersPage', 1)->assertSet('paginators.servicesPage', 3)
            ->assertSet('selectedOrderId', $orders[0]->id)->assertSet('search', 'Treffer')->assertSet('statusFilter', 'planned')
            ->assertDispatched('operations-period-changed', from: '2027-05-10', until: '2027-05-20');
        $component->assertDispatched('rt-workspace-url', function (string $event, array $payload) use ($orders): bool {
            parse_str(parse_url($payload['url'], PHP_URL_QUERY), $query);

            return ($query['from'] ?? '') === '2027-05-10' && ($query['until'] ?? '') === '2027-05-20'
                && ($query['search'] ?? '') === 'Treffer' && ($query['status'] ?? '') === 'planned'
                && (int) ($query['order'] ?? 0) === $orders[0]->id;
        });
        $component->call('resetFilters')->assertSet('rangeFrom', '2027-05-10')->assertSet('rangeTo', '2027-05-20');
    }

    public function test_selected_out_of_period_order_remains_accessible_through_native_details_without_writes(): void
    {
        $order = $this->order(['starts_at' => '2027-01-01 08:00:00', 'ends_at' => '2027-01-01 16:00:00']);
        $before = $order->fresh()->getAttributes();
        $component = $this->listing()->assertViewHas('orderTableItems', fn ($page): bool => $page->total() === 0)
            ->call('openDetails', $order->id)->assertSet('selectedOrderId', $order->id)->assertSet('detailOpen', true)
            ->call('setDetailSection', 'planning')->assertSet('detailSection', 'planning')
            ->call('applyPeriod', '2027-06-01', '2027-06-05')->assertSet('selectedOrderId', $order->id)
            ->assertViewHas('selectedOrder', fn ($selected): bool => $selected->id === $order->id);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(1, Order::count());
        $this->assertSame(0, Shift::count());
        $component->assertDispatched('rt-workspace-url', function (string $event, array $payload) use ($order): bool {
            parse_str(parse_url($payload['url'], PHP_URL_QUERY), $query);

            return ($query['section'] ?? '') === 'planning' && (int) ($query['order'] ?? 0) === $order->id
                && ($query['from'] ?? '') === '2027-06-01' && ($query['until'] ?? '') === '2027-06-05';
        });
    }

    public function test_profile_and_detail_embeds_ignore_request_period_and_reject_period_apply(): void
    {
        $order = $this->order(['starts_at' => '2027-01-01 08:00:00', 'ends_at' => '2027-01-01 16:00:00']);
        $profile = Livewire::actingAs($this->admin)->withQueryParams(['from' => 'invalid', 'until' => 'also-invalid'])
            ->test(Orders::class, ['consolidated' => true, 'profileEmbedded' => true, 'customerId' => $this->customer->id])
            ->assertSet('rangeFrom', '')->assertSet('rangeTo', '')->assertNotDispatched('operations-period-changed')
            ->assertViewHas('orderTableItems', fn ($page): bool => $page->total() === 1 && $page->first()->id === $order->id);
        $profile->call('applyPeriod', '2027-05-10', '2027-05-20')->assertStatus(403);
        Livewire::actingAs($this->admin)->withQueryParams(['from' => 'invalid', 'until' => 'also-invalid'])
            ->test(Orders::class, ['consolidated' => true, 'detailOnly' => true, 'embeddedDetail' => true, 'initialOrderId' => $order->id])
            ->assertSet('rangeFrom', '')->assertSet('rangeTo', '')->assertNotDispatched('operations-period-changed')
            ->assertViewHas('selectedOrder', fn ($selected): bool => $selected->id === $order->id)
            ->call('applyPeriod', '2027-05-10', '2027-05-20')->assertStatus(403);
        Livewire::actingAs($this->admin)->withQueryParams(['from' => 'invalid', 'until' => 'also-invalid'])
            ->test(Orders::class)->assertViewHas('orderTableItems', fn ($page): bool => $page->total() === 1)
            ->assertSet('rangeFrom', '')->call('applyPeriod', '2027-05-10', '2027-05-20')->assertStatus(403);
    }

    public function test_period_cannot_bypass_locked_customer_scope_or_existing_management_permissions(): void
    {
        $own = $this->order();
        $otherCustomer = Customer::create(['company_name' => 'Andere Firma', 'is_active' => true]);
        $foreign = $this->order(['customer_id' => $otherCustomer->id]);
        $component = $this->listing(['customerId' => $this->customer->id])->call('applyPeriod', '2027-05-10', '2027-05-20');
        $this->assertSame([$own->id], $this->ids($component));
        Livewire::actingAs(User::factory()->create(['role' => 'staff', 'status' => true]))->test(Orders::class, ['consolidated' => true])->assertForbidden();
        $this->actingAs($this->admin);
        $this->expectException(ModelNotFoundException::class);
        $component->call('openDetails', $foreign->id);
    }

    public function test_direct_invalid_public_period_update_is_validated_before_query_hydration(): void
    {
        $component = $this->listing();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $component->set('rangeFrom', '2027-02-30')->assertHasErrors(['rangeFrom']);
            $reads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select * from "shifts"'));
            $this->assertSame([], array_values($reads));
        } finally {
            DB::disableQueryLog();
        }
    }
}
