<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeProposal;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Human source processing deliberately has no dependency on AI settings or providers. */
class AiIntakeManualService
{
    /** Attach advice to the same native record without queuing extraction or duplicating it. */
    public function forInquiryAssist(OperationInquiry $inquiry, User $actor, int $expectedRevision): AiIntake
    {
        $this->access($actor);

        return OperationsTransaction::run(function () use ($inquiry, $actor, $expectedRevision): AiIntake {
            $this->access($actor);
            $linked = AiIntakeProposal::where('inquiry_id', $inquiry->id)->latest('source_revision')->latest('id')->first();
            if ($linked) {
                $intake = AiIntake::lockForUpdate()->findOrFail($linked->intake_id);
                $record = OperationInquiry::lockForUpdate()->findOrFail($inquiry->id);
                abort_unless($record->revision === $expectedRevision && $linked->fresh()?->inquiry_id === $record->id, 409, 'Der Vorgang wurde geändert. Bitte neu laden.');

                return $intake;
            }
            $record = OperationInquiry::lockForUpdate()->findOrFail($inquiry->id);
            abort_unless($record->revision === $expectedRevision, 409, 'Der Vorgang wurde geändert. Bitte neu laden.');
            // A concurrent request may have attached the source while waiting for this lock.
            if ($linked = AiIntakeProposal::where('inquiry_id', $record->id)->latest('id')->first()) {
                return AiIntake::findOrFail($linked->intake_id);
            }
            $intake = AiIntake::create(['source_type' => 'manual', 'processing_mode' => 'manual', 'status' => 'review',
                'title' => $record->title, 'revision' => 1, 'source_revision' => 1, 'customer_id' => $record->customer_id,
                'created_by' => $actor->id, 'supervising_user_id' => $actor->id, 'inquiry_ids' => [$record->id]]);
            $message = $intake->messages()->create(['direction' => 'inbound', 'body' => $record->original,
                'sender_email' => $record->contact_email, 'metadata' => ['kind' => 'native_inquiry', 'inquiry_id' => $record->id,
                    'inquiry_revision' => $record->revision, 'submitted_by' => $actor->id, 'timezone' => $record->timezone]]);
            $intake->update(['latest_inbound_message_id' => $message->id]);
            $demand = $record->only(['title', 'timezone', 'location_name', 'role_name', 'required_staff']);
            $demand += ['starts_at' => $record->starts_at?->setTimezone($record->timezone)->format('Y-m-d\TH:i'),
                'ends_at' => $record->ends_at?->setTimezone($record->timezone)->format('Y-m-d\TH:i'), 'qualification_ids' => []];
            $proposal = $intake->proposals()->create(['source_revision' => 1, 'position_index' => 0, 'revision' => 1, 'status' => 'proposed',
                'inquiry_id' => $record->id, 'inquiry_revision' => $record->revision, 'inquiry_fingerprint' => $this->fingerprint($record),
                'payload' => ['demand' => $demand, 'segments' => [], 'business_path' => 'inquiry',
                    'provenance' => ['kind' => 'native_inquiry', 'actor_id' => $actor->id, 'inquiry_revision' => $record->revision],
                    'review_confirmation' => ['customer_communication' => 'off', 'planning_mode' => 'manual']]]);
            $conversion = OperationAudit::where('subject_type', 'OperationInquiry')->where('subject_id', $record->id)
                ->whereIn('action', ['inquiry.convert', 'inquiry.direct_order'])->latest('id')->first();
            app(OperationsAuditService::class)->record($intake, $actor, 'intake.native_assist_opened', [
                'inquiry_id' => $record->id, 'inquiry_revision' => $record->revision, 'inquiry_fingerprint' => $this->fingerprint($record),
                'source_revision' => $intake->source_revision, 'proposal_id' => $proposal->id, 'proposal_revision' => $proposal->revision,
                'order_id' => $record->order_id, 'conversion_audit_id' => $conversion?->id,
            ]);

            return $intake;
        });
    }

