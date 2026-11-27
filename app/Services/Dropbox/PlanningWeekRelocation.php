<?php

namespace App\Services\Dropbox;

use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\Shift;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Cache;

final class PlanningWeekRelocation
{
    public function __construct(private WeekFileMatcher $weeks) {}

    public function belongsToWeek(DropboxConnection $connection, DropboxSource $source, string $week): bool
    {
        if ($source->profile !== 'weekly') {
            return false;
        }

        $name = $this->weeks->match($source->name, $connection->option('filename_rule'));
        if ($name['year'] ?? null) {
            return sprintf('%04d-W%02d', $name['year'], $name['week']) === $week;
        }

        $known = array_values(array_unique($source->weeks ?? []));
        if ($name) {
            $known = array_values(array_filter($known, fn ($candidate) => (int) substr($candidate, 6) === $name['week']));
        }

        return $known === [$week];
    }

    public function needsMove(DropboxConnection $connection, DropboxSource $source, DropboxAppearance $appearance, string $week): bool
    {
        $previousDate = $appearance->baseline['date'] ?? $appearance->last_excel['date'] ?? null;

        return $source->profile === 'weekly' && $previousDate && $this->weeks->week($previousDate) !== $week
            && ! $this->belongsToWeek($connection, $source, $week);
    }

    public function assertReady(DropboxConnection $connection, DropboxRecord $record, DropboxSource $source): void
    {
        app(SyncGuard::class)->current($connection, write: true);
        if (! app(SyncGuard::class)->pathAllowed($connection, $source->path) || in_array($source->state, ['missing', 'excluded'], true)) {
            throw new DropboxApiException('source_unavailable');
        }
        $metadata = app(DropboxClient::class)->rpc($connection, 'files/get_metadata', ['path' => $source->path]);
        if (($metadata['id'] ?? '') !== $source->file_id || $source->progress || $source->processed_rev !== ($metadata['rev'] ?? '')) {
            app(WorkLedger::class)->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);
            throw new DropboxApiException('relocation_source_needs_import', 10);
        }
        if (DropboxConflict::where('source_id', $source->id)->where('record_id', $record->id)->whereIn('state', ['open', 'rechecking'])->exists()) {
            throw new DropboxApiException('relocation_source_needs_review', 60);
        }
    }

    public function retire(DropboxConnection $connection, DropboxRecord $record, DropboxSource $previous, DropboxSource $destination, string $week): void
    {
        $lock = Cache::store(config('dropbox.lock_store'))->lock('dropbox-file:'.$connection->id.':'.$previous->file_id, 330);
        if (! $lock->get()) {
            throw new DropboxApiException('file_busy', 5);
        }

        try {
            $previous = $previous->fresh();
            $this->assertReady($connection, $record, $previous);

            OperationsTransaction::run(function () use ($connection, $record, $previous, $destination, $week) {
                app(SyncGuard::class)->current($connection, write: true, lock: true);
                $record = DropboxRecord::lockForUpdate()->findOrFail($record->id);
                if (! Shift::lockForUpdate()->find($record->model_id)) {
                    return;
                }
                $current = app(DomainAdapter::class)->current($record);
                if ($this->weeks->week($current['date']) !== $week) {
                    return; // A newer App change has its own export intent.
                }
                $destinationRows = DropboxAppearance::where('source_id', $destination->id)->where('record_id', $record->id)->get();
                if (! $destinationRows->contains(fn ($appearance) => ($appearance->locator['master'] ?? false)
                    && isset($appearance->baseline['date']) && $this->weeks->week($appearance->baseline['date']) === $week)) {
                    throw new DropboxApiException('destination_needs_import', 10);
                }

                $oldRows = DropboxAppearance::where('source_id', $previous->id)->where('record_id', $record->id)->lockForUpdate()->get();
                foreach ($oldRows as $appearance) {
                    if (! $this->needsMove($connection, $previous, $appearance, $week)) {
                        continue;
                    }
                    $historical = $appearance->last_excel;
                    $historical['cancelled'] = true;
                    $marker = 'Storno: nach '.$week.' verschoben.';
                    if (isset($appearance->locator['cells']['cancellation'])) {
                        $historical['cancellation'] = trim($marker.' '.($historical['cancellation'] ?? ''));
                    } elseif (isset($appearance->locator['cells']['notes'])) {
                        $historical['notes'] = trim(($historical['notes'] ?? '').' '.$marker);
                    } else {
                        throw new DropboxApiException('relocation_marker_column_missing', 300);
                    }
                    $history = DropboxRecord::create(['connection_id' => $connection->id, 'domain' => 'planning', 'model_type' => 'ReportedAssignmentHistory',
                        'model_id' => $record->model_id, 'metadata' => ['historical_values' => $historical, 'week_relocation' => $week]]);
                    $appearance->update(['record_id' => $history->id]);
                }

                app(WorkLedger::class)->enqueue($connection, 'file', 'file:'.$previous->id, ['source_id' => $previous->id], 0);
            });
        } finally {
            $lock->release();
        }
    }
}
