<?php

namespace App\Services\Personnel;

use App\Models\Team;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;

/** The same guarded primary-team change for the profile and employee selection. */
final class EmployeeTeamService
{
    public function canManage(?User $actor = null): bool
    {
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;
        $fresh = $actor ? User::with('currentTeam')->find($actor->id) : null;

        return $fresh?->isActive() && $fresh->canViewManagementDashboard()
            && Gate::forUser($fresh)->allows('employees.view')
            && Gate::forUser($fresh)->allows('employees.create')
            && Gate::forUser($fresh)->allows('roles.manage');
    }

    /** @return array{ids: array<int, int>, snapshots: array<int, string>, employees: Collection<int, User>} */
    public function prepare(array $ids): array
    {
        $actor = $this->authorizeActor();
        $ids = $this->normalizeIds($ids);
        $employees = User::with(['currentTeam', 'teams', 'ownedTeams'])->whereIn('id', $ids)->orderBy('id')->get();
        $this->authorizeTargets($actor, $employees, $ids);

        return [
            'ids' => $ids,
            'snapshots' => $employees->mapWithKeys(fn (User $user) => [$user->id => $this->fingerprint($user)])->all(),
            'employees' => $employees,
        ];
    }

    /** @return Collection<int, Team> */
    public function availableTeams(): Collection
    {
        $actor = $this->authorizeActor();

        return Team::where('personal_team', false)->orderBy('name')->get()
            ->filter(fn (Team $team) => $this->canAssignTeam($actor, $team))->values();
    }

