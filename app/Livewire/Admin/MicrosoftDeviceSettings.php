<?php

namespace App\Livewire\Admin;

use App\Services\DeviceManagement\MicrosoftDeviceSettings as MicrosoftDeviceSettingsService;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class MicrosoftDeviceSettings extends Component
{
    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
        $this->reloadForm();
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetValidation();

        try {
            app(MicrosoftDeviceSettingsService::class)->save($this->form, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError('form.'.$key, $message);
                }
            }

            return;
        } catch (Throwable) {
            $this->addError('form', 'Die Microsoft-Geräteeinstellungen konnten nicht gespeichert werden.');

            return;
        }

        $this->reloadForm();
        $this->dispatch('swal:toast', type: 'success', text: 'Microsoft-Geräteeinstellungen wurden gespeichert.');
    }

    public function testConnection(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetValidation('connection');

        try {
            app(MicrosoftDeviceSyncService::class)->probe();
        } catch (Throwable) {
            $this->addError('connection', 'Der Microsoft-Verbindungstest konnte nicht abgeschlossen werden. Prüfen Sie die gespeicherten Zugangsdaten und Anwendungsrechte.');
        }
    }

    public function syncNow(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetValidation('connection');

        try {
            $queued = app(MicrosoftDeviceSyncScheduler::class)->queue(force: true);
            $this->dispatch('swal:toast', type: $queued ? 'success' : 'info', text: $queued
                ? 'Die Microsoft-Gerätesynchronisierung wurde in die Warteschlange gestellt.'
                : 'Es läuft bereits eine Synchronisierung oder die Verbindung ist noch nicht aktiviert.');
        } catch (Throwable) {
            $this->addError('connection', 'Die Synchronisierung konnte nicht gestartet werden. Prüfen Sie die gespeicherte Verbindung und den asynchronen Geräte-Queue-Worker.');
        }
    }

    private function authorizeSuperAdmin(): void
    {
        Gate::authorize('settings.manage');
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    private function reloadForm(): void
    {
        $this->form = app(MicrosoftDeviceSettingsService::class)->forForm();
    }

    public function render()
    {
        $this->authorizeSuperAdmin();

        return view('livewire.admin.microsoft-device-settings', [
            'connectionStatus' => app(MicrosoftDeviceSettingsService::class)->status(),
        ]);
    }
}
