<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DispositionMailboxReview;
use App\Models\User;
use App\Support\Operations\AiCustomerCommunicationSchema;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\OperationsTransaction;

/** A single captured mail, followed by explicit human decisions in the native workflows. */
class DispositionMailboxWorkflowService
{
    private const FIELDS = ['customer_id' => 'Kundenzuordnung', 'starts_at' => 'Beginn', 'ends_at' => 'Ende', 'timezone' => 'Zeitzone', 'location_name' => 'Einsatzort', 'role_name' => 'Tätigkeit', 'required_staff' => 'Personalbedarf', 'segments' => 'Einsatzabschnitte', 'qualification_ids' => 'Qualifikationen', 'native_review' => 'Bestehende Anfrage prüfen'];

    public function __construct(private readonly DispositionMailboxService $mailbox) {}

    public function recognize(User $actor, string $folder, int $uid, int $validity, int $expectedSettingsRevision): array
    {
        $settings = $this->settings($actor, $expectedSettingsRevision);
        $intake = $this->mailbox->captureForRecognition($actor, $folder, $uid, $validity, $expectedSettingsRevision);
        $this->selectedSource($intake, $settings, $uid, $validity);
        $review = $this->review($settings, $folder, $uid, $validity);
        $intake = app(AiIntakeService::class)->analyzeManually($intake, $actor, $intake->revision, $expectedSettingsRevision);
        $this->settings($actor, $expectedSettingsRevision);
        abort_unless($review->fresh()->revision === $review->revision, 409, 'Die Mail wurde während der Erkennung geprüft. Bitte erneut öffnen.');

        return $this->snapshot($actor, $intake, $settings, $folder, $uid, $validity);
    }

    public function refresh(User $actor, array $context): array
    {
        [$intake, $settings] = $this->current($actor, $context);

        return $this->snapshot($actor, $intake, $settings, $context['folder'], $context['uid'], $context['uid_validity']);
    }

    public function confirmClassification(User $actor, array $context, bool $inquiry): array
    {
        $this->current($actor, $context);
        $this->mailbox->classify($actor, $context['folder'], $context['uid'], $context['uid_validity'], $inquiry ? 'inquiry' : 'not_inquiry', $context['review_revision'], $context['intake_revision']);
        $settings = $this->settings($actor, $context['settings_revision']);
        $intake = $this->mailbox->linkedIntake($actor, $context['intake_id']);

        return $this->snapshot($actor, $intake, $settings, $context['folder'], $context['uid'], $context['uid_validity']);
    }

    public function assignCustomer(User $actor, array $context, int $customerId, ?int $contactId = null): array
    {
        return OperationsTransaction::run(function () use ($actor, $context, $customerId, $contactId): array {
            [$intake, $settings, $review] = $this->current($actor, $context, true);
            $this->confirmedInquiry($review);
            abort_if(in_array($intake->status, ['paused', 'completed'], true), 409, 'Diesen Eingang bitte in der bestehenden Anfrage prüfen.');
            app(AiIntakeService::class)->assignCustomer($intake, $actor, $customerId, $contactId, $context['intake_revision']);

            return $this->snapshot($actor, $intake->fresh(), $settings, $context['folder'], $context['uid'], $context['uid_validity']);
        });
    }

    public function createInquiries(User $actor, array $context): array
    {
        return OperationsTransaction::run(function () use ($actor, $context): array {
            [$intake, $settings, $review] = $this->current($actor, $context, true);
            $this->confirmedCustomer($intake, $review);
            abort_unless(($intake->analysis['intent'] ?? '') === 'inquiry' && ($intake->analysis['extraction_complete'] ?? false) && ! ($intake->analysis['overflow'] ?? true)
                && $intake->proposals()->where('source_revision', $intake->source_revision)->exists(), 422, 'Die erkannten Angaben zuerst in der Anfrageprüfung vervollständigen.');
            app(AiIntakeService::class)->createInquiries($intake, $actor, $context['intake_revision']);

            return $this->snapshot($actor, $intake->fresh(), $settings, $context['folder'], $context['uid'], $context['uid_validity']);
        });
    }

