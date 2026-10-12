<?php

namespace Tests\Feature;

use App\Livewire\Operations\StaffTimeline;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Routing\TravelAssessmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelineDayAttentionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-20T12:00:00+02:00'));
        $this->manager = User::factory()->create(['name' => 'Synthetic planner', 'role' => 'admin', 'status' => true]);
        $this->order = $this->order();
        $this->mock(StaffEligibilityService::class)->shouldNotReceive('assessMany', 'assessPreviewCoreMany');
        $this->mock(TravelAssessmentService::class)->shouldNotReceive('assessMany', 'assessManyForDisplay', 'preflight');
    }

    private function order(array $attributes = []): Order
    {
        $customer = Customer::create(['company_name' => 'Synthetic customer', 'is_active' => true]);

        return Order::create(array_replace(['customer_id' => $customer->id, 'title' => 'Synthetic service',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-01-01T00:00', 'ends_at' => '2028-01-01T00:00',
            'required_staff' => 1, 'created_by' => $this->manager->id], $attributes));
    }

    private function shift(array $attributes = []): Shift
    {
        return Shift::create(array_replace(['order_id' => $this->order->id, 'title' => 'Synthetic shift', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00',
            'required_staff' => 1, 'planned_break_minutes' => 0, 'status' => 'draft', 'created_by' => $this->manager->id], $attributes));
    }

    private function assignment(Shift $shift, string $status, ?User $user = null): ShiftAssignment
    {
        $user ??= User::factory()->create(['role' => 'staff', 'status' => true]);

        return ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id,
            'status' => $status, 'assigned_by' => $this->manager->id]);
    }

    private function summary(string $from = '2027-05-07', string $until = '2027-05-19', ?int $customerId = null): array
    {
        $days = collect();
        for ($day = CarbonImmutable::parse($from, 'Europe/Berlin'); $day->lte(CarbonImmutable::parse($until, 'Europe/Berlin')); $day = $day->addDay()) {
            $days->push($day);
        }
        $timeline = new StaffTimeline;
        $timeline->attentionCustomerId = $customerId;

        return (new \ReflectionMethod(StaffTimeline::class, 'dayAttention'))->invoke($timeline, $days);
    }

    private function timeline(array $attributes = [])
    {
        return Livewire::actingAs($this->manager)->test(StaffTimeline::class, $attributes + [
            'from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true,
            'periodNavigationOwner' => 'synthetic-owner',
        ]);
    }

    public function test_open_places_and_requests_are_separate_but_a_shift_is_counted_once(): void
    {
        $both = $this->shift(['required_staff' => 4]);
        $this->assignment($both, 'confirmed');
        $this->assignment($both, 'requested');
        $this->assignment($both, 'declined');
        $this->assignment($both, 'cancelled');
        $awaiting = $this->shift(['required_staff' => 2]);
        $this->assignment($awaiting, 'requested');
        $this->assignment($awaiting, 'requested');
        $this->shift(['required_staff' => 3]);
        $staffed = $this->shift();
        $this->assignment($staffed, 'confirmed');

        $this->assertSame(['shifts' => 3, 'open_shifts' => 2, 'open_places' => 5,
            'awaiting_shifts' => 2, 'requested_places' => 3], $this->summary()['2027-05-13']);
        $this->assertSame(0, $this->summary()['2027-05-12']['shifts']);
        $this->assertSame(7, ShiftAssignment::count());
    }

    public function test_overnight_and_midnight_endings_use_native_half_open_day_overlap(): void
    {
        $this->shift(['starts_at' => '2027-05-12T22:00', 'ends_at' => '2027-05-13T06:00']);
        $this->shift(['starts_at' => '2027-05-13T20:00', 'ends_at' => '2027-05-14T00:00']);
        $this->shift(['timezone' => 'UTC', 'starts_at' => '2027-05-14T22:00', 'ends_at' => '2027-05-15T02:00']);
        $summary = $this->summary();
        $this->assertSame(1, $summary['2027-05-12']['shifts']);
        $this->assertSame(2, $summary['2027-05-13']['shifts']);
        $this->assertSame(0, $summary['2027-05-14']['shifts'], 'UTC midnight in Berlin belongs only to the next day.');
        $this->assertSame(1, $summary['2027-05-15']['shifts']);
    }

    public function test_dst_days_and_a_long_shift_are_clipped_to_the_loaded_axis(): void
    {
        $this->shift(['starts_at' => '2027-03-27T23:00', 'ends_at' => '2027-03-29T00:00']);
        $this->shift(['starts_at' => '2027-03-01T00:00', 'ends_at' => '2027-04-01T00:00', 'required_staff' => 2]);
        $this->shift(['starts_at' => '2027-03-30T08:00', 'ends_at' => '2027-03-30T16:00']);
        $summary = $this->summary('2027-03-27', '2027-03-29');
        $this->assertSame(['2027-03-27', '2027-03-28', '2027-03-29'], array_keys($summary));
        $this->assertSame(2, $summary['2027-03-27']['shifts']);
        $this->assertSame(2, $summary['2027-03-28']['shifts']);
        $this->assertSame(1, $summary['2027-03-29']['shifts']);
        $this->assertSame(2, $summary['2027-03-29']['open_places']);
        $this->assertSame(23.0, CarbonImmutable::parse('2027-03-28', 'Europe/Berlin')->diffInHours(CarbonImmutable::parse('2027-03-29', 'Europe/Berlin')));
    }

    public function test_closed_and_deleted_shifts_or_orders_are_excluded_without_hiding_unfinished_past_shifts(): void
    {
        $this->shift(); // In the past, but still actionable like the native sidebar.
        $this->shift(['status' => 'completed']);
        $this->shift(['status' => 'cancelled']);
        $this->shift()->delete();
        foreach (['completed', 'invoiced', 'cancelled'] as $status) {
            $this->shift(['order_id' => $this->order(['status' => $status])->id]);
        }
        $deletedOrder = $this->order();
        $this->shift(['order_id' => $deletedOrder->id]);
        $deletedOrder->delete();
        $this->assertSame(1, $this->summary()['2027-05-13']['shifts']);
        $this->assertSame(1, $this->summary()['2027-05-13']['open_places']);
    }

    public function test_counts_cover_all_buffered_days_and_do_not_depend_on_staff_paging_or_staff_search(): void
    {
        $staff = collect(range(1, 48))->map(fn (int $number) => User::factory()->create([
            'name' => sprintf('Synthetic %02d', $number), 'role' => 'staff', 'status' => true,
        ]));
        $this->assignment($this->shift(), 'requested', $staff->last());
        $this->shift(['starts_at' => '2027-05-07T08:00', 'ends_at' => '2027-05-07T16:00']);
        $this->shift(['starts_at' => '2027-05-19T08:00', 'ends_at' => '2027-05-19T16:00']);
        $this->shift(['starts_at' => '2027-05-06T08:00', 'ends_at' => '2027-05-06T16:00']);
        $expected = $this->summary();
        $timeline = $this->timeline()->assertViewHas('dayAttention', $expected)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 24 && ! $rows->pluck('user.id')->contains($staff->last()->id));
        $timeline->call('loadMore')->assertViewHas('dayAttention', $expected)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 48);
        $timeline->set('search', 'No matching staff')->assertViewHas('dayAttention', $expected)
            ->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        $this->assertCount(13, $expected);
        $this->assertSame(1, $expected['2027-05-13']['awaiting_shifts']);
        $this->assertSame(0, $expected['2027-05-13']['open_places']);
    }

    public function test_customer_scope_is_native_and_cannot_be_changed_by_the_client(): void
    {
        $this->shift();
        $other = $this->order();
        $this->shift(['order_id' => $other->id, 'required_staff' => 3]);
        $expected = $this->summary(customerId: $this->order->customer_id);
        $this->assertSame(1, $expected['2027-05-13']['shifts']);
        $this->assertSame(1, $expected['2027-05-13']['open_places']);
        $timeline = $this->timeline(['attentionCustomerId' => $this->order->customer_id])->assertViewHas('dayAttention', $expected);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $timeline->set('attentionCustomerId', $other->customer_id);
    }

    public function test_projection_is_one_bounded_scalar_query_for_many_days_and_shifts(): void
    {
        foreach (range(1, 120) as $number) {
            $this->shift(['title' => 'Never exposed '.$number, 'required_staff' => 2]);
        }
        $this->shift(['starts_at' => '2027-05-20T08:00', 'ends_at' => '2027-05-20T16:00', 'required_staff' => 999]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $summary = $this->summary();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertSame(120, $summary['2027-05-13']['shifts']);
        $this->assertSame(240, $summary['2027-05-13']['open_places']);
        $this->assertSame(0, $summary['2027-05-19']['shifts']);
        $this->assertCount(13, $summary);
        $this->assertStringNotContainsString('Never exposed', json_encode($summary));
        $this->assertStringNotContainsString('routing', strtolower($queries[0]['query']));
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_refresh_uses_fresh_native_status_and_authorization_without_proposals(): void
    {
        $shift = $this->shift();
        $timeline = $this->timeline()->assertViewHas('dayAttention', fn (array $days) => $days['2027-05-13']['shifts'] === 1);
        $this->assignment($shift, 'confirmed');
        $timeline->call('$refresh')->assertViewHas('dayAttention', fn (array $days) => $days['2027-05-13']['shifts'] === 0);
        $this->manager->forceFill(['status' => false])->save();
        $timeline->call('$refresh')->assertForbidden();
    }

    public function test_non_planning_timeline_does_not_load_or_expose_day_attention(): void
    {
        $this->shift();
        $this->timeline(['planningEnabled' => false])->assertViewHas('dayAttention', []);
    }
}
