<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SourceScanner
{
    public function __construct(private DropboxClient $client, private WorkLedger $ledger, private SyncGuard $guard) {}

    public function scan(DropboxConnection $connection, bool $preview = false): void
    {
        $this->guard->current($connection, preview: $preview);
        foreach ($connection->option('folders') as $path) {
            $folder = DB::table('dropbox_folders')->where('connection_id', $connection->id)->where('path', $path)->where('generation', $connection->generation)->where('preview', $preview)->first();
            if (! $folder) {
                $id = DB::table('dropbox_folders')->insertGetId(['connection_id' => $connection->id, 'path' => $path, 'generation' => $connection->generation, 'preview' => $preview, 'created_at' => now(), 'updated_at' => now()]);
                $folder = DB::table('dropbox_folders')->find($id);
            }
            $cursor = $folder->cursor;
            for ($page = 0; $page < 20; $page++) {
                $this->guard->current($connection, preview: $preview);
                try {
                    $result = $this->client->rpc($connection, $cursor ? 'files/list_folder/continue' : 'files/list_folder', $cursor ? ['cursor' => $cursor] : ['path' => $path === '/' ? '' : $path, 'recursive' => true, 'include_deleted' => true, 'limit' => 2000]);
                } catch (DropboxApiException $e) {
                    if ($e->reason !== 'cursor_reset') {
                        throw $e;
                    }
                    $cursor = null;
                    DB::table('dropbox_folders')->where('id', $folder->id)->update(['cursor' => null]);

                    continue;
                }
                DB::transaction(function () use ($connection, $preview, $result, $folder) {
                    $this->guard->current($connection, preview: $preview, lock: true);
                    foreach ($result['entries'] as $entry) {
                        $this->register($connection, $entry, $preview);
                    }
                    DB::table('dropbox_folders')->where('id', $folder->id)->update(['cursor' => $result['cursor'], 'updated_at' => now()]);
                });
                $cursor = $result['cursor'];
                if (! $result['has_more']) {
                    break;
                }
                if ($page === 19) {
                    $this->ledger->enqueue($connection, 'scan', ($preview ? 'preview:' : '').'scan', ['preview' => $preview], 0);
                }
            }
        }
        $explicit = $connection->option('additional_sources');
        if ($connection->option('matrix_path')) {
            $explicit[] = ['path' => $connection->option('matrix_path'), 'profile' => 'matrix'];
        }
        foreach ($explicit as $configured) {
            try {
                $entry = $this->client->rpc($connection, 'files/get_metadata', ['path' => $configured['path']]);
                DB::transaction(fn () => $this->register($connection, $entry, $preview));
                DropboxConflict::where('connection_id', $connection->id)->where('reason', 'matrix_missing')->get()->filter(fn ($c) => ($c->snapshot['path'] ?? '') === $configured['path'])->each(fn ($c) => $c->update(['state' => 'resolved']));
            } catch (DropboxApiException $e) {
                if ($e->reason !== 'not_found') {
                    throw $e;
                } app(ConflictStore::class)->put($connection, null, null, 'matrix_missing', ['path' => $configured['path']], $configured['path']);
            }
        }
        // A parser improvement must also revisit unchanged files retained by
        // Dropbox's cursor, once per version; users need not upload them again.
        if (! $preview) {
            foreach (DropboxSource::where('connection_id', $connection->id)->whereNotIn('state', ['missing', 'excluded'])->get() as $source) {
                if (($source->last_report['parser_version'] ?? null) !== SourceSyncReport::VERSION) {
                    $this->ledger->enqueue($connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);
                }
            }
        }
        DropboxConnection::whereKey($connection->id)->where('generation', $connection->generation)->update(['checked_at' => now()]);
    }

    public function register(DropboxConnection $connection, array $entry, bool $preview = false): ?DropboxSource
    {
        $path = $entry['path_lower'] ?? $entry['path_display'] ?? '';
        if (($entry['.tag'] ?? '') === 'deleted') {
            if (! $this->guard->pathAllowed($connection, $path)) {
                return null;
            }
            // Cursor entries can include the deletion of a file that has already
            // been replaced at the same path. The current path wins in either order.
            try {
                $current = $this->client->rpc($connection, 'files/get_metadata', ['path' => $path]);
                if (($current['.tag'] ?? 'file') === 'file') {
                    return $this->register($connection, $current, $preview);
                }
            } catch (DropboxApiException $e) {
                if ($e->reason !== 'not_found') {
                    throw $e;
                }
            }
            foreach (DropboxSource::where('connection_id', $connection->id)->where('path', $path)->get() as $source) {
                if (isset($source->last_report['replacement_source_id'])) {
                    continue; // A superseded baseline remains excluded evidence.
                }
                $source->update(['state' => 'missing']);
                app(ConflictStore::class)->put($connection, $source, null, 'source_missing', ['path' => $path]);
            }

            return null;
        }
        if (($entry['.tag'] ?? 'file') !== 'file' || ! $this->guard->pathAllowed($connection, $path)) {
            return null;
        }
        $source = DropboxSource::where('connection_id', $connection->id)->where('file_id', $entry['id'])->first();
        if ($source && isset($source->last_report['replacement_source_id'])) {
            return null; // Historical cursor entries cannot reactivate an audited replacement.
        }
        $isMatrix = $connection->option('matrix_path') && mb_strtolower($connection->option('matrix_path')) === mb_strtolower($path);
        $configured = collect($connection->option('additional_sources'))->first(fn ($s) => mb_strtolower($s['path']) === mb_strtolower($path));
        $isMatrix = $isMatrix || ($configured['profile'] ?? '') === 'matrix';
        $match = app(WeekFileMatcher::class)->match($entry['name'], $connection->option('filename_rule'));
        if (! $isMatrix && ! $match && ! $configured) {
            if ($source) {
                $source->update(['state' => 'excluded']);
            }

            return null;
        }
        if (! $source && ! $connection->option('auto_discover') && ! $isMatrix && ! $configured) {
            return null;
        }
        if (! $source) {
            $source = $this->replacement($connection, $path, $isMatrix ? 'matrix' : 'weekly', $entry);
        }
        $source ??= new DropboxSource(['connection_id' => $connection->id, 'file_id' => $entry['id'], 'first_seen_at' => now()]);
        if (in_array($source->state, ['missing', 'excluded'], true)) {
            $source->state = 'new';
            $source->processed_rev = null;
        }
        $source->fill(['path' => $path, 'name' => $entry['name'], 'profile' => $isMatrix ? 'matrix' : 'weekly', 'rev' => $entry['rev'], 'server_modified' => $entry['server_modified'] ?? null]);
        $source->save();
        DropboxConflict::where('source_id', $source->id)->where('reason', 'source_missing')->update(['state' => 'resolved']);
        if (! $preview && ! $connection->option('watch_history') && $source->weeks && max($source->weeks) < now($connection->option('timezone'))->format('o-\WW')) {
            return $source;
        }
        if ($source->processed_rev !== $source->rev || $preview) {
            $this->ledger->enqueue($connection, 'file', ($preview ? 'preview:' : '').'file:'.$source->id, ['source_id' => $source->id, 'preview' => $preview]);
        }

        return $source;
    }

    private function replacement(DropboxConnection $connection, string $path, string $profile, array $entry): ?DropboxSource
    {
        $candidates = DropboxSource::where('connection_id', $connection->id)->where('path', $path)->where('profile', $profile)->get()
            ->reject(fn ($source) => isset($source->last_report['replacement_source_id']));
        if ($candidates->count() !== 1) {
            return null;
        }
        $candidate = $candidates->first();
        $lock = Cache::store(config('dropbox.lock_store'))->lock('dropbox-file:'.$connection->id.':'.$candidate->file_id, 330);
        if (! $lock->get()) {
            throw new DropboxApiException('file_busy', 5);
        }
        try {
            try {
                $this->client->rpc($connection, 'files/get_metadata', ['path' => $candidate->file_id]);

                return null; // A moved or still-active file keeps its own baseline.
            } catch (DropboxApiException $e) {
                if ($e->reason !== 'not_found') {
                    throw $e;
                }
            }

            return DB::transaction(function () use ($connection, $candidate, $entry, $path, $profile) {
                $this->guard->current($connection, lock: true);
                if (DropboxSource::where('connection_id', $connection->id)->where('file_id', $entry['id'])->exists()) {
                    return DropboxSource::where('connection_id', $connection->id)->where('file_id', $entry['id'])->first();
                }
                $fresh = DropboxSource::lockForUpdate()->findOrFail($candidate->id);
                if ($fresh->path !== $path || $fresh->profile !== $profile || $fresh->file_id !== $candidate->file_id) {
                    throw new DropboxApiException('file_busy', 5);
                }
                // Dropbox revisions belong to a file ID. Preserve the common row
                // baselines but discard every progress/own-revision shortcut.
                $fresh->forceFill(['file_id' => $entry['id'], 'state' => 'new', 'processed_rev' => null, 'own_rev' => null, 'progress' => null])->save();

                return $fresh;
            });
        } finally {
            $lock->release();
        }
    }
}
