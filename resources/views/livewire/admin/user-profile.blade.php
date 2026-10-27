<div class="employee-workspace employee-profile" data-profile-shell data-employee-profile-entry="{{ \App\Support\Operations\OperationsPages::url('people') }}">
  @php
    $roleLabel = match ($user->role) {
        'superadmin' => __('app.settings_superadmin'),
        'admin' => __('app.role_admin'),
        'staff' => __('app.role_staff'),
        default => __('app.role_user'),
    };
    $roleColor = 'slate';
    $lastActivityAt = $user->lastActivityAt();
    $isUserOnline = $user->isOnline();
    $profileGroups = collect($employeeProfileTabs)->groupBy(fn ($definition) => $definition['group'] ?? 'Profil & Stammdaten', preserveKeys: true);
    $profileGroups = collect(['Profil & Stammdaten', 'Betrieb', 'Personalakte', 'Entwicklung & Aufgaben', 'Arbeitszeit & Regeln'])
        ->filter(fn ($group) => $profileGroups->has($group))->mapWithKeys(fn ($group) => [$group => $profileGroups[$group]]);
    $profileGroup = $employeeProfileTabs[$profileTab]['group'] ?? 'Profil & Stammdaten';
    $profileGroupLabels = [
        'Betrieb' => ['label' => 'Betrieb', 'icon' => 'far fa-train'],
        'Profil & Stammdaten' => ['label' => 'Profil', 'icon' => 'far fa-user'],
        'Personalakte' => ['label' => 'Personalakte', 'icon' => 'far fa-folder-open'],
        'Entwicklung & Aufgaben' => ['label' => 'Entwicklung', 'icon' => 'far fa-graduation-cap'],
        'Arbeitszeit & Regeln' => ['label' => 'Arbeitszeit & Regeln', 'icon' => 'far fa-clock'],
    ];
    $profilePrefix = 'employee-profile-'.$user->id;
  @endphp

  <x-dynamic-component :component="$embedded ? 'operations.surface' : 'ui.page'" :title="$embedded ? null : 'Mitarbeiterprofil'" :eyebrow="__('app.employees')" :description="$user->email" :auto-intro="!$embedded">
    <x-slot:actions>
        <x-ui.dropdown.page-actions width="48">
            @if($canManageEmployeeTeams)
                <x-dropdown-link wire:click.prevent="openTeamChange">
                    <i class="far fa-users mr-2" aria-hidden="true"></i>Team wechseln
                </x-dropdown-link>
            @endif
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

      @if ($canInviteEmployee && $user->role === 'staff' && ! $user->hasVerifiedEmail())
        <x-ui.feedback.alert type="warning" :inline="true" aria-label="Mitarbeiterzugang" data-account-invitation class="mb-3"
            wire:key="{{ $profilePrefix }}-account-invitation" wire:ignore.self>
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <span role="status">
              @if ($errors->has('accountInvitation'))
                {{ $errors->first('accountInvitation') }}
              @elseif ($accountInvitationNotice)
                Einladung versendet.
              @else
                {{ ! $user->isActive() ? 'Konto inaktiv – bitte zuerst aktivieren.' : 'E-Mail noch nicht bestätigt.' }}
              @endif
            </span>
          <button type="button" class="rt-ui-alert__action" @disabled(!$user->isActive())
              x-on:click.prevent="{{ \App\Support\Ui\ConfirmationAction::alpine(method: 'sendAccountInvitation', arguments: [$user->email], title: 'Mitarbeiter einladen', message: 'Zugangseinladung an '.$user->email.' senden? Der Mitarbeiter legt sein Passwort selbst fest. Ein bisheriger Einladungs- oder Passwortlink wird ersetzt.', variant: 'default', confirmLabel: 'Einladung senden') }}"
              wire:loading.attr="disabled" wire:target="sendAccountInvitation">
            <i class="far fa-envelope" aria-hidden="true"></i>
            <span wire:loading.remove wire:target="sendAccountInvitation">Mitarbeiter einladen</span>
            <span wire:loading wire:target="sendAccountInvitation">Wird versendet …</span>
          </button>
          </div>
        </x-ui.feedback.alert>
      @endif

    <div class="employee-profile__workspace employee-profile__dossier relative" data-autosave-scope>
      <x-ui.autosave-status
          event="employee-profile-field-saved"
          target="savePendingInlineChanges"
          dirty-target="inlineValues"
      />

      @include('livewire.admin.user-profile.partials.identity-card')



      <div class="employee-profile__navigation" data-profile-navigation x-data="{}">
        <nav class="employee-profile__groups" aria-label="Bereiche der Mitarbeiterakte">
            @foreach($profileGroups as $group => $groupTabs)
                @php
                    $groupDefinition = $profileGroupLabels[$group];
                    $groupAction = '$wire.call('.\Illuminate\Support\Js::from('setProfileTab').', '.\Illuminate\Support\Js::from((string) $groupTabs->keys()->first()).').then(() => { if ($el.isConnected) $el.focus({ preventScroll: true }); })';
                @endphp
                <x-ui.buttons.button-basic type="button" mode="link" :x-on:click="$groupAction"
                    wire:key="{{ $profilePrefix }}-group-{{ \Illuminate\Support\Str::slug($group) }}"
                    wire:loading.attr="disabled" wire:target="setProfileTab"
                    :aria-current="$profileGroup === $group ? 'page' : 'false'"
                    :data-profile-group="$group"
                    class="employee-profile__group" :title="$group">
                    <span class="employee-profile__group-icon"><i class="{{ $groupDefinition['icon'] }}" aria-hidden="true"></i></span>
                    <span class="employee-profile__group-label">{{ $groupDefinition['label'] }}</span>
                </x-ui.buttons.button-basic>
            @endforeach
            <span class="employee-profile__selection-marker" data-profile-marker="main" aria-hidden="true" wire:ignore
                x-data="rtProfileTabMotion({ variant: 'main' })"></span>
        </nav>
        <div class="employee-profile__tab-row">
            <x-operations.panel.tabs :tabs="$profileGroups[$profileGroup]->all()"
                :label="$profileGroup" :id-prefix="$profilePrefix" :active="$profileTab"
                action="setProfileTab" class="employee-profile__tabs">
                <x-slot:selectionIndicator>
                    <span class="employee-profile__selection-marker" data-profile-marker="sub" aria-hidden="true" wire:ignore
                        x-data="rtProfileTabMotion({ variant: 'sub' })"></span>
                </x-slot:selectionIndicator>
            </x-operations.panel.tabs>
        </div>
        <div class="employee-profile__loading" wire:loading.delay wire:target="setProfileTab" role="status">
            <i class="far fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">Bereich wird geladen …</span>
        </div>
      </div>

    <div class="employee-profile__content" x-data="{}" wire:loading.class="employee-profile__content--loading" wire:target="setProfileTab">
        @if($profileTab === 'teams' && $canManageEmployeeTeams)
            <x-operations.panel.tab name="teams" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel">
                @include('livewire.admin.user-profile.partials.teams')
            </x-operations.panel.tab>
        @endif
        @foreach($profileGroups[$profileGroup] as $tabId => $definition)
            @if($tabId !== $profileTab)
                <section hidden role="tabpanel" id="{{ $profilePrefix }}-panel-{{ $tabId }}" aria-labelledby="{{ $profilePrefix }}-tab-{{ $tabId }}"></section>
            @endif
        @endforeach
        @if(isset($employeeProfileTabs['operationsOverview']) && $profileTab === 'operationsOverview')
            <x-operations.panel.tab name="operationsOverview" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel">
                <livewire:profile.employee-operations :user-id="(int) $user->id" view-mode="overview" :key="'employee-operations-overview-'.$user->id" />
            </x-operations.panel.tab>
        @endif

        @if(isset($employeeProfileTabs['shifts']) && $profileTab === 'shifts')
            <x-operations.panel.tab name="shifts" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel">
                <livewire:profile.employee-operations :user-id="(int) $user->id" view-mode="shifts" :key="'employee-operations-shifts-'.$user->id" />
            </x-operations.panel.tab>
        @endif

        @if(isset($employeeProfileTabs['regions']) && $profileTab === 'regions')
            <x-operations.panel.tab name="regions" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel">
                <livewire:profile.employee-regions :user-id="(int) $user->id" :key="'employee-regions-'.$user->id" />
            </x-operations.panel.tab>
        @endif

        {{-- Native inline-edit actions remain within the existing server-owned tab. --}}
        @if(isset($employeeProfileTabs['outlook']) && $profileTab === 'outlook')
            <x-operations.panel.tab name="outlook" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel">
                <livewire:admin.user-profile.outlook-preferences :user-id="(int) $user->id" :key="'employee-outlook-'.$user->id" />
            </x-operations.panel.tab>
        @endif

        @if($profileTab === 'userDetails')
            <x-operations.panel.tab name="userDetails" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel">
                @include('livewire.admin.user-profile.partials.overview')
            </x-operations.panel.tab>
        @endif

        @if(isset($employeeProfileTabs['locations']) && $profileTab === 'locations')
            <x-operations.panel.tab name="locations" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel">
                <livewire:profile.employee-location-history :user-id="(int) $user->id" :key="'employee-location-history-'.$user->id" />
            </x-operations.panel.tab>
        @endif

        {{-- TAB: Bemerkungen --}}
        @if(isset($employeeProfileTabs['userNotes']) && $profileTab === 'userNotes')
        <x-operations.panel.tab name="userNotes" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
            <livewire:admin.user-profile.user-notes :user-id="$user->id" :key="'user-notes-'.$user->id" />
        </x-operations.panel.tab>
        @endif

        {{-- TAB: Dateien --}}
        @if(isset($employeeProfileTabs['userFiles']) && $profileTab === 'userFiles')
        <x-operations.panel.tab name="userFiles" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
            <livewire:tools.file-pools.manage-file-pools
                :model-type="\App\Models\User::class"
                :model-id="$user->id"
                :read-only="false"
                :key="'user-files-'.$user->id"
            />
        </x-operations.panel.tab>
        @endif

        {{-- TAB: Nachrichten --}}
        @if(isset($employeeProfileTabs['userMessages']) && $profileTab === 'userMessages')
        <x-operations.panel.tab name="userMessages" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
            @if (class_exists(\App\Livewire\Admin\UserProfile\UserMessages::class))
                <livewire:admin.user-profile.user-messages :user-id="$user->id" :key="'user-messages-'.$user->id" />
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <i class="fad fa-info-circle mr-2"></i>
                    {{ __('app.module_not_available') }}
                </div>
            @endif
        </x-operations.panel.tab>
        @endif

        @if(isset($employeeProfileTabs['devices']) && $profileTab === 'devices')
            <x-operations.panel.tab name="devices" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
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
            </x-operations.panel.tab>
        @endif

        @if ($canViewMasterData && $profileTab === 'masterData')
            <x-operations.panel.tab name="masterData" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
                @include('livewire.admin.user-profile.partials.master-data', ['profile' => $profile])
            </x-operations.panel.tab>
        @endif
        @if ($canViewMasterData && $profileTab === 'documents')
            <x-operations.panel.tab name="documents" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
                <livewire:admin.user-profile.employee-documents :user-id="$user->id" :key="'employee-documents-'.$user->id" />
            </x-operations.panel.tab>
        @endif

        @if ($canViewCompensation && $profileTab === 'compensation')
            <x-operations.panel.tab name="compensation" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
                @include('livewire.admin.user-profile.partials.compensation-data', ['profile' => $profile])
            </x-operations.panel.tab>
        @endif
        @if(isset($employeeProfileTabs[$profileTab]['component']))
            @php($section = $employeeProfileTabs[$profileTab])
            <x-operations.panel.tab :name="$profileTab" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
                @if($section['component'] === 'qualifications')
                    <livewire:operations.personnel-review module="qualifications" :embedded="true" :profile-user-id="(int) $user->id" :key="'profile-qualifications-'.$user->id" />
                @elseif($section['component'] === 'workforce')
                    <livewire:operations.workforce-accounts :tab="$section['tab']" :embedded="true" :profile-user-id="(int) $user->id" :key="'profile-workforce-'.$user->id.'-'.$profileTab" />
                @elseif($section['component'] === 'enhancements')
                    <livewire:operations.personnel-enhancements :tab="$section['tab']" :embedded="true" :profile-user-id="(int) $user->id" :key="'profile-enhancements-'.$user->id.'-'.$profileTab" />
                @endif
            </x-operations.panel.tab>
        @endif
    </div>
    </div>
  </x-dynamic-component>

    @if($canManageEmployeeTeams)
        <livewire:admin.employees.employee-team-modal :key="'profile-team-change-'.$user->id" />
    @endif

    {{-- Compose-Modal fuer den Nachrichten-Tab --}}
    @if(Gate::allows('users.messages.create') && $profileTab === 'userMessages')
        <livewire:admin.users.messages.message-form :key="'profile-message-form'" />
    @endif
</div>
