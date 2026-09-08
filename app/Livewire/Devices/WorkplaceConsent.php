<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use App\Services\DeviceManagement\DeviceWorkplaceService;
use App\Services\DeviceManagement\WorkplaceProfileCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkplaceConsent extends Component
{
    #[Locked]
    public string $deviceId;
    #[Locked]
    public ?int $reviewedRevision = null;
    #[Locked]
    public ?string $reviewedHash = null;
    public bool $showModal = false;
    public bool $confirmed = false;
    public bool $withdrawConfirmed = false;
    public string $profile = 'railtime_basic';

    private function device(): Device
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor?->isActive() && $actor->email_verified_at, 403);
        $device = Device::query()->where('public_id', $this->deviceId)->firstOrFail();
        abort_unless(Gate::forUser($actor)->allows('devices.manage') || $device->assignments()->active()->where('user_id', $actor->id)->exists(), 403);

        return $device;
    }

    public function open(): void
    {
        $summary = app(DeviceWorkplaceService::class)->summary($this->device());
        $this->profile = $summary['profile_key'] ?? 'railtime_basic';
        $this->reviewedRevision = $summary['revision'];
        $this->reviewedHash = $summary['profile_key'] ? WorkplaceProfileCatalog::hash($summary['profile_key']) : null;
        $this->reset('confirmed', 'withdrawConfirmed');
        $this->showModal = true;
    }

    public function configure(): void
    {
        app(DeviceWorkplaceService::class)->configure($this->device(), $this->profile, auth()->user());
        $this->open();
    }

    public function accept(): void
    {
        abort_unless($this->reviewedRevision && $this->reviewedHash, 409);
        app(DeviceWorkplaceService::class)->accept($this->device(), auth()->user(), $this->reviewedRevision, $this->reviewedHash, $this->confirmed);
        $this->open();
    }

    public function withdraw(): void
    {
        $this->validate(['withdrawConfirmed' => ['accepted']]);
        app(DeviceWorkplaceService::class)->revoke($this->device(), auth()->user());
        $this->open();
    }

    public function render()
    {
        $device = $this->device();
        $summary = app(DeviceWorkplaceService::class)->summary($device);
        $owner = $device->assignments()->active()->where('user_id', auth()->id())->exists();

        return view('livewire.devices.workplace-consent', compact('device', 'summary', 'owner'));
    }
}
