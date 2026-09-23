<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;

class ConflictStore
{
    public function put(DropboxConnection $connection, ?DropboxSource $source, ?DropboxRecord $record, string $reason, array $snapshot, string $slot = ''): DropboxConflict
    {
        $key = hash('sha256', implode(':', [$connection->id, $source?->id, $record?->id, $reason, $slot]));
        $conflict = DropboxConflict::firstOrNew(['key' => $key]);
        $old = $conflict->snapshot;
        if ($reason === 'not_representable' && $old === $snapshot && $conflict->state === 'acknowledged') {
            return $conflict;
        }
        if ($old !== $snapshot) {
            $conflict->decision = null;
            $conflict->resolved_by = null;
        }
        $conflict->forceFill(['connection_id' => $connection->id, 'source_id' => $source?->id, 'record_id' => $record?->id, 'reason' => $reason, 'snapshot' => $snapshot, 'state' => 'open'])->save();

        return $conflict;
    }
}
