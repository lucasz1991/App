<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClosingService
{
    public function start(DropboxConnection $connection, User $actor): void
    {
        app(ConnectionManager::class)->authorize($actor);
        if ($connection->mode !== DropboxMode::Bidirectional) {
            throw ValidationException::withMessages(['closing' => 'Der Abschluss benötigt einen eingerichteten beidseitigen Abgleich.']);
        }
        DB::transaction(function () use ($connection) {
            app(SyncGuard::class)->current($connection, write: true, lock: true);
            $connection->forceFill(['closing' => true, 'closing_started_at' => now(), 'archive_path' => '/RailTime-Archiv-'.now()->format('Ymd-His').'-'.$connection->id.'-'.$connection->generation])->save();
            app(ConnectionManager::class)->reconcile($connection);
        });
    }

    public function advance(DropboxConnection $connection): void
    {
        if (! $connection->closing || $connection->mode !== DropboxMode::Bidirectional) {
            return;
        }
        if (DropboxWorkItem::where('connection_id', $connection->id)->where('generation', $connection->generation)->whereColumn('requested', '>', 'completed')->exists()) {
            return;
        }
        if (DropboxConflict::where('connection_id', $connection->id)->whereIn('state', ['open', 'rechecking'])->exists()) {
            return;
        }
        if (! $connection->checked_at || $connection->checked_at->lt($connection->closing_started_at)) {
            return;
        }
        $sources = DropboxSource::where('connection_id', $connection->id)->whereNotIn('state', ['missing', 'excluded'])->get();
        foreach ($sources as $source) {
            if ($source->state !== 'synchronized') {
                return;
            }
            if (! DB::table('dropbox_archives')->where('source_id', $source->id)->where('generation', $connection->generation)->where('source_rev', $source->processed_rev)->exists()) {
                app(WorkLedger::class)->enqueue($connection, 'archive', 'archive:'.$source->id, ['source_id' => $source->id], 0);
            }
        }
        if (DropboxWorkItem::where('connection_id', $connection->id)->where('generation', $connection->generation)->whereColumn('requested', '>', 'completed')->exists()) {
            return;
        }
        app(WorkLedger::class)->enqueue($connection, 'finish', 'finish', [], 0);
    }

    public function archive(DropboxConnection $connection, DropboxSource $source): void
    {
        app(SyncGuard::class)->current($connection, write: true);
        if (! $connection->closing) {
            throw new DropboxApiException('configuration_changed');
        }
        $client = app(DropboxClient::class);
        try {
            $client->rpc($connection, 'files/create_folder_v2', ['path' => $connection->archive_path, 'autorename' => false]);
        } catch (DropboxApiException $e) {
            if ($e->reason !== 'revision_conflict') {
                throw $e;
            }
            $folder = $client->rpc($connection, 'files/get_metadata', ['path' => $connection->archive_path]);
            if (($folder['.tag'] ?? '') !== 'folder') {
                throw $e;
            }
        }
        $download = $client->download($connection, $source->file_id);
        if ($download['metadata']['rev'] !== $source->processed_rev) {
            app(WorkLedger::class)->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);
            throw new DropboxApiException('revision_conflict');
        }
        $path = $connection->archive_path.'/'.$source->id.'-'.$source->processed_rev.'-'.$source->name;
        $metadata = app(RevisionUploader::class)->upload($connection, $path, $download['bytes'], null);
        DB::table('dropbox_archives')->updateOrInsert(['source_id' => $source->id, 'generation' => $connection->generation, 'source_rev' => $source->processed_rev], ['connection_id' => $connection->id, 'archive_path' => $path, 'archive_rev' => $metadata['rev'], 'created_at' => now(), 'updated_at' => now()]);
    }

    public function finish(DropboxConnection $connection): void
    {
        app(SyncGuard::class)->current($connection, write: true);
        if (! $connection->closing) {
            throw new DropboxApiException('configuration_changed');
        }
        $client = app(DropboxClient::class);
        foreach (DropboxSource::where('connection_id', $connection->id)->whereNotIn('state', ['missing', 'excluded'])->get() as $source) {
            $actual = $client->rpc($connection, 'files/get_metadata', ['path' => $source->file_id]);
            if ($actual['rev'] !== $source->processed_rev || ! DB::table('dropbox_archives')->where('source_id', $source->id)->where('generation', $connection->generation)->where('source_rev', $actual['rev'])->exists()) {
                app(WorkLedger::class)->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);
                throw new DropboxApiException('revision_conflict');
            }
        }
        DB::transaction(function () use ($connection) {
            $fresh = app(SyncGuard::class)->current($connection, write: true, lock: true);
            if (DropboxWorkItem::where('connection_id', $fresh->id)->where('generation', $fresh->generation)->where('kind', '!=', 'finish')->whereColumn('requested', '>', 'completed')->exists()
                || DropboxConflict::where('connection_id', $fresh->id)->whereIn('state', ['open', 'rechecking'])->exists()) {
                throw new DropboxApiException('closing_pending');
            }
            $fresh->forceFill(['mode' => DropboxMode::AppOnly, 'closing' => false, 'generation' => $fresh->generation + 1, 'access_token' => null, 'refresh_token' => null])->save();
        });
        // Local detachment has already completed; revocation is best effort.
        try {
            $client->rpc($connection, 'auth/token/revoke');
        } catch (\Throwable) {
        }
    }
}
