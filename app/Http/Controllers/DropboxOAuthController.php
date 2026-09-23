<?php

namespace App\Http\Controllers;

use App\Enums\DropboxMode;
use App\Models\DropboxConnection;
use App\Services\Dropbox\DropboxClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DropboxOAuthController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        Gate::authorize('settings.manage');
        abort_unless($request->user()?->isSuperAdmin() && $request->user()?->status, 403);
    }

    public function connect(Request $request)
    {
        $this->authorizeAdmin($request);
        $connection = DropboxConnection::remote()->latest('id')->firstOrFail();
        abort_unless($connection->app_key && $connection->app_secret, 422, 'App-Key und App-Secret zuerst speichern.');
        $state = Str::random(64);
        $request->session()->put('dropbox.oauth', ['state' => $state, 'connection' => $connection->id, 'generation' => $connection->generation, 'expires' => now()->addMinutes(10)->timestamp]);

        return redirect()->away('https://www.dropbox.com/oauth2/authorize?'.http_build_query([
            'client_id' => $connection->app_key, 'response_type' => 'code', 'token_access_type' => 'offline',
            'redirect_uri' => route('dropbox.callback'), 'state' => $state, 'scope' => implode(' ', config('dropbox.scopes')),
        ]));
    }

    public function callback(Request $request, DropboxClient $client)
    {
        $this->authorizeAdmin($request);
        $state = $request->session()->pull('dropbox.oauth');
        abort_unless(is_array($state) && $state['expires'] >= now()->timestamp && hash_equals($state['state'], (string) $request->query('state')), 403);
        abort_unless(is_string($request->query('code')), 422, 'Dropbox-Freigabe wurde nicht erteilt.');
        $connection = DropboxConnection::remote()->findOrFail($state['connection']);
        abort_unless($connection->generation === $state['generation'], 409);
        $tokens = $client->exchange($connection, ['grant_type' => 'authorization_code', 'code' => $request->query('code'), 'redirect_uri' => route('dropbox.callback')]);
        $probe = clone $connection;
        $probe->access_token = $tokens['access_token'];
        $probe->expires_at = now()->addSeconds($tokens['expires_in'] ?? 14400);
        $account = $client->rpc($probe, 'users/get_current_account');
        DB::transaction(function () use ($connection, $state, $tokens, $account) {
            $current = DropboxConnection::lockForUpdate()->findOrFail($connection->id);
            abort_unless($current->generation === $state['generation'], 409);
            if ($current->account_id && $current->account_id !== $account['account_id']) {
                $current->forceFill(['mode' => DropboxMode::Off, 'generation' => $current->generation + 1, 'access_token' => null, 'refresh_token' => null])->save();
                $current = new DropboxConnection(['app_key' => $connection->app_key, 'app_secret' => $connection->app_secret, 'settings' => $connection->settings, 'generation' => 1]);
            } else {
                $current->generation++;
            }
            $current->forceFill([
                'mode' => DropboxMode::Off, 'closing' => false, 'account_id' => $account['account_id'],
                'account_label' => $account['name']['display_name'] ?? 'Dropbox',
                'namespace_id' => $account['root_info']['root_namespace_id'] ?? null,
                'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? $current->refresh_token,
                'expires_at' => now()->addSeconds($tokens['expires_in'] ?? 14400), 'scopes' => explode(' ', $tokens['scope'] ?? ''),
                'preview_at' => null, 'preview_generation' => null, 'error_code' => null,
            ])->save();
        });

        return redirect('/administrator/settings')->with('status', 'Dropbox verbunden. Vorschau starten und Quellen prüfen.');
    }
}
