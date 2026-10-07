<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerPortalIdentity;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerPortal\CustomerPortalPublicationService;
use App\Support\CustomerPortal\CustomerPortalDateTime;
use App\Support\CustomerPortal\PortalActor;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\OperationsAutomationActor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InquiryWorkflowService
{
    public function __construct(private OperationsAuditService $audit) {}

    public function save(?OperationInquiry $inquiry, array $input, User|CustomerPortalIdentity|OperationsAutomationActor $actor, ?int $revision = null): OperationInquiry
    {
        PortalActor::authorizeInquiry($actor, $inquiry, $input);
        $data = Validator::make($input, [
            'channel' => ['required', Rule::in(['email', 'phone', 'portal', 'manual'])],
            'source_reference' => ['nullable', 'string', 'max:190'],
            'title' => ['required', 'string', 'max:180'], 'original' => ['required', 'string', 'max:20000'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'contact_name' => ['nullable', 'string', 'max:180'], 'contact_email' => ['nullable', 'email', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:80'], 'timezone' => ['required', 'timezone'],
            'starts_at' => ['nullable', 'string'], 'ends_at' => ['nullable', 'string'],
            'location_name' => ['nullable', 'string', 'max:180'], 'role_name' => ['nullable', 'string', 'max:160'],
            'required_staff' => ['nullable', 'integer', 'min:1', 'max:999'],
        ])->validate();
        foreach (['source_reference', 'customer_id', 'required_staff'] as $optional) {
            if (blank($data[$optional] ?? null)) {
                $data[$optional] = null;
            }
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? ($actor instanceof CustomerPortalIdentity
                ? CustomerPortalDateTime::local($data[$key], $data['timezone'], $key)
                : OperationsDateTime::local($data[$key], $data['timezone'], $key)) : null;
        }
        if ($data['starts_at'] && $data['ends_at'] && $data['ends_at']->lessThanOrEqualTo($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }

        return OperationsTransaction::run(function () use ($inquiry, $data, $actor, $revision): OperationInquiry {
            PortalActor::authorizeInquiry($actor, $inquiry, $data);
            $record = $inquiry ? OperationInquiry::lockForUpdate()->findOrFail($inquiry->id) : new OperationInquiry;
            if ($record->exists) {
                $this->editable($record, $revision);
                // Original communication is immutable; corrected demand gets a new revision.
                unset($data['original'], $data['channel'], $data['source_reference']);
                $record->revision++;
            } else {
                if (filled($data['source_reference'] ?? null) && OperationInquiry::where('channel', $data['channel'])->where('source_reference', $data['source_reference'])->exists()) {
                    throw ValidationException::withMessages(['source_reference' => 'Dieser Eingang wurde bereits erfasst.']);
                }
                $record->created_by = PortalActor::internalId($actor);
            }
            $record->fill($data)->forceFill([
                'status' => 'new', 'verified_revision' => null, 'offer' => null,
                'accepted_revision' => null, 'acceptance_note' => null, 'updated_by' => PortalActor::internalId($actor),
            ] + PortalActor::references($actor, (int) $data['customer_id']))->save();
            $this->audit->record($record, $actor, 'inquiry.saved', ['demand' => $record->only(['customer_id', 'starts_at', 'ends_at', 'timezone', 'required_staff', 'role_name', 'location_name'])]);

            return $record;
        });
    }

    public function transition(OperationInquiry $inquiry, int $revision, string $action, array $input, User|CustomerPortalIdentity|OperationsAutomationActor $actor): OperationInquiry
    {
        PortalActor::authorizeInquiry($actor, $inquiry, $input, $action);

        return OperationsTransaction::run(function () use ($inquiry, $revision, $action, $input, $actor): OperationInquiry {
            $record = OperationInquiry::lockForUpdate()->findOrFail($inquiry->id);
            PortalActor::authorizeInquiry($actor, $record, $input, $action);
            if ($action === 'convert' && $record->order_id && $record->revision === $revision) {
                return $record;
            }
            $this->editable($record, $revision);
            switch ($action) {
                case 'verify':
                    $this->require(in_array($record->status, ['new', 'verified'], true), 'Bitte Änderungen zunächst speichern.');
                    $this->complete($record);
                    $record->verified_revision = $record->revision;
                    $record->status = 'verified';
                    break;
                case 'offer':
                    $this->require($record->verified_revision === $revision && in_array($record->status, ['verified', 'offered'], true), 'Der Bedarf muss zuerst geprüft werden.');
                    $offer = Validator::make($input, ['amount' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'], 'terms' => ['required', 'string', 'max:5000'], 'valid_until' => ['nullable', 'date_format:Y-m-d']])->validate();
                    $calculated = isset($input['positions']) ? app(CommercialOfferService::class)->positions($input['positions'], $record->customer_id, $record->starts_at->format('Y-m-d')) : null;
                    $commercial = null;
                    if (filled($input['commercial_offer_id'] ?? null)) {
                        $commercial = CommercialOfferRevision::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)->lockForUpdate()->findOrFail($input['commercial_offer_id']);
                        $latestId = CommercialOfferRevision::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)->latest('revision')->value('id');
                        $this->require($commercial->status === 'draft' && $latestId === $commercial->id && ($commercial->snapshot['source_revision'] ?? null) === $revision, 'Dieser Angebotsentwurf ist nicht mehr aktuell.');
                    }
                    // Replacing an offer also creates a new demand/offer revision, invalidating old acceptance.
                    if ($record->offer) {
                        $record->revision++;
                        $record->verified_revision = $record->revision;
                    }
                    $record->offer = ['revision' => $record->revision, 'amount_cents' => $commercial?->total_cents ?? $calculated['amount_cents'] ?? CommercialOfferService::scaled($offer['amount'], 2), 'currency' => 'EUR', 'terms' => $commercial?->snapshot['terms'] ?? $offer['terms'],
                        'valid_until' => $offer['valid_until'] ?? null, 'positions' => $commercial?->snapshot['positions'] ?? $calculated['positions'] ?? []];
                    if (CommercialOfferService::ready()) {
                        $commercial ??= CommercialOfferRevision::create([
                            'subject_type' => 'OperationInquiry', 'subject_id' => $record->id,
                            'revision' => (int) CommercialOfferRevision::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)->max('revision') + 1,
                            'kind' => 'offer', 'status' => 'offered', 'issued_at' => now()->utc(), 'snapshot' => $record->offer,
                            'total_cents' => $record->offer['amount_cents'], 'valid_until' => ($offer['valid_until'] ?? null) ?: null, 'created_by' => $actor->id,
                        ]);
                        $record->offer = $record->offer + ['commercial_offer_id' => $commercial->id];
                    }
                    $record->accepted_revision = null;
                    $record->acceptance_note = null;
                    $record->status = 'offered';
                    break;
                case 'accept':
                    if ($actor instanceof CustomerPortalIdentity) {
                        $publication = app(CustomerPortalPublicationService::class)->visible($actor, (int) $record->customer_id, null)->where('subject_type', 'offer')->where('subject_id', $record->offer['commercial_offer_id'] ?? 0)->first();
                        $portalOffer = CommercialOfferRevision::find($record->offer['commercial_offer_id'] ?? 0);
                        abort_unless($publication && $portalOffer && $publication->source_revision === $portalOffer->state_version, 409, 'Angebot wurde nicht im aktuellen Stand freigegeben.');
                    }
                    $this->require($record->status === 'offered' && ($record->offer['revision'] ?? null) === $revision, 'Es liegt kein aktuelles Angebot vor.');
                    $this->require(empty($record->offer['valid_until']) || $record->offer['valid_until'] >= now($record->timezone)->format('Y-m-d'), 'Die Angebotsfrist ist abgelaufen.');
                    if (CommercialOfferService::ready() && ($commercialId = ($record->offer['commercial_offer_id'] ?? null))) {
                        $commercial = CommercialOfferRevision::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)->lockForUpdate()->findOrFail($commercialId);
                        $latest = CommercialOfferRevision::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)->latest('revision')->value('id');
                        $this->require($commercial->status === 'offered' && $latest === $commercial->id, 'Es liegt ein neuerer Angebotsstand vor.');
                    }
                    $acceptance = Validator::make($input, ['note' => ['required', 'string', 'min:5', 'max:5000'], 'authorized' => ['accepted']])->validate();
                    $record->accepted_revision = $revision;
                    $record->acceptance_note = $acceptance['note'];
                    $record->status = 'accepted';
                    if (isset($commercial)) {
                        $commercial->forceFill(['status' => 'accepted', 'state_version' => $commercial->state_version + 1, 'accepted_at' => now()->utc(), 'accepted_by' => PortalActor::internalId($actor), 'acceptance_note' => $acceptance['note']] + PortalActor::references($actor, (int) $record->customer_id))->save();
                    }
                    break;
                case 'convert':
                    $this->complete($record);
                    $this->require($record->status === 'accepted' && $record->accepted_revision === $revision && ($record->offer['revision'] ?? null) === $revision, 'Eine aktuelle Kundenzusage ist erforderlich.');
                    $order = app(OrderSchedulingService::class)->save(new Order, [
                        'customer_id' => $record->customer_id, 'title' => $record->title,
                        'service_type' => $record->role_name, 'status' => OrderStatus::Confirmed,
                        'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at,
                        'timezone' => $record->timezone, 'location_name' => $record->location_name,
                        'required_staff' => $record->required_staff, 'priority' => 'normal',
                        'notes' => $record->number,
                    ], $actor);
                    $record->order_id = $order->id;
                    if ($record->customer_portal_identity_id) {
                        $order->forceFill($record->only(['customer_portal_location_id', 'customer_portal_identity_id', 'customer_portal_membership_id']))->save();
                    }
                    $record->status = 'converted';
                    if (CommercialOfferService::ready()) {
                        $originOffer = CommercialOfferRevision::find($record->offer['commercial_offer_id'] ?? 0);
                        CommercialOfferRevision::create(['subject_type' => 'Order', 'subject_id' => $order->id, 'revision' => 1,
                            'kind' => 'offer', 'status' => 'accepted', 'snapshot' => $record->offer + ['origin_inquiry_id' => $record->id],
                            'total_cents' => $record->offer['amount_cents'], 'created_by' => $actor->id, 'accepted_at' => now()->utc(),
                            'accepted_by' => $originOffer?->customer_portal_identity_id ? null : ($originOffer?->accepted_by ?? $actor->id), 'acceptance_note' => $record->acceptance_note]
                            + ($originOffer?->customer_portal_identity_id ? $originOffer->only(['customer_portal_identity_id', 'customer_portal_membership_id']) : []));
                    }
                    break;
                case 'reject':
                    $rejection = Validator::make($input, ['note' => ['required', 'string', 'min:5', 'max:5000']])->validate();
                    $record->status = 'rejected';
                    $record->acceptance_note = $rejection['note'];
                    $record->accepted_revision = null;
                    break;
                case 'duplicate':
                    $id = (int) ($input['original_id'] ?? 0);
                    $original = OperationInquiry::find($id);
                    $this->require($original && $id < $record->id && ! $original->duplicate_of_id, 'Bitte einen älteren Originalvorgang auswählen.');
                    $this->require(! $record->offer && ! $record->accepted_revision, 'Ein Vorgang mit Angebot oder Zusage kann nicht als Dublette verknüpft werden.');
                    $record->duplicate_of_id = $id;
                    $record->status = 'duplicate';
                    break;
                default:
                    throw ValidationException::withMessages(['workflow' => 'Ungültiger Arbeitsschritt.']);
            }
            $record->updated_by = PortalActor::internalId($actor);
            $record->save();
            $this->audit->record($record, $actor, 'inquiry.'.$action, ['status' => $record->status, 'offer' => $record->offer, 'acceptance_note' => $record->acceptance_note, 'order_id' => $record->order_id, 'duplicate_of_id' => $record->duplicate_of_id]);

            return $record;
        }, 3);
    }

    private function editable(OperationInquiry $record, ?int $revision): void
    {
        $this->require($record->revision === $revision, 'Der Vorgang wurde geändert. Bitte neu laden.');
        $this->require(! $record->order_id && ! $record->duplicate_of_id && $record->status !== 'rejected', 'Dieser Vorgang ist bereits abgeschlossen.');
    }

    private function complete(OperationInquiry $record): void
    {
        $customer = Customer::find($record->customer_id);
        $this->require($customer && $customer->is_active, 'Bitte einen aktiven Kunden zuordnen.');
        $this->require($record->starts_at && $record->ends_at && $record->ends_at->greaterThan($record->starts_at)
            && filled($record->role_name) && filled($record->location_name) && $record->required_staff > 0, 'Zeitraum, Einsatzort, Tätigkeit und Personalbedarf müssen vollständig sein.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
