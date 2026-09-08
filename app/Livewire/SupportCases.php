<?php

namespace App\Livewire;

use App\Models\SupportCase;
use App\Services\Support\SupportCaseService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SupportCases extends Component
{
    use WithPagination;

    #[Url(as: 'fall')]
    public ?string $caseId = null;
    public string $statusFilter = '';
    public string $replyBody = '';
    #[Locked]
    public string $replyId = '';
    public bool $showCase = false;

    public function mount(): void
    {
        $this->replyId = (string) Str::uuid();
        if ($this->caseId) { $this->openCase($this->caseId); }
    }

    public function openCase(string $id): void
    {
        $case = SupportCase::query()->where('public_id', $id)->firstOrFail();
        app(SupportCaseService::class)->authorize($case, auth()->user());
        $this->caseId = $case->public_id;
        $this->showCase = true;
        $this->replyBody = '';
        $this->replyId = (string) Str::uuid();
    }

    public function reply(): void
    {
        $case = $this->selected();
        app(SupportCaseService::class)->reply($case, auth()->user(), $this->replyId, $this->replyBody);
        $this->replyBody = '';
        $this->replyId = (string) Str::uuid();
    }

    public function transition(string $status): void
    {
        app(SupportCaseService::class)->transition($this->selected(), auth()->user(), $status);
    }

    private function selected(): SupportCase
    {
        $case = SupportCase::query()->where('public_id', $this->caseId)->firstOrFail();
        app(SupportCaseService::class)->authorize($case, auth()->user());

        return $case;
    }

    public function render()
    {
        $user = auth()->user();
        abort_unless($user?->isActive() && $user->email_verified_at, 403);
        $canManage = Gate::forUser($user)->allows('support.manage');
        $cases = SupportCase::query()->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when(isset(SupportCaseService::STATUSES[$this->statusFilter]), fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['user', 'device'])->latest('updated_at')->paginate(20);
        $detail = $this->showCase && $this->caseId ? app(SupportCaseService::class)->serialize($this->selected(), $user) : null;

        return view('livewire.support-cases', compact('cases', 'detail', 'canManage'))->layout('layouts.master', ['area' => $user->usesAdminLayout() ? 'admin' : 'user']);
    }
}
