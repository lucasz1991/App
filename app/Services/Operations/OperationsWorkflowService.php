<?php

namespace App\Services\Operations;

use App\Models\CustomerPortalIdentity;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\PortalActor;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Validation\ValidationException;

class OperationsWorkflowService
{
    public function ready(): bool
    {
        return OperationsEnhancementsSchema::ready();
    }

    public function authorize(OperationWorkflow $record, User $actor, bool $manage = false): void
    {
        OperationsEnhancementsSchema::requireReady();
        if (! $manage && (int) $record->user_id === (int) $actor->id && in_array($record->kind, ['proof', 'travel', 'partner'], true)) {
            OperationsAccess::own($actor, $actor->id);

            return;
        }
        OperationsAccess::authorize($actor, match ($record->kind) {
            'import' => 'operations.inquiries.manage','billing' => 'operations.costs.manage',default => 'operations.manage'
        });
        if ($record->user_id) {
            app(PersonnelScopeService::class)->authorize($actor, (int) $record->user_id, 'employees.master-data.view');
        }
    }

    public function create(string $kind, string $title, array $payload, User $actor, ?int $userId = null, ?int $orderId = null, ?int $shiftId = null): OperationWorkflow
    {
        OperationsEnhancementsSchema::requireReady();

        return OperationsTransaction::run(function () use ($kind, $title, $payload, $actor, $userId, $orderId, $shiftId) {
            $record = OperationWorkflow::create(['kind' => $kind, 'title' => $title, 'payload' => $payload, 'user_id' => $userId, 'order_id' => $orderId, 'shift_id' => $shiftId, 'created_by' => $actor->id]);
            $this->snapshot($record, 'created', $actor);

            return $record;
        }, 3);
    }

    public function change(int $id, int $revision, User $actor, callable $change, bool $manage = false): OperationWorkflow
    {
        return OperationsTransaction::run(function () use ($id, $revision, $actor, $change, $manage) {
            $record = OperationWorkflow::lockForUpdate()->findOrFail($id);
            $this->authorize($record, $actor, $manage);
            $this->check($record->revision === $revision, 'Stand geändert. Bitte neu laden.');
            $action = $change($record);
            $record->revision++;
            $record->save();
            $this->snapshot($record, $action, $actor);

            return $record;
        }, 3);
    }

    public function snapshot(OperationWorkflow $record, string $action, User|CustomerPortalIdentity $actor): void
    {
        $references = [];
        if ($actor instanceof CustomerPortalIdentity) {
            abort_unless($record->kind === 'proof' && in_array($action, ['accept', 'reject', 'customer.accept', 'customer.reject', 'portal.accept', 'portal.reject'], true), 403);
            $customerId = (int) Order::findOrFail($record->order_id)->customer_id;
            app(CustomerPortalScope::class)->membership($actor, $customerId, 'proofs.accept');
            app(CustomerPortalScope::class)->orders($actor, $customerId, null)->whereKey($record->order_id)->firstOrFail();
            $references = PortalActor::references($actor, $customerId);
        }
        $record->revisions()->create(['revision' => $record->revision, 'action' => $action, 'snapshot' => $record->only(['kind', 'title', 'user_id', 'order_id', 'shift_id', 'status', 'payload']), 'actor_id' => PortalActor::internalId($actor), 'created_at' => now()->utc()] + $references);
        // Private payloads belong only in encrypted revisions, not general audit metadata.
        app(OperationsAuditService::class)->record($record, $actor, $record->kind.'.'.$action, ['revision' => $record->revision]);
    }

    public function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
