<?php

namespace App\Services\Operations;

use App\Models\OperationsAttentionItem;
use App\Models\OperationsMonitorProfile;
use App\Models\ShiftAssignment;
use App\Models\StaffingCase;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PersonalSchedule;
use App\Support\Operations\PlanningLocks;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OperationsDutyMonitorService
{
    public const LABELS = ['planned' => 'Geplant', 'start_due' => 'Startmeldung ausstehend', 'start_missing' => 'Startmeldung fehlt', 'running' => 'Im Dienst', 'end_overdue' => 'Endmeldung fehlt', 'ended' => 'Beendet', 'unconfigured' => 'Toleranz offen', 'needs_review' => 'Veröffentlichungsstand prüfen'];

    public function ready(): bool
    {
        return Schema::hasColumns('operations_monitor_profiles', ['id', 'name', 'start_grace_minutes', 'end_grace_minutes', 'responsible_user_id', 'auto_cases', 'is_active', 'revision', 'approved_by', 'created_at', 'updated_at']) && Schema::hasColumns('operations_attention_locks', ['key']) && app(OperationsReminderService::class)->ready();
    }

    public function saveProfile(array $data, User $actor, ?int $id = null, ?int $revision = null): OperationsMonitorProfile
    {
        $actor = User::findOrFail($actor->id);
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.rules.manage');
        abort_unless($this->ready(), 503);
        $data = Validator::make($data, ['name' => 'required|string|max:100', 'start_grace_minutes' => 'required|integer|min:0|max:1440', 'end_grace_minutes' => 'required|integer|min:0|max:1440', 'responsible_user_id' => 'required|integer|exists:users,id', 'auto_cases' => 'required|boolean', 'is_active' => 'required|boolean'])->validate();
        OperationsAccess::authorize(User::findOrFail($data['responsible_user_id']), 'operations.manage');

        return OperationsTransaction::run(function () use ($data, $actor, $id, $revision) {
            DB::table('operations_attention_locks')->insertOrIgnore(['key' => 'monitor-profiles']);
            DB::table('operations_attention_locks')->where('key', 'monitor-profiles')->lockForUpdate()->first();
            User::lockForUpdate()->findOrFail($actor->id);
            $profiles = OperationsMonitorProfile::orderBy('id')->lockForUpdate()->get();
            $record = $id ? $profiles->firstWhere('id', $id) : new OperationsMonitorProfile;
            abort_unless($record, 404);
            if ($id && $record->revision !== $revision) {
                throw ValidationException::withMessages(['workflow' => 'Leitstellenprofil wurde geändert.']);
            }
            if ($data['is_active']) {
                foreach ($profiles->where('is_active', true) as $other) {
                    if ($other->id !== $id) {
                        $other->forceFill(['is_active' => false, 'revision' => $other->revision + 1])->save();
                    }
                }
            }
            $record->fill($data + ['approved_by' => $actor->id]);
            $record->revision = $id ? $record->revision + 1 : 1;
            $record->save();
            app(OperationsAuditService::class)->record($record, $actor, 'monitor.profile.saved');

            return $record;
        }, 3);
    }

    /** A missing server receipt is not proof of absence, particularly offline. */
    public function board(User $actor, string $from, string $until): Collection
    {
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.manage');
        OperationsAccess::requireReady();
        Validator::make(compact('from', 'until'), ['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $start = CarbonImmutable::parse($from, $zone);
        $end = CarbonImmutable::parse($until, $zone)->addDay();
        abort_if($start->diffInDays($end) > 31, 422);
        $profile = $this->ready() ? OperationsMonitorProfile::where('is_active', true)->first() : null;

        return ShiftAssignment::where('status', 'confirmed')->whereHas('shift', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed'])->where('published_revision', '>', 0)->whereColumn('shifts.published_revision', 'shift_assignments.plan_revision')->where(fn ($q) => $q->whereColumn('shifts.revision', '!=', 'shifts.published_revision')->orWhere(fn ($q) => $q->during($start, $end))))
            ->with(['shift', 'user', 'timeEntry'])->get()
            ->map(fn ($assignment) => $this->row($assignment, $profile))
            ->filter(fn ($row) => $row !== null && ($row['state'] === 'needs_review' || ($row['starts_at']->lt($end) && $row['ends_at']->gt($start))))
            ->sortBy(fn ($row) => [in_array($row['state'], ['start_missing', 'end_overdue', 'needs_review'], true) ? 0 : 1, $row['starts_at']?->timestamp ?? PHP_INT_MAX])->values();
    }

    public function escalate(int $assignmentId, int $planRevision, User $actor): StaffingCase
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.manage');
        abort_unless($this->ready(), 503);

        return OperationsTransaction::run(function () use ($assignmentId, $planRevision, $actor) {
            $assignment = ShiftAssignment::findOrFail($assignmentId);
            PlanningLocks::acquire([$assignment->shift_id], [$assignment->user_id]);
            $assignment = ShiftAssignment::where('status', 'confirmed')->with(['shift.order.customer', 'user', 'timeEntry'])->lockForUpdate()->findOrFail($assignmentId);
            $profile = OperationsMonitorProfile::where('is_active', true)->lockForUpdate()->firstOrFail();
            if ($assignment->plan_revision !== $planRevision || $assignment->shift->published_revision !== $planRevision || in_array($assignment->shift->status->value, ['cancelled', 'completed'], true)) {
                throw ValidationException::withMessages(['workflow' => 'Dienststand wurde geändert.']);
            }
            $row = $this->row($assignment, $profile);
            if (! $row || ! in_array($row['state'], ['start_missing', 'end_overdue'], true)) {
                throw ValidationException::withMessages(['workflow' => 'Es liegt keine überfällige Meldung vor.']);
            }
            $key = hash('sha256', 'monitor:'.$profile->id.':'.$assignment->id.':'.$planRevision.':'.$row['state']);
            $existing = OperationsAttentionItem::where('recipient_user_id', $profile->responsible_user_id)->where('source_key', $key)->first();
            if ($existing) {
                return StaffingCase::findOrFail($existing->record_id);
            }
            $case = app(WorkforcePlanningService::class)->openCase([
                'shift_id' => $assignment->shift_id, 'shift_assignment_id' => $assignment->id, 'kind' => $row['state'] === 'end_overdue' ? 'relief' : 'callout',
                'responsible_id' => $profile->responsible_user_id, 'due_at' => now('UTC')->format('Y-m-d\TH:i'), 'timezone' => 'UTC',
                'note' => $row['state'] === 'end_overdue' ? 'Endmeldung fehlt. Rückfrage und tatsächlichen Dienststand prüfen.' : 'Startmeldung fehlt. Kontakt und Offline-Synchronisation prüfen.',
            ], $actor);
            OperationsAttentionItem::create(['recipient_user_id' => $profile->responsible_user_id, 'subject_user_id' => $assignment->user_id, 'source_key' => $key, 'kind' => 'monitor_case', 'headline' => $row['title'].' · Meldung prüfen', 'module' => 'workforce-planning', 'record_id' => $case->id, 'source_revision' => $planRevision, 'due_at' => now()->utc()]);

            return $case;
        }, 3);
    }

    public function runScheduled(): int
    {
        if (! $this->ready() || ! WorkforcePlanningSchema::ready()) {
            return 0;
        }
        $profile = OperationsMonitorProfile::where('is_active', true)->where('auto_cases', true)->first();
        if (! $profile) {
            return 0;
        }
        $actor = User::find($profile->responsible_user_id);
        if (! $actor || ! $actor->status || ! $actor->can('operations.manage')) {
            return 0;
        }
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $count = 0;
        foreach ($this->board($actor, now($zone)->subDay()->toDateString(), now($zone)->toDateString()) as $row) {
            if (! in_array($row['state'], ['start_missing', 'end_overdue'], true)) {
                continue;
            }
            try {
                $case = $this->escalate($row['assignment_id'], $row['plan_revision'], $actor);
                if ($case->wasRecentlyCreated) {
                    $count++;
                }
            } catch (ValidationException|ModelNotFoundException $error) {
                continue;
            }
        }

        return $count;
    }

    private function row(ShiftAssignment $assignment, ?OperationsMonitorProfile $profile): ?array
    {
        $window = app(PersonalSchedule::class)->publishedWindow($assignment);
        if (! $window) {
            return ['id' => 'duty-'.$assignment->id, 'assignment_id' => $assignment->id, 'user_id' => $assignment->user_id, 'user_name' => $assignment->user?->name, 'title' => 'Freigegebener Dienststand fehlt', 'starts_at' => null, 'ends_at' => null, 'actual_start' => $assignment->timeEntry?->starts_at, 'actual_end' => $assignment->timeEntry?->ends_at, 'plan_revision' => $assignment->plan_revision, 'state' => 'needs_review', 'status' => self::LABELS['needs_review'], 'shift_id' => $assignment->shift_id];
        }
        $now = CarbonImmutable::now('UTC');
        $entry = $assignment->timeEntry;
        $state = match (true) {
            $entry?->ends_at !== null => 'ended',
            $entry !== null && $profile !== null && $now->gt($window['ends_at']->addMinutes($profile->end_grace_minutes)) => 'end_overdue',
            $entry !== null => 'running',
            $now->lt($window['starts_at']) => 'planned',
            ! $profile => 'unconfigured',
            $now->gt($window['starts_at']->addMinutes($profile->start_grace_minutes)) => 'start_missing',
            default => 'start_due',
        };

        return ['id' => 'duty-'.$assignment->id, 'assignment_id' => $assignment->id, 'user_id' => $assignment->user_id, 'user_name' => $assignment->user?->name, 'title' => $window['title'],
            'starts_at' => $window['starts_at'], 'ends_at' => $window['ends_at'], 'actual_start' => $entry?->starts_at, 'actual_end' => $entry?->ends_at,
            'plan_revision' => $assignment->plan_revision, 'state' => $state, 'status' => self::LABELS[$state], 'shift_id' => $assignment->shift_id];
    }
}
