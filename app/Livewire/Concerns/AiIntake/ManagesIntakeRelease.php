<?php

namespace App\Livewire\Concerns\AiIntake;

use App\Services\Operations\AiIntakeManualService;
use App\Services\Operations\AiIntakeService;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\OperationsTransaction;

/**
 * Prüfung, Freigabe und Übernahme: Anfrage, Direktauftrag, Zuordnung und erneute Prüfung.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait ManagesIntakeRelease
{
    /** Von der Komponente bereitzustellen (App\Livewire\Operations\AiIntakeInbox). */
    abstract private function actor(): \App\Models\User;
    abstract private function selected(): \App\Models\AiIntake;
    abstract private function perform(callable $action, bool $reload = true): void;

    /** Explicitly accept only the displayed position; other positions keep their own path. */
    public function confirmDrawerPosition(string $businessPath): void
    {
        abort_unless(in_array($businessPath, ['inquiry', 'direct_order'], true), 422);
        $actor = $this->actor();
        if ($businessPath === 'direct_order') {
            OperationsAccess::authorize($actor, 'operations.manage');
        }
        $this->perform(function () use ($businessPath, $actor): void {
            OperationsTransaction::run(function () use ($businessPath, $actor): void {
                $record = $this->selected();
                $this->assertDrawerReady($record);
                $proposals = $record->proposals()->where('source_revision', $record->source_revision)->get();
                abort_unless($proposals->pluck('revision', 'id')->all() == $this->confirmationProposalRevisions, 409, 'Die Leistungen wurden geändert. Bitte neu laden.');
                $proposal = $proposals->firstWhere('id', $this->drawerProposalId);
                abort_unless($proposal && ! in_array($proposal->status, ['applied', 'stale', 'failed'], true), 409, 'Die aktuelle Leistung zuerst prüfen.');
                abort_if($this->proposalNeedsCorrection($proposal->payload ?? []), 422, 'Die Angaben dieser Leistung zuerst ergänzen.');
                abort_if($proposal->inquiry && ! in_array($proposal->inquiry->status, ['new', 'verified'], true), 409, 'Den bestehenden Angebots- oder Auftragsvorgang öffnen.');
                $claimed = app(AiIntakeManualService::class)->claimManual($record, $actor, $this->selectedRevision);
                $proposal = $proposal->fresh();
                if (($proposal->payload['business_path'] ?? 'inquiry') !== $businessPath) {
                    $payload = array_intersect_key($proposal->payload, array_flip(['demand', 'segments']));
                    $payload['business_path'] = $businessPath;
                    app(AiIntakeManualService::class)->savePosition($claimed, $actor, $claimed->revision,
                        $this->selectedSourceRevision, $payload, $proposal->id, $proposal->revision);
                    $claimed = $claimed->fresh();
                }
                $expectedRevisions = $claimed->proposals()->where('source_revision', $claimed->source_revision)->pluck('revision', 'id')->all();
                $confirmed = app(AiIntakeManualService::class)->confirm($claimed, $actor, $claimed->revision,
                    $this->selectedSourceRevision, $expectedRevisions, $proposal->id);
                if ($businessPath === 'direct_order') {
                    $inquiry = $confirmed->proposals()->findOrFail($proposal->id)->inquiry;
                    app(InquiryWorkflowService::class)->takeDirectOrder($inquiry, $inquiry->revision, $actor);
                }
            });
        });
    }

    public function reanalyze(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            if (! $this->embedded) {
                $this->assertDrawerReady($intake);
            }
            if ($intake->source_type === 'email') {
                app(AiIntakeService::class)->analyzeManually($intake, $this->actor(), $this->selectedRevision, $this->selectedSettingsRevision);
            } else {
                app(AiIntakeService::class)->reanalyze($intake, $this->actor(), $this->selectedRevision);
            }
        });
    }

    public function pause(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            if (! $this->embedded) {
                $this->assertDrawerReady($intake);
            }
            app(AiIntakeService::class)->pause($intake, $this->actor(), $this->selectedRevision);
        });
    }

    public function createInquiries(): void
    {
        $this->perform(fn () => app(AiIntakeService::class)->createInquiries($this->selected(), $this->actor(), $this->selectedRevision));
    }

    public function confirmReview(): void
    {
        $this->perform(function (): void {
            abort_if($this->editingProposalId !== null, 409, 'Die offene Korrektur zuerst speichern.');
            $intake = $this->selected();
            abort_if($this->hasUnsavedAssignment($intake), 409, 'Die geänderte Kunden- oder Kontaktzuordnung zuerst speichern.');
            app(AiIntakeService::class)->confirmReview($intake, $this->actor(), $this->selectedRevision,
                $this->selectedSourceRevision, $this->selectedSettingsRevision, $this->confirmationProposalRevisions);
        });
    }

    public function confirmManual(): void
    {
        $this->perform(function (): void {
            $intake = $this->selected();
            abort_if($this->editingProposalId || $this->manualEditorOpen || $this->hasUnsavedAssignment($intake), 409, 'Die offenen Angaben zuerst speichern.');
            $this->claimManual();
            $record = app(AiIntakeManualService::class)->confirm($this->selected(), $this->actor(), $this->selectedRevision, $this->selectedSourceRevision, $this->confirmationProposalRevisions);
            // Confirmation is durable even if a later direct acceptance needs a correction.
            $this->select($record->id);
            foreach ($record->proposals()->where('source_revision', $record->source_revision)->where('status', '!=', 'stale')->with('inquiry')->get() as $proposal) {
                if (($proposal->payload['business_path'] ?? 'inquiry') === 'direct_order' && $proposal->inquiry) {
                    app(InquiryWorkflowService::class)->takeDirectOrder($proposal->inquiry, $proposal->inquiry->revision, $this->actor());
                    $this->select($record->id);
                }
            }
        });
    }

    public function takeDirectOrder(int $id, int $revision, int $inquiryRevision): void
    {
        $this->perform(function () use ($id, $revision, $inquiryRevision): void {
            $intake = $this->selected();
            if (! $this->embedded) {
                $this->assertDrawerReady($intake);
            }
            abort_unless($intake->revision === $this->selectedRevision && $intake->source_revision === $this->selectedSourceRevision, 409, 'Der Eingang wurde inzwischen geändert.');
            $proposal = $intake->proposals()->with('inquiry')->findOrFail($id);
            abort_unless($proposal->revision === $revision && $proposal->source_revision === $intake->source_revision && $proposal->inquiry, 409, 'Die Position wurde inzwischen geändert.');
            app(InquiryWorkflowService::class)->takeDirectOrder($proposal->inquiry, $inquiryRevision, $this->actor());
        });
    }

    public function approveProposal(int $id, int $revision): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->perform(fn () => app(AiIntakeService::class)->approveProposal($this->selected()->proposals()->findOrFail($id), $this->actor(), $revision));
    }

    public function continueInquiry(int $id, int $revision): void
    {
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            $proposal = $this->selected()->proposals()->findOrFail($id);
            $inquiryId = app(AiIntakeService::class)->continueProposalManually($proposal, $this->actor(), $revision);
            $this->redirect(OperationsPages::url('cases', ['view' => 'inbox', 'inquiry' => $inquiryId]), navigate: true);
        }, false);
    }

    public function retryPreparation(int $id, int $revision): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            app(AiIntakeService::class)->retryApprovedProposal($this->selected()->proposals()->findOrFail($id), $this->actor(), $revision);
        });
    }

    public function mapProposal(int $id, int $revision): void
    {
        $this->validate(['mappingInquiryIds.'.$id => ['required', 'integer', 'min:1']]);
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            app(AiIntakeService::class)->mapProposalInquiry($this->selected()->proposals()->findOrFail($id), $this->actor(), (int) $this->mappingInquiryIds[$id], $revision);
        });
    }
}
