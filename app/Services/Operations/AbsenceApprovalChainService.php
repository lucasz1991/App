<?php

namespace App\Services\Operations;

use App\Models\AbsenceApprovalPolicy;
use App\Models\AbsenceApprovalStep;
use App\Models\AbsenceRequest;
use App\Models\User;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AbsenceApprovalChainService
{
    public function ready(): bool
    {
        return Schema::hasColumns('absence_approval_policies', ['revision', 'payload', 'status', 'title', 'created_by', 'approved_by', 'approved_at']) && Schema::hasColumns('absence_approval_steps', ['absence_request_id', 'position', 'reviewer_id', 'delegate_id', 'decided_by', 'due_at', 'decided_at', 'status', 'snapshot', 'revision']) && Schema::hasColumns('personnel_enhancement_locks', ['lock_key']);
    }

    public function create(array $data, User $actor): AbsenceApprovalPolicy
    {
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.rules.manage');
        abort_unless($this->ready(), 503);
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'kind' => 'required|in:vacation,unavailable,other', 'team_id' => 'nullable|integer|exists:teams,id', 'minimum_days' => 'required|integer|between:1,366', 'maximum_days' => 'required|integer|gte:minimum_days|lte:366', 'stages' => 'required|array|min:1|max:6', 'stages.*.reviewer_id' => 'required|integer|exists:users,id', 'stages.*.delegate_id' => 'nullable|integer|exists:users,id', 'stages.*.hours' => 'required|integer|between:1,720'])->validate();
        $this->check(collect($data['stages'])->pluck('reviewer_id')->map(fn ($id) => (int) $id)->unique()->count() === count($data['stages']), 'Jede Stufe benötigt eine andere Hauptfreigabeperson.');
        foreach ($data['stages'] as $stage) {
            foreach (array_filter([$stage['reviewer_id'], $stage['delegate_id'] ?? null]) as $id) {
                $reviewer = User::findOrFail($id);
                abort_unless($reviewer->status && Gate::forUser($reviewer)->allows('operations.absences.review'), 422, 'Freigabeperson benötigt die Abwesenheitsberechtigung.');
            }
        }

        return OperationsTransaction::run(function () use ($data, $actor) {
            $record = AbsenceApprovalPolicy::create(['title' => $data['title'], 'payload' => $data, 'created_by' => $actor->id]);
            app(OperationsAuditService::class)->record($record, $actor, 'absence_chain.drafted');

            return $record->fresh();
        });
    }

    public function activate(AbsenceApprovalPolicy $policy, int $revision, User $actor): void
    {
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.rules.manage');
        OperationsTransaction::run(function () use ($policy, $revision, $actor): void {
            abort_unless($this->ready(), 503);
            abort_unless(DB::table('personnel_enhancement_locks')->where('lock_key', 'absence-policy')->lockForUpdate()->first(), 503);
            $record = AbsenceApprovalPolicy::lockForUpdate()->findOrFail($policy->id);
            abort_if((int) $record->created_by === (int) $actor->id, 403);
            $this->check($record->status === 'draft' && $record->revision === $revision, 'Freigabekette wurde geändert.');
            $p = $record->payload;
            $overlap = AbsenceApprovalPolicy::where('status', 'active')->get()->contains(function ($existing) use ($p) {
                $q = $existing->payload;

                return $q['kind'] === $p['kind'] && $q['minimum_days'] <= $p['maximum_days'] && $q['maximum_days'] >= $p['minimum_days'] && (! ($q['team_id'] ?? null) || ! ($p['team_id'] ?? null) || (int) $q['team_id'] === (int) $p['team_id']);
            });
            $this->check(! $overlap, 'Eine aktive Freigabekette überschneidet sich; zuerst bisherigen Stand beenden.');
            $record->forceFill(['status' => 'active', 'approved_by' => $actor->id, 'approved_at' => now()->utc(), 'revision' => $revision + 1])->save();
            app(OperationsAuditService::class)->record($record, $actor, 'absence_chain.activated');
        });
    }

    public function retire(AbsenceApprovalPolicy $policy, int $revision, string $note, User $actor): void
    {
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.rules.manage');
        Validator::make(['note' => $note], ['note' => 'required|string|min:5|max:1000'])->validate();
        OperationsTransaction::run(function () use ($policy, $revision, $note, $actor): void {
            abort_unless($this->ready(), 503);
            abort_unless(DB::table('personnel_enhancement_locks')->where('lock_key', 'absence-policy')->lockForUpdate()->first(), 503);
            $record = AbsenceApprovalPolicy::lockForUpdate()->findOrFail($policy->id);
            $this->check($record->status === 'active' && $record->revision === $revision, 'Freigabekette wurde geändert.');
            $record->forceFill(['status' => 'retired', 'revision' => $revision + 1])->save();
            app(OperationsAuditService::class)->record($record, $actor, 'absence_chain.retired', ['note' => $note]);
            // Existing request steps retain their immutable policy snapshot.
        });
    }

    /** Called inside the absence/user transaction. Existing requests remain unaltered. */
    public function attach(AbsenceRequest $request): void
    {
        if (! $this->ready() || $request->kind === 'sick') {
            return;
        }
        $user = User::findOrFail($request->user_id);
        $zone = $request->timezone;
        $days = (int) ceil($request->starts_at->setTimezone($zone)->startOfDay()->diffInDays($request->ends_at->setTimezone($zone)));
        $matching = AbsenceApprovalPolicy::where('status', 'active')->orderBy('id')->get()->filter(function ($record) use ($request, $user, $days) {
            $p = $record->payload;

            return $p['kind'] === $request->kind && $days >= $p['minimum_days'] && $days <= $p['maximum_days'] && (! ($p['team_id'] ?? null) || $user->teams()->where('teams.id', $p['team_id'])->exists());
        });
        $this->check($matching->count() <= 1, 'Mehrere Freigabeketten passen; Personal muss die Zuordnung prüfen.');
        if (! $policy = $matching->first()) {
            return;
        }
        foreach ($policy->payload['stages'] as $i => $stage) {
            $this->check((int) $stage['reviewer_id'] !== (int) $request->user_id && (int) ($stage['delegate_id'] ?? 0) !== (int) $request->user_id, 'Selbstfreigabe ist in der Freigabekette nicht erlaubt.');
            app(PersonnelScopeService::class)->authorize(User::findOrFail($stage['reviewer_id']), (int) $request->user_id, 'operations.absences.review');
            if ($stage['delegate_id'] ?? null) {
                app(PersonnelScopeService::class)->authorize(User::findOrFail($stage['delegate_id']), (int) $request->user_id, 'operations.absences.review');
            }
            AbsenceApprovalStep::create(['absence_request_id' => $request->id, 'position' => $i + 1, 'reviewer_id' => $stage['reviewer_id'], 'delegate_id' => $stage['delegate_id'] ?? null, 'status' => $i === 0 ? 'open' : 'waiting', 'due_at' => now()->utc()->addHours($stage['hours']), 'snapshot' => ['policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'hours' => $stage['hours']]]);
        }
    }

    /** True means the last configured stage passed, or no chain is configured. */
    public function advance(AbsenceRequest $request, User $actor): bool
    {
        if (! $this->ready()) {
            abort_if(Schema::hasTable('absence_approval_policies') || Schema::hasTable('absence_approval_steps'), 503, 'Freigabeketten müssen zuerst vollständig geprüft werden.');

            return true;
        }
        $steps = AbsenceApprovalStep::where('absence_request_id', $request->id)->orderBy('position')->lockForUpdate()->get();
        if ($steps->isEmpty()) {
            return true;
        }
        $step = $steps->firstWhere('status', 'open');
        $this->check($step !== null, 'Keine offene Freigabestufe.');
        abort_unless(in_array((int) $actor->id, [(int) $step->reviewer_id, (int) $step->delegate_id], true), 403);
        $this->check(! $steps->where('status', 'approved')->contains(fn ($previous) => (int) $previous->decided_by === (int) $actor->id), 'Jede Stufe benötigt eine andere Freigabeperson.');
        $used = $steps->where('status', 'approved')->pluck('decided_by')->map(fn ($id) => (int) $id)->push((int) $actor->id)->all();
        $future = $steps->where('status', 'waiting')->values()->all();
        $this->check($this->distinctFutureReviewers($future, $used, (int) $request->user_id), 'Diese Vertretung blockiert eine spätere Stufe; andere Freigabeperson benötigt.');
        $step->forceFill(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now()->utc(), 'revision' => $step->revision + 1])->save();
        app(OperationsAuditService::class)->record($step, $actor, 'absence_chain.stage_approved');
        if ($next = $steps->firstWhere('status', 'waiting')) {
            $next->forceFill(['status' => 'open', 'due_at' => now()->utc()->addHours($next->snapshot['hours']), 'revision' => $next->revision + 1])->save();

            return false;
        }

        return true;
    }

    public function authorizeDecision(AbsenceRequest $request, User $actor): void
    {
        if (! $this->ready()) {
            abort_if(Schema::hasTable('absence_approval_policies') || Schema::hasTable('absence_approval_steps'), 503, 'Freigabeketten müssen zuerst vollständig geprüft werden.');

            return;
        }
        $steps = AbsenceApprovalStep::where('absence_request_id', $request->id);
        if ($steps->exists()) {
            $step = $steps->where('status', 'open')->first();
            abort_unless($step && in_array((int) $actor->id, [(int) $step->reviewer_id, (int) $step->delegate_id], true), 403);
        }
    }

    public function close(AbsenceRequest $request, string $status): void
    {
        if ($this->ready()) {
            AbsenceApprovalStep::where('absence_request_id', $request->id)->whereIn('status', ['open', 'waiting'])->update(['status' => $status]);
        }
    }

    public function inbox(User $actor): Collection
    {
        if (! $this->ready() || ! $actor->status || ! Gate::forUser($actor)->allows('operations.absences.review')) {
            return collect();
        }

        return AbsenceApprovalStep::where('status', 'open')->where(fn ($q) => $q->where('reviewer_id', $actor->id)->orWhere('delegate_id', $actor->id))->get()->flatMap(function ($step) use ($actor) {
            $request = AbsenceRequest::find($step->absence_request_id);
            if (! $request || $request->status !== 'pending' || (int) $request->user_id === (int) $actor->id || ! app(PersonnelScopeService::class)->allows($actor, (int) $request->user_id, 'operations.absences.review')) {
                return [];
            }

            return [['id' => $request->id, 'kind' => 'absence_stage', 'title' => 'Abwesenheit · Freigabestufe '.$step->position, 'user_id' => $request->user_id, 'due_on' => $step->due_at->toDateString(), 'status' => $step->due_at->isPast() ? 'overdue' : 'open', 'revision' => $request->revision, 'target_tab' => 'approvals']];
        });
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }

    private function distinctFutureReviewers(array $steps, array $used, int $userId): bool
    {
        if ($steps === []) {
            return true;
        }
        $step = array_shift($steps);
        foreach (array_unique(array_filter([(int) $step->reviewer_id, (int) $step->delegate_id])) as $id) {
            if (in_array($id, $used, true) || $id === $userId) {
                continue;
            }
            $candidate = User::find($id);
            if ($candidate && app(PersonnelScopeService::class)->allows($candidate, $userId, 'operations.absences.review') && $this->distinctFutureReviewers($steps, [...$used, $id], $userId)) {
                return true;
            }
        }

        return false;
    }
}
