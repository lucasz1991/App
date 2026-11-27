<?php

namespace App\Livewire\Concerns\AiIntake;

use App\Services\Operations\AiIntakeService;
use App\Support\Operations\OperationsAccess;

/**
 * Schichtentwürfe aus einer übernommenen Position vorbereiten und freigeben.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait ManagesIntakePlanning
{
    /** Von der Komponente bereitzustellen (App\Livewire\Operations\AiIntakeInbox). */
    abstract private function actor(): \App\Models\User;
    abstract private function selected(): \App\Models\AiIntake;
    abstract private function perform(callable $action, bool $reload = true): void;

    public function openPlanning(int $id, int $revision): void
    {
        $this->perform(function () use ($id, $revision): void {
            OperationsAccess::authorize($this->actor(), 'operations.manage');
            $proposal = $this->selected()->proposals()->with('inquiry')->findOrFail($id);
            abort_unless($proposal->revision === $revision && $proposal->source_revision === $this->selectedSourceRevision
                && ! in_array($proposal->status, ['applied', 'stale'], true)
                && $proposal->inquiry?->status === 'converted' && $proposal->inquiry->order_id, 409, 'Zuerst den aktuellen Auftrag in der Anfrage bestätigen.');
            abort_if($this->editingProposalId !== null || $this->manualEditorOpen || $this->planningProposalId !== null || $this->customerEditorOpen
                || $this->hasUnsavedAssignment($this->selected()), 409, 'Die offenen Angaben zuerst speichern oder verwerfen.');
            $this->planningProposalId = $id;
            if (! $this->embedded) {
                $this->drawerProposalId = $id;
            }
            $this->planningProposalRevision = $revision;
            $inquiry = $proposal->inquiry;
            $this->planningSegments = $proposal->payload['segments'] ?? [];
            if ($this->planningSegments === []) {
                $this->planningSegments[] = ['starts_at' => $inquiry->starts_at->setTimezone($inquiry->timezone)->format('Y-m-d\TH:i'),
                    'ends_at' => $inquiry->ends_at->setTimezone($inquiry->timezone)->format('Y-m-d\TH:i'),
                    'timezone' => $inquiry->timezone, 'required_staff' => $inquiry->required_staff, 'planned_break_minutes' => ''];
            }
        }, false);
    }

    public function closePlanning(): void
    {
        $this->actor();
        $this->reset('planningProposalId', 'planningProposalRevision', 'planningSegments');
        $this->resetValidation();
    }

    public function addPlanningSegment(): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $proposal = $this->selected()->proposals()->with('inquiry')->findOrFail($this->planningProposalId);
        abort_unless($proposal->inquiry && count($this->planningSegments) < 50, 422);
        $this->planningSegments[] = ['starts_at' => '', 'ends_at' => '', 'timezone' => $proposal->inquiry->timezone,
            'required_staff' => $proposal->inquiry->required_staff, 'planned_break_minutes' => ''];
    }

    public function removePlanningSegment(int $index): void
    {
        OperationsAccess::authorize($this->actor(), 'operations.manage');
        $this->selected()->proposals()->findOrFail($this->planningProposalId);
        unset($this->planningSegments[$index]);
        $this->planningSegments = array_values($this->planningSegments);
    }

    public function preparePlanning(): void
    {
        $this->perform(function (): void {
            $proposal = $this->selected()->proposals()->findOrFail($this->planningProposalId);
            $ids = app(AiIntakeService::class)->prepareProposalShifts($proposal, $this->actor(), $this->planningProposalRevision, $this->planningSegments);
            $this->dispatch('operations-plan-changed');
            $this->dispatch('swal:toast', type: 'success', text: count($ids).' Schichtentwürfe vorbereitet. Einsatzort prüfen und zur Besetzung freigeben.');
        });
    }

    public function publishPlanning(int $id, int $revision): void
    {
        $this->perform(function () use ($id, $revision): void {
            if (! $this->embedded) {
                $this->assertDrawerReady($this->selected());
            }
            $proposal = $this->selected()->proposals()->findOrFail($id);
            app(AiIntakeService::class)->publishProposalShifts($proposal, $this->actor(), $revision, $this->planningShiftRevisions[$id] ?? []);
            $this->dispatch('operations-plan-changed');
            $this->dispatch('swal:toast', type: 'success', text: 'Schichten sind zur Besetzung freigegeben. Mitarbeiter im Schichtplan zuordnen.');
        });
    }
}
