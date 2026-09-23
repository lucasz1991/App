<?php

namespace App\Services\Excel;

use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use App\Services\Dropbox\DropboxClient;
use RuntimeException;

class LocalWorkbookClient extends DropboxClient
{
    public function __construct(private DropboxSource $source, private string $bytes) {}

    public function download(DropboxConnection $connection, string $id): array
    {
        if (! $connection->isLocalImport() || $this->source->connection_id !== $connection->id || $id !== $this->source->file_id) {
            throw new RuntimeException('local_source_mismatch');
        }

        return ['bytes' => $this->bytes, 'metadata' => ['id' => $id, 'rev' => $this->source->rev, 'path_lower' => $this->source->path, 'content_hash' => self::contentHash($this->bytes)]];
    }

    public function rpc(DropboxConnection $connection, string $method, ?array $arguments = null): array
    {
        throw new RuntimeException('local_import_has_no_remote_api');
    }

    public function upload(DropboxConnection $connection, string $path, string $bytes, ?string $revision): array
    {
        throw new RuntimeException('local_import_is_read_only');
    }
}
