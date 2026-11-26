<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\Personnel\EmployeePhoneStatusService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeePhoneStatus extends Component
{
    #[Locked]
    public int $userId;

    #[Locked]
    public string $profilePrefix;

    #[Locked]
    public int $ownerUserId;

    public function mount(int $userId, string $profilePrefix): void
    {
        abort_unless(auth()->user() instanceof User, 403);
        $this->ownerUserId = (int) auth()->id();
        $this->userId = $userId;
        $this->profilePrefix = $profilePrefix;
    }

    public function refreshStatus(): void
    {
        $this->authorizeActor();
    }

    public function render(EmployeePhoneStatusService $service): View
    {
        $this->authorizeActor();

        return view('livewire.profile.employee-phone-status', ['phoneStatus' => $service->read($this->userId)]);
    }

    private function authorizeActor(): void
    {
        abort_unless(auth()->user() instanceof User && (int) auth()->id() === $this->ownerUserId, 403);
    }
}
