<?php

namespace App\Livewire;

use App\Models\SupportCase;
use App\Models\SupportRemoteSession;
use App\Services\DeviceManagement\Desktop\RemoteSupportService;
use App\Services\Support\SupportAttachmentService;
use App\Services\Support\SupportCaseService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class SupportCases extends Component
{
    use WithFileUploads { _startUpload as protected startSupportUpload; }
    use WithPagination;

    public $attachment = null;

    public bool $attachmentConfirmed = false;

    #[Renderless]
    public function _startUpload($name, $fileInfo, $isMultiple)
    {
        $this->selected();
        abort_unless($this->attachmentConfirmed && $name === 'attachment' && ! $isMultiple && count($fileInfo) === 1, 422);
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');
        $configuration = config('filesystems.disks.'.$disk, []);
        abort_unless(in_array($disk, ['local', 'private'], true) && ($configuration['driver'] ?? '') === 'local'
            && in_array($configuration['root'] ?? '', [storage_path('app'), storage_path('app/private')], true), 503, 'Supportanhänge benötigen einen privaten lokalen Upload-Zwischenspeicher.');
        abort_unless(($fileInfo[0]['size'] ?? 0) > 0 && ($fileInfo[0]['size'] ?? PHP_INT_MAX) <= 5242880, 422);
        $this->startSupportUpload($name, $fileInfo, $isMultiple);
    }

    public function attachFile(): void
    {
        $this->validate(['attachment' => ['required', 'file', 'max:5120'], 'attachmentConfirmed' => ['accepted']]);
        app(SupportAttachmentService::class)->store($this->selected(), auth()->user(), $this->attachment, $this->attachmentConfirmed);
        if ($this->attachment instanceof TemporaryUploadedFile) {
            $this->attachment->delete(); // Remove only this explicit upload's temporary copy after encrypted persistence.
        }
        $this->reset('attachment', 'attachmentConfirmed');
    }

    #[Url(as: 'fall')]
    public ?string $caseId = null;

    #[Url(as: 'geraet')]
    public ?string $deviceId = null;

    public string $statusFilter = '';

    public string $replyBody = '';

    #[Locked]
    public string $replyId = '';

    public bool $showCase = false;

    public function mount(): void
    {
        $this->replyId = (string) Str::uuid();
        if ($this->caseId) {
            $this->openCase($this->caseId);
        }
    }

    public function openCase(string $id): void
    {
        $this->reset('attachment', 'attachmentConfirmed');
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

    /** Fernwartung anfragen: der Mitarbeiter muss am PC zustimmen. */
    public function requestRemoteSupport(RemoteSupportService $remote): void
    {
        $remote->request($this->selected(), auth()->user());
    }

    public function endRemoteSupport(string $sessionId, RemoteSupportService $remote): void
    {
        $case = $this->selected();
        $remote->end(SupportRemoteSession::query()->where('public_id', $sessionId)->where('support_case_id', $case->id)->firstOrFail(), auth()->user());
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
            ->when($this->deviceId, fn ($q) => $q->whereHas('device', fn ($devices) => $devices->where('public_id', $this->deviceId)))
            ->when(isset(SupportCaseService::STATUSES[$this->statusFilter]), fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['user', 'device'])->latest('updated_at')->paginate(20);
        $detail = $this->showCase && $this->caseId ? app(SupportCaseService::class)->serialize($this->selected(), $user) : null;

        // Fernwartung je Fall: nur für IT mit Fernsupport-Recht und nur mit verbundener App auf einem Firmengerät.
        $remote = null;
        $remoteService = app(RemoteSupportService::class);
        if ($detail && $remoteService->ready() && $remoteService->canRequest($user)) {
            $case = $this->selected();
            $sessions = $case->sessions()->latest('id')->limit(5)->get();
            $remote = ['available' => $remoteService->clientFor($case) !== null,
                'open' => $sessions->contains(fn ($session) => in_array($session->status, ['requested', 'consented'], true) && $session->expires_at->isFuture()),
                'sessions' => $sessions->map(fn ($session) => ['id' => $session->public_id, 'status' => $session->status,
                    'created_at' => $session->created_at, 'consented_at' => $session->consented_at, 'ended_at' => $session->ended_at,
                    'expires_at' => $session->expires_at, 'url' => $remoteService->remoteUrl($session, $user)])->all()];
        }

        return view('livewire.support-cases', compact('cases', 'detail', 'canManage', 'remote'))->layout('layouts.master');
    }
}
