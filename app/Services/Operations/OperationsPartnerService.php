<?php

namespace App\Services\Operations;

use App\Models\EmployeeQualification;
use App\Models\OperationWorkflow;
use App\Models\Shift;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Validator;

class OperationsPartnerService
{
    public function request(array $data, User $actor): OperationWorkflow
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        $data = Validator::make($data, ['shift_id' => 'required|integer|exists:shifts,id', 'user_id' => 'required|integer|exists:users,id', 'partner_name' => 'required|string|max:180', 'deadline' => 'required|date|after:now', 'note' => 'nullable|string|max:1000'])->validate();
        app(PersonnelScopeService::class)->authorize($actor, $data['user_id'], 'employees.master-data.view');
        $shift = Shift::findOrFail($data['shift_id']);
        abort_if(in_array($shift->status->value, ['completed', 'cancelled']) || $shift->published_revision < 1 || $shift->revision !== $shift->published_revision, 409, 'Freigegebenen Dienststand auswählen.');

        return app(OperationsWorkflowService::class)->create('partner', $data['partner_name'].' · '.$shift->title, ['partner_name' => $data['partner_name'], 'deadline' => $data['deadline'], 'note' => $data['note'] ?? '', 'shift_revision' => $shift->revision, 'role' => $shift->role_name, 'requirements' => $shift->qualifications()->get(['qualification_types.id', 'qualification_types.name'])->map(fn ($q) => ['id' => $q->id, 'name' => $q->name])->all(), 'sections' => app(DutyActivityService::class)->snapshot($shift), 'consent' => false, 'sharing' => [], 'external_delivery' => false], $actor, $data['user_id'], $shift->order_id, $shift->id);
    }

    public function consent(int $id, int $revision, bool $grant, array $qualificationIds, User $actor): OperationWorkflow
    {
        OperationsAccess::own($actor, $actor->id);
        Validator::make(['ids' => $qualificationIds], ['ids' => 'array|max:30', 'ids.*' => 'required|integer|distinct'])->validate();
        $known = OperationWorkflow::findOrFail($id);
        abort_unless($known->kind === 'partner' && (int) $known->user_id === $actor->id, 403);

        return OperationsTransaction::run(function () use ($id, $revision, $grant, $qualificationIds, $actor) {
            $record = OperationWorkflow::lockForUpdate()->findOrFail($id);
            abort_unless($record->revision === $revision && in_array($record->status, ['draft', 'prepared'], true), 409);
            $proofs = EmployeeQualification::where('user_id', $actor->id)->where('status', 'approved')->whereIn('id', $qualificationIds)->with('type')->get();
            abort_unless($proofs->count() === count($qualificationIds), 403);
            $payload = $record->payload;
            $payload['consent'] = $grant;
            $payload['consented_revision'] = $revision;
            $payload['consented_at'] = now()->utc()->toIso8601String();
            $payload['sharing'] = $grant ? $proofs->map(fn ($q) => ['id' => $q->id, 'revision' => $q->revision, 'type' => $q->type->name, 'valid_from' => $q->valid_from->toDateString(), 'valid_until' => $q->valid_until->toDateString()])->all() : [];
            $record->payload = $payload;
            $record->status = 'draft';
            $record->revision++;
            $record->save();
            app(OperationsWorkflowService::class)->snapshot($record, $grant ? 'consented' : 'consent_revoked', $actor);

            return $record;
        }, 3);
    }

    public function prepare(int $id, int $revision, User $actor): OperationWorkflow
    {
        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) {
            abort_unless($r->kind === 'partner' && $r->status === 'draft' && ($r->payload['consent'] ?? false), 409, 'Freigabe der betroffenen Person erforderlich.');
            $shift = Shift::lockForUpdate()->findOrFail($r->shift_id);
            abort_unless($shift->revision === $r->payload['shift_revision'] && now()->lt($r->payload['deadline']), 409);
            app(StaffEligibilityService::class)->assertEligible($shift, User::findOrFail($r->user_id));
            foreach ($r->payload['sharing'] as $shared) {
                $q = EmployeeQualification::where('user_id', $r->user_id)->where('status', 'approved')->findOrFail($shared['id']);
                abort_unless($q->revision === $shared['revision'] && $q->valid_until->gte($shift->ends_at->setTimezone($shift->timezone)->startOfDay()), 409);
            }
            $r->status = 'prepared';

            return 'prepared';
        }, true);
    }

    public function projection(int $id, User $actor): array
    {
        $r = OperationWorkflow::findOrFail($id);
        app(OperationsWorkflowService::class)->authorize($r, $actor, true);
        abort_unless($r->kind === 'partner' && $r->status === 'prepared' && $r->payload['consent'], 403);
        $shift = Shift::findOrFail($r->shift_id);
        abort_unless($shift->revision === $r->payload['shift_revision'] && ! in_array($shift->status->value, ['completed', 'cancelled']) && now()->lt($r->payload['deadline']), 409, 'Partneranfrage wurde geändert oder ist abgelaufen.');
        foreach ($r->payload['sharing'] as $shared) {
            $q = EmployeeQualification::where('user_id', $r->user_id)->where('status', 'approved')->find($shared['id']);
            abort_unless($q && $q->revision === $shared['revision'] && $q->valid_from->lte($shift->starts_at->setTimezone($shift->timezone)->startOfDay()) && $q->valid_until->gte($shift->ends_at->setTimezone($shift->timezone)->startOfDay()), 409, 'Nachweisstand erneut prüfen.');
        }

        return ['schema' => 1, 'request_id' => $r->id, 'revision' => $r->revision, 'partner' => $r->payload['partner_name'], 'shift_id' => $r->shift_id, 'deadline' => $r->payload['deadline'], 'role' => $r->payload['role'], 'qualification_requirements' => $r->payload['requirements'], 'qualification_proofs' => $r->payload['sharing'], 'external_delivery' => false];
    }
}
