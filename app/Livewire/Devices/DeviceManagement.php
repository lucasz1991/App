<?php

namespace App\Livewire\Devices;

use App\Enums\AccountProvider;
use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Notifications\DeviceEnrollmentInvitation;
use App\Services\DeviceManagement\DeviceAccountPreparationService;
use App\Services\DeviceManagement\DeviceArtifactService;
use App\Services\DeviceManagement\DeviceCommandService;
use App\Services\DeviceManagement\DeviceEnrollmentModeCatalog;
use App\Services\DeviceManagement\DeviceEnrollmentService;
use App\Services\DeviceManagement\DeviceInventoryImportService;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProviderRegistry;
use App\Services\DeviceManagement\DeviceProviderLinkService;
use App\Services\DeviceManagement\DeviceReadinessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class DeviceManagement extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** @var array<string, array<string, mixed>> */
    protected $queryString = [
        'selectedDevicePublicId' => ['as' => 'device', 'except' => null],
    ];

    public string $search = '';

    public string $platformFilter = '';

    public string $formFactorFilter = '';

    public string $lifecycleFilter = '';

    public string $complianceFilter = '';

    public string $locationFilter = '';

    public int $perPage = 15;

    public ?string $selectedDevicePublicId = null;

    public bool $showCreateForm = false;

    public bool $showProviderReadiness = false;

    public bool $showProviderLinkForm = false;

    public string $captureMode = 'manual';

    /** @var array{provider:string,role:string,external_device_id:string} */
    public array $providerLinkForm = [
        'provider' => '',
        'role' => 'support',
        'external_device_id' => '',
    ];

    /** @var array<string, mixed> */
    public array $deviceForm = [
        'asset_tag' => '',
        'serial_number' => '',
        'display_name' => '',
        'hostname' => '',
        'form_factor' => 'laptop',
        'platform' => 'windows',
        'ownership' => 'corporate',
        'manufacturer' => '',
        'model' => '',
        'os_version' => '',
        'declared_location' => '',
        'primary_provider' => '',
    ];

    public ?int $assignmentUserId = null;

    public string $assignmentNote = '';

    public string $returnLocation = '';

    public string $enrollmentProvider = '';

    public string $enrollmentMode = 'agent';

    /** @var array<int, string> */
    public array $accountProviders = ['microsoft_365', 'google_workspace'];

    public string $commandProvider = '';

    public string $commandType = 'sync';

    public string $commandJustification = '';

    public ?int $commandArtifactId = null;

    public mixed $artifactUpload = null;

    public mixed $inventoryImport = null;

    /** @var array<string, int> */
    public array $lastImportSummary = [];

    public function mount(): void
    {
        Gate::authorize('devices.view');

        if ($this->selectedDevicePublicId) {
            $this->selectDevice($this->selectedDevicePublicId);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPlatformFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFormFactorFilter(): void
    {
        $this->resetPage();
    }

    public function updatedLifecycleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedComplianceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedLocationFilter(): void
    {
        $this->resetPage();
    }

    public function filterByLocation(string $location): void
    {
        Gate::authorize('devices.view');

        $this->locationFilter = trim($location);
        $this->resetPage();
    }

    public function openProviderReadiness(): void
    {
        Gate::authorize('devices.view');
        $this->showProviderReadiness = true;
    }

    public function closeProviderReadiness(): void
    {
        $this->showProviderReadiness = false;
    }

    public function openProviderLink(): void
    {
        Gate::authorize('devices.manage');
        $device = $this->selectedDeviceOrFail();
        $registry = app(DeviceProviderRegistry::class);
        $settings = app(DeviceManagementSettings::class);
        $platform = $device->platform instanceof DevicePlatform
            ? $device->platform->value
            : (string) $device->platform;
        $compatible = collect(array_keys($settings->providerCatalog()))
            ->reject(fn (string $key): bool => $key === 'simulation')
            ->filter(function (string $key) use ($registry, $platform): bool {
                try {
                    $provider = $registry->get($key);

                    return $provider->enabled() && $provider->supportsPlatform($platform);
                } catch (Throwable) {
                    return false;
                }
            })
            ->values();
        $providerKey = (string) ($compatible->first(fn (string $key): bool => $key === 'meshcentral')
            ?? $compatible->first()
            ?? '');
        $existing = $providerKey !== '' ? $device->providerLinkFor($providerKey) : null;

        $this->providerLinkForm = [
            'provider' => $providerKey,
            'role' => $existing?->role
                ?? ($providerKey === strtolower((string) $device->primary_provider) ? 'primary' : 'support'),
            'external_device_id' => (string) ($existing?->external_device_id ?? ''),
        ];
        $this->resetValidation(['providerLinkForm.provider', 'providerLinkForm.role', 'providerLinkForm.external_device_id']);
        $this->showProviderLinkForm = true;
    }

    public function closeProviderLink(): void
    {
        $this->showProviderLinkForm = false;
        $this->providerLinkForm = ['provider' => '', 'role' => 'support', 'external_device_id' => ''];
        $this->resetValidation(['providerLinkForm.provider', 'providerLinkForm.role', 'providerLinkForm.external_device_id']);
    }

    public function saveProviderLink(DeviceProviderLinkService $links): void
    {
        Gate::authorize('devices.manage');

        try {
            $links->link(
                $this->selectedDeviceOrFail(),
                (string) ($this->providerLinkForm['provider'] ?? ''),
                (string) ($this->providerLinkForm['role'] ?? ''),
                (string) ($this->providerLinkForm['external_device_id'] ?? ''),
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            $mapped = [];
            foreach ($exception->errors() as $field => $messages) {
                $mapped['providerLinkForm.'.$field] = $messages;
            }

            throw ValidationException::withMessages($mapped);
        }

        $this->closeProviderLink();
        $this->dispatch('swal:toast', type: 'success', text: 'Provider-Geräte-ID wurde nachvollziehbar verknüpft.');
    }

    public function clearFilters(): void
    {
        Gate::authorize('devices.view');

        $this->reset(['search', 'platformFilter', 'formFactorFilter', 'lifecycleFilter', 'complianceFilter', 'locationFilter']);
        $this->resetPage();
    }

    public function updatedCommandProvider(): void
    {
        try {
            $commands = (array) (app(DeviceProviderRegistry::class)
                ->get($this->commandProvider)
                ->capabilities()['commands'] ?? []);
        } catch (Throwable) {
            $commands = [];
        }

        if (! in_array($this->commandType, $commands, true)) {
            $this->commandType = (string) ($commands[0] ?? '');
        }
    }

    public function updatedEnrollmentProvider(): void
    {
        if (! $this->selectedDevicePublicId) {
            $this->enrollmentMode = '';

            return;
        }

        $device = $this->selectedDeviceOrFail();
        $this->enrollmentMode = app(DeviceEnrollmentModeCatalog::class)
            ->defaultFor($device, $this->enrollmentProvider) ?? '';
        $this->resetValidation(['enrollmentProvider', 'enrollmentMode']);
    }

    public function openCreate(string $mode = 'manual'): void
    {
        Gate::authorize('devices.manage');
        $this->setCaptureMode($mode);
        $this->inventoryImport = null;
        $this->lastImportSummary = [];
        $this->resetValidation();
        $this->showCreateForm = true;
    }

    public function setCaptureMode(string $mode): void
    {
        Gate::authorize('devices.manage');

        if (! in_array($mode, ['manual', 'import'], true)) {
            throw ValidationException::withMessages([
                'captureMode' => 'Diese Erfassungsart ist nicht verfügbar.',
            ]);
        }

        $this->captureMode = $mode;
        $this->resetValidation();
    }

    public function closeCreate(): void
    {
        $this->showCreateForm = false;
        $this->resetCaptureModalState();
    }

    public function updatedShowCreateForm(bool $visible): void
    {
        if (! $visible) {
            $this->resetCaptureModalState();
        }
    }

    private function resetCaptureModalState(): void
    {
        $this->captureMode = 'manual';
        $this->inventoryImport = null;
        $this->lastImportSummary = [];
        $this->resetValidation();
    }

    public function createDevice(DeviceInventoryService $inventory): void
    {
        Gate::authorize('devices.manage');

        try {
            $device = $inventory->create($this->deviceForm, auth()->user());
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $field => $fieldMessages) {
                $messages[str_starts_with($field, 'deviceForm.') ? $field : 'deviceForm.'.$field] = $fieldMessages;
            }

            throw ValidationException::withMessages($messages);
        }

        $this->selectedDevicePublicId = $device->public_id;
        $this->showCreateForm = false;
        $this->captureMode = 'manual';
        $this->deviceForm = [
            'asset_tag' => '',
            'serial_number' => '',
            'display_name' => '',
            'hostname' => '',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'manufacturer' => '',
            'model' => '',
            'os_version' => '',
            'declared_location' => '',
            'primary_provider' => '',
        ];

        $this->dispatch('swal:toast', type: 'success', text: 'Gerät wurde im virtuellen Lager erfasst.');
    }

    public function selectDevice(string $publicId): void
    {
        Gate::authorize('devices.view');
        $device = Device::query()->where('public_id', $publicId)->firstOrFail();
        $this->selectedDevicePublicId = $device->public_id;
        $this->returnLocation = (string) $device->declared_location;
        $this->assignmentUserId = $device->activeAssignment()->value('user_id');
        $this->setCompatibleProviderDefaults($device);
        $this->resetValidation();
    }

    public function selectDeviceById(int $deviceId): void
    {
        Gate::authorize('devices.view');

        $publicId = Device::query()->whereKey($deviceId)->value('public_id');
        abort_unless(is_string($publicId) && $publicId !== '', 404);

        $this->selectDevice($publicId);
    }

    public function closeDevice(): void
    {
        $this->selectedDevicePublicId = null;
        $this->assignmentUserId = null;
        $this->artifactUpload = null;
        $this->showProviderLinkForm = false;
        $this->resetValidation();
    }

    public function assignDevice(DeviceInventoryService $inventory): void
    {
        Gate::authorize('devices.assign');
        $device = $this->selectedDeviceOrFail();
        $validated = $this->validate([
            'assignmentUserId' => ['required', 'integer', 'exists:users,id'],
            'assignmentNote' => ['nullable', 'string', 'max:2000'],
        ]);
        $employee = User::query()->whereKey($validated['assignmentUserId'])->where('status', true)->firstOrFail();

        $inventory->assign($device, $employee, auth()->user(), $validated['assignmentNote'] ?: null);
        $this->dispatch('swal:toast', type: 'success', text: 'Gerät wurde dem Mitarbeiter zugewiesen.');
    }

    public function returnDevice(DeviceInventoryService $inventory): void
    {
        Gate::authorize('devices.assign');
        $this->validate([
            'returnLocation' => ['nullable', 'string', 'max:191'],
            'assignmentNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $inventory->returnToInventory(
            $this->selectedDeviceOrFail(),
            auth()->user(),
            $this->returnLocation ?: null,
            $this->assignmentNote ?: null,
        );
        $this->assignmentUserId = null;
        $this->dispatch('swal:toast', type: 'success', text: 'Gerät ist wieder im virtuellen Lager.');
    }

    public function confirmHandover(DeviceInventoryService $inventory): void
    {
        $inventory->confirmHandover($this->selectedDeviceOrFail(), auth()->user());
        $this->dispatch('swal:toast', type: 'success', text: 'Übergabe wurde nachvollziehbar bestätigt.');
    }

    public function createEnrollment(DeviceEnrollmentService $enrollments): void
    {
        Gate::authorize('devices.enrollment.manage');
        $device = $this->selectedDeviceOrFail();
        $assignment = $device->activeAssignment()->with('user')->first();
        if (! $assignment?->user) {
            throw ValidationException::withMessages([
                'enrollmentProvider' => 'Vor der Einladung muss das Gerät einem aktiven Mitarbeiter zugewiesen sein.',
            ]);
        }

        $this->validate([
            'enrollmentProvider' => ['required', 'string', 'max:64'],
            'enrollmentMode' => ['required', Rule::in(['agent', 'work_profile', 'profile', 'ade', 'fully_managed'])],
        ]);

        try {
            $invitation = $enrollments->invite(
                $device,
                $assignment->user,
                $this->enrollmentProvider,
                $this->enrollmentMode,
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $field => $fieldMessages) {
                $messages[match ($field) {
                    'mode' => 'enrollmentMode',
                    'provider', 'assignee' => 'enrollmentProvider',
                    default => $field,
                }] = $fieldMessages;
            }

            throw ValidationException::withMessages($messages);
        }
        $invitation->enrollment->loadMissing('device');
        $assignment->user->notify(new DeviceEnrollmentInvitation(
            $invitation->enrollment,
            $invitation->plainToken,
        ));

        activity('device-management')
            ->causedBy(auth()->user())
            ->performedOn($invitation->enrollment)
            ->withProperties([
                'device_public_id' => $device->public_id,
                'recipient_user_id' => $assignment->user_id,
                'expires_at' => $invitation->enrollment->expires_at?->toIso8601String(),
            ])
            ->log('device_enrollment_email_sent');

        $this->dispatch('swal:toast', type: 'success', text: 'Persönliche Einrichtungs-E-Mail wurde versendet.');
    }

    public function prepareAccounts(DeviceAccountPreparationService $accounts): void
    {
        Gate::authorize('devices.accounts.manage');
        $device = $this->selectedDeviceOrFail();
        $assignment = $device->activeAssignment()->with('user')->first();
        if (! $assignment?->user) {
            throw ValidationException::withMessages([
                'accountProviders' => 'Konten können erst nach einer Mitarbeiterzuweisung vorbereitet werden.',
            ]);
        }

        $this->validate([
            'accountProviders' => ['required', 'array', 'min:1'],
            'accountProviders.*' => [Rule::enum(AccountProvider::class)],
        ]);

        $accounts->prepare($device, $assignment->user, auth()->user(), $this->accountProviders);
        $this->dispatch('swal:toast', type: 'success', text: 'Microsoft-/Google-/Apple-Sollprofile wurden ohne Passwörter vorbereitet.');
    }

    public function refreshReadiness(DeviceReadinessService $readiness): void
    {
        Gate::authorize('devices.manage');
        $readiness->refresh($this->selectedDeviceOrFail(), auth()->user());
        $this->dispatch('swal:toast', type: 'success', text: 'Bereitschaft wurde aus belegbaren Zuständen neu bewertet.');
    }

    public function uploadArtifact(DeviceArtifactService $artifacts): void
    {
        Gate::authorize('devices.commands.execute');
        $this->validate([
            'artifactUpload' => [
                'required',
                'file',
                'max:'.app(DeviceManagementSettings::class)->artifactMaxKilobytes(),
                'mimes:ps1,bat,cmd,sh,msi,exe,pkg,dmg,apk,mobileconfig,pdf,txt,csv,xlsx,xls,docx,pptx,zip',
            ],
        ]);

        $artifact = $artifacts->store($this->selectedDeviceOrFail(), $this->artifactUpload, auth()->user());
        $this->artifactUpload = null;
        $this->commandArtifactId = $artifact->id;
        $this->dispatch('swal:toast', type: 'success', text: 'Datei wurde privat gespeichert und mit SHA-256 erfasst.');
    }

    public function importInventory(DeviceInventoryImportService $imports): void
    {
        Gate::authorize('devices.manage');
        $this->validate([
            'inventoryImport' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);

        $this->lastImportSummary = $imports->import($this->inventoryImport, auth()->user());
        $this->inventoryImport = null;
        $this->dispatch(
            'swal:toast',
            type: 'success',
            text: $this->lastImportSummary['rows'].' Gerätezeilen wurden geprüft und übernommen.',
        );
    }

    public function approveArtifact(int $artifactId, DeviceArtifactService $artifacts): void
    {
        $device = $this->selectedDeviceOrFail();
        $artifact = $device->artifacts()->whereKey($artifactId)->firstOrFail();
        $artifacts->approve($artifact, auth()->user());
        $this->dispatch('swal:toast', type: 'success', text: 'Artefakt ist für eine protokollierte Geräteaktion freigegeben.');
    }

    public function requestCommand(DeviceCommandService $commands): void
    {
        Gate::authorize('devices.commands.execute');
        $validated = $this->validate([
            'commandProvider' => ['required', 'string', 'max:64'],
            'commandType' => ['required', Rule::enum(DeviceCommandType::class)],
            'commandJustification' => ['required', 'string', 'min:10', 'max:2000'],
            'commandArtifactId' => ['nullable', 'integer'],
        ]);

        $device = $this->selectedDeviceOrFail();
        $type = DeviceCommandType::from($validated['commandType']);
        $payload = [];
        if (in_array($type, [DeviceCommandType::ExecuteScript, DeviceCommandType::InstallSoftware], true)) {
            $artifact = $device->artifacts()
                ->whereKey($validated['commandArtifactId'])
                ->whereNotNull('approved_at')
                ->first();
            if (! $artifact) {
                throw ValidationException::withMessages([
                    'commandArtifactId' => 'Für diese Aktion ist ein geprüftes Artefakt erforderlich.',
                ]);
            }
            $payload = [
                'artifact_public_id' => $artifact->public_id,
                'artifact_sha256' => $artifact->sha256,
                'artifact_kind' => $artifact->kind,
            ];
        } elseif ($type === DeviceCommandType::ApplyProfile) {
            $profileAssignments = $device->accountAssignments()
                ->with(['provisioningProfile', 'identityAccount'])
                ->whereIn('status', ['pending', 'pending_provider', 'queued', 'error'])
                ->get();
            if ($profileAssignments->isEmpty()) {
                throw ValidationException::withMessages([
                    'commandType' => 'Es gibt noch kein vorbereitetes Microsoft-/Google-/Apple-Sollprofil.',
                ]);
            }

            $payload = [
                'profiles' => $profileAssignments->map(fn ($assignment): array => [
                    'assignment_id' => $assignment->id,
                    'profile_public_id' => $assignment->provisioningProfile?->public_id,
                    'profile_version' => $assignment->provisioningProfile?->version,
                    'identity_provider' => $assignment->identityAccount?->provider?->value,
                    'principal' => $assignment->identityAccount?->principal,
                ])->values()->all(),
            ];
        }

        $command = $commands->request(
            $device,
            $validated['commandProvider'],
            $type,
            auth()->user(),
            $validated['commandJustification'],
            $payload,
        );

        $this->commandJustification = '';
        $message = $command->status === DeviceCommandStatus::PendingApproval
            ? 'Fernlöschung wartet auf einen zweiten Administrator.'
            : 'Geräteaktion wurde sicher in die Warteschlange gestellt.';
        $this->dispatch('swal:toast', type: 'success', text: $message);
    }

    public function approveWipe(string $commandPublicId, DeviceCommandService $commands): void
    {
        Gate::authorize('devices.wipe');
        $command = DeviceCommand::query()
            ->where('public_id', $commandPublicId)
            ->where('device_id', $this->selectedDeviceOrFail()->id)
            ->firstOrFail();
        $commands->approveWipe($command, auth()->user());
        $this->dispatch('swal:toast', type: 'success', text: 'Vier-Augen-Freigabe erteilt; Fernlöschung wurde eingereiht.');
    }

    public function openRemoteSupport(string $providerKey, DeviceProviderRegistry $providers): mixed
    {
        Gate::authorize('devices.support');
        $device = $this->selectedDeviceOrFail();

        try {
            $provider = $providers->get($providerKey);
            if (! $provider->enabled()
                || ! $provider->supportsPlatform($device->platform->value)
                || ! ($provider->capabilities()['remote_support'] ?? false)) {
                throw ValidationException::withMessages([
                    'commandProvider' => 'Für dieses Gerät ist kein belegter Fernsupport verfügbar.',
                ]);
            }

            $url = $provider->remoteSupportUrl($device);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'commandProvider' => 'Der Fernsupport-Link ist nicht sicher oder nicht vollständig konfiguriert.',
            ]);
        }

        if (! $url) {
            throw ValidationException::withMessages([
                'commandProvider' => 'Für diesen Provider fehlt eine sichere Fernsupport-URL.',
            ]);
        }

        activity('device-management')
            ->causedBy(auth()->user())
            ->performedOn($device)
            ->withProperties([
                'device_public_id' => $device->public_id,
                'provider' => $providerKey,
            ])
            ->log('device_remote_support_opened');

        return redirect()->away($url);
    }

    public function render(
        DeviceProviderRegistry $providers,
        DeviceManagementSettings $settings,
        DeviceEnrollmentModeCatalog $enrollmentModes,
    ) {
        Gate::authorize('devices.view');

        $devices = $this->deviceQuery()->paginate(min(max($this->perPage, 5), 100));
        $selectedDeviceRelations = [
            'activeAssignment.user:id,name,email',
            'assignments.user:id,name,email',
            'accountAssignments.identityAccount',
            'accountAssignments.provisioningProfile',
            'enrollments.user:id,name,email',
            'readinessChecks',
            'providerLinks',
        ];
        if (Gate::allows('devices.commands.execute')) {
            $selectedDeviceRelations = array_merge($selectedDeviceRelations, [
                'artifacts.uploader:id,name',
                'artifacts.approver:id,name',
            ]);
        }
        if (Gate::allows('devices.audit.view')) {
            $selectedDeviceRelations = array_merge($selectedDeviceRelations, [
                'commands.requester:id,name',
                'commands.approver:id,name',
            ]);
        }

        $selectedDevice = $this->selectedDevicePublicId
            ? $this->selectedDeviceOrFail()->load($selectedDeviceRelations)
            : null;

        $productionCommandsEnabled = $settings->productionCommandsEnabled();
        $providerCards = collect($settings->providerCatalog())->map(function (array $config, string $key) use ($productionCommandsEnabled, $providers, $settings): array {
            $provider = $providers->get($key);
            $runtime = $settings->providerRuntime($key);

            return [
                'key' => $key,
                'label' => $provider->label(),
                'enabled' => $provider->enabled(),
                'capabilities' => $provider->capabilities(),
                'commands_enabled' => $provider->enabled() && ($key === 'simulation'
                    ? app()->environment(['local', 'testing'])
                    : $providers->commandsEnabledFor($key)),
                'remote_url_available' => filled($runtime['remote_url_template'] ?? null),
            ];
        })->values();

        $enrollmentModeOptions = $selectedDevice && $this->enrollmentProvider !== ''
            ? $enrollmentModes->optionsFor($selectedDevice, $this->enrollmentProvider)
            : [];
        $enrollmentProviderKeys = $selectedDevice
            ? $providerCards
                ->filter(fn (array $provider): bool => (bool) ($provider['capabilities']['enrollment'] ?? false)
                    && $enrollmentModes->optionsFor($selectedDevice, $provider['key']) !== [])
                ->pluck('key')
                ->all()
            : [];

        $locations = Device::query()
            ->whereNotNull('declared_location')
            ->where('declared_location', '!=', '')
            ->distinct()
            ->orderBy('declared_location')
            ->pluck('declared_location');

        $locationStats = Device::query()
            ->select('declared_location')
            ->selectRaw('COUNT(*) as device_count')
            ->whereNotNull('declared_location')
            ->where('declared_location', '!=', '')
            ->groupBy('declared_location')
            ->orderByDesc('device_count')
            ->orderBy('declared_location')
            ->limit(8)
            ->get();

        return view('livewire.devices.device-management', [
            'devices' => $devices,
            'selectedDevice' => $selectedDevice,
            'providerCards' => $providerCards,
            'productionCommandsEnabled' => $productionCommandsEnabled,
            'enrollmentModeOptions' => $enrollmentModeOptions,
            'enrollmentProviderKeys' => $enrollmentProviderKeys,
            'employees' => User::query()
                ->where('status', true)
                ->whereIn('role', ['admin', 'staff'])
                ->where('id', '!=', 1)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'locations' => $locations,
            'locationStats' => $locationStats,
            'stats' => [
                'total' => Device::query()->count(),
                'assigned' => Device::query()->whereHas('activeAssignment')->count(),
                'inventory' => Device::query()->where('lifecycle_status', 'inventory')->count(),
                'attention' => Device::query()->where(function (Builder $query): void {
                    $query->whereIn('compliance_status', ['warning', 'non_compliant'])
                        ->orWhere('management_status', 'error');
                })->count(),
            ],
        ])->layout('layouts.master', ['area' => auth()->user()->usesAdminLayout() ? 'admin' : 'user']);
    }

    private function deviceQuery(): Builder
    {
        $search = trim($this->search);

        return Device::query()
            ->with(['activeAssignment.user:id,name,email'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $nested) use ($like): void {
                    $nested->where('asset_tag', 'like', $like)
                        ->orWhere('serial_number', 'like', $like)
                        ->orWhere('display_name', 'like', $like)
                        ->orWhere('hostname', 'like', $like)
                        ->orWhereHas('activeAssignment.user', fn (Builder $user) => $user
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like));
                });
            })
            ->when($this->platformFilter !== '', fn (Builder $query) => $query->where('platform', $this->platformFilter))
            ->when($this->formFactorFilter !== '', fn (Builder $query) => $query->where('form_factor', $this->formFactorFilter))
            ->when($this->lifecycleFilter !== '', fn (Builder $query) => $query->where('lifecycle_status', $this->lifecycleFilter))
            ->when($this->complianceFilter !== '', fn (Builder $query) => $query->where('compliance_status', $this->complianceFilter))
            ->when($this->locationFilter !== '', fn (Builder $query) => $query->where('declared_location', $this->locationFilter))
            ->latest('updated_at');
    }

    private function selectedDeviceOrFail(): Device
    {
        Gate::authorize('devices.view');

        return Device::query()->where('public_id', $this->selectedDevicePublicId)->firstOrFail();
    }

    private function setCompatibleProviderDefaults(Device $device): void
    {
        $platform = $device->platform instanceof DevicePlatform
            ? $device->platform->value
            : (string) $device->platform;
        $registry = app(DeviceProviderRegistry::class);
        $settings = app(DeviceManagementSettings::class);
        $modeCatalog = app(DeviceEnrollmentModeCatalog::class);
        $compatible = collect($settings->providerCatalog())
            ->map(fn (array $definition, string $key): array => [
                'key' => $key,
                'provider' => $registry->get($key),
            ])
            ->filter(fn (array $entry): bool => $entry['provider']->enabled()
                && $entry['provider']->supportsPlatform($platform));

        $enrollment = $compatible->first(fn (array $entry): bool => (bool) ($entry['provider']->capabilities()['enrollment'] ?? false)
            && $modeCatalog->optionsFor($device, $entry['key']) !== []);
        $command = $compatible->first(fn (array $entry): bool => ($entry['provider']->capabilities()['commands'] ?? []) !== []);
        $this->enrollmentProvider = (string) ($enrollment['key'] ?? '');
        $this->commandProvider = (string) ($command['key'] ?? '');
        $this->updatedCommandProvider();
        $this->enrollmentMode = $this->enrollmentProvider !== ''
            ? ($modeCatalog->defaultFor($device, $this->enrollmentProvider) ?? '')
            : '';
    }
}
