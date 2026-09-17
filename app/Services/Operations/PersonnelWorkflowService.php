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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PersonnelWorkflowService
{
    public function __construct(private OperationsAuditService $audit) {}

    public function requestAbsence(User $actor, array $data): AbsenceRequest
    {
        OperationsAccess::own($actor, $actor->id);
        $data = Validator::make($data, ['kind' => 'required|in:vacation,unavailable,other', 'starts_at' => 'required|string', 'ends_at' => 'required|string', 'timezone' => 'required|timezone', 'note' => 'nullable|string|max:1000'])->validate();
        [$start, $end] = OperationsDateTime::interval($data['starts_at'], $data['ends_at'], $data['timezone']);

        return DB::transaction(function () use ($actor, $data, $start, $end) {
            User::lockForUpdate()->findOrFail($actor->id);
            $this->check(! AbsenceRequest::where('user_id', $actor->id)->whereIn('status', ['pending', 'approved'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 'Für diesen Zeitraum besteht bereits ein Antrag.');
            $record = AbsenceRequest::create(array_merge($data, ['user_id' => $actor->id, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'pending']));
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
            abort_if($actor->id === $request->user_id, 403);
        }
        $this->check(in_array($action, ['approve', 'reject', 'withdraw', 'cancel'], true), 'Ungültige Aktion.');
        Validator::make(['note' => $note], ['note' => (in_array($action, ['reject', 'cancel'], true) ? 'required|min:5' : 'nullable').'|string|max:1000'])->validate();
        DB::transaction(function () use ($request, $revision, $action, $note, $actor) {
            User::lockForUpdate()->findOrFail($request->user_id);
            $record = AbsenceRequest::lockForUpdate()->findOrFail($request->id);
            $this->check($record->revision === $revision && $record->status === ($action === 'cancel' ? 'approved' : 'pending'), 'Antrag wurde bereits bearbeitet. Bitte neu laden.');
            $this->check($action !== 'cancel' || $record->starts_at->isFuture(), 'Begonnene Abwesenheiten können nicht storniert werden.');
            if ($action === 'approve') {
                $this->check(! ShiftAssignment::blocking()->where('user_id', $record->user_id)->whereHas('shift', fn ($q) => $q->notCancelled()->during($record->starts_at, $record->ends_at))->exists(), 'Zugewiesene Dienste müssen zuerst umgeplant werden.');
            }
            $record->forceFill(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn', 'cancel' => 'cancelled'][$action], 'revision' => $revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'review_note' => $note])->save();
            $this->audit->record($record, $actor, 'absence.'.$action, ['note' => $note]);
        }, 3);
    }

    public function submitQualification(User $actor, array $data, UploadedFile $file): EmployeeQualification
    {
        OperationsAccess::own($actor, $actor->id);
        $validated = Validator::make($data + ['file' => $file], ['qualification_type_id' => 'required|integer|exists:qualification_types,id', 'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:valid_from', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:'.config('operations.evidence_max_kilobytes')])->validate();
        $this->check(QualificationType::findOrFail($validated['qualification_type_id'])->is_active, 'Nachweisart ist nicht aktiv.');
        $path = $file->store('operations/evidence', 'local');
        try {
            return DB::transaction(function () use ($actor, $validated, $file, $path) {
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
        abort_if($actor->id === $qualification->user_id, 403);
        $this->check(in_array($action, ['approve', 'reject', 'revoke'], true), 'Ungültige Aktion.');
        Validator::make(['note' => $note], ['note' => ($action === 'approve' ? 'nullable' : 'required').'|string|max:1000'])->validate();
        DB::transaction(function () use ($qualification, $revision, $action, $note, $actor) {
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
        $data = Validator::make($data, ['name' => 'required|string|max:120', 'minimum_rest_minutes' => 'required|integer|min:0|max:10080', 'maximum_shift_minutes' => 'required|integer|min:1|max:1440', 'break_after_minutes' => 'required|integer|min:0|max:1440', 'minimum_break_minutes' => 'required|integer|min:0|max:1439', 'confirmed' => 'accepted'])->validate();
        $this->check($data['minimum_break_minutes'] < $data['maximum_shift_minutes'], 'Pause muss kürzer als die Höchstdauer sein.');
        unset($data['confirmed']);

        return DB::transaction(function () use ($data, $actor) {
            // The creator lock serializes first-profile setup; profiles are immutable versions.
            User::orderBy('id')->lockForUpdate()->get(['id']);
            OperationsRuleProfile::where('is_active', true)->update(['is_active' => false]);
            $profile = OperationsRuleProfile::create($data + ['is_active' => true, 'created_by' => $actor->id, 'approved_at' => now()->utc()]);
            foreach (ShiftAssignment::blocking()->whereHas('shift', fn ($q) => $q->notCancelled()->upcoming())->with(['shift', 'user'])->get() as $assignment) {
                app(StaffEligibilityService::class)->assertEligible($assignment->shift, $assignment->user);
            }
            $this->audit->record($profile, $actor, 'rules.activated', $data);

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
