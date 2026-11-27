<?php

namespace App\Livewire\Concerns\AiIntake;

use App\Services\Operations\AiIntakeManualService;
use App\Services\Operations\AiIntakeService;

/**
 * Positionen eines Eingangs per Formular erfassen und korrigieren.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait ManagesIntakePositions
{
    /** Von der Komponente bereitzustellen (App\Livewire\Operations\AiIntakeInbox). */
    abstract private function actor(): \App\Models\User;
    abstract private function selected(): \App\Models\AiIntake;
    abstract private function perform(callable $action, bool $reload = true): void;

    /** Reveal the next correction without resetting a draft or applying the source. */
    public function reviewNextPosition(): void
    {
        $this->perform(function (): void {
            $record = $this->selected()->load('proposals.inquiry');
            abort_unless($record->revision === $this->selectedRevision && $record->source_revision === $this->selectedSourceRevision,
                409, 'Der Eingang wurde inzwischen geändert. Bitte neu laden.');
            $this->detailTab = 'review';
            if ($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null) {
                return;
            }
            if (! $record->customer_id || $this->hasUnsavedAssignment($record)) {
                $this->customerEditorOpen = true;

                return;
            }
            $proposals = $record->proposals->where('source_revision', $record->source_revision)->where('status', '!=', 'stale');
            foreach ($proposals as $proposal) {
                if (! in_array($proposal->status, ['applied', 'stale'], true)
                    && (! $proposal->inquiry || in_array($proposal->inquiry->status, ['new', 'verified'], true))
                    && ($proposal->status === 'failed' || $this->proposalNeedsCorrection($proposal->payload ?? []))) {
                    $this->editProposal($proposal->id);

                    return;
                }
            }
            if ($proposals->isEmpty()) {
                $this->addManualPosition();
            }
        }, false);
    }

    public function editProposal(int $id): void
    {
        $proposal = $this->selected()->proposals()->findOrFail($id);
        abort_unless($proposal->source_revision === $this->selected()->source_revision && ! in_array($proposal->status, ['applied', 'stale'], true), 409, 'Die Zuordnung und den aktuellen Stand zuerst prüfen.');
        if (! $this->embedded) {
            $this->perform(function () use ($id): void {
                abort_if($this->manualEditorOpen || $this->editingProposalId !== null || $this->planningProposalId !== null || $this->customerEditorOpen
                    || $this->hasUnsavedAssignment($this->selected()), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
                $this->claimManual();
                $this->select($this->selectedId);
                $this->fillProposalForm($id);
                $this->drawerProposalId = $id;
            }, false);

            return;
        }
        $this->fillProposalForm($id);
    }

    private function fillProposalForm(int $id): void
    {
        $proposal = $this->selected()->proposals()->findOrFail($id);
        $this->editingProposalId = $proposal->id;
        $this->proposalRevision = $proposal->revision;
        $this->proposalForm = array_intersect_key($proposal->payload ?? [], array_flip(['demand', 'segments', 'business_path']));
        $this->proposalForm['business_path'] ??= 'inquiry';
        $this->proposalForm['requirements_reviewed'] = false;
        $this->resetValidation();
    }

    private function claimManual(): void
    {
        $current = $this->selected();
        abort_unless($current->source_revision === $this->selectedSourceRevision, 409, 'Die Quelle wurde inzwischen geändert. Bitte neu laden.');
        $record = app(AiIntakeManualService::class)->claimManual($current, $this->actor(), $this->selectedRevision);
        if ($record->revision !== $this->selectedRevision) {
            $this->confirmationProposalRevisions = $record->proposals()->where('source_revision', $record->source_revision)->pluck('revision', 'id')->all();
        }
        $this->selectedRevision = $record->revision;
    }

    public function addManualPosition(): void
    {
        $this->perform(function (): void {
            abort_if($this->editingProposalId || $this->manualEditorOpen || $this->planningProposalId !== null || $this->customerEditorOpen || $this->hasUnsavedAssignment($this->selected()), 409, 'Die offenen Angaben zuerst speichern.');
            $this->claimManual();
            $this->select($this->selectedId);
            $this->manualEditorOpen = true;
            $this->proposalForm = ['demand' => ['title' => '', 'starts_at' => '', 'ends_at' => '', 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'location_name' => '', 'role_name' => '', 'required_staff' => '', 'qualification_ids' => []], 'segments' => [], 'business_path' => 'inquiry', 'requirements_reviewed' => false];
        }, false);
    }

    public function cancelPositionEdit(): void
    {
        $this->selected();
        $this->editingProposalId = null;
        $this->manualEditorOpen = false;
        $this->proposalForm = [];
        $this->resetValidation();
    }

    public function saveManualPosition(): void
    {
        $this->perform(function (): void {
            abort_unless($this->manualEditorOpen || $this->editingProposalId, 409);
            $this->claimManual();
            $proposal = app(AiIntakeManualService::class)->savePosition($this->selected(), $this->actor(), $this->selectedRevision, $this->selectedSourceRevision,
                $this->proposalForm, $this->editingProposalId, $this->editingProposalId ? $this->proposalRevision : null);
            $this->drawerProposalId = $proposal->id;
        });
    }

    public function addSegment(): void
    {
        $this->selected();
        abort_unless(($this->editingProposalId || $this->manualEditorOpen) && count($this->proposalForm['segments'] ?? []) < 50, 422);
        $this->proposalForm['segments'][] = ['starts_at' => '', 'ends_at' => '', 'timezone' => $this->proposalForm['demand']['timezone'] ?? 'Europe/Berlin', 'planned_break_minutes' => 0, 'required_staff' => 1];
    }

    public function removeSegment(int $index): void
    {
        $this->selected();
        unset($this->proposalForm['segments'][$index]);
        $this->proposalForm['segments'] = array_values($this->proposalForm['segments'] ?? []);
    }

    public function saveProposal(): void
    {
        if (! $this->embedded) {
            $this->saveManualPosition();

            return;
        }
        $this->perform(function (): void {
            $proposal = $this->selected()->proposals()->findOrFail($this->editingProposalId);
            $payload = $proposal->payload;
            $payload['demand'] = $this->proposalForm['demand'] ?? [];
            $payload['segments'] = $this->proposalForm['segments'] ?? [];
            $payload['requirements_reviewed'] = (bool) ($this->proposalForm['requirements_reviewed'] ?? false);
            app(AiIntakeService::class)->saveProposal($proposal, $this->actor(), $payload, $this->proposalRevision);
            $this->editingProposalId = null;
        });
    }

    /** Presentation only. The workflow services still validate every write. */
    private function proposalNeedsCorrection(array $payload): bool
    {
        $demand = $payload['demand'] ?? [];
        foreach (['title', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff'] as $field) {
            if (! filled($demand[$field] ?? null)) {
                return true;
            }
        }

        return ! empty($payload['missing_fields']) || ! empty($payload['unmapped_requirements'])
            || (($payload['assumptions']['required_staff']['basis'] ?? '') === 'default'
                && empty($payload['review_confirmation']['actor_id']));
    }
}
