<?php

namespace App\Livewire\Devices;

use App\Models\DeviceEnrollment;
use App\Services\DeviceManagement\DeviceEnrollmentService;
use Livewire\Component;

class DeviceEnrollmentSetup extends Component
{
    public string $enrollmentPublicId;

    /** @var array<int, string> */
    public array $steps = [];

    public ?string $enrollmentUrl = null;

    public ?string $providerMessage = null;

    public bool $limitedManagement = false;

    public function mount(string $token, DeviceEnrollmentService $enrollments): void
    {
        $claim = $enrollments->claim($token, auth()->user());
        $this->enrollmentPublicId = $claim->enrollment->public_id;
        $this->steps = $claim->instructions->steps;
        $this->enrollmentUrl = $claim->instructions->enrollmentUrl;
        $this->providerMessage = $claim->instructions->message;
        $this->limitedManagement = $claim->instructions->limitedManagement;
    }

    public function render()
    {
        $enrollment = DeviceEnrollment::query()
            ->where('public_id', $this->enrollmentPublicId)
            ->where('user_id', auth()->id())
            ->with(['device.activeAssignment.user:id,name,email'])
            ->firstOrFail();

        return view('livewire.devices.device-enrollment-setup', compact('enrollment'))
            ->layout('layouts.master', ['area' => auth()->user()->usesAdminLayout() ? 'admin' : 'user']);
    }
}
