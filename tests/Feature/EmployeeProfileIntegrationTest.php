<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Livewire\Operations\PersonalPageWorkspace;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile as Profile;
use App\Services\Operations\PersonnelScopeService;
use App\Support\Operations\EmployeeProfileSections;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeProfileIntegrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private User $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_07_22_000002_create_employee_document_requirements_table.php',
            '2026_07_18_000001_create_activity_log_table.php',
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_09_17_190000_create_employee_payroll_references.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_121000_create_customer_workflow_extensions.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php',
            '2026_10_06_101000_create_personnel_enhancements.php',
            '2026_10_06_102000_create_operations_enhancements.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-10 07:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'name' => 'Alpha Profile Fixture', 'status' => true]);
        $this->outside = User::factory()->create(['role' => 'staff', 'name' => 'Other Profile Fixture', 'status' => true]);
    }

    private function manager(array $abilities): User
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = new Team(['name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => array_fill_keys($abilities, true)]);
        $team->forceFill(['user_id' => $this->admin->id])->save();
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();

        return $manager->fresh();
    }

    private function responsibility(User $manager, User $employee, array $abilities): void
    {
        app(PersonnelScopeService::class)->assign($employee, [
            'responsible_user_id' => $manager->id,
            'starts_on' => '2027-01-01',
            'abilities' => $abilities,
        ], $this->admin);
    }

    public function test_admin_gets_all_existing_individual_modules_not_global_workspaces(): void
    {
        $sections = EmployeeProfileSections::forUser($this->admin, $this->employee);
        $this->assertSame([
            'qualifications', 'training', 'signatures', 'emergency', 'workflows', 'development',
            'sickness', 'tasks', 'accounts', 'absences', 'workModel', 'vacationPolicy', 'ruleAssignments',
            'planReviews', 'responsibilities',
        ], array_keys($sections));
        foreach (['reports', 'recruiting', 'surveys', 'approvals', 'payroll', 'terminal'] as $globalSection) {
            $this->assertArrayNotHasKey($globalSection, $sections);
        }
    }

    public function test_base_profile_permission_does_not_grant_personnel_modules(): void
    {
        $manager = $this->manager(['employees.view']);
        $this->assertSame([], EmployeeProfileSections::forUser($manager, $this->employee));
        $this->actingAs($manager);
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertViewHas('employeeProfileTabs', fn (array $tabs) => array_keys($tabs) === ['userDetails'])
            ->call('setProfileTab', 'qualifications')->assertForbidden();
    }

    public function test_qualification_only_preserves_limited_workspace_without_full_profile_access(): void
    {
        $manager = $this->manager(['operations.qualifications.manage']);
        $this->responsibility($manager, $this->employee, ['operations.qualifications.manage']);
        $this->assertSame(['qualifications', 'training'], array_keys(EmployeeProfileSections::forUser($manager, $this->employee)));
        $this->actingAs($manager);
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id])->assertForbidden();
        Livewire::test(PersonalPageWorkspace::class, ['page' => 'people', 'initialView' => 'training', 'context' => ['user' => $this->employee->id]])
            ->assertSet('userId', $this->employee->id)->assertSee('Teilnehmer zuordnen')
            ->assertDontSee($this->outside->name);
    }

    public function test_sections_follow_each_ability_scope_without_switching_the_profile_employee(): void
    {
        $manager = $this->manager(['employees.view', 'operations.qualifications.manage', 'employees.master-data.view', 'employees.emergency.access']);
        $this->responsibility($manager, $this->employee, ['operations.qualifications.manage']);
        $this->responsibility($manager, $this->outside, ['employees.master-data.view', 'employees.emergency.access']);

        $this->assertSame(['qualifications', 'training'], array_keys(EmployeeProfileSections::forUser($manager, $this->employee)));
        $otherSections = EmployeeProfileSections::forUser($manager, $this->outside);
        $this->assertArrayHasKey('emergency', $otherSections);
        $this->assertArrayNotHasKey('qualifications', $otherSections);

        $this->actingAs($manager);
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id, 'embedded' => true])
            ->call('setProfileTab', 'training')->assertSet('userId', $this->employee->id)
            ->assertSet('profileTab', 'training')->assertDontSee($this->outside->name)
            ->call('setProfileTab', 'emergency')->assertForbidden();
    }

    #[DataProvider('emergencyScopes')]
    public function test_emergency_tab_requires_both_scopes_for_the_same_employee(array $abilities, bool $visible): void
    {
        $manager = $this->manager(['employees.master-data.view', 'employees.emergency.access']);
        $this->responsibility($manager, $this->employee, $abilities);
        $this->assertSame($visible, array_key_exists('emergency', EmployeeProfileSections::forUser($manager, $this->employee)));
    }

    public static function emergencyScopes(): array
    {
        return [
            'master-data only' => [['employees.master-data.view'], false],
            'emergency only' => [['employees.emergency.access'], false],
            'both' => [['employees.master-data.view', 'employees.emergency.access'], true],
        ];
    }

    public function test_rule_assignments_need_the_rule_permission_for_this_employee(): void
    {
        $manager = $this->manager(['employees.view', 'employees.master-data.view', 'employees.master-data.edit', 'operations.rules.manage']);
        $this->responsibility($manager, $this->employee, ['employees.master-data.view', 'employees.master-data.edit']);
        $this->responsibility($manager, $this->outside, ['employees.master-data.view', 'operations.rules.manage']);
        $this->assertArrayNotHasKey('ruleAssignments', EmployeeProfileSections::forUser($manager, $this->employee));
        $this->assertArrayHasKey('ruleAssignments', EmployeeProfileSections::forUser($manager, $this->outside));
        $this->actingAs($manager);
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id])
            ->call('setProfileTab', 'ruleAssignments')->assertForbidden();
    }

    public function test_inactive_actor_and_non_employee_target_have_no_personnel_sections(): void
    {
        $this->assertSame([], EmployeeProfileSections::forUser($this->admin, $this->admin));
        $this->admin->forceFill(['status' => false])->save();
        $this->assertSame([], EmployeeProfileSections::forUser($this->admin, $this->employee));
    }

    #[DataProvider('optionalModules')]
    public function test_missing_optional_schema_only_removes_affected_module_family(string $table, array $missing, array $preserved): void
    {
        Schema::drop($table);
        $sections = EmployeeProfileSections::forUser($this->admin, $this->employee);
        foreach ($missing as $key) {
            $this->assertArrayNotHasKey($key, $sections);
        }
        foreach ($preserved as $key) {
            $this->assertArrayHasKey($key, $sections);
        }
        $this->actingAs($this->admin);
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id])->assertSuccessful();
    }

    public static function optionalModules(): array
    {
        return [
            'qualifications' => ['employee_qualifications', ['qualifications'], ['training', 'signatures', 'accounts']],
            'processes' => ['personnel_trainings', ['training', 'tasks'], ['qualifications', 'signatures', 'accounts']],
            'enhancements' => ['employee_emergency_contacts', ['emergency', 'signatures', 'workflows', 'development', 'sickness'], ['qualifications', 'training', 'accounts']],
            'plan reviews' => ['personnel_plan_reviews', ['planReviews'], ['qualifications', 'training', 'accounts', 'workModel']],
            'absences' => ['absence_requests', ['absences'], ['qualifications', 'workModel']],
            'rule profiles' => ['operations_rule_profiles', ['ruleAssignments'], ['qualifications', 'workModel']],
            'responsibilities' => ['personnel_responsibilities', ['responsibilities'], ['qualifications', 'workModel']],
        ];
    }

    #[DataProvider('profileModes')]
    public function test_profile_preserves_existing_tools_but_only_mounts_the_active_section(bool $embedded): void
    {
        $this->actingAs($this->admin);
        $profile = Livewire::test(UserProfile::class, ['userId' => $this->employee->id, 'embedded' => $embedded])
            ->assertSet('profileTab', 'userDetails')
            ->assertViewHas('employeeProfileTabs', fn (array $tabs) => array_diff(['userDetails', 'userNotes', 'userFiles', 'userMessages', 'devices', 'masterData', 'documents', 'compensation'], array_keys($tabs)) === []);
        $this->assertSame([], $this->personnelChildren($profile->html()));
        $this->assertDatabaseCount('file_pools', 0);
        $profile->assertSee('data-profile-navigation', false)
            ->assertSee('role="tablist"', false)
            ->assertDontSee('@js(', false)
            ->assertDontSee('employee-profile-section-listbox', false);
        $this->assertProfileTabRelationship($profile->html(), 'userDetails');
        $profile->call('setProfileTab', 'masterData')->assertSet('profileTab', 'masterData');
        $this->assertProfileTabRelationship($profile->html(), 'masterData');
        $this->assertSame([], $this->personnelChildren($profile->html()));
        $this->assertDatabaseCount('file_pools', 0);
    }

    public static function profileModes(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    private function assertProfileTabRelationship(string $html, string $tab): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $tabs = $xpath->query('//*[@role="tab" and @aria-selected="true"]');
        $this->assertCount(1, $tabs);
        $selected = $tabs->item(0);
        $this->assertSame($tab, $selected->getAttribute('data-panel-tab'));
        $panel = $xpath->query('//*[@id="'.$selected->getAttribute('aria-controls').'"]')->item(0);
        $this->assertNotNull($panel);
        $this->assertSame('tabpanel', $panel->getAttribute('role'));
        $this->assertSame($selected->getAttribute('id'), $panel->getAttribute('aria-labelledby'));
        $this->assertStringNotContainsString('x-on:focus', $selected->ownerDocument->saveHTML($selected));
    }

    #[DataProvider('personnelSections')]
    public function test_selected_section_mounts_exactly_one_child_with_locked_employee_context(string $section, string $component, string $tab, bool $embedded): void
    {
        $this->actingAs($this->admin);
        $profile = Livewire::test(UserProfile::class, ['userId' => $this->employee->id, 'embedded' => $embedded])
            ->call('setProfileTab', $section)->assertSet('profileTab', $section)->assertSuccessful();
        $children = $this->personnelChildren($profile->html());
        $this->assertCount(1, $children);
        $this->assertSame($component, $children[0]['memo']['name']);
        $this->assertSame($this->employee->id, $children[0]['data']['profileUserId']);
        $this->assertTrue($children[0]['data']['embedded']);
        $this->assertSame($tab, $children[0]['data'][$component === 'operations.personnel-review' ? 'module' : 'tab']);
        $profile->call('setProfileTab', 'userDetails')->assertSet('profileTab', 'userDetails');
        $this->assertSame([], $this->personnelChildren($profile->html()));
        $this->assertDatabaseCount('file_pools', 0);
    }

    public static function personnelSections(): array
    {
        $sections = [
            'qualifications' => ['operations.personnel-review', 'qualifications'],
            'training' => ['operations.workforce-accounts', 'training'],
            'signatures' => ['operations.personnel-enhancements', 'documents'],
            'emergency' => ['operations.personnel-enhancements', 'emergency'],
            'workflows' => ['operations.personnel-enhancements', 'workflows'],
            'development' => ['operations.personnel-enhancements', 'development'],
            'sickness' => ['operations.personnel-enhancements', 'sickness'],
            'tasks' => ['operations.workforce-accounts', 'tasks'],
            'accounts' => ['operations.workforce-accounts', 'account'],
            'absences' => ['operations.workforce-accounts', 'absences'],
            'workModel' => ['operations.workforce-accounts', 'models'],
            'vacationPolicy' => ['operations.workforce-accounts', 'policies'],
            'ruleAssignments' => ['operations.workforce-accounts', 'rules'],
            'planReviews' => ['operations.workforce-accounts', 'checks'],
            'responsibilities' => ['operations.workforce-accounts', 'responsibilities'],
        ];
        $cases = [];
        foreach ($sections as $section => [$component, $tab]) {
            foreach ([false, true] as $embedded) {
                $cases[$section.($embedded ? ' embedded' : ' standalone')] = [$section, $component, $tab, $embedded];
            }
        }

        return $cases;
    }

    public function test_invalid_pending_inline_value_prevents_switch_and_remains_available_for_correction(): void
    {
        $this->actingAs($this->admin);
        $email = $this->employee->email;
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.email', 'not-an-email')
            ->call('setProfileTab', 'qualifications')
            ->assertHasErrors(['inlineValues.email'])
            ->assertSet('profileTab', 'userDetails')
            ->assertSet('inlineValues.email', 'not-an-email')
            ->assertSet('dirtyInlineFields', ['email']);
        $this->assertSame($email, $this->employee->fresh()->email);
    }

    public function test_valid_pending_inline_values_are_saved_before_switch_and_other_employee_unchanged(): void
    {
        Profile::create(['user_id' => $this->employee->id, 'phone' => 'old fixture phone']);
        $otherName = $this->outside->name;
        $this->actingAs($this->admin);
        Livewire::test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.name', 'Saved Profile Fixture')
            ->set('inlineValues.phone', '+49 000 111')
            ->call('setProfileTab', 'training')
            ->assertHasNoErrors()
            ->assertSet('profileTab', 'training')
            ->assertSet('dirtyInlineFields', [])
            ->assertDispatched('employee-profile-field-saved');
        $this->assertSame('Saved Profile Fixture', $this->employee->fresh()->name);
        $this->assertSame('+49 000 111', Profile::where('user_id', $this->employee->id)->value('phone'));
        $this->assertSame($otherName, $this->outside->fresh()->name);
    }

    private function personnelChildren(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $children = [];
        foreach ((new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = json_decode($element->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
            if (in_array($snapshot['memo']['name'], ['operations.personnel-review', 'operations.workforce-accounts', 'operations.personnel-enhancements'], true)) {
                $children[] = $snapshot;
            }
        }

        return $children;
    }
}
