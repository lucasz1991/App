<?php

namespace App\Livewire\Devices;

use App\Models\DeviceAssignment;
use Livewire\Component;

class MyDevices extends Component
{
    public function render()
    {
        $user = auth()->user();
        abort_unless($user?->isActive(), 403);

        $assignments = DeviceAssignment::query()
            ->where('user_id', $user->id)
            ->active()
            ->with([
                'device.enrollments' => fn ($query) => $query->where('user_id', $user->id)->latest('invited_at'),
                'device.readinessChecks',
                'device.accountAssignments.provisioningProfile',
            ])
            ->latest('assigned_at')
            ->get();

        return view('livewire.devices.my-devices', compact('assignments'))
            ->layout('layouts.master', ['area' => $user->usesAdminLayout() ? 'admin' : 'user']);
    }
}