    public function prepareClarification(User $actor, array $context): array
    {
        return OperationsTransaction::run(function () use ($actor, $context): array {
            [$intake, $settings, $review] = $this->current($actor, $context, true);
            $this->confirmedCustomer($intake, $review);
            app(AiIntakeMailService::class)->prepareManualClarification($intake, $actor, $context['intake_revision'], $context['settings_revision']);

            return $this->snapshot($actor, $intake->fresh(), $settings, $context['folder'], $context['uid'], $context['uid_validity']);
        });
    }

    public function customerOptions(User $actor, string $search = '', ?int $selectedId = null): array
    {
        $this->settings($actor);
        abort_if(mb_strlen($search) > 120 || preg_match('/[\x00-\x1f]/', $search), 422);

        $customers = Customer::active()->when(trim($search) !== '', fn ($query) => $query->where('company_name', 'like', '%'.trim($search).'%'))
            ->orderBy('company_name')->limit(20)->get(['id', 'company_name']);
        if ($selectedId && ! $customers->contains('id', $selectedId) && ($selected = Customer::active()->whereKey($selectedId)->first(['id', 'company_name']))) {
            $customers = $customers->take(19)->push($selected);
        }

        return $customers->map(fn ($customer) => ['id' => $customer->id, 'name' => $customer->company_name])->all();
    }

