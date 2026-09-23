<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use Illuminate\Support\Facades\DB;

class RevisionUploader
{
    public function __construct(private DropboxClient $client, private SyncGuard $guard) {}

    public function upload(DropboxConnection $connection, string $path, string $bytes, ?string $revision, array $manifest = []): array
    {
        $this->guard->current($connection, write: true);
        $hash = DropboxClient::contentHash($bytes);
        $ledgerPath = mb_strtolower($path);
        $pending = DB::table('dropbox_uploads')->where('connection_id', $connection->id)->where('generation', $connection->generation)
            ->where('path', $ledgerPath)->where('content_hash', $hash)->whereIn('status', ['prepared', 'uncertain'])->latest('id')->first();
        if ($pending) {
            try {
                $actual = $this->client->rpc($connection, 'files/get_metadata', ['path' => $path]);
                if (($actual['content_hash'] ?? '') === $hash) {
                    DB::table('dropbox_uploads')->where('id', $pending->id)->update(['status' => 'confirmed', 'result_rev' => $actual['rev'], 'updated_at' => now()]);

                    return $actual;
                }
                if (($actual['rev'] ?? null) !== $revision) {
                    throw new DropboxApiException('revision_conflict');
                }
            } catch (DropboxApiException $e) {
                if ($e->reason !== 'not_found' || $revision !== null) {
                    throw $e;
                }
            }
        }
        $id = $pending?->id ?? DB::table('dropbox_uploads')->insertGetId(['connection_id' => $connection->id, 'generation' => $connection->generation, 'path' => $ledgerPath, 'expected_rev' => $revision, 'content_hash' => $hash, 'manifest' => json_encode($manifest), 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
        $this->guard->current($connection, write: true);
        try {
            $result = $this->client->upload($connection, $path, $bytes, $revision);
        } catch (\Throwable $e) {
            DB::table('dropbox_uploads')->where('id', $id)->update(['status' => $e instanceof DropboxApiException ? 'rejected' : 'uncertain', 'updated_at' => now()]);
            throw $e;
        }
        DB::table('dropbox_uploads')->where('id', $id)->update(['status' => 'confirmed', 'result_rev' => $result['rev'], 'updated_at' => now()]);

        return $result;
    }
}
