<?php

namespace App\Services\CustomerPortal;

use App\Models\CommercialOfferRevision;
use App\Models\CustomerCondition;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalSubmission;
use App\Models\CustomerPortalSubmissionItem;
use App\Models\OperationInquiry;
use App\Services\Operations\CommercialOfferService;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\CustomerPortal\CustomerPortalDateTime as OperationsDateTime;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CustomerPortalSubmissionService
{
    public function submit(CustomerPortalIdentity $identity, int $customerId, string $uuid, array $input, array $attachments = []): CustomerPortalSubmission
    {
        CustomerPortalIntakeSchema::requireReady();
        Validator::make(['uuid' => $uuid], ['uuid' => 'required|uuid'])->validate();
        $data = Validator::make($input, [
            'intent' => ['required', Rule::in(['quote', 'framework'])],
            'reference' => 'nullable|string|max:100', 'note' => 'nullable|string|max:2000',
            'accept_conditions' => 'nullable|boolean', 'accepted_terms_hash' => 'nullable|string|size:64',
            'positions' => 'nullable|array|min:1|max:20',
        ])->validate();
        $positions = $input['positions'] ?? [$input];
        $validated = [];
        foreach ($positions as $position) {
            $row = Validator::make($position, [
                'title' => 'required|string|max:180', 'starts_at' => 'required|string', 'ends_at' => 'required|string',
                'timezone' => 'required|timezone', 'location_id' => 'nullable|integer', 'location_name' => 'nullable|string|max:180',
                'role_name' => 'required|string|max:160', 'required_staff' => 'required|integer|min:1|max:999',
                'condition_id' => 'nullable|integer', 'quantity' => 'nullable|decimal:0,3|min:0.001|max:99999.999',
                'planned_break_minutes' => 'nullable|integer|min:0|max:1440',
                'train_reference' => 'nullable|string|max:100', 'vehicle_reference' => 'nullable|string|max:100',
                'cost_center' => 'nullable|string|max:100',
            ])->validate();
            [$start, $end] = OperationsDateTime::interval($row['starts_at'], $row['ends_at'], $row['timezone']);
            $row['starts_at'] = $start->setTimezone($row['timezone'])->format('Y-m-d\TH:i:sP');
            $row['ends_at'] = $end->setTimezone($row['timezone'])->format('Y-m-d\TH:i:sP');
            $validated[] = $row;
        }
        $data['positions'] = $validated;
        Validator::make(['attachments' => $attachments], ['attachments' => 'array|max:20', 'attachments.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'])->validate();
        $data['attachments'] = array_map(fn (UploadedFile $file) => ['hash' => hash_file('sha256', $file->getRealPath()), 'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180), 'size' => $file->getSize()], $attachments);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $storedPaths = [];
        try {
            return OperationsTransaction::run(function () use ($identity, $customerId, $uuid, $data, $hash, $attachments, &$storedPaths) {
                $scope = app(CustomerPortalScope::class);
                $membership = $scope->membership($identity, $customerId, 'requests.create', true);
                $existing = CustomerPortalSubmission::where('customer_id', $customerId)->where('uuid', $uuid)->lockForUpdate()->first();
                if ($existing) {
                    abort_unless($existing->identity_id === $identity->id && hash_equals($existing->payload_hash, $hash), 409, 'Anfragekennung gehört zu einem anderen Stand.');

                    return $existing->load('items');
                }
                foreach ($data['positions'] as &$row) {
                    if (! empty($row['location_id'])) {
                        $location = CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->lockForUpdate()->findOrFail($row['location_id']);
                        abort_if($membership->location_ids && ! in_array($location->id, $membership->location_ids, true), 403);
                        $row['location_name'] = $location->name;
                    } else {
                        abort_if($membership->location_ids, 403, 'Bitte einen freigegebenen Einsatzort auswählen.');
                        abort_unless(filled($row['location_name'] ?? ''), 422, 'Einsatzort fehlt.');
                    }
                    if ($row['condition_id'] ?? null) {
                        CustomerCondition::where('customer_id', $customerId)->findOrFail($row['condition_id']);
                    }
                }
                unset($row);
                $submission = CustomerPortalSubmission::create(['customer_id' => $customerId, 'identity_id' => $identity->id, 'membership_id' => $membership->id, 'uuid' => $uuid, 'payload_hash' => $hash, 'payload' => $data, 'status' => 'review']);
                foreach ($data['positions'] as $index => $row) {
                    $inquiry = app(InquiryWorkflowService::class)->save(null, [
                        'channel' => 'portal', 'source_reference' => 'portal:'.$customerId.':'.$uuid.':'.$index,
                        'customer_id' => $customerId, 'title' => $row['title'], 'original' => json_encode(['reference' => $data['reference'] ?? '', 'note' => $data['note'] ?? '', 'position' => $row], JSON_THROW_ON_ERROR),
                        'contact_name' => $membership->contact->name, 'contact_email' => $membership->contact->email,
                        'timezone' => $row['timezone'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
                        'location_name' => $row['location_name'], 'role_name' => $row['role_name'], 'required_staff' => $row['required_staff'],
                    ], $identity);
                    $inquiry->forceFill(['customer_portal_location_id' => $row['location_id'] ?? null])->save();
                    $submission->items()->create(['customer_id' => $customerId, 'location_id' => $row['location_id'] ?? null, 'inquiry_id' => $inquiry->id, 'position' => $index, 'payload' => $row]);
                    app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'verify', [], $identity);
                }
                // Uploads and every series position belong to the same atomic request, before any decision.
                foreach ($attachments as $file) {
                    $attachment = app(CustomerPortalIntakeAttachmentService::class)->upload($identity, $customerId, 'submission', $submission->id, (string) Str::uuid(), $file);
                    $storedPaths[] = $attachment->file_path;
                }
                app(CustomerPortalDeliveryService::class)->enqueue($customerId, $membership->id, 'requests', 'portal-intake:'.$submission->id.':1', [
                    'title' => 'Anfrage eingegangen', 'message' => 'Ihre Anfrage wurde erfasst und wird geprüft. Dies ist noch keine Leistungszusage.',
                    'url' => route('customer-portal.workspace', ['customer' => $customerId, 'section' => 'requests']),
                ]);

                return app(CustomerPortalDecisionService::class)->evaluate($submission->id)->load('items');
            });
        } catch (\Throwable $exception) {
            app(CustomerPortalIntakeAttachmentService::class)->discardRolledBack($customerId, $storedPaths);
            throw $exception;
        }
    }

    public function acceptOffer(CustomerPortalIdentity $identity, int $customerId, int $offerId, int $stateVersion, string $note): void
    {
        CustomerPortalIntakeSchema::requireReady();
        OperationsTransaction::run(function () use ($identity, $customerId, $offerId, $stateVersion, $note) {
            app(CustomerPortalScope::class)->membership($identity, $customerId, 'offers.accept', true);
            $offer = CommercialOfferRevision::findOrFail($offerId);
            $subject = match ($offer->subject_type) {
                'OperationInquiry' => app(CustomerPortalScope::class)->inquiries($identity, $customerId, null)->findOrFail($offer->subject_id),
                'Order' => app(CustomerPortalScope::class)->orders($identity, $customerId, null)->findOrFail($offer->subject_id),
                default => abort(404),
            };
            abort_unless((int) $subject->customer_id === $customerId, 404);
            app(CommercialOfferService::class)->accept($offerId, $stateVersion, $note, true, $identity);
            // Customer consent is not RailTime's resource promise or a staff response.
            if ($subject instanceof OperationInquiry) {
                $item = CustomerPortalSubmissionItem::where('customer_id', $customerId)->where('inquiry_id', $subject->id)->first();
                if ($item) {
                    app(CustomerPortalDecisionService::class)->evaluate($item->submission_id);
                }
            }
        });
    }

    public function frameworkTerms(CustomerPortalIdentity $identity, int $customerId, string $startsAt, string $timezone): array
    {
        app(CustomerPortalScope::class)->membership($identity, $customerId, 'offers.accept');
        $at = OperationsDateTime::local($startsAt, $timezone, 'starts_at');
        $localDate = $at->setTimezone($timezone)->format('Y-m-d');
        $conditions = CustomerCondition::where('customer_id', $customerId)->where('valid_from', '<=', $localDate)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $localDate))->orderBy('id')->get()->map(fn ($c) => $c->only(['id', 'code', 'label', 'unit', 'unit_price_cents', 'valid_from', 'valid_until', 'terms']));

        return ['hash' => hash('sha256', $conditions->toJson()), 'conditions' => $conditions->all(), 'terms' => $conditions->map(fn ($c) => $c['label'].' · '.number_format($c['unit_price_cents'] / 100, 2, ',', '.').' EUR/'.$c['unit'].' · '.$c['terms'])->implode("\n")];
    }
}
