<?php

namespace App\Livewire\Operations;

use App\Livewire\Operations\Concerns\EditsRotationCycleForm;
use App\Livewire\Operations\Concerns\PreviewsPlanProposals;
use App\Models\PlanningTeam;
use App\Models\PlanVariant;
use App\Models\QualificationBundle;
use App\Models\QualificationType;
use App\Models\RotationCycle;
use App\Models\Shift;
use App\Models\ShiftDependency;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Services\Operations\PlanningCapacityService;
use App\Services\Operations\PlanningEnhancementService;
use App\Services\Operations\RotationPlanningService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Planungswerkzeuge der Disposition: Kapazität, Qualifikationsbündel, Verteilung,
 * Rotationen, Teams, Dienstketten, Stellenplanung und Planvorschlag.
 *
 * Alle Reiter gehören zum Fachbereich Planung und teilen Zeitraum, Ziele und den
 * Formulardialog; deshalb bleibt es eine Komponente. In Concerns liegen:
 *   - EditsRotationCycleForm (Zyklusdienste und Ausnahmen im Rotationsformular)
 *   - PreviewsPlanProposals  (Vorschau, Übernahme als Planvariante, Variantenfreigabe)
 */
class PlanningEnhancements extends Component
{
    use EditsRotationCycleForm, PreviewsPlanProposals;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public string $tab = 'capacity';

    #[Locked]
    public string $modal = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public ?int $shiftRevision = null;

    #[Locked]
    public array $preview = [];

    #[Locked]
    public array $chainRevisions = [];

    public bool $formOpen = false;

    public array $form = [];

    public string $from = '';

    public string $until = '';

    public array $filters = ['location' => '', 'role' => '', 'pool_id' => null];

    public array $unfavourableRoles = [];

    public array $goals = ['wish_weight' => 30, 'load_weight' => 1, 'night_weight' => 10, 'weekend_weight' => 10, 'night_start' => '22:00', 'night_end' => '06:00'];

    public function mount(bool $personal = false, string $tab = 'capacity', bool $embedded = false): void
    {
        $this->personal = $personal;
        $this->embedded = $embedded;
        $this->access();
        $this->from = now()->startOfWeek()->toDateString();
        $this->until = now()->startOfWeek()->addWeeks(4)->subDay()->toDateString();
        $this->setTab($tab);
    }

    private function access(): void
    {
        abort_if($this->personal, 403);
        abort_unless(auth()->user(), 403);
        app(PlanningEnhancementService::class)->access(auth()->user());
    }

    public function setTab(string $tab): void
    {
        $this->access();
        abort_unless(in_array($tab, ['bundles', 'capacity', 'fairness', 'rotations', 'teams', 'chains', 'positions', 'optimizer'], true), 422);
        $this->tab = $tab;
        $this->formOpen = false;
        $this->preview = [];
        $this->modal = '';
        $this->recordId = null;
        $this->revision = null;
        $this->resetValidation();
    }

    public function edit(string $modal, ?int $id = null): void
    {
        $this->access();
        abort_unless(in_array($modal, ['bundle', 'bundle-coverage', 'attach', 'team', 'bulk', 'rotation', 'rotation-preview', 'chain', 'position'], true), 422);
        $this->modal = $modal;
        $this->recordId = $id;
        $this->revision = null;
        $this->shiftRevision = null;
        $this->preview = [];
        $this->chainRevisions = [];
        $this->resetValidation();
        $this->form = match ($modal) {
            'bundle-coverage' => ['date' => $this->from],
            'bundle' => ['name' => '', 'role_name' => '', 'qualification_ids' => [], 'development_ids' => []],'attach' => ['shift_id' => '', 'scope' => 'Dienst'],'team' => ['name' => '', 'user_ids' => [], 'is_active' => true],'bulk' => ['team_id' => $id, 'shift_ids' => []],'rotation' => ['name' => '', 'anchor' => $this->from, 'cycle_days' => 7, 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'slots' => [['day' => 0, 'shift_id' => '', 'team_id' => null, 'offset' => 0]], 'exceptions' => []],'rotation-preview' => ['from' => $this->from, 'until' => $this->until],'chain' => ['predecessor_id' => '', 'successor_id' => '', 'transfer_minutes' => 0, 'train_code' => '', 'vehicle_code' => '', 'handover_location' => '', 'same_employee' => false],'position' => ['name' => '', 'role_name' => '', 'location_name' => '', 'from' => $this->from, 'until' => $this->until, 'target_fte' => 1, 'full_time_week_minutes' => null, 'qualification_ids' => []]
        };
        if ($id) {
            $record = match ($modal) {
                'bundle','bundle-coverage','attach' => QualificationBundle::findOrFail($id),'team','bulk' => PlanningTeam::findOrFail($id),'rotation','rotation-preview' => RotationCycle::findOrFail($id),default => abort(422)
            };
            $this->revision = $record->revision;
            if ($modal === 'bundle') {
                $this->form = ['name' => $record->name, 'role_name' => $record->role_name, 'qualification_ids' => collect($record->requirements)->where('mandatory', true)->pluck('id')->all(), 'development_ids' => collect($record->requirements)->where('mandatory', false)->pluck('id')->all()];
            }
            if ($modal === 'team') {
                $this->form = $record->only(['name', 'user_ids', 'is_active']);
            }
            if ($modal === 'rotation') {
                $this->form = $record->only(['name', 'anchor', 'cycle_days', 'timezone', 'slots', 'exceptions']);
            }
        }
        $this->formOpen = true;
    }

