<div>
  @php
    $roleLabel = match ($user->role) {
        'admin' => __('app.role_admin'),
        'staff' => __('app.role_staff'),
        default => __('app.role_user'),
    };
    $roleColor = match ($user->role) {
        'admin' => 'purple',
        'staff' => 'sky',
        default => 'green',
    };
    $lastActivityAt = $user->lastActivityAt();
    $isUserOnline = $user->isOnline();
  @endphp

  <x-ui.page :title="$user->name" :eyebrow="__('app.employees')" :description="$user->email">
    <x-slot:actions>
        <x-ui.dropdown.page-actions width="48">
            @if ($user->status)
                <x-dropdown-link wire:click.prevent="deactivateUser()" :can="'users.edit'" tone="warning">
                    <i class="far fa-times-circle mr-2"></i>
                    {{ __('app.deactivate') }}
                </x-dropdown-link>
            @else
                <x-dropdown-link wire:click.prevent="activateUser()" :can="'users.edit'" tone="success">
                    <i class="far fa-check-circle mr-2"></i>
                    {{ __('app.activate') }}
                </x-dropdown-link>
            @endif

            @can('employees.delete')
                @if ((int) $user->id !== (int) auth()->id() && ! $user->isSuperAdmin())
                    <x-dropdown-link
                        confirm-method="deleteUser"
                        :confirm-title="__('app.delete_user')"
                        :confirm-message="__('app.delete_user_confirm')"
                        :confirm-label="__('app.delete')"
                        tone="danger"
                    >
                        <i class="far fa-trash-alt mr-2"></i>
                        {{ __('app.delete_user') }}
                    </x-dropdown-link>
                @endif
            @endcan
        </x-ui.dropdown.page-actions>
    </x-slot:actions>

    <div class="relative space-y-5" data-autosave-scope>
      <x-ui.autosave-status
          event="employee-profile-field-saved"
          target="savePendingInlineChanges"
          dirty-target="inlineValues"
      />

      @include('livewire.admin.user-profile.partials.identity-card')

    @php
        $employeeProfileTabs = [
            'userDetails' => ['label' => __('app.details'), 'icon' => 'fad fa-id-card'],
            'userNotes' => ['label' => __('app.notes'), 'icon' => 'fad fa-sticky-note'],
            'userFiles' => ['label' => __('app.files'), 'icon' => 'fad fa-folder-open'],
            'userMessages' => ['label' => __('app.messages'), 'icon' => 'fad fa-envelope'],
        ];
        if (auth()->user()->can('devices.view')) {
            $employeeProfileTabs['devices'] = ['label' => 'Geräte', 'icon' => 'fad fa-laptop'];
        }
        if ($canViewMasterData) {
            $employeeProfileTabs['masterData'] = ['label' => __('app.master_data'), 'icon' => 'fad fa-user-lock'];
            $employeeProfileTabs['documents'] = ['label' => __('app.employee_documents'), 'icon' => 'fad fa-folder-check'];
        }
        if ($canViewCompensation) {
            $employeeProfileTabs['compensation'] = ['label' => __('app.compensation_data'), 'icon' => 'fad fa-coins'];
        }
    @endphp

    <x-ui.accordion.tabs
        :tabs="$employeeProfileTabs"
        :collapse-at="'md'"
        default="userDetails"
        persist-key="admin.user.{{ $user->id }}.tabs"
    >
        {{-- TAB: Details — klare zweispaltige Info-Sektionen --}}
        {{-- Wichtig: kein display-Utility (grid) direkt auf dem x-show-Panel,
             sonst gewinnt Tailwinds !important gegen Alpines inline display:none. --}}
        <x-ui.accordion.tab-panel for="userDetails" contentClass="space-y-4">
          <div class="grid gap-4 lg:grid-cols-2" data-anim-stagger>
            {{-- Persoenliche Daten --}}
            <section class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-rt-glow>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                        <i class="far fa-user text-sm"></i>
                    </span>
                    {{ __('app.personal_data') }}
                </h3>
                <dl class="mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                    <div class="flex items-start justify-between gap-4 py-2.5">
                        <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.username') }}</dt>
                        <dd class="min-w-0 flex-1">
                            <x-ui.inline-edit-field id="employee-details-name" field="name" :can-edit="$canEditEmployee">
                                {{ $user->name }}
                            </x-ui.inline-edit-field>
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-2.5">
                        <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.birth_date') }}</dt>
                        <dd class="min-w-0 flex-1">
                            <x-ui.inline-edit-field id="employee-details-birth-date" field="birth_date" type="date" :can-edit="$canEditEmployee">
                                {{ $profile?->birth_date?->format('d.m.Y') ?: __('app.not_set') }}
                            </x-ui.inline-edit-field>
                        </dd>
                    </div>
                    @if ($canViewMasterData)
                        <div class="flex items-start justify-between gap-4 py-2.5">
                            <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.personnel_nr') }}</dt>
                            <dd class="min-w-0 flex-1">
                                <x-ui.inline-edit-field id="employee-details-personnel-nr" field="personnel_nr" :can-edit="$canEditMasterData">
                                    {{ $profile?->personnel_nr ?: __('app.not_set') }}
                                </x-ui.inline-edit-field>
                            </dd>
                        </div>
                    @endif
                    <div class="flex items-start justify-between gap-4 py-2.5">
                        <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.registered_at') }}</dt>
                        <dd class="pt-2 text-right text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $user->created_at->format('d.m.Y') }}</dd>
                    </div>
                </dl>
            </section>

            {{-- Kontakt & Anschrift --}}
            <section class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-rt-glow>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                        <i class="far fa-address-card text-sm"></i>
                    </span>
                    {{ __('app.contact') }}
                </h3>
                <dl class="mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                    @foreach ([
                        ['email', 'email', 'email', 'email'],
                        ['phone', 'phone', 'text', 'tel'],
                        ['mobile', 'mobile', 'text', 'tel'],
                        ['street', 'street', 'text', 'street-address'],
                        ['postal_code', 'postal_code', 'text', 'postal-code'],
                        ['city', 'city', 'text', 'address-level2'],
                        ['country', 'country', 'text', 'country-name'],
                    ] as [$field, $label, $type, $autocomplete])
                        @php
                            $value = $field === 'email' ? $user->email : $profile?->{$field};
                        @endphp
                        <div class="flex items-start justify-between gap-4 py-2.5">
                            <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.'.$label) }}</dt>
                            <dd class="min-w-0 flex-1">
                                <x-ui.inline-edit-field
                                    :id="'employee-contact-'.$field"
                                    :field="$field"
                                    :type="$type"
                                    :autocomplete="$autocomplete"
                                    :can-edit="$canEditEmployee"
                                >
                                    <span class="break-words">{{ $value ?: __('app.not_set') }}</span>
                                    @if ($field === 'email')
                                        @if ($user->email_verified_at)
                                            <i class="far fa-check-circle shrink-0 text-emerald-500 dark:text-emerald-400" title="{{ __('app.email_verified') }}"></i>
                                        @else
                                            <i class="far fa-exclamation-circle shrink-0 text-amber-500 dark:text-amber-400" title="{{ __('app.email_not_verified') }}"></i>
                                        @endif
                                    @endif
                                </x-ui.inline-edit-field>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
          </div>
        </x-ui.accordion.tab-panel>

        {{-- TAB: Bemerkungen --}}
        <x-ui.accordion.tab-panel for="userNotes" contentClass="space-y-4" panelClass="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 z-10 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <livewire:admin.user-profile.user-notes :user-id="$user->id" :key="'user-notes-'.$user->id" />
        </x-ui.accordion.tab-panel>

        {{-- TAB: Dateien --}}
        <x-ui.accordion.tab-panel for="userFiles" contentClass="space-y-4" panelClass="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 z-10 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <livewire:tools.file-pools.manage-file-pools
                :model-type="\App\Models\User::class"
                :model-id="$user->id"
                :read-only="false"
                :key="'user-files-'.$user->id"
            />
        </x-ui.accordion.tab-panel>

        {{-- TAB: Nachrichten --}}
        <x-ui.accordion.tab-panel for="userMessages" contentClass="space-y-4" panelClass="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 z-10 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            @if (class_exists(\App\Livewire\Admin\UserProfile\UserMessages::class))
                <livewire:admin.user-profile.user-messages :user-id="$user->id" :key="'user-messages-'.$user->id" />
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <i class="fad fa-info-circle mr-2"></i>
                    {{ __('app.module_not_available') }}
                </div>
            @endif
        </x-ui.accordion.tab-panel>

        @can('devices.view')
            <x-ui.accordion.tab-panel for="devices" contentClass="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    <div>
                        <h3 class="text-sm font-semibold text-rt-text dark:text-white">Zugewiesene Geräte und Historie</h3>
                        <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">Inventar, Übergabe, Einrichtungsstatus und letzte Synchronisierung sind mit diesem Mitarbeiter verknüpft.</p>
                    </div>
                    <a href="{{ route(auth()->user()->usesAdminLayout() ? 'admin.devices' : 'devices.index') }}" wire:navigate class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-rt-red px-3 text-xs font-semibold text-white">
                        <i class="far fa-laptop"></i> Geräteverwaltung öffnen
                    </a>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    @forelse($deviceAssignments as $deviceAssignment)
                        @php
                            $assignedDevice = $deviceAssignment->device;
                            $checks = $assignedDevice?->readinessChecks?->keyBy('check_key') ?? collect();
                            $requiredKeys = array_keys(\App\Services\DeviceManagement\DeviceReadinessService::REQUIRED_CHECKS);
                            $ready = collect($requiredKeys)->every(fn($key) => in_array($checks->get($key)?->status, ['passed','not_applicable'], true));
                        @endphp
                        <article class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"><i class="far {{ in_array($assignedDevice?->form_factor, ['phone','tablet']) ? 'fa-mobile-alt' : 'fa-laptop' }}"></i></span>
                                    <div class="min-w-0"><h4 class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $assignedDevice?->display_name ?: $assignedDevice?->hostname ?: 'Gelöschtes Gerät' }}</h4><p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $assignedDevice?->asset_tag ?: $assignedDevice?->serial_number }} · {{ $deviceAssignment->assigned_at?->format('d.m.Y') }}</p></div>
                                </div>
                                <span class="rounded-full px-2 py-1 text-[11px] font-semibold {{ $deviceAssignment->status === 'active' ? ($ready ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800') : 'bg-slate-100 text-slate-700' }}">{{ $deviceAssignment->status === 'active' ? ($ready ? 'bereit' : 'Einrichtung offen') : 'zurückgegeben' }}</span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div class="rounded-lg bg-rt-surface-muted px-3 py-2 dark:bg-rt-dark-surface-muted"><dt class="text-rt-muted dark:text-rt-dark-muted">Standort</dt><dd class="mt-1 font-medium text-rt-text dark:text-white">{{ $assignedDevice?->declared_location ?: 'Nicht gemeldet' }}</dd></div>
                                <div class="rounded-lg bg-rt-surface-muted px-3 py-2 dark:bg-rt-dark-surface-muted"><dt class="text-rt-muted dark:text-rt-dark-muted">Letzter Sync</dt><dd class="mt-1 font-medium text-rt-text dark:text-white">{{ $assignedDevice?->last_synced_at?->diffForHumans() ?? 'Noch nie' }}</dd></div>
                            </dl>
                            @if($assignedDevice)
                                <a href="{{ route(auth()->user()->usesAdminLayout() ? 'admin.devices' : 'devices.index', ['device' => $assignedDevice->public_id]) }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-rt-red hover:underline">Gerätedetail öffnen</a>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-rt-border p-8 text-center text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted lg:col-span-2">Diesem Mitarbeiter wurde noch kein Gerät zugewiesen.</div>
                    @endforelse
                </div>
            </x-ui.accordion.tab-panel>
        @endcan

        @if ($canViewMasterData)
            <x-ui.accordion.tab-panel for="masterData" contentClass="space-y-4">
                @include('livewire.admin.user-profile.partials.master-data', ['profile' => $profile])
            </x-ui.accordion.tab-panel>
            <x-ui.accordion.tab-panel for="documents" contentClass="space-y-4">
                <livewire:admin.user-profile.employee-documents :user-id="$user->id" :key="'employee-documents-'.$user->id" />
            </x-ui.accordion.tab-panel>
        @endif

        @if ($canViewCompensation)
            <x-ui.accordion.tab-panel for="compensation" contentClass="space-y-4">
                @include('livewire.admin.user-profile.partials.compensation-data', ['profile' => $profile])
            </x-ui.accordion.tab-panel>
        @endif
    </x-ui.accordion.tabs>
    </div>
  </x-ui.page>

    {{-- Compose-Modal fuer den Nachrichten-Tab --}}
    @can('users.messages.create')
        <livewire:admin.users.messages.message-form :key="'profile-message-form'" />
    @endcan
</div>
