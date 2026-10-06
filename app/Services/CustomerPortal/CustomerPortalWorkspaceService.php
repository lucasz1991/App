<?php

namespace App\Services\CustomerPortal;

use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMessage;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSubmission;
use App\Models\CustomerPortalSubmissionItem;
use App\Models\OperationInquiry;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CustomerPortalWorkspaceService
{
    public function workspace(CustomerPortalIdentity $identity, int $customerId, string $tab = 'overview'): array
    {
        CustomerPortalWorkflowSchema::requireReady();
        $scope = app(CustomerPortalScope::class);
        $member = $scope->membership($identity, $customerId);
        abort_unless(in_array($tab, ['overview', 'orders', 'requests', 'calendar', 'offers', 'proofs', 'documents', 'messages', 'reports', 'profile'], true), 404);
        $rows = [];
        $ability = match ($tab) {
            'requests' => 'requests.create', 'documents' => 'documents.view', 'messages' => 'messages.create', 'reports' => 'reports.view',
            'overview', 'profile', 'offers', 'proofs' => null, default => 'orders.view',
        };
        if ($ability) {
            $scope->membership($identity, $customerId, $ability);
        }
        if (in_array($tab, ['offers', 'proofs'], true)) {
            abort_unless(in_array('orders.view', $member->capabilities ?? [], true) && in_array($tab, $member->getRelation('setting')->modules ?? [], true), 403);
        }
        if ($tab === 'overview' && in_array('orders.view', $member->capabilities ?? [], true) && in_array('orders', $member->getRelation('setting')->modules ?? [], true)) {
            $rows = app(CustomerPortalPublicationService::class)->visible($identity, $customerId)->where('subject_type', 'order')->limit(500)->get()->map(fn ($r) => $this->publication($r))
                ->filter(fn ($r) => filled($r['ends_at']) && CarbonImmutable::parse($r['ends_at'])->greaterThanOrEqualTo(now()->utc()) && ! in_array($r['status'], ['cancelled', 'invoiced'], true))
                ->sortBy('starts_at')->take(6)->values()->all();
        }
        if (in_array($tab, ['orders', 'calendar', 'offers', 'proofs', 'documents', 'reports'], true)) {
            $types = match ($tab) {
                'offers' => ['offer'], 'proofs' => ['proof'], 'documents' => ['document', 'invoice'], default => ['order']
            };
            $rows = app(CustomerPortalPublicationService::class)->visible($identity, $customerId, $ability)->whereIn('subject_type', $types)->latest('published_at')->limit(500)->get()->map(fn ($r) => $this->publication($r))->all();
        } elseif ($tab === 'requests') {
            $rows = $this->submissions($identity, $customerId)->with('items')->latest('id')->limit(250)->get()->map(fn ($r) => $this->submission($r))->all();
            $legacy = $scope->inquiries($identity, $customerId)->whereNotIn('id', CustomerPortalSubmissionItem::select('inquiry_id'))->latest('id')->limit(250)->get()->map(fn ($r) => $this->inquiry($r))->all();
            $rows = array_merge($rows, $legacy);
            $proposals = in_array('changes.create', $member->capabilities ?? [], true) && in_array('changes', $member->getRelation('setting')->modules ?? [], true)
                ? $this->requests($identity, $customerId)->latest('id')->limit(250)->get()->map(fn ($r) => $this->request($r))->all() : [];
            $rows = array_merge($rows, $proposals);
        } elseif ($tab === 'messages') {
            $rows = $this->messages($identity, $customerId)->latest('id')->limit(500)->get()->map(fn ($r) => $this->message($r))->all();
        } elseif ($tab === 'profile') {
            $customer = $member->getRelation('customer');
            $rows = [['id' => $customer->id, 'title' => $customer->company_name, 'status' => 'active', 'subject_type' => 'profile'] + $customer->only(['customer_number', 'phone', 'street', 'postal_code', 'city', 'country'])];
        }
        $totals = ['orders' => 0, 'requests' => 0, 'proofs' => 0, 'messages' => 0];
        foreach (['orders' => 'orders.view', 'requests' => 'requests.create', 'proofs' => 'orders.view', 'messages' => 'messages.create'] as $key => $permission) {
            $module = $key === 'proofs' ? 'proofs' : explode('.', $permission)[0];
            if (! in_array($permission, $member->capabilities ?? [], true) || ! in_array($module, $member->getRelation('setting')->modules ?? [], true)) {
                continue;
            }
            $totals[$key] = match ($key) {
                'requests' => $this->submissions($identity, $customerId)->count() + $scope->inquiries($identity, $customerId)->whereNotIn('id', CustomerPortalSubmissionItem::select('inquiry_id'))->count() + (in_array('changes.create', $member->capabilities ?? [], true) && in_array('changes', $member->getRelation('setting')->modules ?? [], true) ? $this->requests($identity, $customerId)->count() : 0),
                'messages' => $this->messages($identity, $customerId)->count(),
                default => app(CustomerPortalPublicationService::class)->visible($identity, $customerId, $key === 'proofs' ? null : $permission)->where('subject_type', $key === 'proofs' ? 'proof' : 'order')->count(),
            };
        }
        $locations = CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->when($member->location_ids, fn ($q) => $q->whereIn('id', $member->location_ids))->get(['id', 'name'])->toArray();

        return ['customer' => $member->getRelation('customer')->only(['id', 'company_name', 'customer_number']), 'rows' => $rows, 'totals' => $totals, 'locations' => $locations,
            'metadata' => ['tab' => $tab, 'limited' => count($rows) >= 500, 'capabilities' => $member->capabilities, 'modules' => $member->getRelation('setting')->modules]];
    }

    public function detail(CustomerPortalIdentity $identity, int $customerId, string $type, int $id): array
    {
        CustomerPortalWorkflowSchema::requireReady();
        if (in_array($type, ['order', 'offer', 'proof', 'document', 'invoice'], true)) {
            $ability = in_array($type, ['document', 'invoice'], true) ? 'documents.view' : (in_array($type, ['offer', 'proof'], true) ? null : 'orders.view');
            $row = app(CustomerPortalPublicationService::class)->visible($identity, $customerId, $ability)->where('subject_type', $type)->findOrFail($id);
            if (in_array($type, ['offer', 'proof'], true)) {
                $m = app(CustomerPortalScope::class)->membership($identity, $customerId);
                abort_unless(in_array('orders.view', $m->capabilities ?? [], true) && in_array($type.'s', $m->getRelation('setting')->modules ?? [], true), 403);
            }
            $safe = array_intersect_key($row->payload, array_flip(['id', 'public_id', 'order_number', 'title', 'service_type', 'starts_at', 'ends_at', 'timezone', 'location_name', 'city', 'required_staff', 'status', 'positions', 'rows', 'customer_decision', 'revision', 'state_version', 'subject_type', 'subject_id', 'total_cents', 'currency', 'terms', 'valid_until', 'summary', 'review_status']));

            return $this->publication($row) + ['snapshot' => $safe, 'source_id' => $row->subject_id, 'state_version' => $safe['state_version'] ?? null];
        }

        return match ($type) {
            'submission' => $this->submissionDetail($identity, $customerId, $id),
            'inquiry' => $this->inquiry(app(CustomerPortalScope::class)->inquiries($identity, $customerId)->findOrFail($id)),
            'request' => $this->request($this->requests($identity, $customerId)->findOrFail($id)),
            'message' => $this->message($this->messages($identity, $customerId)->findOrFail($id)),
            default => abort(404),
        };
    }

    public function management(User $actor, int $customerId, string $tab = 'publications'): array
    {
        CustomerPortalWorkflowSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.publish');
        abort_unless(in_array($tab, ['publications', 'requests', 'messages'], true), 404);
        $rows = match ($tab) {
            'requests' => CustomerPortalRequest::where('customer_id', $customerId)->latest('id')->limit(500)->get()->map(fn ($r) => $this->request($r))->all(),
            'messages' => CustomerPortalMessage::where('customer_id', $customerId)->latest('id')->limit(500)->get()->map(fn ($r) => $this->message($r))->all(),
            default => CustomerPortalPublication::where('customer_id', $customerId)->latest('id')->limit(500)->get()->map(fn ($r) => $this->publication($r) + ['status' => $r->status])->all(),
        };
        $targets = Order::where('customer_id', $customerId)->latest('id')->limit(250)->get()->map(fn ($r) => ['type' => 'order', 'id' => $r->id, 'label' => $r->order_number.' · '.$r->title])->all();
        $orderIds = Order::where('customer_id', $customerId)->pluck('id');
        $inquiryIds = OperationInquiry::where('customer_id', $customerId)->pluck('id');
        $offers = CommercialOfferRevision::whereIn('status', ['offered', 'accepted'])->whereNotNull('issued_at')->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', 'Order')->whereIn('subject_id', $orderIds))->orWhere(fn ($q) => $q->where('subject_type', 'OperationInquiry')->whereIn('subject_id', $inquiryIds)))->limit(250)->get()->map(fn ($r) => ['type' => 'offer', 'id' => $r->id, 'label' => 'Angebot #'.$r->id.' · Stand '.$r->revision])->all();
        $proofs = OperationWorkflow::where('kind', 'proof')->whereIn('status', ['reviewed', 'accepted', 'rejected'])->whereIn('order_id', $orderIds)->limit(250)->get()->map(fn ($r) => ['type' => 'proof', 'id' => $r->id, 'label' => $r->title])->all();

        return ['customer' => Customer::findOrFail($customerId)->only(['id', 'company_name', 'customer_number']), 'rows' => $rows, 'targets' => array_merge($targets, $offers, $proofs)];
    }

    public function report(CustomerPortalIdentity $identity, int $customerId): string
    {
        $rows = $this->workspace($identity, $customerId, 'reports')['rows'];
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Auftrag', 'Leistung', 'Status', 'Beginn', 'Ende', 'Zeitzone'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map([$this, 'csvCell'], [$row['number'] ?? '', $row['title'], $row['status'], $row['starts_at'] ?? '', $row['ends_at'] ?? '', $row['timezone'] ?? '']), ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$csv;
    }

    public function template(CustomerPortalIdentity $identity, int $customerId, int $publicationId): array
    {
        app(CustomerPortalScope::class)->membership($identity, $customerId, 'requests.create');
        $publication = app(CustomerPortalPublicationService::class)->visible($identity, $customerId)->where('subject_type', 'order')->findOrFail($publicationId);
        $order = app(CustomerPortalScope::class)->orders($identity, $customerId)->findOrFail($publication->subject_id);
        $location = $publication->location_id ? CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->find($publication->location_id) : null;

        // Reuse operational descriptions only. The customer must select new dates and current conditions.
        return ['title' => $order->title, 'role_name' => $order->service_type ?? '', 'required_staff' => $order->required_staff, 'location_id' => $location?->id,
            'location_name' => $location?->name ?? $order->location_name ?? '', 'timezone' => $order->timezone, 'starts_at' => '', 'ends_at' => '', 'condition_id' => null, 'quantity' => null,
            'intent' => 'quote', 'reference' => '', 'note' => '', 'accept_conditions' => false, 'accepted_terms_hash' => null];
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
    }

    private function requests(CustomerPortalIdentity $identity, int $customerId): Builder
    {
        $member = app(CustomerPortalScope::class)->membership($identity, $customerId, 'changes.create');
        $query = CustomerPortalRequest::where('customer_id', $customerId)->where('identity_id', $identity->id)
            ->when($member->history_from, fn ($q) => $q->where('created_at', '>=', $member->history_from))
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereIn('order_id', app(CustomerPortalScope::class)->orders($identity, $customerId, null)->select('orders.id')));

        return $query;
    }

    private function submissions(CustomerPortalIdentity $identity, int $customerId): Builder
    {
        CustomerPortalIntakeSchema::requireReady();
        $member = app(CustomerPortalScope::class)->membership($identity, $customerId, 'requests.create');
        $allowed = app(CustomerPortalScope::class)->inquiries($identity, $customerId)->select('operation_inquiries.id');

        return CustomerPortalSubmission::where('customer_id', $customerId)
            ->when($member->history_from, fn ($q) => $q->where('created_at', '>=', $member->history_from))
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($q) => $q->where(fn ($invalid) => $invalid->where('customer_id', '!=', $customerId)->orWhereNotIn('inquiry_id', $allowed)));
    }

    private function submissionDetail(CustomerPortalIdentity $identity, int $customerId, int $id): array
    {
        $record = $this->submissions($identity, $customerId)->with('items')->find($id);
        abort_unless($record, 404);
        $data = $this->submission($record);
        $data['attachments'] = $record->identity_id === $identity->id ? app(CustomerPortalIntakeAttachmentService::class)->files($identity, $customerId, 'submission', $record->id) : [];

        return $data;
    }

    private function submission(CustomerPortalSubmission $r): array
    {
        $positions = $r->items->map(fn ($item) => array_intersect_key($item->payload, array_flip(['title', 'starts_at', 'ends_at', 'timezone', 'location_id', 'location_name', 'role_name', 'required_staff', 'condition_id', 'quantity', 'planned_break_minutes', 'train_reference', 'vehicle_reference', 'cost_center'])))->all();
        $first = $positions[0] ?? [];
        $message = $r->decision['message'] ?? 'Anfrage wird geprüft.';

        return ['id' => $r->id, 'title' => $first['title'] ?? 'Leistungsanfrage', 'number' => sprintf('PA-%06d', $r->id), 'subject_type' => 'submission', 'status' => $r->status, 'revision' => $r->revision,
            'summary' => $message, 'message' => $message, 'starts_at' => $first['starts_at'] ?? null, 'ends_at' => $first['ends_at'] ?? null, 'timezone' => $first['timezone'] ?? null, 'position_count' => count($positions),
            'snapshot' => ['title' => $first['title'] ?? 'Leistungsanfrage', 'status' => $r->status, 'reference' => $r->payload['reference'] ?? '', 'note' => $r->payload['note'] ?? '', 'intent' => $r->payload['intent'] ?? 'quote', 'positions' => $positions, 'message' => $message],
            'created_at' => $r->created_at?->toIso8601String()];
    }

    private function messages(CustomerPortalIdentity $identity, int $customerId): Builder
    {
        $member = app(CustomerPortalScope::class)->membership($identity, $customerId, 'messages.create');

        return CustomerPortalMessage::where('customer_id', $customerId)->where('visibility', 'customer')
            ->when($member->history_from, fn ($q) => $q->where('created_at', '>=', $member->history_from))
            ->where(function ($q) use ($identity, $customerId, $member) {
                $q->whereIn('order_id', app(CustomerPortalScope::class)->orders($identity, $customerId, null)->select('orders.id'));
                if (! $member->location_ids) {
                    $q->orWhereNull('order_id');
                } else {
                    $q->orWhere(fn ($own) => $own->whereNull('order_id')->where('identity_id', $identity->id));
                }
            });
    }

    private function publication(CustomerPortalPublication $r): array
    {
        $p = $r->payload;

        return ['id' => $r->id, 'publication_id' => $r->id, 'revision' => $r->revision, 'source_revision' => $r->source_revision, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
            'order_id' => $r->order_id, 'title' => $r->title, 'status' => $p['status'] ?? $r->status, 'publication_status' => $r->status, 'number' => $p['order_number'] ?? '',
            'starts_at' => $p['starts_at'] ?? null, 'ends_at' => $p['ends_at'] ?? null, 'timezone' => $p['timezone'] ?? null, 'summary' => $p['summary'] ?? '',
            'downloadable' => (bool) ($r->file_path && $r->reviewed_at && $r->status === 'published'), 'published_at' => $r->published_at?->toIso8601String()];
    }

    private function inquiry(OperationInquiry $r): array
    {
        return ['id' => $r->id, 'title' => $r->title, 'status' => $r->status, 'number' => $r->number, 'subject_type' => 'inquiry', 'order_id' => $r->order_id, 'revision' => $r->revision,
            'starts_at' => $r->starts_at?->toIso8601String(), 'ends_at' => $r->ends_at?->toIso8601String(), 'timezone' => $r->timezone, 'summary' => $r->service_type ?? $r->role_name ?? ''];
    }

    private function request(CustomerPortalRequest $r): array
    {
        return ['id' => $r->id, 'title' => $r->title, 'status' => $r->status, 'subject_type' => 'request', 'kind' => $r->kind, 'order_id' => $r->order_id, 'revision' => $r->revision,
            'summary' => $r->payload['message'] ?? '', 'proposed_fields' => $r->payload['proposed_fields'] ?? [], 'target_type' => $r->payload['target_type'] ?? 'customer', 'target_id' => $r->payload['target_id'] ?? $r->customer_id,
            'response' => $r->response['text'] ?? '', 'created_at' => $r->created_at?->toIso8601String()];
    }

    private function message(CustomerPortalMessage $r): array
    {
        return ['id' => $r->id, 'title' => $r->subject, 'subject_type' => 'message', 'order_id' => $r->order_id, 'status' => $r->identity_id ? 'received' : 'replied', 'visibility' => $r->visibility,
            'summary' => $r->body, 'body' => $r->body, 'created_at' => $r->created_at?->toIso8601String()];
    }
}
