<?php

namespace App\Livewire\Operations;

use App\Models\OperationsMonitorProfile;
use App\Models\OperationsReminderPreference;
use App\Models\User;
use App\Services\Operations\OperationsDutyMonitorService;
use App\Services\Operations\OperationsReminderService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\UnifiedOperationsInboxService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class AttentionCenter extends Component
{
    use WithPagination;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public string $mode = 'inbox';

    #[Locked]
    public string $tab = 'inbox';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public ?int $subjectId = null;

    public bool $formOpen = false;

    public array $form = [];

    public string $from = '';

    public string $until = '';

    public string $search = '';

    public function mount(bool $personal = false, string $mode = 'inbox'): void
    {
        $this->personal = $personal;
        abort_unless(in_array($mode, ['inbox', 'monitor'], true) && (! $personal || $mode === 'inbox'), 422);
        $this->mode = $mode;
        $this->tab = $mode === 'monitor' ? 'board' : 'inbox';
        $this->from = now(config('operations.display_timezone'))->toDateString();
        $this->until = $this->from;
        $this->access();
    }

    private function access(): void
    {
        $actor = User::findOrFail(auth()->id());
        if ($this->personal) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            OperationsAccess::authorize($actor, $this->mode === 'monitor' ? 'operations.manage' : 'operations.inbox.view');
        }
        OperationsAccess::requireReady();
    }

    public function setTab(string $tab): void
    {
        $this->access();
        abort_unless(in_array($tab, $this->mode === 'monitor' ? ['board', 'profiles'] : ['inbox', 'reminders'], true), 422);
        if ($tab === 'reminders') {
            abort_unless(app(OperationsReminderService::class)->ready(), 503);
            if (! $this->personal) {
                OperationsAccess::authorize(auth()->user(), 'employees.master-data.edit');
            }
        }
        if ($tab === 'profiles') {
            abort_unless(app(OperationsDutyMonitorService::class)->ready(), 503);
            app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'operations.rules.manage');
        }
        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedUntil(): void
    {
        $this->resetPage();
    }

    public function openItem(string $id): void
    {
        $this->access();
        $this->redirect(app(UnifiedOperationsInboxService::class)->destination($id, auth()->user(), $this->personal), navigate: true);
    }

    public function markRead(int $id, int $revision): void
    {
        $this->access();
        app(OperationsReminderService::class)->markRead($id, $revision, auth()->user());
    }

    public function refreshReminders(): void
    {
        $this->access();
        abort_unless($this->personal, 403);
        app(OperationsReminderService::class)->syncUser(auth()->user());
    }

    public function edit(?int $id = null): void
    {
        $this->access();
        abort_unless(in_array($this->tab, ['profiles', 'reminders'], true), 422);
        $this->recordId = $id;
        $this->revision = null;
        $this->subjectId = $this->personal ? auth()->id() : null;
        if ($this->tab === 'profiles') {
            app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'operations.rules.manage');
            $this->form = ['name' => '', 'start_grace_minutes' => 5, 'end_grace_minutes' => 15, 'responsible_user_id' => auth()->id(), 'auto_cases' => false, 'is_active' => false];
            if ($id) {
                $record = OperationsMonitorProfile::findOrFail($id);
                $this->revision = $record->revision;
                $this->form = $record->only(array_keys($this->form));
            }
        } else {
            if (! $this->personal) {
                app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.edit');
            }
            $this->form = ['user_id' => $this->subjectId, 'kind' => 'punch_start', 'lead_minutes' => 30, 'quiet_from' => '', 'quiet_until' => '', 'timezone' => config('operations.display_timezone', 'Europe/Berlin'), 'is_active' => false];
            if ($id) {
                $query = OperationsReminderPreference::query();
                if ($this->personal) {
                    $query->where('user_id', auth()->id());
                } else {
                    app(PersonnelScopeService::class)->applyRelatedQuery($query, auth()->user(), 'employees.master-data.edit');
                }
                $record = $query->findOrFail($id);
                $this->subjectId = $record->user_id;
                $this->revision = $record->revision;
                $this->form = $record->only(array_keys($this->form));
            }
        }
        $this->formOpen = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->access();
        if ($this->tab === 'profiles') {
            app(OperationsDutyMonitorService::class)->saveProfile($this->form, auth()->user(), $this->recordId, $this->revision);
        } else {
            abort_unless($this->tab === 'reminders', 422);
            $this->validate(['form.user_id' => 'required|integer|exists:users,id']);
            $target = User::where('role', 'staff')->where('status', true)->findOrFail($this->personal ? auth()->id() : ($this->subjectId ?? $this->form['user_id']));
            app(OperationsReminderService::class)->savePreference($target, $this->form, auth()->user(), $this->recordId, $this->revision);
        }
        $this->reset(['formOpen', 'recordId', 'revision', 'subjectId', 'form']);
        session()->flash('operations.saved', 'Gespeichert.');
    }

    public function escalate(int $assignmentId, int $planRevision): void
    {
        $this->access();
        abort_unless(! $this->personal && $this->mode === 'monitor', 403);
        app(OperationsDutyMonitorService::class)->escalate($assignmentId, $planRevision, auth()->user());
        session()->flash('operations.saved', 'Fall zur Prüfung angelegt.');
    }

    public function render()
    {
        $this->access();
        $actor = auth()->user();
        $preferencesReady = app(OperationsReminderService::class)->ready();
        $profilesReady = app(OperationsDutyMonitorService::class)->ready();
        $items = match ($this->tab) {
            'inbox' => app(UnifiedOperationsInboxService::class)->items($actor, $this->personal),
            'board' => app(OperationsDutyMonitorService::class)->board($actor, $this->from, $this->until)->map(fn ($row) => (object) $row),
            'profiles' => $profilesReady ? OperationsMonitorProfile::orderBy('is_active', 'desc')->orderBy('name')->get() : collect(),
            'reminders' => $preferencesReady ? ($this->personal ? OperationsReminderPreference::where('user_id', $actor->id) : app(PersonnelScopeService::class)->applyRelatedQuery(OperationsReminderPreference::query(), $actor, 'employees.master-data.edit'))->orderBy('kind')->get() : collect(),
        };
        if (filled($this->search)) {
            $items = $items->filter(fn ($row) => str_contains(mb_strtolower(($row->title ?? $row->name ?? OperationsReminderService::KINDS[$row->kind] ?? '').' '.($row->subject ?? $row->user_name ?? '')), mb_strtolower(mb_substr($this->search, 0, 100))));
        }
        $items = $items->map(function ($item) {
            $item->view_tab = $this->tab;

            return $item;
        });
        $page = $this->getPage();
        $items = new LengthAwarePaginator($items->forPage($page, 25)->values(), $items->count(), 25, $page, ['path' => request()->url()]);
        $users = $this->personal ? collect() : ($actor->can('employees.master-data.edit') ? app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff')->where('status', true), $actor, 'employees.master-data.edit')->orderBy('name')->get(['id', 'name']) : collect());
        $managers = $profilesReady && ! $this->personal ? User::where('status', true)->get(['id', 'name', 'role', 'status', 'current_team_id'])->filter(fn ($user) => $user->can('operations.manage')) : collect();

        return view('livewire.operations.attention-center', compact('items', 'users', 'managers', 'preferencesReady', 'profilesReady'));
    }
}
