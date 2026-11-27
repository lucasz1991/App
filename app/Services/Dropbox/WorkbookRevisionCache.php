<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class WorkbookRevisionCache
{
    /** Callers hold the existing file lock. Only the raw parser output is reusable. */
    public function read(DropboxClient $client, DropboxConnection $connection, DropboxSource $source, ?array $prepared = null): array
    {
        if ($connection->isLocalImport()) {
            $download = $client->downloadSource($connection, $source);
            $download['parsed'] = $prepared ?? app(WorkbookReader::class)->read($download['bytes'], $source->profile);

            return $download;
        }

        $guard = app(SyncGuard::class);
        $guard->assertSourceAvailable($connection, $source);
        $path = $this->path($source);
        if (File::isFile($path)) {
            $metadata = $client->sourceMetadata($connection, $source);
            try {
                $snapshot = Crypt::decrypt(File::get($path));
                if ($snapshot['identity'] === $this->identity($connection, $source, $metadata)) {
                    return [
                        'metadata' => $metadata,
                        'bytes' => $snapshot['bytes'],
                        'parsed' => json_decode(gzdecode($snapshot['parsed']), true, 512, JSON_THROW_ON_ERROR),
                    ];
                }
            } catch (DecryptException|FileNotFoundException) {
                // An unreadable or evicted snapshot is rebuilt from the scoped live file.
            }
        }

        $download = $client->downloadSource($connection, $source);
        $download['parsed'] = app(WorkbookReader::class)->read($download['bytes'], $source->profile);
        $this->store($connection, $source, $download['metadata'], $download['bytes'], $download['parsed']);

        return $download;
    }

    public function store(DropboxConnection $connection, DropboxSource $source, array $metadata, string $bytes, array $parsed): void
    {
        app(SyncGuard::class)->assertSourceMetadata($source, $metadata);
        if (isset($metadata['content_hash']) && ! hash_equals($metadata['content_hash'], DropboxClient::contentHash($bytes))) {
            throw new DropboxApiException('revision_conflict');
        }

        $path = $this->path($source);
        $snapshot = Crypt::encrypt([
            'identity' => $this->identity($connection, $source, $metadata),
            'bytes' => $bytes,
            'parsed' => gzencode(json_encode($parsed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 3),
        ]);
        File::ensureDirectoryExists(dirname($path), 0700);
        File::replace($path, $snapshot, 0600);
    }

    private function identity(DropboxConnection $connection, DropboxSource $source, array $metadata): array
    {
        return [
            $connection->id, $connection->generation, $source->file_id, $source->profile,
            SourceSyncReport::VERSION, $metadata['rev'], $metadata['content_hash'] ?? null,
        ];
    }

    private function path(DropboxSource $source): string
    {
        return Storage::disk('local')->path('private/dropbox-revisions/'.$source->connection_id.'/'.$source->id.'.snapshot');
    }
}
