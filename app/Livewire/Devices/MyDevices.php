<?php

namespace App\Livewire\Devices;

use App\Models\DeviceAssignment;
use App\Services\DeviceManagement\Desktop\DeviceClientReleaseService;
use App\Services\DeviceManagement\Desktop\OutlookDesktopPairingService;
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

        // RailTime-App für Windows: freigegebener Installer und die eigenen verbundenen PCs.
        $releases = app(DeviceClientReleaseService::class);
        $release = $releases->available() ? $releases->current() : null;
        $pairings = app(OutlookDesktopPairingService::class);
        $desktopClients = $pairings->ready() ? $pairings->clientsFor($user) : collect();
        $hasWindows = $assignments->contains(fn ($assignment) => $assignment->device?->platform?->value === 'windows');

        return view('livewire.devices.my-devices', compact('assignments', 'release', 'desktopClients', 'hasWindows'))
            ->layout('layouts.master');
    }
}
