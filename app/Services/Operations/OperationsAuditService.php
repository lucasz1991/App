<?php

namespace App\Services\Operations;

use App\Models\CustomerPortalIdentity;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerPortal\PortalActor;
use App\Support\Operations\OperationsAutomationActor;
use Illuminate\Database\Eloquent\Model;

class OperationsAuditService
{
    public function record(Model $subject, User|CustomerPortalIdentity|OperationsAutomationActor $actor, string $action, array $data = []): void
    {
        $references = [];
        if ($actor instanceof OperationsAutomationActor) {
            $actor->authorize('audit', $subject instanceof OperationInquiry ? 'operations.inquiries.manage' : 'operations.manage');
            $references = $actor->references();
        }
        if ($actor instanceof CustomerPortalIdentity) {
            $customerId = $subject->customer_id;
            if (! $customerId && $subject->order_id) {
                $customerId = Order::findOrFail($subject->order_id)->customer_id;
            }
            if (! $customerId && $subject->subject_id) {
                $customerId = match ($subject->subject_type) {
                    'Order' => Order::findOrFail($subject->subject_id)->customer_id,
                    'OperationInquiry' => OperationInquiry::findOrFail($subject->subject_id)->customer_id,
                    default => abort(403),
                };
            }
            $references = PortalActor::references($actor, (int) $customerId);
        }
        OperationAudit::create([
            'subject_type' => class_basename($subject), 'subject_id' => $subject->id,
            'actor_id' => PortalActor::internalId($actor), 'action' => $action, 'revision' => $subject->revision,
            'data' => $data, 'created_at' => now()->utc(),
        ] + $references);
    }
}
