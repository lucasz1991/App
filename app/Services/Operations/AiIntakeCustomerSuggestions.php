<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\User;
use App\Support\Operations\OperationsAccess;

/** Advisory identity candidates; this service never assigns or creates customers. */
class AiIntakeCustomerSuggestions
{
    public function suggest(AiIntake $intake, User $actor): array
    {
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.inquiries.manage');
        $fresh = AiIntake::findOrFail($intake->id);
        if ($fresh->customer_id) {
            return [];
        }

        return array_map(fn ($candidate) => ['customer_id' => $candidate['customer_id'], 'reasons' => $candidate['reasons']],
            app(AiIntakeCustomerContextService::class)->candidates($fresh, $actor));
    }
}
