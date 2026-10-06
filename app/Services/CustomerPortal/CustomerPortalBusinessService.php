<?php

namespace App\Services\CustomerPortal;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMessage;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalRequest;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\OperationsWorkflowService;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerPortalBusinessService
{
    public function submitRequest(CustomerPortalIdentity $identity, int $customerId, string $uuid, array $input): CustomerPortalRequest
    {
        CustomerPortalWorkflowSchema::requireReady();
        $data = Validator::make($input + ['uuid' => $uuid], ['uuid' => 'required|uuid', 'kind' => 'required|in:change,complaint,master_data,cancel,disruption,replacement', 'order_id' => 'nullable|integer', 'title' => 'required|string|max:180', 'message' => 'required|string|min:5|max:5000', 'target_type' => 'nullable|in:customer,contact,location', 'target_id' => 'nullable|integer|min:1', 'proposed_fields' => 'nullable|array', 'proposed_fields.name' => 'nullable|string|max:180', 'proposed_fields.company_name' => 'nullable|string|max:180', 'proposed_fields.email' => 'nullable|email:rfc|max:254', 'proposed_fields.phone' => 'nullable|string|max:80', 'proposed_fields.street' => 'nullable|string|max:180', 'proposed_fields.postal_code' => 'nullable|string|max:20', 'proposed_fields.city' => 'nullable|string|max:100', 'proposed_fields.country' => 'nullable|size:2'])->validate();
        // Changes are proposals. Never mutate customer contacts, security e-mail or live orders here.
        $targetType = $data['target_type'] ?? 'customer';
        $allowedFields = match ($targetType) {
            'contact' => ['name', 'email', 'phone'], 'location' => ['name', 'street', 'postal_code', 'city', 'country'], default => ['company_name', 'phone', 'street', 'postal_code', 'city', 'country'],
        };
        $data['proposed_fields'] = array_intersect_key($data['proposed_fields'] ?? [], array_flip($allowedFields));
        $data['target_type'] = $targetType;
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return OperationsTransaction::run(function () use ($identity, $customerId, $uuid, $data, $hash) {
            Customer::lockForUpdate()->findOrFail($customerId);
            $member = app(CustomerPortalScope::class)->membership($identity, $customerId, 'changes.create', true);
            $existing = CustomerPortalRequest::where('identity_id', $identity->id)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless($existing->customer_id === $customerId && hash_equals($existing->request_hash, $hash), 409);

                return $existing;
            }
            if ($data['order_id'] ?? null) {
                app(CustomerPortalScope::class)->orders($identity, $customerId, null)->lockForUpdate()->findOrFail($data['order_id']);
            }
            abort_unless($data['kind'] === 'master_data' || ($data['order_id'] ?? null), 422);
            if ($data['kind'] === 'master_data') {
                if ($data['target_type'] === 'contact') {
                    abort_unless((int) ($data['target_id'] ?? 0) === (int) $member->contact_id, 403);
                    CustomerContact::where('customer_id', $customerId)->findOrFail($data['target_id']);
                } elseif ($data['target_type'] === 'location') {
                    $location = CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->findOrFail($data['target_id'] ?? 0);
                    abort_unless(! $member->location_ids || in_array($location->id, $member->location_ids, true), 403);
                } else {
                    abort_unless(! isset($data['target_id']) || (int) $data['target_id'] === $customerId, 403);
                }
            }

            return CustomerPortalRequest::create(['customer_id' => $customerId, 'membership_id' => $member->id, 'identity_id' => $identity->id, 'order_id' => $data['order_id'] ?? null,
                'client_uuid' => $uuid, 'request_hash' => $hash, 'kind' => $data['kind'], 'title' => $data['title'], 'status' => 'submitted', 'payload' => ['message' => $data['message'], 'proposed_fields' => $data['proposed_fields'], 'target_type' => $data['target_type'], 'target_id' => $data['target_id'] ?? $customerId]]);
        }, 3);
    }

    public function reviewRequest(User $actor, int $id, int $revision, string $status, string $response): CustomerPortalRequest
    {
        CustomerPortalWorkflowSchema::requireReady();
        Validator::make(compact('status', 'response'), ['status' => 'required|in:reviewing,resolved,rejected', 'response' => 'required|string|min:5|max:5000'])->validate();
        $reference = CustomerPortalRequest::findOrFail($id);
        app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');

        return OperationsTransaction::run(function () use ($actor, $reference, $id, $revision, $status, $response) {
            Customer::lockForUpdate()->findOrFail($reference->customer_id);
            app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');
            $record = CustomerPortalRequest::lockForUpdate()->findOrFail($id);
            abort_unless($record->revision === $revision && in_array($record->status, ['submitted', 'reviewing'], true), 409);
            $record->forceFill(['status' => $status, 'revision' => $revision + 1, 'response' => ['text' => $response, 'at' => now()->utc()->toIso8601String()], 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc()])->save();

            return $record;
        }, 3);
    }

    public function message(CustomerPortalIdentity $identity, int $customerId, string $uuid, array $input): CustomerPortalMessage
    {
        CustomerPortalWorkflowSchema::requireReady();
        $data = Validator::make($input + ['uuid' => $uuid], ['uuid' => 'required|uuid', 'order_id' => 'nullable|integer', 'subject' => 'required|string|max:180', 'body' => 'required|string|min:1|max:5000'])->validate();
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return OperationsTransaction::run(function () use ($identity, $customerId, $uuid, $data, $hash) {
            Customer::lockForUpdate()->findOrFail($customerId);
            $member = app(CustomerPortalScope::class)->membership($identity, $customerId, 'messages.create', true);
            if ($data['order_id'] ?? null) {
                app(CustomerPortalScope::class)->orders($identity, $customerId, null)->findOrFail($data['order_id']);
            }
            $existing = CustomerPortalMessage::where('customer_id', $customerId)->where('client_uuid', $uuid)->first();
            if ($existing) {
                abort_unless($existing->identity_id === $identity->id && hash_equals($existing->request_hash, $hash), 409);

                return $existing;
            }

            return CustomerPortalMessage::create(['customer_id' => $customerId, 'order_id' => $data['order_id'] ?? null, 'membership_id' => $member->id, 'identity_id' => $identity->id,
                'client_uuid' => $uuid, 'request_hash' => $hash, 'visibility' => 'customer', 'subject' => $data['subject'], 'body' => $data['body']]);
        }, 3);
    }

    public function reply(User $actor, int $customerId, array $input): CustomerPortalMessage
    {
        CustomerPortalWorkflowSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.publish');
        $data = Validator::make($input, ['order_id' => 'nullable|integer', 'subject' => 'required|string|max:180', 'body' => 'required|string|min:1|max:5000', 'visibility' => 'required|in:internal,customer'])->validate();

        return OperationsTransaction::run(function () use ($actor, $customerId, $data) {
            Customer::lockForUpdate()->findOrFail($customerId);
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.publish');
            if ($data['order_id'] ?? null) {
                Order::where('customer_id', $customerId)->findOrFail($data['order_id']);
            }

            return CustomerPortalMessage::create(['customer_id' => $customerId, 'order_id' => $data['order_id'] ?? null, 'actor_id' => $actor->id, 'client_uuid' => (string) Str::uuid(),
                'request_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'visibility' => $data['visibility'], 'subject' => $data['subject'], 'body' => $data['body']]);
        }, 3);
    }

    public function decideProof(CustomerPortalIdentity $identity, int $customerId, int $publicationId, int $publicationRevision, string $action, string $note): OperationWorkflow
    {
        CustomerPortalWorkflowSchema::requireReady();
        Validator::make(compact('action', 'note'), ['action' => 'required|in:accept,reject', 'note' => 'required|string|min:5|max:2000'])->validate();

        return OperationsTransaction::run(function () use ($identity, $customerId, $publicationId, $publicationRevision, $action, $note) {
            Customer::lockForUpdate()->findOrFail($customerId);
            $member = app(CustomerPortalScope::class)->membership($identity, $customerId, 'proofs.accept', true);
            $publication = app(CustomerPortalPublicationService::class)->visible($identity, $customerId, 'proofs.accept')->where('subject_type', 'proof')->lockForUpdate()->findOrFail($publicationId);
            abort_unless($publication->revision === $publicationRevision, 409);
            app(CustomerPortalScope::class)->orders($identity, $customerId, null)->lockForUpdate()->findOrFail($publication->order_id);
            $record = OperationWorkflow::where('kind', 'proof')->where('order_id', $publication->order_id)->lockForUpdate()->findOrFail($publication->subject_id);
            abort_unless($record->revision === $publication->source_revision && $record->status === 'reviewed' && isset($record->payload['review']), 409);
            $contact = CustomerContact::where('customer_id', $customerId)->where('is_active', true)->findOrFail($member->contact_id);
            abort_unless(in_array('acceptance', $contact->roles ?? [], true), 403);
            $payload = $record->payload;
            $payload['customer_decision'] = ['contact_id' => $contact->id, 'name' => $contact->name, 'note' => $note, 'decision' => $action, 'source' => 'customer_portal',
                'proof_revision' => $record->revision, 'identity_id' => $identity->id, 'membership_id' => $member->id, 'publication_id' => $publication->id, 'publication_revision' => $publication->revision, 'at' => now()->utc()->toIso8601String()];
            $record->forceFill(['payload' => $payload, 'status' => $action === 'accept' ? 'accepted' : 'rejected', 'revision' => $record->revision + 1])->save();
            app(OperationsWorkflowService::class)->snapshot($record, $action, $identity);
            // Decision is immediately visible as a new immutable customer-safe publication.
            $next = $publication->payload;
            $next['status'] = $record->status;
            $next['customer_decision'] = array_intersect_key($payload['customer_decision'], array_flip(['decision', 'note', 'at', 'source', 'proof_revision']));
            CustomerPortalPublication::create($publication->only(['customer_id', 'location_id', 'order_id', 'subject_type', 'subject_id', 'service_starts_at', 'service_ends_at', 'title']) + ['revision' => $publicationRevision + 1, 'source_revision' => $record->revision, 'status' => 'published', 'payload' => $next, 'published_at' => now()->utc()]);

            return $record;
        }, 3);
    }
}
