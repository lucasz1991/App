<?php

namespace App\Services\CustomerPortal;

use App\Models\CommercialOfferRevision;
use App\Models\Customer;
use App\Models\CustomerCondition;
use App\Models\CustomerLocation;
use App\Models\CustomerPortalAudit;
use App\Models\CustomerPortalAutomationProfile;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalPublication;
use App\Models\CustomerPortalSubmission;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Services\Operations\CommercialOfferService;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\CustomerPortal\CustomerPortalDateTime;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CustomerPortalDecisionService
{
    public function saveProfile(User $actor, int $customerId, int $expectedRevision, array $input): CustomerPortalAutomationProfile
    {
        CustomerPortalIntakeSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
        $data = Validator::make($input, ['name' => 'required|string|max:180', 'mode' => ['required', Rule::in(['manual', 'offer', 'accept'])], 'auto_reject' => 'required|boolean', 'allowed_roles' => 'required|array|max:30', 'allowed_roles.*' => 'string|max:160|distinct', 'location_ids' => 'required|array|max:100', 'location_ids.*' => 'integer|distinct', 'condition_ids' => 'required|array|max:100', 'condition_ids.*' => 'integer|distinct', 'minimum_lead_minutes' => 'nullable|integer|min:0|max:525600', 'maximum_staff' => 'nullable|integer|min:1|max:999', 'maximum_total_cents' => 'nullable|integer|min:0|max:9999999900', 'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from'])->validate();

        return OperationsTransaction::run(function () use ($actor, $customerId, $expectedRevision, $data) {
            Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
            abort_unless(CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->whereIn('id', $data['location_ids'])->count() === count($data['location_ids']), 422);
            abort_unless(CustomerCondition::where('customer_id', $customerId)->whereIn('id', $data['condition_ids'])->count() === count($data['condition_ids']), 422);
            $profile = CustomerPortalAutomationProfile::where('customer_id', $customerId)->lockForUpdate()->first();
            abort_unless(($profile?->revision ?? 0) === $expectedRevision, 409, 'Regelprofil wurde geändert.');
            $profile ??= new CustomerPortalAutomationProfile(['customer_id' => $customerId]);
            $profile->fill($data)->forceFill(['created_by' => $actor->id, 'approved_by' => null, 'approved_at' => null, 'revision' => $expectedRevision + 1])->save();
            $this->profileSnapshot($profile, $actor, 'prepared');

            return $profile;
        });
    }

    public function approveProfile(User $actor, int $customerId, int $id, int $revision): CustomerPortalAutomationProfile
    {
        CustomerPortalIntakeSchema::requireReady();

        return OperationsTransaction::run(function () use ($actor, $customerId, $id, $revision) {
            Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
            OperationsAccess::authorize($actor, 'operations.inquiries.manage');
            $profile = CustomerPortalAutomationProfile::where('customer_id', $customerId)->lockForUpdate()->findOrFail($id);
            abort_unless($profile->revision === $revision && $profile->created_by !== $actor->id && ! $profile->approved_at, 409, 'Zweite aktuelle Freigabe erforderlich.');
            abort_unless($profile->mode === 'manual' || ($profile->allowed_roles && $profile->location_ids && $profile->condition_ids && $profile->maximum_staff && $profile->minimum_lead_minutes !== null && $profile->maximum_total_cents !== null), 422, 'Für Automatik müssen alle Grenzen ausdrücklich gepflegt sein.');
            $profile->forceFill(['approved_by' => $actor->id, 'approved_at' => now()->utc(), 'revision' => $revision + 1])->save();
            $this->profileSnapshot($profile, $actor, 'approved');

            return $profile;
        });
    }

    public function simulate(User $actor, int $customerId, int $submissionId): array
    {
        CustomerPortalIntakeSchema::requireReady();
        app(CustomerPortalScope::class)->authorizeManager($actor, $customerId, 'customers.portal.automation');
        $submission = CustomerPortalSubmission::where('customer_id', $customerId)->findOrFail($submissionId);

        try {
            return $this->assessment($submission);
        } catch (ValidationException $exception) {
            return ['outcome' => 'review', 'message' => 'Preis- oder Angebotsgrundlagen müssen geprüft werden.', 'prices' => []];
        }
    }

    public function evaluate(int $submissionId): CustomerPortalSubmission
    {
        CustomerPortalIntakeSchema::requireReady();

        return OperationsTransaction::run(function () use ($submissionId) {
            $base = CustomerPortalSubmission::findOrFail($submissionId);
            $identity = CustomerPortalIdentity::findOrFail($base->identity_id);
            try {
                $membership = app(CustomerPortalScope::class)->membership($identity, $base->customer_id, 'requests.create', true);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }

                return $base;
            }
            $submission = CustomerPortalSubmission::lockForUpdate()->findOrFail($submissionId);
            if (in_array($submission->status, ['accepted', 'rejected'], true)) {
                return $submission;
            }
            $profile = CustomerPortalAutomationProfile::where('customer_id', $submission->customer_id)->lockForUpdate()->first();
            if (! $profile?->approved_at || $profile->mode === 'manual' || $membership->setting->automation_mode !== $profile->mode) {
                return $submission;
            }
            $actor = User::find($profile->approved_by);
            if (! $actor?->status) {
                return $submission;
            }
            try {
                app(CustomerPortalScope::class)->authorizeManager($actor, $submission->customer_id, 'customers.portal.automation');
                app(CustomerPortalScope::class)->authorizeManager($actor, $submission->customer_id, 'customers.portal.publish');
                OperationsAccess::authorize($actor, 'operations.inquiries.manage');
            } catch (AuthorizationException $exception) {
                return $submission;
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }

                return $submission;
            }
            try {
                $assessment = $this->assessment($submission);
            } catch (ValidationException $exception) {
                return $submission;
            }
            if ($assessment['outcome'] === 'excluded') {
                return $profile->auto_reject && $membership->setting->auto_reject
                    ? $this->apply($actor, $submission, 'reject', $assessment['message'], true)
                    : $submission;
            }
            if ($assessment['outcome'] !== 'ready') {
                if (($assessment['reason'] ?? '') === 'publication_withdrawn') {
                    return $this->finish($submission, 'review', 'Anfrage wird erneut geprüft.', $actor, true);
                }

                return $submission;
            }
            try {
                OperationsTransaction::run(fn () => $this->offers($actor, $submission, $assessment['prices']));
            } catch (ValidationException|AuthorizationException $exception) {
                return $this->finish($submission->fresh(), 'review', 'Angebotsgrundlagen müssen manuell geprüft werden.', $actor, true);
            } catch (HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 409, 422], true)) {
                    throw $exception;
                }

                return $this->finish($submission->fresh(), 'review', 'Angebotsgrundlagen müssen manuell geprüft werden.', $actor, true);
            }
            if ($profile->mode === 'offer') {
                return $this->finish($submission, 'offered', 'Ein aktuelles Angebot liegt zur Prüfung vor.', $actor, true);
            }
            try {
                return OperationsTransaction::run(fn () => $this->apply($actor, $submission->fresh(), 'accept', 'Leistung gemäß freigegebenen Bedingungen bestätigt.', true));
            } catch (ValidationException $exception) {
                return $this->finish($submission->fresh(), 'review', 'Kapazität oder Bestellgrundlage muss manuell geprüft werden.', $actor, true);
            } catch (HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 409, 422], true)) {
                    throw $exception;
                }

                return $this->finish($submission->fresh(), 'review', 'Kapazität oder Bestellgrundlage muss manuell geprüft werden.', $actor, true);
            }
        });
    }

    public function decide(User $actor, int $customerId, int $submissionId, int $revision, string $action, string $note): CustomerPortalSubmission
    {
        CustomerPortalIntakeSchema::requireReady();
        Validator::make(compact('action', 'note'), ['action' => 'required|in:offer,accept,reject,review', 'note' => 'required|string|min:5|max:2000'])->validate();

        return OperationsTransaction::run(function () use ($actor, $customerId, $submissionId, $revision, $action, $note) {
            Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
            app(CustomerPortalScope::class)->authorizeManager($actor, $customerId);
            OperationsAccess::authorize($actor, 'operations.inquiries.manage');
            $submission = CustomerPortalSubmission::where('customer_id', $customerId)->lockForUpdate()->findOrFail($submissionId);
            abort_unless($submission->revision === $revision && ! in_array($submission->status, ['accepted', 'rejected'], true), 409, 'Anfragestand wurde geändert oder abgeschlossen.');

            return $this->apply($actor, $submission, $action, $note, false);
        });
    }

    private function apply(User $actor, CustomerPortalSubmission $s, string $action, string $note, bool $automatic): CustomerPortalSubmission
    {
        $identity = CustomerPortalIdentity::findOrFail($s->identity_id);
        if ($automatic || in_array($action, ['offer', 'accept'], true)) {
            app(CustomerPortalScope::class)->membership($identity, $s->customer_id, 'requests.create', true);
        }
        if (in_array($action, ['offer', 'accept'], true)) {
            app(CustomerPortalScope::class)->authorizeManager($actor, $s->customer_id, 'customers.portal.publish');
            abort_unless(app(CustomerPortalIntakeAttachmentService::class)->decisionIssues($s->customer_id, 'submission', $s->id, $s->payload['attachments'] ?? []) === [], 409, 'Anlagen müssen zuerst geprüft werden.');
        }
        if ($action === 'offer') {
            $assessment = $this->assessment($s, false);
            abort_unless($assessment['outcome'] === 'ready', 422, $assessment['message']);
            $this->offers($actor, $s, $assessment['prices']);
        } elseif ($action === 'accept') {
            abort_unless(OperationsAccess::ready(), 503);
            foreach ($s->load('items')->items as $item) {
                $inquiry = OperationInquiry::lockForUpdate()->findOrFail($item->inquiry_id);
                $source = $item->payload;
                abort_unless($inquiry->revision === 1 && $inquiry->customer_id === $s->customer_id && $inquiry->verified_revision === $inquiry->revision && ! $inquiry->order_id && ! $inquiry->duplicate_of_id
                    && $inquiry->customer_portal_location_id === $item->location_id && $inquiry->location_name === $source['location_name']
                    && $inquiry->title === $source['title'] && $inquiry->timezone === $source['timezone'] && $inquiry->role_name === $source['role_name'] && $inquiry->required_staff === (int) $source['required_staff']
                    && $inquiry->starts_at->eq(CustomerPortalDateTime::local($source['starts_at'], $source['timezone']))
                    && $inquiry->ends_at->eq(CustomerPortalDateTime::local($source['ends_at'], $source['timezone'], 'ends_at')), 409, 'Anfragegrundlage wurde geändert und muss erneut geprüft werden.');
                if ($automatic && $inquiry->status === 'accepted') {
                    $acceptedOffer = CommercialOfferRevision::findOrFail($inquiry->offer['commercial_offer_id'] ?? 0);
                    $acceptor = CustomerPortalIdentity::find($acceptedOffer->customer_portal_identity_id);
                    abort_unless($acceptor && $acceptedOffer->status === 'accepted', 409, 'Aktuelle Kundenzustimmung fehlt.');
                    app(CustomerPortalScope::class)->membership($acceptor, $s->customer_id, 'offers.accept', true);
                }
                if ($inquiry->status !== 'accepted') {
                    $membership = app(CustomerPortalScope::class)->membership($identity, $s->customer_id, 'offers.accept', true);
                    $row = $item->payload;
                    $terms = app(CustomerPortalSubmissionService::class)->frameworkTerms($identity, $s->customer_id, $row['starts_at'], $row['timezone']);
                    abort_unless($s->payload['intent'] === 'framework' && $membership->setting->booking_authority && ($s->payload['accept_conditions'] ?? false) && hash_equals($terms['hash'], $s->payload['accepted_terms_hash'] ?? ''), 409, 'Aktuelle Kundenzustimmung fehlt.');
                    abort_unless($inquiry->status === 'offered', 409, 'Aktuelles Angebot fehlt.');
                    app(CommercialOfferService::class)->accept($inquiry->offer['commercial_offer_id'], CommercialOfferRevision::findOrFail($inquiry->offer['commercial_offer_id'])->state_version, 'Ausdrückliche Rahmenbuchung über Kundenportal.', true, $identity);
                }
            }
            $reservations = app(CustomerCapacityService::class)->reserve($s->fresh()->load('items'));
            foreach ($s->items as $item) {
                $inquiry = OperationInquiry::findOrFail($item->inquiry_id);
                $converted = app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'convert', [], $actor);
                foreach ($reservations as $reservation) {
                    if ($reservation->inquiry_id === $inquiry->id) {
                        $reservation->forceFill(['order_id' => $converted->order_id, 'status' => 'committed'])->save();
                    }
                }
                app(CustomerPortalPublicationService::class)->publish($actor, $s->customer_id, 'order', $converted->order_id, ['location_id' => $item->location_id]);
            }
        } elseif ($action === 'reject') {
            foreach ($s->load('items')->items as $item) {
                $inquiry = OperationInquiry::findOrFail($item->inquiry_id);
                app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'reject', ['note' => $note], $actor);
            }
        }

        return $this->finish($s, match ($action) {
            'offer' => 'offered', 'accept' => 'accepted', 'reject' => 'rejected', default => 'review'
        }, $note, $actor, $automatic);
    }

    private function assessment(CustomerPortalSubmission $s, bool $requireProfile = true): array
    {
        $profile = CustomerPortalAutomationProfile::where('customer_id', $s->customer_id)->first();
        $result = ['outcome' => 'review', 'message' => 'Grundlagen müssen geprüft werden.', 'prices' => []];
        if (app(CustomerPortalIntakeAttachmentService::class)->decisionIssues($s->customer_id, 'submission', $s->id, $s->payload['attachments'] ?? []) !== []) {
            return $result + ['reason' => 'attachments'];
        }
        // A safe file is not an interpreted business instruction. Attachments require an explicit human decision.
        if ($requireProfile && ! empty($s->payload['attachments'])) {
            return $result + ['reason' => 'attachments_business_review'];
        }
        if ($requireProfile && (! $profile?->approved_at || $profile->valid_from->isFuture() || ($profile->valid_until && $profile->valid_until->endOfDay()->isPast()))) {
            return $result;
        }
        foreach ($s->load('items')->items as $item) {
            $i = OperationInquiry::findOrFail($item->inquiry_id);
            $p = $item->payload;
            if ($i->revision !== 1 || $i->verified_revision !== $i->revision || $i->order_id || $i->duplicate_of_id || ! in_array($i->status, ['verified', 'offered', 'accepted'], true)
                || $i->customer_id !== $s->customer_id || $i->customer_portal_location_id !== $item->location_id
                || $i->title !== $p['title'] || $i->timezone !== $p['timezone']
                || $i->role_name !== $p['role_name'] || $i->location_name !== $p['location_name'] || $i->required_staff !== (int) $p['required_staff']
                || $i->starts_at->ne(CustomerPortalDateTime::local($p['starts_at'], $p['timezone'], 'starts_at'))
                || $i->ends_at->ne(CustomerPortalDateTime::local($p['ends_at'], $p['timezone'], 'ends_at'))) {
                return $result + ['reason' => 'stale'];
            }
            if (! $item->location_id) {
                return $result;
            }
            if (! CustomerLocation::whereKey($item->location_id)->where('customer_id', $s->customer_id)->where('is_active', true)->exists()) {
                return $result;
            }
            if ($requireProfile && ! in_array($i->role_name, $profile->allowed_roles, true)) {
                // Free-text activity is not a classified service. Unknown descriptions need human mapping.
                return $result + ['reason' => 'role_unmapped'];
            }
            if ($requireProfile && ! in_array($item->location_id, $profile->location_ids, true)) {
                return ['outcome' => 'excluded', 'message' => 'Die angefragte Leistung oder der Einsatzort ist für diesen Kunden nicht angeboten.', 'prices' => []];
            }
            if ($requireProfile && ($i->required_staff > $profile->maximum_staff || now()->addMinutes($profile->minimum_lead_minutes)->gt($i->starts_at))) {
                return $result;
            }
            $date = $i->starts_at->setTimezone($i->timezone)->format('Y-m-d');
            $endDate = $i->ends_at->setTimezone($i->timezone)->format('Y-m-d');
            if ($requireProfile && ($profile->valid_from->toDateString() > $date || ($profile->valid_until && $profile->valid_until->toDateString() < $endDate))) {
                return $result;
            }
            $condition = CustomerCondition::where('customer_id', $s->customer_id)->when($requireProfile, fn ($q) => $q->whereIn('id', $profile->condition_ids))->where('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $endDate))->find($p['condition_id'] ?? 0);
            if (! $condition || ! isset($p['quantity']) || blank($condition->terms)) {
                return $result;
            }
            $result['prices'][$i->id] = ['title' => $condition->label, 'unit' => $condition->unit, 'quantity' => (string) $p['quantity'], 'price' => number_format($condition->unit_price_cents / 100, 2, '.', ''), 'kind' => 'standard', 'condition_id' => $condition->id, 'terms' => $condition->terms];
            if (in_array($i->status, ['offered', 'accepted'], true)) {
                $expected = app(CommercialOfferService::class)->positions([$result['prices'][$i->id]], $s->customer_id, $date);
                $offer = CommercialOfferRevision::find($i->offer['commercial_offer_id'] ?? 0);
                $latestPublication = $offer ? CustomerPortalPublication::where('customer_id', $s->customer_id)->where('subject_type', 'offer')->where('subject_id', $offer->id)->latest('revision')->first() : null;
                if ($requireProfile && $latestPublication?->status === 'withdrawn') {
                    return $result + ['reason' => 'publication_withdrawn'];
                }
                if (! $offer || json_encode($offer->snapshot['positions']) !== json_encode($expected['positions']) || ($offer->snapshot['terms'] ?? '') !== $condition->terms) {
                    return $result + ['reason' => 'price_changed'];
                }
            }
        }
        $total = 0;
        foreach ($result['prices'] as $inquiryId => $position) {
            $i = OperationInquiry::findOrFail($inquiryId);
            $total += app(CommercialOfferService::class)->positions([$position], $s->customer_id, $i->starts_at->setTimezone($i->timezone)->format('Y-m-d'))['amount_cents'];
        }
        if (! $result['prices'] || ($requireProfile && ($profile->maximum_total_cents === null || $total > $profile->maximum_total_cents))) {
            return $result;
        }

        return ['outcome' => 'ready', 'message' => 'Aktuelle Grundlagen vollständig; Kapazität wird vor Zusage reserviert.', 'prices' => $result['prices'], 'total_cents' => $total, 'profile_revision' => $profile?->revision];
    }

    private function offers(User $actor, CustomerPortalSubmission $s, array $prices): void
    {
        foreach ($s->load('items')->items as $item) {
            $i = OperationInquiry::lockForUpdate()->findOrFail($item->inquiry_id);
            if (in_array($i->status, ['offered', 'accepted'], true)) {
                $offer = CommercialOfferRevision::findOrFail($i->offer['commercial_offer_id']);
                $published = CustomerPortalPublication::where('customer_id', $s->customer_id)->where('subject_type', 'offer')->where('subject_id', $offer->id)->latest('revision')->first();
                if (! $published || $published->status !== 'published' || $published->source_revision !== $offer->state_version) {
                    app(CustomerPortalPublicationService::class)->publish($actor, $s->customer_id, 'offer', $offer->id, ['location_id' => $item->location_id]);
                }

                continue;
            }
            $position = $prices[$i->id];
            $latest = CommercialOfferRevision::where('subject_type', 'OperationInquiry')->where('subject_id', $i->id)->latest('revision')->first();
            $draft = app(CommercialOfferService::class)->draft($i, $latest?->id, ['kind' => 'offer', 'terms' => $position['terms'], 'valid_until' => now($i->timezone)->addDays(2)->format('Y-m-d'), 'positions' => [$position]], $actor);
            app(CommercialOfferService::class)->issue($draft->id, $draft->fresh()->state_version, $actor);
            app(CustomerPortalPublicationService::class)->publish($actor, $s->customer_id, 'offer', $draft->id, ['location_id' => $item->location_id]);
        }
    }

    private function finish(CustomerPortalSubmission $s, string $status, string $note, User $actor, bool $automatic): CustomerPortalSubmission
    {
        $profile = CustomerPortalAutomationProfile::where('customer_id', $s->customer_id)->first();
        if ($s->status === $status && ($s->decision['message'] ?? null) === $note && ($s->decision['automatic'] ?? null) === $automatic && ($s->decision['profile_revision'] ?? null) === $profile?->revision) {
            return $s->load('items');
        }
        $s->forceFill(['status' => $status, 'revision' => $s->revision + 1, 'decision' => ['message' => $note, 'automatic' => $automatic, 'actor_id' => $actor->id, 'profile_id' => $profile?->id, 'profile_revision' => $profile?->revision, 'at' => now()->utc()->toIso8601String()]])->save();
        CustomerPortalAudit::create(['customer_id' => $s->customer_id, 'membership_id' => $s->membership_id, 'actor_id' => $actor->id, 'action' => 'submission_'.$status, 'revision' => $s->revision, 'details' => ['submission_id' => $s->id, 'automatic' => $automatic, 'profile_revision' => $profile?->revision]]);
        app(CustomerPortalDeliveryService::class)->enqueue($s->customer_id, $s->membership_id, 'decisions', 'portal-decision:'.$s->id.':'.$s->revision, ['title' => 'Rückmeldung zu Ihrer Anfrage', 'message' => $note, 'url' => route('customer-portal.workspace', ['customer' => $s->customer_id, 'section' => 'requests'])]);

        return $s->load('items');
    }

    private function profileSnapshot(CustomerPortalAutomationProfile $profile, User $actor, string $action): void
    {
        DB::table('customer_portal_profile_revisions')->insert(['profile_id' => $profile->id, 'revision' => $profile->revision, 'action' => $action, 'snapshot' => encrypt($profile->getAttributes()), 'actor_id' => $actor->id, 'created_at' => now()->utc()]);
    }
}
