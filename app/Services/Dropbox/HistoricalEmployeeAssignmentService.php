<?php

namespace App\Services\Dropbox;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\OperationAudit;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OperationsAuditService;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Historical source evidence is visible without reserving staff or generating a new request. */
class HistoricalEmployeeAssignmentService
{
    /** The caller persists record.assignment_id and the amended metadata inside its import transaction. */
    public function sync(DropboxConnection $connection, DropboxRecord $record, Shift $shift, ?DropboxIdentity $identity, User $actor, bool $historical, bool $cancelled = false, bool $allowRestore = false): ?ShiftAssignment
    {
        app(ConnectionManager::class)->authorize($actor);
        if ((int) $record->connection_id !== (int) $connection->id || $record->domain !== 'planning'
            || $record->model_type !== 'Shift' || ($record->model_id && (int) $record->model_id !== (int) $shift->id)
            || ($identity && (int) $identity->connection_id !== (int) $connection->id)) {
            throw ValidationException::withMessages(['sync' => 'Historische Mitarbeiterangabe gehört nicht zu diesem Einsatz und dieser Verbindung.']);
        }

        return SyncContext::import(fn () => OperationsTransaction::run(function () use ($connection, $record, $shift, $identity, $actor, $historical, $cancelled, $allowRestore) {
            $shift = Shift::lockForUpdate()->findOrFail($shift->id);
            $previous = $record->assignment_id ? ShiftAssignment::lockForUpdate()->find($record->assignment_id) : null;
            if ($previous && (int) $previous->shift_id !== (int) $shift->id) {
                throw ValidationException::withMessages(['sync' => 'Bisherige Mitarbeiterzuordnung gehört zu einer anderen Schicht.']);
            }
            $person = $identity?->kind === 'employee' && $identity->user_id ? User::find($identity->user_id) : null;
            if ($cancelled && $previous && $identity && (! $person || (int) $previous->user_id !== (int) $person->id)) {
                throw ValidationException::withMessages(['sync' => 'Gleichzeitiger Mitarbeiterwechsel und Stornierung einer historischen Zuordnung muss geprüft werden.']);
            }
            $past = CarbonImmutable::instance($shift->ends_at)->lte(CarbonImmutable::now());
            $eligible = ! $cancelled && ($record->metadata['imported_order'] ?? false) && $past
                && (($historical && app(HistoricalImportCompletion::class)->confirmedPast($connection, CarbonImmutable::instance($shift->ends_at)))
                    || $shift->status === ShiftStatus::Completed)
                && $shift->status !== ShiftStatus::Cancelled && $person?->role === 'staff';
            if ($previous && $previous->status !== ShiftAssignmentStatus::Reported
                && ! ($allowRestore && $eligible && $this->restorable($record, $previous))) {
                if ($allowRestore && $eligible && in_array($previous->status, [ShiftAssignmentStatus::Cancelled, ShiftAssignmentStatus::Declined], true)) {
                    throw ValidationException::withMessages(['sync' => 'Stornierte oder abgelehnte Mitarbeiterzuordnung wurde außerhalb dieses Imports bearbeitet und muss geprüft werden.']);
                }

                return $previous; // Passive reconciliation never restores a cancellation or changes a native decision.
            }
            if ($previous?->status === ShiftAssignmentStatus::Cancelled && (int) $previous->user_id !== (int) $person?->id) {
                $previous = null; // This import already retired it; a different person uses its own assignment.
            }
            $retired = null;
            if ($previous && (! $eligible || (int) $previous->user_id !== (int) $person?->id)) {
                $this->retire($record, $previous, $actor);
                $retired = $previous;
                $previous = null;
            }
            if (! $eligible) {
                return $cancelled ? $retired : null;
            }
            $assignment = $previous ?? ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $person->id)->lockForUpdate()->first();
            if ($assignment && $assignment->status !== ShiftAssignmentStatus::Reported
                && ! ($allowRestore && $this->restorable($record, $assignment))) {
                if ($allowRestore && in_array($assignment->status, [ShiftAssignmentStatus::Cancelled, ShiftAssignmentStatus::Declined], true)) {
                    throw ValidationException::withMessages(['sync' => 'Stornierte oder abgelehnte Mitarbeiterzuordnung wurde außerhalb dieses Imports bearbeitet und muss geprüft werden.']);
                }

                return $assignment; // Preserve personal decisions for the same user, including rejection or cancellation.
            }
            if ($assignment && WorkTimeEntry::where('shift_assignment_id', $assignment->id)->exists()) {
                return $assignment;
            }
            $assignment ??= new ShiftAssignment;
            $created = ! $assignment->exists;
            $restored = $assignment->exists && $assignment->status === ShiftAssignmentStatus::Cancelled;
            $assignment->fill(['shift_id' => $shift->id, 'user_id' => $person->id, 'status' => ShiftAssignmentStatus::Reported,
                'assigned_by' => $assignment->assigned_by ?: $actor->id, 'responded_at' => null,
                'note' => 'Historische Mitarbeiterangabe aus Excel; keine Mitarbeiterzusage oder Zeitfreigabe.']);
            $assignment->plan_revision = $shift->revision;
            if ($assignment->isDirty()) {
                $assignment->save();
                app(OperationsAuditService::class)->record($assignment, $actor, $created ? 'assignment.import_reported' : ($restored ? 'assignment.import_report_restored' : 'assignment.import_report_updated'), [
                    'connection_id' => $connection->id, 'record_id' => $record->id, 'shift_id' => $shift->id,
                    'source' => 'excel_history',
                ]);
            }
            $record->metadata = [...($record->metadata ?? []), 'historical_assignment_id' => $assignment->id];

            return $assignment;
        }, 1));
    }

    private function restorable(DropboxRecord $record, ShiftAssignment $assignment): bool
    {
        if ($assignment->status !== ShiftAssignmentStatus::Cancelled || ! $record->exists
            || WorkTimeEntry::where('shift_assignment_id', $assignment->id)->exists()) {
            return false;
        }
        $audit = OperationAudit::where('subject_type', 'ShiftAssignment')->where('subject_id', $assignment->id)->latest('id')->first();

        return $audit?->action === 'assignment.import_report_retired'
            && (int) ($audit->data['record_id'] ?? 0) === (int) $record->id
            && (int) ($audit->data['connection_id'] ?? 0) === (int) $record->connection_id
            && ($audit->data['source'] ?? null) === 'excel_history';
    }

    private function retire(DropboxRecord $record, ShiftAssignment $assignment, User $actor): void
    {
        if ((int) ($record->metadata['historical_assignment_id'] ?? 0) !== (int) $assignment->id
            || WorkTimeEntry::where('shift_assignment_id', $assignment->id)->exists()) {
            throw ValidationException::withMessages(['sync' => 'Historische Zuordnung wurde außerhalb des Imports bearbeitet und muss geprüft werden.']);
        }
        $assignment->update(['status' => ShiftAssignmentStatus::Cancelled]);
        app(OperationsAuditService::class)->record($assignment, $actor, 'assignment.import_report_retired', [
            'connection_id' => $record->connection_id, 'record_id' => $record->id, 'source' => 'excel_history',
        ]);
        $record->assignment_id = null;
        $metadata = $record->metadata ?? [];
        unset($metadata['historical_assignment_id']);
        $record->metadata = $metadata;
    }
}
