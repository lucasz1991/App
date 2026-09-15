<?php

namespace App\Services\Operations;

use App\Models\OperationAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OperationsAuditService
{
    public function record(Model $subject, User $actor, string $action, array $data = []): void
    {
        OperationAudit::create([
            'subject_type' => class_basename($subject), 'subject_id' => $subject->id,
            'actor_id' => $actor->id, 'action' => $action, 'revision' => $subject->revision,
            'data' => $data, 'created_at' => now()->utc(),
        ]);
    }
}
