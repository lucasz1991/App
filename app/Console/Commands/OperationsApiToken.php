<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\OperationsAuditService;
use App\Support\Operations\OperationsApiAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class OperationsApiToken extends Command
{
    protected $signature = 'operations:api-token {user : Numeric user ID} {--name=Integration} {--scope=*} {--days=30} {--revoke= : Token ID belonging to this user}';

    protected $description = 'Create or revoke an expiring, explicitly scoped RailTime API token';

    public function handle(): int
    {
        $data = Validator::make(['user' => $this->argument('user'), 'name' => $this->option('name'), 'days' => $this->option('days'), 'scopes' => $this->option('scope'), 'revoke' => $this->option('revoke')],
            ['user' => 'required|integer|min:1', 'name' => 'required|string|max:100', 'days' => 'required|integer|min:1|max:90', 'scopes' => ($this->option('revoke') ? 'array' : 'required|array|min:1').'|max:7', 'scopes.*' => 'required|distinct|in:'.implode(',', OperationsApiAccess::SCOPES), 'revoke' => 'nullable|integer|min:1'])->validate();
        $user = User::findOrFail($data['user']);
        if ($data['revoke']) {
            $token = $user->tokens()->where('name', 'like', 'RailTime:%')->findOrFail($data['revoke']);
            $token->delete();
            app(OperationsAuditService::class)->record($user, $user, 'api.token.revoked', ['token_id' => (int) $data['revoke'], 'source' => 'console']);
            $this->info('Token widerrufen.');

            return self::SUCCESS;
        }
        foreach ($data['scopes'] as $scope) {
            OperationsApiAccess::authorizeScope($user, $scope);
        }
        $token = $user->createToken('RailTime:'.$data['name'], $data['scopes'], now()->addDays((int) $data['days']));
        app(OperationsAuditService::class)->record($user, $user, 'api.token.created', ['token_id' => $token->accessToken->id, 'scopes' => $data['scopes'], 'source' => 'console']);
        $this->info('Token '.$token->accessToken->id.' – einmalig sichern, nicht protokollieren:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
