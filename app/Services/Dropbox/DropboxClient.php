<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DropboxClient
{
    public function token(DropboxConnection $connection): string
    {
        if ($connection->access_token && $connection->expires_at?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }

        return DB::transaction(function () use ($connection) {
            $current = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            if ($current->generation !== $connection->generation || ! $current->refresh_token) {
                throw new DropboxApiException('reconnect_required');
            }
            if ($current->access_token && $current->expires_at?->isAfter(now()->addMinute())) {
                return $current->access_token;
            }
            $result = $this->exchange($current, ['grant_type' => 'refresh_token', 'refresh_token' => $current->refresh_token]);
            $current->forceFill(['access_token' => $result['access_token'], 'expires_at' => now()->addSeconds($result['expires_in'] ?? 14400), 'error_code' => null])->save();

            return $current->access_token;
        });
    }

    public function exchange(DropboxConnection $connection, array $parameters): array
    {
        $response = Http::asForm()->withBasicAuth($connection->app_key, $connection->app_secret)
            ->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])
            ->post('https://api.dropboxapi.com/oauth2/token', $parameters);
        if (! $response->successful()) {
            throw new DropboxApiException('reconnect_required');
        }

        return $response->json();
    }

    public function rpc(DropboxConnection $connection, string $method, ?array $arguments = null): array
    {
        $response = $this->authenticated($connection, function () use ($connection, $method, $arguments) {
            $request = Http::withToken($this->token($connection))->connectTimeout(5)->timeout(25)
                ->withOptions(['allow_redirects' => false]);
            if ($connection->namespace_id && str_starts_with($method, 'files/')) {
                $request = $request->withHeaders(['Dropbox-API-Path-Root' => json_encode(['.tag' => 'root', 'root' => $connection->namespace_id])]);
            }

            return $request->withBody(json_encode($arguments, JSON_THROW_ON_ERROR), 'application/json')
                ->post('https://api.dropboxapi.com/2/'.$method);
        });
        $this->check($response);

        return $response->json() ?? [];
    }

    /** @return array{bytes: string, metadata: array} */
    public function download(DropboxConnection $connection, string $id): array
    {
        $response = $this->authenticated($connection, fn () => $this->contentRequest($connection, ['path' => $id])->withBody('', 'application/octet-stream')->post('https://content.dropboxapi.com/2/files/download'));
        $this->check($response);
        if (strlen($response->body()) > config('dropbox.max_file_bytes')) {
            throw new DropboxApiException('file_too_large');
        }

        return ['bytes' => $response->body(), 'metadata' => json_decode($response->header('Dropbox-API-Result'), true, 512, JSON_THROW_ON_ERROR)];
    }

    public function upload(DropboxConnection $connection, string $path, string $bytes, ?string $revision): array
    {
        $response = $this->authenticated($connection, fn () => $this->contentRequest($connection, [
            'path' => $path, 'mode' => $revision ? ['.tag' => 'update', 'update' => $revision] : ['.tag' => 'add'],
            'autorename' => false, 'strict_conflict' => true, 'mute' => true,
        ])->withBody($bytes, 'application/octet-stream')->post('https://content.dropboxapi.com/2/files/upload'));
        $this->check($response);

        return $response->json();
    }

    private function contentRequest(DropboxConnection $connection, array $arguments): PendingRequest
    {
        $headers = ['Dropbox-API-Arg' => json_encode($arguments, JSON_THROW_ON_ERROR)];
        if ($connection->namespace_id) {
            $headers['Dropbox-API-Path-Root'] = json_encode(['.tag' => 'root', 'root' => $connection->namespace_id]);
        }

        return Http::withToken($this->token($connection))->withHeaders($headers)->connectTimeout(5)->timeout(60)->withOptions(['allow_redirects' => false]);
    }

    private function authenticated(DropboxConnection $connection, callable $request): Response
    {
        $response = $request();
        if ($response->status() !== 401) {
            return $response;
        }
        DB::transaction(function () use ($connection) {
            $fresh = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            if ($fresh->generation !== $connection->generation || ! $fresh->refresh_token) {
                throw new DropboxApiException('reconnect_required');
            }
            $fresh->forceFill(['access_token' => null, 'expires_at' => null])->save();
        });
        $connection->access_token = null;
        $connection->expires_at = null;

        return $request();
    }

    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $tag = ($response->json('error') ?? [])['.tag'] ?? null;
        $reason = match ($response->status()) {
            401 => 'reconnect_required', 429 => 'rate_limit',
            409 => $tag === 'reset' ? 'cursor_reset' : (str_contains((string) $response->json('error_summary'), 'not_found') ? 'not_found' : 'revision_conflict'),
            default => 'api_unavailable',
        };
        throw new DropboxApiException($reason, max(5, min(3600, (int) ($response->header('Retry-After') ?: 30))));
    }

    public static function contentHash(string $bytes): string
    {
        $hashes = '';
        for ($i = 0; $i < strlen($bytes); $i += 4194304) {
            $hashes .= hash('sha256', substr($bytes, $i, 4194304), true);
        }

        return hash('sha256', $hashes);
    }
}
