<?php

namespace App\Support\Operations;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class OperationsApiAccess
{
    public const SCOPES = ['operations:own:read', 'operations:own:write', 'operations:times:read', 'operations:times:review', 'operations:times:export', 'operations:absences:read', 'operations:absences:review'];

    public static function authorize(Request $request, string $scope): User
    {
        $actor = $request->user();
        abort_unless($actor && $request->bearerToken() && $actor->currentAccessToken() instanceof PersonalAccessToken, 401);
        // Explicit scopes only: legacy wildcard tokens never gain payroll access.
        abort_unless(in_array($scope, $actor->currentAccessToken()->abilities ?? [], true), 403);
        self::authorizeScope($actor, $scope);
        OperationsAccess::requireReady();

        return $actor;
    }

    public static function authorizeScope(User $actor, string $scope): void
    {
        abort_unless($actor->status && $actor->hasVerifiedEmail() && in_array($scope, self::SCOPES, true), 403);
        match ($scope) {
            'operations:own:read', 'operations:own:write' => OperationsAccess::own($actor, $actor->id),
            'operations:times:read' => abort_unless($actor->can('operations.time.review') || $actor->can('operations.time.export'), 403),
            'operations:times:review' => OperationsAccess::authorize($actor, 'operations.time.review'),
            'operations:times:export' => OperationsAccess::authorize($actor, 'operations.time.export'),
            'operations:absences:read', 'operations:absences:review' => OperationsAccess::authorize($actor, 'operations.absences.review'),
        };
    }
}
