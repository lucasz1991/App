<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\PersonnelTask;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PersonnelProcessService
{
    public function __construct(private OperationsAuditService $audit) {}

    public function ready(): bool
    {
        return Schema::hasTable('personnel_tasks') && Schema::hasTable('personnel_trainings') && Schema::hasTable('personnel_training_participants');
    }

    public function createTask(User $employee, array $data, User $actor): PersonnelTask
    {
        $this->manage($actor, $employee);
        $data = Validator::make($data, ['assigned_to' => 'required|integer|exists:users,id', 'type' => 'required|in:onboarding,offboarding,general', 'title' => 'required|string|max:180', 'due_on' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:1000'])->validate();
        $this->check(User::findOrFail($data['assigned_to'])->status, 'Verantwortliche Person ist nicht aktiv.');

        return OperationsTransaction::run(function () use ($employee, $data, $actor) {
            User::lockForUpdate()->findOrFail($employee->id);
            $record = PersonnelTask::create($data + ['user_id' => $employee->id, 'created_by' => $actor->id]);
            $this->audit->record($record, $actor, 'personnel_task.created', $data);

            return $record;
        }, 3);
    }

    public function completeTask(PersonnelTask $task, int $revision, string $note, User $actor): void
    {
        abort_unless($this->ready(), 503);
        abort_unless($actor->status, 403);
        abort_unless((int) $task->assigned_to === (int) $actor->id || $actor->isAdmin() || app(PersonnelEnhancementService::class)->delegateCanComplete($task, $actor), 403);
        if ((int) $task->user_id === (int) $actor->id) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            app(PersonnelScopeService::class)->authorize($actor, (int) $task->user_id, 'employees.master-data.view');
        }
        Validator::make(['note' => $note], ['note' => 'nullable|string|max:1000'])->validate();
        OperationsTransaction::run(function () use ($task, $revision, $note, $actor): void {
            User::lockForUpdate()->findOrFail($task->user_id);
            $record = PersonnelTask::lockForUpdate()->findOrFail($task->id);
            $this->check($record->status === 'open' && $record->revision === $revision, 'Aufgabe wurde bereits geändert.');
            app(PersonnelEnhancementService::class)->checkTaskDependency($record);
            $record->forceFill(['status' => 'done', 'revision' => $revision + 1, 'completed_by' => $actor->id, 'completed_at' => now()->utc()])->save();
            app(PersonnelEnhancementService::class)->syncWorkflow($record);
            $this->audit->record($record, $actor, 'personnel_task.completed', ['note' => $note]);
        }, 3);
    }

    public function cancelTask(PersonnelTask $task, int $revision, string $note, User $actor): void
    {
        $this->manage($actor, (int) $task->user_id);
        Validator::make(['note' => $note], ['note' => 'required|string|min:5|max:1000'])->validate();
        OperationsTransaction::run(function () use ($task, $revision, $note, $actor): void {
            User::lockForUpdate()->findOrFail($task->user_id);
            $record = PersonnelTask::lockForUpdate()->findOrFail($task->id);
            $this->check($record->status === 'open' && $record->revision === $revision, 'Aufgabe wurde bereits geändert.');
            $record->forceFill(['status' => 'cancelled', 'revision' => $revision + 1])->save();
            $this->audit->record($record, $actor, 'personnel_task.cancelled', ['note' => $note]);
        }, 3);
    }

    public function createTraining(array $data, User $actor): PersonnelTraining
    {
        OperationsAccess::authorize($actor, 'operations.qualifications.manage');
        abort_unless($this->ready(), 503);
        $data = Validator::make($data, ['title' => 'required|string|max:180', 'qualification_type_id' => 'nullable|integer|exists:qualification_types,id', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone', 'capacity' => 'required|integer|min:1|max:10000'])->validate();
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);

        return OperationsTransaction::run(function () use ($data, $start, $end, $actor) {
            $record = PersonnelTraining::create(array_merge($data, ['starts_at' => $start, 'ends_at' => $end, 'created_by' => $actor->id]));
            $this->audit->record($record, $actor, 'training.created', ['title' => $data['title'], 'capacity' => $data['capacity']]);

            return $record;
        }, 3);
    }

    public function enroll(PersonnelTraining $training, User $employee, int $revision, User $actor): PersonnelTrainingParticipant
    {
        app(PersonnelScopeService::class)->authorize($actor, $employee, 'operations.qualifications.manage');
        abort_unless($this->ready(), 503);

        return OperationsTransaction::run(function () use ($training, $employee, $revision, $actor) {
            // Training before user is the shared lock order for enrolment and cancellation.
            $record = PersonnelTraining::lockForUpdate()->findOrFail($training->id);
            $employee = User::lockForUpdate()->findOrFail($employee->id);
            $this->check($record->status === 'scheduled' && $record->revision === $revision && $record->ends_at->isFuture(), 'Schulung wurde geändert oder ist beendet.');
            $this->check($employee->status && $employee->role === 'staff', 'Mitarbeiter ist nicht aktiv.');
            $this->check($record->participants()->where('status', 'confirmed')->count() < $record->capacity, 'Schulung ist ausgebucht.');
            $this->check(! AbsenceRequest::where('user_id', $employee->id)->whereIn('status', ['approved', 'reported'])->where('starts_at', '<', $record->ends_at->utc())->where('ends_at', '>', $record->starts_at->utc())->exists(), 'Abwesenheit überschneidet sich mit der Schulung.');
            $this->check(! ShiftAssignment::blocking()->where('user_id', $employee->id)->whereHas('shift', fn ($q) => $q->notCancelled()->during($record->starts_at, $record->ends_at))->exists(), 'Zugewiesene Dienste müssen zuerst umgeplant werden.');
            $this->check(! $this->conflictingTrainings($employee, $record->starts_at, $record->ends_at, [$record->id])->exists(), 'Eine andere Schulung überschneidet sich.');
            $plannedWork = new Shift(['timezone' => $record->timezone, 'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at, 'planned_break_minutes' => 0]);
            $trainingContext = ['exclude_training_ids' => [$record->id]];
            if (class_exists(CustomerCapacityService::class)) {
                $trainingContext['additional_shifts'] = app(CustomerCapacityService::class)->additionalShifts($plannedWork, $employee);
            }
            $contractIssues = app(WorkforceAccountService::class)->planningIssues($plannedWork, $employee, $trainingContext);
            $contractIssues = array_merge($contractIssues, app(StaffEligibilityService::class)->trainingRestIssues($record, $employee, ['exclude_training_ids' => [$record->id]]));
            $this->check($contractIssues === [], implode(' ', array_column($contractIssues, 'message')));
            $participant = PersonnelTrainingParticipant::where('personnel_training_id', $record->id)->where('user_id', $employee->id)->lockForUpdate()->first();
            $this->check(! $participant || $participant->status === 'cancelled', 'Mitarbeiter ist bereits angemeldet.');
            if ($participant) {
                $participant->forceFill(['status' => 'confirmed', 'revision' => $participant->revision + 1, 'created_by' => $actor->id, 'reviewed_by' => null, 'reviewed_at' => null])->save();
            } else {
                $participant = PersonnelTrainingParticipant::create(['personnel_training_id' => $record->id, 'user_id' => $employee->id, 'status' => 'confirmed', 'created_by' => $actor->id]);
            }
            $this->audit->record($participant, $actor, 'training.enrolled', ['training_id' => $record->id]);

            return $participant;
        }, 3);
    }

    public function participation(PersonnelTrainingParticipant $participant, int $revision, string $action, string $note, User $actor): void
    {
        $participant = PersonnelTrainingParticipant::findOrFail($participant->id);
        app(PersonnelScopeService::class)->authorize($actor, (int) $participant->user_id, 'operations.qualifications.manage');
        Validator::make(['action' => $action, 'note' => $note], ['action' => 'required|in:attend,cancel', 'note' => 'required|string|min:5|max:1000'])->validate();
        if ($action === 'attend') {
            abort_if((int) $actor->id === (int) $participant->user_id, 403);
        }
        OperationsTransaction::run(function () use ($participant, $revision, $action, $note, $actor): void {
            $training = PersonnelTraining::lockForUpdate()->findOrFail($participant->personnel_training_id);
            User::lockForUpdate()->findOrFail($participant->user_id);
            $record = PersonnelTrainingParticipant::lockForUpdate()->findOrFail($participant->id);
            app(PersonnelScopeService::class)->authorize($actor, (int) $record->user_id, 'operations.qualifications.manage');
            abort_if($action === 'attend' && (int) $actor->id === (int) $record->user_id, 403);
            $this->check($record->revision === $revision && $record->status === 'confirmed', 'Teilnahme wurde bereits geändert.');
            $this->check($action !== 'cancel' || $training->starts_at->isFuture(), 'Eine bereits begonnene Teilnahme kann nicht storniert werden.');
            $this->check($action !== 'attend' || $training->ends_at->isPast(), 'Teilnahme erst nach Schulungsende bestätigen.');
            $record->forceFill(['status' => $action === 'attend' ? 'attended' : 'cancelled', 'revision' => $revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'note' => $note])->save();
            $this->audit->record($record, $actor, 'training.'.$action, ['note' => $note]);
            // Attendance is not an approved qualification and does not create a second time credit.
        }, 3);
    }

    public function cancelTraining(PersonnelTraining $training, int $revision, string $note, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.qualifications.manage');
        Validator::make(['note' => $note], ['note' => 'required|string|min:5|max:1000'])->validate();
        OperationsTransaction::run(function () use ($training, $revision, $note, $actor): void {
            $record = PersonnelTraining::lockForUpdate()->findOrFail($training->id);
            $this->check($record->revision === $revision && $record->status === 'scheduled', 'Schulung wurde bereits geändert.');
            $participants = $record->participants()->whereIn('status', ['confirmed', 'attended'])->orderBy('user_id')->get();
            foreach ($participants as $participant) {
                app(PersonnelScopeService::class)->authorize($actor, (int) $participant->user_id, 'operations.qualifications.manage');
                User::lockForUpdate()->findOrFail($participant->user_id);
            }
            // Check after every user lock: waiting must not allow a course that has since begun to disappear.
            $this->check($record->starts_at->isFuture(), 'Eine bereits begonnene Schulung kann nicht storniert werden.');
            $this->check(! $participants->contains('status', 'attended'), 'Eine absolvierte Teilnahme kann nicht durch Schulungsstorno entfernt werden.');
            foreach ($participants as $participant) {
                $participant->update(['status' => 'cancelled', 'revision' => $participant->revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'note' => $note]);
                $this->audit->record($participant, $actor, 'training.cancel', ['note' => $note]);
            }
            $record->update(['status' => 'cancelled', 'revision' => $revision + 1]);
            $this->audit->record($record, $actor, 'training.cancelled', ['note' => $note]);
        }, 3);
    }

    public function planningIssues(Shift $shift, User $user, array $context = []): array
    {
        if (! $this->ready()) {
            return [];
        }

        return $this->conflictingTrainings($user, $shift->starts_at, $shift->ends_at, $context['exclude_training_ids'] ?? [])->get()->map(fn ($participant) => ['code' => 'training_overlap', 'message' => 'Schulung überschneidet sich: '.$participant->training->title.'.'])->all();
    }

    public function hasBlockingTraining(User|int $user, CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->ready() && PersonnelTrainingParticipant::where('user_id', $user instanceof User ? $user->id : $user)->whereIn('status', ['confirmed', 'attended'])
            ->whereHas('training', fn ($query) => $query->where('status', 'scheduled')->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc()))->exists();
    }

    private function conflictingTrainings(User $user, $start, $end, array $excluded = [])
    {
        return PersonnelTrainingParticipant::where('user_id', $user->id)->whereIn('status', ['confirmed', 'attended'])->whereNotIn('personnel_training_id', $excluded)->whereHas('training', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc()))->with('training');
    }

    private function manage(User $actor, User|int $target): void
    {
        app(PersonnelScopeService::class)->authorize($actor, $target, 'employees.master-data.edit');
        abort_unless($this->ready(), 503);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['personnel' => $message]);
        }
    }
}
