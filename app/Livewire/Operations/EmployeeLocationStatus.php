<?php

namespace App\Livewire\Operations;

use App\Models\MobileLocationCode;
use App\Services\EmployeeLocation\EmployeeLocationService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use App\Support\Operations\OperationsAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeLocationStatus extends Component
{
    public bool $explanationOpen = false;

    public bool $historyOpen = false;

    public string $assignmentId = '';

    #[Locked]
    public int $explanationVersion = 1;

    #[Locked]
    public string $registrationCode = '';

    #[Locked]
    public ?string $registrationExpiresAt = null;

    #[Locked]
    public string $handoffUrl = '';

    #[Locked]
    public string $handoffCode = '';

    #[Locked]
    public ?string $handoffExpiresAt = null;

    public function mount(): void
    {
        $this->authorizeOwn();
        $this->explanationVersion = (int) EmployeeLocationSettings::read()['explanation_version'];
    }

    public function refreshStatus(): void
    {
        $this->authorizeOwn();
        if ($this->registrationExpiresAt && now()->gte($this->registrationExpiresAt)) {
            $this->registrationCode = '';
            $this->registrationExpiresAt = null;
        }
        $this->expireHandoff();
    }

    public function openExplanation(): void
    {
        $this->authorizeOwn();
        $this->explanationVersion = (int) EmployeeLocationSettings::read()['explanation_version'];
        $this->explanationOpen = true;
    }

    public function accept(EmployeeLocationService $service): void
    {
        $this->authorizeOwn();
        $service->decide(auth()->user(), true, $this->explanationVersion);
        $this->explanationOpen = false;
        $this->clearHandoff();
    }

    public function decline(EmployeeLocationService $service): void
    {
        $this->authorizeOwn();
        $service->decide(auth()->user(), false, $this->explanationVersion);
        $this->explanationOpen = false;
        $this->clearHandoff();
    }

    public function withdraw(EmployeeLocationService $service): void
    {
        $this->authorizeOwn();
        $service->withdraw(auth()->user());
        $this->clearHandoff();
    }

    public function deleteHistory(EmployeeLocationService $service): void
    {
        $this->authorizeOwn();
        $service->deleteHistory(auth()->user());
        $this->clearHandoff();
    }

    public function createRegistration(EmployeeLocationService $service): void
    {
        $this->authorizeOwn();
        $this->validate(['assignmentId' => 'required|integer|min:1']);
        $this->registrationCode = $service->pairingCode(auth()->user(), (int) $this->assignmentId);
        $this->registrationExpiresAt = now()->addMinutes(15)->toIso8601String();
    }

    public function prepareHandoff(EmployeeLocationService $service): void
    {
        $this->authorizeOwn();
        $base = rtrim((string) config('app.url'), '/');
        abort_unless(str_starts_with($base, 'https://'), 422, 'Der native Standortdienst benötigt eine HTTPS-Adresse.');
        $code = $service->handoff(auth()->user());
        $issued = MobileLocationCode::where('user_id', auth()->id())->where('purpose', 'start')->where('code_hash', hash('sha256', $code))->firstOrFail();
        $this->handoffCode = $code;
        $this->handoffExpiresAt = $issued->expires_at->toISOString();
        $this->handoffUrl = $base.'/employee-location/open#code='.rawurlencode($code).'&server='.rawurlencode($base);
    }

    public function render(EmployeeLocationService $service): View
    {
        $this->authorizeOwn();
        $this->expireHandoff();
        $status = $service->status(auth()->user(), $this->historyOpen);
        if ($this->explanationOpen || $status['needs_decision']) {
            $this->explanationVersion = (int) $status['settings']['explanation_version'];
        }

        return view('livewire.operations.employee-location-status', ['locationStatus' => $status]);
    }

    private function authorizeOwn(): void
    {
        abort_unless(auth()->user(), 401);
        OperationsAccess::own(auth()->user(), (int) auth()->id());
    }

    private function expireHandoff(): void
    {
        if ($this->handoffExpiresAt && now()->gte($this->handoffExpiresAt)) {
            $this->clearHandoff();
        }
    }

    private function clearHandoff(): void
    {
        $this->handoffUrl = '';
        $this->handoffCode = '';
        $this->handoffExpiresAt = null;
    }
}
