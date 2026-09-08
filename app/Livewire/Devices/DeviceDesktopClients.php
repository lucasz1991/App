<?php

namespace App\Livewire\Devices;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceDesktopClient;
use App\Models\User;
use App\Services\DeviceManagement\Desktop\DeviceDesktopService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DeviceDesktopClients extends Component
{
    public string $devicePublicId = '';

    #[Locked]
    public ?string $clientPublicId = null;

    #[Locked]
    public ?string $pairingToken = null;

    #[Locked]
    public ?string $pairingExpiresAt = null;

    public bool $endpointConfirmed = false;

    public bool $revokeConfirmed = false;

    public array $policy = [];

    public string $packageId = '';

    public string $packageVersion = '';

    public string $notificationTitle = '';

    public string $notificationBody = '';

    public string $justification = '';

    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->actor();
        $this->policy = app(DeviceDesktopService::class)->defaults();
    }

    public function selectClient(string $publicId): void
    {
        $this->actor();
        $client = DeviceDesktopClient::query()->where('public_id', $publicId)->firstOrFail();
        $this->reset('pairingToken', 'pairingExpiresAt', 'revokeConfirmed', 'notice');
        $this->clientPublicId = $client->public_id;
        $this->policy = array_intersect_key($client->policy ?? [], app(DeviceDesktopService::class)->defaults());
        $this->resetValidation();
    }

    public function issuePairing(DeviceDesktopService $clients): void
    {
        $actor = $this->actor();
        $this->validate(['devicePublicId' => ['required', 'uuid'], 'endpointConfirmed' => ['accepted']]);
        $device = Device::query()->where('public_id', $this->devicePublicId)->firstOrFail();
        $issued = $clients->issue($device, $actor, $this->endpointConfirmed);
        $this->clientPublicId = $issued['client']->public_id;
        $this->policy = $clients->defaults();
        $this->pairingToken = $issued['enrollment_token'];
        $this->pairingExpiresAt = $issued['expires_at'];
        $this->endpointConfirmed = false;
        $this->notice = 'Einmalcode erstellt. Nur vertraulich an den zugewiesenen Mitarbeiter übergeben; niemals in Tickets oder E-Mails protokollieren.';
    }

    public function dismissPairing(): void
    {
        $this->actor();
        $this->reset('pairingToken', 'pairingExpiresAt');
    }

    public function savePolicy(DeviceDesktopService $clients): void
    {
        $clients->savePolicy($this->selected(), $this->policy, $this->actor());
        $this->notice = 'Clientrichtlinie gespeichert. Lokale Benutzer- und Betriebssystemzustimmung bleibt erforderlich.';
    }

    public function queueSoftware(DeviceDesktopService $clients): void
    {
        $clients->queueJob($this->selected(), 'install_software', [
            'package_id' => trim($this->packageId), 'version' => trim($this->packageVersion),
        ], $this->justification, $this->actor());
        $this->notice = 'Softwareauftrag vorgemerkt. Ausführung erst bei globaler Freigabe, frischem Clientabgleich und lokaler Bestätigung; maximal 30 Minuten gültig.';
    }

    public function queueNotification(DeviceDesktopService $clients): void
    {
        $clients->queueJob($this->selected(), 'notification', [
            'title' => trim($this->notificationTitle), 'body' => trim($this->notificationBody),
        ], $this->justification, $this->actor());
        $this->notice = 'Mitteilung vorgemerkt; Zustellung erst beim nächsten freigegebenen Clientabgleich. Kein Offline-Push.';
    }

    public function revoke(DeviceDesktopService $clients): void
    {
        $this->validate(['revokeConfirmed' => ['accepted']]);
        $clients->revoke($this->selected(), $this->actor());
        $this->reset('pairingToken', 'pairingExpiresAt', 'revokeConfirmed');
        $this->notice = 'Clientzugang und offene Einladungen widerrufen; noch offene Aufträge abgebrochen. Bereits laufende lokale Prozesse werden dadurch nicht beendet.';
    }

    private function selected(): DeviceDesktopClient
    {
        $this->actor();

        return DeviceDesktopClient::query()->where('public_id', $this->clientPublicId)->firstOrFail();
    }

    private function actor(): User
    {
        $user = auth()->user()?->fresh();
        abort_unless($user?->isActive() && $user->email_verified_at !== null, 403);
        Gate::forUser($user)->authorize('devices.view');
        app(DeviceDesktopService::class)->assertStorageReady();

        return $user;
    }

    public function render()
    {
        $actor = $this->actor();
        $clients = DeviceDesktopClient::query()->with(['device', 'assignment.user:id,name'])->latest()->limit(200)->get();
        $selected = $this->clientPublicId ? $this->selected()->load(['device', 'assignment.user:id,name']) : null;
        $jobs = $selected ? $selected->jobs()->latest()->limit(20)->get() : collect();
        $setupBlocked = $selected && $selected->jobs()->where('type', 'install_software')->where('status', '!=', 'succeeded')->exists();
        $devices = Device::query()->forPlatform(DevicePlatform::Windows)->operational()
            ->whereHas('assignments', fn ($query) => $query->active())
            ->with('activeAssignment.user:id,name')->orderBy('display_name')->limit(250)->get();
        $commandsEnabled = app(DeviceManagementSettings::class)->productionCommandsEnabled(fresh: true);

        return view('livewire.devices.device-desktop-clients', compact('clients', 'selected', 'jobs', 'devices', 'commandsEnabled', 'setupBlocked'))
            ->layout('layouts.master', ['area' => $actor->usesAdminLayout() ? 'admin' : 'user']);
    }
}
