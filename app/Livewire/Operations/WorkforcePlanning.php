<?php

namespace App\Livewire\Operations;

use App\Models\AvailabilityPeriod;
use App\Models\EmployeeAvailability;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftOffer;
use App\Models\ShiftTransferRequest;
use App\Models\StaffingCase;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\WorkforcePlanningService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\WorkforcePlanningSchema;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class WorkforcePlanning extends Component
{
    use WithPagination;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public string $tab = 'wishes';

    #[Locked]
    public string $modal = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public ?int $planRevision = null;

    public bool $formOpen = false;

    public array $form = [];

    #[Locked]
    public bool $embedded = false;

    public function mount(bool $personal = false, string $tab = '', bool $embedded = false, array $context = []): void
    {
        $this->personal = $personal;
        $this->access();
        $this->embedded = $embedded;
        if ($tab !== '') {
            $this->setTab($tab);
        }
        if (isset($context['record'])) {
            $type = $context['record_type'] ?? '';
            abort_unless($this->tab === 'cases' && $type === 'staffing-case' || $this->tab === 'periods' && $type === 'availability-period', 404);
            $record = ($type === 'staffing-case' ? StaffingCase::query() : AvailabilityPeriod::query())->findOrFail((int) $context['record']);
            abort_if(isset($context['revision']) && (int) $record->revision !== (int) $context['revision'], 409, 'Der Vorgang wurde geändert.');
            abort_if(isset($context['shift']) && (int) ($record->shift_id ?? 0) !== (int) $context['shift'], 404);
            $this->edit($type === 'staffing-case' ? 'case' : 'period', $record->id);
        }
    }

    private function access(): void
    {
        $actor = auth()->user();
        abort_unless($actor, 403);
        if ($this->personal) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            OperationsAccess::authorize($actor, 'operations.manage');
        }
        WorkforcePlanningSchema::requireReady();
    }

    public function setTab(string $tab): void
    {
        $this->access();
        abort_unless(in_array($tab, $this->personal ? ['wishes', 'offers', 'transfers'] : ['pools', 'wishes', 'periods', 'offers', 'transfers', 'cases'], true), 422);
        $this->tab = $tab;
        $this->resetPage();
    }

    public function edit(string $kind, ?int $id = null): void
    {
        $this->access();
        abort_unless(in_array($kind, $this->personal ? ['wish', 'transfer'] : ['pool', 'period', 'offer', 'case', 'review-transfer'], true), 403);
        $this->modal = $kind;
        $this->recordId = $id;
        $this->revision = null;
        $this->planRevision = null;
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $this->form = match ($kind) {
            'wish' => ['kind' => 'available', 'from' => now($zone)->toDateString(), 'until' => now($zone)->toDateString(), 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'whole_day' => true, 'start_time' => '', 'end_time' => '', 'timezone' => $zone, 'availability_period_id' => '', 'preferred_pool_id' => '', 'note' => '', 'late_reason' => ''],
            'pool' => ['name' => '', 'kind' => 'regular', 'location_name' => '', 'responsible_id' => '', 'is_active' => true, 'user_ids' => []],
            'period' => ['name' => '', 'from' => now($zone)->toDateString(), 'until' => now($zone)->addWeek()->toDateString(), 'due_at' => now($zone)->addDay()->format('Y-m-d\TH:i'), 'timezone' => $zone],
            'offer' => ['shift_id' => '', 'user_ids' => [], 'expires_at' => now($zone)->addDay()->format('Y-m-d\TH:i'), 'timezone' => $zone],
            'transfer' => ['source_id' => '', 'target_user_id' => '', 'target_id' => '', 'target_revision' => '', 'note' => ''],
            'case' => ['shift_id' => '', 'shift_assignment_id' => '', 'kind' => 'failure', 'responsible_id' => auth()->id(), 'due_at' => now($zone)->addHour()->format('Y-m-d\TH:i'), 'timezone' => $zone, 'note' => ''],
            default => ['note' => '', 'approve' => true, 'action' => 'contact', 'user_id' => '', 'channel' => 'phone', 'handed_over_at' => '', 'timezone' => $zone],
        };
        if ($id) {
            $record = match ($kind) {
                'wish' => EmployeeAvailability::where('user_id', auth()->id())->findOrFail($id),
                'pool' => WorkforcePool::findOrFail($id),
                'period' => AvailabilityPeriod::findOrFail($id),
                'case' => StaffingCase::findOrFail($id),
                'review-transfer' => ShiftTransferRequest::findOrFail($id),
                default => abort(422),
            };
            $this->revision = $record->revision;
            if (in_array($kind, ['wish', 'pool', 'period'], true)) {
                foreach (array_keys($this->form) as $key) {
                    if (in_array($key, ['from', 'until'], true)) {
                        $this->form[$key] = $record->$key->toDateString();
                    } elseif ($key === 'due_at') {
                        $this->form[$key] = $record->due_at->setTimezone($record->timezone)->format('Y-m-d\TH:i');
                    } elseif ($key === 'user_ids') {
                        $this->form[$key] = $record->users()->pluck('users.id')->all();
                    } else {
                        $this->form[$key] = $record->$key ?? ($this->form[$key] ?? '');
                    }
                }
            } elseif ($kind === 'case') {
                $this->form = ['note' => '', 'action' => 'contact', 'user_id' => '', 'channel' => 'phone', 'handed_over_at' => '', 'timezone' => $zone];
            }
        }
        $this->formOpen = true;
        $this->resetValidation();
    }

    public function updatedForm($value, string $key): void
    {
        $this->access();
        if ($key === 'shift_id' && ! $this->personal && $this->modal === 'offer') {
            $this->planRevision = Shift::findOrFail((int) $value)->revision;
            $this->form['user_ids'] = [];
        } elseif ($key === 'source_id' && $this->personal && $this->modal === 'transfer') {
            $this->planRevision = ShiftAssignment::where('user_id', auth()->id())->findOrFail((int) $value)->plan_revision;
        }
    }

    public function save(WorkforcePlanningService $service): void
    {
        $this->access();
        $data = $this->form;
        foreach (['availability_period_id', 'preferred_pool_id', 'responsible_id', 'target_id', 'shift_assignment_id', 'user_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }
        if ($this->personal) {
            if ($this->modal === 'wish') {
                $data['weekdays'] = array_map('intval', $data['weekdays']);
                $service->saveWish($this->recordId, $this->revision, $data, auth()->user());
            } elseif ($this->modal === 'transfer') {
                $source = ShiftAssignment::where('user_id', auth()->id())->findOrFail((int) ($data['source_id'] ?? 0));
                abort_unless($this->planRevision !== null, 422);
                $service->requestTransfer($source->id, $this->planRevision, (int) ($data['target_user_id'] ?? 0), $data['target_id'] ? (int) $data['target_id'] : null, $data['target_revision'] ? (int) $data['target_revision'] : null, $data['note'] ?? '', auth()->user());
            } else {
                abort(403);
            }
        } else {
            match ($this->modal) {
                'pool' => $service->savePool($this->recordId, $this->revision, $data, auth()->user()),
                'period' => $service->savePeriod($this->recordId, $this->revision, $data, auth()->user()),
                'offer' => $service->offer((int) $data['shift_id'], (int) $this->planRevision, array_map('intval', $data['user_ids']), $data['expires_at'], $data['timezone'], auth()->user()),
                'case' => $this->recordId ? $service->updateCase($this->recordId, $this->revision, $data['action'], $data, auth()->user()) : $service->openCase($data, auth()->user()),
                'review-transfer' => $service->reviewTransfer($this->recordId, $this->revision, (bool) $data['approve'], $data['note'], auth()->user()),
                default => abort(422),
            };
        }
        $this->formOpen = false;
        $this->dispatch('operations-plan-changed');
    }

    public function removeWish(WorkforcePlanningService $service): void
    {
        $this->access();
        abort_unless($this->personal && $this->modal === 'wish' && $this->recordId, 403);
        $service->removeWish($this->recordId, $this->revision, $this->form['late_reason'] ?? '', auth()->user());
        $this->formOpen = false;
    }

    public function offerResponse(int $id, int $revision, string $status, WorkforcePlanningService $service): void
    {
        $this->access();
        abort_unless($this->personal, 403);
        $service->respondOffer($id, $revision, $status, auth()->user());
    }

    public function reviewOffer(int $id, int $revision, bool $approve, WorkforcePlanningService $service): void
    {
        $this->access();
        abort_if($this->personal, 403);
        $service->reviewOffer($id, $revision, $approve, auth()->user());
        $this->dispatch('operations-plan-changed');
    }

    public function transferResponse(int $id, int $revision, string $action, WorkforcePlanningService $service): void
    {
        $this->access();
        abort_unless($this->personal && in_array($action, ['accept', 'decline', 'withdraw'], true), 403);
        if ($action === 'withdraw') {
            $service->withdrawTransfer($id, $revision, auth()->user());
        } else {
            $service->respondTransfer($id, $revision, $action === 'accept', auth()->user());
        }
    }

    public function render()
    {
        $this->access();
        $employeeId = auth()->id();
        $query = match ($this->tab) {
            'pools' => WorkforcePool::with('users')->withCount('users')->orderBy('name'),
            'wishes' => EmployeeAvailability::with('user')->when($this->personal, fn ($q) => $q->where('user_id', $employeeId))->orderByDesc('from'),
            'periods' => AvailabilityPeriod::orderByDesc('from'),
            'offers' => ShiftOffer::with(['shift.order', 'responses.user'])->when($this->personal, fn ($q) => $q->whereJsonContains('invited_user_ids', $employeeId)->whereHas('shift', fn ($q) => $q->whereColumn('revision', 'published_revision')->where('published_revision', '>', 0)->where('starts_at', '>', now()->utc())->whereNotIn('status', ['draft', 'cancelled', 'completed', 'in_progress'])))->latest(),
            'transfers' => ShiftTransferRequest::with(['source.shift', 'source.user', 'target.shift', 'targetUser'])->when($this->personal, fn ($q) => $q->where(fn ($q) => $q->where('target_user_id', $employeeId)->orWhereHas('source', fn ($q) => $q->where('user_id', $employeeId))))->latest(),
            'cases' => StaffingCase::with('shift')->latest(),
        };
        $items = $query->paginate(20);
        $items->getCollection()->each(fn ($item) => $item->setAttribute('personal_view', $this->personal));
        $people = User::where('role', 'staff')->where('status', true)->orderBy('name')->get();
        $periods = AvailabilityPeriod::where('until', '>=', now()->subYear()->toDateString())->orderByDesc('from')->limit(24)->get();
        $periods->each(fn ($period) => $period->setAttribute('submission_state', app(WorkforcePlanningService::class)->periodStatus($period, auth()->user()))->setAttribute('personal_view', $this->personal));
        $shifts = $this->personal ? collect() : Shift::notCancelled()->where('ends_at', '>', now()->utc())->orderBy('starts_at')->limit(200)->get();
        $ownAssignments = $this->personal ? ShiftAssignment::where('user_id', $employeeId)->where('status', 'confirmed')->whereHas('shift', fn ($q) => $q->notCancelled()->whereColumn('revision', 'published_revision')->where('published_revision', '>', 0)->where('starts_at', '>', now()->utc()))->with('shift')->orderBy('id')->get() : collect();
        $candidateIssues = [];
        $candidateWishes = [];
        if (! $this->personal && $this->formOpen && $this->modal === 'offer' && ! empty($this->form['shift_id'])) {
            $shift = Shift::findOrFail($this->form['shift_id']);
            $candidateIssues = app(StaffEligibilityService::class)->assessMany($shift, $people);
            $candidateWishes = $people->mapWithKeys(fn ($person) => [$person->id => app(WorkforcePlanningService::class)->wishSummary($shift, $person)['state']])->all();
        }
        $submissions = collect();
        if (! $this->personal && $this->modal === 'period' && $this->recordId && $this->formOpen) {
            $period = AvailabilityPeriod::findOrFail($this->recordId);
            $submitted = EmployeeAvailability::where('availability_period_id', $period->id)->pluck('user_id')->unique();
            $submissions = $people->map(fn ($person) => (object) ['id' => $person->id, 'name' => $person->name, 'status_label' => $submitted->contains($person->id) ? 'Eingereicht' : ($period->due_at->isPast() ? 'Überfällig' : 'Offen')]);
        }

        return view('livewire.operations.workforce-planning', ['items' => $items, 'people' => $people, 'periods' => $periods, 'shifts' => $shifts, 'ownAssignments' => $ownAssignments, 'candidateIssues' => $candidateIssues, 'candidateWishes' => $candidateWishes, 'submissions' => $submissions, 'pools' => WorkforcePool::where('is_active', true)->orderBy('name')->get(), 'managers' => User::where('status', true)->get()->filter(fn ($user) => Gate::forUser($user)->allows('operations.manage'))]);
    }
}