    /**
     * Ordinary, non-owned teams are editable. Personal memberships and team
     * ownership are fixed; the primary context is always selected explicitly.
     *
     * @return array{employee: User, teams: Collection, fixedTeams: Collection, primaryTeams: Collection}
     */
    public function editorState(int $userId, array $selectedIds): array
    {
        $employee = $this->prepare([$userId])['employees']->first();
        $fixedTeams = $employee->teams->filter(fn (Team $team) => $team->personal_team || $employee->ownsTeam($team))
            ->merge($employee->ownedTeams)->unique('id')->sortBy('name')->values();
        $teams = $this->availableTeams()->merge($employee->teams->filter(fn (Team $team) => ! $team->personal_team && ! $employee->ownsTeam($team)))
            ->reject(fn (Team $team) => $employee->ownsTeam($team))->unique('id')->sortBy('name')->values();
        $selected = array_filter($selectedIds, fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)));
        $primaryTeams = $teams->whereIn('id', array_map('intval', $selected))->merge($fixedTeams)->unique('id')->sortBy('name')->values();

        return compact('employee', 'teams', 'fixedTeams', 'primaryTeams');
    }

    /** @return array{employees: Collection, teams: Collection, replacementTeams: Collection, replacementRequired: int} */
    public function bulkEditorState(array $ids, string $operation, array $teamIds): array
    {
        $prepared = $this->prepare($ids);
        $actor = $this->authorizeActor();
        $this->validateMembershipOperation($operation);
        $teamIds = $this->normalizeMembershipIds($teamIds);
        $selected = Team::whereIn('id', $teamIds)->get()->keyBy('id');
        $this->validateBulkTeams($selected, $teamIds);
        $employees = $prepared['employees'];
        $teams = $operation === 'add'
            ? $this->availableTeams()
            : $employees->flatMap(fn (User $employee) => $employee->teams->reject(fn (Team $team) => $team->personal_team))
                ->unique('id')->sortBy('name')->values();
        $teams = new Collection($teams->all());
        $replacementTeams = null;
        $replacementRequired = 0;
        foreach ($employees as $employee) {
            $plan = $this->bulkMembershipPlan($employee, $operation, $selected);
            if (! $plan['affected']) {
                continue;
            }
            $replacementRequired++;
            $candidates = $plan['allTeams']->filter(fn (Team $team) => $this->canAssignTeam($actor, $team))->keyBy('id');
            $replacementTeams = $replacementTeams === null ? $candidates : $replacementTeams->intersectByKeys($candidates);
        }
        $replacementTeams = ($replacementTeams ?? new Collection)->sortBy('name')->values();

        return compact('employees', 'teams', 'replacementTeams', 'replacementRequired');
    }

    /** Add/remove a delta for all selected employees, or commit no changes. */
    public function changeMembershipsForSelection(array $ids, string $operation, array $teamIds, array $snapshots, ?int $replacementTeamId = null): int
    {
        $ids = $this->normalizeIds($ids);
        $this->validateMembershipOperation($operation);
        $teamIds = $this->normalizeMembershipIds($teamIds);
        if ($teamIds === []) {
            throw ValidationException::withMessages(['teamIds' => 'Bitte wählen Sie mindestens ein Team für die Sammeländerung.']);
        }

        return DB::transaction(function () use ($ids, $operation, $teamIds, $snapshots, $replacementTeamId): int {
            $actor = $this->authorizeActor(lock: true);
            $employees = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $this->authorizeTargets($actor, $employees, $ids);
            $memberships = DB::table('team_user')->whereIn('user_id', $ids)->orderBy('user_id')->orderBy('team_id')->lockForUpdate()->get();
            $ownedTeams = Team::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $allIds = $memberships->pluck('team_id')->merge($ownedTeams->pluck('id'))->merge($teamIds)
                ->merge($employees->pluck('current_team_id'))->push($replacementTeamId)->filter()->unique()->sort()->values();
            $teams = Team::whereIn('id', $allIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $selected = $teams->only($teamIds);
            $this->validateBulkTeams($selected, $teamIds);

            $plans = [];
            foreach ($employees as $employee) {
                $this->hydrateLockedTeams($employee, $memberships->where('user_id', $employee->id), $teams);
                if (! isset($snapshots[$employee->id]) || ! is_string($snapshots[$employee->id])
                    || ! hash_equals($this->fingerprint($employee), $snapshots[$employee->id])) {
                    throw ValidationException::withMessages(['snapshots' => 'Mindestens eine Teamzuordnung wurde zwischenzeitlich geändert. Bitte öffnen Sie die Sammelbearbeitung erneut.']);
                }
                $plan = $this->bulkMembershipPlan($employee, $operation, $selected);
                $primaryId = $plan['affected'] ? $replacementTeamId : $employee->current_team_id;
                $primary = $primaryId ? $plan['allTeams']->firstWhere('id', $primaryId) : null;
                if (! $primary) {
                    throw ValidationException::withMessages(['replacementTeamId' => 'Bitte wählen Sie ein gemeinsames verbleibendes primäres Team für die betroffenen Mitarbeiter.']);
                }
                foreach ($plan['added'] as $team) {
                    abort_unless($this->canAssignTeam($actor, $team), 403);
                }
                if ($plan['affected']) {
                    abort_unless($this->canAssignTeam($actor, $primary), 403);
                }
                if ((int) $employee->id === (int) $actor->id && ! $actor->isAdmin()) {
                    try {
                        $this->ensureSelfAccess($actor, $primary, $plan['finalPivots']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(['replacementTeamId' => $exception->errors()['primaryTeamId']]);
                    }
                }
                if (count($plan['editableIds']) > 100) {
                    throw ValidationException::withMessages(['teamIds' => 'Ein Mitarbeiter hätte mehr als 100 bearbeitbare Teammitgliedschaften. Bitte verkleinern Sie die Änderung.']);
                }
                $plans[$employee->id] = ['ids' => $plan['editableIds'], 'primary' => (int) $primary->id];
            }

            $changed = 0;
            // Keep the original actor context for every other target. The
            // independently prevalidated self-change is deliberately last.
            $ordered = $employees->sortBy(fn (User $employee) => (int) $employee->id === (int) $actor->id ? 1 : 0);
            foreach ($ordered as $employee) {
                $plan = $plans[$employee->id];
                try {
                    $changed += (int) $this->updateMemberships($employee->id, $plan['ids'], $plan['primary'], $snapshots[$employee->id]);
                } catch (ValidationException $exception) {
                    $keys = ['snapshot' => 'snapshots', 'primaryTeamId' => 'replacementTeamId'];
                    $errors = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$keys[$key] ?? $key] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
            }

            return $changed;
        }, 3);
    }

    private function validateMembershipOperation(string $operation): void
    {
        if (! in_array($operation, ['add', 'remove'], true)) {
            throw ValidationException::withMessages(['operation' => 'Bitte wählen Sie Teams hinzufügen oder Teams entfernen.']);
        }
    }

    private function validateBulkTeams(Collection $teams, array $ids): void
    {
        if ($teams->count() !== count($ids) || $teams->contains(fn (Team $team) => $team->personal_team)) {
            throw ValidationException::withMessages(['teamIds' => 'Bitte wählen Sie ausschließlich vorhandene organisatorische Teams. Persönliche Teams bleiben unverändert.']);
        }
    }

    /** @return array{editableIds: array<int,int>, finalPivots: Collection, allTeams: Collection, added: Collection, affected: bool} */
    private function bulkMembershipPlan(User $employee, string $operation, Collection $selected): array
    {
        $editable = $employee->teams->filter(fn (Team $team) => ! $team->personal_team && ! $employee->ownsTeam($team));
        $chosen = $selected->reject(fn (Team $team) => $employee->ownsTeam($team));
        $finalEditable = $operation === 'add'
            ? $editable->merge($chosen)->unique('id')
            : $editable->reject(fn (Team $team) => $selected->contains('id', $team->id));
        $fixedPivots = $employee->teams->filter(fn (Team $team) => $team->personal_team || $employee->ownsTeam($team));
        $finalPivots = $fixedPivots->merge($finalEditable)->unique('id')->values();
        $allTeams = $finalPivots->merge($employee->ownedTeams)->unique('id')->values();
        $added = $finalEditable->reject(fn (Team $team) => $employee->teams->contains('id', $team->id))->values();
        $editableIds = $finalEditable->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $affected = ! $employee->current_team_id || ! $allTeams->contains('id', $employee->current_team_id);

        return compact('editableIds', 'finalPivots', 'allTeams', 'added', 'affected');
    }

    public function updateMemberships(int $userId, array $teamIds, int $primaryTeamId, string $snapshot): bool
    {
        $teamIds = $this->normalizeMembershipIds($teamIds);

        return DB::transaction(function () use ($userId, $teamIds, $primaryTeamId, $snapshot): bool {
            $actor = $this->authorizeActor(lock: true);
            $employee = User::whereKey($userId)->lockForUpdate()->first();
            $employees = new Collection($employee ? [$employee] : []);
            $this->authorizeTargets($actor, $employees, [$userId]);

            $memberships = DB::table('team_user')->where('user_id', $userId)->orderBy('team_id')->lockForUpdate()->get();
            $ownedTeams = Team::where('user_id', $userId)->orderBy('id')->lockForUpdate()->get();
            $lockedIds = $memberships->pluck('team_id')->merge($ownedTeams->pluck('id'))->merge($teamIds)
                ->push($employee->current_team_id)->push($primaryTeamId)->filter()->unique()->sort()->values();
            $teams = Team::whereIn('id', $lockedIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->hydrateLockedTeams($employee, $memberships, $teams);
            if (! hash_equals($this->fingerprint($employee), $snapshot)) {
                throw ValidationException::withMessages(['snapshot' => 'Die Teamzuordnung wurde zwischenzeitlich geändert. Bitte laden Sie die Teamansicht neu.']);
            }

            $selected = new Collection;
            foreach ($teamIds as $id) {
                $team = $teams->get($id);
                if (! $team || $team->personal_team || $employee->ownsTeam($team)) {
                    throw ValidationException::withMessages(['teamIds' => 'Die Auswahl enthält ein nicht bearbeitbares Team. Persönliche und eigene Teams bleiben unverändert.']);
                }
                $selected->push($team);
            }
            $retained = $employee->teams->filter(fn (Team $team) => $team->personal_team || $employee->ownsTeam($team) || in_array((int) $team->id, $teamIds, true));
            $added = $selected->reject(fn (Team $team) => $employee->teams->contains('id', $team->id))->values();
            $removed = $employee->teams->reject(fn (Team $team) => $retained->contains('id', $team->id))->values();
            $finalPivots = $retained->merge($added)->unique('id')->values();
            $primary = $teams->get($primaryTeamId);
            if (! $primary || (! $employee->ownsTeam($primary) && ! $finalPivots->contains('id', $primaryTeamId))) {
                throw ValidationException::withMessages(['primaryTeamId' => 'Bitte wählen Sie ein primäres Team aus den verbleibenden Mitgliedschaften.']);
            }
            foreach ($added as $team) {
                abort_unless($this->canAssignTeam($actor, $team), 403);
            }
            $primaryChanged = (int) $employee->current_team_id !== $primaryTeamId;
            if ($primaryChanged) {
                abort_unless($this->canAssignTeam($actor, $primary), 403);
            }
            if ((int) $employee->id === (int) $actor->id && ! $actor->isAdmin()) {
                $this->ensureSelfAccess($actor, $primary, $finalPivots);
            }
            if ($added->isEmpty() && $removed->isEmpty() && ! $primaryChanged) {
                return false;
            }

            foreach ($added as $team) {
                AddingTeamMember::dispatch($team, $employee);
                $employee->teams()->attach($team->id, ['role' => 'team_access']);
            }
            foreach ($removed as $team) {
                $employee->teams()->detach($team->id);
            }
            $previousTeamId = $employee->current_team_id;
            if ($primaryChanged) {
                // Never store null or ask Jetstream to choose a personal team.
                $employee->forceFill(['current_team_id' => $primaryTeamId])->save();
            }
            activity('employee-team')->causedBy($actor)->performedOn($employee)
                ->withProperties([
                    'added_team_ids' => $added->pluck('id')->all(),
                    'removed_team_ids' => $removed->pluck('id')->all(),
                    'previous_team_id' => $previousTeamId,
                    'primary_team_id' => $primaryTeamId,
                ])->log('employee_team_memberships_updated');
            DB::afterCommit(function () use ($employee, $added, $removed, $primaryChanged): void {
                foreach ($added as $team) {
                    TeamMemberAdded::dispatch($team, $employee);
                }
                foreach ($removed as $team) {
                    TeamMemberRemoved::dispatch($team, $employee);
                }
                if ($added->isEmpty() && $removed->isEmpty() && $primaryChanged) {
                    app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleForUser($employee);
                }
            });

            return true;
        }, 3);
    }

    /**
     * Replace only the prior primary organizational membership. Additional and
     * personal memberships stay intact; team ownership is never transferred.
     *
     * @param  array<int, string>  $snapshots
     */
    public function change(array $ids, int $teamId, array $snapshots): int
    {
        $ids = $this->normalizeIds($ids);

        return DB::transaction(function () use ($ids, $teamId, $snapshots): int {
            $actor = $this->authorizeActor(lock: true);
            $employees = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $this->authorizeTargets($actor, $employees, $ids);

            // Lock the membership rows as well as their teams before taking the
            // fresh fingerprint or changing any employee in the batch.
            $memberships = DB::table('team_user')->whereIn('user_id', $ids)->orderBy('user_id')->orderBy('team_id')->lockForUpdate()->get();
            $ownedTeams = Team::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $teamIds = $memberships->pluck('team_id')->merge($ownedTeams->pluck('id'))->merge($employees->pluck('current_team_id'))->push($teamId)->filter()->unique()->sort()->values();
            $teams = Team::whereIn('id', $teamIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $destination = $teams->get($teamId);
            if (! $destination || $destination->personal_team) {
                throw ValidationException::withMessages(['teamId' => 'Bitte wählen Sie ein vorhandenes organisatorisches Team.']);
            }
            abort_unless($this->canAssignTeam($actor, $destination), 403);

            foreach ($employees as $employee) {
                $this->hydrateLockedTeams($employee, $memberships->where('user_id', $employee->id), $teams);
                if (! isset($snapshots[$employee->id]) || ! is_string($snapshots[$employee->id])
                    || ! hash_equals($this->fingerprint($employee), $snapshots[$employee->id])) {
                    throw ValidationException::withMessages(['teamId' => 'Die Teamzuordnung wurde zwischenzeitlich geändert. Bitte öffnen Sie den Teamwechsel erneut.']);
                }
                $previous = $teams->get($employee->current_team_id);
                if ($previous && ! $previous->personal_team && $previous->id !== $destination->id && $employee->ownsTeam($previous)) {
                    throw ValidationException::withMessages(['teamId' => 'Ein Mitarbeiter besitzt sein bisheriges Team. Die Eigentümerschaft muss zuerst separat geklärt werden.']);
                }
                if ((int) $employee->id === (int) $actor->id && ! $actor->isAdmin()
                    && ($destination->name !== 'Verwaltung'
                        || ! ($destination->rbac_permissions['employees.view'] ?? false)
                        || ! ($destination->rbac_permissions['employees.create'] ?? false)
                        || ! ($destination->rbac_permissions['roles.manage'] ?? false))) {
                    throw ValidationException::withMessages(['teamId' => 'Sie können sich mit dieser Sammeländerung nicht den Verwaltungszugang entziehen. Bitte lassen Sie Ihr Team durch einen Administrator ändern.']);
                }
            }

            $changed = 0;
            foreach ($employees as $employee) {
                $previous = $teams->get($employee->current_team_id);
                $alreadyMember = $employee->ownsTeam($destination) || $employee->teams->contains('id', $destination->id);
                if ((int) $employee->current_team_id === (int) $destination->id && $alreadyMember) {
                    continue;
                }

                $added = false;
                $removed = false;
                if (! $alreadyMember) {
                    AddingTeamMember::dispatch($destination, $employee);
                    $employee->teams()->attach($destination->id, ['role' => 'team_access']);
                    $added = true;
                }
                if ($previous && ! $previous->personal_team && $previous->id !== $destination->id) {
                    $removed = (bool) $employee->teams()->detach($previous->id);
                }
                $employee->forceFill(['current_team_id' => $destination->id])->save();
                activity('employee-team')->causedBy($actor)->performedOn($employee)
                    ->withProperties(['previous_team_id' => $previous?->id, 'team_id' => $destination->id])
                    ->log('employee_team_changed');
                $changed++;

                DB::afterCommit(function () use ($employee, $destination, $previous, $added, $removed): void {
                    if ($added) {
                        TeamMemberAdded::dispatch($destination, $employee);
                    }
                    if ($removed) {
                        TeamMemberRemoved::dispatch($previous, $employee);
                    }
                    // A change between existing memberships has no Jetstream
                    // membership event, but still changes the active context.
                    if (! $added && ! $removed) {
                        app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleForUser($employee);
                    }
                });
            }

            return $changed;
        }, 3);
    }

    public function create(string $name): Team
    {
        return DB::transaction(function () use ($name): Team {
            $actor = $this->authorizeActor(lock: true, membership: false);
            $name = trim($name);
            Validator::make(['newTeamName' => $name], ['newTeamName' => ['required', 'string', 'max:255']])->validate();
            if (! $actor->isAdmin() && in_array(mb_strtolower($name), ['administratoren', 'administrator', 'administration'], true)) {
                throw ValidationException::withMessages(['newTeamName' => 'Dieses Verwaltungsteam kann nur von einem Administrator erstellt werden.']);
            }
            // PHP case folding also covers German names on SQLite, whose
            // built-in LOWER otherwise only folds ASCII characters.
            if (Team::orderBy('id')->lockForUpdate()->get(['name'])->contains(fn (Team $team) => mb_strtolower(trim($team->name)) === mb_strtolower($name))) {
                throw ValidationException::withMessages(['newTeamName' => 'Ein Team mit diesem Namen ist bereits vorhanden.']);
            }
            $team = new Team(['name' => $name, 'personal_team' => false, 'rbac_permissions' => RbacCatalog::defaultTeamPermissions()]);
            $team->user_id = $actor->id;
            // Unlike the general Jetstream CreateTeam action, administrative
            // creation must not silently switch the administrator's context.
            AddingTeam::dispatch($actor);
            $team->save();
            activity('employee-team')->causedBy($actor)->performedOn($team)->log('employee_team_created');

            return $team;
        }, 3);
    }

    private function authorizeActor(bool $lock = false, bool $membership = true): User
    {
        abort_unless(auth()->user() instanceof User, 403);
        $query = User::with('currentTeam')->whereKey(auth()->id());
        $actor = ($lock ? $query->lockForUpdate() : $query)->first();
        $managementAccess = false;
        if ($actor && $lock) {
            // Dashboard access can fall back to one recognized non-primary
            // membership. Older native Jetstream removals only lock/detach the
            // pivot, so hold those exact rows and names during authorization.
            $actorMemberships = DB::table('team_user')->where('user_id', $actor->id)
                ->orderBy('team_id')->lockForUpdate()->get();
            $actorTeamIds = $actorMemberships->pluck('team_id')->push($actor->current_team_id)->filter()->unique()->sort()->values();
            $actorTeams = Team::whereIn('id', $actorTeamIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = $actorTeams->get($actor->current_team_id);
            $actor->setRelation('currentTeam', $current);
            $memberTeams = new Collection($actorMemberships->map(fn ($membership) => $actorTeams->get($membership->team_id))->filter()->all());
            $managementAccess = $actor->isAdmin() || in_array($this->managementTeamName($current, $memberTeams), ['Administratoren', 'Administrator', 'Administration', 'Verwaltung'], true);
        } elseif ($actor) {
            $managementAccess = $actor->canViewManagementDashboard();
        }
        abort_unless($actor?->isActive() && $managementAccess, 403);
        Gate::forUser($actor)->authorize('roles.manage');
        if ($membership) {
            Gate::forUser($actor)->authorize('employees.view');
            Gate::forUser($actor)->authorize('employees.create');
        }

        return $actor;
    }

    /** @return array<int, int> */
    private function normalizeIds(array $ids): array
    {
        if (count($ids) < 1 || count($ids) > 100 || ! array_is_list($ids)) {
            throw ValidationException::withMessages(['employeeIds' => 'Bitte wählen Sie zwischen einem und 100 Mitarbeitern.']);
        }
        $normalized = [];
        foreach ($ids as $id) {
            if ((! is_int($id) && (! is_string($id) || ! preg_match('/^[1-9][0-9]*$/D', $id)))
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw ValidationException::withMessages(['employeeIds' => 'Die Mitarbeiterauswahl ist ungültig.']);
            }
            $normalized[] = (int) $id;
        }
        if (count($normalized) !== count(array_unique($normalized))) {
            throw ValidationException::withMessages(['employeeIds' => 'Die Mitarbeiterauswahl enthält doppelte Einträge.']);
        }
        sort($normalized);

        return $normalized;
    }

    private function authorizeTargets(User $actor, Collection $employees, array $ids): void
    {
        if ($employees->count() !== count($ids)) {
            throw ValidationException::withMessages(['employeeIds' => 'Mindestens ein Mitarbeiter ist nicht mehr verfügbar.']);
        }
        foreach ($employees as $employee) {
            abort_unless(in_array($employee->role, ['staff', 'admin', 'superadmin'], true), 403);
            abort_if(($employee->isAdmin() || $employee->isSuperAdmin()) && ! $actor->isAdmin(), 403);
        }
    }

    private function canAssignTeam(User $actor, Team $team): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if (in_array($team->name, ['Administratoren', 'Administrator', 'Administration'], true)) {
            return false;
        }
        foreach ((array) $team->rbac_permissions as $permission => $enabled) {
            if ($enabled && ! Gate::forUser($actor)->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    private function ensureSelfAccess(User $actor, Team $primary, Collection $memberships): void
    {
        $name = $this->managementTeamName($primary, $memberships);
        $projected = clone $actor;
        $projected->setRelation('currentTeam', $primary);
        $keepsRights = collect(['employees.view', 'employees.create', 'roles.manage'])
            ->every(fn (string $permission) => Gate::forUser($projected)->allows($permission));
        if (! in_array($name, ['Administratoren', 'Administrator', 'Administration', 'Verwaltung'], true) || ! $keepsRights) {
            throw ValidationException::withMessages(['primaryTeamId' => 'Sie können sich nicht den eigenen Verwaltungszugang entziehen. Bitte lassen Sie Ihre Zuordnung durch einen Administrator ändern.']);
        }
    }

    private function managementTeamName(?Team $primary, Collection $memberships): ?string
    {
        $recognized = ['Administratoren', 'Administrator', 'Administration', 'Verwaltung', 'Mitarbeiter', 'Gäste', 'Gaeste', 'Gast'];
        if ($primary && ! $primary->personal_team && in_array($primary->name, $recognized, true)) {
            return $primary->name;
        }
        $recognizedMemberships = $memberships->filter(fn (Team $team) => ! $team->personal_team && in_array($team->name, $recognized, true));

        return $recognizedMemberships->count() === 1 ? $recognizedMemberships->first()->name : null;
    }

    /** Use current locked reads, not a possibly older REPEATABLE READ snapshot. */
    private function hydrateLockedTeams(User $user, \Illuminate\Support\Collection $memberships, Collection $teams): void
    {
        $memberTeams = new Collection;
        foreach ($memberships as $membership) {
            if (! ($stored = $teams->get($membership->team_id))) {
                continue;
            }
            $team = clone $stored;
            $team->setRelation('membership', $team->newPivot($user, (array) $membership, 'team_user', true));
            $memberTeams->push($team);
        }
        $user->setRelation('teams', $memberTeams);
        $user->setRelation('ownedTeams', $teams->filter(fn (Team $team) => (int) $team->user_id === (int) $user->id)->values());
        $user->setRelation('currentTeam', $teams->get($user->current_team_id));
    }

    /** @return array<int, int> */
    private function normalizeMembershipIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            return $this->normalizeIds($ids);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['teamIds' => 'Bitte wählen Sie höchstens 100 unterschiedliche, gültige Teams.']);
        }
    }

    private function fingerprint(User $user): string
    {
        return hash('sha256', json_encode([
            'primary' => $user->current_team_id,
            'role' => $user->role,
            'memberships' => $user->teams->sortBy('id')->map(fn (Team $team) => [$team->id, $team->name, $team->membership?->role, $team->user_id, $team->personal_team, $team->rbac_permissions])->values()->all(),
            'primary_team' => $user->currentTeam ? [$user->currentTeam->id, $user->currentTeam->name, $user->currentTeam->user_id, $user->currentTeam->personal_team, $user->currentTeam->rbac_permissions] : null,
            'owned' => $user->ownedTeams->sortBy('id')->map(fn (Team $team) => [$team->id, $team->name, $team->user_id, $team->personal_team, $team->rbac_permissions])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
