<?php

namespace Tests\Feature;

use App\Livewire\Admin\Employees;
use App\Livewire\Admin\Employees\EmployeeTeamBulkModal;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Personnel\EmployeeTeamService;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class EmployeeBulkTeamMembershipTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $first;

    private User $second;

    private Team $north;

    private Team $south;

    private Team $extra;

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
        $this->north = $this->team('Mitarbeiter');
        $this->south = $this->team('Bestehendes Team Süd');
        $this->extra = $this->team('Zusätzliches Einsatzteam');
        $this->first = $this->employee($this->north, 'Erste Auswahl');
        $this->second = $this->employee($this->south, 'Zweite Auswahl');
        $this->actingAs($this->admin);
    }

    public function test_adding_multiple_teams_keeps_mixed_primary_contexts_personal_owned_and_account_data_unchanged(): void
    {
        $another = $this->team('Noch ein Einsatzteam');
        $personal = $this->team('Persönlich', personal: true, owner: $this->first);
        $owned = $this->team('Eigenes Team', owner: $this->second);
        $this->first->teams()->attach($personal, ['role' => 'personal-role']);
        $this->second->teams()->attach($owned, ['role' => 'owner-role']);
        UserProfile::create(['user_id' => $this->first->id, 'city' => 'Hamburg', 'position' => 'Wagenmeister']);
        $beforeAccounts = $this->accounts();
        $beforeProfiles = DB::table('user_profiles')->get()->toJson();
        $beforeTeams = DB::table('teams')->orderBy('id')->get()->toJson();
        $selection = $this->prepare();

        $this->assertSame(2, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id, $another->id], $selection['snapshots']));

        $this->assertSame($beforeAccounts, $this->accounts());
        $this->assertSame($beforeProfiles, DB::table('user_profiles')->get()->toJson());
        $this->assertSame($beforeTeams, DB::table('teams')->orderBy('id')->get()->toJson());
        $this->assertSame($this->north->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->south->id, $this->second->fresh()->current_team_id);
        foreach ([$this->first, $this->second] as $employee) {
            foreach ([$this->extra, $another] as $team) {
                $this->assertDatabaseHas('team_user', ['user_id' => $employee->id, 'team_id' => $team->id, 'role' => 'team_access']);
            }
        }
        $this->assertDatabaseHas('team_user', ['user_id' => $this->first->id, 'team_id' => $personal->id, 'role' => 'personal-role']);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->second->id, 'team_id' => $owned->id, 'role' => 'owner-role']);
        $this->assertSame(2, DB::table('activity_log')->where('description', 'employee_team_memberships_updated')->count());
        $this->assertNoTransports();
    }

    public function test_removing_multiple_additional_teams_preserves_all_other_pivots_and_both_primary_teams(): void
    {
        $another = $this->team('Auch entfernen');
        foreach ([$this->first, $this->second] as $employee) {
            $employee->teams()->attach($this->extra, ['role' => 'remove-role']);
            $employee->teams()->attach($another, ['role' => 'remove-role']);
        }
        $selection = $this->prepare();
        $this->assertSame(2, $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->extra->id, $another->id], $selection['snapshots']));
        $this->assertSame($this->north->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->south->id, $this->second->fresh()->current_team_id);
        foreach ([$this->first, $this->second] as $employee) {
            $this->assertDatabaseMissing('team_user', ['user_id' => $employee->id, 'team_id' => $this->extra->id]);
            $this->assertDatabaseMissing('team_user', ['user_id' => $employee->id, 'team_id' => $another->id]);
        }
        $this->assertDatabaseHas('team_user', ['user_id' => $this->first->id, 'team_id' => $this->north->id, 'role' => 'existing-role']);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->second->id, 'team_id' => $this->south->id, 'role' => 'existing-role']);
    }

    public function test_removing_a_primary_only_replaces_the_affected_employee_context(): void
    {
        $this->first->teams()->attach($this->extra);
        $selection = $this->prepare();
        $this->assertSame(1, $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->north->id], $selection['snapshots'], $this->extra->id));
        $this->assertSame($this->extra->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->south->id, $this->second->fresh()->current_team_id);
        $this->assertDatabaseMissing('team_user', ['user_id' => $this->first->id, 'team_id' => $this->north->id]);
        $this->assertDatabaseMissing('team_user', ['user_id' => $this->second->id, 'team_id' => $this->extra->id]);
        $this->assertSame(1, DB::table('activity_log')->where('description', 'employee_team_memberships_updated')->count());
    }

    public function test_primary_replacement_must_be_explicit_and_belong_to_every_affected_final_membership_set(): void
    {
        foreach ([$this->first, $this->second] as $employee) {
            $employee->teams()->attach($this->extra);
        }
        $selection = $this->prepare();
        $before = $this->state();
        $this->assertValidation('replacementTeamId', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->north->id, $this->south->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
        $this->assertValidation('replacementTeamId', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->north->id, $this->south->id], $selection['snapshots'], $this->north->id));
        $this->assertSame($before, $this->state());
        $this->assertSame(2, $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->north->id, $this->south->id], $selection['snapshots'], $this->extra->id));
        $this->assertSame($this->extra->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->extra->id, $this->second->fresh()->current_team_id);
    }

    public function test_a_replacement_valid_for_only_the_first_employee_does_not_partially_update_the_batch(): void
    {
        $other = $this->team('Nur für den zweiten');
        $this->first->teams()->attach($this->extra);
        $this->second->teams()->attach($other);
        $selection = $this->prepare();
        $before = $this->state();
        $this->assertValidation('replacementTeamId', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->north->id, $this->south->id], $selection['snapshots'], $this->extra->id));
        $this->assertSame($before, $this->state());
    }

    public function test_adding_teams_to_a_target_with_no_primary_requires_an_explicit_primary_for_that_target_only(): void
    {
        $this->first->forceFill(['current_team_id' => null])->save();
        $selection = $this->prepare();
        $before = $this->state();
        $this->assertValidation('replacementTeamId', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
        $this->assertSame(2, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots'], $this->extra->id));
        $this->assertSame($this->extra->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->south->id, $this->second->fresh()->current_team_id);
    }

    public function test_adding_an_existing_team_and_removing_an_absent_team_are_noops_without_audits_or_member_events(): void
    {
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        $selection = $this->prepare([$this->first->id]);
        $before = $this->state();
        $this->assertSame(0, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->north->id], $selection['snapshots']));
        $this->assertSame(0, $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
        Event::assertNotDispatched(TeamMemberAdded::class);
        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    public function test_changed_count_and_audits_only_include_targets_whose_memberships_actually_change(): void
    {
        $this->first->teams()->attach($this->extra, ['role' => 'keep-legacy-role']);
        $selection = $this->prepare();
        $this->assertSame(1, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertDatabaseHas('team_user', ['user_id' => $this->first->id, 'team_id' => $this->extra->id, 'role' => 'keep-legacy-role']);
        $audit = DB::table('activity_log')->where('description', 'employee_team_memberships_updated')->sole();
        $this->assertSame($this->second->id, $audit->subject_id);
    }

    #[DataProvider('invalidEmployeeSelections')]
    public function test_untrusted_employee_selection_is_strict_bounded_and_rejected_without_any_changes(array $ids): void
    {
        $before = $this->state();
        $this->assertValidation('employeeIds', fn () => $this->service()->changeMembershipsForSelection($ids, 'add', [$this->extra->id], []));
        $this->assertSame($before, $this->state());
    }

    public static function invalidEmployeeSelections(): array
    {
        return [
            'empty' => [[]], 'max' => [range(1, 101)], 'duplicate' => [[2, '2']], 'zero' => [[0]], 'negative' => [[-1]],
            'float' => [[2.5]], 'boolean' => [[true]], 'nested' => [[[2]]], 'trailing' => [['2x']], 'leading-zero' => [['02']],
            'whitespace' => [[' 2']], 'missing' => [[999999]], 'associative' => [['employee' => 2]],
        ];
    }

    #[DataProvider('invalidTeamSelections')]
    public function test_untrusted_team_selection_is_strict_nonempty_and_bounded(array $ids): void
    {
        $selection = $this->prepare();
        $before = $this->state();
        $this->assertValidation('teamIds', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', $ids, $selection['snapshots']));
        $this->assertSame($before, $this->state());
    }

    public static function invalidTeamSelections(): array
    {
        return [
            'empty' => [[]], 'max' => [range(1, 101)], 'duplicate' => [[3, '3']], 'zero' => [[0]], 'negative' => [[-1]],
            'float' => [[2.5]], 'boolean' => [[true]], 'nested' => [[[3]]], 'trailing' => [['3x']], 'leading-zero' => [['03']],
            'whitespace' => [[' 3']], 'missing' => [[999999]], 'associative' => [['team' => 3]],
        ];
    }

    #[DataProvider('invalidOperations')]
    public function test_bulk_operation_cannot_be_changed_to_an_unrecognized_sync_or_replace_action(string $operation): void
    {
        $selection = $this->prepare();
        $before = $this->state();
        $this->assertValidation('operation', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], $operation, [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
    }

    public static function invalidOperations(): array
    {
        return ['empty' => [''], 'replace' => ['replace'], 'sync' => ['sync'], 'case' => ['ADD']];
    }

    public function test_a_missing_forged_or_late_stale_snapshot_rolls_back_all_selected_employees(): void
    {
        foreach (['missing', 'forged', 'stale'] as $variant) {
            $selection = $this->prepare();
            if ($variant === 'missing') {
                unset($selection['snapshots'][$this->second->id]);
            } elseif ($variant === 'forged') {
                $selection['snapshots'][$this->second->id] = 'forged';
            } else {
                $this->second->teams()->updateExistingPivot($this->south->id, ['role' => 'parallel-change']);
            }
            $before = $this->state();
            $this->assertValidation('snapshots', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
            $this->assertSame($before, $this->state());
        }
    }

    #[DataProvider('requiredPermissions')]
    public function test_every_actor_permission_is_rechecked_from_fresh_state_for_the_whole_batch(string $permission): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        $selection = $this->prepare();
        $actor->currentTeam->update(['rbac_permissions' => array_diff_key($this->permissions(), [$permission => true])]);
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
    }

    public static function requiredPermissions(): array
    {
        return ['view' => ['employees.view'], 'edit' => ['employees.create'], 'rights' => ['roles.manage']];
    }

    public function test_inactive_actor_and_late_protected_target_cannot_partially_apply_a_batch(): void
    {
        $selection = $this->prepare();
        $this->admin->forceFill(['status' => false])->save();
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
        $this->admin->forceFill(['status' => true])->save();
        $selection = $this->prepare();
        $this->second->forceFill(['role' => 'guest'])->save();
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
    }

    public function test_delegated_actor_cannot_add_stronger_or_reserved_teams_or_touch_an_admin_target(): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        foreach ([$this->team('Stärkere Rechte', ['devices.providers.manage' => true]), $this->team('Administratoren')] as $team) {
            $selection = $this->prepare();
            $before = $this->state();
            $this->assertForbidden(fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$team->id], $selection['snapshots']));
            $this->assertSame($before, $this->state());
        }
        $this->second->forceFill(['role' => 'admin'])->save();
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->changeMembershipsForSelection([$this->first->id, $this->second->id], 'add', [$this->extra->id], []));
        $this->assertSame($before, $this->state());
    }

    public function test_a_late_target_save_failure_rolls_back_earlier_target_changes_audits_and_membership_events(): void
    {
        foreach ([$this->first, $this->second] as $employee) {
            $employee->teams()->attach($this->extra);
        }
        $selection = $this->prepare();
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        Event::listen('eloquent.saving: '.User::class, function (User $user): void {
            if ($user->id === $this->second->id && $user->isDirty('current_team_id')) {
                throw new RuntimeException('Isolated late batch failure');
            }
        });
        $before = $this->state();
        try {
            $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->north->id, $this->south->id], $selection['snapshots'], $this->extra->id);
            $this->fail('Expected the isolated failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Isolated late batch failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        Event::assertNotDispatched(TeamMemberAdded::class);
        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    public function test_native_member_events_wait_for_outer_commit_and_do_not_escape_a_batch_rollback(): void
    {
        Event::fake([TeamMemberAdded::class, TeamMemberRemoved::class]);
        $selection = $this->prepare();
        $before = $this->state();
        DB::beginTransaction();
        try {
            $this->assertSame(2, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
            Event::assertNotDispatched(TeamMemberAdded::class);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->state());
        Event::assertNotDispatched(TeamMemberAdded::class);
        DB::beginTransaction();
        $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']);
        Event::assertNotDispatched(TeamMemberAdded::class);
        DB::commit();
        Event::assertDispatchedTimes(TeamMemberAdded::class, 2);
        Event::assertNotDispatched(TeamMemberRemoved::class);
    }

    public function test_preview_is_read_only_and_replacement_candidates_are_the_intersection_of_affected_targets_only(): void
    {
        $onlyFirst = $this->team('Nur erste Auswahl');
        $onlySecond = $this->team('Nur zweite Auswahl');
        $unaffected = $this->employee($this->south, 'Unbetroffene Auswahl');
        $this->first->teams()->attach($this->extra);
        $this->first->teams()->attach($onlyFirst);
        $this->second->teams()->attach($this->extra);
        $this->second->teams()->attach($onlySecond);
        $before = $this->state();
        $state = $this->service()->bulkEditorState([$this->first->id, $this->second->id, $unaffected->id], 'remove', [$this->north->id, $this->south->id]);
        // The third primary is also removed here, so it must participate.
        $this->assertSame(3, $state['replacementRequired']);
        $this->assertCount(0, $state['replacementTeams']);
        $state = $this->service()->bulkEditorState([$this->first->id, $this->second->id], 'remove', [$this->north->id, $this->south->id]);
        $this->assertSame(2, $state['replacementRequired']);
        $this->assertSame([$this->extra->id], $state['replacementTeams']->pluck('id')->all());
        $state = $this->service()->bulkEditorState([$this->first->id, $unaffected->id], 'remove', [$this->north->id]);
        $this->assertSame(1, $state['replacementRequired']);
        $this->assertEqualsCanonicalizing([$this->extra->id, $onlyFirst->id], $state['replacementTeams']->pluck('id')->all());
        $empty = $this->service()->bulkEditorState([$this->first->id], 'add', []);
        $this->assertSame(0, $empty['replacementRequired']);
        $this->assertSame($before, $this->state());
    }

    public function test_owned_teams_remain_fixed_while_the_same_team_can_be_removed_from_other_selected_members(): void
    {
        $owned = $this->team('Eigentum erste Auswahl', owner: $this->first);
        $this->first->teams()->attach($owned, ['role' => 'owner-role']);
        $this->first->forceFill(['current_team_id' => $owned->id])->save();
        $this->second->teams()->attach($owned, ['role' => 'remove-this-membership']);
        $selection = $this->prepare();
        $preview = $this->service()->bulkEditorState($selection['ids'], 'remove', [$owned->id]);
        $this->assertSame(0, $preview['replacementRequired']);
        $this->assertSame(1, $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$owned->id], $selection['snapshots']));
        $this->assertSame($owned->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->first->id, $owned->fresh()->user_id);
        $this->assertDatabaseHas('team_user', ['user_id' => $this->first->id, 'team_id' => $owned->id, 'role' => 'owner-role']);
        $this->assertDatabaseMissing('team_user', ['user_id' => $this->second->id, 'team_id' => $owned->id]);
    }

    #[DataProvider('operations')]
    public function test_personal_teams_are_not_editable_bulk_targets_and_remain_intact(string $operation): void
    {
        $personal = $this->team('Persönliches Team', personal: true, owner: $this->first);
        $this->first->teams()->attach($personal, ['role' => 'personal-role']);
        $selection = $this->prepare();
        $before = $this->state();
        $this->assertValidation('teamIds', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], $operation, [$this->extra->id, $personal->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
    }

    public static function operations(): array
    {
        return ['add' => ['add'], 'remove' => ['remove']];
    }

    public function test_fresh_destination_rights_and_self_lockout_are_checked_before_a_bulk_write(): void
    {
        $actor = $this->manager();
        $this->actingAs($actor);
        $selection = $this->prepare();
        $this->extra->update(['rbac_permissions' => ['devices.providers.manage' => true]]);
        $before = $this->state();
        $this->assertForbidden(fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
        $this->extra->update(['rbac_permissions' => []]);
        $this->first->teams()->attach($this->extra);
        $actor->teams()->attach($this->extra);
        $selection = $this->prepare([$this->first->id, $actor->id]);
        $before = $this->state();
        $this->assertValidation('replacementTeamId', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'remove', [$this->north->id, $actor->current_team_id], $selection['snapshots'], $this->extra->id));
        $this->assertSame($before, $this->state());
        $this->assertTrue($this->service()->canManage($actor));
    }

    public function test_one_hundred_selected_employees_are_supported_without_collapsing_their_primary_contexts(): void
    {
        $ids = [$this->first->id, $this->second->id];
        foreach (range(1, 98) as $index) {
            $ids[] = $this->employee($this->south, 'Auswahl '.$index)->id;
        }
        $selection = $this->prepare($ids);
        $this->assertSame(100, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame(100, DB::table('team_user')->where('team_id', $this->extra->id)->count());
        $this->assertSame($this->north->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->south->id, $this->second->fresh()->current_team_id);
    }

    public function test_one_hundred_team_ids_are_accepted_but_the_final_editable_membership_limit_is_atomic(): void
    {
        $ids = [$this->north->id];
        foreach (range(1, 99) as $index) {
            $ids[] = $this->team('Delta Grenze '.$index)->id;
        }
        $selection = $this->prepare([$this->first->id]);
        $this->assertSame(1, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', $ids, $selection['snapshots']));
        $this->assertSame(100, $this->first->teams()->count());
        $selection = $this->prepare();
        $before = $this->state();
        $this->assertValidation('teamIds', fn () => $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($before, $this->state());
    }

    public function test_global_administrator_can_bulk_update_inactive_and_superadmin_targets_without_changing_account_security(): void
    {
        $this->first->forceFill(['status' => false])->save();
        $this->second->forceFill(['role' => 'superadmin'])->save();
        $beforeAccounts = $this->accounts();
        $selection = $this->prepare();
        $this->assertSame(2, $this->service()->changeMembershipsForSelection($selection['ids'], 'add', [$this->extra->id], $selection['snapshots']));
        $this->assertSame($beforeAccounts, $this->accounts());
        $this->assertFalse($this->first->fresh()->status);
        $this->assertSame('superadmin', $this->second->fresh()->role);
    }

    public function test_new_bulk_modal_shows_exact_selection_and_saves_only_after_explicit_confirmation(): void
    {
        $before = $this->state();
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamBulkModal::class)
            ->call('open', [$this->second->id, (string) $this->first->id])
            ->assertSet('showModal', true)->assertSet('operation', 'add')->assertSet('teamIds', [])
            ->assertSet('replacementTeamId', null)->assertSee('Teams bearbeiten')
            ->assertSee('Erste Auswahl')->assertSee('Zweite Auswahl')->assertSee('Änderungen speichern');
        $this->assertSame($before, $this->state());
        $this->assertSame([$this->first->id, $this->second->id], $component->get('employeeIds'));
        $this->assertCount(2, $component->get('snapshots'));
        $component->set('teamIds', [$this->extra->id])->call('save')->assertHasNoErrors()
            ->assertSet('showModal', false)->assertSet('employeeIds', [])->assertSet('snapshots', [])
            ->assertDispatched('employee-teams-updated', ids: [$this->first->id, $this->second->id])
            ->assertDispatched('employeeSaved')->assertDispatched('refresh-navigation-menu');
        $this->assertSame($this->north->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->south->id, $this->second->fresh()->current_team_id);
        $this->assertSame(2, DB::table('team_user')->where('team_id', $this->extra->id)->count());
        $this->assertNoTransports();
    }

    #[DataProvider('lockedModalProperties')]
    public function test_bulk_modal_selection_and_snapshots_are_locked_against_browser_tampering(string $property, array $value): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamBulkModal::class)->call('open', [$this->first->id]);
        $before = $this->state();
        try {
            $component->set($property, $value);
            $this->fail('Expected locked property protection.');
        } catch (CannotUpdateLockedPropertyException $exception) {
            $this->assertStringContainsString($property, $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    public static function lockedModalProperties(): array
    {
        return ['selection' => ['employeeIds', [99999]], 'snapshots' => ['snapshots', [2 => 'forged']]];
    }

    public function test_bulk_modal_renders_a_replacement_only_when_needed_and_does_not_choose_one_automatically(): void
    {
        $this->first->teams()->attach($this->extra);
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamBulkModal::class)->call('open', [$this->first->id, $this->second->id]);
        $this->assertCount(0, $this->xpath($component->html())->query('//button[@role="combobox" and @id="employee-bulk-primary-team"]'));
        $component->set('operation', 'remove')->set('teamIds', [$this->north->id])->assertSet('replacementTeamId', null)
            ->assertSee('Neues primäres Team');
        $this->assertCount(1, $this->xpath($component->html())->query('//button[@role="combobox" and @id="employee-bulk-primary-team"]'));
        $before = $this->state();
        $component->call('save')->assertHasErrors('replacementTeamId')->assertSet('showModal', true);
        $this->assertSame($before, $this->state());
        $component->set('replacementTeamId', $this->extra->id)->call('save')->assertHasNoErrors();
        $this->assertSame($this->extra->id, $this->first->fresh()->current_team_id);
        $this->assertSame($this->south->id, $this->second->fresh()->current_team_id);
    }

    public function test_bulk_modal_keeps_a_stale_draft_open_and_cancel_never_changes_memberships(): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamBulkModal::class)->call('open', [$this->first->id, $this->second->id]);
        $snapshots = $component->get('snapshots');
        $this->second->teams()->attach($this->extra);
        $before = $this->state();
        $component->set('teamIds', [$this->extra->id])->call('save')->assertHasErrors('snapshots')
            ->assertSet('showModal', true)->assertSet('snapshots', $snapshots)->assertSet('teamIds', [$this->extra->id])
            ->assertNotDispatched('employee-teams-updated');
        $this->assertSame($before, $this->state());
        $component->call('close')->assertSet('showModal', false)->assertSet('employeeIds', [])->assertSet('teamIds', []);
        $this->assertSame($before, $this->state());
    }

    public function test_bulk_modal_resets_team_and_primary_drafts_when_operation_changes_and_does_not_save_an_empty_action(): void
    {
        $this->first->teams()->attach($this->extra);
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamBulkModal::class)->call('open', [$this->first->id])
            ->set('operation', 'remove')->set('teamIds', [$this->north->id])->set('replacementTeamId', $this->extra->id);
        $component->set('operation', 'add')->assertSet('teamIds', [])->assertSet('replacementTeamId', null);
        $before = $this->state();
        $component->call('save')->assertHasErrors('teamIds')->assertSee('Bitte wählen Sie mindestens ein Team')
            ->assertSet('showModal', true)->assertNotDispatched('employee-teams-updated');
        $this->assertSame($before, $this->state());
        $component->set('replacementTeamId', $this->extra->id)->set('teamIds', [$this->south->id])->assertSet('replacementTeamId', null);
    }

    public function test_bulk_modal_explains_when_no_common_replacement_exists_and_never_invents_a_primary_team(): void
    {
        $component = Livewire::actingAs($this->admin)->test(EmployeeTeamBulkModal::class)->call('open', [$this->first->id, $this->second->id])
            ->set('operation', 'remove')->set('teamIds', [$this->north->id, $this->south->id])
            ->assertSet('replacementTeamId', null)->assertSee('Kein gemeinsames verbleibendes Team verfügbar.');
        $this->assertCount(0, $this->xpath($component->html())->query('//button[@role="combobox" and @id="employee-bulk-primary-team"]'));
        $before = $this->state();
        $component->call('save')->assertHasErrors('replacementTeamId')->assertSet('showModal', true);
        $this->assertSame($before, $this->state());
    }

    public function test_bulk_modal_rechecks_revoked_role_management_on_save(): void
    {
        $actor = $this->manager();
        $component = Livewire::actingAs($actor)->test(EmployeeTeamBulkModal::class)->call('open', [$this->first->id, $this->second->id])
            ->set('teamIds', [$this->extra->id]);
        $actor->currentTeam->update(['rbac_permissions' => ['employees.view' => true, 'employees.create' => true]]);
        $before = $this->state();
        $component->call('save')->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public function test_bulk_modal_denies_direct_closed_save_and_unauthorized_open_without_leaking_team_or_target_names(): void
    {
        Livewire::actingAs($this->admin)->test(EmployeeTeamBulkModal::class)->call('save')->assertForbidden();
        $component = Livewire::actingAs($this->first)->test(EmployeeTeamBulkModal::class)->assertSuccessful()
            ->assertDontSee('Zweite Auswahl')->assertDontSee('Zusätzliches Einsatzteam');
        $before = $this->state();
        $component->call('open', [$this->second->id])->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public function test_directory_exposes_one_bulk_team_editor_and_removes_the_export_menu_and_callable_stub(): void
    {
        $this->migrateDirectory();
        $component = Livewire::actingAs($this->admin)->test(Employees::class);
        $this->assertCount(1, $this->xpath($component->html())->query('//*[@data-employee-team-bulk-modal]'));
        $component->set('selectedEmployees', [$this->first->id, $this->second->id])
            ->assertSee('Teams bearbeiten')->assertDontSee(__('app.export'))->assertDontSee('exportSelected', false);
        $before = $this->state();
        $component->call('openTeamEditSelected')->assertDispatched('open-employee-team-bulk-modal', ids: [$this->first->id, $this->second->id]);
        $this->assertSame($before, $this->state());
        $this->assertFalse(method_exists(Employees::class, 'exportSelected'));
        $this->expectException(MethodNotFoundException::class);
        $component->call('exportSelected');
    }

    public function test_directory_bulk_team_action_rejects_empty_missing_or_unauthorized_selection_and_clears_only_updated_ids(): void
    {
        $this->migrateDirectory();
        $component = Livewire::actingAs($this->admin)->test(Employees::class);
        $component->set('selectedEmployees', [])->call('openTeamEditSelected')->assertHasErrors('employeeIds');
        $component->set('selectedEmployees', [99999])->call('openTeamEditSelected')->assertHasErrors('employeeIds');
        $component->set('selectedEmployees', [$this->first->id, $this->second->id])->set('selectAll', true)
            ->set('perPage', 1)->call('setPage', 2)->call('teamsUpdated', [$this->first->id])
            ->assertSet('selectedEmployees', [$this->second->id])->assertSet('selectAll', false)->assertSet('paginators.page', 1);
        $actor = $this->manager(['employees.view' => true, 'employees.create' => true]);
        Livewire::actingAs($actor)->test(Employees::class)->set('selectedEmployees', [$this->first->id])
            ->assertDontSee('Teams bearbeiten')->call('openTeamEditSelected')->assertForbidden();
        $this->assertSame(0, DB::table('activity_log')->where('description', 'employee_team_memberships_updated')->count());
    }

    private function migrateDirectory(): void
    {
        foreach ([
            '2026_07_22_000002_create_employee_document_requirements_table.php',
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

    private function service(): EmployeeTeamService
    {
        return app(EmployeeTeamService::class);
    }

    private function prepare(?array $ids = null): array
    {
        return $this->service()->prepare($ids ?? [$this->first->id, $this->second->id]);
    }

    private function team(string $name, array $permissions = [], bool $personal = false, ?User $owner = null): Team
    {
        return Team::forceCreate(['name' => $name, 'user_id' => ($owner ?? $this->admin)->id, 'personal_team' => $personal, 'rbac_permissions' => $permissions]);
    }

    private function employee(Team $team, string $name): User
    {
        $employee = User::factory()->create(['role' => 'staff', 'status' => true, 'name' => $name, 'current_team_id' => $team->id]);
        $employee->teams()->attach($team, ['role' => 'existing-role']);

        return $employee;
    }

    private function manager(?array $permissions = null): User
    {
        $team = $this->team('Verwaltung', $permissions ?? $this->permissions());

        return $this->employee($team, 'Verwaltung Fixture');
    }

    private function permissions(): array
    {
        return ['employees.view' => true, 'employees.create' => true, 'roles.manage' => true];
    }

    private function accounts(): array
    {
        return [$this->first->fresh()->getAttributes(), $this->second->fresh()->getAttributes()];
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
