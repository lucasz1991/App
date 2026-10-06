<?php

namespace Tests\Feature;

use App\Livewire\Operations\StaffTimeline;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelinePlanningActionsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $anna;

    private User $ben;

    private Shift $shift;

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
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['name' => 'Disposition QA', 'role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna Alpha', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Beta', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Gepflegte Regeln', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Rail QA', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Kundenleistung', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-10T00:00', 'ends_at' => '2027-05-17T00:00', 'required_staff' => 2, 'created_by' => $this->manager->id]);
        $this->shift = Shift::create(['order_id' => $order->id, 'title' => 'Offener Donnerstag', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->manager->id, 'revision' => 1, 'published_revision' => 0]);
    }

    private function timeline(bool $enabled = true)
    {
        return Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => $enabled]);
    }

    public function test_cell_actions_are_opt_in_and_suggestions_default_off(): void
    {
        $this->timeline(false)->assertDontSee('data-timeline-cell-action', false)->assertDontSee('data-timeline-proposal', false)->call('toggleSuggestions')->assertForbidden();
        $this->timeline()->assertSee('data-timeline-cell-action', false)->assertSee('<div class="hidden" data-timeline-modal-host>', false)->assertSet('showSuggestions', false)->assertDontSee('data-timeline-proposal', false);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_click_and_selection_only_prepare_then_explicit_confirmation_assigns_requested(): void
    {
        $timeline = $this->timeline()->call('openCell', $this->ben->id, '2027-05-13')->assertSet('assignmentOpen', true)->assertSee('Offener Donnerstag')->assertSee('Auswählen');
        $this->assertSame(0, ShiftAssignment::count());
        $timeline->call('selectCellShift', $this->shift->id, 1)->assertSet('planningRevision', 1)->assertSet('assignmentStatus', 'requested')->assertSee('Einteilen');
        $this->assertSame(0, ShiftAssignment::count());
        $timeline->call('confirmAssignment')->assertSet('assignmentOpen', false)->assertDispatched('operations-plan-changed');
        $assignment = ShiftAssignment::firstOrFail();
        $this->assertSame($this->ben->id, $assignment->user_id);
        $this->assertSame('requested', $assignment->status->value);
        $this->assertSame(0, $this->shift->fresh()->published_revision);
    }

    public function test_blocked_person_sees_reason_without_selection_or_ghost(): void
    {
        AbsenceRequest::create(['user_id' => $this->ben->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00', 'timezone' => 'Europe/Berlin']);
        $this->timeline()->call('openCell', $this->ben->id, '2027-05-13')->assertViewHas('choices', fn ($choices) => $choices->count() === 1 && ! $choices->first()->eligible)
            ->assertDontSee('Auswählen')->call('selectCellShift', $this->shift->id, 1)->assertHasErrors('workflow')->assertSet('planningShiftId', null);
        $this->timeline()->call('toggleSuggestions')->assertViewHas('planningPreview', fn ($preview) => $preview['proposals']->pluck('user.id')->all() === [$this->anna->id]);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_stale_selection_and_stale_confirm_are_rejected(): void
    {
        $timeline = $this->timeline()->call('openCell', $this->ben->id, '2027-05-13');
        $this->shift->forceFill(['revision' => 2])->save();
        $timeline->call('selectCellShift', $this->shift->id, 1)->assertHasErrors('workflow')->assertSet('planningShiftId', null);
        $timeline->call('selectCellShift', $this->shift->id, 2)->assertHasNoErrors();
        $this->shift->forceFill(['revision' => 3])->save();
        $timeline->call('confirmAssignment')->assertHasErrors('workflow');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_concurrent_fill_or_new_absence_cannot_be_overridden_by_confirmation(): void
    {
        $timeline = $this->timeline()->call('openCell', $this->ben->id, '2027-05-13')->call('selectCellShift', $this->shift->id, 1);
        AbsenceRequest::create(['user_id' => $this->ben->id, 'kind' => 'unavailable', 'status' => 'approved', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00', 'timezone' => 'Europe/Berlin']);
        $timeline->call('confirmAssignment')->assertHasErrors('workflow');
        $this->assertSame(0, ShiftAssignment::count());
        ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $this->anna->id, 'status' => 'requested', 'assigned_by' => $this->manager->id]);
        $timeline->call('confirmAssignment')->assertHasErrors('workflow');
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_ghost_click_is_read_only_and_rechecks_ranking_and_revision(): void
    {
        EmployeeAvailability::create(['user_id' => $this->ben->id, 'kind' => 'preferred', 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin']);
        $timeline = $this->timeline()->call('toggleSuggestions')->assertSee('data-timeline-proposal', false)->call('openSuggestion', $this->shift->id, $this->ben->id, 1)->assertSet('planningUserId', $this->ben->id)->assertSee('Wunschdienst');
        $this->assertSame(0, ShiftAssignment::count());
        $timeline->set('assignmentOpen', false);
        $this->shift->forceFill(['revision' => 2])->save();
        $timeline->call('openSuggestion', $this->shift->id, $this->ben->id, 1)->assertHasErrors('workflow')->assertSet('assignmentOpen', false);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_proposals_and_repeated_switches_never_change_real_event_geometry(): void
    {
        $existing = $this->shift->replicate(['public_id']);
        $existing->starts_at = '2027-05-14T08:00';
        $existing->ends_at = '2027-05-14T16:00';
        $existing->save();
        ShiftAssignment::create(['shift_id' => $existing->id, 'user_id' => $this->anna->id, 'status' => 'confirmed', 'assigned_by' => $this->manager->id]);
        $geometry = fn ($rows) => json_encode($rows->map(fn ($row) => ['id' => $row['user']->id, 'events' => $row['events'], 'lanes' => $row['lane_count'], 'days' => $row['days']])->all());
        $before = '';
        $timeline = $this->timeline()->assertViewHas('rows', function ($rows) use (&$before, $geometry) {
            $before = $geometry($rows);

            return true;
        });
        foreach ([true, false, true, false] as $state) {
            $timeline->call('toggleSuggestions')->assertSet('showSuggestions', $state)->assertViewHas('rows', function ($rows) use ($before, $geometry) {
                $this->assertSame($before, $geometry($rows));

                return true;
            });
        }
        $this->assertSame(1, ShiftAssignment::count());
        $css = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        $this->assertStringContainsString('opacity: .5', $css);
        $this->assertStringContainsString('position: absolute', $css);
        $this->assertSame('fd3e2599d6d42f8671484add0a262d58cf1da6e889bcba36da4153e8b2b4c9f4', hash_file('sha256', resource_path('js/staff-timeline.js')));
    }

    public function test_search_and_load_more_preserve_preview_and_cell_keys(): void
    {
        for ($i = 0; $i < 25; $i++) {
            User::factory()->create(['name' => 'QA Staff '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'role' => 'staff', 'status' => true]);
        }
        $this->timeline()->call('toggleSuggestions')->call('loadMore')->assertViewHas('users', fn ($users) => $users->count() === 27)->set('search', 'Ben Beta')
            ->assertViewHas('users', fn ($users) => $users->count() === 1)->assertSee('staff-day-'.$this->ben->id.'-2027-05-13', false)->assertSet('showSuggestions', true);
    }

    public function test_bad_date_unknown_user_and_absence_only_cannot_prepare_assignment(): void
    {
        $this->timeline()->call('openCell', $this->ben->id, '2027-05-20')->assertStatus(422);
        Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true, 'absencesOnly' => true])
            ->assertDontSee('data-timeline-cell-action', false)->call('toggleSuggestions')->assertForbidden();
    }

    public function test_unknown_person_never_prepares_an_assignment(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->timeline()->call('openCell', 999999, '2027-05-13');
    }

    public function test_closed_order_or_actual_start_after_selection_is_rechecked_inside_lock_boundary(): void
    {
        $timeline = $this->timeline()->call('openCell', $this->ben->id, '2027-05-13')->call('selectCellShift', $this->shift->id, 1);
        $this->shift->order->update(['status' => 'completed']);
        $timeline->call('confirmAssignment')->assertHasErrors('workflow');
        $this->assertSame(0, ShiftAssignment::count());
        $this->shift->order->update(['status' => 'confirmed']);
        $this->travelTo(CarbonImmutable::parse('2027-05-13T08:01:00+02:00'));
        $timeline->call('confirmAssignment')->assertHasErrors('workflow');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_targets_are_locked_and_status_cannot_accept_non_reserving_values(): void
    {
        $this->timeline()->call('openCell', $this->ben->id, '2027-05-13')->call('selectCellShift', $this->shift->id, 1)->set('assignmentStatus', 'cancelled')->call('confirmAssignment')->assertHasErrors('assignmentStatus');
        $this->assertSame(0, ShiftAssignment::count());
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->timeline()->set('planningUserId', $this->ben->id);
    }

    public function test_repeated_dst_hour_keeps_offsets_in_choices_and_confirmation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-10-30T07:00:00+02:00'));
        $this->shift->forceFill(['starts_at' => CarbonImmutable::parse('2027-10-31T02:45:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-10-31T02:15:00+01:00'), 'planned_break_minutes' => 0])->save();
        Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-10-30', 'until' => '2027-11-01', 'planningEnabled' => true])
            ->call('openCell', $this->ben->id, '2027-10-31')->assertViewHas('choices', fn ($choices) => str_contains($choices->first()->period, '+02:00') && str_contains($choices->first()->period, '+01:00'))
            ->call('selectCellShift', $this->shift->id, 1)->assertSee('31.10. 02:45 +02:00')->assertSee('31.10. 02:15 +01:00');
        $this->assertSame(0, ShiftAssignment::count());
    }
}
