<?php

namespace App\Services\Dropbox;

use App\Enums\OrderStatus;
use App\Enums\ShiftStatus;
use App\Models\DropboxAppearance;
use App\Models\DropboxRecord;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\OrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ImportedOrderStatus
{
    /** Reconcile only import-owned orders; never publish shifts or approve time. */
    public function reconcile(DropboxRecord $record, User $actor): void
    {
        if ($record->domain !== 'planning' || $record->model_type !== 'Shift' || isset($record->metadata['historical_values'])) {
            return;
        }
        SyncContext::import(fn () => DB::transaction(function () use ($record, $actor) {
            $shift = Shift::find($record->model_id);
            if (! $shift) {
                return;
            }
            $order = Order::lockForUpdate()->findOrFail($shift->order_id);
            $shifts = $order->shifts()->lockForUpdate()->get();
            $records = DropboxRecord::where('connection_id', $record->connection_id)->where('domain', 'planning')
                ->where('model_type', 'Shift')->whereIn('model_id', $shifts->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $owner = $records->first(fn ($r) => ($r->metadata['imported_order'] ?? false) === true);
            if (! $owner) {
                return;
            }
            $lastHistory = $order->statusHistory()->reorder()->latest('id')->first();
            $managed = $owner->metadata['order_status_import'] ?? null;
            if ($managed) {
                if ($order->status->value !== $managed['status'] || (int) $lastHistory?->id !== (int) $managed['history_id']) {
                    return; // A person or another workflow now owns this status.
                }
            } elseif ($order->status !== OrderStatus::Requested || $lastHistory) {
                return;
            }
            $active = $shifts->reject(fn ($s) => $s->status === ShiftStatus::Cancelled);
            $target = OrderStatus::Cancelled;
            $reason = 'Alle zugehörigen Schichten sind storniert.';
            if ($active->isNotEmpty()) {
                $appearances = DropboxAppearance::whereIn('record_id', $records->modelKeys())->get()->groupBy('record_id');
                $states = [];
                foreach ($active as $activeShift) {
                    $shiftRecords = $records->where('model_id', $activeShift->id);
                    if (! $shiftRecords->contains(fn ($r) => ($r->metadata['imported_order'] ?? false) === true)) {
                        return; // Do not infer the state of additional app-created shifts.
                    }
                    $hasActiveRecord = false;
                    foreach ($shiftRecords as $shiftRecord) {
                        $current = app(DomainAdapter::class)->current($shiftRecord);
                        if ($current['cancelled'] ?? false) {
                            continue; // A cancelled assignment does not complete its active shift.
                        }
                        $values = $appearances->get($shiftRecord->id, collect())->map(fn ($a) => $a->last_excel)
                            ->filter(fn ($v) => ! ($v['cancelled'] ?? false));
                        if ($values->isEmpty()) {
                            return;
                        }
                        // Use the accepted merge once per record. An unchanged copy must
                        // not veto newer evidence, and app-only data is not Excel evidence.
                        foreach (['actual_end', 'notes', 'information'] as $field) {
                            if (! $values->contains(fn ($v) => ($v[$field] ?? null) === ($current[$field] ?? null))) {
                                $current[$field] = null;
                            }
                        }
                        $current['draft'] = $values->contains(fn ($v) => (bool) ($v['draft'] ?? false));
                        $state = $this->sourceStatus($current, $activeShift->timezone);
                        if ($state === null) {
                            return;
                        }
                        $hasActiveRecord = true;
                        $states[] = $state;
                    }
                    if (! $hasActiveRecord) {
                        return;
                    }
                }
                $target = in_array(OrderStatus::Requested, $states, true) ? OrderStatus::Requested
                    : (in_array(OrderStatus::Planned, $states, true) ? OrderStatus::Planned : OrderStatus::Completed);
                $reason = $target === OrderStatus::Completed
                    ? 'Abschluss aus gemeldeter Ist-Endzeit oder ausdrücklichem Abschlussvermerk; keine Zeitfreigabe.'
                    : 'Dispositionszeile mit Datum und Planzeiten; Abschluss nicht belegt. Ein Schichtentwurf bleibt unveröffentlicht.';
            }
            // Completed/invoiced/cancelled and manually advanced orders are never reset by reimport.
            $path = match ($target) {
                OrderStatus::Cancelled => in_array($order->status, [OrderStatus::Requested, OrderStatus::Confirmed, OrderStatus::Planned, OrderStatus::InProgress], true) ? [OrderStatus::Cancelled] : [],
                OrderStatus::Planned => match ($order->status) {
                    OrderStatus::Requested => [OrderStatus::Confirmed, OrderStatus::Planned],
                    OrderStatus::Confirmed => [OrderStatus::Planned],
                    default => [],
                },
                OrderStatus::Completed => match ($order->status) {
                    OrderStatus::Requested => [OrderStatus::Confirmed, OrderStatus::InProgress, OrderStatus::Completed],
                    OrderStatus::Confirmed, OrderStatus::Planned => [OrderStatus::InProgress, OrderStatus::Completed],
                    OrderStatus::InProgress => [OrderStatus::Completed],
                    default => [],
                },
                default => [],
            };
            foreach ($path as $status) {
                $order = app(OrderLifecycleService::class)->transition($order, $status, $actor, 'Excel-Import: '.$reason);
            }
            if ($managed || $path !== []) {
                $owner->metadata = array_merge($owner->metadata ?? [], ['order_status_import' => [
                    'status' => $order->status->value,
                    'history_id' => $order->statusHistory()->reorder()->max('id'),
                ]]);
                $owner->save();
            }
        }));
    }

    private function sourceStatus(array $values, string $timezone): ?OrderStatus
    {
        if ($values['draft'] ?? false) {
            return OrderStatus::Requested;
        }
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', ($values['date'] ?? '').' '.($values['starts'] ?? ''), $timezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', ($values['date'] ?? '').' '.($values['ends'] ?? ''), $timezone);
            if ($end->lte($start)) {
                $end = $end->addDay();
            }
            $now = CarbonImmutable::now($timezone);
            $actual = $values['actual_end'] ?? null;
            if (is_string($actual) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $actual)) {
                $actualEnd = CarbonImmutable::createFromFormat('!Y-m-d H:i', $values['date'].' '.$actual, $timezone);
                if ($actualEnd->lt($start)) {
                    $actualEnd = $actualEnd->addDay();
                }
                if ($actualEnd->lte($now)) {
                    return OrderStatus::Completed;
                }
            }
            foreach (['notes', 'information'] as $field) {
                if ($end->lte($now) && preg_match('/^\s*(?:Status\s*:\s*)?(?:erledigt|abgeschlossen|durchgeführt)\s*[.!]?\s*$/iuD', (string) ($values[$field] ?? ''))) {
                    return OrderStatus::Completed;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return OrderStatus::Planned;
    }
}
