<?php

namespace Tests\Feature;

use App\Livewire\Operations\StaffTimeline;
use App\Models\Customer;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\TimelinePlanningSuggestionService;
use App\Services\Routing\TomTomRoutingService;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelineLoadingProjectionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-09T07:00:00+02:00'));
        $this->manager = User::factory()->create(['name' => 'Planning benchmark', 'role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Configured rules', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Synthetic benchmark', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic service', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-01T00:00', 'ends_at' => '2027-06-01T00:00', 'required_staff' => 2, 'created_by' => $this->manager->id]);
    }

    private function shift(string $start, string $end, int $staff = 1): Shift
    {
        return Shift::create(['order_id' => $this->order->id, 'title' => 'Synthetic shift', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => $end, 'required_staff' => $staff, 'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->manager->id, 'revision' => 1, 'published_revision' => 0]);
    }

    public static function previewStaffCounts(): array
    {
        return ['24 staff' => [24], '120 staff' => [120]];
    }

    #[DataProvider('previewStaffCounts')]
    public function test_bounded_preview_benchmark_preserves_all_staff_and_virtual_conflicts(int $staffCount): void
    {
        $assessments = new \ArrayObject(['count' => 0]);
        $this->app->bind(StaffEligibilityService::class, fn () => new class($assessments) extends StaffEligibilityService
        {
            public function __construct(private \ArrayObject $assessments) {}

            public function assessMany(Shift $shift, Collection $users, bool $lock = false, array $context = [], bool $display = false): array
            {
                $this->assessments['count']++;

                return parent::assessMany($shift, $users, $lock, $context, $display);
            }

            public function assessPreviewCoreMany(Shift $shift, Collection $users, array $context = []): array
            {
                $this->assessments['count']++;

                return parent::assessPreviewCoreMany($shift, $users, $context);
            }
        });
        User::factory()->count($staffCount)->create(['role' => 'staff', 'status' => true]);
        foreach (range(10, 15) as $date) {
            $this->shift('2027-05-'.$date.'T08:00', '2027-05-'.$date.'T16:00', 2);
            $this->shift('2027-05-'.$date.'T12:00', '2027-05-'.$date.'T20:00', 2);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = hrtime(true);
        $preview = app(TimelinePlanningSuggestionService::class)->preview('2027-05-10', '2027-05-16', $this->manager);
        $elapsed = (hrtime(true) - $started) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        if (getenv('TIMELINE_BENCHMARK')) {
            fwrite(STDERR, '\nTIMELINE preview '.$staffCount.' staff: '.count($queries).' queries; '.round($elapsed, 1).' ms; '.$preview['proposals']->count().' proposals\n');
        }
        $this->assertSame(12, $preview['open_total']);
        $this->assertCount(24, $preview['proposals']);
        $this->assertSame(12, $assessments['count']);
        $this->assertLessThanOrEqual(250, count($queries), 'Core-only no-model fixture must not issue per-candidate no-op model reads.');
        foreach ($preview['proposals']->groupBy('user.id') as $proposals) {
            foreach ($proposals as $left) {
                foreach ($proposals as $right) {
                    if ($left === $right) {
                        continue;
                    }
                    $this->assertFalse($left['shift']->starts_at->lt($right['shift']->ends_at) && $left['shift']->ends_at->gt($right['shift']->starts_at));
                }
            }
        }
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_per_user_virtual_schedules_match_individual_fresh_assessments_and_never_leak(): void
    {
        $staff = User::factory()->count(3)->create(['role' => 'staff', 'status' => true]);
        $target = $this->shift('2027-05-13T08:00', '2027-05-13T16:00');
        $global = $this->shift('2027-05-12T08:00', '2027-05-12T16:00');
        $overlap = $this->shift('2027-05-13T12:00', '2027-05-13T20:00');
        $later = $this->shift('2027-05-14T08:00', '2027-05-14T16:00');
        $byUser = [$staff[0]->id => [$overlap], $staff[1]->id => [$later]];
        $service = app(StaffEligibilityService::class);
        $batch = $service->assessMany($target, $staff, false, ['additional_shifts' => [$global], 'additional_shifts_by_user' => $byUser]);
        foreach ($staff as $user) {
            $expected = $service->assessMany($target, collect([$user]), false, ['additional_shifts' => [$global, ...($byUser[$user->id] ?? [])]])[$user->id];
            $this->assertSame($expected, $batch[$user->id]);
        }
        $this->assertContains('rest_overlap', array_column($batch[$staff[0]->id], 'code'));
        $this->assertSame([], $batch[$staff[1]->id]);
        $this->assertSame([], $batch[$staff[2]->id]);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_routing_neighbors_merge_global_and_own_virtual_duties_without_cross_candidate_leaks(): void
    {
        $staff = User::factory()->count(2)->create(['role' => 'staff', 'status' => true]);
        TomTomSettings::save(array_replace(TomTomSettings::DEFAULTS, ['enabled' => true]), 'synthetic-only-key');
        $this->mock(TomTomRoutingService::class)->shouldReceive('summaryMany')->andReturn([]);
        $target = $this->shift('2027-05-13T08:00', '2027-05-13T16:00');
        $global = $this->shift('2027-05-12T08:00', '2027-05-12T16:00');
        $own = $this->shift('2027-05-13T06:00', '2027-05-13T07:00');
        $service = app(TravelAssessmentService::class);
        $batch = $service->assessMany($target, $staff, ['additional_shifts' => [$global], 'additional_shifts_by_user' => [$staff[0]->id => [$own]]]);
        $this->assertSame($own->id, $batch[$staff[0]->id]['travel']['before']['shift_id']);
        $this->assertSame($global->id, $batch[$staff[1]->id]['travel']['before']['shift_id']);
        foreach ($staff as $index => $user) {
            $expected = $service->assessMany($target, collect([$user]), ['additional_shifts' => [$global, ...($index === 0 ? [$own] : [])]])[$user->id];
            $this->assertSame($expected, $batch[$user->id]);
        }
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_batch_preserves_configured_contract_limits_and_reports_fresh_changes(): void
    {
        $staff = User::factory()->count(2)->create(['role' => 'staff', 'status' => true]);
        $target = $this->shift('2027-05-13T08:00', '2027-05-13T16:00');
        $earlier = $this->shift('2027-05-12T08:00', '2027-05-12T16:00');
        $model = EmployeeWorkModel::create(['user_id' => $staff[0]->id, 'name' => 'Configured contract', 'starts_on' => '2027-01-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400, 'maximum_weekly_minutes' => 600, 'daily_minutes' => array_fill(1, 7, 480), 'status' => 'active', 'created_by' => $this->manager->id]);
        $service = app(StaffEligibilityService::class);
        $batch = $service->assessMany($target, $staff, false, ['additional_shifts_by_user' => [$staff[0]->id => [$earlier]]]);
        $this->assertContains('contract_weekly_limit', array_column($batch[$staff[0]->id], 'code'));
        $this->assertSame([], $batch[$staff[1]->id]);
        $this->assertSame($service->assessMany($target, collect([$staff[0]]), false, ['additional_shifts' => [$earlier]])[$staff[0]->id], $batch[$staff[0]->id]);
        $model->forceFill(['maximum_weekly_minutes' => 1200])->save();
        $fresh = $service->assessMany($target, $staff, false, ['additional_shifts_by_user' => [$staff[0]->id => [$earlier]]]);
        $this->assertSame([], $fresh[$staff[0]->id]);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_render_benchmark_is_bounded_by_selected_days_and_three_day_reserve(): void
    {
        $staff = User::factory()->count(24)->create(['role' => 'staff', 'status' => true]);
        foreach ($staff as $user) {
            foreach (range(1, 28) as $date) {
                $day = str_pad((string) $date, 2, '0', STR_PAD_LEFT);
                $shift = $this->shift('2027-05-'.$day.'T08:00', '2027-05-'.$day.'T16:00');
                ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => 'confirmed', 'assigned_by' => $this->manager->id]);
            }
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = hrtime(true);
        $timeline = Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true, 'periodNavigationOwner' => 'synthetic-owner']);
        $elapsed = (hrtime(true) - $started) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        if (getenv('TIMELINE_BENCHMARK')) {
            fwrite(STDERR, '\nTIMELINE render: '.count($queries).' queries; '.round($elapsed, 1).' ms; '.strlen($timeline->html()).' HTML bytes\n');
        }
        $timeline->assertViewHas('days', fn ($days) => $days->count() === 13)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 24 && $rows->every(fn ($row) => $row['events']->count() === 13))
            ->assertViewHas('workloads', fn ($rows) => $rows->every(fn ($row) => $row['confirmed'] === 3150.0));
        $this->assertSame(672, ShiftAssignment::count());
    }

    public function test_unchanged_panel_notifications_and_repeated_preview_loads_send_no_html_morph(): void
    {
        User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->shift('2027-05-13T08:00', '2027-05-13T16:00');
        $timeline = Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true, 'showSuggestions' => true]);
        $timeline->assertSet('suggestionsReady', false)
            ->dispatch('operations-timeline-distribution', open: true, shiftId: null)
            ->assertSet('suggestionsReady', false);
        $this->assertArrayNotHasKey('html', $timeline->effects);
        $timeline->call('loadSuggestions')->assertSet('suggestionsReady', true);
        $this->assertArrayHasKey('html', $timeline->effects);
        $timeline->call('loadSuggestions');
        $this->assertArrayNotHasKey('html', $timeline->effects);
        $timeline->dispatch('operations-timeline-distribution', open: true, shiftId: null);
        $this->assertArrayNotHasKey('html', $timeline->effects);
        $timeline->dispatch('operations-timeline-distribution', open: false, shiftId: null)->assertSet('showSuggestions', false);
        $this->assertArrayHasKey('html', $timeline->effects);
    }

    public function test_changed_focus_still_renders_and_no_op_notifications_recheck_authorization(): void
    {
        User::factory()->create(['role' => 'staff', 'status' => true]);
        $shift = $this->shift('2027-05-13T08:00', '2027-05-13T16:00');
        $timeline = Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true, 'showSuggestions' => true]);
        $timeline->dispatch('operations-timeline-distribution', open: true, shiftId: $shift->id)->assertSet('focusShiftId', $shift->id);
        $this->assertArrayHasKey('html', $timeline->effects);
        $this->manager->forceFill(['status' => false])->save();
        $timeline->dispatch('operations-timeline-distribution', open: true, shiftId: $shift->id)->assertForbidden();
    }
}
