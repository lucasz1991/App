<?php

namespace App\Livewire\Admin;

use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings as MicrosoftDeviceSettingsService;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class MicrosoftDeviceSettings extends Component
{
    /** @var array<string, mixed> */
    public array $form = [];

    #[Locked]
    public int $runtimePollUntil = 0;

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
        $this->reloadForm();
        $this->runtimePollUntil = now()->timestamp + 120;
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
        $this->resetValidation('runtime');
        $runtime = $this->runtimeStatus();
        if (! ($runtime['schema_ready'] ?? false) || ! ($runtime['queue_ready'] ?? false)) {
            $this->addError('runtime', 'Der Geräteabgleich kann noch nicht starten. Beheben Sie die angezeigten Datenbank- oder Warteschlangenprobleme.');

            return;
        }

        try {
            $queued = app(MicrosoftDeviceSyncScheduler::class)->queue(force: true);
            $this->runtimePollUntil = now()->timestamp + 120;
            $this->dispatch('swal:toast', type: $queued ? 'success' : 'info', text: $queued
                ? 'Die Microsoft-Gerätesynchronisierung wurde in die Warteschlange gestellt.'
                : 'Es läuft bereits eine Synchronisierung oder die Verbindung ist noch nicht aktiviert.');
        } catch (Throwable) {
            $this->addError('runtime', 'Die Synchronisierung konnte nicht gestartet werden. Prüfen Sie den Betriebsstatus und die gespeicherte Microsoft-Verbindung.');
        }
    }

    public function testBackgroundProcessing(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetValidation('runtime');
        if (! ($this->runtimeStatus()['queue_ready'] ?? false)) {
            $this->addError('runtime', 'Der Hintergrundtest benötigt eine erreichbare, korrekt eingerichtete Warteschlange. Beheben Sie zuerst die angezeigten Probleme.');

            return;
        }

        try {
            $queued = app(MicrosoftDeviceRuntime::class)->queueWorkerProbe();
            $this->runtimePollUntil = now()->timestamp + 120;
            $this->dispatch('swal:toast', type: $queued ? 'success' : 'info', text: $queued
                ? 'Der Hintergrundtest wurde eingeplant. Die Bestätigung erscheint erst, sobald der Worker den Test verarbeitet hat.'
                : 'Ein Hintergrundtest ist bereits eingeplant. Sein aktueller Status wird angezeigt.');
        } catch (Throwable) {
            $this->addError('runtime', 'Der Hintergrundtest konnte nicht eingeplant werden. Prüfen Sie die angezeigten Warteschlangenprobleme.');
        }
    }

    public function refreshRuntime(): void
    {
        $this->authorizeSuperAdmin();
        $this->runtimePollUntil = now()->timestamp + 120;
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

    /** @return array<string, mixed> */
    private function runtimeStatus(): array
    {
        try {
            return app(MicrosoftDeviceRuntime::class)->status();
        } catch (Throwable) {
            return [
                'schema_ready' => false,
                'queue_ready' => false,
                'issues' => [['code' => 'runtime_unavailable', 'message' => 'Der Betriebsstatus konnte nicht gelesen werden. Prüfen Sie die Datenbankverbindung und führen Sie die ausstehenden Migrationen aus.']],
                'scheduler' => ['state' => 'unknown', 'checked_at' => null],
                'worker' => ['state' => 'unknown', 'checked_at' => null],
                'run' => [],
                'overdue' => false,
                'worker_probe' => ['status' => 'unknown', 'queued_at' => null, 'acknowledged_at' => null],
            ];
        }
    }

    public function render()
    {
        $this->authorizeSuperAdmin();
        $runtime = $this->runtimeStatus();
        $runtimePending = in_array(data_get($runtime, 'run.status'), ['queued', 'running'], true)
            || in_array(data_get($runtime, 'worker_probe.status'), ['queued', 'running'], true);

        return view('livewire.admin.microsoft-device-settings', [
            'connectionStatus' => app(MicrosoftDeviceSettingsService::class)->status(),
            'runtimeStatus' => $runtime,
            'runtimePending' => $runtimePending,
            'runtimePolling' => $runtimePending && $this->runtimePollUntil > now()->timestamp,
        ]);
    }
}
