<?php

namespace App\Services\CustomerPortal;

use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalPublication;
use App\Models\OperationInquiry;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerPortalPublicationService
{
    public function publish(User $actor, int $customerId, string $type, int $id, array $data = []): CustomerPortalPublication
    {
        CustomerPortalWorkflowSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.publish');
        $data = Validator::make($data, ['location_id' => 'nullable|integer', 'summary' => 'nullable|string|max:2000'])->validate();

        return OperationsTransaction::run(function () use ($actor, $customerId, $type, $id, $data) {
            Customer::lockForUpdate()->findOrFail($customerId);
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.publish');
            $locationId = $this->location($customerId, $data['location_id'] ?? null);
            [$snapshot, $sourceRevision, $orderId] = $this->projection($customerId, $type, $id);
            $latest = CustomerPortalPublication::where('customer_id', $customerId)->where('subject_type', $type)->where('subject_id', $id)->latest('revision')->first();
            $snapshot['summary'] = $data['summary'] ?? '';

            return CustomerPortalPublication::create(['customer_id' => $customerId, 'location_id' => $locationId, 'order_id' => $orderId,
                'subject_type' => $type, 'subject_id' => $id, 'source_revision' => $sourceRevision, 'revision' => ($latest?->revision ?? 0) + 1,
                'status' => 'published', 'title' => $snapshot['title'], 'payload' => $snapshot, 'service_starts_at' => $snapshot['starts_at'] ?? null, 'service_ends_at' => $snapshot['ends_at'] ?? null, 'published_by' => $actor->id, 'published_at' => now()->utc()]);
        }, 3);
    }

    /** Released business projections are explicitly allowlisted; internal notes and personnel are never copied. */
    private function projection(int $customerId, string $type, int $id): array
    {
        if ($type === 'order') {
            $r = Order::where('customer_id', $customerId)->lockForUpdate()->findOrFail($id);

            return [$r->only(['id', 'public_id', 'order_number', 'title', 'service_type', 'starts_at', 'ends_at', 'timezone', 'location_name', 'city', 'required_staff']) + ['status' => $r->status->value], (int) ($r->revision ?? 1), $r->id];
        }
        if ($type === 'offer') {
            $r = CommercialOfferRevision::lockForUpdate()->findOrFail($id);
            $subject = match ($r->subject_type) {
                'Order' => Order::where('customer_id', $customerId)->findOrFail($r->subject_id),
                'OperationInquiry' => OperationInquiry::where('customer_id', $customerId)->findOrFail($r->subject_id),
                default => abort(404),
            };
            abort_unless(in_array($r->status, ['offered', 'accepted'], true) && $r->issued_at, 409);
            $rows = array_map(fn ($row) => array_intersect_key($row, array_flip(['title', 'unit', 'quantity_milli', 'unit_price_cents', 'total_cents', 'kind', 'included', 'starts_at', 'ends_at', 'timezone'])), $r->snapshot['positions'] ?? []);

            return [['id' => $r->id, 'title' => $subject->title, 'status' => $r->status, 'revision' => $r->revision, 'state_version' => $r->state_version,
                'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'positions' => $rows, 'total_cents' => $r->total_cents,
                'currency' => $r->snapshot['currency'] ?? 'EUR', 'terms' => $r->snapshot['terms'] ?? '', 'valid_until' => $r->valid_until?->format('Y-m-d'), 'starts_at' => $subject->starts_at, 'ends_at' => $subject->ends_at, 'timezone' => $subject->timezone], $r->state_version, $subject instanceof Order ? $subject->id : $subject->order_id];
        }
        if ($type === 'proof') {
            $r = OperationWorkflow::where('kind', 'proof')->lockForUpdate()->findOrFail($id);
            $order = Order::where('customer_id', $customerId)->findOrFail($r->order_id);
            abort_unless(in_array($r->status, ['reviewed', 'accepted', 'rejected'], true) && isset($r->payload['review']), 409);
            $rows = array_map(fn ($row) => array_intersect_key($row, array_flip(['activity', 'quantity', 'unit'])), $r->payload['rows'] ?? []);
            $decision = array_intersect_key($r->payload['customer_decision'] ?? [], array_flip(['decision', 'note', 'at', 'source', 'proof_revision']));

            return [['id' => $r->id, 'title' => $r->title, 'status' => $r->status, 'rows' => $rows, 'customer_decision' => $decision, 'starts_at' => $order->starts_at, 'ends_at' => $order->ends_at, 'timezone' => $order->timezone], $r->revision, $r->order_id];
        }
        abort(422, 'Diese Daten können nicht veröffentlicht werden.');
    }

    public function quarantineDocument(User $actor, int $customerId, UploadedFile $file, array $data): CustomerPortalPublication
    {
        CustomerPortalWorkflowSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.publish');
        $data = Validator::make($data + ['file' => $file], ['title' => 'required|string|max:180', 'kind' => 'required|in:document,invoice', 'order_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'])->validate();
        $hash = hash_file('sha256', $file->getRealPath());
        $path = 'customer-portal/quarantine/'.$customerId.'/'.Str::uuid().'/'.$hash;
        try {
            abort_unless(Storage::disk('local')->put($path, file_get_contents($file->getRealPath())), 503, 'Datei konnte nicht gespeichert werden.');

            return OperationsTransaction::run(function () use ($actor, $customerId, $file, $data, $hash, $path) {
                Customer::lockForUpdate()->findOrFail($customerId);
                app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.publish');
                $order = null;
                if ($data['order_id'] ?? null) {
                    $order = Order::where('customer_id', $customerId)->findOrFail($data['order_id']);
                }
                $existing = CustomerPortalPublication::where('customer_id', $customerId)->where('file_hash', $hash)->where('subject_type', $data['kind'])->first();
                if ($existing) {
                    Storage::disk('local')->delete($path);

                    return $existing;
                }
                $record = CustomerPortalPublication::create(['customer_id' => $customerId, 'location_id' => $this->location($customerId, $data['location_id'] ?? null), 'order_id' => $data['order_id'] ?? null,
                    'subject_type' => $data['kind'], 'subject_id' => 0, 'revision' => 1, 'source_revision' => 1, 'status' => 'quarantined', 'title' => $data['title'], 'payload' => ['title' => $data['title'], 'review_status' => 'unverified'],
                    'service_starts_at' => $order->starts_at ?? null, 'service_ends_at' => $order->ends_at ?? null, 'file_path' => $path, 'file_hash' => $hash, 'file_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180), 'file_mime' => $file->getMimeType(), 'file_size' => $file->getSize()]);
                $record->forceFill(['subject_id' => $record->id])->save();

                return $record;
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /** No scanner is simulated: a responsible person must explicitly record their completed safety review. */
    public function reviewDocument(User $actor, int $id, int $revision, bool $confirmedSafe, string $note): CustomerPortalPublication
    {
        CustomerPortalWorkflowSchema::requireReady();
        Validator::make(compact('confirmedSafe', 'note'), ['confirmedSafe' => 'accepted', 'note' => 'required|string|min:5|max:2000'])->validate();
        $reference = CustomerPortalPublication::findOrFail($id);
        app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');

        return OperationsTransaction::run(function () use ($actor, $reference, $id, $revision, $note) {
            Customer::lockForUpdate()->findOrFail($reference->customer_id);
            app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');
            $record = CustomerPortalPublication::lockForUpdate()->findOrFail($id);
            abort_unless($record->status === 'quarantined' && $record->revision === $revision && ! $record->withdrawn_at, 409);
            $latest = CustomerPortalPublication::where('customer_id', $record->customer_id)->where('subject_type', $record->subject_type)->where('subject_id', $record->subject_id)->latest('revision')->first();
            abort_unless($latest->id === $record->id, 409);
            $this->verifiedBytes($record);

            return CustomerPortalPublication::create($record->only(['customer_id', 'location_id', 'order_id', 'subject_type', 'subject_id', 'source_revision', 'service_starts_at', 'service_ends_at', 'title', 'file_path', 'file_hash', 'file_name', 'file_mime', 'file_size']) + [
                'revision' => $revision + 1, 'status' => 'published', 'payload' => ['title' => $record->title, 'review_status' => 'manually_reviewed', 'review_note' => $note], 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'published_by' => $actor->id, 'published_at' => now()->utc()]);
        }, 3);
    }

    public function withdraw(User $actor, int $id, int $revision, string $reason): void
    {
        CustomerPortalWorkflowSchema::requireReady();
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'])->validate();
        $reference = CustomerPortalPublication::findOrFail($id);
        app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');
        OperationsTransaction::run(function () use ($actor, $reference, $id, $revision, $reason) {
            Customer::lockForUpdate()->findOrFail($reference->customer_id);
            app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');
            $record = CustomerPortalPublication::lockForUpdate()->findOrFail($id);
            $latest = CustomerPortalPublication::where('customer_id', $record->customer_id)->where('subject_type', $record->subject_type)->where('subject_id', $record->subject_id)->latest('revision')->first();
            abort_unless($record->revision === $revision && $latest->id === $id && ! $record->withdrawn_at, 409);
            CustomerPortalPublication::create($record->only(['customer_id', 'location_id', 'order_id', 'subject_type', 'subject_id', 'source_revision', 'service_starts_at', 'service_ends_at', 'title']) + ['revision' => $revision + 1, 'status' => 'withdrawn', 'payload' => ['reason' => $reason], 'withdrawn_at' => now()->utc(), 'published_by' => $actor->id]);
        }, 3);
    }

    public function visible(CustomerPortalIdentity $identity, int $customerId, ?string $ability = 'orders.view'): Builder
    {
        CustomerPortalWorkflowSchema::requireReady();
        $membership = app(CustomerPortalScope::class)->membership($identity, $customerId, $ability);

        return CustomerPortalPublication::where('customer_id', $customerId)->where('status', 'published')->whereNull('withdrawn_at')
            ->when($membership->history_from, fn ($q) => $q->where('published_at', '>=', $membership->history_from))
            ->when($membership->history_from, fn ($q) => $q->where(fn ($dates) => $dates->where(fn ($documents) => $documents->whereNull('service_ends_at')->whereNull('order_id')->whereIn('subject_type', ['document', 'invoice']))->orWhere('service_ends_at', '>=', $membership->history_from->startOfDay()->utc())))
            ->when($membership->location_ids, fn ($q) => $q->whereIn('location_id', $membership->location_ids))
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereIn('order_id', app(CustomerPortalScope::class)->orders($identity, $customerId, null)->select('orders.id')))
            ->where(function ($q) use ($identity, $customerId) {
                $q->where('subject_type', '!=', 'offer')->orWhereIn('subject_id', CommercialOfferRevision::where(function ($offers) use ($identity, $customerId) {
                    $offers->where(fn ($orders) => $orders->where('subject_type', 'Order')->whereIn('subject_id', app(CustomerPortalScope::class)->orders($identity, $customerId, null)->select('orders.id')))
                        ->orWhere(fn ($inquiries) => $inquiries->where('subject_type', 'OperationInquiry')->whereIn('subject_id', app(CustomerPortalScope::class)->inquiries($identity, $customerId, null)->select('operation_inquiries.id')));
                })->select('commercial_offer_revisions.id'));
            })
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_portal_publications as newer')
                ->whereColumn('newer.customer_id', 'customer_portal_publications.customer_id')->whereColumn('newer.subject_type', 'customer_portal_publications.subject_type')
                ->whereColumn('newer.subject_id', 'customer_portal_publications.subject_id')->whereColumn('newer.revision', '>', 'customer_portal_publications.revision'));
    }

    public function download(CustomerPortalIdentity $identity, int $customerId, int $id)
    {
        $record = $this->visible($identity, $customerId, 'documents.view')->whereIn('subject_type', ['document', 'invoice'])->findOrFail($id);
        abort_unless($record->reviewed_by && $record->reviewed_at, 409);
        $this->verifiedBytes($record);

        return Storage::disk('local')->download($record->file_path, $record->file_name, ['Content-Type' => $record->file_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function managerDownload(User $actor, int $id)
    {
        CustomerPortalWorkflowSchema::requireReady();
        $record = CustomerPortalPublication::whereIn('subject_type', ['document', 'invoice'])->findOrFail($id);
        app(CustomerPortalScope::class)->authorizeManager($actor, $record->customer_id, 'customers.portal.publish');
        $this->verifiedBytes($record);

        return Storage::disk('local')->download($record->file_path, $record->file_name, ['Content-Type' => $record->file_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function verifiedBytes(CustomerPortalPublication $record): void
    {
        abort_unless($record->file_path && str_starts_with($record->file_path, 'customer-portal/quarantine/'.$record->customer_id.'/') && ! str_contains($record->file_path, '..') && Storage::disk('local')->exists($record->file_path), 404);
        abort_unless(hash_equals($record->file_hash ?? '', hash('sha256', Storage::disk('local')->get($record->file_path))), 409);
    }

    private function location(int $customerId, ?int $id): ?int
    {
        return $id ? CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->findOrFail($id)->id : null;
    }
}
