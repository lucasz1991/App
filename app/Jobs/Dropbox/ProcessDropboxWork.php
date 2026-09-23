<?php

namespace App\Jobs\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Services\Dropbox\ClosingService;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\RecordExporter;
use App\Services\Dropbox\SourceScanner;
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
        $item = DropboxWorkItem::find($this->workId);
        if (! $item || $item->requested <= $item->completed) {
            return;
        }
        $connection = DropboxConnection::find($item->connection_id);
        if (! $connection || $connection->generation !== $item->generation || (! $connection->mode->active() && ! ($item->payload['preview'] ?? false))) {
            return;
        }
        $lock = Cache::store(config('dropbox.lock_store'))->lock('dropbox-work:'.$item->id, 330);
        if (! $lock->get()) {
            return;
        }
        $sequence = $item->requested;
        try {
            $item->forceFill(['running_until' => now()->addSeconds(330), 'attempts' => $item->attempts + 1])->save();
            $preview = (bool) ($item->payload['preview'] ?? false);
            match ($item->kind) {
                'scan' => app(SourceScanner::class)->scan($connection, $preview),
                'file' => app(FileSynchronizer::class)->sync($connection, DropboxSource::where('connection_id', $connection->id)->findOrFail($item->payload['source_id']), $preview),
                'export' => app(RecordExporter::class)->export($connection, $item->payload['type'], $item->payload['id'], $preview),
                'archive' => app(ClosingService::class)->archive($connection, DropboxSource::where('connection_id', $connection->id)->findOrFail($item->payload['source_id'])),
                'finish' => app(ClosingService::class)->finish($connection),
                default => throw new DropboxApiException('unknown_work_type'),
            };
            DB::transaction(function () use ($item, $sequence) {
                $fresh = DropboxWorkItem::lockForUpdate()->findOrFail($item->id);
                $fresh->forceFill(['completed' => max($fresh->completed, $sequence), 'queued_until' => null, 'running_until' => null, 'error_code' => null])->save();
            });
            if ($preview && ! DropboxWorkItem::where('connection_id', $connection->id)->where('generation', $connection->generation)->whereColumn('requested', '>', 'completed')->where('payload->preview', true)->exists()) {
                DropboxConnection::whereKey($connection->id)->where('generation', $connection->generation)->update(['preview_at' => now(), 'preview_generation' => $connection->generation]);
            }
        } catch (\Throwable $e) {
            $code = $e instanceof DropboxApiException ? $e->reason : ($e instanceof ValidationException ? 'validation_required' : (preg_match('/^[a-z_]+(?::[a-z_]+)?$/D', $e->getMessage()) ? $e->getMessage() : 'processing_failed'));
            $delay = $e instanceof DropboxApiException ? $e->retryAfter : min(3600, 30 * 2 ** min($item->attempts, 7));
            DropboxWorkItem::whereKey($item->id)->update(['error_code' => $code, 'queued_until' => null, 'running_until' => null, 'available_at' => now()->addSeconds($delay)]);
            if ($code === 'reconnect_required') {
                $connection->forceFill(['error_code' => $code])->save();
            }
            DB::table('dropbox_runs')->insert(['connection_id' => $connection->id, 'kind' => $item->kind, 'status' => 'error', 'summary' => json_encode(['work_id' => $item->id, 'code' => $code]), 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            $lock->release();
        }
        app(WorkLedger::class)->dispatch($connection->id);
    }
}
