<?php

namespace App\Services\Dropbox;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChangeCapture
{
    public function record(Model $model): void
    {
        if (! Schema::hasTable('dropbox_connections')) {
            return;
        }
        $connection = DropboxConnection::remote()->latest('id')->first();
        if (! $connection || $connection->mode === DropboxMode::AppOnly) {
            return;
        }
        $type = class_basename($model);
        // Never capture passwords, login addresses or access roles from User.
        app(WorkLedger::class)->enqueue($connection, 'export', $type.':'.$model->getKey(), ['type' => $type, 'id' => $model->getKey()]);
        DB::afterCommit(function () use ($connection) {
            app(WorkLedger::class)->dispatch($connection->id);
        });
    }
}
