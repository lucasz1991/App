<?php

namespace App\Jobs\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Services\Dropbox\ClosingService;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\RecordExporter;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\SourceSyncReport;
use App\Services\Dropbox\SyncGuard;
use App\Services\Dropbox\WorkLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessDropboxWork implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $workId) {}

    public function handle(): void
    {
        $lock = Cache::store(config('dropbox.lock_store'))->lock('dropbox-work:'.$this->workId, 330);
        if (! $lock->get()) {
            return;
        }
        try {
            $claim = $this->claim();
            if (! $claim) {
                return;
            }
            [$item, $connection] = $claim;
            $this->process($item, $connection);
        } finally {
            $lock->release();
        }
        app(WorkLedger::class)->dispatch($connection->id);
    }

    /** @return array{DropboxWorkItem, DropboxConnection}|null */
    private function claim(): ?array
    {
        return DB::transaction(function () {
            $item = DropboxWorkItem::lockForUpdate()->find($this->workId);
            $now = now();
            if (! $item || $item->requested <= $item->completed || $item->available_at->gt($now) || $item->running_until?->gt($now)) {
                return null;
            }
            $connection = DropboxConnection::find($item->connection_id);
            if (! $connection?->refresh_token || $connection->generation !== $item->generation || (! $connection->mode->active() && ! ($item->payload['preview'] ?? false))) {
                return null;
            }
            $item->forceFill(['running_until' => $now->addSeconds(330), 'attempts' => $item->attempts + 1])->save();

            return [$item, $connection];
        }, 3);
    }

    private function process(DropboxWorkItem $item, DropboxConnection $connection): void
    {
        $sequence = $item->requested;
        $preview = (bool) ($item->payload['preview'] ?? false);
        try {
            match ($item->kind) {
                'scan' => app(SourceScanner::class)->scan($connection, $preview),
                'file' => $this->syncSource($connection, (int) $item->payload['source_id'], $preview),
                'export' => app(RecordExporter::class)->export($connection, $item->payload['type'], $item->payload['id'], $preview),
                'archive' => app(ClosingService::class)->archive($connection, DropboxSource::where('connection_id', $connection->id)->findOrFail($item->payload['source_id'])),
                'finish' => app(ClosingService::class)->finish($connection),
                default => throw new DropboxApiException('unknown_work_type'),
            };
            $completed = DropboxWorkItem::whereKey($item->id)->where('attempts', $item->attempts)->where('completed', '<', $sequence)
                ->update(['completed' => $sequence, 'queued_until' => null, 'running_until' => null, 'error_code' => null]);
            if ($completed && $preview && ! DropboxWorkItem::where('connection_id', $connection->id)->where('generation', $connection->generation)->whereColumn('requested', '>', 'completed')->where('payload->preview', true)->exists()) {
                DropboxConnection::whereKey($connection->id)->where('generation', $connection->generation)->update(['preview_at' => now(), 'preview_generation' => $connection->generation]);
            }
        } catch (\Throwable $e) {
            $code = $e instanceof DropboxApiException ? $e->reason : ($e instanceof ValidationException ? 'validation_required' : (preg_match('/^[a-z_]+(?::[a-z_]+)?$/D', $e->getMessage()) ? $e->getMessage() : 'processing_failed'));
            $delay = $e instanceof DropboxApiException ? $e->retryAfter : min(3600, 30 * 2 ** min($item->attempts, 7));
            $failed = DropboxWorkItem::whereKey($item->id)->where('attempts', $item->attempts)
                ->update(['error_code' => $code, 'queued_until' => null, 'running_until' => null, 'available_at' => now()->addSeconds($delay)]);
            if (! $failed) {
                return;
            }
            if ($code === 'reconnect_required') {
                $connection->forceFill(['error_code' => $code])->save();
            }
            if ($item->kind === 'file' && ! $preview && ($source = DropboxSource::where('connection_id', $connection->id)->find($item->payload['source_id'] ?? 0))) {
                app(SourceSyncReport::class)->failed($source, $code);
            }
            DB::table('dropbox_runs')->insert(['connection_id' => $connection->id, 'kind' => $item->kind, 'status' => 'error', 'summary' => json_encode(['work_id' => $item->id, 'code' => $code]), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function syncSource(DropboxConnection $connection, int $sourceId, bool $preview): void
    {
        $source = DropboxSource::where('connection_id', $connection->id)->findOrFail($sourceId);
        if ($preview || $connection->mode === DropboxMode::Preview) {
            $source = app(SourceScanner::class)->sourceForPreview($connection, $source);
            if ($source) {
                app(FileSynchronizer::class)->sync($connection, $source, true);
            }

            return;
        }
        if (in_array($source->state, ['missing', 'excluded'], true)) {
            return; // The scanner retains the source hint; obsolete work is done.
        }
        if (! app(SyncGuard::class)->pathAllowed($connection, $source->path)) {
            $source->update(['state' => 'excluded']);

            return;
        }
        app(FileSynchronizer::class)->sync($connection, $source);
    }
}
