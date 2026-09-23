<?php

namespace App\Http\Controllers;

use App\Models\DropboxConnection;
use App\Services\Dropbox\WorkLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DropboxWebhookController extends Controller
{
    public function __invoke(Request $request, WorkLedger $ledger)
    {
        if ($request->isMethod('get')) {
            $challenge = $request->query('challenge');
            abort_unless(is_string($challenge) && preg_match('/^[a-zA-Z0-9_-]{1,512}$/D', $challenge), 400);

            return response($challenge)->header('Content-Type', 'text/plain')->header('X-Content-Type-Options', 'nosniff');
        }
        $body = $request->getContent();
        abort_if(strlen($body) > 65536, 413);
        abort_unless(Schema::hasTable('dropbox_connections'), 503);
        $connections = DropboxConnection::whereNotNull('app_secret')->get()->filter(fn ($c) => hash_equals(hash_hmac('sha256', $body, $c->app_secret), (string) $request->header('X-Dropbox-Signature')));
        abort_if($connections->isEmpty(), 403);
        $payload = json_decode($body, true);
        abort_unless(is_array($payload) && is_array($payload['list_folder']['accounts'] ?? null), 400);
        DB::transaction(function () use ($connections, $payload, $ledger) {
            foreach ($connections as $connection) {
                if (! in_array($connection->account_id, $payload['list_folder']['accounts'], true)) {
                    continue;
                }
                $connection->forceFill(['webhook_at' => now()])->save();
                if ($connection->mode->active()) {
                    $ledger->enqueue($connection, 'scan', 'scan');
                }
            }
        });
        // SQL is the acknowledgement boundary. A dead Redis server cannot make
        // an acknowledged webhook disappear; the scheduler re-dispatches it.
        app()->terminating(fn () => $ledger->dispatch());

        return response('', 200);
    }
}