    public function claimManual(AiIntake $intake, User $actor, int $expectedRevision): AiIntake
    {
        $this->access($actor);

        return OperationsTransaction::run(function () use ($intake, $actor, $expectedRevision): AiIntake {
            $this->access($actor);
            $current = $this->current($intake, $expectedRevision);
            if ($current->processing_mode === 'manual') {
                return $current;
            }
            abort_if($current->deliveries()->whereIn('status', ['sending', 'unknown'])->exists(), 409, 'Den laufenden oder ungeklärten Versand zuerst persönlich prüfen.');
            $current->runs()->where('status', 'running')->update(['status' => 'stale', 'error_code' => 'manual_processing', 'finished_at' => now()->utc()]);
            $current->deliveries()->whereIn('status', ['draft', 'pending'])->update(['status' => 'canceled', 'failure_code' => 'manual_processing']);
            // Ownership is durable even when this source has no positions yet.
            $current->update(['processing_mode' => 'manual', 'revision' => $current->revision + 1,
                'status' => $current->status === 'paused' ? 'paused' : 'review', 'error_code' => null]);
            foreach ($current->proposals()->lockForUpdate()->get() as $proposal) {
                $payload = $proposal->payload ?? [];
                $payload['review_confirmation'] = array_replace((array) ($payload['review_confirmation'] ?? []), [
                    'customer_communication' => 'off', 'planning_mode' => 'manual',
                ]);
                $proposal->update(['payload' => $payload, 'revision' => $proposal->revision + 1,
                    'status' => $proposal->status === 'approved' ? 'proposed' : $proposal->status, 'approved_by' => null, 'approved_at' => null]);
            }
            app(OperationsAuditService::class)->record($current, $actor, 'intake.manual_claimed', ['source_revision' => $current->source_revision]);

            return $current;
        });
    }

    public function savePosition(AiIntake $intake, User $actor, int $expectedRevision, int $expectedSourceRevision, array $payload, ?int $proposalId = null, ?int $expectedProposalRevision = null): AiIntakeProposal
    {
        $this->access($actor);
        $businessPath = Validator::make($payload, ['business_path' => ['nullable', Rule::in(['inquiry', 'direct_order'])]])->validate()['business_path'] ?? 'inquiry';
        $payload = app(AiIntakeService::class)->validateManualPosition($payload);
        if (filled($payload['demand']['starts_at'] ?? null) && filled($payload['demand']['ends_at'] ?? null)) {
            OperationsDateTime::interval($payload['demand']['starts_at'], $payload['demand']['ends_at'], $payload['demand']['timezone']);
        }

        return OperationsTransaction::run(function () use ($intake, $actor, $expectedRevision, $expectedSourceRevision, $payload, $businessPath, $proposalId, $expectedProposalRevision): AiIntakeProposal {
            $this->access($actor);
            $current = $this->current($intake, $expectedRevision, $expectedSourceRevision);
            abort_unless($current->processing_mode === 'manual', 409, 'Den Eingang zuerst zur Formularbearbeitung übernehmen.');
            $record = $proposalId ? $current->proposals()->lockForUpdate()->findOrFail($proposalId) : new AiIntakeProposal;
            if ($record->exists) {
                abort_unless($record->revision === $expectedProposalRevision && $record->source_revision === $current->source_revision
                    && $record->status !== 'applied', 409, 'Diese Leistung wurde geändert. Bitte neu laden.');
                if ($record->inquiry_id) {
                    $inquiry = OperationInquiry::lockForUpdate()->findOrFail($record->inquiry_id);
                    abort_unless(in_array($inquiry->status, ['new', 'verified'], true), 409, 'Den bestehenden Auftrag oder Angebotsvorgang über den verknüpften Vorgang bearbeiten.');
                }
            } else {
                abort_unless($current->proposals()->where('source_revision', $current->source_revision)->count() < 20, 422, 'Höchstens 20 Leistungen pro Eingang erfassen.');
                $record->fill(['intake_id' => $current->id, 'source_revision' => $current->source_revision,
                    'position_index' => ((int) ($current->proposals()->where('source_revision', $current->source_revision)->max('position_index') ?? -1)) + 1]);
            }
            $payload['business_path'] = $businessPath;
            $payload['provenance'] = ['kind' => 'manual', 'actor_id' => $actor->id, 'source_revision' => $current->source_revision, 'at' => now()->utc()->toIso8601String()];
            $payload['assumptions'] = [];
            $payload['raw_requirements'] = $record->payload['raw_requirements'] ?? [];
            $payload['unmapped_requirements'] = ($record->payload['unmapped_requirements'] ?? []) && ! $payload['requirements_reviewed'] ? $record->payload['unmapped_requirements'] : [];
            $payload['mapping'] = $record->payload['mapping'] ?? null;
            $candidates = ! $record->inquiry_id ? $current->proposals()->where('source_revision', '<', $current->source_revision)->whereNotNull('inquiry_id')->pluck('inquiry_id')->unique()->values()->all() : [];
            if ($candidates !== []) {
                $payload['candidate_inquiry_ids'] = $candidates;
            }
            // Saving is not confirmation. Preserve only the safety policy, never old approval proof.
            $payload['review_confirmation'] = ['customer_communication' => 'off', 'planning_mode' => 'manual'];
            $record->fill(['payload' => $payload, 'revision' => $record->exists ? $record->revision + 1 : 1,
                'status' => 'proposed', 'error_code' => $candidates !== [] ? 'reply_position_review' : null, 'approved_by' => null, 'approved_at' => null])->save();
            $current->update(['revision' => $current->revision + 1, 'status' => 'review', 'missing_fields' => ['native_review']]);
            app(OperationsAuditService::class)->record($current, $actor, 'intake.manual_position_saved', ['source_revision' => $current->source_revision, 'proposal_id' => $record->id, 'proposal_revision' => $record->revision]);

            return $record;
        });
    }

