<?php

namespace App\Services\Operations;

use App\Jobs\ProcessAiIntake;
use App\Models\AiIntake;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\Ai\Attachments\AssistantAttachmentException;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsAutomationActor;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiIntakeService
{
    private const FIELDS = ['starts_at', 'ends_at', 'location_name', 'role_name', 'required_staff'];
    private const QUESTIONS = ['starts_at' => 'Bitte nennen Sie Datum und Uhrzeit des Beginns.', 'ends_at' => 'Bitte nennen Sie Datum und Uhrzeit des Endes.', 'location_name' => 'An welchem Einsatzort wird die Leistung benötigt?', 'role_name' => 'Welche Tätigkeit wird benötigt?', 'required_staff' => 'Wie viele Mitarbeiter werden benötigt?'];

    public function submit(User $actor, string $text, array $uploads = [], array $meta = []): AiIntake
    {
        $this->access($actor);
        Validator::make(compact('text', 'uploads'), ['text' => 'present|string|max:20000', 'uploads' => 'array|max:3'])->validate();
        $meta = Validator::make($meta, ['customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('is_active', true)->whereNull('deleted_at')], 'contact_email' => 'nullable|email|max:254', 'timezone' => 'nullable|timezone', 'kind' => 'nullable|in:text,audio,upload'])->validate();
        abort_if(trim($text) === '' && $uploads === [], 422, 'Text oder Anlage erforderlich.');
        $settings = AiDispositionSettings::all();
        $paths = [];
        try {
            return OperationsTransaction::run(function () use ($actor, $text, $uploads, $meta, $settings, &$paths) {
                $this->access($actor);
                $intake = AiIntake::create(['source_type' => 'manual', 'title' => 'Manueller Eingang', 'status' => AiDispositionSettings::enabled() ? 'received' : 'review', 'error_code' => AiDispositionSettings::enabled() ? null : 'automation_disabled', 'revision' => 1, 'source_revision' => 1, 'supervising_user_id' => ($settings['supervisor_id'] ?? null) ?: $actor->id, 'created_by' => $actor->id]);
                $email = $this->email($meta['contact_email'] ?? '');
                $this->matchCustomer($intake, $email);
                if (! empty($meta['customer_id'])) {
                    $intake->customer_id = (int) $meta['customer_id'];
                    $intake->match_method = 'manual';
                    if ($intake->customer_contact_id && $intake->contact?->customer_id !== $intake->customer_id) {
                        $intake->customer_contact_id = null;
                    }
                }
                $intake->save();
                $message = $intake->messages()->create(['direction' => 'inbound', 'body' => $text, 'sender_email' => $email ?: null, 'metadata' => ['kind' => $meta['kind'] ?? 'text', 'timezone' => $meta['timezone'] ?? 'Europe/Berlin', 'submitted_by' => $actor->id]]);
                app(AiIntakeAttachmentService::class)->store($intake, $message, $uploads, $paths);
                $intake->update(['latest_inbound_message_id' => $message->id]);
                if (AiDispositionSettings::enabled()) {
                    ProcessAiIntake::dispatch($intake->id)->afterCommit();
                }

                return $intake;
            });
        } catch (Throwable $e) {
            app(AiIntakeAttachmentService::class)->discard($paths);
            throw $e;
        }
    }

    public function receiveEmail(array $message): AiIntake
    {
        AiIntakeSchema::requireReady();
        $settings = AiDispositionSettings::all();
        abort_unless(AiDispositionSettings::enabled() && ! empty($settings['supervisor_id']), 409);
        $sender = $this->email($message['from'] ?? '');
        $replyTo = $this->email($message['reply_to'] ?? $sender);
        $text = (string) ($message['text'] ?? '');
        $raw = (string) ($message['raw'] ?? '');
        $receipt = hash('sha256', json_encode([(string) ($message['mailbox_id'] ?? 'disposition'), (string) ($message['uid_validity'] ?? ''), (string) ($message['uid'] ?? ''), empty($message['uid']) ? ($message['message_id'] ?? hash('sha256', $raw ?: $text)) : null], JSON_THROW_ON_ERROR));
        $existing = AiIntakeMessage::where('receipt_key', $receipt)->first();
        if ($existing) {
            return $existing->intake;
        }
        // A poisoned receipt must not stop later mail. Keep bounded originals and mark manual retrieval honestly.
        $sourceError = is_string($message['source_error'] ?? null) ? mb_substr($message['source_error'], 0, 100) : null;
        if (strlen($raw) > 32 * 1024 * 1024) {
            $message['declared_size'] = strlen($raw);
            $message['raw_archive_complete'] = false;
            $raw = 'Original überschreitet 32 MB; Nachricht muss im Dispositionspostfach manuell abgerufen werden.';
            $sourceError = 'mailbox_message_size';
        }
        if (mb_strlen($text) > 20000) {
            $sourceError ??= 'mailbox_text_limit';
        }
        if (strlen($text) > 32 * 1024 * 1024) {
            $text = 'Nachricht überschreitet die sichere Textspeicherung; Original im Postfach manuell abrufen.';
            $message['text_archive_complete'] = false;
            $sourceError = 'mailbox_message_size';
        }
        $paths = [];
        try {
            $record = OperationsTransaction::run(function () use ($message, $settings, $sender, $replyTo, $text, $raw, $receipt, $sourceError, &$paths) {
                if ($existing = AiIntakeMessage::where('receipt_key', $receipt)->first()) {
                    return $existing->intake;
                }
                $intake = $this->replyIntake($message, $sender);
                if ($intake) {
                    $intake = AiIntake::lockForUpdate()->findOrFail($intake->id);
                    $intake->source_revision++;
                    $intake->revision++;
                    $intake->status = $intake->status === 'paused' ? 'paused' : 'received';
                    $intake->processed_source_revision = null;
                    $intake->error_code = null;
                    $intake->proposals()->whereNotIn('status', ['applied', 'stale'])->update(['status' => 'stale', 'error_code' => 'source_changed']);
                    $intake->save();
                } else {
                    $intake = AiIntake::create(['source_type' => 'email', 'status' => 'received', 'revision' => 1, 'source_revision' => 1, 'title' => mb_substr((string) ($message['subject'] ?? 'E-Mail-Anfrage'), 0, 180), 'supervising_user_id' => $settings['supervisor_id']]);
                }
                $metadata = array_intersect_key($message, array_flip(['auto_submitted', 'precedence', 'list_id', 'is_bounce', 'in_reply_to', 'references', 'mailbox_id', 'uid_validity', 'uid', 'subject', 'source_error', 'raw_archive_complete', 'text_archive_complete', 'declared_size']));
                $metadata['raw_archive_complete'] ??= true;
                $metadata['content_hash'] = hash('sha256', $raw ?: $text);
                $input = ['direction' => 'inbound', 'receipt_key' => $receipt, 'body' => $text, 'sender_email' => $sender ?: null, 'reply_to_email' => $replyTo ?: null, 'external_message_id' => $this->messageId($message['message_id'] ?? null), 'metadata' => $metadata];
                if ($raw !== '') {
                    $input['raw_path'] = app(AiIntakeAttachmentService::class)->put($intake, $raw, $paths);
                    $input['raw_hash'] = hash('sha256', $raw);
                }
                $stored = $intake->messages()->create($input);
                $error = $sourceError;
                if (! $error) {
                    try {
                        app(AiIntakeAttachmentService::class)->store($intake, $stored, (array) ($message['attachments'] ?? []), $paths);
                    } catch (ValidationException|AssistantAttachmentException $e) {
                        $error = 'attachments_review';
                    } catch (HttpException $e) {
                        if ($e->getStatusCode() !== 422) {
                            throw $e;
                        }
                        $error = 'attachments_review';
                    }
                }
                $intake->latest_inbound_message_id = $stored->id;
                $this->matchCustomer($intake, $sender);
                if ($sender === '' || ($replyTo !== '' && $replyTo !== $sender)) {
                    $intake->status = 'review';
                    $intake->error_code = 'sender_unverified';
                }
                if ($error) {
                    $intake->status = 'review';
                    $intake->error_code = $error;
                }
                $intake->save();
                if (! $error && $intake->status !== 'paused') {
                    ProcessAiIntake::dispatch($intake->id)->afterCommit();
                }

                return $intake;
            });
            app(AiIntakeAttachmentService::class)->discard($paths);

            return $record;
        } catch (Throwable $e) {
            app(AiIntakeAttachmentService::class)->discard($paths);
            // A unique receipt race has one winning immutable source owner.
            if ($existing = AiIntakeMessage::where('receipt_key', $receipt)->first()) {
                return $existing->intake;
            }
            throw $e;
        }
    }

    public function analyze(int $intakeId): void
    {
        AiIntakeSchema::requireReady();
        $lock = Cache::lock('ai-intake:'.$intakeId, 660);
        if (! $lock->get()) {
            return;
        }
        $run = null;
        try {
            $settings = AiDispositionSettings::all();
            if (! AiDispositionSettings::enabled()) {
                return;
            }
            $intake = OperationsTransaction::run(function () use ($intakeId, $settings, &$run) {
                $intake = AiIntake::lockForUpdate()->findOrFail($intakeId);
                if (in_array($intake->status, ['paused', 'completed'], true) || $intake->processed_source_revision === $intake->source_revision || str_starts_with((string) $intake->error_code, 'mailbox_') || $intake->error_code === 'attachments_review') {
                    return null;
                }
                $supervisor = User::findOrFail($settings['supervisor_id'] ?? 0);
                OperationsAccess::authorize($supervisor, 'operations.inquiries.manage');
                $intake->update(['status' => 'analyzing', 'supervising_user_id' => $supervisor->id, 'error_code' => null]);
                $run = $this->run($intake, 'analysis', $settings);

                return $intake;
            });
            if (! $intake) {
                return;
            }
            $extracted = app(AiIntakeExtractionService::class)->extract($intake);
            $result = $this->validateExtraction($intake, $extracted['result']);
            OperationsTransaction::run(function () use ($intake, $run, $settings, $result, $extracted): void {
                $current = AiIntake::lockForUpdate()->findOrFail($intake->id);
                if ($current->source_revision !== $run->source_revision || $current->status === 'paused' || (int) AiDispositionSettings::all()['revision'] !== $run->settings_revision) {
                    $run->update(['status' => 'stale', 'result' => $result, 'error_code' => 'source_changed', 'finished_at' => now()->utc()]);

                    return;
                }
                $supervisor = User::findOrFail($run->supervising_user_id);
                OperationsAccess::authorize($supervisor, 'operations.inquiries.manage');
                abort_unless(AiDispositionSettings::enabled(), 409);
                $current->update(['analysis' => $result, 'title' => $result['title'], 'summary' => $result['summary'], 'confidence' => $result['confidence'], 'last_analyzed_at' => now()->utc(), 'processed_source_revision' => $current->source_revision, 'revision' => $current->revision + 1, 'status' => 'review', 'missing_fields' => []]);
                $missing = $current->customer_id ? [] : ['customer_id'];
                if ($result['intent'] !== 'inquiry' || ! $result['positions']) {
                    $current->update(['error_code' => $result['intent'] === 'inquiry' ? 'no_positions' : 'manual_intent']);
                } else {
                    foreach ($result['positions'] as $index => $position) {
                        $demand = array_intersect_key($position, array_flip(['title', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff']));
                        $demand['qualification_ids'] = [];
                        $absent = $this->missing($demand);
                        $missing = array_merge($missing, $absent);
                        $proposal = $current->proposals()->firstOrCreate(['source_revision' => $current->source_revision, 'position_index' => $index], ['revision' => 1, 'status' => 'proposed', 'payload' => ['demand' => $demand, 'segments' => $position['segments'], 'evidence' => $position['evidence'], 'missing_fields' => $absent]]);
                        if (($settings['automation_mode'] ?? 'automatic') === 'automatic') {
                            $this->createInquiry($current, $proposal, $this->actor($run));
                        }
                    }
                    $current->update(['inquiry_ids' => $current->proposals()->where('source_revision', $current->source_revision)->whereNotNull('inquiry_id')->pluck('inquiry_id')->all(), 'missing_fields' => array_values(array_unique($missing)), 'status' => $missing === [] ? 'ready' : 'review']);
                    if ($missing !== [] && $current->source_type === 'email' && ($settings['automation_mode'] ?? 'automatic') === 'automatic' && $current->question_round < min(2, (int) $settings['max_rounds'])) {
                        $questions = [];
                        foreach (array_unique($missing) as $field) {
                            if (isset(self::QUESTIONS[$field])) {
                                $questions[] = ['field' => $field, 'question' => self::QUESTIONS[$field]];
                            }
                        }
                        if ($questions !== []) {
                            $delivery = app(AiIntakeMailService::class)->enqueueClarification($current, $questions, $run);
                            if ($delivery) {
                                $current->refresh();
                                $current->update(['status' => 'waiting_customer']);
                            } else {
                                $current->update(['status' => 'review', 'error_code' => 'recipient_not_verified']);
                            }
                        }
                    }
                }
                $run->update(['status' => 'succeeded', 'result' => $result, 'model' => $extracted['model'] ?? null, 'provider_request_id' => $extracted['request_id'] ?? null, 'cost_usd' => $extracted['cost_usd'] ?? null, 'finished_at' => now()->utc()]);
            });
        } catch (Throwable $e) {
            $code = $e instanceof ValidationException ? 'validation_review' : 'processing_failed';
            if ($run && $run->fresh()?->status === 'running') {
                $run->update(['status' => 'failed', 'error_code' => $code, 'finished_at' => now()->utc()]);
                AiIntake::whereKey($intakeId)->where('source_revision', $run->source_revision)->where('status', '!=', 'paused')->update(['status' => 'review', 'error_code' => $code]);
            }
        } finally {
            $lock->release();
        }
    }

    public function assignCustomer(AiIntake $intake, User $actor, int $customerId, ?int $contactId = null, ?int $expectedRevision = null): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($intake, $actor, $customerId, $contactId, $expectedRevision): void {
            $this->access($actor);
            $intake = $this->current($intake, $expectedRevision);
            $customer = Customer::where('is_active', true)->lockForUpdate()->findOrFail($customerId);
            if ($contactId) {
                CustomerContact::where('customer_id', $customer->id)->where('is_active', true)->findOrFail($contactId);
            }
            foreach ($intake->proposals()->where('source_revision', $intake->source_revision)->with('inquiry')->get() as $proposal) {
                if ($proposal->inquiry) {
                    abort_if($proposal->inquiry->order_id, 409, 'Kundenzuordnung eines umgewandelten Vorgangs bleibt erhalten.');
                    $input = $this->inquiryInput($intake, $proposal->payload['demand']);
                    $input['customer_id'] = $customerId;
                    $inquiry = app(InquiryWorkflowService::class)->save($proposal->inquiry, $input, $actor, $proposal->inquiry->revision);
                    $proposal->update(['status' => 'proposed', 'revision' => $proposal->revision + 1, 'approved_at' => null, 'approved_by' => null, 'inquiry_revision' => $inquiry->revision, 'inquiry_fingerprint' => $this->fingerprint($inquiry)]);
                }
            }
            $intake->update(['customer_id' => $customerId, 'customer_contact_id' => $contactId, 'match_method' => 'manual', 'status' => 'review', 'revision' => $intake->revision + 1]);
        });
    }

    public function saveProposal(AiIntakeProposal $proposal, User $actor, array $payload, ?int $expectedRevision = null): AiIntakeProposal
    {
        $this->access($actor);
        $payload = $this->validateProposal($payload, false);

        return OperationsTransaction::run(function () use ($proposal, $actor, $payload, $expectedRevision) {
            $this->access($actor);
            $record = AiIntakeProposal::lockForUpdate()->findOrFail($proposal->id);
            $intake = AiIntake::lockForUpdate()->findOrFail($record->intake_id);
            $this->revision($record->revision, $expectedRevision ?? $proposal->revision);
            abort_if($record->status === 'applied' || $record->source_revision !== $intake->source_revision, 409, 'Vorschlag ist nicht mehr aktuell.');
            $inquiry = $record->inquiry_id ? OperationInquiry::lockForUpdate()->findOrFail($record->inquiry_id) : null;
            if ($inquiry) {
                abort_unless(in_array($inquiry->status, ['new', 'verified'], true), 409, 'Kommerzielle Vorgänge über die native Anfrage ändern.');
                $inquiry = app(InquiryWorkflowService::class)->save($inquiry, $this->inquiryInput($intake, $payload['demand']), $actor, $inquiry->revision);
                if ($intake->customer_id && $this->missing($payload['demand']) === []) {
                    $inquiry = app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'verify', [], $actor);
                }
            }
            $payload['missing_fields'] = $this->missing($payload['demand']);
            $record->update(['payload' => $payload, 'revision' => $record->revision + 1, 'status' => 'proposed', 'approved_by' => null, 'approved_at' => null, 'error_code' => null, 'inquiry_revision' => $inquiry?->revision, 'inquiry_fingerprint' => $inquiry ? $this->fingerprint($inquiry) : null]);
            $intake->update(['revision' => $intake->revision + 1]);

            return $record;
        });
    }

    public function approveProposal(AiIntakeProposal $proposal, User $actor, ?int $expectedRevision = null): void
    {
        $this->access($actor, 'operations.manage');
        OperationsTransaction::run(function () use ($proposal, $actor, $expectedRevision): void {
            $this->access($actor, 'operations.manage');
            $record = AiIntakeProposal::lockForUpdate()->findOrFail($proposal->id);
            $intake = AiIntake::lockForUpdate()->findOrFail($record->intake_id);
            $this->revision($record->revision, $expectedRevision ?? $proposal->revision);
            abort_unless($record->source_revision === $intake->source_revision && $record->status === 'proposed' && $record->inquiry_id, 409, 'Vorschlag benötigt einen aktuellen Anfragevorgang.');
            $payload = $this->validateProposal($record->payload, true);
            $inquiry = OperationInquiry::lockForUpdate()->findOrFail($record->inquiry_id);
            abort_unless(! $inquiry->order_id && ! $inquiry->duplicate_of_id && ! in_array($inquiry->status, ['rejected', 'duplicate'], true) && $inquiry->customer_id === $intake->customer_id && $inquiry->verified_revision === $inquiry->revision && $this->demandMatches($inquiry, $payload['demand']), 409, 'Anfragegrundlage muss zuerst aktuell geprüft werden.');
            $record->update(['status' => 'approved', 'revision' => $record->revision + 1, 'approved_by' => $actor->id, 'approved_at' => now()->utc(), 'inquiry_revision' => $inquiry->revision, 'inquiry_fingerprint' => $this->fingerprint($inquiry)]);
            $intake->update(['revision' => $intake->revision + 1]);
        });
    }

    public function createInquiries(AiIntake $intake, User $actor, int $expectedRevision): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($intake, $actor, $expectedRevision): void {
            $this->access($actor);
            $current = $this->current($intake, $expectedRevision);
            abort_unless(($current->analysis['intent'] ?? '') === 'inquiry', 422);
            foreach ($current->proposals()->where('source_revision', $current->source_revision)->get() as $proposal) {
                $this->createInquiry($current, $proposal, $actor);
            }
            $current->update(['inquiry_ids' => $current->proposals()->where('source_revision', $current->source_revision)->whereNotNull('inquiry_id')->pluck('inquiry_id')->all(), 'revision' => $current->revision + 1]);
        });
    }

    public function pause(AiIntake $intake, User $actor, ?int $expectedRevision = null): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($intake, $actor, $expectedRevision): void {
            $this->access($actor);
            $record = $this->current($intake, $expectedRevision);
            $record->update(['status' => 'paused', 'paused_at' => now()->utc(), 'revision' => $record->revision + 1]);
            $record->deliveries()->where('status', 'pending')->update(['status' => 'canceled', 'failure_code' => 'intake_paused']);
        });
    }

    public function reanalyze(AiIntake $intake, User $actor, ?int $expectedRevision = null): void
    {
        $this->access($actor);
        OperationsTransaction::run(function () use ($intake, $actor, $expectedRevision): void {
            $this->access($actor);
            $record = $this->current($intake, $expectedRevision);
            $record->proposals()->whereNotIn('status', ['applied', 'stale'])->update(['status' => 'stale', 'error_code' => 'reanalysis_requested']);
            $record->update(['status' => 'received', 'paused_at' => null, 'processed_source_revision' => null, 'source_revision' => $record->source_revision + 1, 'revision' => $record->revision + 1, 'error_code' => null]);
            if (AiDispositionSettings::enabled()) {
                ProcessAiIntake::dispatch($record->id)->afterCommit();
            }
        });
    }

    public function applyApprovedProposals(?int $intakeId = null): int
    {
        if (! AiIntakeSchema::ready() || ! AiDispositionSettings::enabled()) {
            return 0;
        }
        $count = 0;
        $query = AiIntakeProposal::where('status', 'approved')->whereNotNull('approved_at')->whereHas('inquiry', fn ($q) => $q->whereNotNull('order_id'))->when($intakeId, fn ($q) => $q->where('intake_id', $intakeId));
        foreach ($query->orderBy('id')->limit(50)->get() as $proposal) {
            $run = null;
            try {
                $intake = $proposal->intake;
                $settings = AiDispositionSettings::all();
                if (($settings['automation_mode'] ?? 'automatic') !== 'automatic' || $intake->status === 'paused') {
                    continue;
                }
                $run = $this->run($intake, 'apply', $settings, $proposal->id);
                OperationsTransaction::run(function () use ($proposal, $run): void {
                    $record = AiIntakeProposal::lockForUpdate()->findOrFail($proposal->id);
                    if ($record->status === 'applied') {
                        return;
                    }
                    $intake = AiIntake::lockForUpdate()->findOrFail($record->intake_id);
                    abort_unless($record->status === 'approved' && $record->source_revision === $intake->source_revision, 409);
                    $approver = User::findOrFail($record->approved_by);
                    OperationsAccess::authorize($approver, 'operations.manage');
                    $inquiry = OperationInquiry::lockForUpdate()->findOrFail($record->inquiry_id);
                    abort_unless($inquiry->order_id && $inquiry->status === 'converted' && $inquiry->revision === $record->inquiry_revision && hash_equals($record->inquiry_fingerprint, $this->fingerprint($inquiry)), 409, 'Anfragegrundlage wurde geändert.');
                    $conversion = OperationAudit::where('subject_type', 'OperationInquiry')->where('subject_id', $inquiry->id)->where('action', 'inquiry.convert')->latest('id')->first();
                    abort_unless($conversion && $conversion->actor_id && ($conversion->actor_kind ?? 'human') === 'human', 409, 'Persönliche Auftragsübernahme fehlt.');
                    $order = Order::lockForUpdate()->findOrFail($inquiry->order_id);
                    abort_unless($order->status->value === 'confirmed' && $order->customer_id === $intake->customer_id && $order->starts_at->eq($inquiry->starts_at) && $order->ends_at->eq($inquiry->ends_at) && $order->required_staff === $inquiry->required_staff && $order->service_type === $inquiry->role_name, 409, 'Auftrag wurde inzwischen verändert.');
                    $payload = $this->validateProposal($record->payload, true);
                    $actor = $this->actor($run);
                    $actor->authorize('demand.save', 'operations.manage');
                    $demand = app(OrderDemandService::class)->save($order->id, null, null, $payload['demand'], $actor);
                    $shiftIds = [];
                    foreach ($payload['segments'] as $index => $segment) {
                        [$start, $end] = OperationsDateTime::interval($segment['starts_at'], $segment['ends_at'], $segment['timezone']);
                        $shift = new Shift;
                        $shift->forceFill(['order_demand_id' => $demand->id]);
                        $shift = app(ShiftSchedulingService::class)->save($shift, ['order_id' => $order->id, 'title' => $payload['demand']['title'].' · '.($index + 1), 'role_name' => $demand->role_name, 'timezone' => $segment['timezone'], 'starts_at' => $start, 'ends_at' => $end, 'location_name' => $order->location_name, 'required_staff' => $segment['required_staff'], 'planned_break_minutes' => $segment['planned_break_minutes'], 'status' => 'draft'], $actor);
                        $shift->qualifications()->sync($payload['demand']['qualification_ids']);
                        $shiftIds[] = $shift->id;
                    }
                    $record->update(['status' => 'applied', 'demand_id' => $demand->id, 'applied_shift_ids' => $shiftIds, 'applied_at' => now()->utc(), 'error_code' => null, 'revision' => $record->revision + 1]);
                    $run->update(['status' => 'succeeded', 'result' => ['proposal_id' => $record->id, 'demand_id' => $demand->id, 'shift_ids' => $shiftIds], 'finished_at' => now()->utc()]);
                });
                $count++;
            } catch (Throwable $e) {
                if ($run) {
                    $run->update(['status' => 'failed', 'error_code' => 'proposal_application_review', 'finished_at' => now()->utc()]);
                }
                AiIntakeProposal::whereKey($proposal->id)->where('status', 'approved')->update(['status' => 'failed', 'error_code' => 'proposal_application_review']);
            }
        }

        return $count;
    }

    public function attachmentBytes(AiIntakeAttachment $attachment, User $actor): string
    {
        $this->access($actor);
        $attachment = AiIntakeAttachment::findOrFail($attachment->id);

        return app(AiIntakeAttachmentService::class)->bytes($attachment->intake, $attachment->file_path, $attachment->file_hash);
    }

    public function rawBytes(AiIntakeMessage $message, User $actor): string
    {
        $this->access($actor);
        $message = AiIntakeMessage::findOrFail($message->id);
        abort_unless($message->raw_path && $message->raw_hash, 404);

        return app(AiIntakeAttachmentService::class)->bytes($message->intake, $message->raw_path, $message->raw_hash);
    }

    private function createInquiry(AiIntake $intake, AiIntakeProposal $proposal, User|OperationsAutomationActor $actor): void
    {
        if ($proposal->inquiry_id) {
            return;
        }
        $existing = $intake->proposals()->where('position_index', $proposal->position_index)->whereNotNull('inquiry_id')->orderByDesc('source_revision')->first();
        $inquiry = $existing ? OperationInquiry::lockForUpdate()->findOrFail($existing->inquiry_id) : null;
        if ($inquiry && ! in_array($inquiry->status, ['new', 'verified'], true)) {
            $proposal->update(['status' => 'stale', 'error_code' => 'native_workflow_active']);

            return;
        }
        $demand = $proposal->payload['demand'];
        $input = $this->inquiryInput($intake, $demand);
        $input['source_reference'] = 'ai-intake:'.$intake->public_id.':'.$proposal->position_index;
        $inquiry = app(InquiryWorkflowService::class)->save($inquiry, $input, $actor, $inquiry?->revision);
        if ($intake->customer_id && $this->missing($demand) === []) {
            $inquiry = app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'verify', [], $actor);
        }
        $proposal->update(['inquiry_id' => $inquiry->id, 'inquiry_revision' => $inquiry->revision, 'inquiry_fingerprint' => $this->fingerprint($inquiry)]);
    }

    private function inquiryInput(AiIntake $intake, array $demand): array
    {
        return array_intersect_key($demand, array_flip(['title', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff'])) + ['channel' => $intake->source_type === 'email' ? 'email' : 'manual', 'customer_id' => $intake->customer_id, 'original' => 'AI-Eingang '.$intake->public_id.'. Originalnachrichten und Anlagen sind unverändert im privaten Eingang gespeichert.', 'contact_email' => $intake->contact?->email, 'contact_name' => $intake->contact?->name];
    }

    private function validateExtraction(AiIntake $intake, array $result): array
    {
        Validator::make($result, ['intent' => 'required|in:inquiry,amendment,cancel,other', 'title' => 'required|string|max:180', 'summary' => 'required|string|max:2000', 'confidence' => 'required|numeric|min:0|max:1', 'positions' => 'required|array|max:20', 'positions.*.title' => 'required|string|max:180', 'positions.*.timezone' => 'required|timezone', 'positions.*.starts_at' => 'nullable|string|max:40', 'positions.*.ends_at' => 'nullable|string|max:40', 'positions.*.location_name' => 'nullable|string|max:180', 'positions.*.role_name' => 'nullable|string|max:160', 'positions.*.required_staff' => 'nullable|integer|min:1|max:999', 'positions.*.segments' => 'present|array|max:50', 'positions.*.evidence' => 'present|array|max:100', 'questions' => 'present|array|max:10'])->validate();
        foreach ($result['positions'] as &$position) {
            $evidence = [];
            foreach ($position['evidence'] as $item) {
                if (! is_array($item) || ! in_array($item['field'] ?? '', [...self::FIELDS, 'segments', 'timezone', 'title'], true) || ! is_string($item['quote'] ?? null) || $item['quote'] === '' || mb_strlen($item['quote']) > 2000) {
                    continue;
                }
                $valid = false;
                if (! empty($item['message_id'])) {
                    $source = $intake->messages()->where('direction', 'inbound')->find($item['message_id']);
                    $valid = $source && str_contains($source->body, $item['quote']);
                }
                if (! $valid && ! empty($item['attachment_id'])) {
                    $file = $intake->attachments()->find($item['attachment_id']);
                    $valid = $file && (in_array($file->kind, ['image', 'pdf'], true) || str_contains((string) $file->extracted_text, $item['quote']));
                }
                if ($valid) {
                    $evidence[$item['field']] = array_intersect_key($item, array_flip(['message_id', 'attachment_id', 'quote']));
                }
            }
            foreach (self::FIELDS as $field) {
                if (filled($position[$field] ?? null) && ! isset($evidence[$field])) {
                    $position[$field] = null;
                }
            }
            if (! isset($evidence['segments'])) {
                $position['segments'] = [];
            }
            if (! empty($position['starts_at']) && ! empty($position['ends_at'])) {
                try {
                    OperationsDateTime::interval($position['starts_at'], $position['ends_at'], $position['timezone']);
                } catch (ValidationException) {
                    $position['starts_at'] = $position['ends_at'] = null;
                    $position['segments'] = [];
                }
            }
            $position['evidence'] = $evidence;
        }
        unset($position);

        return $result;
    }

    private function validateProposal(array $payload, bool $complete): array
    {
        $rules = ['demand' => 'required|array', 'demand.title' => 'required|string|max:180', 'demand.timezone' => 'required|timezone', 'demand.starts_at' => ($complete ? 'required' : 'nullable').'|string|max:40', 'demand.ends_at' => ($complete ? 'required' : 'nullable').'|string|max:40', 'demand.location_name' => ($complete ? 'required' : 'nullable').'|string|max:180', 'demand.role_name' => ($complete ? 'required' : 'nullable').'|string|max:160', 'demand.required_staff' => ($complete ? 'required' : 'nullable').'|integer|min:1|max:999', 'demand.qualification_ids' => 'present|array|max:50', 'demand.qualification_ids.*' => ['integer', 'distinct', Rule::exists('qualification_types', 'id')->where('is_active', true)], 'segments' => ($complete ? 'required' : 'present').'|array|max:50', 'segments.*.starts_at' => 'required|string|max:40', 'segments.*.ends_at' => 'required|string|max:40', 'segments.*.timezone' => 'required|timezone', 'segments.*.required_staff' => 'required|integer|min:1|max:999', 'segments.*.planned_break_minutes' => 'required|integer|min:0|max:1440'];
        Validator::make($payload, $rules)->validate();
        $payload['demand'] = array_intersect_key($payload['demand'], array_flip(['title', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff', 'qualification_ids']));
        if (filled($payload['demand']['required_staff'] ?? null)) {
            $payload['demand']['required_staff'] = (int) $payload['demand']['required_staff'];
        }
        $payload['demand']['qualification_ids'] = array_map('intval', $payload['demand']['qualification_ids']);
        foreach ($payload['segments'] as &$segment) {
            $segment['required_staff'] = (int) $segment['required_staff'];
            $segment['planned_break_minutes'] = (int) $segment['planned_break_minutes'];
        }
        unset($segment);
        if ($complete) {
            [$start, $end] = OperationsDateTime::interval($payload['demand']['starts_at'], $payload['demand']['ends_at'], $payload['demand']['timezone']);
            $profile = OperationsRuleProfile::where('is_active', true)->first();
            abort_unless($profile, 422, 'Aktives Regelprofil fehlt.');
            $cursor = $start;
            foreach ($payload['segments'] as &$segment) {
                $segment = array_intersect_key($segment, array_flip(['starts_at', 'ends_at', 'timezone', 'planned_break_minutes', 'required_staff']));
                [$from, $to] = OperationsDateTime::interval($segment['starts_at'], $segment['ends_at'], $segment['timezone']);
                $minutes = $from->diffInMinutes($to);
                abort_unless($from->isFuture() && $from->eq($cursor) && $to->lte($end) && $segment['required_staff'] === $payload['demand']['required_staff'] && $minutes <= $profile->maximum_shift_minutes && $segment['planned_break_minutes'] < $minutes && ($minutes <= $profile->break_after_minutes || $segment['planned_break_minutes'] >= $profile->minimum_break_minutes), 422, 'Schichtsegmente müssen den Bedarf lückenlos und regelkonform abdecken.');
                $cursor = $to;
            }
            unset($segment);
            abort_unless($cursor->eq($end), 422, 'Schichtsegmente decken den Bedarf nicht vollständig ab.');
        }

        return ['demand' => $payload['demand'], 'segments' => $payload['segments'], 'evidence' => is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [], 'missing_fields' => $this->missing($payload['demand'])];
    }

    private function missing(array $demand): array
    {
        return array_values(array_filter(self::FIELDS, fn ($field) => blank($demand[$field] ?? null)));
    }

    private function fingerprint(OperationInquiry $inquiry): string
    {
        return hash('sha256', json_encode([$inquiry->customer_id, $inquiry->title, $inquiry->starts_at?->toIso8601String(), $inquiry->ends_at?->toIso8601String(), $inquiry->timezone, $inquiry->role_name, $inquiry->location_name, $inquiry->required_staff], JSON_THROW_ON_ERROR));
    }

    private function demandMatches(OperationInquiry $inquiry, array $demand): bool
    {
        [$start, $end] = OperationsDateTime::interval($demand['starts_at'], $demand['ends_at'], $demand['timezone']);

        return $inquiry->starts_at?->eq($start) && $inquiry->ends_at?->eq($end) && $inquiry->title === $demand['title'] && $inquiry->timezone === $demand['timezone'] && $inquiry->role_name === $demand['role_name'] && $inquiry->location_name === $demand['location_name'] && $inquiry->required_staff === $demand['required_staff'];
    }

    private function run(AiIntake $intake, string $kind, array $settings, ?int $proposalId = null): AiIntakeRun
    {
        return $intake->runs()->create(['kind' => $kind, 'status' => 'running', 'source_revision' => $intake->source_revision, 'settings_revision' => $settings['revision'], 'supervising_user_id' => $settings['supervisor_id'], 'input_hash' => hash('sha256', json_encode([$intake->id, $intake->source_revision, $kind, $intake->proposals()->pluck('revision', 'id')->all()], JSON_THROW_ON_ERROR)), 'input_snapshot' => ['proposal_id' => $proposalId, 'message_ids' => $intake->messages()->pluck('id')->all(), 'attachment_hashes' => $intake->attachments()->pluck('file_hash', 'id')->all()], 'configuration' => ['settings_revision' => $settings['revision'], 'mode' => $settings['automation_mode'] ?? 'automatic'], 'started_at' => now()->utc()]);
    }

    private function actor(AiIntakeRun $run): OperationsAutomationActor
    {
        return new OperationsAutomationActor($run->id, $run->intake_id, $run->supervising_user_id, $run->source_revision, $run->settings_revision, $run->input_snapshot['proposal_id'] ?? null);
    }

    private function matchCustomer(AiIntake $intake, string $sender): void
    {
        if ($sender === '' || $intake->match_method === 'manual') {
            return;
        }
        $contacts = Schema::hasTable('customer_contacts') ? CustomerContact::where('is_active', true)->whereRaw('LOWER(TRIM(email)) = ?', [$sender])->whereHas('customer', fn ($q) => $q->where('is_active', true))->get() : collect();
        $customers = Customer::where('is_active', true)->whereRaw('LOWER(TRIM(email)) = ?', [$sender])->get();
        $customerIds = $contacts->pluck('customer_id')->merge($customers->pluck('id'))->unique();
        if ($customerIds->count() === 1 && $contacts->count() <= 1) {
            $intake->customer_id = $customerIds->first();
            $intake->customer_contact_id = $contacts->first()?->id;
            $intake->match_method = $contacts->isNotEmpty() ? 'contact_email' : 'customer_email';
        } else {
            $intake->customer_id = null;
            $intake->customer_contact_id = null;
            $intake->match_method = null;
        }
    }

    private function replyIntake(array $message, string $sender): ?AiIntake
    {
        $ids = array_filter(array_map(fn ($id) => $this->messageId($id), array_merge([(string) ($message['in_reply_to'] ?? '')], is_array($message['references'] ?? null) ? $message['references'] : preg_split('/\s+/', (string) ($message['references'] ?? '')))));
        if ($ids === [] || $sender === '') {
            return null;
        }
        $intakeIds = AiIntakeMessage::whereIn('external_message_id', $ids)->pluck('intake_id')->merge(\App\Models\AiIntakeDelivery::whereIn('message_id_header', $ids)->pluck('intake_id'))->unique();
        if ($intakeIds->count() !== 1) {
            return null;
        }
        $intake = AiIntake::find($intakeIds->first());
        $previous = $intake?->messages()->where('direction', 'inbound')->latest('id')->first();
        if (! $previous || $previous->sender_email !== $sender || (string) ($previous->metadata['mailbox_id'] ?? 'disposition') !== (string) ($message['mailbox_id'] ?? 'disposition')) {
            return null;
        }

        return $intake;
    }

    private function email(mixed $value): string
    {
        $value = is_array($value) ? ($value['address'] ?? $value['email'] ?? '') : $value;
        $value = strtolower(trim((string) $value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function messageId(mixed $value): ?string
    {
        $value = trim((string) $value, '<> ');

        return $value !== '' && strlen($value) <= 191 && ! preg_match('/[\r\n\x00]/', $value) ? $value : null;
    }

    private function access(User $actor, string $ability = 'operations.inquiries.manage'): void
    {
        AiIntakeSchema::requireReady();
        OperationsAccess::authorize(User::findOrFail($actor->id), $ability);
    }

    private function current(AiIntake $intake, ?int $revision): AiIntake
    {
        $record = AiIntake::lockForUpdate()->findOrFail($intake->id);
        $this->revision($record->revision, $revision ?? $intake->revision);

        return $record;
    }

    private function revision(int $actual, int $expected): void
    {
        abort_unless($actual === $expected, 409, 'Vorgang wurde geändert. Bitte neu laden.');
    }
}
