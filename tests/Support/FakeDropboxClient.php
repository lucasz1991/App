<?php

namespace Tests\Support;

use App\Models\DropboxConnection;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\DropboxClient;

class FakeDropboxClient extends DropboxClient
{
    public array $files = [];

    public int $uploads = 0;

    public int $downloads = 0;

    public bool $failAfterUpload = false;

    public function put(string $path, string $bytes): array
    {
        $path = mb_strtolower($path);
        $old = $this->files[$path] ?? null;
        $metadata = ['.tag' => 'file', 'id' => $old['metadata']['id'] ?? 'id:'.count($this->files), 'name' => basename($path), 'path_lower' => $path, 'rev' => (string) (($old['metadata']['rev'] ?? 0) + 1), 'content_hash' => self::contentHash($bytes), 'server_modified' => now()->toIso8601String()];
        $this->files[$path] = compact('bytes', 'metadata');

        return $metadata;
    }

    private function file(string $path): array
    {
        foreach ($this->files as $key => $file) {
            if ($key === mb_strtolower($path) || $file['metadata']['id'] === $path) {
                return $file;
            }
        }
        throw new DropboxApiException('not_found');
    }

    public function rpc(DropboxConnection $connection, string $method, ?array $arguments = null): array
    {
        return match ($method) {
            'files/get_metadata' => $this->file($arguments['path'])['metadata'],
            'files/list_folder', 'files/list_folder/continue' => ['entries' => array_column($this->files, 'metadata'), 'cursor' => 'cursor-1', 'has_more' => false],
            'users/get_current_account' => ['account_id' => 'dbid:test', 'name' => ['display_name' => 'Testkonto'], 'root_info' => ['root_namespace_id' => 'root']],
            default => [],
        };
    }

    public function download(DropboxConnection $connection, string $id): array
    {
        $this->downloads++;

        return $this->file($id);
    }

    public function upload(DropboxConnection $connection, string $path, string $bytes, ?string $revision): array
    {
        $existing = $this->files[mb_strtolower($path)] ?? null;
        if (($existing && $existing['metadata']['rev'] !== $revision) || (! $existing && $revision !== null)) {
            throw new DropboxApiException('revision_conflict');
        }
        $this->uploads++;
        $metadata = $this->put($path, $bytes);
        if ($this->failAfterUpload) {
            $this->failAfterUpload = false;
            throw new \RuntimeException('network_timeout');
        }

        return $metadata;
    }
}
