<?php

namespace Tests\Feature;

use App\Jobs\RefreshOutlookAddinUserSnapshot;
use App\Livewire\Admin\Employees\EmployeeFormModal;
use App\Livewire\Admin\Employees\EmployeeTeams;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Personnel\EmployeeTeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeTeamMembershipTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Team $old;

    private Team $next;

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
        $this->next = $this->team('Einsatzteam Nord');
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $this->old->id]);
        $this->employee->teams()->attach($this->old, ['role' => 'existing-primary-role']);
        $this->actingAs($this->admin);
    }

    public function test_multiple_memberships_can_be_added_and_removed_without_changing_global_account_or_fixed_memberships(): void
    {
        $removed = $this->team('Entfernen');
        $retained = $this->team('Behalten');
        $owned = $this->team('Eigenes Team', owner: $this->employee);
        $personal = $this->team('Persönliches Team', personal: true, owner: $this->employee);
        foreach ([$removed, $retained, $owned, $personal] as $team) {
            $this->employee->teams()->attach($team, ['role' => 'existing-'.$team->id]);
        }
        UserProfile::create(['user_id' => $this->employee->id, 'city' => 'Hamburg', 'position' => 'Wagenmeister']);
        $beforeAccount = $this->nonTeamAccount();
        $beforeProfile = $this->employee->profile->getAttributes();
        $beforeTeams = Team::orderBy('id')->get()->map->getAttributes()->all();
        $snapshot = $this->snapshot();

        $this->assertTrue($this->service()->updateMemberships($this->employee->id, [(string) $this->old->id, $retained->id, $this->next->id], $this->next->id, $snapshot));

        $this->assertSame($this->next->id, $this->employee->fresh()->current_team_id);
        $this->assertSame($beforeAccount, $this->nonTeamAccount());
        $this->assertSame($beforeProfile, $this->employee->profile->fresh()->getAttributes());
        $this->assertSame($beforeTeams, Team::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseMissing('team_user', ['user_id' => $this->employee->id, 'team_id' => $removed->id]);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $this->old->id, 'role' => 'existing-primary-role']);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $this->next->id, 'role' => 'team_access']);
        foreach ([$retained, $owned, $personal] as $team) {
            $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $team->id, 'role' => 'existing-'.$team->id]);
        }
        $audit = DB::table('activity_log')->where('log_name', 'employee-team')->sole();
        $this->assertSame('employee_team_memberships_updated', $audit->description);
        $this->assertSame($this->employee->id, $audit->subject_id);
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame([
            'added_team_ids' => [$this->next->id], 'removed_team_ids' => [$removed->id],
            'previous_team_id' => $this->old->id, 'primary_team_id' => $this->next->id,
        ], json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR));
        $this->assertNoTransports();
    }

    public function test_removing_the_current_team_requires_an_explicit_selected_replacement(): void
    {
        $snapshot = $this->snapshot();
        $before = $this->state();
        $this->assertValidation('primaryTeamId', fn () => $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->old->id, $snapshot));
        $this->assertSame($before, $this->state());
        $this->assertTrue($this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot));
        $this->assertDatabaseMissing('team_user', ['user_id' => $this->employee->id, 'team_id' => $this->old->id]);
        $this->assertSame($this->next->id, $this->employee->fresh()->current_team_id);
    }

    #[DataProvider('fixedMembershipTypes')]
    public function test_fixed_personal_or_owned_teams_are_retained_and_can_be_explicit_primary(bool $personal): void
    {
        $fixed = $this->team('Festes Team', personal: $personal, owner: $this->employee);
        $this->employee->teams()->attach($fixed, ['role' => 'fixed-role']);
        $this->assertTrue($this->service()->updateMemberships($this->employee->id, [], $fixed->id, $this->snapshot()));
        $this->assertSame($fixed->id, $this->employee->fresh()->current_team_id);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $fixed->id, 'role' => 'fixed-role']);
        $this->assertSame($this->employee->id, $fixed->fresh()->user_id);
    }

    public static function fixedMembershipTypes(): array
    {
        return ['personal' => [true], 'owned-organizational' => [false]];
    }

    public function test_owned_team_without_a_redundant_pivot_remains_owned_and_can_be_primary(): void
    {
        $owned = $this->team('Nur Eigentum', owner: $this->employee);
        $this->assertTrue($this->service()->updateMemberships($this->employee->id, [], $owned->id, $this->snapshot()));
        $this->assertSame($owned->id, $this->employee->fresh()->current_team_id);
        $this->assertTrue($this->employee->fresh()->ownsTeam($owned));
        $this->assertSame(0, DB::table('team_user')->where('user_id', $this->employee->id)->count());
    }

    public function test_noop_preserves_pivot_roles_timestamps_and_emits_no_domain_audit_or_events(): void
    {
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        $snapshot = $this->snapshot();
        $before = $this->state();
        $this->assertFalse($this->service()->updateMemberships($this->employee->id, [(string) $this->old->id], $this->old->id, $snapshot));
        $this->assertSame($before, $this->state());
        Event::assertNotDispatched(TeamMemberAdded::class);
        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    #[DataProvider('invalidTeamSelections')]
    public function test_untrusted_team_list_is_strict_and_cannot_modify_any_account_or_membership(array $ids): void
    {
        $snapshot = $this->snapshot();
        $before = $this->state();
        $this->assertValidation('teamIds', fn () => $this->service()->updateMemberships($this->employee->id, $ids, $this->old->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    public static function invalidTeamSelections(): array
    {
        return [
            'duplicate' => [[1, '1']], 'zero' => [[0]], 'negative' => [[-1]], 'float' => [[1.5]],
            'boolean' => [[true]], 'nested' => [[[1]]], 'suffix' => [['1x']], 'leading-zero' => [['01']],
            'whitespace' => [[' 1']], 'missing' => [[999999]], 'associative' => [['team' => 1]], 'too-many' => [range(1, 101)],
        ];
    }

    #[DataProvider('fixedMembershipTypes')]
    public function test_fixed_team_ids_cannot_be_injected_as_editable_memberships(bool $personal): void
    {
        $fixed = $this->team('Fixiert', personal: $personal, owner: $this->employee);
        $this->employee->teams()->attach($fixed);
        $snapshot = $this->snapshot();
        $before = $this->state();
        $this->assertValidation('teamIds', fn () => $this->service()->updateMemberships($this->employee->id, [$this->old->id, $fixed->id], $this->old->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    public function test_foreign_personal_team_cannot_be_assigned_or_activated(): void
    {
        $foreign = $this->team('Fremdes persönliches Team', personal: true);
        $snapshot = $this->snapshot();
        $before = $this->state();
        $this->assertValidation('teamIds', fn () => $this->service()->updateMemberships($this->employee->id, [$this->old->id, $foreign->id], $this->old->id, $snapshot));
        $this->assertValidation('primaryTeamId', fn () => $this->service()->updateMemberships($this->employee->id, [$this->old->id], $foreign->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    public function test_empty_membership_list_without_any_fixed_primary_is_rejected(): void
    {
        $snapshot = $this->snapshot();
        $before = $this->state();
        $this->assertValidation('primaryTeamId', fn () => $this->service()->updateMemberships($this->employee->id, [], $this->old->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('staleChanges')]
    public function test_concurrent_membership_account_or_team_metadata_changes_make_the_snapshot_stale(string $change): void
    {
        $snapshot = $this->snapshot();
        match ($change) {
            'primary' => $this->employee->forceFill(['current_team_id' => $this->next->id])->save(),
            'pivot-added' => $this->employee->teams()->attach($this->next),
            'pivot-role' => $this->employee->teams()->updateExistingPivot($this->old->id, ['role' => 'changed']),
            'team-name' => $this->old->update(['name' => 'Parallel geändert']),
            'team-rights' => $this->old->update(['rbac_permissions' => ['roles.manage' => true]]),
            'owner' => $this->old->forceFill(['user_id' => $this->employee->id])->save(),
            'role' => $this->employee->forceFill(['role' => 'admin'])->save(),
        };
        $before = $this->state();
        $this->assertValidation('snapshot', fn () => $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    public static function staleChanges(): array
    {
        return array_combine($names = ['primary', 'pivot-added', 'pivot-role', 'team-name', 'team-rights', 'owner', 'role'], array_map(fn ($name) => [$name], $names));
    }

    public function test_forged_snapshot_and_snapshot_from_another_employee_cannot_authorize_a_write(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        foreach (['', 'forged', $this->snapshot($other)] as $snapshot) {
            $before = $this->state();
            $this->assertValidation('snapshot', fn () => $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot));
            $this->assertSame($before, $this->state());
        }
    }

    #[DataProvider('requiredPermissions')]
    public function test_each_management_permission_is_rechecked_fresh_at_membership_save(string $permission): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        $snapshot = $this->snapshot();
        $actor->currentTeam->update(['rbac_permissions' => array_diff_key($this->managerPermissions(), [$permission => true])]);
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    public static function requiredPermissions(): array
    {
        return ['view' => ['employees.view'], 'edit' => ['employees.create'], 'rights' => ['roles.manage']];
    }

    public function test_inactive_admin_and_manager_who_lost_management_context_are_denied_fresh(): void
    {
        $snapshot = $this->snapshot();
        $this->admin->forceFill(['status' => false])->save();
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot));
        $this->assertSame($before, $this->state());
        $actor = $this->manager();
        $this->actingAs($actor);
        $snapshot = $this->snapshot();
        $actor->currentTeam->update(['name' => 'Keine Verwaltung mehr']);
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('protectedTargetRoles')]
    public function test_delegated_manager_cannot_use_membership_save_to_touch_nonstaff_or_admin_accounts(string $role): void
    {
        $target = User::factory()->create(['role' => $role, 'status' => true, 'current_team_id' => $this->old->id]);
        $snapshot = in_array($role, ['admin', 'superadmin'], true) ? $this->snapshot($target) : 'forged';
        $this->actingAs($this->manager());
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->updateMemberships($target->id, [$this->next->id], $this->next->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    public static function protectedTargetRoles(): array
    {
        return ['guest' => ['guest'], 'editor' => ['editor'], 'admin' => ['admin'], 'superadmin' => ['superadmin']];
    }

    public function test_delegated_manager_can_retain_or_remove_inaccessible_existing_membership_but_cannot_add_or_activate_it(): void
    {
        $strong = $this->team('Mehr Rechte', ['devices.providers.manage' => true]);
        $reserved = $this->team('Administratoren');
        $actor = $this->manager();
        $this->actingAs($actor);
        foreach ([$strong, $reserved] as $team) {
            $before = $this->state();
            $snapshot = $this->snapshot();
            $this->assertForbidden(fn () => $this->service()->updateMemberships($this->employee->id, [$this->old->id, $team->id], $this->old->id, $snapshot));
            $this->assertSame($before, $this->state());
        }
        $this->employee->teams()->attach($strong, ['role' => 'legacy-rights']);
        $snapshot = $this->snapshot();
        $before = $this->state();
        $this->assertFalse($this->service()->updateMemberships($this->employee->id, [$this->old->id, $strong->id], $this->old->id, $snapshot));
        $this->assertSame($before, $this->state());
        $this->assertForbidden(fn () => $this->service()->updateMemberships($this->employee->id, [$this->old->id, $strong->id], $strong->id, $snapshot));
        $this->assertSame($before, $this->state());
        $this->assertTrue($this->service()->updateMemberships($this->employee->id, [$this->old->id], $this->old->id, $snapshot));
        $this->assertDatabaseMissing('team_user', ['user_id' => $this->employee->id, 'team_id' => $strong->id]);
    }

    public function test_delegated_self_edit_cannot_remove_the_last_management_fallback_or_required_rights(): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        $snapshot = $this->snapshot($actor);
        $before = $this->state();
        $this->assertValidation('primaryTeamId', fn () => $this->service()->updateMemberships($actor->id, [$this->next->id], $this->next->id, $snapshot));
        $this->assertSame($before, $this->state());
        $this->assertTrue($this->service()->canManage($actor));
    }

    public function test_self_edit_can_keep_the_single_native_management_fallback_with_sufficient_active_team_rights(): void
    {
        $actor = $this->manager();
        $previous = $actor->current_team_id;
        $active = $this->team('Sicherer Arbeitsbereich', $this->managerPermissions());
        $this->actingAs($actor);
        $this->assertTrue($this->service()->updateMemberships($actor->id, [$previous, $active->id], $active->id, $this->snapshot($actor)));
        $fresh = $actor->fresh('currentTeam');
        $this->assertSame($active->id, $fresh->current_team_id);
        $this->assertTrue($fresh->canViewManagementDashboard());
        $this->assertTrue($this->service()->canManage($fresh));
        $before = $this->state();
        $snapshot = $this->snapshot($fresh);
        $this->assertValidation('primaryTeamId', fn () => $this->service()->updateMemberships($actor->id, [$active->id], $active->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('globalAdminTargetRoles')]
    public function test_global_admin_can_assign_admin_targets_without_rewriting_their_global_role_or_security_state(string $role): void
    {
        $target = User::factory()->create(['role' => $role, 'status' => true, 'current_team_id' => $this->old->id]);
        $target->teams()->attach($this->old);
        $before = array_diff_key($target->fresh()->getAttributes(), array_flip(['current_team_id', 'updated_at']));
        $this->assertTrue($this->service()->updateMemberships($target->id, [$this->old->id, $this->next->id], $this->next->id, $this->snapshot($target)));
        $this->assertSame($before, array_diff_key($target->fresh()->getAttributes(), array_flip(['current_team_id', 'updated_at'])));
    }

    public static function globalAdminTargetRoles(): array
    {
        return ['admin' => ['admin'], 'superadmin' => ['superadmin']];
    }

    public function test_maximum_one_hundred_canonical_team_ids_are_accepted_and_missing_employee_is_rejected(): void
    {
        $ids = [$this->old->id];
        foreach (range(1, 99) as $index) {
            $ids[] = $this->team('Grenzfall '.$index)->id;
        }
        $this->assertTrue($this->service()->updateMemberships($this->employee->id, $ids, $this->old->id, $this->snapshot()));
        $this->assertSame(100, $this->employee->teams()->count());
        $before = $this->state();
        $this->assertValidation('employeeIds', fn () => $this->service()->updateMemberships(99999, [$this->old->id], $this->old->id, 'forged'));
        $this->assertSame($before, $this->state());
    }

    public function test_new_team_rights_are_checked_fresh_even_when_the_employee_snapshot_is_still_current(): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        $snapshot = $this->snapshot();
        $this->next->update(['rbac_permissions' => ['devices.providers.manage' => true]]);
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->updateMemberships($this->employee->id, [$this->old->id, $this->next->id], $this->old->id, $snapshot));
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('schedulerChangeTypes')]
    public function test_native_outlook_refresh_is_enqueued_only_after_commit_for_membership_and_primary_only_changes(bool $addMembership): void
    {
        if (! $addMembership) {
            $this->employee->teams()->attach($this->next);
        }
        Schema::create('mail_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('kind');
            $table->unsignedInteger('delivery_revision')->default(1);
            $table->boolean('outlook_default');
            $table->timestamp('published_at');
            $table->text('published_html');
        });
        DB::table('mail_documents')->insert(['kind' => 'signature', 'outlook_default' => true, 'published_at' => now(), 'published_html' => '<p>Isolated published fixture</p>']);
        config(['outlook_addin.snapshots.auto_refresh' => true]);
        $snapshot = $this->snapshot();
        $before = $this->state();
        DB::beginTransaction();
        try {
            $this->service()->updateMemberships($this->employee->id, [$this->old->id, $this->next->id], $this->next->id, $snapshot);
            Bus::assertNotDispatched(RefreshOutlookAddinUserSnapshot::class);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->state());
        Bus::assertNotDispatched(RefreshOutlookAddinUserSnapshot::class);
        $this->service()->updateMemberships($this->employee->id, [$this->old->id, $this->next->id], $this->next->id, $snapshot);
        Bus::assertDispatched(RefreshOutlookAddinUserSnapshot::class, fn ($job): bool => $job->userId === $this->employee->id && $job->afterCommit === true);
        $this->assertNoTransports();
    }

    public static function schedulerChangeTypes(): array
    {
        return ['new-membership-native-event' => [true], 'primary-only-direct-scheduler' => [false]];
    }

    public function test_native_membership_events_and_audit_are_atomic_and_wait_for_the_outer_transaction(): void
    {
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        $snapshot = $this->snapshot();
        $before = $this->state();
        DB::beginTransaction();
        try {
            $this->assertTrue($this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot));
            Event::assertNotDispatched(TeamMemberAdded::class);
            Event::assertNotDispatched(TeamMemberRemoved::class);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->state());
        Event::assertNotDispatched(TeamMemberAdded::class);
        Event::assertNotDispatched(TeamMemberRemoved::class);
        DB::beginTransaction();
        $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot);
        Event::assertNotDispatched(TeamMemberAdded::class);
        DB::commit();
        Event::assertDispatched(TeamMemberAdded::class, fn ($event): bool => $event->team->id === $this->next->id && $event->user->id === $this->employee->id);
        Event::assertDispatched(TeamMemberRemoved::class, fn ($event): bool => $event->team->id === $this->old->id && $event->user->id === $this->employee->id);
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'employee-team')->count());
    }

    public function test_failure_during_primary_save_rolls_back_membership_changes_and_domain_audit(): void
    {
        $snapshot = $this->snapshot();
        $before = $this->state();
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        Event::listen('eloquent.saving: '.User::class, function (User $user): void {
            if ($user->id === $this->employee->id && $user->isDirty('current_team_id')) {
                throw new RuntimeException('Isolated failure after proposed membership writes');
            }
        });
        try {
            $this->service()->updateMemberships($this->employee->id, [$this->next->id], $this->next->id, $snapshot);
            $this->fail('Expected the fixture failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Isolated failure after proposed membership writes', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        Event::assertNotDispatched(TeamMemberAdded::class);
        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    public function test_new_profile_module_loads_multiple_teams_saves_and_refreshes_its_locked_snapshot(): void
    {
        $fixed = $this->team('Persönlich behalten', personal: true, owner: $this->employee);
        $this->employee->teams()->attach($fixed, ['role' => 'personal-role']);
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeams::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('teamIds', [$this->old->id])->assertSet('primaryTeamId', $this->old->id)
            ->assertSee('Teams zuweisen')->assertSee('Primäres Team')->assertSee('Persönlich behalten')
            ->assertDontSee('Teams & Rechte')->assertDontSee('Team erstellen');
        $snapshot = $component->get('snapshot');
        $component->set('teamIds', [$this->old->id, $this->next->id])->set('primaryTeamId', $this->next->id)
            ->call('save')->assertHasNoErrors()->assertDispatched('employee-teams-updated', ids: [$this->employee->id])
            ->assertDispatched('employeeSaved')->assertDispatched('refresh-navigation-menu');
        $this->assertNotSame($snapshot, $component->get('snapshot'));
        $this->assertEqualsCanonicalizing([$this->old->id, $this->next->id], $component->get('teamIds'));
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $fixed->id, 'role' => 'personal-role']);
        $this->assertSame($this->next->id, $this->employee->fresh()->current_team_id);
        $this->assertNoTransports();
    }

    #[DataProvider('childLockedProperties')]
    public function test_profile_module_identity_and_snapshot_cannot_be_overwritten_from_the_browser(string $property, mixed $value): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeams::class, ['userId' => $this->employee->id]);
        $before = $this->state();
        try {
            $component->set($property, $value);
            $this->fail('Expected locked property protection.');
        } catch (CannotUpdateLockedPropertyException $exception) {
            $this->assertStringContainsString($property, $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    public static function childLockedProperties(): array
    {
        return ['target' => ['userId', 99999], 'snapshot' => ['snapshot', 'forged']];
    }

    public function test_profile_module_stale_save_preserves_draft_and_requires_an_explicit_reload(): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeams::class, ['userId' => $this->employee->id]);
        $snapshot = $component->get('snapshot');
        $parallel = $this->team('Parallel zugeordnet');
        $this->employee->teams()->attach($parallel);
        $before = $this->state();
        $component->set('teamIds', [$this->next->id])->set('primaryTeamId', $this->next->id)->call('save')
            ->assertHasErrors('snapshot')->assertSet('teamIds', [$this->next->id])->assertSet('snapshot', $snapshot)
            ->assertNotDispatched('employee-teams-updated');
        $this->assertSame($before, $this->state());
        $component->call('reload')->assertHasNoErrors()->assertSet('primaryTeamId', $this->old->id);
        $this->assertEqualsCanonicalizing([$this->old->id, $parallel->id], $component->get('teamIds'));
        $this->assertNotSame($snapshot, $component->get('snapshot'));
    }

    public function test_inactive_employee_can_be_assigned_teams_by_active_admin_without_being_activated(): void
    {
        $this->employee->forceFill(['status' => false])->save();
        Livewire::actingAs($this->admin)->test(EmployeeTeams::class, ['userId' => $this->employee->id])
            ->set('teamIds', [$this->old->id, $this->next->id])->call('save')->assertHasNoErrors();
        $this->assertFalse($this->employee->fresh()->status);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $this->next->id]);
    }

    public function test_reading_a_target_without_a_current_team_does_not_create_or_switch_a_personal_team(): void
    {
        $this->employee->forceFill(['current_team_id' => null])->save();
        $before = $this->state();
        $this->service()->prepare([$this->employee->id]);
        Livewire::actingAs($this->admin)->test(EmployeeTeams::class, ['userId' => $this->employee->id])
            ->assertSuccessful()->assertSet('primaryTeamId', null);
        $this->assertSame($before, $this->state());
    }

    public function test_profile_module_requires_fresh_permissions_on_reload_and_save(): void
    {
        Livewire::actingAs($this->employee)->test(EmployeeTeams::class, ['userId' => $this->employee->id])->assertForbidden();
        $actor = $this->manager();
        $component = Livewire::actingAs($actor)->test(EmployeeTeams::class, ['userId' => $this->employee->id]);
        $actor->currentTeam->update(['rbac_permissions' => ['employees.view' => true, 'employees.create' => true]]);
        $before = $this->state();
        $component->call('save')->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public function test_existing_employee_edit_dialog_loads_and_saves_multiple_teams_without_destructive_sync(): void
    {
        $extra = $this->team('Bestehendes Zusatzteam');
        $fixed = $this->team('Persönlich fix', personal: true, owner: $this->employee);
        $this->employee->teams()->attach($extra, ['role' => 'extra-role']);
        $this->employee->teams()->attach($fixed, ['role' => 'personal-role']);
        $before = $this->nonTeamAccount();
        $component = Livewire::actingAs($this->admin)->test(EmployeeFormModal::class)->call('open', $this->employee->id)
            ->assertSet('primary_team_id', $this->old->id)->assertSee('Teams zuweisen')->assertSee('Primäres Team');
        $this->assertEqualsCanonicalizing([$this->old->id, $extra->id], $component->get('selected_team_ids'));
        $component->set('selected_team_ids', [$this->old->id, $extra->id, $this->next->id])
            ->set('primary_team_id', $this->next->id)->call('save')->assertHasNoErrors()
            ->assertDispatched('employeeSaved')->assertSet('showModal', false);
        $this->assertSame($before, $this->nonTeamAccount());
        $this->assertSame($this->next->id, $this->employee->fresh()->current_team_id);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $extra->id, 'role' => 'extra-role']);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->employee->id, 'team_id' => $fixed->id, 'role' => 'personal-role']);
        $this->assertNoTransports();
    }

    public function test_stale_employee_edit_rejects_name_email_profile_password_and_team_writes_together(): void
    {
        UserProfile::create(['user_id' => $this->employee->id, 'city' => 'Hamburg']);
        $component = Livewire::actingAs($this->admin)->test(EmployeeFormModal::class)->call('open', $this->employee->id)
            ->set('name', 'Nicht speichern')->set('email', 'not-saved@example.test')->set('city', 'Berlin')
            ->set('password', 'replacement-password')->set('password_confirmation', 'replacement-password')
            ->set('selected_team_ids', [$this->next->id])->set('primary_team_id', $this->next->id);
        $this->employee->teams()->attach($this->next);
        $before = $this->state();
        $component->call('save')->assertHasErrors('teamSnapshot')->assertSet('showModal', true)
            ->assertNotDispatched('employeeSaved');
        $this->assertSame($before, $this->state());
        $this->assertNoTransports();
    }

    public function test_late_profile_failure_in_edit_dialog_rolls_back_account_memberships_audit_and_after_commit_events(): void
    {
        UserProfile::create(['user_id' => $this->employee->id, 'city' => 'Hamburg']);
        $component = Livewire::actingAs($this->admin)->test(EmployeeFormModal::class)->call('open', $this->employee->id)
            ->set('name', 'Nicht teilweise speichern')->set('city', 'Berlin')
            ->set('selected_team_ids', [$this->next->id])->set('primary_team_id', $this->next->id);
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        Event::listen('eloquent.saving: '.UserProfile::class, function (UserProfile $profile): void {
            if ($profile->user_id === $this->employee->id) {
                throw new RuntimeException('Isolated late profile failure');
            }
        });
        $before = $this->state();
        try {
            $component->call('save');
            $this->fail('Expected fixture profile failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Isolated late profile failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        Event::assertNotDispatched(TeamMemberAdded::class);
        Event::assertNotDispatched(TeamMemberRemoved::class);
        $this->assertNoTransports();
    }

    public function test_editor_without_team_management_can_edit_ordinary_fields_without_losing_any_memberships(): void
    {
        $extra = $this->team('Zusatz bleibt');
        $this->employee->teams()->attach($extra, ['role' => 'keep-role']);
        $beforePivots = DB::table('team_user')->where('user_id', $this->employee->id)->orderBy('id')->get()->toJson();
        $actor = $this->manager(['employees.view' => true, 'employees.create' => true]);
        Livewire::actingAs($actor)->test(EmployeeFormModal::class)->call('open', $this->employee->id)
            ->assertDontSee('Teams zuweisen')->set('name', 'Autorisierter Name')->set('city', 'Bremen')
            ->call('save')->assertHasNoErrors()->assertDispatched('employeeSaved');
        $this->assertSame('Autorisierter Name', $this->employee->fresh()->name);
        $this->assertSame('Bremen', $this->employee->profile->fresh()->city);
        $this->assertSame($beforePivots, DB::table('team_user')->where('user_id', $this->employee->id)->orderBy('id')->get()->toJson());
        $this->assertNoTransports();
    }

    #[DataProvider('unauthorizedEditTeamInputs')]
    public function test_editor_without_roles_manage_cannot_tamper_with_team_fields_or_partially_save_other_fields(string $field, mixed $value, string $error): void
    {
        $actor = $this->manager(['employees.view' => true, 'employees.create' => true]);
        $component = Livewire::actingAs($actor)->test(EmployeeFormModal::class)->call('open', $this->employee->id)
            ->set('name', 'Nicht teilweise speichern')->set($field, $value);
        $before = $this->state();
        $component->call('save')->assertHasErrors($error)->assertNotDispatched('employeeSaved');
        $this->assertSame($before, $this->state());
    }

    public function test_edit_dialog_rechecks_revoked_team_management_before_changing_any_submitted_field(): void
    {
        $actor = $this->manager();
        $component = Livewire::actingAs($actor)->test(EmployeeFormModal::class)->call('open', $this->employee->id)
            ->set('selected_team_ids', [$this->old->id, $this->next->id])->set('name', 'Nicht speichern');
        $actor->currentTeam->update(['rbac_permissions' => ['employees.view' => true, 'employees.create' => true]]);
        $before = $this->state();
        $component->call('save')->assertHasErrors('selected_team_ids')->assertNotDispatched('employeeSaved');
        $this->assertSame($before, $this->state());
    }

    public static function unauthorizedEditTeamInputs(): array
    {
        return ['memberships' => ['selected_team_ids', [2], 'selected_team_ids'], 'primary' => ['primary_team_id', 2, 'primary_team_id']];
    }

    #[DataProvider('editLockedProperties')]
    public function test_employee_edit_identity_and_original_team_snapshot_are_locked(string $property, mixed $value): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeFormModal::class)->call('open', $this->employee->id);
        $before = $this->state();
        try {
            $component->set($property, $value);
            $this->fail('Expected locked property protection.');
        } catch (CannotUpdateLockedPropertyException $exception) {
            $this->assertStringContainsString($property, $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    public static function editLockedProperties(): array
    {
        return ['target' => ['userId', 99999], 'snapshot' => ['teamSnapshot', 'forged'], 'original' => ['originalTeamState', []]];
    }

    public function test_create_dialog_keeps_its_existing_single_team_contract(): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeFormModal::class)->call('open')
            ->assertSet('userId', null)->assertDontSee('Teams zuweisen')->set('name', 'Neuer Mitarbeiter')
            ->set('email', 'new-team-fixture@example.test')->set('primary_team_id', $this->old->id)
            ->call('save')->assertHasNoErrors()->assertDispatched('employeeSaved');
        $created = User::where('email', 'new-team-fixture@example.test')->sole();
        $this->assertSame('staff', $created->role);
        $this->assertFalse($created->status);
        $this->assertNull($created->email_verified_at);
        $this->assertSame([$this->old->id], $created->teams()->pluck('teams.id')->all());
        $this->assertSame($this->old->id, $created->current_team_id);
        $this->assertNoTransports();
    }

    private function service(): EmployeeTeamService
    {
        return app(EmployeeTeamService::class);
    }

    private function snapshot(?User $employee = null): string
    {
        $employee ??= $this->employee;

        return $this->service()->prepare([$employee->id])['snapshots'][$employee->id];
    }

    private function team(string $name, array $permissions = [], bool $personal = false, ?User $owner = null): Team
    {
        return Team::forceCreate(['name' => $name, 'user_id' => ($owner ?? $this->admin)->id, 'personal_team' => $personal, 'rbac_permissions' => $permissions]);
    }

    private function manager(?array $permissions = null): User
    {
        $team = $this->team('Verwaltung', $permissions ?? $this->managerPermissions());
        $actor = User::factory()->create(['role' => 'staff', 'status' => true, 'current_team_id' => $team->id]);
        $actor->teams()->attach($team, ['role' => 'team_access']);

        return $actor;
    }

    private function managerPermissions(): array
    {
        return ['employees.view' => true, 'employees.create' => true, 'roles.manage' => true];
    }

    private function nonTeamAccount(): array
    {
        return array_diff_key($this->employee->fresh()->getAttributes(), array_flip(['current_team_id', 'updated_at']));
    }

    private function state(): array
    {
        return collect(['users', 'user_profiles', 'teams', 'team_user', 'activity_log'])
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
