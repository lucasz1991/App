<?php

namespace App\Services\Dropbox;

use App\Jobs\Dropbox\ProcessDropboxWork;
use App\Models\DropboxConnection;
use App\Models\DropboxWorkItem;
use Illuminate\Support\Facades\DB;

class WorkLedger
{
    public function enqueue(DropboxConnection $connection, string $kind, string $resource, array $payload = [], ?int $delay = null): DropboxWorkItem
    {
        return DB::transaction(function () use ($connection, $kind, $resource, $payload, $delay) {
            $fresh = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            if ($fresh->generation !== $connection->generation) {
                throw new DropboxApiException('configuration_changed');
            }
            $item = DropboxWorkItem::where('connection_id', $fresh->id)->where('generation', $fresh->generation)->where('resource', $resource)->lockForUpdate()->first();
            if (! $item) {
                $item = new DropboxWorkItem(['connection_id' => $fresh->id, 'generation' => $fresh->generation, 'resource' => $resource, 'requested' => 0, 'completed' => 0]);
            }
            // Do not keep postponing a busy file; only the first outstanding event sets the deadline.
            if ($item->requested === $item->completed || ! $item->exists) {
                $item->available_at = now()->addSeconds($delay ?? $fresh->option('debounce_seconds'));
            }
            $item->forceFill(['kind' => $kind, 'payload' => $payload, 'requested' => $item->requested + 1, 'error_code' => null])->save();

            return $item;
        }, 3);
    }

    public function dispatch(?int $connectionId = null): int
    {
        $now = now()->toImmutable();
        $unreserved = fn ($q) => $q->whereColumn('requested', '>', 'completed')
            ->where(fn ($q) => $q->whereNull('queued_until')->orWhere('queued_until', '<=', $now))
            ->where(fn ($q) => $q->whereNull('running_until')->orWhere('running_until', '<=', $now));
        $query = DropboxWorkItem::where($unreserved)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('dropbox_connections')
                ->whereColumn('dropbox_connections.id', 'dropbox_work_items.connection_id')
                ->whereColumn('dropbox_connections.generation', 'dropbox_work_items.generation')
                ->whereNotNull('dropbox_connections.refresh_token')
                ->where(fn ($active) => $active->whereIn('dropbox_connections.mode', ['preview', 'import', 'bidirectional'])->orWhere('dropbox_work_items.payload->preview', true)));
        if ($connectionId) {
            $query->where('connection_id', $connectionId);
        }
        $count = 0;
        foreach ($query->orderBy('id')->limit(100)->get() as $item) {
            $connection = DropboxConnection::find($item->connection_id);
            if (! $connection || $connection->generation !== $item->generation || (! $connection->mode->active() && ! ($item->payload['preview'] ?? false))) {
                continue;
            }
            $leaseUntil = max($item->available_at, $now)->addMinutes(6);
            $reserved = DropboxWorkItem::whereKey($item->id)->where($unreserved)->where('available_at', $item->available_at)
                ->update(['queued_until' => $leaseUntil]);
            if (! $reserved) {
                continue;
            }
            try {
                ProcessDropboxWork::dispatch($item->id)->onConnection(config('dropbox.queue_connection'))
                    ->onQueue(match ($item->kind) {
                        'scan' => 'dropbox-events', 'file' => 'dropbox-import', default => 'dropbox-export'
                    })
                    ->delay($item->available_at);
                $count++;
            } catch (\Throwable) {
                DropboxWorkItem::whereKey($item->id)->where('queued_until', $leaseUntil)
                    ->update(['queued_until' => null, 'error_code' => 'queue_unavailable']);
            }
        }

        return $count;
    }
}
