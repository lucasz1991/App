<?php

namespace Tests\Feature;

use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\TimelinePlanningSuggestionService;
use App\Services\Routing\RoutingUsageService;
use App\Services\Routing\ServerRoutingCache;
use App\Services\Routing\TomTomRoutingService;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelinePreviewRoutingCacheTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private User $employee;

    private Shift $target;

    private string $cacheDirectory;

    private int $routeSeconds = 60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_11_120100_create_routing_monthly_usage_table.php'))->up();
        Schema::table('shifts', function (Blueprint $table): void {
            $table->json('location_snapshot')->nullable();
            $table->json('disposition_details')->nullable();
        });
        $this->travelTo(CarbonImmutable::parse('2027-05-12T06:00:00+02:00'));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Configured routing rules', 'minimum_rest_minutes' => 30, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Synthetic cache fixture', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic service', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T00:00:00+02:00', 'ends_at' => '2027-05-13T00:00:00+02:00', 'required_staff' => 2, 'created_by' => $this->actor->id]);
        $make = fn (string $start, string $end, float $latitude) => Shift::create(['order_id' => $order->id, 'title' => 'Synthetic duty', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T'.$start.':00+02:00', 'ends_at' => '2027-05-12T'.$end.':00+02:00', 'required_staff' => 1, 'planned_break_minutes' => 0, 'status' => 'draft', 'location_name' => 'Confirmed synthetic worksite', 'location_snapshot' => ['name' => 'Confirmed synthetic worksite', 'latitude' => $latitude, 'longitude' => 13.4, 'revision' => 1, 'confirmed' => true], 'created_by' => $this->actor->id])->fresh();
        $previous = $make('07:00', '08:00', 51.0);
        $this->target = $make('09:00', '10:00', 52.52);
        ShiftAssignment::create(['shift_id' => $previous->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->actor->id, 'plan_revision' => $previous->revision]);
        $this->cacheDirectory = storage_path('framework/testing/timeline-preview-cache-'.Str::uuid());
        app()->instance(ServerRoutingCache::class, new ServerRoutingCache($this->cacheDirectory));
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]), 'synthetic-timeline-cache-key');
        Http::preventStrayRequests();
        $this->fakeRoute(60);
    }

    protected function tearDown(): void
    {
        if (isset($this->cacheDirectory) && str_starts_with($this->cacheDirectory, storage_path('framework/testing/timeline-preview-cache-'))) {
            File::deleteDirectory($this->cacheDirectory);
        }
        parent::tearDown();
    }

    private function fakeRoute(int $seconds): void
    {
        $this->routeSeconds = $seconds;
        Http::fake(['api.tomtom.com/routing/*' => fn () => Http::response(['routes' => [['summary' => ['travelTimeInSeconds' => $this->routeSeconds, 'lengthInMeters' => 20000]]]], 200, ['Cache-Control' => 'no-cache, no-transform'])]);
    }

    private function preview(): array
    {
        return app(TimelinePlanningSuggestionService::class)->preview('2027-05-12', '2027-05-12', $this->actor);
    }

    public function test_prepared_timeline_preview_reuses_only_display_routes_and_public_ranking_stays_fresh(): void
    {
        $this->assertCount(1, $this->preview()['proposals']);
        $this->assertSame(1, app(ServerRoutingCache::class)->stats()['entries']);
        $this->assertCount(1, $this->preview()['proposals']);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
        $cached = app(StaffEligibilityService::class)->assessDetailedMany($this->target, collect([$this->employee]), false, [], true)[$this->employee->id];
        $this->assertTrue($cached['travel']['before']['cache_hit']);
        $this->assertSame(60, $cached['travel']['before']['duration_seconds']);
        Http::assertSentCount(1);
        $this->fakeRoute(1800);
        $this->assertTrue(app(TimelinePlanningSuggestionService::class)->rankedCandidates($this->target, $this->actor)->isEmpty());
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertCount(1, $this->preview()['proposals']);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_cached_route_never_caches_fresh_core_eligibility_or_actor_authorization(): void
    {
        $this->assertCount(1, $this->preview()['proposals']);
        AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-12T08:00:00+02:00', 'ends_at' => '2027-05-12T11:00:00+02:00', 'timezone' => 'Europe/Berlin']);
        $this->assertCount(0, $this->preview()['proposals']);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->actor->forceFill(['status' => false])->save();
        $this->expectException(HttpException::class);
        $this->preview();
    }

    public function test_context_display_flags_do_not_enable_cache_without_explicit_server_argument(): void
    {
        $this->preview();
        $this->fakeRoute(1800);
        $issues = app(StaffEligibilityService::class)->assessMany($this->target, collect([$this->employee]), false, ['display' => true, 'cache' => true, 'cache_purpose' => 'display'])[$this->employee->id];
        $this->assertContains('travel_before', array_column($issues, 'code'), json_encode($issues));
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_explicit_display_request_in_transaction_cannot_read_cached_routes_or_call_provider(): void
    {
        $this->preview();
        $this->fakeRoute(1800);
        $routing = \Mockery::mock(TomTomRoutingService::class, [9.0])->makePartial();
        $routing->shouldReceive('summaryManyForDisplay')->never();
        $routing->shouldReceive('summaryMany')->once()->passthru();
        $this->app->instance(TomTomRoutingService::class, $routing);
        $issues = DB::transaction(fn () => app(StaffEligibilityService::class)->assessMany($this->target, collect([$this->employee]), false, [], true))[$this->employee->id];
        $this->assertSame([], $issues);
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
        Http::assertNothingSent();
    }

    public function test_locked_display_attempt_requires_native_preflight_and_keeps_fresh_results(): void
    {
        $this->preview();
        try {
            DB::transaction(fn () => app(StaffEligibilityService::class)->assessMany($this->target, collect([$this->employee]), true, [], true));
            $this->fail('Binding check must require its native preflight.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('workflow', $exception->errors());
        }
        $this->fakeRoute(1800);
        $preflight = app(TravelAssessmentService::class)->preflight($this->target, collect([$this->employee]));
        $issues = DB::transaction(fn () => app(StaffEligibilityService::class)->assessMany($this->target, collect([$this->employee]), true, ['travel_preflight' => $preflight], true))[$this->employee->id];
        $this->assertContains('travel_before', array_column($issues, 'code'));
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
    }

    public function test_cold_preview_routes_only_core_eligible_users_but_public_diagnostics_keep_all_candidates(): void
    {
        $blocked = User::factory()->create(['role' => 'staff', 'status' => true]);
        $previous = $this->target->replicate(['public_id']);
        $previous->forceFill(['starts_at' => '2027-05-12T07:00:00+02:00', 'ends_at' => '2027-05-12T08:00:00+02:00', 'location_snapshot' => ['name' => 'Other synthetic worksite', 'latitude' => 50.0, 'longitude' => 13.4, 'revision' => 1, 'confirmed' => true]])->save();
        ShiftAssignment::create(['shift_id' => $previous->id, 'user_id' => $blocked->id, 'status' => 'confirmed', 'assigned_by' => $this->actor->id, 'plan_revision' => $previous->revision]);
        AbsenceRequest::create(['user_id' => $blocked->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-12T08:00:00+02:00', 'ends_at' => '2027-05-12T11:00:00+02:00', 'timezone' => 'Europe/Berlin']);
        $this->assertSame([$this->employee->id], $this->preview()['proposals']->pluck('user.id')->all());
        $this->assertSame(1, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertSame([$this->employee->id], app(TimelinePlanningSuggestionService::class)->rankedCandidates($this->target, $this->actor)->pluck('user.id')->all());
        $this->assertSame(3, app(RoutingUsageService::class)->current()['routing_transactions']);
        Http::assertSentCount(3);
        $this->assertSame(2, ShiftAssignment::count());
    }

    public function test_all_core_blocked_preview_performs_zero_provider_requests(): void
    {
        AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-12T08:00:00+02:00', 'ends_at' => '2027-05-12T11:00:00+02:00', 'timezone' => 'Europe/Berlin']);
        $this->assertCount(0, $this->preview()['proposals']);
        $this->assertSame(0, app(RoutingUsageService::class)->current()['routing_transactions']);
        $this->assertSame(0, app(ServerRoutingCache::class)->stats()['entries']);
        Http::assertNothingSent();
    }

    public function test_expired_preview_route_is_recalculated_and_changed_conflict_is_visible(): void
    {
        $this->assertCount(1, $this->preview()['proposals']);
        $this->travelTo(CarbonImmutable::now()->addMinutes(6));
        $this->fakeRoute(1800);
        $this->assertCount(0, $this->preview()['proposals']);
        $this->assertSame(2, app(RoutingUsageService::class)->current()['routing_transactions']);
        Http::assertSentCount(1);
    }
}