    public function confirm(AiIntake $intake, User $actor, int $expectedRevision, int $expectedSourceRevision, array $expectedProposalRevisions): AiIntake
    {
        $this->access($actor);

        return OperationsTransaction::run(function () use ($intake, $actor, $expectedRevision, $expectedSourceRevision, $expectedProposalRevisions): AiIntake {
            $this->access($actor);
            $current = $this->current($intake, $expectedRevision, $expectedSourceRevision);
            abort_unless($current->processing_mode === 'manual', 409, 'Den Eingang zuerst zur Formularbearbeitung übernehmen.');
            $proposals = $current->proposals()->where('source_revision', $current->source_revision)->lockForUpdate()->get();
            abort_unless($proposals->isNotEmpty() && $proposals->pluck('revision', 'id')->all() == $expectedProposalRevisions, 409, 'Die Leistungen wurden geändert. Bitte neu laden.');
            abort_unless(Customer::active()->whereKey($current->customer_id)->lockForUpdate()->first(), 422, 'Bitte einen aktiven Kunden zuordnen.');
            $changed = false;
            foreach ($proposals as $proposal) {
                if ($proposal->status === 'applied') {
                    app(AiIntakeService::class)->assertManualOrderCurrent($proposal, $actor, $proposal->revision);

                    continue;
                }
                abort_if(in_array($proposal->status, ['stale', 'failed'], true), 409, 'Die aktuelle Quelle und die Leistung zuerst persönlich prüfen.');
                $payload = $proposal->payload;
                $validated = app(AiIntakeService::class)->validateManualPosition($payload);
                abort_unless($validated['missing_fields'] === [] && empty($payload['unmapped_requirements']), 422, 'Beginn, Ende, Ort, Tätigkeit, Personalbedarf und Qualifikationen vollständig prüfen.');
                OperationsDateTime::interval($validated['demand']['starts_at'], $validated['demand']['ends_at'], $validated['demand']['timezone']);
                $inquiry = $proposal->inquiry_id ? OperationInquiry::lockForUpdate()->findOrFail($proposal->inquiry_id) : null;
                $proof = $payload['review_confirmation'] ?? [];
                if ($inquiry && ($proof['source_revision'] ?? null) === $current->source_revision && ! empty($proof['actor_id'])
                    && $inquiry->verified_revision === $inquiry->revision
                    && $proposal->inquiry_revision === $inquiry->revision && $proposal->inquiry_fingerprint === $this->fingerprint($inquiry)) {
                    continue;
                }
                abort_if($inquiry && ! in_array($inquiry->status, ['new', 'verified'], true), 409, 'Den bestehenden Angebots- oder Auftragsvorgang persönlich prüfen.');
                if (! $inquiry) {
                    abort_if($current->proposals()->where('source_revision', '<', $current->source_revision)->whereNotNull('inquiry_id')->exists(), 409, 'Die Antwort zuerst ausdrücklich einer bestehenden Anfrage zuordnen.');
                } else {
                    abort_unless($proposal->inquiry_revision === $inquiry->revision && $proposal->inquiry_fingerprint === $this->fingerprint($inquiry), 409, 'Die native Anfrage wurde geändert. Bitte neu laden und prüfen.');
                }
                $demand = $validated['demand'];
                $input = array_intersect_key($demand, array_flip(['title', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff'])) + [
                    'channel' => $current->source_type === 'email' ? 'email' : 'manual', 'customer_id' => $current->customer_id,
                    'source_reference' => 'ai-intake:'.$current->public_id.':'.$proposal->position_index,
                    'original' => 'Eingang '.$current->public_id.'. Originalnachrichten und Anlagen sind unverändert im privaten Eingang gespeichert.',
                    'contact_email' => $current->contact?->email, 'contact_name' => $current->contact?->name,
                ];
                $inquiry = app(InquiryWorkflowService::class)->save($inquiry, $input, $actor, $inquiry?->revision);
                $inquiry = app(InquiryWorkflowService::class)->transition($inquiry, $inquiry->revision, 'verify', [], $actor);
                $payload['review_confirmation'] = ['actor_id' => $actor->id, 'source_revision' => $current->source_revision, 'intake_revision' => $expectedRevision,
                    'at' => now()->utc()->toIso8601String(), 'customer_communication' => 'off', 'planning_mode' => 'manual', 'method' => 'form'];
                $proposal->update(['payload' => $payload, 'revision' => $proposal->revision + 1, 'inquiry_id' => $inquiry->id,
                    'inquiry_revision' => $inquiry->revision, 'inquiry_fingerprint' => $this->fingerprint($inquiry)]);
                $changed = true;
            }
            if ($changed) {
                $current->update(['revision' => $current->revision + 1, 'status' => 'ready', 'missing_fields' => [], 'error_code' => null,
                    'inquiry_ids' => $proposals->pluck('inquiry_id')->unique()->values()->all()]);
                app(OperationsAuditService::class)->record($current, $actor, 'intake.manual_confirmed', ['source_revision' => $current->source_revision, 'inquiry_ids' => $current->inquiry_ids]);
            }

            return $current;
        });
    }

    private function access(User $actor): void
    {
        AiIntakeSchema::requireManualReady();
        OperationsAccess::authorize(User::findOrFail($actor->id), 'operations.inquiries.manage');
    }

    private function current(AiIntake $intake, int $expectedRevision, ?int $expectedSourceRevision = null): AiIntake
    {
        $current = AiIntake::lockForUpdate()->findOrFail($intake->id);
        abort_unless($current->revision === $expectedRevision && ($expectedSourceRevision === null || $current->source_revision === $expectedSourceRevision), 409, 'Der Eingang oder die Mailquelle wurde geändert. Bitte neu laden.');
        abort_if(in_array($current->status, ['not_inquiry', 'completed', 'paused'], true) || $current->paused_at, 409, 'Diesen Eingang zuerst wieder öffnen.');

        return $current;
    }

    private function fingerprint(OperationInquiry $inquiry): string
    {
        return hash('sha256', json_encode([$inquiry->customer_id, $inquiry->title, $inquiry->starts_at?->toIso8601String(), $inquiry->ends_at?->toIso8601String(), $inquiry->timezone, $inquiry->role_name, $inquiry->location_name, $inquiry->required_staff], JSON_THROW_ON_ERROR));
    }
}
