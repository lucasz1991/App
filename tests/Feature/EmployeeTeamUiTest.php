<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees;
use App\Livewire\Admin\Employees\EmployeeTeamModal;
use App\Livewire\Admin\Employees\TeamRbacModal;
use App\Livewire\Admin\UserProfile;
use App\Models\Team;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeTeamUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Team $team;

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
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Bus::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Mitarbeiter', 'personal_team' => false, 'rbac_permissions' => ['operations.time.export' => true]]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true, 'name' => 'Team UI Fixture', 'current_team_id' => $this->team->id]);
        $this->employee->teams()->attach($this->team, ['role' => 'team_access']);
    }

    #[DataProvider('hosts')]
    public function test_native_profile_exposes_team_tab_and_existing_manual_tab_structure_only_to_authorized_editor(bool $embedded): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id, 'embedded' => $embedded])
            ->assertSuccessful()->assertViewHas('canManageEmployeeTeams', true)
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ($tabs['teams']['label'] ?? null) === 'Teams')
            ->assertDontSee('Teams & Rechte')->assertDontSee('data-team-rbac-livewire', false);
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-employee-team-profile]'), 'Do not eagerly render team details in the overview.');
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-employee-team-modal]'));
        $this->assertCount(0, $this->xpath($component->html())->query('//*[@data-team-rbac-livewire]'));

        $component->call('setProfileTab', 'teams')->assertSuccessful()->assertSet('profileTab', 'teams')
            ->assertSee('Teamzuordnung')->assertSee('Mitarbeiter')->assertSee('Teams zuweisen')->assertSee('Teams speichern')
            ->assertDontSee('Teams & Rechte')->assertDontSee('Team erstellen')
            ->assertDontSee('Freigegebene Zeitnachweise exportieren');
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-employee-team-profile]'));
        $this->assertCount(1, $xpath->query('//*[@role="tabpanel" and @id="employee-profile-'.$this->employee->id.'-panel-teams"]'));
        $this->assertCount(1, $xpath->query('//*[@role="tab" and @id="employee-profile-'.$this->employee->id.'-tab-teams" and @aria-selected="true"]'));
        $this->assertCount(0, $xpath->query('//*[@aria-label="Teamrechte"]'));
        $this->assertCount(0, $xpath->query('//*[@data-team-rbac-manager or @data-team-rbac-livewire or @data-team-rbac-permissions]'));
        $component->call('openTeamChange')->assertDispatched('open-employee-team-modal', ids: [$this->employee->id]);
        $this->assertSame($this->team->id, $this->employee->fresh()->current_team_id);
        $this->assertNoWrites();
    }

    public static function hosts(): array
    {
        return ['standalone' => [false], 'embedded' => [true]];
    }

    public function test_profile_team_panel_explains_memberships_but_does_not_display_a_permission_matrix_or_summary(): void
    {
        $extra = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Persönliche Mitgliedschaft', 'personal_team' => true, 'rbac_permissions' => []]);
        $this->employee->teams()->attach($extra, ['role' => 'team_access']);
        Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])->call('setProfileTab', 'teams')
            ->assertSee('Persönliche Mitgliedschaft')->assertSee('persönlich')->assertSee('Weitere Mitgliedschaften')
            ->assertDontSee('Freigegebene Zeitnachweise exportieren')->assertDontSee('Teams & Rechte')
            ->assertDontSee('Team erstellen');
        Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->admin->id])->call('setProfileTab', 'teams')
            ->assertSee('Teamzuordnung')->assertDontSee('globale Administratorrechte')
            ->assertDontSee('data-team-rbac-livewire', false);
        $this->assertDatabaseHas('team_user', ['team_id' => $extra->id, 'user_id' => $this->employee->id]);
        $this->assertNoWrites();
    }

    public function test_profile_refresh_after_team_update_preserves_unsaved_inline_values(): void
    {
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->set('inlineValues.name', 'Noch ungespeicherte Eingabe')->assertSet('dirtyInlineFields', ['name']);
        $next = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Weitere Planung', 'personal_team' => false, 'rbac_permissions' => []]);
        $this->employee->forceFill(['current_team_id' => $next->id])->save();
        $component->call('teamsUpdated', [$this->employee->id])->assertSuccessful()
            ->assertSet('inlineValues.name', 'Noch ungespeicherte Eingabe')->assertSet('dirtyInlineFields', ['name']);
        $this->assertSame('Team UI Fixture', $this->employee->fresh()->name);
        $this->assertNoWrites();
    }

    public function test_inactive_employee_profile_still_offers_the_same_team_module_to_an_authorized_administrator(): void
    {
        $this->employee->forceFill(['status' => false])->save();
        Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertViewHas('canManageEmployeeTeams', true)->call('setProfileTab', 'teams')
            ->assertSee('Teams zuweisen')->assertSee('Teams speichern')->assertDontSee('Teams & Rechte');
        $this->assertFalse($this->employee->fresh()->status);
        $this->assertNoWrites();
    }

    public function test_manager_without_role_management_keeps_profile_access_but_not_team_tools(): void
    {
        $manager = $this->manager(['employees.view' => true, 'employees.create' => true]);
        $component = Livewire::actingAs($manager)->test(UserProfile::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertViewHas('canManageEmployeeTeams', false)
            ->assertViewHas('employeeProfileTabs', fn (array $tabs): bool => ! isset($tabs['teams']))
            ->assertDontSee('data-employee-team-modal', false)->assertDontSee('data-team-rbac-livewire', false);
        $component->call('setProfileTab', 'teams')->assertForbidden();
        Livewire::actingAs($manager)->test(UserProfile::class, ['userId' => $this->employee->id])->call('openTeamChange')->assertForbidden();
        $this->assertNoWrites();
    }

    public function test_profile_has_no_callable_rights_opening_or_rights_update_entry_point(): void
    {
        $this->assertFalse(method_exists(UserProfile::class, 'openTeamRights'));
        $this->assertFalse(method_exists(UserProfile::class, 'teamRightsUpdated'));
        $component = Livewire::actingAs($this->admin)->test(UserProfile::class, ['userId' => $this->employee->id]);
        $this->assertNoWrites();
        $this->expectException(MethodNotFoundException::class);
        $component->call('openTeamRights');
    }

    public function test_directory_keeps_the_existing_rights_modal_and_native_rights_entry_separate_from_profiles(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Employees::class)->assertSuccessful()
            ->assertSee(__('app.teams_permissions'));
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-team-rbac-livewire="modal"]'));
        $this->assertCount(1, $xpath->query('//*[@data-employee-team-modal]'));
        $component->call('openTeamRbacModal')->assertDispatched('open-team-rbac-modal');

        $rights = Livewire::actingAs($this->admin)->test(TeamRbacModal::class)->call('open')
            ->assertSet('showModal', true)->assertSee('Team erstellen');
        $this->assertCount(1, $this->xpath($rights->html())->query('//*[@data-team-rbac-manager]'));
        $this->assertCount(1, $this->xpath($rights->html())->query('//*[@data-team-rbac-permissions]'));
        $this->assertNoWrites();
    }

    public function test_bulk_action_validates_current_selection_and_dispatches_snapshot_owner_not_direct_changes(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $this->team->id]);
        $other->teams()->attach($this->team, ['role' => 'team_access']);
        $component = Livewire::actingAs($this->admin)->test(Employees::class);
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-employee-team-modal]'));
        $component->set('selectedEmployees', [$this->employee->id, $other->id])->assertSee('Team wechseln')
            ->call('openTeamChangeSelected')->assertHasNoErrors()
            ->assertDispatched('open-employee-team-modal', ids: [$this->employee->id, $other->id]);
        $this->assertSame($this->team->id, $this->employee->fresh()->current_team_id);
        $this->assertSame($this->team->id, $other->fresh()->current_team_id);
        $component->set('selectedEmployees', [99999])->call('openTeamChangeSelected')->assertHasErrors('employeeIds');
        $component->set('selectedEmployees', [])->call('openTeamChangeSelected')->assertHasErrors('employeeIds');
        $this->assertNoWrites();
    }

    public function test_bulk_controls_hidden_and_server_denied_without_roles_manage(): void
    {
        $manager = $this->manager(['employees.view' => true, 'employees.create' => true]);
        Livewire::actingAs($manager)->test(Employees::class)->set('selectedEmployees', [$this->employee->id])
            ->assertDontSee('Team wechseln')->call('openTeamChangeSelected')->assertForbidden();
        $this->assertNoWrites();
    }

    public function test_modal_discloses_exact_primary_replacement_and_native_save_cancel_contract(): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamModal::class)->call('open', [$this->employee->id])
            ->assertSee('Primäres Team für 1 Mitarbeiter ändern.')->assertSee('Team UI Fixture')
            ->assertSee('Weitere und persönliche Mitgliedschaften bleiben bestehen.')
            ->assertSee('Globale Benutzerrolle und Kontostatus bleiben unverändert.');
        $xpath = $this->xpath($component->html());
        $this->assertCount(1, $xpath->query('//*[@data-employee-team-modal]'));
        $this->assertCount(1, $xpath->query('//button[@*[name()="wire:click"]="save"]'));
        $this->assertCount(1, $xpath->query('//button[@*[name()="wire:click"]="close"]'));
        $this->assertCount(1, $xpath->query('//*[@aria-label="Ausgewählte Mitarbeiter"]'));
        $component->call('close')->assertSet('showModal', false);
        $this->assertNoWrites();
    }

    public function test_existing_team_rights_modal_can_preselect_only_a_real_organizational_team(): void
    {
        Livewire::actingAs($this->admin)->test(TeamRbacModal::class)->call('open', $this->team->id)
            ->assertSet('selectedTeamId', $this->team->id)->assertSet('showModal', true)->assertSee('Team erstellen');
        $personal = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Privat', 'personal_team' => true]);
        Livewire::actingAs($this->admin)->test(TeamRbacModal::class)->call('open', $personal->id)->assertNotFound();
        $this->assertNoWrites();
    }

    #[DataProvider('privilegedManagerMethods')]
    public function test_direct_rbac_public_actions_remain_forbidden_without_role_permission(string $method, array $arguments): void
    {
        Livewire::actingAs($this->employee)->test(TeamRbacModal::class)->call($method, ...$arguments)->assertForbidden();
        $this->assertNoWrites();
    }

    public static function privilegedManagerMethods(): array
    {
        return ['select' => ['setTeam', [1]], 'all-on' => ['setSelectedTeamToTrue', []], 'all-off' => ['setSelectedTeamToFalse', []], 'save' => ['save', []]];
    }

    private function manager(array $permissions): User
    {
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => $permissions]);
        $manager = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $team->id]);
        $manager->teams()->attach($team, ['role' => 'team_access']);

        return $manager;
    }

    private function assertNoWrites(): void
    {
        $this->assertSame(0, DB::table('activity_log')->whereIn('description', ['employee_team_changed', 'employee_team_created', 'employee_team_memberships_updated'])->count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
