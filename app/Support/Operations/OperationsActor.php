<?php

namespace App\Support\Operations;

use App\Models\User;

final class OperationsActor
{
    public static function authorize(User|OperationsAutomationActor $actor, string $ability, string $operation): void
    {
        if ($actor instanceof OperationsAutomationActor) {
            $actor->authorize($operation, $ability);
        } else {
            OperationsAccess::authorize($actor, $ability);
        }
    }

    public static function internalId(User|OperationsAutomationActor $actor): ?int
    {
        return $actor instanceof User ? (int) $actor->id : null;
    }
}
