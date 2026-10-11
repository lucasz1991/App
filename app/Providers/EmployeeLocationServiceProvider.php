<?php

namespace App\Providers;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\User;
use App\Services\EmployeeLocation\EmployeeLocationService;
use App\Services\Privacy\SessionPermissionService;
use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class EmployeeLocationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('employee-location', fn (Request $request) => Limit::perMinute(60)->by('employee-location:'.hash('sha256', (string) ($request->bearerToken() ?: $request->ip()))));
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                app(SessionPermissionService::class)->end($event->user);
            }
        });
        DeviceAssignment::updated(function (DeviceAssignment $assignment): void {
            if ($assignment->wasChanged(['status', 'returned_at', 'user_id', 'device_id'])) {
                app(EmployeeLocationService::class)->revokeAssignment((int) $assignment->id);
            }
        });
        DeviceAssignment::deleting(function (DeviceAssignment $assignment): void {
            app(EmployeeLocationService::class)->revokeAssignment((int) $assignment->id);
        });
        Device::updated(function (Device $device): void {
            if (EmployeeLocationSettings::ready() && $device->wasChanged(['ownership', 'platform', 'lifecycle_status'])) {
                foreach ($device->assignments()->active()->pluck('id') as $id) {
                    app(EmployeeLocationService::class)->revokeAssignment((int) $id);
                }
            }
        });
        Device::deleting(function (Device $device): void {
            if (! EmployeeLocationSettings::ready()) {
                return;
            }
            foreach ($device->assignments()->active()->pluck('id') as $id) {
                app(EmployeeLocationService::class)->revokeAssignment((int) $id);
            }
        });
        User::updated(function (User $user): void {
            if ($user->wasChanged(['status', 'role']) && (! $user->isActive() || $user->role !== 'staff') && EmployeeLocationSettings::ready()) {
                app(EmployeeLocationService::class)->stopForUser((int) $user->id);
                foreach ($user->devices()->pluck('device_assignments.id') as $id) {
                    app(EmployeeLocationService::class)->revokeAssignment((int) $id);
                }
            }
        });
    }
}
