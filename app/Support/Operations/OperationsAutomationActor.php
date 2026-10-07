<?php

namespace App\Support\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeProposal;
use App\Models\AiIntakeRun;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Models\OrderDemand;
use App\Models\User;

/** A scoped machine principal, never a logged-in administrator or customer. */
final readonly class OperationsAutomationActor
{
    public function __construct(public int $runId, public int $intakeId, public int $supervisingUserId, public int $sourceRevision, public int $settingsRevision, public ?int $proposalId = null) {}

    public function authorize(string $operation, string $ability): User
    {
        AiIntakeSchema::requireReady();
        $run = AiIntakeRun::findOrFail($this->runId);
        $intake = AiIntake::findOrFail($this->intakeId);
        $settings = AiDispositionSettings::all();
        abort_unless(AiDispositionSettings::enabled() && ($settings['automation_mode'] ?? 'automatic') === 'automatic'
            && (int) ($settings['supervisor_id'] ?? 0) === $this->supervisingUserId && (int) ($settings['revision'] ?? 0) === $this->settingsRevision
            && $intake->supervising_user_id === $this->supervisingUserId && $intake->status !== 'paused' && $intake->source_revision === $this->sourceRevision
            && $run->status === 'running' && $run->intake_id === $this->intakeId && $run->supervising_user_id === $this->supervisingUserId
            && $run->source_revision === $this->sourceRevision && $run->settings_revision === $this->settingsRevision, 409, 'Automatisierung wurde geändert oder angehalten.');
        $allowed = $run->kind === 'analysis' ? ['inquiry.save', 'inquiry.verify', 'audit'] : ($run->kind === 'apply' ? ['demand.save', 'demand.generate', 'shift.save', 'audit'] : []);
        abort_unless(in_array($operation, $allowed, true), 403, 'Dieser Schritt benötigt eine persönliche Freigabe.');
        $supervisor = User::findOrFail($this->supervisingUserId);
        OperationsAccess::authorize($supervisor, $ability);

        return $supervisor;
    }

    public function references(): array
    {
        return ['actor_kind' => 'automation', 'automation_run_id' => $this->runId, 'supervising_user_id' => $this->supervisingUserId];
    }

    public function assertInquiryScope(?OperationInquiry $inquiry, array $input): void
    {
        $intake = AiIntake::findOrFail($this->intakeId);
        $reference = $inquiry?->source_reference ?? ($input['source_reference'] ?? '');
        $prefix = 'ai-intake:'.$intake->public_id.':';
        abort_unless(str_starts_with($reference, $prefix) && ctype_digit(substr($reference, strlen($prefix)))
            && (int) ($input['customer_id'] ?? $inquiry?->customer_id ?? 0) === (int) $intake->customer_id
            && ($inquiry?->channel ?? ($input['channel'] ?? null)) === ($intake->source_type === 'email' ? 'email' : 'manual')
            && (! $inquiry || in_array($inquiry->status, ['new', 'verified'], true)), 403, 'Anfrage gehört nicht zu diesem Automatisierungslauf.');
        abort_unless($intake->proposals()->where('source_revision', $this->sourceRevision)->where('position_index', (int) substr($reference, strlen($prefix)))->exists(), 403);
    }

    public function assertOrderScope(int $orderId): void
    {
        abort_unless($this->proposalId, 403);
        $proposal = AiIntakeProposal::where('intake_id', $this->intakeId)->where('source_revision', $this->sourceRevision)->findOrFail($this->proposalId);
        abort_unless($proposal->status === 'approved' && $proposal->approved_at && $proposal->inquiry?->order_id === $orderId && $proposal->inquiry->status === 'converted', 403, 'Auftrag gehört nicht zum freigegebenen Vorschlag.');
    }

    public function assertDemandScope(int $demandId): void
    {
        $demand = OrderDemand::findOrFail($demandId);
        $this->assertOrderScope((int) $demand->order_id);
        abort_unless(OperationAudit::where('subject_type', 'OrderDemand')->where('subject_id', $demandId)->where('action', 'demand.saved')->where('automation_run_id', $this->runId)->exists(), 403, 'Bedarf wurde nicht in diesem Lauf vorbereitet.');
    }
}
