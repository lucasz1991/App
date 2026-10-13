<?php

namespace Tests\Feature;

use App\Enums\ShiftStatus;
use App\Livewire\Operations\StaffTimeline;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\PlanningTravelDisplay;
use App\Services\Operations\TimelinePlanningSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelineFiltersTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $alex;

    private User $ben;

    private User $cara;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['name' => 'Synthetic planner', 'role' => 'admin', 'status' => true]);
        $this->alex = User::factory()->create(['name' => 'Alex Alpha', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Beta', 'role' => 'staff', 'status' => true]);
        $this->cara = User::factory()->create(['name' => 'Cara Gamma', 'role' => 'staff', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic customer', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic service',
            'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2027-05-01T00:00', 'ends_at' => '2027-06-01T00:00', 'required_staff' => 1, 'created_by' => $this->manager->id]);
    }

    private function shift(array $attributes = []): Shift
    {
        return Shift::create(array_replace(['order_id' => $this->order->id, 'title' => 'Synthetic shift', 'role_name' => 'Tf',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T10:00',
            'required_staff' => 1, 'planned_break_minutes' => 0, 'status' => 'draft', 'created_by' => $this->manager->id], $attributes));
    }

    private function assign(Shift $shift, User $user, string $status = 'confirmed'): void
    {
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id,
            'status' => $status, 'assigned_by' => $this->manager->id]);
    }

    private function timeline(array $attributes = []): Testable
    {
        return Livewire::actingAs($this->manager)->test(StaffTimeline::class, $attributes + [
            'from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true,
            'periodNavigationOwner' => 'synthetic-owner',
        ]);
    }

    private function row(Testable $timeline, User $user): array
    {
        $row = $timeline->viewData('rows')->first(fn (array $row) => $row['user']->id === $user->id);
        $this->assertNotNull($row);

        return $row;
    }

    private function userIds(Testable $timeline): array
    {
        return $timeline->viewData('users')->getCollection()->pluck('id')->all();
    }

    public function test_default_projection_and_options_reuse_only_native_domains(): void
    {
        $requested = $this->shift(['role_name' => 'Tf', 'status' => 'open']);
        $confirmed = $this->shift(['role_name' => 'Rangierbegleiter', 'status' => 'confirmed', 'starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T10:00']);
        $this->assign($requested, $this->alex, 'requested');
        $this->assign($confirmed, $this->alex);
        $timeline = $this->timeline()->assertSet('staffPresence', 'all')->assertSet('shiftRole', 'all')
            ->assertSet('shiftStatus', 'all')->assertSet('assignmentStatusFilter', 'all')->assertViewHas('timelineFilterCount', 0);
        $this->assertSame([$requested->id, $confirmed->id], $this->row($timeline, $this->alex)['events']->pluck('shift_id')->all());
        $this->assertSame(['Rangierbegleiter', 'Tf'], array_column($timeline->viewData('timelineRoleOptions'), 'value'));
        $this->assertSame(array_values(array_diff(ShiftStatus::values(), ['cancelled'])), array_column($timeline->viewData('timelineShiftStatusOptions'), 'value'));
        $this->assertSame(['requested', 'confirmed'], array_column($timeline->viewData('timelineAssignmentStatusOptions'), 'value'));
        Http::assertNothingSent();
    }

    public function test_presence_uses_selected_period_not_reserves_or_availability(): void
    {
        $this->assign($this->shift(), $this->alex, 'requested');
        $this->assign($this->shift(['starts_at' => '2027-05-17T08:00', 'ends_at' => '2027-05-17T10:00']), $this->ben);
        $this->assign($this->shift(['status' => 'cancelled']), $this->cara);
        $timeline = $this->timeline()->set('staffPresence', 'assigned');
        $this->assertSame([$this->alex->id], $this->userIds($timeline));
        $timeline->set('staffPresence', 'unassigned');
        $this->assertSame([$this->ben->id, $this->cara->id], $this->userIds($timeline));
        $this->assertCount(1, $this->row($timeline, $this->ben)['events'], 'Reserve content remains visible but does not claim selected-period staffing.');
    }

    public function test_presence_preserves_inactive_assignees_and_half_open_midnight_boundaries(): void
    {
        $inactive = User::factory()->create(['name' => 'Inactive assignee', 'role' => 'staff', 'status' => false]);
        $this->assign($this->shift(['starts_at' => '2027-05-09T22:00', 'ends_at' => '2027-05-10T06:00']), $inactive);
        $this->assign($this->shift(['starts_at' => '2027-05-09T22:00', 'ends_at' => '2027-05-10T00:00']), $this->alex);
        $this->assign($this->shift(['starts_at' => '2027-05-17T00:00', 'ends_at' => '2027-05-17T06:00']), $this->ben);
        $timeline = $this->timeline()->set('staffPresence', 'assigned');
        $this->assertSame([$inactive->id], $this->userIds($timeline));
        $this->assertSame('2027-05-07', $timeline->viewData('axisFrom')->toDateString());
        $this->assertSame('2027-05-20', $timeline->viewData('axisUntil')->toDateString());
    }

    public function test_presence_and_name_search_are_filtered_before_incremental_pagination(): void
    {
        $shift = $this->shift();
        for ($index = 0; $index < 29; $index++) {
            $user = User::factory()->create(['name' => sprintf('Selected staff %02d', $index), 'role' => 'staff', 'status' => true]);
            $this->assign($shift, $user);
        }
        $timeline = $this->timeline()->set('staffPresence', 'assigned');
        $this->assertSame(29, $timeline->viewData('users')->total());
        $this->assertCount(24, $this->userIds($timeline));
        $timeline->call('loadMore');
        $this->assertCount(29, $this->userIds($timeline));
        $timeline->set('search', 'Selected staff 28');
        $this->assertSame(1, $timeline->viewData('users')->total());
        $this->assertSame(1, $timeline->get('paginators.staffPage'));
        $timeline->set('staffPresence', 'unassigned')->set('search', '');
        $this->assertSame([$this->alex->id, $this->ben->id, $this->cara->id], $this->userIds($timeline));
    }

    public function test_shift_filters_change_badges_but_not_workload_absences_or_day_attention(): void
    {
        $requested = $this->shift(['role_name' => 'Tf', 'status' => 'open']);
        $confirmed = $this->shift(['role_name' => 'Rangierbegleiter', 'status' => 'confirmed', 'starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T10:00']);
        $this->assign($requested, $this->alex, 'requested');
        $this->assign($confirmed, $this->alex);
        AbsenceRequest::create(['user_id' => $this->alex->id, 'kind' => 'vacation', 'status' => 'approved',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-15T08:00', 'ends_at' => '2027-05-15T10:00']);
        $timeline = $this->timeline();
        $workload = $timeline->viewData('workloads')->get($this->alex->id);
        $attention = $timeline->viewData('dayAttention');
        $timeline->set('shiftRole', 'Tf')->set('shiftStatus', 'open')->set('assignmentStatusFilter', 'requested');
        $events = $this->row($timeline, $this->alex)['events'];
        $this->assertSame([$requested->id], $events->where('kind', 'shift')->pluck('shift_id')->all());
        $this->assertCount(1, $events->where('kind', 'absence'));
        $this->assertSame($workload, $timeline->viewData('workloads')->get($this->alex->id));
        $this->assertSame($attention, $timeline->viewData('dayAttention'));
        $timeline->set('assignmentStatusFilter', 'confirmed');
        $this->assertCount(0, $this->row($timeline, $this->alex)['events']->where('kind', 'shift'));
        $this->assertCount(1, $this->row($timeline, $this->alex)['events']->where('kind', 'absence'));
        $this->assertSame(240.0, $workload['planned']);
        $timeline->call('resetTimelineFilters');
        $this->assertSame([$requested->id, $confirmed->id], $this->row($timeline, $this->alex)['events']->where('kind', 'shift')->pluck('shift_id')->all());
    }

    public function test_display_proposal_filters_do_not_recompute_or_narrow_the_native_preview(): void
    {
        $tf = $this->shift(['role_name' => 'Tf', 'status' => 'draft']);
        $rb = $this->shift(['role_name' => 'Rangierbegleiter', 'status' => 'open', 'starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T10:00']);
        $proposals = collect([$tf, $rb])->map(fn (Shift $shift) => ['shift' => $shift, 'user' => $this->alex,
            'revision' => 1, 'reasons' => ['Synthetic preview'], 'fit' => 'suitable', 'fit_label' => 'Passend', 'urgency' => 'normal', 'urgency_label' => 'Später']);
        $this->partialMock(TimelinePlanningSuggestionService::class)->shouldReceive('preview')->once()
            ->andReturn(['proposals' => $proposals, 'open_total' => 2, 'limited' => false]);
        $timeline = $this->timeline(['showSuggestions' => true])->call('loadSuggestions');
        $this->assertCount(2, $timeline->viewData('proposalRows')->get($this->alex->id));
        $version = $timeline->get('previewVersion');
        $timeline->set('shiftRole', 'Tf')->set('shiftStatus', 'draft');
        $this->assertSame([$tf->id], $timeline->viewData('proposalRows')->get($this->alex->id)->pluck('shift_id')->all());
        $timeline->set('assignmentStatusFilter', 'confirmed');
        $this->assertCount(0, $timeline->viewData('proposalRows'));
        $this->assertCount(2, $timeline->viewData('planningPreview')['proposals']);
        $this->assertSame(2, $timeline->viewData('planningPreview')['open_total']);
        $timeline->call('resetTimelineFilters')->call('loadSuggestions')->assertSet('previewVersion', $version);
        Http::assertNothingSent();
    }

    public function test_role_options_are_bounded_actual_values_and_retain_a_selected_out_of_window_role(): void
    {
        for ($index = 0; $index < 36; $index++) {
            $this->shift(['role_name' => sprintf('Function %02d', $index)]);
        }
        $this->shift(['role_name' => 'Cancelled only', 'status' => 'cancelled']);
        $this->shift(['role_name' => 'Past only', 'starts_at' => '2027-04-01T08:00', 'ends_at' => '2027-04-01T10:00']);
        $timeline = $this->timeline();
        $this->assertCount(30, $timeline->viewData('timelineRoleOptions'));
        $this->assertNotContains('Cancelled only', array_column($timeline->viewData('timelineRoleOptions'), 'value'));
        $this->assertNotContains('Past only', array_column($timeline->viewData('timelineRoleOptions'), 'value'));
        $timeline->set('shiftRole', 'Function 35');
        $this->assertCount(31, $timeline->viewData('timelineRoleOptions'));
        $this->assertContains('Function 35', array_column($timeline->viewData('timelineRoleOptions'), 'value'));
        $timeline->set('shiftRole', 'Past only');
        $this->assertContains('Past only', array_column($timeline->viewData('timelineRoleOptions'), 'value'));
    }

    public function test_invalid_filter_updates_return_field_errors_and_keep_previous_valid_state(): void
    {
        $this->shift();
        foreach (['staffPresence' => ['available', [], 1], 'shiftRole' => ['Unknown function', str_repeat('x', 256), []],
            'shiftStatus' => ['cancelled', 'unsupported', []], 'assignmentStatusFilter' => ['declined', 'cancelled', []]] as $property => $values) {
            foreach ($values as $value) {
                $this->timeline()->set($property, $value)->assertStatus(200)->assertHasErrors($property)->assertSet($property, 'all');
            }
        }
    }

    public function test_filter_updates_reset_only_planning_presentation_not_period_or_preview_version(): void
    {
        $this->shift();
        $timeline = $this->timeline();
        $timeline->set('assignmentOpen', true)->set('search', 'Alex')->set('staffPresence', 'unassigned')
            ->assertSet('assignmentOpen', false)->assertSet('from', '2027-05-10')->assertSet('until', '2027-05-16')
            ->assertSet('previewVersion', 0)->assertViewHas('timelineFilterCount', 2)
            ->assertDispatched('operations-timeline-filters-changed', timelineId: $timeline->instance()->getId())
            ->assertNotDispatched('operations-timeline-window-reset');
        $timeline->call('resetTimelineFilters')->assertSet('search', '')->assertSet('staffPresence', 'all')
            ->assertSet('shiftRole', 'all')->assertSet('shiftStatus', 'all')->assertSet('assignmentStatusFilter', 'all')
            ->assertSet('from', '2027-05-10')->assertSet('until', '2027-05-16')->assertViewHas('timelineFilterCount', 0);
    }

    public function test_mixed_invalid_updates_restore_filters_atomically_and_preserve_field_errors(): void
    {
        $timeline = $this->timeline()->set('assignmentOpen', true);
        $timeline->update(updates: ['staffPresence' => 'assigned', 'shiftStatus' => ['invalid'], 'search' => 'Alex'])
            ->assertStatus(200)->assertHasErrors('shiftStatus')->assertSet('shiftStatus', 'all')
            ->assertSet('staffPresence', 'all')->assertSet('search', 'Alex')->assertSet('assignmentOpen', false);
        $this->assertSame([$this->alex->id], $this->userIds($timeline));
        $timeline->set('shiftStatus', 'draft')->assertHasNoErrors('shiftStatus')->assertSet('shiftStatus', 'draft');
    }

    public function test_invalid_filter_keeps_existing_assignment_error_and_renders_recoverable_feedback(): void
    {
        $shift = $this->shift();
        $travel = ['issues' => [], 'warnings' => [], 'travel' => []];
        $travel += app(PlanningTravelDisplay::class)->status($travel, false);
        $this->partialMock(TimelinePlanningSuggestionService::class)->shouldReceive('cellChoices')->andReturn(collect([
            ['shift' => $shift, 'revision' => 1, 'eligible' => true, 'issues' => [], 'travel' => $travel, 'warnings' => []],
        ]));
        $timeline = $this->timeline()->call('openCell', $this->alex->id, '2027-05-13')
            ->call('selectCellShift', $shift->id, 1)->set('assignmentStatus', 'unsupported')
            ->call('confirmAssignment')->assertHasErrors('assignmentStatus');
        $timeline->set('shiftStatus', [])->assertStatus(200)->assertHasErrors(['assignmentStatus', 'shiftStatus'])
            ->assertSet('shiftStatus', 'all')->assertSet('assignmentOpen', true);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$timeline->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//p[@role="alert"][contains(text(),"Dieser Filter ist nicht mehr verfügbar")]')->length);
        $timeline->call('resetTimelineFilters')->assertHasNoErrors(['assignmentStatus', 'shiftStatus'])->assertSet('assignmentOpen', false);
        $this->assertDatabaseCount('shift_assignments', 0);
    }

    public function test_period_hydration_retains_display_filters_and_bounded_lazy_axis(): void
    {
        $this->shift();
        $timeline = $this->timeline()->set('shiftRole', 'Tf')->set('staffPresence', 'unassigned')->set('assignmentStatusFilter', 'confirmed');
        $instance = $timeline->instance();
        $instance->from = '2027-05-17';
        $instance->until = '2027-05-23';
        $instance->hydrate();
        $data = $instance->render()->getData();
        $this->assertSame('Tf', $instance->shiftRole);
        $this->assertSame('unassigned', $instance->staffPresence);
        $this->assertSame('confirmed', $instance->assignmentStatusFilter);
        $this->assertSame('2027-05-14', $data['axisFrom']->toDateString());
        $this->assertSame('2027-05-27', $data['axisUntil']->toDateString());
        $this->assertSame(13, $data['days']->count());
        $this->assertSame(7, $data['selectedDays']->count());
    }

    public function test_filter_updates_recheck_fresh_actor_and_operations_permission(): void
    {
        $timeline = $this->timeline();
        DB::table('users')->where('id', $this->manager->id)->update(['status' => false]);
        $timeline->set('staffPresence', 'assigned')->assertForbidden();
        DB::table('users')->where('id', $this->manager->id)->update(['status' => true]);
        Gate::define('operations.manage', fn (User $user): bool => $user->is($this->alex));
        $timeline = Livewire::actingAs($this->alex)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true]);
        Gate::define('operations.manage', fn (): bool => false);
        $timeline->set('shiftStatus', 'draft')->assertForbidden();
    }

    public function test_presence_and_role_option_projection_use_constant_query_counts(): void
    {
        $this->actingAs($this->manager);
        $shift = $this->shift();
        $counts = [];
        foreach ([24, 120] as $size) {
            while (User::where('role', 'staff')->count() < $size) {
                $person = User::factory()->create(['role' => 'staff', 'status' => true]);
                $this->assign($shift, $person);
            }
            $timeline = new StaffTimeline;
            $timeline->staffPresence = 'assigned';
            $from = CarbonImmutable::parse('2027-05-10', 'Europe/Berlin');
            $until = $from->addWeek();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                (new \ReflectionMethod(StaffTimeline::class, 'staffQuery'))->invoke($timeline, $from, $until)->count();
                (new \ReflectionMethod(StaffTimeline::class, 'timelineRoleOptions'))->invoke($timeline, $from->subDays(3), $until->addDays(3));
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        }
        $this->assertSame([2, 2], $counts);
        Http::assertNothingSent();
    }

    public function test_header_dropdown_is_child_owned_separated_accessible_and_uses_native_loading(): void
    {
        $this->shift();
        $timeline = $this->timeline();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$timeline->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//template[@*[name()="x-teleport"]="[data-timeline-filter-owner=\'synthetic-owner\']"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-timeline-filter-trigger]')->length);
        $this->assertSame(2, $xpath->query('//fieldset[contains(@class,"rt-timeline-filters__group")]')->length);
        foreach (['staffPresence', 'shiftRole', 'shiftStatus', 'assignmentStatusFilter'] as $property) {
            $this->assertSame(1, $xpath->query('//*[@data-rt-custom-select][@data-autosave-model="'.$property.'"]')->length);
        }
        $this->assertStringContainsString('operations-timeline-filters-changed.window', $timeline->html());
        $this->assertStringContainsString('Abwesenheiten und die vollständige Arbeitszeit bleiben sichtbar.', $timeline->html());
        $reset = $xpath->query('//footer[contains(@class,"rt-timeline-filters__footer")]/button')->item(0);
        $this->assertTrue($reset->hasAttribute('disabled'));
        $this->assertFalse($reset->hasAttribute('wire:click'), 'Native disabled buttons must not expose a click action.');
        $timeline->set('search', 'Alex');
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$timeline->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $reset = $xpath->query('//button[@*[name()="wire:click"]="resetTimelineFilters"]')->item(0);
        $this->assertNotNull($reset);
        $this->assertFalse($reset->hasAttribute('disabled'));
        $this->assertDatabaseCount('shift_assignments', 0);
    }
}
