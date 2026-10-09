<?php

namespace App\Services\Dropbox;

use App\Enums\ShiftStatus;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\User;
use App\Services\Operations\OperationsAuditService;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Carbon\CarbonImmutable;

class HistoricalImportCompletion
{
    public function confirmedPast(DropboxConnection $connection, CarbonImmutable $end): bool
    {
        $cutoff = $connection->option('historical_completed_before');

        return is_string($cutoff) && $cutoff !== '' && $end->lte(CarbonImmutable::parse($cutoff)) && $end->lte(CarbonImmutable::now());
    }

    /** Explicit user-confirmed historical completion; does not approve times or publish. */
    public function apply(DropboxRecord $record, User $actor): void
    {
        app(ConnectionManager::class)->authorize($actor);
        if ($record->domain !== 'planning' || $record->model_type !== 'Shift' || ! ($record->metadata['imported_order'] ?? false)) {
            return;
        }
        $connection = DropboxConnection::findOrFail($record->connection_id);
        SyncContext::import(fn () => OperationsTransaction::run(function () use ($record, $actor, $connection) {
            $shift = PlanningLocks::acquire([$record->model_id])->get($record->model_id);
            if (in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true) || ! $this->confirmedPast($connection, CarbonImmutable::instance($shift->ends_at))) {
                return;
            }
            $shift->forceFill(['status' => ShiftStatus::Completed, 'revision' => $shift->revision + 1, 'updated_by' => $actor->id])->save();
            app(OperationsAuditService::class)->record($shift, $actor, 'shift.import_history_completed', ['confirmed_before' => $connection->option('historical_completed_before'), 'origin' => 'user_confirmed']);
        }, 1));
    }
}
