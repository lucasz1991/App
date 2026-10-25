<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Jobs\QueueAiIntakeOrderConfirmation;
use App\Models\AiIntake;
use App\Models\AiIntakeProposal;
use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerPortalIdentity;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerPortal\CustomerPortalPublicationService;
use App\Support\CustomerPortal\CustomerPortalDateTime;
use App\Support\CustomerPortal\PortalActor;
use App\Support\Operations\AiCustomerCommunicationSchema;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsAutomationActor;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
            $auditData = ['status' => $record->status, 'offer' => $record->offer, 'acceptance_note' => $record->acceptance_note, 'order_id' => $record->order_id, 'duplicate_of_id' => $record->duplicate_of_id];
            $communication = $action === 'convert' && $actor instanceof User && AiCustomerCommunicationSchema::ready() ? AiDispositionSettings::all(true) : null;
            $customerMailSuppressed = $communication && AiIntakeProposal::where('inquiry_id', $record->id)->get(['payload'])
                ->contains(fn ($proposal) => ($proposal->payload['review_confirmation']['customer_communication'] ?? null) === 'off');
            if ($communication && ! $customerMailSuppressed && $communication['enabled'] && ($communication['customer_confirmation_mode'] ?? 'off') !== 'off' && AiIntakeProposal::where('inquiry_id', $record->id)->exists()) {
                // Persist the opt-in intent inside the human conversion transaction, before a queue can fail.
                $auditData['customer_confirmation'] = ['enabled' => true, 'settings_revision' => (int) $communication['revision'], 'supervisor_id' => (int) $communication['supervisor_id'], 'mode' => $communication['customer_confirmation_mode']];
            }
            $this->audit->record($record, $actor, 'inquiry.'.$action, $auditData);
            if (isset($auditData['customer_confirmation'])) {
                DB::afterCommit(function () use ($record, $actor): void {
                    try {
                        QueueAiIntakeOrderConfirmation::dispatch($record->id, $actor->id)->afterCommit();
                    } catch (\Throwable) {
                        // The persisted intent is recovered by the bounded disposition poll; never undo an approved order.
                        Log::warning('AI customer confirmation queue unavailable', ['inquiry_id' => $record->id]);
                    }
                });
            }

            return $record;
        }, 3);
    }

    /** Accept a personally checked order without inventing an offer or customer acceptance. */
    public function takeDirectOrder(OperationInquiry $inquiry, int $revision, User $actor): OperationInquiry
    {
        OperationsAccess::authorize($actor, 'operations.manage');
        PortalActor::authorizeInquiry($actor, $inquiry, [], 'direct_order');

        return OperationsTransaction::run(function () use ($inquiry, $revision, $actor): OperationInquiry {
            $currentActor = $actor->fresh();
            abort_unless($currentActor, 403);
            OperationsAccess::authorize($currentActor, 'operations.manage');
            PortalActor::authorizeInquiry($currentActor, $inquiry, [], 'direct_order');

            // Follow the same source -> position -> inquiry lock order as intake review.
            $sources = $this->lockDirectOrderSources($inquiry->id);
            $record = OperationInquiry::lockForUpdate()->findOrFail($inquiry->id);
            $this->require($record->revision === $revision, 'Der Vorgang wurde geändert. Bitte neu laden.');
            $proof = $record->order_id ? OperationAudit::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)
                ->whereIn('action', ['inquiry.convert', 'inquiry.direct_order'])->latest('id')->first() : null;
            $sourceContext = $this->directOrderSourceContext($record, $sources, $proof);
            $fingerprint = $this->directOrderFingerprint($record);

            if ($record->order_id) {
                $order = Order::lockForUpdate()->findOrFail($record->order_id);
                $this->require($record->status === 'converted' && $proof && $proof->actor_id
                    && ($proof->actor_kind ?? 'human') === 'human' && $proof->revision === $revision
                    && (int) ($proof->data['order_id'] ?? 0) === (int) $order->id,
                    'Die bestehende Auftragsübernahme muss zuerst geprüft werden.');
                if ($proof->action === 'inquiry.direct_order') {
                    $sameSource = (int) ($proof->data['source_context']['intake_id'] ?? 0) === (int) ($sourceContext['intake_id'] ?? 0)
                        && (int) ($proof->data['source_context']['source_revision'] ?? 0) === (int) ($sourceContext['source_revision'] ?? 0)
                        && (int) ($proof->data['source_context']['proposal_id'] ?? 0) === (int) ($sourceContext['proposal_id'] ?? 0);
                    $nativeBridge = $sourceContext !== [] && $this->directOrderBridgeMatches($record,
                        $sources['intakes']->get($sourceContext['intake_id']), $sources['proposals']->firstWhere('id', $sourceContext['proposal_id']), $proof);
                    $this->require(hash_equals((string) ($proof->data['inquiry_fingerprint'] ?? ''), $fingerprint)
                        && ($sameSource || $nativeBridge),
                        'Die Grundlage der direkten Auftragsannahme wurde geändert. Bitte neu prüfen.');
                }

                return $record;
            }

            $this->editable($record, $revision);
            Validator::make($record->getAttributes(), [
                'title' => ['required', 'string', 'max:180'],
                'timezone' => ['required', 'timezone'],
                'role_name' => ['required', 'string', 'max:160'],
                'location_name' => ['required', 'string', 'max:180'],
                'required_staff' => ['required', 'integer', 'min:1', 'max:999'],
            ])->validate();
            $this->complete($record);

            $order = app(OrderSchedulingService::class)->save(new Order, [
                'customer_id' => $record->customer_id, 'title' => $record->title,
                'service_type' => $record->role_name, 'status' => OrderStatus::Confirmed,
                'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at,
                'timezone' => $record->timezone, 'location_name' => $record->location_name,
                'required_staff' => $record->required_staff, 'priority' => 'normal',
                'notes' => $record->number,
            ], $currentActor);
            if ($record->customer_portal_identity_id) {
                $order->forceFill($record->only(['customer_portal_location_id', 'customer_portal_identity_id', 'customer_portal_membership_id']))->save();
            }
            $record->forceFill(['order_id' => $order->id, 'status' => 'converted', 'updated_by' => $currentActor->id])->save();
            $this->audit->record($record, $currentActor, 'inquiry.direct_order', [
                'status' => $record->status, 'order_id' => $order->id,
                'source_reference' => $record->source_reference, 'source_context' => $sourceContext,
                'inquiry_fingerprint' => $fingerprint,
                'demand' => $record->only(['customer_id', 'title', 'starts_at', 'ends_at', 'timezone', 'role_name', 'location_name', 'required_staff']),
            ]);

            return $record;
        }, 3);
    }

    /** @return array{intakes: Collection, proposals: Collection} */
    private function lockDirectOrderSources(int $inquiryId): array
    {
        if (! Schema::hasTable('ai_intake_proposals')) {
            return ['intakes' => collect(), 'proposals' => collect()];
        }
        $intakeIds = AiIntakeProposal::where('inquiry_id', $inquiryId)->distinct()->orderBy('intake_id')->pluck('intake_id');
        $intakes = AiIntake::whereIn('id', $intakeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $proposals = AiIntakeProposal::where('inquiry_id', $inquiryId)->orderBy('id')->lockForUpdate()->get();

        return compact('intakes', 'proposals');
    }

    /** @return array<string, int> */
    private function directOrderSourceContext(OperationInquiry $record, array $sources, ?OperationAudit $proof = null): array
    {
        $proposals = $sources['proposals'];
        $this->require(! Schema::hasTable('ai_intake_proposals')
            || AiIntakeProposal::where('inquiry_id', $record->id)->orderBy('id')->pluck('id')->all() === $proposals->pluck('id')->all(),
            'Die Quellenzuordnung wurde geändert. Bitte neu laden.');
        if ($proposals->isEmpty()) {
            $this->require(! str_starts_with((string) $record->source_reference, 'ai-intake:'), 'Die aktuelle Leistungszuordnung fehlt. Bitte den Eingang erneut prüfen.');

            return [];
        }
        $this->require($sources['intakes']->count() === 1 && $proposals->pluck('intake_id')->unique()->count() === 1,
            'Die Quellenzuordnung ist nicht eindeutig. Bitte den Eingang erneut prüfen.');
        $intake = $sources['intakes']->first();
        $current = $proposals->where('source_revision', $intake->source_revision);
        $this->require($current->count() === 1 && ! $intake->paused_at && ! in_array($intake->status, ['paused', 'not_inquiry'], true),
            'Die Mailquelle wurde geändert. Bitte den aktuellen Eingang prüfen.');
        $proposal = $current->first();
        $confirmation = $proposal->payload['review_confirmation'] ?? [];
        $this->require($proposal->status !== 'stale' && $proposal->inquiry_revision === $record->revision
            && $proposal->inquiry_fingerprint && hash_equals($proposal->inquiry_fingerprint, $this->directOrderFingerprint($record))
            && (int) $intake->customer_id === (int) $record->customer_id
            && ((! empty($confirmation['actor_id']) && (int) ($confirmation['source_revision'] ?? 0) === $intake->source_revision)
                || $this->directOrderBridgeMatches($record, $intake, $proposal, $proof)),
            'Quelle und Leistungsangaben müssen vor der direkten Auftragsannahme persönlich im aktuellen Stand geprüft werden.');

        return ['intake_id' => (int) $intake->id, 'source_revision' => $intake->source_revision,
            'proposal_id' => (int) $proposal->id, 'proposal_revision' => $proposal->revision, 'position_index' => $proposal->position_index];
    }

    private function directOrderFingerprint(OperationInquiry $inquiry): string
    {
        return hash('sha256', json_encode([$inquiry->customer_id, $inquiry->title, $inquiry->starts_at?->toIso8601String(), $inquiry->ends_at?->toIso8601String(), $inquiry->timezone, $inquiry->role_name, $inquiry->location_name, $inquiry->required_staff], JSON_THROW_ON_ERROR));
    }

    private function directOrderBridgeMatches(OperationInquiry $record, AiIntake $intake, AiIntakeProposal $proposal, ?OperationAudit $proof): bool
    {
        if (! $record->order_id || $proof?->action !== 'inquiry.direct_order' || ($proof->data['source_context'] ?? null) !== []
            || ($proposal->payload['provenance']['kind'] ?? null) !== 'native_inquiry') {
            return false;
        }
        $bridge = OperationAudit::where('subject_type', 'AiIntake')->where('subject_id', $intake->id)
            ->where('action', 'intake.native_assist_opened')->latest('id')->first();
        $data = $bridge?->data ?? [];

        return $bridge && $bridge->actor_id && ($bridge->actor_kind ?? 'human') === 'human'
            && (int) ($data['conversion_audit_id'] ?? 0) === (int) $proof->id
            && (int) ($data['inquiry_id'] ?? 0) === (int) $record->id && (int) ($data['inquiry_revision'] ?? 0) === $record->revision
            && ($data['inquiry_fingerprint'] ?? null) === $this->directOrderFingerprint($record)
            && (int) ($data['source_revision'] ?? 0) === $intake->source_revision
            && (int) ($data['proposal_id'] ?? 0) === (int) $proposal->id
            && (int) ($data['order_id'] ?? 0) === (int) $record->order_id;
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
