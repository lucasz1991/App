<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Livewire\Operations\OperationsEnhancements;
use App\Livewire\Operations\PersonalPageWorkspace;
use App\Livewire\Operations\WorkforceAccounts;
use App\Models\OperationsMonthClosing;
use App\Models\PersonnelTask;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile as Profile;
use App\Services\Operations\PersonnelScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class PersonalPageWorkspaceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private User $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_07_22_000002_create_employee_document_requirements_table.php'))->up();
        foreach (['2026_07_18_000001_create_activity_log_table.php', '2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_101000_create_personnel_enhancements.php', '2026_10_06_102000_create_operations_enhancements.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-10 07:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'name' => 'Alpha Synthetic', 'status' => true]);
        $this->outside = User::factory()->create(['role' => 'staff', 'name' => 'Outside Synthetic', 'status' => true]);
    }

    private function manager(array $abilities, bool $scoped = true): User
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = new Team(['name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => array_fill_keys($abilities, true)]);
        $team->forceFill(['user_id' => $this->admin->id])->save();
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        if ($scoped) {
            $scopedAbilities = array_values(array_intersect($abilities, PersonnelScopeService::ABILITIES));
            app(PersonnelScopeService::class)->assign($this->employee, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => $scopedAbilities], $this->admin);
        }

        return $manager->fresh();
    }

    public function test_people_has_exactly_four_authorized_main_views(): void
    {
        $this->assertSame(['employees', 'documents', 'qualifications', 'training'], array_keys(PersonalPageWorkspace::availableViews($this->admin, 'people')));
        foreach (['people', 'personnel-processes', 'leave', 'time-review', 'payroll'] as $page) {
            $this->assertLessThanOrEqual(4, count(PersonalPageWorkspace::availableViews($this->admin, $page)));
        }
    }

    public function test_qualification_only_can_use_records_without_employee_or_account_permission(): void
    {
        $manager = $this->manager(['operations.qualifications.manage']);
        $this->actingAs($manager);
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people'])
            ->assertSet('view', 'qualifications')
            ->assertSee('Nachweisarten')
            ->assertDontSee('Personalnummer')
            ->call('setView', 'training')->assertSet('view', 'training')
            ->assertSee('Teilnehmer zuordnen')
            ->assertDontSee('Outside Synthetic');
    }

    public function test_training_component_has_no_hidden_accounts_for_qualification_only(): void
    {
        $this->actingAs($this->manager(['operations.qualifications.manage']));
        Livewire::test(WorkforceAccounts::class, ['tab' => 'training', 'embedded' => true, 'initialUserId' => $this->employee->id])
            ->assertSet('tab', 'training')
            ->assertViewHas('summary', null)
            ->assertViewHas('rules', fn ($rows) => $rows->isEmpty())
            ->assertViewHas('assignees', fn ($rows) => $rows->isEmpty())
            ->call('showTab', 'account')->assertForbidden();
    }

    public function test_export_only_has_export_and_history_but_cannot_enter_closing(): void
    {
        $manager = $this->manager(['operations.time.export']);
        $this->assertSame(['export', 'history'], array_keys(PersonalPageWorkspace::availableViews($manager, 'payroll')));
        $this->actingAs($manager);
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'payroll'])
            ->assertSet('view', 'export')
            ->assertSee('Auswahl exportieren')
            ->call('setView', 'closing')->assertForbidden();
    }

    public function test_rules_only_opens_only_rule_context(): void
    {
        $manager = $this->manager(['operations.rules.manage'], false);
        $this->actingAs($manager);
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'time-review'])
            ->assertSet('view', '')
            ->assertSet('section', 'rules')
            ->assertSee('Aktives Regelprofil')
            ->assertDontSee('Zeitstatus')
            ->call('setView', 'times')->assertForbidden();
    }

    public function test_terminal_only_opens_terminal_without_hr_or_time_data(): void
    {
        $this->actingAs($this->manager(['operations.terminal.manage']));
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'time-review'])
            ->assertSet('view', '')
            ->assertSet('section', 'terminal')
            ->assertSee('Zugang einrichten')
            ->assertDontSee('Zeitstatus')
            ->assertDontSee('Outside Synthetic');
    }

    public function test_unrelated_missing_operations_table_does_not_disable_personnel_records(): void
    {
        Schema::drop('work_time_exports');
        $this->actingAs($this->manager(['operations.qualifications.manage']));
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people', 'initialView' => 'qualifications'])
            ->assertSee('Nachweisarten');
    }

    public function test_foreign_training_person_context_is_rejected(): void
    {
        $this->actingAs($this->manager(['operations.qualifications.manage']));
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people', 'initialView' => 'training', 'context' => ['user_id' => $this->outside->id]])->assertForbidden();
    }

    public function test_account_kinds_separate_visible_balances(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(WorkforceAccounts::class, ['embedded' => true, 'accountKind' => 'time', 'initialUserId' => $this->employee->id])
            ->assertSee('Zeitkorrektur')->assertDontSee('Anspruch buchen');
        Livewire::test(WorkforceAccounts::class, ['embedded' => true, 'accountKind' => 'vacation', 'initialUserId' => $this->employee->id])
            ->assertSee('Anspruch buchen')->assertDontSee('Zeitkorrektur');
    }

    public function test_private_profile_values_do_not_escape_target_scope(): void
    {
        Profile::create(['user_id' => $this->outside->id, 'personnel_nr' => 'PRIVATE-MASTER-OUTSIDE', 'iban' => 'PRIVATE-COMPENSATION-OUTSIDE']);
        $this->actingAs($this->manager(['employees.view', 'employees.master-data.view', 'employees.compensation.view']));
        Livewire::test(UserProfile::class, ['userId' => $this->outside->id, 'embedded' => true])
            ->assertDontSee('PRIVATE-MASTER-OUTSIDE')->assertDontSee('PRIVATE-COMPENSATION-OUTSIDE')
            ->assertViewHas('canViewMasterData', false)->assertViewHas('canViewCompensation', false)
            ->assertSet('inlineValues', fn ($values) => ! array_key_exists('personnel_nr', $values) && ! array_key_exists('iban', $values));
    }

    public function test_compensation_scope_is_explicit_not_implied_by_master_data_scope(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'personnel_nr' => 'ALLOWED-MASTER', 'iban' => 'ALLOWED-COMPENSATION']);
        $manager = $this->manager(['employees.view', 'employees.master-data.view', 'employees.compensation.view'], false);
        app(PersonnelScopeService::class)->assign($this->employee, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => ['employees.master-data.view']], $this->admin);
        $this->actingAs($manager);
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSee('ALLOWED-MASTER')->assertDontSee('ALLOWED-COMPENSATION')
            ->assertViewHas('canViewCompensation', false);
    }

    public function test_export_only_month_history_uses_closed_snapshots_and_cannot_mutate(): void
    {
        $manager = $this->manager(['operations.time.export']);
        $snapshot = ['entries' => [], 'issues' => [], 'complete' => true, 'completeness' => ['complete' => true], 'payroll_reference' => [], 'user_id' => $this->employee->id, 'month' => '2027-04'];
        $closing = OperationsMonthClosing::create(['user_id' => $this->employee->id, 'month' => '2027-04', 'timezone' => 'Europe/Berlin', 'status' => 'reopened', 'revision' => 3, 'snapshot' => $snapshot + ['private_current' => 'NOT-THE-CLOSED-SNAPSHOT'], 'prepared_by' => $this->admin->id]);
        $closing->revisions()->create(['revision' => 2, 'action' => 'closed', 'snapshot' => $snapshot, 'actor_id' => $this->admin->id, 'created_at' => now()]);
        $this->actingAs($manager);
        Livewire::test(OperationsEnhancements::class, ['tab' => 'payroll', 'embedded' => true, 'payrollExportOnly' => true, 'initialRecordId' => $closing->id])
            ->assertSet('selectedRevision', 2)
            ->assertViewHas('closingSnapshot', $snapshot)
            ->assertDontSee('Kontrolliert öffnen')
            ->call('act', 'reopen')->assertForbidden();
        $this->assertSame(3, $closing->fresh()->revision);
    }

    public function test_explicit_unauthorized_initial_view_is_not_replaced_silently(): void
    {
        $this->actingAs($this->manager(['operations.qualifications.manage']));
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people', 'initialView' => 'employees'])->assertForbidden();
    }

    public function test_task_deep_link_keeps_type_revision_and_filters_child_rows(): void
    {
        $task = PersonnelTask::create(['user_id' => $this->employee->id, 'assigned_to' => $this->admin->id, 'title' => 'Focused task synthetic', 'type' => 'other', 'status' => 'open', 'revision' => 2, 'created_by' => $this->admin->id]);
        PersonnelTask::create(['user_id' => $this->employee->id, 'assigned_to' => $this->admin->id, 'title' => 'Unselected task synthetic', 'type' => 'other', 'status' => 'open', 'revision' => 1, 'created_by' => $this->admin->id]);
        $this->actingAs($this->admin);
        $context = ['user' => $this->employee->id, 'record' => $task->id, 'record_type' => 'task', 'revision' => 2];
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'personnel-processes', 'initialView' => 'tasks', 'context' => $context])
            ->assertSet('context.revision', 2)->assertSee('Focused task synthetic')->assertDontSee('Unselected task synthetic')
            ->call('setView', 'workflows')->assertDispatched('rt-workspace-url');
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'personnel-processes', 'initialView' => 'tasks', 'context' => array_replace($context, ['revision' => 1])])->assertStatus(409);
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'personnel-processes', 'initialView' => 'tasks', 'context' => array_replace($context, ['record_type' => 'qualification'])])->assertNotFound();
    }

    public function test_embedded_profile_does_not_mount_hidden_file_notes_or_document_children(): void
    {
        $this->actingAs($this->admin);
        $profile = Livewire::test(UserProfile::class, ['userId' => $this->employee->id, 'embedded' => true]);
        $profile->assertSee('Alpha Synthetic')->assertDontSee('wire:id="user-notes')->assertDontSee('employee-documents-');
        $this->assertDatabaseCount('file_pools', 0);
        $profile->call('setProfileTab', 'masterData')->assertSet('profileTab', 'masterData');
        $this->assertDatabaseCount('file_pools', 0);
    }

    public function test_internal_view_change_selects_an_allowed_person_for_the_new_scope(): void
    {
        $manager = $this->manager(['operations.qualifications.manage', 'employees.master-data.view'], false);
        app(PersonnelScopeService::class)->assign($this->employee, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => ['operations.qualifications.manage']], $this->admin);
        app(PersonnelScopeService::class)->assign($this->outside, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => ['employees.master-data.view']], $this->admin);
        $this->actingAs($manager);
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people', 'initialView' => 'training', 'context' => ['user' => $this->employee->id]])
            ->assertSet('userId', $this->employee->id)
            ->call('setView', 'documents')->assertSet('userId', $this->outside->id)
            ->assertDispatched('rt-workspace-url')
            ->call('setView', 'training')->assertSet('userId', $this->employee->id);
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people', 'initialView' => 'documents', 'context' => ['user' => $this->employee->id]])->assertForbidden();
    }

    public function test_secondary_context_can_open_without_unrelated_main_view_permission(): void
    {
        $this->actingAs($this->manager(['employees.master-data.view', 'employees.emergency.access']));
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people', 'initialSection' => 'emergency', 'context' => ['user' => $this->employee->id]])
            ->assertSet('section', 'emergency')->assertDontSee('Mitarbeiterliste');
        $this->actingAs($this->manager(['employees.master-data.view', 'operations.rules.manage']));
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'leave', 'initialSection' => 'calendars', 'context' => ['user' => $this->employee->id]])
            ->assertSet('section', 'calendars')->assertSee('Regionalkalender');
    }

    public function test_responsibility_form_exposes_explicit_compensation_scopes(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(WorkforceAccounts::class, ['tab' => 'responsibilities', 'embedded' => true, 'initialUserId' => $this->employee->id])
            ->call('openForm', 'responsibility')->assertSee('Vergütung anzeigen')->assertSee('Vergütung bearbeiten');
    }
}
