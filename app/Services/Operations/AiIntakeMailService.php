<?php

namespace App\Services\Operations;

use App\Enums\OrderStatus;
use App\Jobs\DeliverAiIntakeMail;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Support\Operations\AiCustomerCommunicationSchema;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiIntakeMailService
{
    public function __construct(private readonly AiDispositionSmtpTransport $transport) {}

    /** The global assisted setting always turns automatic communication into a private draft. */
    public function mode(array $settings, string $type): string
    {
        $key = match ($type) {
            'receipt' => 'customer_receipt_mode',
            'order_confirmation' => 'customer_confirmation_mode',
            default => 'customer_clarification_mode',
        };
        $mode = $settings[$key] ?? ($type === 'clarification' ? 'automatic' : 'off');
        if (! in_array($mode, ['off', 'draft', 'automatic'], true)) {
            return 'off';
        }

        return $mode === 'automatic' && ($settings['automation_mode'] ?? 'automatic') !== 'automatic' ? 'draft' : $mode;
    }

    public function enqueueReceipt(AiIntake $intake): ?AiIntakeDelivery
    {
        if (! AiCustomerCommunicationSchema::ready()) {
            return null;
        }

        return DB::transaction(function () use ($intake): ?AiIntakeDelivery {
            $settings = AiDispositionSettings::all(true);
            $fresh = AiIntake::lockForUpdate()->findOrFail($intake->id);
            $contact = $this->verifiedContact($fresh, $settings);
            if (! $settings['enabled'] || $this->mode($settings, 'receipt') === 'off' || ! $contact || $fresh->paused_at || $fresh->source_revision !== 1 || in_array($fresh->status, ['paused', 'failed'], true)) {
                return null;
            }

            return $this->enqueueFixed($fresh, $contact, $settings, 'receipt', 'Eingang Ihrer Anfrage · '.$fresh->public_id,
                "Guten Tag,\n\nIhre Anfrage wurde unter der Referenz ".$fresh->public_id." erfasst. Wir prüfen die eingegangenen Angaben.\n\nDiese Eingangsbestätigung ist keine verbindliche Auftragsbestätigung.\n\n".$settings['from_name'],
                hash('sha256', 'receipt|'.$fresh->id));
        }, 3);
    }

    /** Resolve only the exact current source position that was converted through the native human gate. */
    public function enqueueForConvertedInquiry(int $inquiryId, int $approverId): int
    {
        if (! AiCustomerCommunicationSchema::ready()) {
            return 0;
        }
        $inquiry = OperationInquiry::find($inquiryId);
        $approver = User::where('status', true)->find($approverId);
        $order = $inquiry?->order_id ? Order::find($inquiry->order_id) : null;
        if (! $order || ! $approver || ! $approver->can('operations.inquiries.manage')) {
            return 0;
        }
        $count = 0;
        foreach (AiIntakeProposal::query()->select(['id', 'intake_id', 'source_revision'])->with('intake')->where('inquiry_id', $inquiryId)->orderBy('id')->limit(20)->get()->unique('intake_id') as $proposal) {
            if ($proposal->intake && $proposal->source_revision === $proposal->intake->source_revision) {
                $count += $this->enqueueOrderConfirmation($proposal->intake, $order, $approver)?->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $count;
    }

    /** Recover only persisted opt-in intents at the unchanged configuration, never historical conversions. */
    public function recoverConfirmationIntents(): int
    {
        if (! AiCustomerCommunicationSchema::ready()) {
            return 0;
        }
        $settings = AiDispositionSettings::all(true);
        if (! $settings['enabled'] || $this->mode($settings, 'order_confirmation') === 'off') {
            return 0;
        }
        $intents = OperationAudit::query()->select(['id', 'subject_id', 'actor_id'])->where('subject_type', 'OperationInquiry')->where('action', 'inquiry.convert')
            ->where('actor_kind', 'human')->whereNull('automation_run_id')->where('data->customer_confirmation->enabled', true)
            ->where('data->customer_confirmation->settings_revision', (int) $settings['revision'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('ai_intake_deliveries')->whereColumn('approval_audit_id', 'operation_audits.id')->where('message_type', 'order_confirmation'))
            ->latest('id')->limit(100)->get();
        $count = 0;
        foreach ($intents as $intent) {
            try {
                $count += $this->enqueueForConvertedInquiry((int) $intent->subject_id, (int) $intent->actor_id);
            } catch (\Throwable) {
                // A failed queue/DB attempt retains its persisted intent or pending outbox for the next poll.
                continue;
            }
        }

        return $count;
    }

    public function enqueueOrderConfirmation(AiIntake $intake, Order $order, User $approver): ?AiIntakeDelivery
    {
        if (! AiCustomerCommunicationSchema::ready()) {
            return null;
        }

        return DB::transaction(function () use ($intake, $order, $approver): ?AiIntakeDelivery {
            $settings = AiDispositionSettings::all(true);
            $fresh = AiIntake::lockForUpdate()->findOrFail($intake->id);
            $contact = $this->verifiedContact($fresh, $settings);
            $native = $this->approvedOrder($fresh, (int) $order->id, (int) $approver->id, $settings);
            if (! $settings['enabled'] || $this->mode($settings, 'order_confirmation') === 'off' || ! $contact || ! $native || $fresh->paused_at || in_array($fresh->status, ['paused', 'failed'], true)) {
                return null;
            }
            $facts = $native['facts'];
            $body = "Guten Tag,\n\nwir bestätigen Ihren Auftrag mit folgenden Angaben:\n\n"
                .'- Auftragsnummer: '.$this->line($facts['order_number'])."\n"
                .'- Leistung: '.$this->line($facts['title'])."\n"
                .'- Funktion: '.$this->line($facts['service_type'])."\n"
                .'- Einsatzort: '.$this->line($facts['location_name'])."\n"
                .'- Beginn: '.$native['order']->starts_at->setTimezone($facts['timezone'])->format('d.m.Y H:i')."\n"
                .'- Ende: '.$native['order']->ends_at->setTimezone($facts['timezone'])->format('d.m.Y H:i')."\n"
                .'- Zeitzone: '.$facts['timezone']."\n"
                .'- Personalbedarf: '.$facts['required_staff']."\n\n"
                ."Diese Bestätigung bezieht sich auf die oben genannten Auftragsdaten. Es gelten die bereits vereinbarten Bedingungen.\n\n".$settings['from_name'];

            return $this->enqueueFixed($fresh, $contact, $settings, 'order_confirmation', 'Bestätigung Auftrag '.$this->line($facts['order_number']), $body,
                hash('sha256', 'order_confirmation|'.$fresh->id.'|'.$order->id), ['order_id' => $order->id, 'order_fingerprint' => $native['fingerprint'], 'approval_audit_id' => $native['audit']->id]);
        }, 3);
    }

    public function enqueueClarification(AiIntake $intake, array $questions, ?AiIntakeRun $run = null): ?AiIntakeDelivery
    {
        return DB::transaction(function () use ($intake, $questions, $run): ?AiIntakeDelivery {
            $settings = AiDispositionSettings::all(true);
            $fresh = AiIntake::lockForUpdate()->findOrFail($intake->id);
            $mode = $this->mode($settings, 'clarification');
            if (isset($fresh->analysis['intent']) && $fresh->analysis['intent'] !== 'inquiry') {
                return null;
            }
            $contact = $this->verifiedContact($fresh, $settings);
            if ($run !== null && ((int) $run->settings_revision !== (int) $settings['revision'] || $run->source_revision !== $fresh->source_revision)) {
                return null;
            }
            if (! $settings['enabled'] || $mode === 'off' || ($mode === 'draft' && ! AiCustomerCommunicationSchema::ready()) || ! $contact || $fresh->paused_at || $fresh->question_round >= $settings['max_rounds']) {
                return null;
            }
            $message = AiIntakeMessage::find($fresh->latest_inbound_message_id);
            $prompts = [
                'starts_at' => 'An welchem Datum und um welche Uhrzeit beginnt der Einsatz?',
                'ends_at' => 'An welchem Datum und um welche Uhrzeit endet der Einsatz?',
                'location_name' => 'An welchem Einsatzort findet der Einsatz statt?',
                'role_name' => 'Welche Funktion oder Leistung wird benötigt?',
                'required_staff' => 'Wie viele Mitarbeitende werden benötigt?',
                'timezone' => 'In welcher Zeitzone gelten die angegebenen Einsatzzeiten?',
                'segments' => 'Welche einzelnen Einsatzabschnitte und Zeiten gehören zur Anfrage?',
                'qualification_ids' => 'Welche Qualifikationen sind für den Einsatz erforderlich?',
            ];
            $questionText = collect($questions)->map(function ($question, $key) use ($prompts, $fresh): ?string {
                $field = is_array($question) ? (string) ($question['field'] ?? '') : (is_string($key) ? $key : (string) $question);
                if (! isset($prompts[$field])) {
                    return null;
                }
                if (is_array($question) && array_key_exists('position', $question)) {
                    $position = filter_var($question['position'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);

                    if ($position === false) {
                        return null;
                    }
                    $proposal = $fresh->proposals()->where('source_revision', $fresh->source_revision)->where('position_index', $position - 1)->first();
                    $demand = (array) ($proposal?->payload['demand'] ?? []);
                    $evidence = (array) ($proposal?->payload['evidence'] ?? []);
                    $labels = [];
                    foreach (['role_name' => 'Funktion', 'location_name' => 'Ort'] as $name => $label) {
                        if (! empty($evidence[$name]) && is_string($demand[$name] ?? null)) {
                            $value = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $demand[$name])), 0, 80);
                            $labels[] = $label.': „'.$value.'“';
                        }
                    }

                    return 'Leistung '.$position.($labels ? ' ('.implode(' · ', $labels).')' : '').': '.$prompts[$field];
                }

                return $prompts[$field];
            })->filter()->unique()->values();
            if ($questionText->isEmpty()) {
                return null;
            }
            $round = $fresh->question_round + 1;
            $dedup = hash('sha256', 'clarification|'.$fresh->id.'|'.$fresh->source_revision);
            $domain = substr(strrchr($settings['from_address'], '@') ?: '@railtime.invalid', 1);
            $metadata = (array) ($message?->metadata ?? []);
            $references = $this->messageIds($metadata['references'] ?? []);
            $parent = $this->messageIds([$message?->external_message_id]);
            $delivery = AiIntakeDelivery::firstOrCreate(['dedup_key' => $dedup], [
                'intake_id' => $fresh->id, 'message_id' => $message?->id, 'customer_id' => $fresh->customer_id, 'contact_id' => $contact->id,
                'recipient_email' => strtolower(trim($contact->email)), 'subject' => mb_substr('Rückfrage zu Ihrer Anfrage · '.$fresh->public_id, 0, 180),
                'body' => "Guten Tag,\n\nfür die Bearbeitung Ihrer Anfrage fehlen noch folgende Angaben:\n\n".$questionText->map(fn ($q) => '- '.$q)->implode("\n")."\n\nBitte antworten Sie auf diese E-Mail. Ihre Anfrage ist noch keine verbindliche Auftragsbestätigung.\n\n".$settings['from_name'],
                'status' => $mode === 'draft' ? 'draft' : 'pending', 'settings_revision' => $settings['revision'], 'intake_revision' => $fresh->revision,
                'source_revision' => $fresh->source_revision, 'question_round' => $round, 'attempts' => 0,
                'message_id_header' => 'ai-'.Str::uuid().'@'.$domain, 'in_reply_to' => $parent[0] ?? null,
                'references' => array_slice(array_values(array_unique([...$references, ...$parent])), -20),
                'metadata' => ['run_id' => $run?->id, 'contact_revision' => (int) $contact->revision, 'supervisor_id' => $settings['supervisor_id'], 'dispatch_mode' => $mode, 'created_as_draft' => $mode === 'draft'],
            ] + (AiCustomerCommunicationSchema::ready() ? ['message_type' => 'clarification'] : []));
            if ($delivery->wasRecentlyCreated) {
                $fresh->forceFill($mode === 'draft'
                    ? ['status' => 'review', 'error_code' => 'customer_message_approval_required', 'revision' => $fresh->revision + 1]
                    : ['question_round' => $round, 'status' => 'waiting_customer', 'revision' => $fresh->revision + 1])->save();
                $delivery->forceFill(['intake_revision' => $fresh->revision])->save();
                if ($mode !== 'draft') {
                    DeliverAiIntakeMail::dispatch($delivery->id)->afterCommit();
                }
            }

            return in_array($delivery->status, ['canceled', 'unknown'], true) ? null : $delivery;
        });
    }

    /** Concrete private review DTOs: never pass these templates or recipients to a provider or activity export. */
    public function draftMessages(User $actor): Collection
    {
        $actor = User::where('status', true)->find($actor->id);
        if (! $actor?->can('operations.inquiries.manage') || ! AiCustomerCommunicationSchema::ready()) {
            return collect();
        }
        $settings = AiDispositionSettings::all(true);

        return AiIntakeDelivery::query()->with(['intake', 'customer:id,company_name', 'contact:id,name,email'])->where('status', 'draft')->latest('id')->limit(10)->get()->map(function (AiIntakeDelivery $delivery) use ($settings): array {
            $intake = $delivery->intake;
            $contact = $intake ? $this->verifiedContact($intake, $settings) : null;

            return ['id' => (int) $delivery->id, 'message_type' => $this->type($delivery), 'intake_id' => (int) $delivery->intake_id,
                'title' => $delivery->subject, 'status' => 'draft', 'settings_revision' => $delivery->settings_revision,
                'intake_revision' => (int) ($intake?->revision ?? $delivery->intake_revision), 'created_at' => $delivery->created_at->toIso8601String(),
                'customer_name' => $delivery->customer?->company_name ?? 'Kunde',
                'recipient_label' => trim(($delivery->contact?->name ?? 'Kontakt').' · '.$delivery->recipient_email), 'body' => $delivery->body,
                'can_approve' => $intake && $this->contextValid($delivery, $intake, $settings, $contact, true)];
        });
    }

    public function approveDraft(AiIntakeDelivery $delivery, User $actor, int $expectedSettingsRevision, int $expectedIntakeRevision): AiIntakeDelivery
    {
        $actor = $this->authorizedActor($actor);

        return DB::transaction(function () use ($delivery, $actor, $expectedSettingsRevision, $expectedIntakeRevision): AiIntakeDelivery {
            $intake = AiIntake::lockForUpdate()->findOrFail($delivery->intake_id);
            $record = AiIntakeDelivery::lockForUpdate()->findOrFail($delivery->id);
            $this->authorizedActor($actor);
            $settings = AiDispositionSettings::all(true);
            abort_unless($record->status === 'draft' && $record->intake_id === $intake->id && $expectedSettingsRevision === $record->settings_revision && $expectedIntakeRevision === $intake->revision
                && $this->contextValid($record, $intake, $settings, $this->verifiedContact($intake, $settings), true), 409, 'Nachrichtenentwurf oder Freigabegrundlage wurde geändert. Bitte neu prüfen.');
            if ($this->type($record) === 'clarification') {
                $intake->forceFill(['question_round' => $record->question_round, 'status' => 'waiting_customer', 'error_code' => null, 'revision' => $intake->revision + 1])->save();
            }
            $record->forceFill(['status' => 'pending', 'approved_by' => $actor->id, 'approved_at' => now()->utc(), 'intake_revision' => $intake->revision])->save();
            $this->auditMessage($record, $actor, 'approved', $intake->revision);
            DeliverAiIntakeMail::dispatch($record->id)->afterCommit();

            return $record;
        }, 3);
    }

    public function rejectDraft(AiIntakeDelivery $delivery, User $actor, int $expectedSettingsRevision, int $expectedIntakeRevision): AiIntakeDelivery
    {
        $actor = $this->authorizedActor($actor);

        return DB::transaction(function () use ($delivery, $actor, $expectedSettingsRevision, $expectedIntakeRevision): AiIntakeDelivery {
            $intake = AiIntake::lockForUpdate()->findOrFail($delivery->intake_id);
            $record = AiIntakeDelivery::lockForUpdate()->findOrFail($delivery->id);
            $this->authorizedActor($actor);
            abort_unless($record->status === 'draft' && $record->intake_id === $intake->id && $record->settings_revision === $expectedSettingsRevision && $intake->revision === $expectedIntakeRevision, 409, 'Nachrichtenentwurf wurde geändert. Bitte neu laden.');
            $record->forceFill(['status' => 'canceled', 'failure_code' => 'personally_rejected'])->save();
            if ($this->type($record) === 'clarification' && $intake->error_code === 'customer_message_approval_required') {
                $intake->forceFill(['status' => 'review', 'error_code' => 'customer_clarification_rejected', 'revision' => $intake->revision + 1])->save();
            }
            $this->auditMessage($record, $actor, 'rejected', $intake->revision);

            return $record;
        }, 3);
    }

    private function enqueueFixed(AiIntake $intake, CustomerContact $contact, array $settings, string $type, string $subject, string $body, string $dedup, array $extra = []): AiIntakeDelivery
    {
        $message = $intake->messages()->where('direction', 'inbound')->findOrFail($intake->latest_inbound_message_id);
        $mode = $this->mode($settings, $type);
        $domain = substr(strrchr($settings['from_address'], '@') ?: '@railtime.invalid', 1);
        $parent = $this->messageIds([$message->external_message_id]);
        $references = $this->messageIds($message->metadata['references'] ?? []);
        $delivery = AiIntakeDelivery::firstOrCreate(['dedup_key' => $dedup], $extra + [
            'intake_id' => $intake->id, 'message_id' => $message->id, 'message_type' => $type, 'customer_id' => $intake->customer_id, 'contact_id' => $contact->id,
            'recipient_email' => strtolower(trim($contact->email)), 'subject' => mb_substr($subject, 0, 180), 'body' => $body,
            'status' => $mode === 'draft' ? 'draft' : 'pending', 'settings_revision' => $settings['revision'], 'intake_revision' => $intake->revision,
            'source_revision' => $intake->source_revision, 'question_round' => 0, 'attempts' => 0,
            'message_id_header' => 'ai-'.Str::uuid().'@'.$domain, 'in_reply_to' => $parent[0] ?? null,
            'references' => array_slice(array_values(array_unique([...$references, ...$parent])), -20),
            'metadata' => ['contact_revision' => (int) $contact->revision, 'supervisor_id' => $settings['supervisor_id'], 'dispatch_mode' => $mode, 'created_as_draft' => $mode === 'draft'],
        ]);
        if ($delivery->wasRecentlyCreated && $mode === 'automatic') {
            DeliverAiIntakeMail::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }

    private function type(AiIntakeDelivery $delivery): string
    {
        return $delivery->message_type ?: 'clarification';
    }

    private function authorizedActor(User $actor): User
    {
        $fresh = User::where('status', true)->find($actor->id);
        abort_unless($fresh, 403);
        OperationsAccess::authorize($fresh, 'operations.inquiries.manage');

        return $fresh;
    }

    private function auditMessage(AiIntakeDelivery $delivery, User $actor, string $action, int $revision): void
    {
        OperationAudit::create(['subject_type' => 'AiIntakeDelivery', 'subject_id' => $delivery->id, 'actor_id' => $actor->id, 'actor_kind' => 'human',
            'action' => 'ai.customer_message.'.$action, 'revision' => $revision, 'data' => ['intake_id' => $delivery->intake_id, 'message_type' => $this->type($delivery)], 'created_at' => now()->utc()]);
    }

    private function contextValid(AiIntakeDelivery $delivery, AiIntake $intake, array $settings, ?CustomerContact $contact, bool $forApproval = false): bool
    {
        $type = $this->type($delivery);
        $mode = $this->mode($settings, $type);
        if (! in_array($type, ['receipt', 'clarification', 'order_confirmation'], true) || ! $settings['enabled'] || $mode === 'off' || (int) $settings['revision'] !== $delivery->settings_revision || ! $contact || $intake->paused_at || in_array($intake->status, ['paused', 'failed'], true)
            || $intake->source_revision !== $delivery->source_revision || $intake->latest_inbound_message_id !== $delivery->message_id || $contact->id !== (int) $delivery->contact_id || $intake->customer_id !== (int) $delivery->customer_id
            || $contact->revision !== (int) ($delivery->metadata['contact_revision'] ?? 0) || ! hash_equals(strtolower(trim($contact->email)), $delivery->recipient_email)) {
            return false;
        }
        if ($type === 'clarification' && ($intake->status === 'completed' || $intake->revision !== $delivery->intake_revision || $delivery->question_round > $settings['max_rounds'] || ($forApproval && $delivery->question_round !== $intake->question_round + 1))) {
            return false;
        }
        if ($type === 'clarification' && isset($intake->analysis['intent']) && $intake->analysis['intent'] !== 'inquiry') {
            return false;
        }
        if ($forApproval) {
            if ($mode !== 'draft') {
                return false;
            }
        } elseif (($delivery->metadata['dispatch_mode'] ?? 'automatic') === 'draft') {
            $approver = $delivery->approved_at ? User::where('status', true)->find($delivery->approved_by) : null;
            if (! $approver?->can('operations.inquiries.manage')) {
                return false;
            }
        } elseif ($mode !== 'automatic') {
            return false;
        }
        if ($type === 'order_confirmation') {
            $native = $this->approvedOrder($intake, (int) $delivery->order_id, null, $settings);
            if (! $native || (int) $native['audit']->id !== $delivery->approval_audit_id || ! hash_equals($native['fingerprint'], (string) $delivery->order_fingerprint)) {
                return false;
            }
        }

        return true;
    }

    /** Only immutable converted inquiry facts and its actual human conversion audit authorize a confirmation. */
    private function approvedOrder(AiIntake $intake, int $orderId, ?int $expectedApprover = null, ?array $settings = null): ?array
    {
        $order = Order::find($orderId);
        $inquiry = OperationInquiry::where('order_id', $orderId)->where('status', 'converted')->first();
        if (! $order || ! $inquiry || ! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Planned], true) || (int) $order->customer_id !== $intake->customer_id
            || $inquiry->accepted_revision !== $inquiry->revision || ($inquiry->offer['revision'] ?? null) !== $inquiry->revision
            || ! $intake->proposals()->where('source_revision', $intake->source_revision)->where('inquiry_id', $inquiry->id)->exists()) {
            return null;
        }
        $audit = OperationAudit::where('subject_type', 'OperationInquiry')->where('subject_id', $inquiry->id)->where('action', 'inquiry.convert')->where('actor_kind', 'human')->whereNull('automation_run_id')->where('revision', $inquiry->revision)->latest('id')->first();
        $actor = $audit ? User::where('status', true)->find($audit->actor_id) : null;
        if (! $actor?->can('operations.inquiries.manage') || ($expectedApprover !== null && $actor->id !== $expectedApprover) || (int) ($audit->data['order_id'] ?? 0) !== $orderId) {
            return null;
        }
        $settings ??= AiDispositionSettings::all(true);
        $intent = (array) ($audit->data['customer_confirmation'] ?? []);
        if (($intent['enabled'] ?? false) !== true || (int) ($intent['settings_revision'] ?? 0) !== (int) $settings['revision'] || (int) ($intent['supervisor_id'] ?? 0) !== (int) $settings['supervisor_id']) {
            return null;
        }
        foreach (['customer_id', 'title', 'location_name', 'required_staff', 'timezone'] as $field) {
            if ((string) $order->$field !== (string) $inquiry->$field) {
                return null;
            }
        }
        if ((string) $order->service_type !== (string) $inquiry->role_name || ! $order->starts_at?->equalTo($inquiry->starts_at) || ! $order->ends_at?->equalTo($inquiry->ends_at)) {
            return null;
        }
        $facts = ['order_id' => (int) $order->id, 'order_number' => (string) $order->order_number, 'customer_id' => (int) $order->customer_id,
            'title' => (string) $order->title, 'service_type' => (string) $order->service_type, 'location_name' => (string) $order->location_name,
            'starts_at' => $order->starts_at->utc()->toIso8601String(), 'ends_at' => $order->ends_at->utc()->toIso8601String(),
            'timezone' => (string) $order->timezone, 'required_staff' => (int) $order->required_staff, 'inquiry_revision' => (int) $inquiry->revision, 'approval_audit_id' => (int) $audit->id];

        return ['order' => $order, 'audit' => $audit, 'facts' => $facts, 'fingerprint' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR))];
    }

    private function line(string $value): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? ''), 0, 180);
    }

    private function verifiedContact(AiIntake $intake, array $settings): ?CustomerContact
    {
        if (! AiDispositionSettings::supervisor($settings) || (int) $intake->supervising_user_id !== (int) $settings['supervisor_id'] || $intake->match_method !== 'contact_email' || $intake->source_type !== 'email') {
            return null;
        }
        $customer = Customer::find($intake->customer_id);
        $contact = CustomerContact::where('customer_id', $intake->customer_id)->where('is_active', true)->find($intake->customer_contact_id);
        $message = AiIntakeMessage::where('intake_id', $intake->id)->where('direction', 'inbound')->find($intake->latest_inbound_message_id);
        if (! $customer?->is_active || ! $contact || ! $message) {
            return null;
        }
        $email = strtolower(trim($contact->email));
        $metadata = (array) $message->metadata;
        if (! empty($metadata['source_error']) || ($metadata['raw_archive_complete'] ?? true) === false || ($metadata['text_archive_complete'] ?? true) === false
            || str_starts_with((string) $intake->error_code, 'mailbox_') || in_array($intake->error_code, ['sender_unverified', 'automatic_message_review', 'attachments_review'], true)) {
            return null;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! hash_equals($email, strtolower(trim((string) $message->sender_email))) || ($message->reply_to_email && ! hash_equals($email, strtolower(trim($message->reply_to_email))))) {
            return null;
        }
        if (! in_array(strtolower(trim((string) ($metadata['auto_submitted'] ?? ''))), ['', 'no'], true) || in_array(strtolower((string) ($metadata['precedence'] ?? '')), ['bulk', 'junk', 'list'], true) || ($metadata['list_id'] ?? '') !== '' || ($metadata['is_bounce'] ?? false) || hash_equals($email, strtolower($settings['from_address']))) {
            return null;
        }
        // Duplicate contacts with the same sender are an ambiguous customer assignment.
        $matching = CustomerContact::where('is_active', true)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->count();

        return $matching === 1 ? $contact : null;
    }

    public function deliver(int $deliveryId): string
    {
        $base = AiIntakeDelivery::findOrFail($deliveryId);
        $claim = DB::transaction(function () use ($deliveryId, $base): ?array {
            $intake = AiIntake::lockForUpdate()->findOrFail($base->intake_id);
            $delivery = AiIntakeDelivery::lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->status !== 'pending') {
                return null;
            }
            abort_unless($delivery->intake_id === $intake->id, 409);
            $settings = AiDispositionSettings::all(true);
            $contact = $this->verifiedContact($intake, $settings);
            if (! $this->contextValid($delivery, $intake, $settings, $contact)) {
                $delivery->forceFill(['status' => 'canceled', 'failure_code' => 'context_changed'])->save();
                if ($this->type($delivery) === 'clarification' && $intake->status === 'waiting_customer' && $intake->source_revision === $delivery->source_revision) {
                    $intake->forceFill(['status' => 'review', 'error_code' => 'clarification_canceled', 'revision' => $intake->revision + 1])->save();
                }

                return null;
            }
            $delivery->forceFill(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'attempted_at' => now()->utc()])->save();

            return ['delivery' => $delivery, 'settings' => $settings];
        }, 3);
        if ($claim === null) {
            return AiIntakeDelivery::findOrFail($deliveryId)->status;
        }
        try {
            $this->transport->send($claim['delivery'], $claim['settings']);
            DB::transaction(function () use ($deliveryId, $claim): void {
                AiIntake::lockForUpdate()->findOrFail($claim['delivery']->intake_id);
                $delivery = AiIntakeDelivery::lockForUpdate()->findOrFail($deliveryId);
                $delivery->forceFill(['status' => 'sent', 'sent_at' => now()->utc(), 'failure_code' => null])->save();
                AiIntakeMessage::firstOrCreate(['intake_id' => $delivery->intake_id, 'direction' => 'outbound', 'external_message_id' => $delivery->message_id_header], ['sender_email' => $claim['settings']['from_address'], 'body' => $delivery->body, 'metadata' => ['delivery_id' => $delivery->id, 'message_type' => $this->type($delivery), 'in_reply_to' => $delivery->in_reply_to, 'references' => $delivery->references]]);
            });

            return 'sent';
        } catch (\Throwable) {
            $this->markUnknown($deliveryId, 'transport_outcome_unknown');

            return 'unknown';
        }
    }

    public function expireAwaitingReplies(): int
    {
        $settings = AiDispositionSettings::all(true);
        foreach (AiIntakeDelivery::where('status', 'sending')->where('attempted_at', '<', now()->utc()->subMinutes(10))->orderBy('id')->limit(100)->pluck('id') as $id) {
            $this->markUnknown((int) $id, 'worker_outcome_unknown');
        }
        if (! $settings['enabled']) {
            return 0;
        }
        $typed = AiCustomerCommunicationSchema::ready();
        $ids = AiIntake::where('status', 'waiting_customer')->whereHas('deliveries', fn ($query) => $query->when($typed, fn ($q) => $q->where('message_type', 'clarification'))->whereColumn('source_revision', 'ai_intakes.source_revision')->whereColumn('question_round', 'ai_intakes.question_round')->where('status', 'sent')->where('sent_at', '<', now()->utc()->subHours((int) $settings['reply_timeout_hours'])))
            ->whereDoesntHave('deliveries', fn ($query) => $query->when($typed, fn ($q) => $q->where('message_type', 'clarification'))->whereColumn('source_revision', 'ai_intakes.source_revision')->whereColumn('question_round', 'ai_intakes.question_round')->where('status', 'sent')->where('sent_at', '>=', now()->utc()->subHours((int) $settings['reply_timeout_hours'])))->orderBy('id')->limit(100)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $settings, $typed): int {
                $intake = AiIntake::lockForUpdate()->find($id);
                $latest = $intake?->deliveries()->when($typed, fn ($q) => $q->where('message_type', 'clarification'))->where('source_revision', $intake->source_revision)->where('question_round', $intake->question_round)->where('status', 'sent')->latest('sent_at')->first();
                if (! $intake || $intake->status !== 'waiting_customer' || $intake->paused_at || ! $latest || CarbonImmutable::parse($latest->getRawOriginal('sent_at'), 'UTC') >= now()->utc()->subHours((int) $settings['reply_timeout_hours'])) {
                    return 0;
                }
                $intake->forceFill(['status' => 'review', 'error_code' => 'customer_reply_timeout', 'revision' => $intake->revision + 1])->save();

                return 1;
            });
        }

        return $count;
    }

    private function markUnknown(int $deliveryId, string $failure): void
    {
        $base = AiIntakeDelivery::find($deliveryId);
        if (! $base) {
            return;
        }
        DB::transaction(function () use ($base, $failure): void {
            $intake = AiIntake::lockForUpdate()->find($base->intake_id);
            $delivery = AiIntakeDelivery::lockForUpdate()->find($base->id);
            if (! $delivery || $delivery->status !== 'sending') {
                return;
            }
            $delivery->forceFill(['status' => 'unknown', 'failure_code' => $failure])->save();
            if ($this->type($delivery) === 'clarification' && $intake?->status === 'waiting_customer' && $intake->source_revision === $delivery->source_revision) {
                $intake->forceFill(['status' => 'review', 'error_code' => 'delivery_outcome_unknown', 'revision' => $intake->revision + 1])->save();
            }
        }, 3);
    }

    private function messageIds(mixed $ids): array
    {
        $ids = is_array($ids) ? $ids : preg_split('/\s+/', (string) $ids);

        return array_values(array_filter(array_map(fn ($id) => trim((string) $id, '<> '), $ids), fn ($id) => preg_match('/^[^\s<>\r\n]+@[^\s<>\r\n]+$/D', $id) && strlen($id) < 191));
    }
}