    public function contactOptions(User $actor, int $customerId, ?int $selectedId = null): array
    {
        $this->settings($actor);
        abort_unless(Customer::active()->whereKey($customerId)->exists(), 404);

        $contacts = CustomerContact::where('customer_id', $customerId)->where('is_active', true)->orderBy('name')->limit(30)->get(['id', 'name', 'email']);
        if ($selectedId && ! $contacts->contains('id', $selectedId) && ($selected = CustomerContact::where('customer_id', $customerId)->where('is_active', true)->whereKey($selectedId)->first(['id', 'name', 'email']))) {
            $contacts = $contacts->take(29)->push($selected);
        }

        return $contacts->map(fn ($contact) => ['id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email])->all();
    }

    private function settings(User $actor, ?int $revision = null): array
    {
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.inquiries.manage');
        OperationsAccess::requireReady();
        abort_unless($this->mailbox->available($actor), 409, 'Dispositionspostfach bitte zuerst einrichten.');
        $settings = AiDispositionSettings::all(true);
        abort_if($revision !== null && (int) $settings['revision'] !== $revision, 409, 'Das Postfach wurde geändert. Bitte neu öffnen.');

        return $settings;
    }

    private function review(array $settings, string $folder, int $uid, int $validity, bool $lock = false): DispositionMailboxReview
    {
        abort_unless($folder === $settings['imap_folder'] && $uid > 0 && $validity > 0, 422);

        return DispositionMailboxReview::where(['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'folder_hash' => hash('sha256', $folder), 'uid' => $uid, 'uid_validity' => $validity])
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    private function current(User $actor, array $context, bool $lock = false): array
    {
        foreach (['intake_id', 'intake_revision', 'source_revision', 'settings_revision', 'review_revision', 'uid', 'uid_validity'] as $key) {
            abort_unless(isset($context[$key]) && is_int($context[$key]) && $context[$key] >= ($key === 'review_revision' ? 0 : 1), 422);
        }
        abort_unless(is_string($context['folder'] ?? null), 422);
        $settings = $this->settings($actor, $context['settings_revision']);
        abort_unless(($context['mailbox_id'] ?? '') === AiDispositionSettings::mailboxId($settings), 409, 'Das Postfach wurde geändert. Bitte neu öffnen.');
        $review = $this->review($settings, $context['folder'], $context['uid'], $context['uid_validity'], $lock);
        abort_unless($review->revision === $context['review_revision'] && $review->intake_id === $context['intake_id'], 409, 'Die Mailprüfung wurde geändert. Bitte erneut erkennen.');
        $intake = $this->mailbox->linkedIntake($actor, $context['intake_id']);
        if ($lock) {
            $intake = AiIntake::lockForUpdate()->findOrFail($intake->id);
        }
        abort_unless($intake->revision === $context['intake_revision'] && $intake->source_revision === $context['source_revision'], 409, 'Der Eingang wurde geändert. Bitte erneut erkennen.');
        $this->selectedSource($intake, $settings, $context['uid'], $context['uid_validity']);

        return [$intake, $settings, $review];
    }

    private function selectedSource(AiIntake $intake, array $settings, int $uid, int $validity): void
    {
        $receipt = hash('sha256', json_encode([AiDispositionSettings::mailboxId($settings), (string) $validity, (string) $uid, null], JSON_THROW_ON_ERROR));
        $source = AiIntakeMessage::where('receipt_key', $receipt)->where('intake_id', $intake->id)->firstOrFail();
        abort_unless($source->id === $intake->latest_inbound_message_id, 409, 'Zu diesem Eingang liegt eine neuere Mail vor. Bitte die neueste Nachricht öffnen und erkennen.');
    }

    private function confirmedInquiry(DispositionMailboxReview $review): void
    {
        abort_unless($review->reviewed_by && $review->classification === 'inquiry', 422, 'Bitte zuerst bestätigen, dass diese Mail eine Anfrage ist.');
    }

    private function confirmedCustomer(AiIntake $intake, DispositionMailboxReview $review): void
    {
        $this->confirmedInquiry($review);
        abort_unless($intake->customer_id && $intake->match_method === 'manual' && ! in_array($intake->status, ['paused', 'completed'], true), 422, 'Bitte zuerst die Kundenzuordnung bestätigen.');
    }

    private function snapshot(User $actor, AiIntake $intake, array $settings, string $folder, int $uid, int $validity): array
    {
        $review = $this->review($settings, $folder, $uid, $validity);
        $analysis = $intake->analysis ?? [];
        $detected = match (true) {
            ($analysis['intent'] ?? '') === 'inquiry' && in_array($analysis['classification'] ?? '', ['new_inquiry', 'reply'], true) => 'inquiry',
            in_array($analysis['classification'] ?? '', ['unrelated', 'auto_reply'], true) => 'not_inquiry',
            default => 'unreviewed',
        };
        $classification = $review->reviewed_by ? $review->classification : $detected;
        $positions = $intake->proposals()->where('source_revision', $intake->source_revision)->with('inquiry')->orderBy('position_index')->get()->map(function ($proposal): array {
            $demand = $proposal->payload['demand'] ?? [];

            return array_intersect_key($demand, array_flip(['title', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff'])) + [
                'id' => $proposal->id, 'missing_fields' => $proposal->payload['missing_fields'] ?? [], 'inquiry_id' => $proposal->inquiry_id,
                'inquiry_number' => $proposal->inquiry?->number, 'order_id' => $proposal->inquiry?->order_id,
                'inquiry_url' => $proposal->inquiry_id ? OperationsPages::url('cases', ['view' => 'inbox', 'inquiry' => $proposal->inquiry_id]) : null,
                'order_url' => $proposal->inquiry?->order_id ? OperationsPages::url('cases', ['view' => 'orders', 'section' => 'planning', 'order' => $proposal->inquiry->order_id]) : null,
            ];
        })->all();
        $suggestions = app(AiIntakeCustomerSuggestions::class)->suggest($intake, $actor);
        $customers = Customer::active()->whereIn('id', array_column($suggestions, 'customer_id'))->get(['id', 'company_name'])->keyBy('id');
        $missing = array_map(fn ($field) => ['key' => is_string($field) ? $field : '', 'label' => self::FIELDS[is_string($field) ? $field : ''] ?? 'Angabe prüfen'], $intake->missing_fields ?? []);
        $delivery = AiCustomerCommunicationSchema::ready() ? $intake->deliveries()->where('source_revision', $intake->source_revision)->where('message_type', 'clarification')->latest('id')->first() : null;
        $contact = $intake->contact;
        $message = $intake->messages()->whereKey($intake->latest_inbound_message_id)->first(['id', 'sender_email', 'reply_to_email', 'metadata']);
        $contactEmail = strtolower(trim((string) $contact?->email));
        $sourceMeta = $message?->metadata ?? [];
        $canClarify = $intake->match_method === 'manual' && $contact?->is_active && $contact->customer_id === $intake->customer_id
            && filter_var($contactEmail, FILTER_VALIDATE_EMAIL) && $message && hash_equals($contactEmail, strtolower(trim((string) $message->sender_email)))
            && (! $message->reply_to_email || hash_equals($contactEmail, strtolower(trim($message->reply_to_email))))
            && empty($sourceMeta['source_error']) && ($sourceMeta['raw_archive_complete'] ?? true) && ($sourceMeta['text_archive_complete'] ?? true)
            && in_array(strtolower(trim((string) ($sourceMeta['auto_submitted'] ?? ''))), ['', 'no'], true)
            && ! in_array(strtolower((string) ($sourceMeta['precedence'] ?? '')), ['bulk', 'junk', 'list'], true) && ($sourceMeta['list_id'] ?? '') === ''
            && ! ($sourceMeta['is_bounce'] ?? false) && ! hash_equals($contactEmail, strtolower($settings['from_address']));

        return [
            'intake_id' => $intake->id, 'intake_revision' => $intake->revision, 'source_revision' => $intake->source_revision, 'settings_revision' => (int) $settings['revision'],
            'mailbox_id' => AiDispositionSettings::mailboxId($settings), 'folder' => $folder, 'uid' => $uid, 'uid_validity' => $validity, 'review_revision' => $review->revision,
            'detected_classification' => $detected, 'detected_label' => DispositionMailboxReview::LABELS[$detected],
            'classification' => $classification, 'classification_label' => DispositionMailboxReview::LABELS[$classification],
            'title' => $intake->title, 'summary' => $intake->summary, 'positions' => $positions, 'missing_fields' => $missing,
            'intake_status' => $intake->status,
            'customer_id' => $intake->customer_id, 'contact_id' => $intake->customer_contact_id, 'customer_name' => $intake->customer?->company_name,
            'customer_suggestions' => array_values(array_filter(array_map(fn ($candidate) => isset($customers[$candidate['customer_id']]) ? ['id' => $candidate['customer_id'], 'name' => $customers[$candidate['customer_id']]->company_name, 'reasons' => $candidate['reasons']] : null, $suggestions))),
            'can_create_inquiries' => ($analysis['intent'] ?? '') === 'inquiry' && ($analysis['extraction_complete'] ?? false) && ! ($analysis['overflow'] ?? true) && $positions !== [] && collect($positions)->contains(fn ($position) => empty($position['inquiry_id'])),
            'can_prepare_clarification' => (bool) $canClarify && AiCustomerCommunicationSchema::ready() && ($analysis['intent'] ?? '') === 'inquiry' && count(array_intersect(array_column($missing, 'key'), ['starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff', 'segments', 'qualification_ids'])) > 0,
            'clarification' => $delivery ? ['id' => $delivery->id, 'to' => $delivery->recipient_email, 'subject' => $delivery->subject, 'body' => $delivery->body, 'status' => $delivery->status, 'status_label' => ($delivery->metadata['manual_mailbox_test'] ?? false) ? 'Testentwurf · ohne Versand' : (AiIntakeDelivery::STATUS_LABELS[$delivery->status] ?? 'Versand prüfen')] : null,
        ];
    }
}
