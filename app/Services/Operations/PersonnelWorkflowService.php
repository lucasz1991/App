<?php

namespace App\Services\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\QualificationType;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PersonnelWorkflowService
{
    public function __construct(private OperationsAuditService $audit) {}

    public function requestAbsence(User $actor, array $data): AbsenceRequest
    {
        OperationsAccess::own($actor, $actor->id);
        $data = Validator::make($data, ['kind' => 'required|in:vacation,unavailable,other', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone', 'note' => 'nullable|string|max:1000', 'vacation_fraction' => 'nullable|in:1,0.5'])->validate();
        if (! Schema::hasColumn('absence_requests', 'vacation_fraction')) {
            unset($data['vacation_fraction']);
        }
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);

        return OperationsTransaction::run(function () use ($actor, $data, $start, $end) {
            User::lockForUpdate()->findOrFail($actor->id);
            $this->check(! AbsenceRequest::where('user_id', $actor->id)->whereIn('status', ['pending', 'approved', 'reported'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 'Für diesen Zeitraum besteht bereits eine Abwesenheit.');
            $record = AbsenceRequest::create(array_merge($data, ['user_id' => $actor->id, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'pending']));
            app(WorkforceAccountService::class)->reserveAbsence($record);
            $this->audit->record($record, $actor, 'absence.requested');

            return $record;
        }, 3);
    }

    public function absence(AbsenceRequest $request, int $revision, string $action, string $note, User $actor): void
    {
        if ($action === 'withdraw') {
            OperationsAccess::own($actor, $request->user_id);
        } else {
            OperationsAccess::authorize($actor, 'operations.absences.review');
            app(PersonnelScopeService::class)->authorize($actor, (int) $request->user_id, 'operations.absences.review');
            abort_if($actor->id === $request->user_id, 403);
        }
        $this->check(in_array($action, ['approve', 'reject', 'withdraw', 'cancel'], true), 'Ungültige Aktion.');
        Validator::make(['note' => $note], ['note' => (in_array($action, ['reject', 'cancel'], true) ? 'required|min:5' : 'nullable').'|string|max:1000'])->validate();
        OperationsTransaction::run(function () use ($request, $revision, $action, $note, $actor) {
            User::lockForUpdate()->findOrFail($request->user_id);
            $record = AbsenceRequest::lockForUpdate()->findOrFail($request->id);
            $this->check($record->revision === $revision && $record->status === ($action === 'cancel' ? 'approved' : 'pending'), 'Antrag wurde bereits bearbeitet. Bitte neu laden.');
            $this->check($action !== 'cancel' || $record->starts_at->isFuture(), 'Begonnene Abwesenheiten können nicht storniert werden.');
            if ($action === 'approve') {
                $this->check(! ShiftAssignment::blocking()->where('user_id', $record->user_id)->whereHas('shift', fn ($q) => $q->notCancelled()->during($record->starts_at, $record->ends_at))->exists(), 'Zugewiesene Dienste müssen zuerst umgeplant werden.');
                $this->check(! app(PersonnelProcessService::class)->hasBlockingTraining((int) $record->user_id, $record->starts_at, $record->ends_at), 'Schulungsteilnahme muss zuerst umgeplant werden.');
                app(WorkforceAccountService::class)->approveAbsence($record);
            } else {
                app(WorkforceAccountService::class)->releaseAbsence($record);
            }
            $record->forceFill(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn', 'cancel' => 'cancelled'][$action], 'revision' => $revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'review_note' => $note])->save();
            $this->audit->record($record, $actor, 'absence.'.$action, ['note' => $note]);
        }, 3);
    }

    public function reportSickness(User $actor, array $data): AbsenceRequest
    {
        OperationsAccess::own($actor, $actor->id);
        $data = Validator::make($data, ['starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone'])->validate();
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);

        return OperationsTransaction::run(function () use ($actor, $data, $start, $end) {
            User::lockForUpdate()->findOrFail($actor->id);
            $this->check(! AbsenceRequest::where('user_id', $actor->id)->where('kind', 'sick')->where('status', 'reported')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 'Für diesen Zeitraum besteht bereits eine Krankmeldung.');
            $record = AbsenceRequest::create(array_merge($data, ['user_id' => $actor->id, 'kind' => 'sick', 'starts_at' => $start, 'ends_at' => $end, 'status' => 'reported']));
            $affected = ShiftAssignment::blocking()->where('user_id', $actor->id)->whereHas('shift', fn ($q) => $q->notCancelled()->during($start, $end))->pluck('id')->all();
            $this->audit->record($record, $actor, 'sickness.reported', ['affected_assignment_ids' => $affected]);

            return $record;
        }, 3);
    }

    public function correctSickness(AbsenceRequest $request, int $revision, array $data, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.absences.review');
        app(PersonnelScopeService::class)->authorize($actor, (int) $request->user_id, 'operations.absences.review');
        abort_if($actor->id === $request->user_id, 403);
        $data = Validator::make($data, ['starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone', 'note' => 'required|string|min:5|max:1000'])->validate();
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);
        OperationsTransaction::run(function () use ($request, $revision, $data, $start, $end, $actor): void {
            User::lockForUpdate()->findOrFail($request->user_id);
            $record = AbsenceRequest::lockForUpdate()->findOrFail($request->id);
            $this->check($record->kind === 'sick' && $record->status === 'reported' && $record->revision === $revision, 'Krankmeldung wurde bereits geändert.');
            $before = $record->only(['starts_at', 'ends_at', 'timezone']);
            $record->forceFill(['starts_at' => $start, 'ends_at' => $end, 'timezone' => $data['timezone'], 'revision' => $revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc()])->save();
            $this->audit->record($record, $actor, 'sickness.corrected', ['before' => $before, 'note' => $data['note']]);
        }, 3);
    }

    public function submitQualification(User $actor, array $data, UploadedFile $file): EmployeeQualification
    {
        OperationsAccess::own($actor, $actor->id);
        $validated = Validator::make($data + ['file' => $file], ['qualification_type_id' => 'required|integer|exists:qualification_types,id', 'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:valid_from', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.config('operations.evidence_max_kilobytes')])->validate();
        $this->check(QualificationType::findOrFail($validated['qualification_type_id'])->is_active, 'Nachweisart ist nicht aktiv.');
        $path = $file->store('operations/evidence', 'local');
        try {
            return OperationsTransaction::run(function () use ($actor, $validated, $file, $path) {
                User::lockForUpdate()->findOrFail($actor->id);
                unset($validated['file']);
                $record = EmployeeQualification::create($validated + ['user_id' => $actor->id, 'status' => 'pending', 'evidence_path' => $path, 'evidence_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'evidence_mime' => $file->getMimeType()]);
                $this->audit->record($record, $actor, 'qualification.submitted');

                return $record;
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function qualification(EmployeeQualification $qualification, int $revision, string $action, string $note, User $actor): void
    {
        OperationsAccess::authorize($actor, 'operations.qualifications.manage');
        app(PersonnelScopeService::class)->authorize($actor, (int) $qualification->user_id, 'operations.qualifications.manage');
        abort_if($actor->id === $qualification->user_id, 403);
        $this->check(in_array($action, ['approve', 'reject', 'revoke'], true), 'Ungültige Aktion.');
        Validator::make(['note' => $note], ['note' => ($action === 'approve' ? 'nullable' : 'required').'|string|max:1000'])->validate();
        OperationsTransaction::run(function () use ($qualification, $revision, $action, $note, $actor) {
            User::lockForUpdate()->findOrFail($qualification->user_id);
            $record = EmployeeQualification::lockForUpdate()->findOrFail($qualification->id);
            $this->check($record->revision === $revision && ($action === 'revoke' ? $record->status === 'approved' : $record->status === 'pending'), 'Nachweis wurde bereits bearbeitet. Bitte neu laden.');
            if ($action === 'approve') {
                $this->check($record->type?->is_active === true, 'Nachweisart ist nicht aktiv.');
                $this->check($record->evidence_path && str_starts_with($record->evidence_path, 'operations/evidence/') && Storage::disk('local')->exists($record->evidence_path), 'Nachweisdatei fehlt. Bitte erneut einreichen lassen.');
            }
            $record->forceFill(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'][$action], 'revision' => $revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'review_note' => $note])->save();
            // Revocation must persist even when an assigned service needs replanning.
            $affected = $action === 'revoke' ? ShiftAssignment::blocking()->where('user_id', $record->user_id)
                ->whereHas('shift', fn ($q) => $q->notCancelled()->upcoming()->whereHas('qualifications', fn ($q) => $q->where('qualification_types.id', $record->qualification_type_id)))
                ->pluck('id')->all() : [];
            $this->audit->record($record, $actor, 'qualification.'.$action, ['note' => $note, 'affected_assignment_ids' => $affected]);
        }, 3);
    }

    public function saveRules(array $data, User $actor): OperationsRuleProfile
    {
        OperationsAccess::authorize($actor, 'operations.rules.manage');
        app(PersonnelScopeService::class)->authorizeGlobal($actor, 'operations.rules.manage');
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'minimum_rest_minutes' => 'required|integer|min:0|max:10080', 'maximum_shift_minutes' => 'required|integer|min:1|max:1440', 'break_after_minutes' => 'required|integer|min:0|max:1440', 'minimum_break_minutes' => 'required|integer|min:0|max:1439', 'confirmed' => 'accepted'])->validate();
        $this->check($data['minimum_break_minutes'] < $data['maximum_shift_minutes'], 'Pause muss kürzer als die Höchstdauer sein.');
        unset($data['confirmed']);

        return OperationsTransaction::run(function () use ($data, $actor) {
            // The creator lock serializes first-profile setup; profiles are immutable versions.
            User::orderBy('id')->lockForUpdate()->get(['id']);
            OperationsRuleProfile::where('is_active', true)->update(['is_active' => false]);
            $profile = OperationsRuleProfile::create($data + ['is_active' => true, 'created_by' => $actor->id, 'approved_at' => now()->utc()]);
            $reviews = app(WorkforcePlanReviewService::class);
            $impacts = [];
            foreach (ShiftAssignment::blocking()->whereHas('shift', fn ($q) => $q->notCancelled()->upcoming())->with(['shift', 'user'])->get() as $assignment) {
                if ($reviews->ready()) {
                    if (! isset($impacts[$assignment->user_id])) {
                        $impacts[$assignment->user_id] = $reviews->recheckForEmployee($assignment->user, $profile, $actor);
                    }
                } else {
                    // Preserve the unmigrated legacy rule workflow until a review queue exists.
                    app(StaffEligibilityService::class)->assertEligible($assignment->shift, $assignment->user);
                }
            }
            $this->audit->record($profile, $actor, 'rules.activated', $data + ['planning_reviews' => $impacts]);

            return $profile;
        }, 3);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
