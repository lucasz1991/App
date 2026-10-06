<?php

namespace App\Livewire\Operations;

use App\Models\PlanningTeam;
use App\Models\PlanVariant;
use App\Models\QualificationBundle;
use App\Models\QualificationType;
use App\Models\RotationCycle;
use App\Models\Shift;
use App\Models\ShiftDependency;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Services\Operations\DeterministicPlanOptimizer;
use App\Services\Operations\PlanningCapacityService;
use App\Services\Operations\PlanningEnhancementService;
use App\Services\Operations\PlanVariantService;
use App\Services\Operations\RotationPlanningService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PlanningEnhancements extends Component
{
    #[Locked]
    public bool $personal = false;

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

    public function mount(bool $personal = false, string $tab = 'capacity'): void
    {
        $this->personal = $personal;
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

    public function addSlot(): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && count($this->form['slots']) < 50, 422);
        $this->form['slots'][] = ['day' => 0, 'shift_id' => '', 'team_id' => null, 'offset' => 0];
    }

    public function removeSlot(int $index): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && isset($this->form['slots'][$index]) && count($this->form['slots']) > 1, 422);
        array_splice($this->form['slots'], $index, 1);
        $this->preview = [];
    }

    public function addException(): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && count($this->form['exceptions']) < 366, 422);
        $this->form['exceptions'][] = ['date' => $this->from, 'user_id' => null];
    }

    public function removeException(int $index): void
    {
        $this->access();
        abort_unless($this->modal === 'rotation' && isset($this->form['exceptions'][$index]), 422);
        array_splice($this->form['exceptions'], $index, 1);
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
        session()->flash('operations-status', 'Gespeichert.');
    }

    public function approve(int $id, int $revision): void
    {
        $this->access();
        match ($this->tab) {
            'bundles' => app(PlanningEnhancementService::class)->approveBundle($id, $revision, auth()->user()),'positions' => app(PlanningEnhancementService::class)->approvePosition($id, $revision, auth()->user()),default => abort(422)
        };
        session()->flash('operations-status', 'Freigegeben.');
    }

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
        session()->flash('operations-status', $this->modal === 'bulk' ? 'Team angefragt.' : 'Planvariante erstellt.');
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

    public function render()
    {
        $this->access();
        $actor = auth()->user();
        $capacity = app(PlanningCapacityService::class);
        $matrix = $this->tab === 'capacity' ? $capacity->matrix($this->from, $this->until, array_map(fn ($v) => $v === '' ? null : $v, $this->filters), $actor) : ['duties' => [], 'demands' => [], 'weeks' => []];
        $rows = match ($this->tab) {
            'bundles' => QualificationBundle::orderBy('name')->orderByDesc('version')->limit(200)->get(),'teams' => PlanningTeam::orderBy('name')->limit(200)->get(),'rotations' => RotationCycle::orderBy('name')->limit(200)->get(),'chains' => ShiftDependency::orderByDesc('id')->limit(200)->get(),'positions' => collect($capacity->positions($this->from, $actor))->map(fn ($r) => (object) $r),'fairness' => collect($capacity->fairness($this->from, $this->until, ['night_start' => $this->goals['night_start'], 'night_end' => $this->goals['night_end'], 'unfavourable_roles' => $this->unfavourableRoles], $actor))->map(fn ($r) => (object) $r),default => collect($matrix['duties'])->map(fn ($r) => (object) $r)
        };

        return view('livewire.operations.planning-enhancements', ['items' => $rows, 'matrix' => $matrix, 'people' => User::where('role', 'staff')->where('status', true)->orderBy('name')->get(), 'types' => QualificationType::where('is_active', true)->orderBy('name')->get(), 'shifts' => Shift::notCancelled()->where('starts_at', '>=', now()->utc())->orderBy('starts_at')->limit(500)->get(), 'teams' => PlanningTeam::where('is_active', true)->orderBy('name')->get(), 'pools' => WorkforcePool::where('is_active', true)->orderBy('name')->get(), 'variants' => $this->tab === 'optimizer' ? PlanVariant::whereIn('status', ['draft', 'approved'])->orderByDesc('id')->limit(100)->get() : collect()]);
    }
}
