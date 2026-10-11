<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\TimelinePlanningSuggestionService;
use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelinePreviewRouteBatchTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private Order $order;

    private string $cacheDirectory;

    private array $blockedOrigins = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
        (require database_path('migrations/2026_10_11_120100_create_routing_monthly_usage_table.php'))->up();
        Schema::table('shifts', function (Blueprint $table): void {
            $table->json('location_snapshot')->nullable();
            $table->json('disposition_details')->nullable();
        });
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T06:00:00+02:00'));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Configured rules', 'minimum_rest_minutes' => 30, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Synthetic batch fixture', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic service', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T00:00:00+02:00', 'ends_at' => '2027-05-13T00:00:00+02:00', 'required_staff' => 120, 'created_by' => $this->actor->id]);
        $this->cacheDirectory = storage_path('framework/testing/timeline-batch-cache-'.Str::uuid());
        app()->instance(ServerRoutingCache::class, new ServerRoutingCache($this->cacheDirectory));
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]), 'synthetic-batch-cache-key');
        Http::preventStrayRequests();
        Http::fake(['api.tomtom.com/routing/*' => function (Request $request) {
            $blocked = collect($this->blockedOrigins)->contains(fn ($origin) => str_contains($request->url(), '/calculateRoute/'.number_format($origin, 7, '.', '').','));

            return Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => $blocked ? 3600 : 60, 'lengthInMeters' => 20000]]]], 200, ['Cache-Control' => 'no-cache, no-transform']);
        }]);
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDirectory) && str_starts_with($this->cacheDirectory, storage_path('framework/testing/timeline-batch-cache-'))) {
            File::deleteDirectory($this->cacheDirectory);
        }
        parent::tearDown();
    }

    private function shift(string $start = '09:00', string $end = '10:00', float $latitude = 52.52, int $required = 1): Shift
    {
        return Shift::create(['order_id' => $this->order->id, 'title' => 'Synthetic '.$start.' duty', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T'.$start.':00+02:00', 'ends_at' => '2027-05-12T'.$end.':00+02:00', 'required_staff' => $required, 'planned_break_minutes' => 0, 'status' => 'draft', 'location_name' => 'Confirmed synthetic worksite', 'location_snapshot' => ['name' => 'Confirmed synthetic worksite', 'latitude' => $latitude, 'longitude' => 13.4, 'revision' => 1, 'confirmed' => true], 'created_by' => $this->actor->id])->fresh();
    }

    private function staff(int $count): Collection
    {
        return collect(range(0, $count - 1))->map(function (int $index): User {
            $user = User::factory()->create(['name' => 'Staff '.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'role' => 'staff', 'status' => true]);
            $previous = $this->shift('07:00', '08:00', 50 + ($index + 1) / 1000);
            ShiftAssignment::create(['shift_id' => $previous->id, 'user_id' => $user->id, 'status' => 'confirmed', 'assigned_by' => $this->actor->id, 'plan_revision' => $previous->revision]);

            return $user;
        });
    }

    private function target(User $user, int $minutes): void
    {
        EmployeeWorkModel::create(['user_id' => $user->id, 'name' => 'Configured model', 'status' => 'active', 'starts_on' => '2027-01-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => $minutes, 'daily_minutes' => array_fill(1, 7, 480), 'created_by' => $this->actor->id]);
    }

    private function preview(): Collection
    {
        return app(TimelinePlanningSuggestionService::class)->preview('2027-05-12', '2027-05-12', $this->actor)['proposals'];
    }

    private function assertTopMatchesFull(Shift $shift, Collection $proposals, int $limit): Collection
    {
        $full = app(TimelinePlanningSuggestionService::class)->rankedCandidates($shift, $this->actor)->take($limit);
        $this->assertSame($full->pluck('user.id')->all(), $proposals->where('shift.id', $shift->id)->pluck('user.id')->all());

        return $full;
    }

    public static function staffCounts(): array
    {
        return ['24 staff' => [24], '120 staff' => [120]];
    }

    #[DataProvider('staffCounts')]
    public function test_cold_preview_routes_only_three_for_two_slots_and_matches_full_ranking(int $staffCount): void
    {
        $staff = $this->staff($staffCount);
        $shift = $this->shift(required: 2);
        $preview = $this->preview();
        $this->assertSame([$staff[0]->id, $staff[1]->id], $preview->pluck('user.id')->all());
        $this->assertSame(3, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertTopMatchesFull($shift, $preview, 2);
        $this->assertSame($staffCount + 3, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertSame($staffCount, ShiftAssignment::count());
        if (getenv('TIMELINE_BENCHMARK')) {
            fwrite(STDERR, PHP_EOL.'TIMELINE cold route bound: '.$staffCount.' staff / 2 slots = 3 preview requests vs '.$staffCount.' full requests'.PHP_EOL);
        }
    }

    public function test_homogeneous_positive_ratio_basis_routes_the_same_top_candidate(): void
    {
        $staff = $this->staff(5);
        foreach ($staff as $index => $user) {
            $this->target($user, 1000 * ($index + 1));
        }
        $shift = $this->shift();
        $preview = $this->preview();
        $this->assertSame([$staff[4]->id], $preview->pluck('user.id')->all());
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertTopMatchesFull($shift, $preview, 1);
    }

    public function test_known_zero_and_unknown_targets_use_the_same_minutes_basis(): void
    {
        $staff = $this->staff(5);
        $this->target($staff[0], 0);
        $this->target($staff[1], 0);
        $shift = $this->shift();
        $preview = $this->preview();
        $this->assertSame([$staff[0]->id], $preview->pluck('user.id')->all());
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertTopMatchesFull($shift, $preview, 1);
    }

    public static function nullRatioTargets(): array
    {
        return ['unknown' => [null], 'known zero' => [0]];
    }

    #[DataProvider('nullRatioTargets')]
    public function test_mixed_basis_routes_everyone_and_resorts_when_routing_removes_the_last_null_ratio(?int $targetMinutes): void
    {
        $staff = $this->staff(3);
        if ($targetMinutes !== null) {
            $this->target($staff[0], $targetMinutes);
        }
        $this->target($staff[1], 120);
        $this->target($staff[2], 1200);
        $this->blockedOrigins = [50.001];
        $shift = $this->shift();
        $preview = $this->preview();
        $this->assertSame([$staff[2]->id], $preview->pluck('user.id')->all());
        $this->assertSame(3, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertTopMatchesFull($shift, $preview, 1);
    }

    public function test_blocked_top_candidates_trigger_deterministic_backfill_not_an_unchecked_fallback(): void
    {
        $staff = $this->staff(8);
        $this->blockedOrigins = [50.001, 50.002, 50.003];
        $shift = $this->shift();
        $preview = $this->preview();
        $this->assertSame([$staff[3]->id], $preview->pluck('user.id')->all());
        $this->assertSame(4, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertTopMatchesFull($shift, $preview, 1);
        $this->assertSame(8, ShiftAssignment::count());
    }

    public function test_virtual_selected_duty_is_used_for_route_backfill_in_the_next_shift(): void
    {
        $staff = $this->staff(3);
        EmployeeAvailability::create(['user_id' => $staff[0]->id, 'kind' => 'preferred', 'from' => '2027-05-12', 'until' => '2027-05-12', 'weekdays' => [3], 'whole_day' => true, 'timezone' => 'Europe/Berlin']);
        $first = $this->shift();
        $second = $this->shift('11:00', '12:00', 53.55);
        $this->blockedOrigins = [52.52];
        $preview = $this->preview();
        $this->assertSame([$staff[0]->id], $preview->where('shift.id', $first->id)->pluck('user.id')->all());
        $this->assertSame([$staff[1]->id], $preview->where('shift.id', $second->id)->pluck('user.id')->all());
        $service = app(TimelinePlanningSuggestionService::class);
        $expectedFirst = $service->rankedCandidates($first, $this->actor)->first();
        $expectedSecond = $service->rankedCandidates($second, $this->actor, [$expectedFirst['user']->id => [$first]])->first();
        $this->assertSame($expectedFirst['user']->id, $preview->where('shift.id', $first->id)->first()['user']->id);
        $this->assertSame($expectedSecond['user']->id, $preview->where('shift.id', $second->id)->first()['user']->id);
        $this->assertSame(3, ShiftAssignment::count());
    }

    public function test_all_travel_blocked_candidates_are_exhausted_without_unchecked_or_fabricated_proposals(): void
    {
        $this->staff(6);
        $this->blockedOrigins = array_map(fn ($index) => 50 + $index / 1000, range(1, 6));
        $shift = $this->shift();
        $this->assertCount(0, $this->preview());
        $this->assertSame(6, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertCount(0, app(TimelinePlanningSuggestionService::class)->rankedCandidates($shift, $this->actor));
        $this->assertSame(12, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertSame(6, ShiftAssignment::count());
    }
}
