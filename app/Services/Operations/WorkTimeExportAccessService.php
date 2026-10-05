<?php

namespace App\Services\Operations;

use App\Models\User;
use App\Models\WorkTimeBasicExport;
use App\Models\WorkTimeExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WorkTimeExportAccessService
{
    public function legacyQuery(User $actor): Builder
    {
        $ids = app(PersonnelScopeService::class)->visibleUserIds($actor, 'operations.time.export');
        $query = WorkTimeExport::query();
        if ($ids === null) {
            return $query;
        }
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        // A mixed export is visible only when every historical employee is in scope.
        // Older snapshots without employee_id may fall back to their surviving entry.
        return $query->whereHas('items')->whereDoesntHave('items', function ($items) use ($ids) {
            $items->where(function ($outside) use ($ids) {
                $outside->where(function ($historical) use ($ids) {
                    $historical->whereNotNull('snapshot->employee_id')->whereNotIn('snapshot->employee_id', $ids);
                })->orWhere(function ($legacy) use ($ids) {
                    $legacy->whereNull('snapshot->employee_id')->whereNotExists(function ($entry) use ($ids) {
                        $entry->selectRaw('1')->from('work_time_entries')
                            ->whereColumn('work_time_entries.id', 'work_time_export_items.work_time_entry_id')->whereIn('work_time_entries.user_id', $ids);
                    });
                });
            });
        });
    }

    public function basicHistory(User $actor, int $limit = 20): Collection
    {
        $ids = app(PersonnelScopeService::class)->visibleUserIds($actor, 'operations.time.export');
        if ($ids === []) {
            return collect();
        }
        $limit = max(1, min(100, $limit));
        if ($ids === null) {
            return WorkTimeBasicExport::latest('id')->limit($limit)->get();
        }

        // V2 snapshots are encrypted at rest; do not query their ciphertext as JSON.
        return WorkTimeBasicExport::lazyByIdDesc(100)->filter(function ($export) use ($ids) {
            $records = $export->snapshot;

            return is_array($records) && $records !== [] && collect($records)->every(fn ($record) => isset($record['employee_id']) && in_array((int) $record['employee_id'], $ids, true));
        })->take($limit)->collect();
    }
}
