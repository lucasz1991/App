<?php

namespace Tests\Feature;

use App\Livewire\Operations\PersonnelEnhancements;
use App\Livewire\Operations\PersonnelReview;
use App\Livewire\Operations\WorkforceAccounts;
use App\Models\EmployeeDocumentRequirement;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeQualification;
use App\Models\OperationAudit;
use App\Models\PersonnelSignatureRequest;
use App\Models\QualificationType;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileScopeTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_07_22_000002_create_employee_document_requirements_table.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_06_101000_create_personnel_enhancements.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Storage::fake('private');
        $this->travelTo(CarbonImmutable::parse('2027-05-10 07:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['name' => 'Profile Target Fixture', 'role' => 'staff', 'status' => true]);
        $this->other = User::factory()->create(['name' => 'Unrelated Person Fixture', 'role' => 'staff', 'status' => true]);
    }

    private function scope(string $tab): array
    {
        return ['embedded' => true, 'tab' => $tab, 'profileUserId' => $this->employee->id];
    }

    private function qualification(User $employee, string $status = 'pending'): EmployeeQualification
    {
        $type = QualificationType::firstOrCreate(['name' => 'Profile qualification fixture'], ['is_active' => true]);

        return EmployeeQualification::create(['user_id' => $employee->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2028-01-01', 'status' => $status]);
    }

    private function signature(User $employee): PersonnelSignatureRequest
    {
        $requirement = EmployeeDocumentRequirement::create(['user_id' => $employee->id, 'document_type' => 'employment_contract']);
        $path = 'uploads/employee-documents/'.$employee->id.'/employment_contract/profile.pdf';
        Storage::disk('private')->put($path, '%PDF-1.4 synthetic fixture');
        $file = $requirement->file()->create(['user_id' => $this->admin->id, 'name' => 'Fixture.pdf', 'path' => $path, 'disk' => 'private', 'mime_type' => 'application/pdf', 'size' => 25, 'type' => 'employee-document']);
        $version = EmployeeDocumentVersion::create(['employee_document_requirement_id' => $requirement->id, 'file_id' => $file->id, 'revision' => 1, 'snapshot' => ['sha256' => hash_file('sha256', Storage::disk('private')->path($path))], 'created_by' => $this->admin->id, 'created_at' => now()->utc()]);

        return app(PersonnelEnhancementService::class)->requestSignature($version, ['title' => 'Signature '.$employee->id, 'due_on' => '2027-05-12'], $this->admin);
    }

    private function assertRecordExcluded(callable $action): void
    {
        try {
            $action();
            $this->fail('An unrelated profile record must not be found.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_qualification_profile_queries_and_actions_never_include_another_employee(): void
    {
        $own = $this->qualification($this->employee, 'approved');
        $other = $this->qualification($this->other);
        $parameters = ['module' => 'qualifications', 'embedded' => true, 'profileUserId' => $this->employee->id];
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, $parameters)
            ->assertSet('filter', 'all')
            ->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$own->id])
            ->call('openDetails', $own->id)->assertSet('selectedId', $own->id);
        $this->assertRecordExcluded(fn () => Livewire::actingAs($this->admin)->test(PersonnelReview::class, $parameters)->call('openDetails', $other->id));
        $this->assertRecordExcluded(fn () => Livewire::actingAs($this->admin)->test(PersonnelReview::class, $parameters)->call('decide', $other->id, 1, 'approve'));
        $this->assertSame('pending', $other->fresh()->status);
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, $parameters)->call('createRules')->assertForbidden();
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, $parameters)->call('addType')->assertForbidden();
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'absences', 'embedded' => true, 'profileUserId' => $this->employee->id])->assertForbidden();
    }

    public function test_profile_identity_and_tab_properties_are_locked(): void
    {
        foreach ([
            [PersonnelReview::class, ['module' => 'qualifications', 'embedded' => true, 'profileUserId' => $this->employee->id], 'profileUserId', $this->other->id],
            [WorkforceAccounts::class, $this->scope('tasks'), 'profileUserId', $this->other->id],
            [WorkforceAccounts::class, $this->scope('tasks'), 'profileTab', 'training'],
            [PersonnelEnhancements::class, $this->scope('documents'), 'profileUserId', $this->other->id],
            [PersonnelEnhancements::class, $this->scope('documents'), 'profileTab', 'reports'],
        ] as [$component, $parameters, $property, $value]) {
            try {
                Livewire::actingAs($this->admin)->test($component, $parameters)->set($property, $value);
                $this->fail('Profile context must be locked.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_workforce_profile_keeps_target_and_tab_immutable_and_rejects_foreign_records(): void
    {
        $service = app(PersonnelProcessService::class);
        $own = $service->createTask($this->employee, ['title' => 'Own profile task', 'type' => 'general', 'assigned_to' => $this->admin->id], $this->admin);
        $other = $service->createTask($this->other, ['title' => 'Other profile task', 'type' => 'general', 'assigned_to' => $this->admin->id], $this->admin);
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('tasks'))
            ->assertSee('Own profile task')->assertDontSee('Other profile task')
            ->call('completeTask', $own->id, 1)->assertHasNoErrors();
        $this->assertSame('done', $own->fresh()->status);
        $this->assertRecordExcluded(fn () => Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('tasks'))->call('completeTask', $other->id, 1));
        $this->assertSame('open', $other->fresh()->status);
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('tasks'))->set('userId', $this->other->id)->assertForbidden();
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('tasks'))->set('tab', 'models')->assertForbidden();
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('tasks'))->call('showTab', 'models')->assertForbidden();
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('tasks'))->call('openForm', 'model')->assertForbidden();
    }

    public function test_training_profile_preserves_individual_participation_but_not_course_wide_actions(): void
    {
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('training'))
            ->assertSee('Schulungen verwalten')->assertSee('Hier bearbeiten Sie die Teilnahme dieses Mitarbeiters.')
            ->call('openForm', 'enroll')->assertSet('formOpen', true)->assertSet('formKind', 'enroll');
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('training'))->call('openForm', 'training_cancel', 1)->assertForbidden();
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, $this->scope('training'))->call('openForm', 'training')->assertForbidden();
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, ['tab' => 'training', 'embedded' => true, 'initialUserId' => $this->employee->id])
            ->call('openForm', 'training')->assertSet('formKind', 'training')->assertSet('formOpen', true);
    }

    public function test_enhancement_profile_blocks_global_tabs_and_actions_and_target_changes(): void
    {
        foreach (['reports', 'surveys', 'recruiting', 'approvals', 'calendars'] as $tab) {
            Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope($tab))->assertForbidden();
        }
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))->set('userId', $this->other->id)->assertForbidden();
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))->set('tab', 'emergency')->assertForbidden();
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))->call('showTab', 'reports')->assertForbidden();
        foreach (['document_template', 'report', 'applicant', 'survey', 'approval_policy'] as $kind) {
            Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))->call('open', $kind)->assertForbidden();
        }
        foreach ([['runReport', 1, 1], ['downloadReport', 1], ['reportState', 1, 1, false], ['surveyResult', 1], ['eraseApplicant', 1, 1], ['activate', 'document_template', 1, 1]] as $action) {
            Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))->call(...$action)->assertForbidden();
        }
    }

    public function test_signature_and_workflow_actions_cannot_cross_employee_profile_boundaries(): void
    {
        $own = $this->signature($this->employee);
        $other = $this->signature($this->other);
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))
            ->assertSee($own->title)->assertDontSee($other->title)
            ->call('downloadSignatureSource', $own->id)->assertFileDownloaded();
        foreach ([['downloadSignatureSource', $other->id], ['downloadSignature', $other->id], ['reviewSignature', $other->id, 1, true]] as $action) {
            Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))->call(...$action)->assertForbidden();
        }
        $task = app(PersonnelProcessService::class)->createTask($this->other, ['title' => 'Foreign workflow task', 'type' => 'general', 'assigned_to' => $this->admin->id], $this->admin);
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('workflows'))->call('completeTask', $task->id, 1)->assertForbidden();
        $this->assertSame('open', $task->fresh()->status);
    }

    public function test_emergency_profile_retains_explicit_reason_and_audit(): void
    {
        app(PersonnelEnhancementService::class)->saveEmergency($this->employee, ['name' => 'Protected Emergency Fixture', 'relationship' => 'Familie', 'phone' => '+4912345', 'consent' => true], 0, $this->employee);
        $component = Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('emergency'))
            ->assertDontSee('Protected Emergency Fixture')->call('open', 'emergency_read')->assertSet('display', [])
            ->call('save')->assertHasErrors('reason')->assertDontSee('Protected Emergency Fixture');
        $this->assertFalse(OperationAudit::where('action', 'emergency.break_glass')->exists());
        $component->set('form.note', 'Notfallkontakt bei Dienstunfall erforderlich.')->call('save')->assertHasNoErrors()->assertSee('Protected Emergency Fixture');
        $this->assertSame(1, OperationAudit::where('action', 'emergency.break_glass')->count());
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('emergency'))->call('open', 'emergency_edit')->assertForbidden();
    }

    public function test_profile_scopes_preserve_role_and_person_responsibility_permissions(): void
    {
        $abilities = ['employees.master-data.view', 'operations.qualifications.manage'];
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = new Team(['name' => 'Scoped profile fixture', 'personal_team' => false, 'rbac_permissions' => array_fill_keys($abilities, true)]);
        $team->forceFill(['user_id' => $this->admin->id])->save();
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->other, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => $abilities], $this->admin);
        Livewire::actingAs($manager->fresh())->test(PersonnelReview::class, ['module' => 'qualifications', 'embedded' => true, 'profileUserId' => $this->employee->id])->assertForbidden();
        Livewire::actingAs($manager->fresh())->test(WorkforceAccounts::class, $this->scope('tasks'))->assertForbidden();
        Livewire::actingAs($manager->fresh())->test(PersonnelEnhancements::class, $this->scope('documents'))->assertForbidden();
        Livewire::actingAs($manager->fresh())->test(WorkforceAccounts::class, $this->scope('training') + ['initialUserId' => $this->other->id])->assertForbidden();
    }

    public function test_existing_non_profile_selectors_continue_to_work(): void
    {
        Livewire::actingAs($this->admin)->test(WorkforceAccounts::class, ['tab' => 'tasks', 'initialUserId' => $this->employee->id])
            ->set('userId', $this->other->id)->assertSet('userId', $this->other->id)->call('showTab', 'models')->assertSet('tab', 'models')->assertOk();
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, ['tab' => 'documents', 'initialUserId' => $this->employee->id])
            ->set('userId', $this->other->id)->assertSet('userId', $this->other->id)->call('showTab', 'reports')->assertSet('tab', 'reports')->assertOk();
        $this->qualification($this->employee);
        $this->qualification($this->other);
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'qualifications', 'embedded' => true])
            ->assertViewHas('records', fn ($records) => $records->total() === 2);
    }

    public function test_profile_links_keep_collective_tools_available(): void
    {
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'qualifications', 'embedded' => true, 'profileUserId' => $this->employee->id])
            ->assertSee('Nachweisübersicht & Nachweisarten', false)->assertDontSee('Mitarbeiter suchen');
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('documents'))
            ->assertSee('Unterzeichnungen & Vorlagen verwalten')->assertDontSee('open(&#039;document_template&#039;)', false);
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('workflows'))->assertSee('Prozesse & Vorlagen verwalten');
    }

    public function test_development_profile_cannot_open_or_update_a_foreign_review(): void
    {
        $service = app(PersonnelEnhancementService::class);
        $data = ['title' => 'Profile development fixture', 'goal' => 'Synthetic development goal', 'due_on' => '2027-05-15', 'responsible_id' => $this->admin->id];
        $own = $service->saveDevelopment($this->employee, $data, $this->admin);
        $other = $service->saveDevelopment($this->other, $data, $this->admin);
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('development'))
            ->call('open', 'feedback', $own->id, 1)->set('form.note', 'Reviewed profile goal.')->call('save')->assertHasNoErrors();
        $this->assertSame(2, $own->fresh()->revision);
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, $this->scope('development'))
            ->call('open', 'feedback', $other->id, 1)->assertForbidden();
        $this->assertSame(1, $other->fresh()->revision);
    }

    public function test_profile_context_cannot_be_used_as_selfservice_or_for_non_staff_targets(): void
    {
        foreach ([WorkforceAccounts::class => 'tasks', PersonnelEnhancements::class => 'documents'] as $component => $tab) {
            Livewire::actingAs($this->admin)->test($component, ['profileUserId' => $this->employee->id, 'tab' => $tab])->assertForbidden();
            Livewire::actingAs($this->employee)->test($component, $this->scope($tab) + ['personal' => true])->assertForbidden();
        }
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'qualifications', 'profileUserId' => $this->employee->id])->assertForbidden();
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'qualifications', 'embedded' => true, 'profileUserId' => $this->admin->id])->assertNotFound();
        Livewire::actingAs($this->admin)->test(PersonnelEnhancements::class, ['tab' => 'documents', 'embedded' => true, 'profileUserId' => $this->admin->id])->assertNotFound();
    }
}
