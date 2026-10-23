<?php

namespace App\Services\Dropbox;

use App\Enums\OrderStatus;
use App\Enums\ShiftStatus;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OperationsAuditService;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use Carbon\CarbonImmutable;

class HistoricalImportCompletion
{
    public function confirmedPast(DropboxConnection $connection, CarbonImmutable $end): bool
    {
        if ($end->isFuture()) {
            return false;
        }
        if ($connection->option('historical_auto_complete') === true) {
            return true;
        }
        $cutoff = $connection->option('historical_completed_before');

        return is_string($cutoff) && $cutoff !== '' && $end->lte(CarbonImmutable::parse($cutoff));
    }

    /** Only import-owned drafts can become history; native planning and time proof retain ownership. */
    public function canComplete(DropboxRecord $record, Shift $shift, ?DropboxConnection $connection = null): bool
    {
        $connection ??= DropboxConnection::findOrFail($record->connection_id);

        return (int) $connection->id === (int) $record->connection_id
            && $shift->ends_at !== null
            && $this->confirmedPast($connection, CarbonImmutable::instance($shift->ends_at))
            && $this->canManage($record, $shift);
    }

    /** The report schedules an unchanged workbook once its next eligible shift has ended. */
    public function nextDueAt(DropboxSource $source, ?array $activeRecordIds = null): ?CarbonImmutable
    {
        $connection = DropboxConnection::findOrFail($source->connection_id);
        if ($connection->option('historical_auto_complete') !== true || in_array($source->state, ['missing', 'excluded'], true)) {
            return null;
        }
        // The source report already knows the current rows by slot and fingerprint.
        // An own upload advances seen_rev only on edited cells, not unchanged rows.
        $recordIds = DropboxAppearance::where('source_id', $source->id)->where('slot', 'not like', 'missing:%')
            ->when($activeRecordIds === null, fn ($query) => $query->where('seen_rev', $source->rev), fn ($query) => $query->whereIn('record_id', $activeRecordIds))
            ->pluck('record_id')->unique();
        $records = DropboxRecord::where('connection_id', $connection->id)->whereIn('id', $recordIds)
            ->where('domain', 'planning')->where('model_type', 'Shift')->get();
        $blocked = DropboxConflict::whereIn('record_id', $recordIds)->whereIn('state', ['open', 'rechecking'])
            ->whereIn('reason', ['field_conflict', 'business_rule', 'row_missing', 'ambiguous_row', 'mapping_required'])->pluck('record_id')->flip();
        $next = null;
        foreach ($records as $record) {
            if (isset($blocked[$record->id])) {
                continue;
            }
            $shift = Shift::find($record->model_id);
            if (! $shift?->ends_at || ! $shift->ends_at->isFuture() || ! $this->canManage($record, $shift)) {
                continue;
            }
            $end = CarbonImmutable::instance($shift->ends_at)->utc();
            if ($next === null || $end->lt($next)) {
                $next = $end;
            }
        }

        return $next;
    }

    /** Explicit user-confirmed historical completion; does not approve times or publish. */
    public function apply(DropboxRecord $record, User $actor): void
    {
        app(ConnectionManager::class)->authorize($actor);
        if ($record->domain !== 'planning' || $record->model_type !== 'Shift' || ! ($record->metadata['imported_order'] ?? false) || isset($record->metadata['historical_values'])) {
            return;
        }
        $connection = DropboxConnection::findOrFail($record->connection_id);
        SyncContext::import(fn () => OperationsTransaction::run(function () use ($record, $actor, $connection) {
            $shift = PlanningLocks::acquire([$record->model_id])->get($record->model_id);
            if (! $this->canComplete($record, $shift, $connection)) {
                return;
            }
            $shift->forceFill(['status' => ShiftStatus::Completed, 'revision' => $shift->revision + 1, 'updated_by' => $actor->id])->save();
            app(OperationsAuditService::class)->record($shift, $actor, 'shift.import_history_completed', [
                'confirmed_before' => $connection->option('historical_completed_before'),
                'origin' => $connection->option('historical_auto_complete') === true ? 'automatic_import_policy' : 'user_confirmed',
            ]);
        }, 1));
    }

    private function canManage(DropboxRecord $record, Shift $shift): bool
    {
        if ($record->domain !== 'planning' || $record->model_type !== 'Shift' || ! ($record->metadata['imported_order'] ?? false)
            || isset($record->metadata['historical_values']) || ($record->model_id && (int) $record->model_id !== (int) $shift->id)
            || ($shift->status ?? ShiftStatus::Draft) !== ShiftStatus::Draft
            || (int) $shift->published_revision !== 0 || $shift->published_at !== null) {
            return false;
        }
        if ($shift->exists && ($shift->assignments()->blocking()->exists()
            || WorkTimeEntry::whereHas('assignment', fn ($query) => $query->where('shift_id', $shift->id))->exists())) {
            return false;
        }
        $order = Order::find($shift->order_id);
        if (! $order || in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Invoiced], true)) {
            return false;
        }
        $records = DropboxRecord::where('connection_id', $record->connection_id)->where('domain', 'planning')->where('model_type', 'Shift')
            ->whereIn('model_id', $order->shifts()->pluck('id'))->orderBy('id')->get();
        $owner = $records->first(fn ($candidate) => ($candidate->metadata['imported_order'] ?? false) === true) ?? $record;
        $lastHistory = $order->statusHistory()->reorder()->latest('id')->first();
        $managed = $owner->metadata['order_status_import'] ?? null;

        return $managed
            ? $order->status->value === ($managed['status'] ?? null) && (int) $lastHistory?->id === (int) ($managed['history_id'] ?? 0)
            : $order->status === OrderStatus::Requested && $lastHistory === null;
    }
}
