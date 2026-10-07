<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffRegionalPreference;
use App\Models\User;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffRegionalPreferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ShiftStaffingPanelTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private Order $order;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_06_220000_create_staff_regional_preferences_table.php'))->up();
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Geprüftes Testprofil', 'minimum_rest_minutes' => 660, 'maximum_shift_minutes' => 600, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Testbahn', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Bahnhofseinsatz', 'service_type' => 'Wagenmeister', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-15T00:00', 'required_staff' => 3, 'created_by' => $this->admin->id]);
        $this->shift = $this->makeShift();
    }

    private function makeShift(array $extra = []): Shift
    {
        return Shift::create(array_replace(['order_id' => $this->order->id, 'title' => 'Zugabfertigung', 'role_name' => 'Wagenmeister', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T11:00:00+02:00', 'ends_at' => '2027-05-13T13:00:00+02:00', 'required_staff' => 2, 'planned_break_minutes' => 0, 'status' => 'draft', 'location_name' => 'München Hbf', 'created_by' => $this->admin->id], $extra))->fresh();
    }

    private function employee(string $name = 'Erika Muster'): User
    {
        return User::factory()->create(['name' => $name, 'role' => 'staff', 'status' => true]);
    }

    private function region(User $employee, bool $noGo = false): array
    {
        return app(StaffRegionalPreferenceService::class)->save($employee, $this->admin, [
            'enabled' => true, 'base_location' => 'München', 'preferred_radius_km' => 50, 'border_radius_km' => 100,
            'no_go_areas' => $noGo ? [['location' => 'München', 'radius_km' => 10]] : [],
        ], 0);
    }

    private function panel()
    {
        return Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('setView', 'table')->call('openDetails', $this->shift->id);
    }

    private function conflictingShift(User $employee): Shift
    {
        $other = $this->makeShift(['starts_at' => '2027-05-13T08:00:00+02:00', 'ends_at' => '2027-05-13T12:00:00+02:00']);
        app(ShiftAssignmentService::class)->assign($other, $employee, $this->admin);

        return $other;
    }

    public function test_candidate_loading_is_lazy_and_distinguishes_loading_from_no_results(): void
    {
        $employee = $this->employee();
        $component = $this->panel()->assertSet('candidatesReady', false)->assertSet('candidateLimit', 12)
            ->assertSee('Eignung wird geprüft')->assertViewHas('candidates', fn ($candidates) => $candidates->isEmpty());
        $component->call('loadStaffingCandidates')->assertSet('candidatesReady', true)->assertSee('Name suchen')
            ->assertViewHas('candidateTotal', 1)
            ->assertViewHas('candidates', fn ($candidates) => $candidates->pluck('id')->all() === [$employee->id]);
        $component->set('candidateSearch', 'Nicht vorhanden')->assertViewHas('candidateTotal', 0)
            ->assertSee('Keine Treffer. Suche oder Filter anpassen.');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_full_population_is_ranked_before_incremental_twelve_then_twenty_four_and_filters_reset_limit(): void
    {
        foreach (range(1, 25) as $index) {
            $this->employee(sprintf('Person %02d', $index));
        }
        $preferred = $this->employee('Zora Regional');
        $blocked = $this->employee('Aaron NoGo');
        $this->region($preferred);
        $this->region($blocked, true);
        $component = $this->panel()->call('loadStaffingCandidates')->assertViewHas('candidateTotal', 27)
            ->assertViewHas('candidates', fn ($candidates) => $candidates->count() === 12 && $candidates->first()->id === $preferred->id);
        $firstIds = $component->viewData('candidates')->pluck('id')->all();
        $component->call('loadMoreCandidates')->assertSet('candidateLimit', 24)
            ->assertViewHas('candidates', fn ($candidates) => $candidates->count() === 24 && $candidates->pluck('id')->unique()->count() === 24);
        $this->assertSame($firstIds, $component->viewData('candidates')->take(12)->pluck('id')->all());
        $component->call('loadMoreCandidates')->assertViewHas('candidates', fn ($candidates) => $candidates->count() === 27 && $candidates->last()->id === $blocked->id);
        $component->set('candidateSuitability', 'blocked')->assertSet('candidateLimit', 12)
            ->assertViewHas('candidates', fn ($candidates) => $candidates->pluck('id')->all() === [$blocked->id]);
        $component->call('loadMoreCandidates')->set('candidateRegion', 'no_go')->assertSet('candidateLimit', 12);
        $component->call('resetCandidateFilters')->assertSet('candidateSuitability', 'all')->assertSet('candidateRegion', 'all')
            ->assertSet('candidateSearch', '')->assertSet('candidateLimit', 12)->assertViewHas('candidateTotal', 27);
        $component->call('loadMoreCandidates')->set('candidateSearch', 'Zora')->assertSet('candidateLimit', 12)
            ->assertViewHas('candidates', fn ($candidates) => $candidates->pluck('id')->all() === [$preferred->id]);
    }

    public function test_blocked_person_cannot_be_selected_by_forging_the_button_action(): void
    {
        $employee = $this->employee();
        $this->region($employee, true);
        $this->panel()->call('loadStaffingCandidates')->call('chooseCandidate', $employee->id)
            ->assertSet('employeeId', null)->assertHasErrors(['assignment']);
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_normal_choice_is_read_only_and_assignment_requires_explicit_save(): void
    {
        $employee = $this->employee();
        $component = $this->panel()->call('chooseCandidate', $employee->id)->assertSet('employeeId', $employee->id)
            ->assertSee('Noch nicht gespeichert')->assertSee('Verbindlich zuweisen');
        $this->assertSame(0, ShiftAssignment::count());
        $component->call('assignEmployee')->assertHasNoErrors()->assertSet('employeeId', null)->assertSet('assignmentReview', []);
        $this->assertSame(1, ShiftAssignment::where('shift_id', $this->shift->id)->where('user_id', $employee->id)->count());
    }

    public function test_temporal_exception_needs_reason_acknowledgment_then_typed_final_confirmation(): void
    {
        $employee = $this->employee();
        $this->conflictingShift($employee);
        $component = $this->panel()->call('chooseCandidate', $employee->id)
            ->assertSet('employeeId', $employee->id)->assertSet('exceptionPrepared', false)
            ->assertSee('Zeitkonflikt prüfen')->assertSee('Pausen, Ruhezeit und Folgen');
        $component->call('prepareAssignmentException')->assertHasErrors(['exceptionReason', 'exceptionAcknowledged']);
        $this->assertSame(0, ShiftAssignment::where('shift_id', $this->shift->id)->count());
        $component->set('exceptionReason', 'Beide Züge am gleichen Bahnhof betreut; Aufgaben und tatsächliche Pausen wurden abgestimmt.')
            ->set('exceptionAcknowledged', true)->call('prepareAssignmentException')->assertHasNoErrors()
            ->assertSet('exceptionPrepared', true)->assertSeeHtml('data-exception-final-confirmation');
        $component->set('exceptionConfirmation', 'ja')->call('confirmAssignmentException')->assertHasErrors(['exceptionConfirmation']);
        $this->assertSame(0, ShiftAssignment::where('shift_id', $this->shift->id)->count());
        $component->set('exceptionConfirmation', 'FREIGEBEN')->call('confirmAssignmentException')
            ->assertHasNoErrors()->assertSet('employeeId', null)->assertSet('exceptionPrepared', false);
        $this->assertSame(1, ShiftAssignment::where('shift_id', $this->shift->id)->count());
        $this->assertSame(1, OperationAudit::where('action', 'assignment.temporal_exception')->count());
    }

    public function test_changing_shift_clears_candidate_confirmation_regions_and_incremental_state(): void
    {
        $employee = $this->employee();
        $this->conflictingShift($employee);
        $component = $this->panel()->call('loadMoreCandidates')->call('chooseCandidate', $employee->id)
            ->set('exceptionReason', 'Beide Züge am gleichen Bahnhof betreut; Aufgaben und tatsächliche Pausen wurden abgestimmt.')
            ->set('exceptionAcknowledged', true)->call('prepareAssignmentException')->assertSet('exceptionPrepared', true)
            ->call('editRegionalPreference', $employee->id);
        $other = $this->makeShift(['starts_at' => '2027-05-14T11:00:00+02:00', 'ends_at' => '2027-05-14T13:00:00+02:00']);
        $component->call('openDetails', $other->id)->assertSet('selectedShiftId', $other->id)
            ->assertSet('employeeId', null)->assertSet('assignmentReview', [])->assertSet('exceptionPrepared', false)
            ->assertSet('exceptionAcknowledged', false)->assertSet('exceptionReason', '')->assertSet('exceptionConfirmation', '')
            ->assertSet('regionalEmployeeId', null)->assertSet('candidatesReady', false)->assertSet('candidateLimit', 12);
        $this->assertSame(0, ShiftAssignment::where('shift_id', $other->id)->count());
    }

    public function test_stale_shift_revision_is_rejected_after_exception_preparation(): void
    {
        $employee = $this->employee();
        $this->conflictingShift($employee);
        $component = $this->panel()->call('chooseCandidate', $employee->id)
            ->set('exceptionReason', 'Beide Züge am gleichen Bahnhof betreut; Aufgaben und tatsächliche Pausen wurden abgestimmt.')
            ->set('exceptionAcknowledged', true)->call('prepareAssignmentException')->assertSet('exceptionPrepared', true);
        $this->shift->increment('revision');
        $component->set('exceptionConfirmation', 'FREIGEBEN')->call('confirmAssignmentException')
            ->assertHasErrors(['assignment'])->assertSet('exceptionPrepared', false)->assertSet('exceptionAcknowledged', false);
        $this->assertSame(0, ShiftAssignment::where('shift_id', $this->shift->id)->count());
    }

    public function test_region_editor_saves_separate_revision_then_rejects_stale_edit(): void
    {
        $employee = $this->employee();
        $component = $this->panel()->call('editRegionalPreference', $employee->id)->assertSet('regionalRevision', 0)
            ->set('regionalPreference.enabled', true)->set('regionalPreference.base_location', 'München')
            ->call('saveRegionalPreference')->assertHasNoErrors()->assertSet('regionalEmployeeId', null);
        $record = StaffRegionalPreference::where('user_id', $employee->id)->sole();
        $this->assertSame(1, $record->revision);
        $component->call('editRegionalPreference', $employee->id)->assertSet('regionalRevision', 1);
        $newConfig = app(StaffRegionalPreferenceService::class)->get($employee);
        unset($newConfig['revision']);
        $newConfig['base_location'] = 'Hamburg';
        app(StaffRegionalPreferenceService::class)->save($employee, $this->admin, $newConfig, 1);
        $component->set('regionalPreference.base_location', 'Berlin')->call('saveRegionalPreference')->assertHasErrors(['regionalPreference']);
        $this->assertSame('Hamburg', app(StaffRegionalPreferenceService::class)->get($employee)['base_location']);
    }

    public function test_staffing_actions_recheck_permissions_after_the_panel_was_mounted(): void
    {
        $employee = $this->employee();
        $component = $this->panel();
        $this->actingAs($employee);
        $component->call('loadStaffingCandidates')->assertForbidden();
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, StaffRegionalPreference::count());
    }

    public function test_qualification_filter_uses_actual_valid_approvals_and_resets_incremental_window(): void
    {
        $approved = $this->employee('Erika Freigegeben');
        $pending = $this->employee('Paul Ungeprüft');
        $qualification = QualificationType::create(['name' => 'Wagenprüfung', 'is_active' => true]);
        foreach ([[$approved, 'approved'], [$pending, 'pending']] as [$employee, $status]) {
            EmployeeQualification::create(['user_id' => $employee->id, 'qualification_type_id' => $qualification->id, 'status' => $status, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
        }
        $this->panel()->call('loadMoreCandidates')->assertSet('candidateLimit', 24)
            ->set('candidateQualification', (string) $qualification->id)->assertSet('candidateLimit', 12)
            ->assertViewHas('candidates', fn ($candidates) => $candidates->pluck('id')->all() === [$approved->id])
            ->assertViewHas('candidateFilterOptions', fn ($options) => array_column($options['qualifications'], 'id') === [$qualification->id]);
    }

    public function test_confirmation_cannot_be_called_before_the_second_confirmation_stage(): void
    {
        $employee = $this->employee();
        $this->conflictingShift($employee);
        $component = $this->panel()->call('chooseCandidate', $employee->id)->set('exceptionConfirmation', 'FREIGEBEN');
        $component->call('confirmAssignmentException')->assertForbidden();
        $this->assertSame(0, ShiftAssignment::where('shift_id', $this->shift->id)->count());
    }
}
