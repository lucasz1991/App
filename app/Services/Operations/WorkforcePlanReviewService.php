<?php

namespace App\Services\Operations;

use App\Models\EmployeeRuleAssignment;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\PersonnelPlanReview;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkforcePlanReviewService
{
    public function ready(): bool
    {
        return Schema::hasTable('personnel_plan_reviews') && OperationsAccess::ready();
    }

    /** Caller holds the employee lock after a real, authorised configuration change. */
    public function recheckForEmployee(User|int $user, Model $source, User $actor): array
    {
        $user = User::findOrFail($user instanceof User ? $user->id : $user);
        $this->authorizeSource($user, $source, $actor);
        if (! $this->ready()) {
            $this->check(! ShiftAssignment::blocking()->where('user_id', $user->id)->whereHas('shift', fn ($query) => $query->notCancelled()->whereNotIn('status', ['completed', 'in_progress'])->where('starts_at', '>', now()->utc())->where('published_revision', '>', 0)->whereNotNull('published_at'))->exists(), 'Planprüfung ist noch nicht verfügbar.');

            return ['available' => false, 'checked' => 0, 'case_ids' => []];
        }

        return OperationsTransaction::run(function () use ($user, $source, $actor) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $source = $source::findOrFail($source->id);
            $this->authorizeSource($user, $source, $actor);
            $origin = $this->origin($source);
            $cases = [];
            $assignments = ShiftAssignment::blocking()->where('user_id', $user->id)->whereHas('shift', fn ($query) => $query->notCancelled()->whereNotIn('status', ['completed', 'in_progress'])->where('starts_at', '>', now()->utc())->where('published_revision', '>', 0)->whereNotNull('published_at'))->with('shift')->orderBy('id')->get();
            foreach ($assignments as $assignment) {
                $snapshot = $this->assessment($assignment, $user);
                if ($snapshot['issues'] === []) {
                    continue;
                }
                $key = hash('sha256', json_encode([$user->id, $assignment->id, $assignment->shift->published_revision, $origin], JSON_THROW_ON_ERROR));
                $case = PersonnelPlanReview::where('assessment_key', $key)->first();
                if (! $case) {
                    $case = PersonnelPlanReview::create(['user_id' => $user->id, 'shift_id' => $assignment->shift_id, 'shift_assignment_id' => $assignment->id, 'plan_revision' => $assignment->shift->published_revision, 'origin_type' => $origin['type'], 'origin_id' => $source->id, 'origin_revision' => $origin['revision'], 'origin_snapshot' => $origin['data'], 'assessment_key' => $key, 'initial_snapshot' => $snapshot, 'latest_snapshot' => $snapshot, 'status' => $this->classification($snapshot['issues']), 'created_by' => $actor->id]);
                    app(OperationsAuditService::class)->record($case, $actor, 'personnel_plan_review.opened', ['source' => $origin['type'], 'source_id' => $source->id, 'issues' => $snapshot['issues']]);
                }
                $cases[] = (int) $case->id;
            }

            return ['available' => true, 'checked' => $assignments->count(), 'case_ids' => $cases];
        }, 3);
    }

    public function reassess(PersonnelPlanReview $review, int $revision, string $note, User $actor): void
    {
        $review = PersonnelPlanReview::findOrFail($review->id);
        app(PersonnelScopeService::class)->authorize($actor, (int) $review->user_id, 'employees.master-data.edit');
        abort_if((int) $actor->id === (int) $review->user_id, 403);
        abort_unless($this->ready(), 503);
        Validator::make(['note' => $note], ['note' => 'required|string|min:5|max:1000'])->validate();

        OperationsTransaction::run(function () use ($review, $revision, $note, $actor): void {
            // Only the employee and review change: no user→shift lock inversion.
            User::lockForUpdate()->findOrFail($review->user_id);
            $record = PersonnelPlanReview::lockForUpdate()->findOrFail($review->id);
            app(PersonnelScopeService::class)->authorize($actor, (int) $record->user_id, 'employees.master-data.edit');
            abort_if((int) $actor->id === (int) $record->user_id, 403);
            $this->check($record->revision === $revision && in_array($record->status, ['review', 'conflict', 'stale'], true), 'Prüffall wurde bereits bearbeitet.');
            $assignment = ShiftAssignment::with(['shift' => fn ($query) => $query->withTrashed()])->findOrFail($record->shift_assignment_id);
            $this->check((int) $assignment->user_id === (int) $record->user_id, 'Zuweisung gehört nicht mehr zum Prüffall.');
            $snapshot = $this->assessment($assignment, User::findOrFail($record->user_id));
            if ($snapshot['issues'] === []) {
                abort_if((int) $record->created_by === (int) $actor->id, 403, 'Eine andere Person muss den Prüffall abschließen.');
            }
            $before = $record->latest_snapshot;
            $record->forceFill(['latest_snapshot' => $snapshot, 'status' => $snapshot['issues'] === [] ? 'resolved' : $this->classification($snapshot['issues']), 'revision' => $revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'review_note' => $note])->save();
            app(OperationsAuditService::class)->record($record, $actor, 'personnel_plan_review.reassessed', ['before' => $before, 'assessment' => $snapshot, 'note' => $note]);
        }, 3);
    }

    private function assessment(ShiftAssignment $assignment, User $user): array
    {
        $shift = $assignment->shift;
        $snapshot = ['at' => now()->utc()->toIso8601String(), 'shift' => ['id' => $shift->id, 'public_id' => $shift->public_id, 'title' => $shift->title, 'starts_at' => $shift->starts_at->toIso8601String(), 'ends_at' => $shift->ends_at->toIso8601String(), 'timezone' => $shift->timezone, 'revision' => $shift->revision, 'published_revision' => $shift->published_revision], 'assignment' => ['id' => $assignment->id, 'status' => $assignment->status->value, 'plan_revision' => $assignment->plan_revision], 'issues' => []];
        $accounts = app(WorkforceAccountService::class);
        $model = $accounts->effectiveModel($user, $shift->starts_at);
        $rules = $accounts->effectiveRules($user, $shift->starts_at);
        $snapshot['basis'] = ['work_model_id' => $model?->id, 'work_model_revision' => $model?->revision, 'rule_profile_id' => $rules?->id, 'minimum_rest_minutes' => $rules?->minimum_rest_minutes, 'maximum_shift_minutes' => $rules?->maximum_shift_minutes, 'break_after_minutes' => $rules?->break_after_minutes, 'minimum_break_minutes' => $rules?->minimum_break_minutes];
        if ($shift->trashed() || in_array($shift->status->value, ['cancelled', 'completed'], true) || ! $assignment->status->blocksAvailability()) {
            $snapshot['inactive'] = true;

            return $snapshot;
        }
        if (! $shift->starts_at->isFuture()) {
            $snapshot['issues'][] = ['code' => 'plan_already_started', 'message' => 'Begonnener Dienst benötigt eine operative Prüfung.'];
        } elseif ($shift->published_revision <= 0 || ! $shift->published_at || $shift->revision !== $shift->published_revision || $assignment->plan_revision !== $shift->published_revision) {
            $snapshot['issues'][] = ['code' => 'plan_revision_changed', 'message' => 'Aktueller Dienststand ist noch nicht verbindlich veröffentlicht.'];
        } else {
            $snapshot['issues'] = app(StaffEligibilityService::class)->assessMany($shift, collect([$user]))[$user->id] ?? [['code' => 'eligibility_unknown', 'message' => 'Eignungsprüfung ist nicht verfügbar.']];
        }

        return $snapshot;
    }

    private function classification(array $issues): string
    {
        if (in_array('plan_revision_changed', array_column($issues, 'code'), true)) {
            return 'stale';
        }
        $unknown = ['rules_missing', 'contract_missing', 'contract_weekly_allocation_unknown', 'training_rules_missing', 'eligibility_unknown', 'plan_already_started'];

        return collect($issues)->contains(fn ($issue) => ! in_array($issue['code'], $unknown, true)) ? 'conflict' : 'review';
    }

    private function authorizeSource(User $user, Model $source, User $actor): void
    {
        if ($source instanceof OperationsRuleProfile) {
            app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.rules.manage');
        } else {
            abort_unless(($source instanceof EmployeeWorkModel || $source instanceof EmployeeRuleAssignment) && (int) $source->user_id === (int) $user->id, 403);
            app(PersonnelScopeService::class)->authorize($actor, $user, 'employees.master-data.edit');
            if ($source instanceof EmployeeRuleAssignment) {
                app(PersonnelScopeService::class)->authorize($actor, $user, 'operations.rules.manage');
            }
        }
    }

    private function origin(Model $source): array
    {
        $type = match (true) {
            $source instanceof EmployeeWorkModel => 'work_model',
            $source instanceof EmployeeRuleAssignment => 'rule_assignment',
            default => 'global_rules',
        };

        return ['type' => $type, 'id' => (int) $source->id, 'revision' => (int) ($source->revision ?? 1), 'data' => array_intersect_key($source->attributesToArray(), array_flip(['id', 'user_id', 'revision', 'name', 'starts_on', 'ends_on', 'status', 'timezone', 'weekly_target_minutes', 'maximum_weekly_minutes', 'daily_minutes', 'work_windows', 'valuation_rules', 'operations_rule_profile_id', 'minimum_rest_minutes', 'maximum_shift_minutes', 'break_after_minutes', 'minimum_break_minutes']))];
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workforce' => $message]);
        }
    }
}
