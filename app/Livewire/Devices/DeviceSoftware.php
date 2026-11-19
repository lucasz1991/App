<?php

namespace App\Livewire\Devices;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceDesktopJob;
use App\Models\DeviceDesktopSoftwareState;
use App\Models\DeviceSoftwareAssignment;
use App\Models\DeviceSoftwarePackage;
use App\Models\User;
use App\Services\DeviceManagement\Desktop\DeviceSoftwareService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\WorkplaceProfileCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Programmverzeichnis der Geräteverwaltung: Pakete pflegen, zuweisen und den Stand je Gerät sehen. */
class DeviceSoftware extends Component
{
    public bool $showPackageDialog = false;

    #[Locked]
    public ?string $packagePublicId = null;

    public string $name = '';

    public string $packageId = '';

    public string $version = '';

    public string $scope = 'user';

    public bool $agreementsAccepted = false;

    public bool $showAssignDialog = false;

    #[Locked]
    public ?string $assignPublicId = null;

    public string $targetType = DeviceSoftwareAssignment::TARGET_CORPORATE;

    public string $targetProfile = '';

    public string $targetDevice = '';

    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->actor();
    }

    public function openCreate(): void
    {
        $this->directoryActor();
        $this->reset('packagePublicId', 'name', 'packageId', 'version', 'scope', 'agreementsAccepted', 'notice');
        $this->resetValidation();
        $this->showPackageDialog = true;
    }

    public function openEdit(string $publicId): void
    {
        $this->directoryActor();
        $package = $this->package($publicId);
        $this->reset('agreementsAccepted', 'notice');
        $this->resetValidation();
        $this->packagePublicId = $package->public_id;
        $this->name = $package->name;
        $this->packageId = $package->package_id;
        $this->version = $package->version;
        $this->scope = $package->scope;
        $this->showPackageDialog = true;
    }

    public function savePackage(DeviceSoftwareService $software): void
    {
        $actor = $this->directoryActor();
        // Fehler zu Feldern, die zugleich Eigenschaften sind, blieben sonst nach dem Korrigieren stehen.
        $this->resetErrorBag();
        $package = $this->packagePublicId ? $this->package($this->packagePublicId) : null;
        $versionChanged = ! $package || $package->version !== trim($this->version);
        $software->savePackage($package, ['name' => trim($this->name), 'package_id' => trim($this->packageId),
            'version' => trim($this->version), 'scope' => $this->scope, 'agreements_accepted' => $this->agreementsAccepted], $actor);
        $this->showPackageDialog = false;
        $this->reset('agreementsAccepted');
        $this->notice = $package
            ? ($versionChanged ? 'Programm gespeichert. Die neue Version gilt für künftige Installationen; vorhandene Installationen bleiben unverändert.' : 'Programm gespeichert.')
            : 'Programm aufgenommen. Installiert wird erst nach einer Zuweisung.';
    }

    public function toggleActive(string $publicId, DeviceSoftwareService $software): void
    {
        $actor = $this->directoryActor();
        $package = $this->package($publicId);
        $software->setActive($package, ! $package->active, $actor);
        $this->notice = $package->active
            ? 'Programm deaktiviert. Es entstehen keine neuen Aufträge; vorhandene Installationen bleiben.'
            : 'Programm wieder aktiv.';
    }

    public function openAssign(string $publicId): void
    {
        $this->assignActor();
        $this->assignPublicId = $this->package($publicId)->public_id;
        $this->reset('targetType', 'targetProfile', 'targetDevice', 'notice');
        $this->resetValidation();
        $this->showAssignDialog = true;
    }

    public function assign(DeviceSoftwareService $software): void
    {
        $actor = $this->assignActor();
        $target = match ($this->targetType) {
            DeviceSoftwareAssignment::TARGET_PROFILE => $this->targetProfile,
            DeviceSoftwareAssignment::TARGET_DEVICE => $this->targetDevice,
            default => null,
        };
        $software->assign($this->package((string) $this->assignPublicId), $this->targetType, $target, $actor);
        $this->reset('targetProfile', 'targetDevice');
        $this->notice = 'Zuweisung gespeichert. Geräte mit verbundener RailTime-App und erlaubter automatischer Installation erhalten das Programm beim nächsten Abgleich.';
    }

    public function unassign(string $assignmentPublicId, DeviceSoftwareService $software): void
    {
        $actor = $this->assignActor();
        $software->unassign(DeviceSoftwareAssignment::query()->where('public_id', $assignmentPublicId)->firstOrFail(), $actor);
        $this->notice = 'Zuweisung entfernt. Bereits installierte Programme bleiben auf den Geräten.';
    }

    public function retry(int $stateId, DeviceSoftwareService $software): void
    {
        $actor = $this->assignActor();
        $software->retry(DeviceDesktopSoftwareState::query()->findOrFail($stateId), $actor);
        $this->notice = 'Weitere Versuche freigegeben. Der nächste Abgleich des Geräts merkt die Installation erneut vor.';
    }

    private function package(string $publicId): DeviceSoftwarePackage
    {
        return DeviceSoftwarePackage::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function actor(): User
    {
        $user = auth()->user()?->fresh();
        abort_unless($user?->isActive() && $user->email_verified_at !== null, 403);
        Gate::forUser($user)->authorize('devices.view');
        app(DeviceSoftwareService::class)->assertReady();

        return $user;
    }

    private function directoryActor(): User
    {
        $user = $this->actor();
        abort_unless($this->canManageDirectory($user), 403);

        return $user;
    }

    private function assignActor(): User
    {
        $user = $this->actor();
        Gate::forUser($user)->authorize('devices.commands.execute');

        return $user;
    }

    private function canManageDirectory(User $user): bool
    {
        return $user->isSuperAdmin() && Gate::forUser($user)->allows('settings.manage');
    }

    public function render()
    {
        $actor = $this->actor();
        $canManageDirectory = $this->canManageDirectory($actor);
        $canAssign = Gate::forUser($actor)->allows('devices.commands.execute');
        $profiles = collect(WorkplaceProfileCatalog::all())->filter(fn (array $profile): bool => $profile['managed'])
            ->map(fn (array $profile): string => $profile['label']);
        $packages = DeviceSoftwarePackage::query()->with('assignments.device:id,display_name,hostname')
            ->withCount(['states as reported_count', 'states as installed_count' => fn ($query) => $query->where('installed', true)])
            ->orderByDesc('active')->orderBy('name')->limit(200)->get();
        // Die Zeilenvorlagen der Tabelle sehen nur ihr Element, deshalb reisen Anzeigewerte mit ihm.
        foreach ($packages as $package) {
            $package->setAttribute('can_manage', $canManageDirectory);
            $package->setAttribute('can_assign', $canAssign);
            $package->setAttribute('target_labels', $package->assignments->map(fn (DeviceSoftwareAssignment $assignment): string => match ($assignment->target_type) {
                DeviceSoftwareAssignment::TARGET_CORPORATE => 'Alle Firmengeräte',
                DeviceSoftwareAssignment::TARGET_PROFILE => 'Profil: '.($profiles[$assignment->profile_key] ?? $assignment->profile_key),
                default => $assignment->device?->display_name ?: $assignment->device?->hostname ?: 'Gerät entfernt',
            })->all());
        }
        $states = DeviceDesktopSoftwareState::query()
            ->with(['package:id,name,package_id,version,active', 'client.device:id,display_name,hostname', 'client.assignment.user:id,name'])
            ->whereHas('client', fn ($query) => $query->where('status', 'active'))
            ->orderBy('installed')->latest('reported_at')->limit(200)->get();
        $lastJobs = $states->isEmpty() ? collect() : DeviceDesktopJob::query()->where('origin', 'assignment')
            ->whereIn('client_id', $states->pluck('client_id')->unique())->orderByDesc('id')->limit(1000)->get()
            ->unique(fn (DeviceDesktopJob $job): string => $job->client_id.':'.$job->software_package_id)
            ->keyBy(fn (DeviceDesktopJob $job): string => $job->client_id.':'.$job->software_package_id);
        foreach ($states as $state) {
            $state->setRelation('lastJob', $lastJobs->get($state->client_id.':'.$state->software_package_id));
            $state->setAttribute('can_assign', $canAssign);
            $state->setAttribute('max_attempts', DeviceSoftwareService::MAX_ATTEMPTS);
        }
        $assigning = $this->showAssignDialog && $this->assignPublicId ? $packages->firstWhere('public_id', $this->assignPublicId) : null;
        $devices = $assigning ? Device::query()->forPlatform(DevicePlatform::Windows)->operational()
            ->whereIn('ownership', ['corporate', 'byod'])->orderBy('display_name')->limit(250)->get(['id', 'public_id', 'display_name', 'hostname', 'ownership']) : collect();

        return view('livewire.devices.device-software', [
            'packages' => $packages, 'states' => $states, 'profiles' => $profiles, 'devices' => $devices, 'assigning' => $assigning,
            'canManageDirectory' => $canManageDirectory, 'canAssign' => $canAssign,
            'commandsEnabled' => app(DeviceManagementSettings::class)->productionCommandsEnabled(fresh: true),
            'maxAttempts' => DeviceSoftwareService::MAX_ATTEMPTS,
        ])->layout('layouts.master', ['area' => $actor->usesAdminLayout() ? 'admin' : 'user']);
    }
}
