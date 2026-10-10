<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\StaffTimeline;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\TimelinePlanningSuggestionService;
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
        $this->timeline()->assertSee('data-timeline-cell-action', false)->assertSee('data-timeline-planner', false)->assertDontSee('data-timeline-modal-host', false)->assertSet('showSuggestions', false)->assertDontSee('data-timeline-proposal', false);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_distribution_sidebar_drives_suggestions_and_focus_without_bottom_legend(): void
    {
        $timeline = Livewire::actingAs($this->manager)->test(StaffTimeline::class, [
            'from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true, 'searchInHeader' => true,
        ])->assertDontSee('data-timeline-suggestions-switch', false)
            ->assertDontSee('$wire.showSuggestions', false)
            ->assertDontSee('rt-timeline-suggestion-legend', false);
        $timeline->assertSee("rtTimelinePlanning('".$timeline->instance()->getId()."')", false);

        $timeline->dispatch('operations-timeline-distribution', open: true, shiftId: null)->assertSet('showSuggestions', true)
            ->assertSet('focusShiftId', null)
            ->assertSee('data-timeline-proposal', false)
            ->assertDontSee('rt-timeline-suggestion-legend', false)
            ->assertDontSee('Wunsch / verfügbar');
        // Gewählte Schicht: Tag und passende Zeilen markiert, Einteilen-Ziel je geeigneter Person.
        $timeline->dispatch('operations-timeline-distribution', open: true, shiftId: $this->shift->id)
            ->assertSet('focusShiftId', $this->shift->id)
            ->assertSee('rt-timeline-focus-target', false)
            ->assertSee('data-focus="true"', false)
            ->assertSee('data-focus-fit="eligible"', false);
        $timeline->dispatch('operations-timeline-distribution', open: false, shiftId: $this->shift->id)
            ->assertSet('showSuggestions', false)->assertSet('focusShiftId', null)
            ->assertDontSee('data-timeline-proposal', false)
            ->assertDontSee('rt-timeline-focus-target', false);

        $styles = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        $this->assertStringNotContainsString('rt-timeline-suggestion-legend', $styles);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $styles);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_focus_candidate_only_prepares_and_rechecks_ranking_and_revision(): void
    {
        $revision = (int) $this->shift->fresh()->revision;
        $timeline = $this->timeline()->dispatch('operations-timeline-distribution', open: true, shiftId: $this->shift->id);
        $timeline->call('openFocusCandidate', $this->shift->id, $this->anna->id, $revision)
            ->assertSet('assignmentOpen', true)->assertSee('Offener Donnerstag');
        $this->assertSame(0, ShiftAssignment::count());
        $this->timeline()->dispatch('operations-timeline-distribution', open: true, shiftId: $this->shift->id)
            ->call('openFocusCandidate', $this->shift->id, $this->anna->id, $revision + 5)
            ->assertHasErrors('workflow')->assertSet('assignmentOpen', false);
        // Nur die im Panel gewählte Schicht darf über die Zeitleiste vorbereitet werden.
        $this->timeline()->call('openFocusCandidate', $this->shift->id, $this->anna->id, $revision)->assertStatus(422);
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

    public function test_distribution_sidebar_assigns_directly_moves_on_and_rejects_full_or_stale_shifts(): void
    {
        $next = Shift::create(['order_id' => $this->shift->order_id, 'title' => 'Offener Freitag', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->manager->id, 'revision' => 1, 'published_revision' => 0]);
        $revision = (int) $this->shift->fresh()->revision;
        $panel = Livewire::actingAs($this->manager)->withQueryParams(['distribution' => '1'])->test(ShiftManagement::class)->assertSet('distributionOpen', true)
            ->call('selectDistributionShift', $this->shift->id)->assertSet('distributionShiftId', $this->shift->id)
            ->assertDispatched('operations-timeline-distribution', open: true, shiftId: $this->shift->id);
        $this->assertSame(0, ShiftAssignment::count());
        // Status nur aus den reservierenden Werten.
        $panel->set('distributionStatus', 'declined')->call('assignFromDistribution', $this->shift->id, $this->ben->id, $revision)->assertHasErrors('distributionStatus');
        $this->assertSame(0, ShiftAssignment::count());
        $panel->set('distributionStatus', 'requested')->call('assignFromDistribution', $this->shift->id, $this->ben->id, $revision)
            ->assertHasNoErrors()->assertDispatched('operations-plan-changed')
            ->assertSet('distributionShiftId', $next->id);
        $assignment = ShiftAssignment::sole();
        $this->assertSame([$this->ben->id, 'requested'], [$assignment->user_id, $assignment->status->value]);
        // Voll besetzt: keine zweite Einteilung über einen alten Panelstand.
        $panel->call('assignFromDistribution', $this->shift->id, $this->anna->id, $revision)->assertHasErrors('distribution');
        $panel->set('distributionStatus', 'confirmed')->call('assignFromDistribution', $next->id, $this->anna->id, (int) $next->fresh()->revision + 3)->assertHasErrors('distribution');
        $this->assertSame(1, ShiftAssignment::count());
        $panel->call('assignFromDistribution', $next->id, $this->anna->id, (int) $next->fresh()->revision)->assertHasNoErrors();
        $this->assertSame('confirmed', ShiftAssignment::where('shift_id', $next->id)->sole()->status->value);
        $panel->call('toggleDistribution')->assertSet('distributionOpen', false)->assertSet('distributionShiftId', null)
            ->assertDispatched('operations-timeline-distribution', open: false, shiftId: null)
            ->call('assignFromDistribution', $next->id, $this->ben->id, 1)->assertStatus(422);
        $this->assertSame(2, ShiftAssignment::count());
    }

    public function test_header_suggestion_slider_has_two_positions_without_changing_the_shared_primitive(): void
    {
        $styles = file_get_contents(resource_path('css/timeline-planning-actions.css'));
        $selector = '.rt-timeline-suggestions-toggle .rt-ui-toggle--label-inside';
        $rule = function (string $target) use ($styles): string {
            $this->assertSame(1, preg_match('/'.preg_quote($target, '/').'\s*\{([^}]*)\}/', $styles, $matches));

            return $matches[1];
        };
        $control = $rule($selector.' .rt-ui-toggle-control');
        $track = $rule($selector.' .rt-ui-toggle-control::after');
        $knob = $rule($selector.' .rt-ui-toggle-control::before');
        $activeTrack = $rule($selector.' input:checked + .rt-ui-toggle-control::after');
        $activeKnob = $rule($selector.' input:checked + .rt-ui-toggle-control::before');

        $this->assertStringContainsString('--rt-toggle-knob: 18px', $control);
        $this->assertStringContainsString('--rt-toggle-travel: 16px', $control);
        $this->assertStringNotContainsString('--rt-toggle-travel: 0', $control);
        $this->assertStringContainsString('height: 44px', $control);
        $this->assertStringContainsString('padding: 0 6px 0 54px', $control);
        foreach ([$track, $activeTrack] as $state) {
            $this->assertStringContainsString('width: 38px', $state);
            $this->assertStringContainsString('height: 22px', $state);
            $this->assertStringContainsString('transform: translateY(-50%)', $state);
            $this->assertStringContainsString('border: 0', $state);
        }
        $this->assertStringContainsString('var(--rt-shell-muted, #637188)', $track);
        $this->assertStringContainsString('var(--rt-shell-accent, #e4002b)', $activeTrack);
        $this->assertStringContainsString('z-index: 2', $knob);
        $this->assertStringContainsString('background: var(--rt-surface, #fff)', $activeKnob);
        $this->assertStringContainsString('transform 180ms', $knob);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*\{\s*'.preg_quote($selector, '/').' \.rt-ui-toggle-control::before,\s*'.preg_quote($selector, '/').' \.rt-ui-toggle-control::after\s*\{\s*transition: none;\s*\}/', $styles);

        $sharedStyles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('transform: translate(var(--rt-toggle-travel), -50%)', $sharedStyles);
        $this->assertStringContainsString('--rt-toggle-travel: 1.25rem', $sharedStyles);
        $this->assertStringNotContainsString('rt-timeline-suggestions-toggle', $sharedStyles);
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

    public function test_open_sidebar_defers_proposals_and_reuses_the_preview_until_the_plan_changes(): void
    {
        $previews = new \ArrayObject(['count' => 0]);
        $this->app->bind(TimelinePlanningSuggestionService::class, fn () => new class($previews) extends TimelinePlanningSuggestionService
        {
            public function __construct(private \ArrayObject $previews) {}

            public function preview(string $from, string $until, User $actor): array
            {
                $this->previews['count']++;

                return parent::preview($from, $until, $actor);
            }
        });
        EmployeeAvailability::create(['user_id' => $this->ben->id, 'kind' => 'preferred', 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin']);
        // Mit offenem Seitenpanel geöffnet: Plan sofort, Vorschläge erst per wire:init.
        $timeline = Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-10', 'until' => '2027-05-16', 'planningEnabled' => true, 'showSuggestions' => true])
            ->assertSet('suggestionsReady', false)
            ->assertSee('wire:init="loadSuggestions"', false)
            ->assertDontSee('data-timeline-proposal', false);
        $this->assertSame(0, $previews['count']);
        $timeline->call('loadSuggestions')->assertSet('suggestionsReady', true)
            ->assertSee('data-timeline-proposal', false)->assertDontSee('data-timeline-suggestions-pending', false);
        $this->assertSame(1, $previews['count']);
        // Fokuswechsel und Klick rechnen die Vorschau nicht neu.
        $timeline->dispatch('operations-timeline-distribution', open: true, shiftId: $this->shift->id)
            ->call('openSuggestion', $this->shift->id, $this->ben->id, 1)->assertSet('assignmentOpen', true);
        $this->assertSame(1, $previews['count']);
        // Eigene Planänderung: neuer Stand.
        $timeline->set('assignmentOpen', false)->dispatch('operations-plan-changed')->assertSet('previewVersion', 1);
        $this->assertSame(2, $previews['count']);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_a_remembered_proposal_is_rechecked_against_new_absences(): void
    {
        EmployeeAvailability::create(['user_id' => $this->ben->id, 'kind' => 'preferred', 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin']);
        $timeline = $this->timeline()->call('toggleSuggestions')->assertSee('data-timeline-proposal', false)
            ->call('openSuggestion', $this->shift->id, $this->ben->id, 1)->assertSet('assignmentOpen', true)->set('assignmentOpen', false);
        AbsenceRequest::create(['user_id' => $this->ben->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00', 'timezone' => 'Europe/Berlin']);
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
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringContainsString("[data-in-view='true']", $css);
        // Suggestions must stay outside event layout, not freeze the whole controller:
        // staff-timeline.test.js separately checks real lane geometry and scroll behavior.
        $this->assertMatchesRegularExpression('/\.rt-timeline-proposals\s*\{[^}]*position:\s*absolute/', $css);
        $this->assertStringContainsString("track.querySelectorAll('.rt-personnel-timeline-event')", file_get_contents(resource_path('js/staff-timeline.js')));
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

    public function test_hover_is_read_only_and_reports_suitable_blocked_and_empty_without_opening(): void
    {
        $timeline = $this->timeline()->call('previewCell', $this->ben->id, '2027-05-13')
            ->assertReturned(['state' => 'suitable', 'label' => '1 passend', 'detail' => 'Vorläufig geprüft · zum Auswählen klicken'])
            ->assertSet('assignmentOpen', false)->assertSet('planningUserId', null);
        AbsenceRequest::create(['user_id' => $this->ben->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00', 'timezone' => 'Europe/Berlin']);
        $timeline->call('previewCell', $this->ben->id, '2027-05-13')
            ->assertReturned(['state' => 'blocked', 'label' => 'Keine passende Schicht', 'detail' => 'Details und Konfliktgründe per Klick anzeigen.']);
        $timeline->call('previewCell', $this->ben->id, '2027-05-14')
            ->assertReturned(['state' => 'empty', 'label' => 'Keine offenen Dienste', 'detail' => 'Für diesen Tag ist nichts zu verteilen.']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->timeline(false)->call('previewCell', $this->ben->id, '2027-05-13')->assertForbidden();
        $this->timeline()->call('previewCell', $this->ben->id, '2027-06-13')->assertStatus(422);
    }

    public function test_workload_uses_real_daily_targets_and_never_infers_from_profile_prose(): void
    {
        $this->ben->profile()->create(['weekly_working_hours' => '40 Stunden']);
        ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $this->ben->id, 'status' => 'requested', 'assigned_by' => $this->manager->id]);
        $this->timeline()->assertViewHas('workloads', fn ($rows) => $rows[$this->ben->id]['target'] === null && $rows[$this->ben->id]['ratio'] === null && $rows[$this->ben->id]['requested'] === 450.0);
        $model = EmployeeWorkModel::create(['user_id' => $this->ben->id, 'name' => 'QA', 'status' => 'active', 'starts_on' => '2027-05-10', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400, 'daily_minutes' => [1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0], 'created_by' => $this->manager->id]);
        $this->timeline()->assertViewHas('workloads', fn ($rows) => $rows[$this->ben->id]['target'] === 2400 && $rows[$this->ben->id]['percent'] === 19);
        Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-13', 'until' => '2027-05-13', 'planningEnabled' => true])
            ->assertViewHas('workloads', fn ($rows) => $rows[$this->ben->id]['target'] === 480 && $rows[$this->ben->id]['percent'] === 94);
        $model->update(['ends_on' => '2027-05-12']);
        $this->timeline()->assertViewHas('workloads', fn ($rows) => $rows[$this->ben->id]['target'] === null && $rows[$this->ben->id]['missing_days'] === 4);
    }

    public function test_workload_counts_training_and_true_dst_duration_without_inventing_clipped_breaks(): void
    {
        $this->shift->forceFill(['starts_at' => CarbonImmutable::parse('2027-10-30T22:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-10-31T06:00+01:00')])->save();
        ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $this->ben->id, 'status' => 'confirmed', 'assigned_by' => $this->manager->id]);
        $training = PersonnelTraining::create(['title' => 'QA Training', 'starts_at' => '2027-10-31T12:00', 'ends_at' => '2027-10-31T14:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1, 'created_by' => $this->manager->id]);
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->ben->id, 'status' => 'confirmed', 'created_by' => $this->manager->id]);
        Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-10-30', 'until' => '2027-10-31', 'planningEnabled' => true])
            ->assertViewHas('workloads', fn ($rows) => $rows[$this->ben->id]['confirmed'] === 510.0 && $rows[$this->ben->id]['training'] === 120.0 && $rows[$this->ben->id]['planned'] === 630.0);
        Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-10-31', 'until' => '2027-10-31', 'planningEnabled' => true])
            ->assertViewHas('workloads', fn ($rows) => $rows[$this->ben->id]['confirmed'] === 420.0 && $rows[$this->ben->id]['clipped_breaks']);
    }

    public function test_known_zero_target_warns_about_planned_hours_without_inventing_a_percentage(): void
    {
        EmployeeWorkModel::create(['user_id' => $this->ben->id, 'name' => 'QA zero', 'status' => 'active', 'starts_on' => '2027-05-10', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 0, 'daily_minutes' => array_fill(1, 7, 0), 'created_by' => $this->manager->id]);
        ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $this->ben->id, 'status' => 'requested', 'assigned_by' => $this->manager->id]);
        $this->timeline()->assertViewHas('workloads', fn ($rows) => $rows[$this->ben->id]['target'] === 0 && $rows[$this->ben->id]['percent'] === null && $rows[$this->ben->id]['state'] === 'over');
    }
}
