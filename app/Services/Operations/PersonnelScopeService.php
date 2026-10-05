<?php

namespace App\Services\Operations;

use App\Models\PersonnelResponsibility;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PersonnelScopeService
{
    public const ABILITIES = ['employees.master-data.view', 'employees.master-data.edit', 'operations.rules.manage', 'operations.absences.review', 'operations.qualifications.manage', 'operations.time.review', 'operations.time.export'];

    public function ready(): bool
    {
        return Schema::hasTable('personnel_responsibilities');
    }

    /** Null retains the existing global Gate scope; an empty list grants no targets. */
    public function visibleUserIds(User $actor, string $ability): ?array
    {
        OperationsAccess::authorize($actor, $ability);
        if ($actor->isAdmin() || ! Schema::hasTable('personnel_responsibilities') || ! PersonnelResponsibility::where('responsible_user_id', $actor->id)->exists()) {
            return null;
        }
        $today = now(config('operations.display_timezone', 'Europe/Berlin'))->toDateString();

        return PersonnelResponsibility::where('responsible_user_id', $actor->id)->where('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->get()->filter(fn ($row) => in_array($ability, $row->abilities, true))->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function authorize(User $actor, User|int $target, string $ability): void
    {
        $ids = $this->visibleUserIds($actor, $ability);
        abort_unless($ids === null || in_array($target instanceof User ? $target->id : $target, $ids, true), 403);
    }

    public function allows(User $actor, User|int $target, string $ability): bool
    {
        if (! $actor->status || ! Gate::forUser($actor)->allows($ability)) {
            return false;
        }
        $ids = $this->visibleUserIds($actor, $ability);

        return $ids === null || in_array($target instanceof User ? $target->id : $target, $ids, true);
    }

    public function authorizeGlobal(User $actor, string $ability): void
    {
        abort_unless($this->visibleUserIds($actor, $ability) === null, 403, 'Globale Änderungen sind nicht Teil einer Personen-Zuständigkeit.');
    }

    public function applyUsers(Builder|QueryBuilder $query, User $actor, string $ability, string $column = 'id'): Builder|QueryBuilder
    {
        $ids = $this->visibleUserIds($actor, $ability);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    public function applyRelatedQuery(Builder|QueryBuilder $query, User $actor, string $ability, string $column = 'user_id'): Builder|QueryBuilder
    {
        return $this->applyUsers($query, $actor, $ability, $column);
    }

    public function assign(User $target, array $data, User $actor): PersonnelResponsibility
    {
        abort_unless($actor->status && $actor->isAdmin() && Schema::hasTable('personnel_responsibilities'), 403);
        $data = Validator::make($data, ['responsible_user_id' => 'required|integer|exists:users,id', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'abilities' => 'required|array|min:1', 'abilities.*' => ['required', Rule::in(self::ABILITIES)]])->validate();

        return OperationsTransaction::run(function () use ($target, $data, $actor) {
            User::lockForUpdate()->findOrFail($data['responsible_user_id']);
            $record = PersonnelResponsibility::create($data + ['user_id' => $target->id, 'created_by' => $actor->id]);
            app(OperationsAuditService::class)->record($record, $actor, 'personnel_scope.assigned', $data);

            return $record;
        }, 3);
    }
}
