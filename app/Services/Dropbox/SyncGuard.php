<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Models\DropboxSource;

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

    public function assertSourceAvailable(DropboxConnection $connection, DropboxSource $source): void
    {
        if ((int) $source->connection_id !== (int) $connection->id || ! $this->pathAllowed($connection, $source->path) || in_array($source->state, ['excluded', 'missing'], true)) {
            throw new DropboxApiException('source_unavailable');
        }
    }

    /** The request used the registered path. Its occupant must still be the registered file. */
    public function assertSourceMetadata(DropboxSource $source, array $metadata): void
    {
        $path = $metadata['path_lower'] ?? $metadata['path_display'] ?? null;
        if (($metadata['.tag'] ?? 'file') !== 'file' || ($metadata['id'] ?? null) !== $source->file_id
            || ($path !== null && (! is_string($path) || mb_strtolower($path) !== mb_strtolower($source->path)))) {
            throw new DropboxApiException('source_unavailable');
        }
    }
}
