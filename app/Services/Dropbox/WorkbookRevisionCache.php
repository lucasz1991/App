<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class WorkbookRevisionCache
{
    /** Callers hold the existing file lock. Only the raw parser output is reusable. */
    public function read(DropboxClient $client, DropboxConnection $connection, DropboxSource $source, ?array $prepared = null, bool $replacementPreview = false): array
    {
        if ($connection->isLocalImport()) {
            $download = $client->downloadSource($connection, $source);
            $download['parsed'] = $prepared ?? app(WorkbookReader::class)->read($download['bytes'], $source->profile);

            return $download;
        }

        $guard = app(SyncGuard::class);
        $guard->assertSourceAvailable($connection, $source);
        $path = $this->path($source, $replacementPreview);
        $replacementPath = $this->path($source, true);
        $metadata = null;
        foreach ($replacementPreview ? [$path] : [$path, $replacementPath] as $candidate) {
            if (! File::isFile($candidate)) {
                continue;
            }
            $metadata ??= $client->sourceMetadata($connection, $source);
            $identity = $this->identity($connection, $source, $metadata);
            // A cache failure may require parsing again. A Dropbox access or scope
            // failure above must propagate, even if the old snapshot is readable.
            $hit = rescue(function () use ($candidate, $identity, $metadata) {
                $snapshot = Crypt::decrypt(File::get($candidate));
                if ($snapshot['identity'] !== $identity) {
                    return null;
                }

                return ['bytes' => $snapshot['bytes'], 'metadata' => $metadata,
                    'parsed' => json_decode(gzdecode($snapshot['parsed']), true, 512, JSON_THROW_ON_ERROR)];
            });
            if ($hit !== null) {
                if ($candidate !== $path) {
                    $this->store($connection, $source, $metadata, $hit['bytes'], $hit['parsed']);
                }

                return $hit;
            }
        }

        $download = $client->downloadSource($connection, $source);
        $download['parsed'] = app(WorkbookReader::class)->read($download['bytes'], $source->profile);
        $this->store($connection, $source, $download['metadata'], $download['bytes'], $download['parsed'], $replacementPreview);

        return $download;
    }

    public function store(DropboxConnection $connection, DropboxSource $source, array $metadata, string $bytes, array $parsed, bool $replacementPreview = false): void
    {
        app(SyncGuard::class)->assertSourceMetadata($source, $metadata);
        if (isset($metadata['content_hash']) && ! hash_equals($metadata['content_hash'], DropboxClient::contentHash($bytes))) {
            throw new DropboxApiException('revision_conflict');
        }

        // In particular a full disk must not interrupt acknowledgement of a
        // successful upload. The remote revision and SQL ledger remain authoritative.
        rescue(function () use ($connection, $source, $metadata, $bytes, $parsed, $replacementPreview) {
            $path = $this->path($source, $replacementPreview);
            $snapshot = Crypt::encrypt([
                'identity' => $this->identity($connection, $source, $metadata),
                'bytes' => $bytes,
                'parsed' => gzencode(json_encode($parsed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 3),
            ]);
            File::ensureDirectoryExists(dirname($path), 0700);
            File::replace($path, $snapshot, 0600);
        });
    }

    private function identity(DropboxConnection $connection, DropboxSource $source, array $metadata): array
    {
        return [
            $connection->id, $connection->generation, $source->file_id, $source->profile,
            WorkbookReader::CACHE_VERSION, config('dropbox.max_file_bytes'), config('dropbox.max_uncompressed_bytes'),
            $metadata['rev'], $metadata['content_hash'] ?? null,
        ];
    }

    public function forgetConnection(DropboxConnection $connection): void
    {
        rescue(fn () => Storage::disk('local')->deleteDirectory('private/dropbox-revisions/'.(int) $connection->getKey()));
    }

    private function path(DropboxSource $source, bool $replacementPreview): string
    {
        $suffix = $replacementPreview ? '.replacement.snapshot' : '.snapshot';

        return Storage::disk('local')->path('private/dropbox-revisions/'.$source->connection_id.'/'.$source->id.$suffix);
    }
}
