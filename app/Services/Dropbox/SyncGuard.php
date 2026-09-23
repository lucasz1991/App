<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;

class SyncGuard
{
    public function current(DropboxConnection $connection, bool $write = false, bool $preview = false, bool $lock = false): DropboxConnection
    {
        $query = DropboxConnection::whereKey($connection->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        if ($fresh->isLocalImport() || $fresh->generation !== $connection->generation || ! $fresh->refresh_token || (! $preview && ! $fresh->mode->active()) || ($write && $fresh->mode !== DropboxMode::Bidirectional)) {
            throw new DropboxApiException('configuration_changed');
        }

        return $fresh;
    }

    public function pathAllowed(DropboxConnection $connection, string $path): bool
    {
        $path = mb_strtolower($path);
        if ($connection->option('matrix_path') && $path === mb_strtolower($connection->option('matrix_path'))) {
            return true;
        }
        foreach ($connection->option('additional_sources') as $source) {
            if ($path === mb_strtolower($source['path'])) {
                return true;
            }
        }
        foreach ($connection->option('folders') as $folder) {
            if (str_starts_with($path, rtrim(mb_strtolower($folder), '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
