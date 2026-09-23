<?php

namespace App\Services\Excel;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\SyncGuard;

class LocalImportGuard extends SyncGuard
{
    public function current(DropboxConnection $connection, bool $write = false, bool $preview = false, bool $lock = false): DropboxConnection
    {
        $query = DropboxConnection::whereKey($connection->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if ($write || ! $fresh->isLocalImport() || $fresh->refresh_token || $fresh->mode !== DropboxMode::Import || $fresh->generation !== $connection->generation) {
            throw new DropboxApiException('configuration_changed');
        }

        return $fresh;
    }
}