    public function updatedForm(mixed $value, string $key): void
    {
        $this->access();
        $this->preview = [];
        if ($this->modal === 'attach' && $key === 'shift_id') {
            $this->shiftRevision = Shift::findOrFail((int) $value)->revision;
        }
        if ($this->modal === 'chain' && in_array($key, ['predecessor_id', 'successor_id'], true)) {
            $this->chainRevisions[$key] = Shift::findOrFail((int) $value)->revision;
        }
    }

    public function save(): void
    {
        $this->access();
        $service = app(PlanningEnhancementService::class);
        $actor = auth()->user();
        match ($this->modal) {
            'bundle' => $service->createBundle($this->form, $actor, $this->recordId, $this->revision),
            'attach' => $service->attachBundle((int) $this->form['shift_id'], (int) $this->shiftRevision, (int) $this->recordId, (int) $this->revision, $this->form['scope'], $actor),
            'team' => $service->saveTeam($this->recordId, $this->revision, $this->form, $actor),
            'rotation' => app(RotationPlanningService::class)->save($this->recordId, $this->revision, $this->form, $actor),
            'chain' => $service->createDependency($this->form + ['predecessor_revision' => $this->chainRevisions['predecessor_id'] ?? 0, 'successor_revision' => $this->chainRevisions['successor_id'] ?? 0], $actor),
            'position' => $service->createPosition($this->form, $actor),default => abort(422)
        };
        $this->formOpen = false;
        $this->dispatch('operations-plan-changed');
        session()->flash('operations.saved', 'Gespeichert.');
    }

    public function approve(int $id, int $revision): void
    {
        $this->access();
        match ($this->tab) {
            'bundles' => app(PlanningEnhancementService::class)->approveBundle($id, $revision, auth()->user()),'positions' => app(PlanningEnhancementService::class)->approvePosition($id, $revision, auth()->user()),default => abort(422)
        };
        session()->flash('operations.saved', 'Freigegeben.');
    }

    public function render()
    {
        $this->access();
        $actor = auth()->user();
        $capacity = app(PlanningCapacityService::class);
        $matrix = $this->tab === 'capacity' ? $capacity->matrix($this->from, $this->until, array_map(fn ($v) => $v === '' ? null : $v, $this->filters), $actor) : ['duties' => [], 'demands' => [], 'weeks' => []];
        $rows = match ($this->tab) {
            'bundles' => QualificationBundle::orderBy('name')->orderByDesc('version')->limit(200)->get(),'teams' => PlanningTeam::orderBy('name')->limit(200)->get(),'rotations' => RotationCycle::orderBy('name')->limit(200)->get(),'chains' => ShiftDependency::orderByDesc('id')->limit(200)->get(),'positions' => collect($capacity->positions($this->from, $actor))->map(fn ($r) => (object) $r),'fairness' => collect($capacity->fairness($this->from, $this->until, ['night_start' => $this->goals['night_start'], 'night_end' => $this->goals['night_end'], 'unfavourable_roles' => $this->unfavourableRoles], $actor))->map(fn ($r) => (object) $r),default => collect($matrix['duties'])->map(fn ($r) => (object) $r)
        };

        return view('livewire.operations.planning-enhancements', ['items' => $rows, 'matrix' => $matrix, 'people' => User::dutyStaff()->orderBy('name')->get(), 'types' => QualificationType::where('is_active', true)->orderBy('name')->get(), 'shifts' => Shift::notCancelled()->where('starts_at', '>=', now()->utc())->orderBy('starts_at')->limit(500)->get(), 'teams' => PlanningTeam::where('is_active', true)->orderBy('name')->get(), 'pools' => WorkforcePool::where('is_active', true)->orderBy('name')->get(), 'variants' => $this->tab === 'optimizer' ? PlanVariant::whereIn('status', ['draft', 'approved'])->orderByDesc('id')->limit(100)->get() : collect()]);
    }
}
