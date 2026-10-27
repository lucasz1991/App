<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees\EmployeeTeamModal;
use App\Livewire\Admin\Employees\TeamRbacModal;
use App\Models\Team;
use App\Models\User;
use App\Services\Personnel\EmployeeTeamService;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeTeamManagementTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Team $old;

    private Team $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Bus::fake();

        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->old = $this->team('Mitarbeiter');
        $this->destination = $this->team('Disposition Nord', ['operations.manage' => true]);
        $this->employee = $this->employee();
        $this->actingAs($this->admin);
    }

    public function test_primary_team_change_retains_other_memberships_and_all_non_team_account_data(): void
    {
        $extra = $this->team('Zusatzteam');
        $personal = $this->team('Persönlich', [], true, $this->employee);
        $this->employee->teams()->attach($extra, ['role' => 'existing-extra-role']);
        $this->employee->teams()->attach($personal, ['role' => 'existing-personal-role']);
        $before = $this->nonTeamAccount($this->employee);
        $destinationBefore = $this->destination->fresh()->getAttributes();
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);

        $prepared = $this->service()->prepare([$this->employee->id]);
        $this->assertSame([$this->employee->id], $prepared['ids']);
        $this->assertCount(1, $prepared['employees']);
        $this->assertSame(1, $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));

        $this->assertSame($this->destination->id, $this->employee->fresh()->current_team_id);
        $this->assertSame($before, $this->nonTeamAccount($this->employee));
        $this->assertSame($destinationBefore, $this->destination->fresh()->getAttributes());
        $this->assertDatabaseMissing('team_user', ['user_id' => $this->employee->id, 'team_id' => $this->old->id]);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $this->destination->id, 'role' => 'team_access']);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $extra->id, 'role' => 'existing-extra-role']);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $personal->id, 'role' => 'existing-personal-role']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'employee-team', 'description' => 'employee_team_changed', 'subject_id' => $this->employee->id, 'causer_id' => $this->admin->id]);
        Event::assertDispatched(TeamMemberAdded::class);
        Event::assertDispatched(TeamMemberRemoved::class);
        $this->assertNoTransports();
    }

    public function test_bulk_is_unique_atomic_and_noop_does_not_add_audits_or_memberships(): void
    {
        $second = $this->employee();
        $prepared = $this->service()->prepare([(string) $second->id, $this->employee->id]);
        $this->assertCount(2, $prepared['ids']);
        $this->assertSame(2, $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame($this->destination->id, $second->fresh()->current_team_id);
        $this->assertSame($this->destination->id, $this->employee->fresh()->current_team_id);
        $audits = DB::table('activity_log')->where('description', 'employee_team_changed')->count();
        $pivots = DB::table('team_user')->orderBy('id')->get()->toArray();
        $prepared = $this->service()->prepare([$second->id, $this->employee->id]);
        $this->assertSame(0, $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame(2, $audits);
        $this->assertSame($audits, DB::table('activity_log')->where('description', 'employee_team_changed')->count());
        $this->assertEquals($pivots, DB::table('team_user')->orderBy('id')->get()->toArray());
    }

    #[DataProvider('invalidSelections')]
    public function test_untrusted_selection_is_strictly_bounded_and_rejected_without_changes(array $ids): void
    {
        $before = $this->databaseState();
        $this->assertValidation('employeeIds', fn () => $this->service()->prepare($ids));
        $this->assertSame($before, $this->databaseState());
    }

    public static function invalidSelections(): array
    {
        return [
            'empty' => [[]], 'too-many' => [range(1, 101)], 'zero' => [[0]], 'negative' => [[-1]],
            'float' => [[2.3]], 'boolean' => [[true]], 'nested' => [[[2]]], 'suffix' => [['2suffix']],
            'leading-zero' => [['02']], 'whitespace' => [[' 2']], 'scientific' => [['2e1']], 'missing' => [[99999]],
            'duplicate' => [[2, '2']],
            'associative' => [['target' => 2]],
        ];
    }

    public function test_non_employee_and_missing_target_abort_the_entire_selection(): void
    {
        foreach (['guest', 'editor'] as $role) {
            $other = User::factory()->create(['role' => $role]);
            $before = $this->databaseState();
            $this->assertForbidden(fn () => $this->service()->prepare([$this->employee->id, $other->id]));
            $this->assertSame($before, $this->databaseState());
        }
    }

    public function test_personal_or_missing_destination_does_not_change_any_selected_account(): void
    {
        $personal = $this->team('Privat', [], true);
        foreach ([$personal->id, 99999] as $id) {
            $prepared = $this->service()->prepare([$this->employee->id]);
            $before = $this->databaseState();
            $this->assertValidation('teamId', fn () => $this->service()->change($prepared['ids'], $id, $prepared['snapshots']));
            $this->assertSame($before, $this->databaseState());
        }
        $this->assertNotContains($personal->id, $this->service()->availableTeams()->pluck('id')->all());
    }

    public function test_stale_primary_or_membership_snapshot_rejects_bulk_before_any_write(): void
    {
        $second = $this->employee();
        $prepared = $this->service()->prepare([$this->employee->id, $second->id]);
        $second->forceFill(['current_team_id' => $this->destination->id])->save();
        $before = $this->databaseState();
        $this->assertValidation('teamId', fn () => $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame($before, $this->databaseState());

        $prepared = $this->service()->prepare([$this->employee->id]);
        $this->employee->teams()->attach($this->team('Parallel hinzugefügt'), ['role' => 'team_access']);
        $before = $this->databaseState();
        $this->assertValidation('teamId', fn () => $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame($before, $this->databaseState());
    }

    public function test_missing_or_forged_snapshot_cannot_overwrite_a_target(): void
    {
        foreach ([[], [$this->employee->id => 'forged']] as $snapshots) {
            $before = $this->databaseState();
            $this->assertValidation('teamId', fn () => $this->service()->change([$this->employee->id], $this->destination->id, $snapshots));
            $this->assertSame($before, $this->databaseState());
        }
    }

    public function test_primary_team_owner_cannot_be_silently_detached_or_partially_moved(): void
    {
        $owner = $this->employee();
        $owned = $this->team('Gehört Ziel', [], false, $owner);
        $owner->teams()->attach($owned, ['role' => 'team_access']);
        $owner->forceFill(['current_team_id' => $owned->id])->save();
        $prepared = $this->service()->prepare([$this->employee->id, $owner->id]);
        $before = $this->databaseState();
        $this->assertValidation('teamId', fn () => $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame($before, $this->databaseState());
    }

    #[DataProvider('missingPermissions')]
    public function test_each_native_permission_is_required_fresh_for_a_delegated_manager(string $missing): void
    {
        $actor = $this->manager(array_diff_key($this->managerPermissions(), [$missing => true]));
        $this->actingAs($actor);
        $this->assertFalse($this->service()->canManage($actor));
        $before = $this->databaseState();
        $this->assertForbidden(fn () => $this->service()->prepare([$this->employee->id]));
        $this->assertSame($before, $this->databaseState());
    }

    public static function missingPermissions(): array
    {
        return ['view' => ['employees.view'], 'edit' => ['employees.create'], 'roles' => ['roles.manage']];
    }

    public function test_inactive_and_non_management_actors_cannot_open_or_commit_team_changes(): void
    {
        $actor = $this->manager();
        $actor->forceFill(['status' => false])->save();
        $this->actingAs($actor);
        $this->assertFalse($this->service()->canManage($actor));
        $this->assertForbidden(fn () => $this->service()->prepare([$this->employee->id]));
        $actor = $this->employee();
        $this->actingAs($actor);
        $this->assertFalse($this->service()->canManage($actor));
        $this->assertForbidden(fn () => $this->service()->prepare([$this->employee->id]));
    }

    public function test_revoked_permission_is_checked_from_database_not_a_cached_actor_relation(): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        $prepared = $this->service()->prepare([$this->employee->id]);
        $actor->currentTeam->update(['rbac_permissions' => ['employees.view' => true, 'employees.create' => true]]);
        $before = $this->databaseState();
        $this->assertFalse($this->service()->canManage($actor));
        $this->assertForbidden(fn () => $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame($before, $this->databaseState());
    }

    public function test_inactive_global_admin_and_fully_privileged_non_management_actor_are_denied(): void
    {
        $this->admin->forceFill(['status' => false])->save();
        $this->assertFalse($this->service()->canManage($this->admin));
        $this->assertForbidden(fn () => $this->service()->prepare([$this->employee->id]));
        $this->assertForbidden(fn () => $this->service()->create('Inactive admin team'));

        $actor = $this->employee();
        $ordinary = $this->team('Kein Verwaltungsteam', $this->managerPermissions());
        $actor->teams()->attach($ordinary, ['role' => 'team_access']);
        $actor->forceFill(['current_team_id' => $ordinary->id])->save();
        $this->actingAs($actor);
        $this->assertFalse($this->service()->canManage($actor));
        $this->assertForbidden(fn () => $this->service()->prepare([$this->employee->id]));
        $this->assertForbidden(fn () => $this->service()->create('No management team'));
    }

    public function test_delegated_self_downgrade_rejects_entire_bulk_without_losing_actor_access(): void
    {
        $actor = $this->manager();
        $safe = $this->team('Mitarbeiter ohne Verwaltung');
        $this->actingAs($actor);
        $prepared = $this->service()->prepare([$this->employee->id, $actor->id]);
        $before = $this->databaseState();
        $this->assertValidation('teamId', fn () => $this->service()->change($prepared['ids'], $safe->id, $prepared['snapshots']));
        $this->assertSame($before, $this->databaseState());
        $this->assertTrue($this->service()->canManage($actor));
    }

    public function test_destination_rights_are_checked_again_after_preview_before_any_target_changes(): void
    {
        $actor = $this->manager();
        $safe = $this->team('Vorher freigegeben', ['employees.view' => true]);
        $this->actingAs($actor);
        $prepared = $this->service()->prepare([$this->employee->id]);
        $safe->update(['rbac_permissions' => ['employees.view' => true, 'devices.providers.manage' => true]]);
        $before = $this->databaseState();
        $this->assertForbidden(fn () => $this->service()->change($prepared['ids'], $safe->id, $prepared['snapshots']));
        $this->assertSame($before, $this->databaseState());
        $this->assertNotContains($safe->id, $this->service()->availableTeams()->pluck('id')->all());
    }

    public function test_native_member_events_run_after_commit_and_never_escape_outer_rollback(): void
    {
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        $prepared = $this->service()->prepare([$this->employee->id]);
        $before = $this->databaseState();
        DB::beginTransaction();
        try {
            $this->assertSame(1, $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
            Event::assertNotDispatched(TeamMemberAdded::class);
            Event::assertNotDispatched(TeamMemberRemoved::class);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->databaseState());
        Event::assertNotDispatched(TeamMemberAdded::class);
        Event::assertNotDispatched(TeamMemberRemoved::class);

        $prepared = $this->service()->prepare([$this->employee->id]);
        DB::beginTransaction();
        $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']);
        Event::assertNotDispatched(TeamMemberAdded::class);
        DB::commit();
        Event::assertDispatched(TeamMemberAdded::class);
        Event::assertDispatched(TeamMemberRemoved::class);
    }

    public function test_delegated_actor_cannot_move_admin_accounts_or_grant_more_rights_than_they_hold(): void
    {
        $actor = $this->manager();
        $adminTarget = $this->employee('admin');
        $this->actingAs($actor);
        $before = $this->databaseState();
        $this->assertForbidden(fn () => $this->service()->prepare([$this->employee->id, $adminTarget->id]));
        $this->assertSame($before, $this->databaseState());

        $prepared = $this->service()->prepare([$this->employee->id]);
        $this->assertForbidden(fn () => $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame($before, $this->databaseState());
        foreach (['Administratoren', 'Administrator', 'Administration'] as $name) {
            $sensitive = $this->team($name);
            $prepared = $this->service()->prepare([$this->employee->id]);
            $before = $this->databaseState();
            $this->assertForbidden(fn () => $this->service()->change($prepared['ids'], $sensitive->id, $prepared['snapshots']));
            $this->assertSame($before, $this->databaseState());
        }
    }

    public function test_authorized_delegated_change_and_global_admin_target_preserve_global_roles(): void
    {
        $actor = $this->manager();
        $safe = $this->team('Ohne zusätzliche Rechte', ['employees.view' => true]);
        $this->actingAs($actor);
        $prepared = $this->service()->prepare([$this->employee->id]);
        $this->assertSame(1, $this->service()->change($prepared['ids'], $safe->id, $prepared['snapshots']));
        $this->assertSame('staff', $this->employee->fresh()->role);
        $this->actingAs($this->admin);
        $target = $this->employee('admin');
        $prepared = $this->service()->prepare([$target->id]);
        $this->assertSame(1, $this->service()->change($prepared['ids'], $this->destination->id, $prepared['snapshots']));
        $this->assertSame('admin', $target->fresh()->role);
        $this->assertNoTransports();
    }

    public function test_create_uses_native_team_contract_with_all_permissions_off_and_keeps_actor_context(): void
    {
        $this->admin->forceFill(['current_team_id' => $this->old->id])->save();
        $before = $this->nonTeamAccount($this->admin);
        Event::fake([AddingTeam::class, TeamCreated::class]);
        $created = $this->service()->create('  Einsatzteam West  ');
        $this->assertSame('Einsatzteam West', $created->name);
        $this->assertFalse($created->personal_team);
        $this->assertSame($this->admin->id, $created->user_id);
        $this->assertSame(RbacCatalog::defaultTeamPermissions(), $created->rbac_permissions);
        $this->assertSame($this->old->id, $this->admin->fresh()->current_team_id);
        $this->assertSame($before, $this->nonTeamAccount($this->admin));
        Event::assertDispatched(AddingTeam::class);
        Event::assertDispatched(TeamCreated::class);
        $this->assertDatabaseHas('activity_log', ['description' => 'employee_team_created', 'subject_id' => $created->id]);
        $this->assertNoTransports();
    }

    #[DataProvider('invalidNames')]
    public function test_team_creation_name_validation_does_not_write_or_switch_context(string $name): void
    {
        $before = $this->databaseState();
        $this->assertValidation('newTeamName', fn () => $this->service()->create($name));
        $this->assertSame($before, $this->databaseState());
    }

    #[DataProvider('reservedNames')]
    public function test_delegated_team_creator_cannot_gain_dashboard_privileges_through_reserved_team_names(string $name): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        $before = $this->databaseState();
        $this->assertValidation('newTeamName', fn () => $this->service()->create($name));
        $this->assertSame($before, $this->databaseState());
        $this->assertFalse($actor->fresh()->canViewSystemDashboard());
    }

    public static function reservedNames(): array
    {
        return ['technical' => ['Administratoren'], 'singular' => ['Administrator'], 'legacy' => ['Administration'], 'case-space' => ['  aDmInIsTrAtOrEn  ']];
    }

    public function test_delegated_create_requires_roles_manage_but_does_not_require_member_edit_permission(): void
    {
        $actor = $this->manager(['roles.manage' => true]);
        $this->actingAs($actor);
        $created = $this->service()->create('Ordentliches neues Team');
        $this->assertSame(RbacCatalog::defaultTeamPermissions(), $created->rbac_permissions);
        $this->assertSame($actor->current_team_id, $actor->fresh()->current_team_id);
        $actor->currentTeam->update(['rbac_permissions' => []]);
        $before = $this->databaseState();
        $this->assertForbidden(fn () => $this->service()->create('Nicht berechtigt'));
        $this->assertSame($before, $this->databaseState());
    }

    public function test_team_creation_detects_trimmed_unicode_case_equivalent_existing_name(): void
    {
        $this->team('Änderungsteam');
        $before = $this->databaseState();
        $this->assertValidation('newTeamName', fn () => $this->service()->create('  änderungsteam '));
        $this->assertSame($before, $this->databaseState());
    }

    public static function invalidNames(): array
    {
        return ['empty' => [''], 'blank' => ['   '], 'long' => [str_repeat('x', 256)], 'duplicate' => ['mitarbeiter']];
    }

    public function test_modal_locks_prepared_targets_and_revalidates_before_confirmed_save(): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamModal::class)
            ->call('open', [$this->employee->id])->assertSet('showModal', true)
            ->assertSet('employeeIds', [$this->employee->id]);
        $component->set('teamId', $this->destination->id)->call('save')->assertHasNoErrors()
            ->assertSet('showModal', false)->assertDispatched('employee-teams-updated')
            ->assertDispatched('employeeSaved')->assertDispatched('refresh-navigation-menu');
        $this->assertSame($this->destination->id, $this->employee->fresh()->current_team_id);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('employeeIds', [$this->admin->id]);
    }

    public function test_modal_close_is_read_only_and_stale_save_keeps_dialog_open(): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamModal::class)->call('open', [$this->employee->id]);
        $before = $this->databaseState();
        $component->call('close')->assertSet('showModal', false);
        $this->assertSame($before, $this->databaseState());
        $component->call('open', [$this->employee->id])->set('teamId', $this->destination->id);
        $this->employee->forceFill(['current_team_id' => $this->destination->id])->save();
        $before = $this->databaseState();
        $component->call('save')->assertHasErrors('teamId')->assertSet('showModal', true);
        $this->assertSame($before, $this->databaseState());
    }

    public function test_team_creation_in_existing_rbac_manager_preserves_other_unsaved_rights(): void
    {
        $key = str_replace('.', '__dot__', 'employees.view');
        $beforeActor = $this->admin->fresh()->getAttributes();
        $beforeTeams = Team::all()->keyBy('id')->map->getAttributes()->all();
        $component = Livewire::actingAs($this->admin)->test(TeamRbacModal::class)->call('open')
            ->set('matrix.'.$this->old->id.'.'.$key, true)->set('newTeamName', 'Neues Team Süd')->call('createTeam')->assertHasNoErrors();
        $created = Team::where('name', 'Neues Team Süd')->sole();
        $component->assertSet('selectedTeamId', $created->id)->assertSet('matrix.'.$this->old->id.'.'.$key, true);
        $this->assertSame(RbacCatalog::defaultTeamPermissions(), $created->rbac_permissions);
        $this->assertSame($beforeActor, $this->admin->fresh()->getAttributes());
        foreach ($beforeTeams as $id => $attributes) {
            $this->assertSame($attributes, Team::findOrFail($id)->getAttributes());
        }
    }

    public function test_rbac_creation_requires_fresh_role_permission_and_closed_unauthorized_render_does_not_leak(): void
    {
        $actor = $this->employee();
        $component = Livewire::actingAs($actor)->test(TeamRbacModal::class)->assertSuccessful();
        $component->assertDontSee('Disposition Nord');
        $before = $this->databaseState();
        $component->set('newTeamName', 'Forbidden')->call('createTeam')->assertForbidden();
        $this->assertSame($before, $this->databaseState());
        $this->assertNoTransports();
    }

    private function service(): EmployeeTeamService
    {
        return app(EmployeeTeamService::class);
    }

    private function employee(string $role = 'staff'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => true, 'current_team_id' => $this->old->id]);
        $user->teams()->attach($this->old, ['role' => 'team_access']);

        return $user->fresh();
    }

    private function team(string $name, array $permissions = [], bool $personal = false, ?User $owner = null): Team
    {
        return Team::forceCreate(['name' => $name, 'user_id' => ($owner ?? $this->admin)->id, 'personal_team' => $personal, 'rbac_permissions' => $permissions]);
    }

    private function manager(?array $permissions = null): User
    {
        $user = $this->employee();
        $team = $this->team('Verwaltung', $permissions ?? $this->managerPermissions());
        $user->teams()->attach($team, ['role' => 'team_access']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    private function managerPermissions(): array
    {
        return ['employees.view' => true, 'employees.create' => true, 'roles.manage' => true];
    }

    private function nonTeamAccount(User $user): array
    {
        return array_diff_key($user->fresh()->getAttributes(), array_flip(['current_team_id', 'updated_at']));
    }

    private function databaseState(): array
    {
        return collect(['users', 'teams', 'team_user', 'activity_log'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function assertValidation(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function assertForbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected authorization denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        }
    }

    private function assertNoTransports(): void
    {
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
