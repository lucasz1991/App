<?php

namespace App\Livewire\Operations\Concerns;

use App\Models\PlanVariant;
use App\Models\PlanningTeam;
use App\Models\Shift;
use App\Services\Operations\DeterministicPlanOptimizer;
use App\Services\Operations\PlanVariantService;
use App\Services\Operations\PlanningEnhancementService;
use App\Services\Operations\RotationPlanningService;
use Illuminate\Validation\ValidationException;

/**
 * PlanningEnhancements – Vorschauen und Planvarianten: Bündelabdeckung,
 * Team-Einteilung, Rotationsvorschau und Planvorschlag berechnen, als
 * Planvariante übernehmen, Varianten prüfen, freigeben und anwenden.
 */
trait PreviewsPlanProposals
{
    public function prepare(): void
    {
        $this->access();
        $actor = auth()->user();
        if ($this->modal === 'bundle-coverage') {
            $this->preview = ['rows' => app(PlanningEnhancementService::class)->bundleCoverage((int) $this->recordId, $this->form['date'], $actor)];
        } elseif ($this->modal === 'bulk') {
            $team = PlanningTeam::findOrFail((int) $this->form['team_id']);
            abort_unless($team->is_active, 422);
            $ids = array_map('intval', $this->form['shift_ids']);
            $entries = Shift::whereKey($ids)->orderBy('starts_at')->get()->map(fn ($shift) => ['shift_id' => $shift->id, 'revision' => $shift->revision, 'user_ids' => $team->user_ids])->all();
            abort_unless(count($entries) === count(array_unique($ids)), 422);
            $this->preview = app(PlanningEnhancementService::class)->bulkPreview($entries, $actor) + ['entries' => $entries, 'team_id' => $team->id, 'team_revision' => $team->revision];
        } elseif ($this->modal === 'rotation-preview') {
            $this->preview = app(RotationPlanningService::class)->preview((int) $this->recordId, (int) $this->revision, $this->form['from'], $this->form['until'], $actor);
        } elseif ($this->tab === 'optimizer') {
            $this->preview = app(DeterministicPlanOptimizer::class)->preview($this->from, $this->until, $this->goals, $actor);
        } else {
            abort(422);
        }
    }

    public function applyPreview(): void
    {
        $this->access();
        abort_unless(isset($this->preview['fingerprint']), 422);
        $actor = auth()->user();
        if ($this->modal === 'bulk' && $this->formOpen) {
            $team = PlanningTeam::findOrFail($this->preview['team_id']);
            if ($team->revision !== $this->preview['team_revision'] || ! $team->is_active) {
                throw ValidationException::withMessages(['workflow' => 'Team wurde geändert.']);
            }
            app(PlanningEnhancementService::class)->bulkAssign($this->preview['entries'], $this->preview['fingerprint'], $actor);
        } elseif ($this->modal === 'rotation-preview' && $this->formOpen) {
            app(RotationPlanningService::class)->createVariant((int) $this->recordId, (int) $this->revision, $this->form['from'], $this->form['until'], $this->preview['fingerprint'], $actor);
        } elseif ($this->tab === 'optimizer') {
            app(DeterministicPlanOptimizer::class)->createVariant($this->from, $this->until, $this->goals, $this->preview['fingerprint'], $actor);
        } else {
            abort(422);
        }
        $this->formOpen = false;
        $this->preview = [];
        $this->dispatch('operations-plan-changed');
        session()->flash('operations.saved', $this->modal === 'bulk' ? 'Team angefragt.' : 'Planvariante erstellt.');
    }

    public function inspectVariant(int $id): void
    {
        $this->access();
        $variant = PlanVariant::findOrFail($id);
        $this->modal = 'variant';
        $this->recordId = $id;
        $this->revision = $variant->revision;
        $this->preview = app(PlanVariantService::class)->preview($variant, auth()->user());
        $this->formOpen = true;
    }

    public function approveVariant(): void
    {
        $this->access();
        abort_unless($this->modal === 'variant' && isset($this->preview['fingerprint']), 422);
        app(PlanVariantService::class)->approve((int) $this->recordId, (int) $this->revision, $this->preview['fingerprint'], auth()->user());
        $this->formOpen = false;
    }

    public function applyVariant(int $id, int $revision): void
    {
        $this->access();
        app(PlanVariantService::class)->apply($id, $revision, auth()->user());
        $this->dispatch('operations-plan-changed');
    }
}
