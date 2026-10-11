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
    $profileGroup = $employeeProfileTabs[$profileTab]['group'] ?? 'Profil & Stammdaten';
    $profileGroupLabels = [
        'Profil & Stammdaten' => ['label' => 'Profil', 'icon' => 'far fa-user'],
        'Personalakte' => ['label' => 'Personalakte', 'icon' => 'far fa-folder-open'],
        'Entwicklung & Aufgaben' => ['label' => 'Entwicklung', 'icon' => 'far fa-graduation-cap'],
        'Arbeitszeit & Regeln' => ['label' => 'Arbeitszeit', 'icon' => 'far fa-clock'],
    ];
    $profilePrefix = 'employee-profile-'.$user->id;
  @endphp

  <x-dynamic-component :component="$embedded ? 'operations.surface' : 'ui.page'" :title="$embedded ? null : 'Mitarbeiterprofil'" :eyebrow="__('app.employees')" :description="$user->email" :auto-intro="!$embedded">
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

    <div class="employee-profile__workspace relative" data-autosave-scope>
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
                    wire:loading.attr="disabled" wire:target="setProfileTab"
                    :aria-current="$profileGroup === $group ? 'page' : 'false'"
                    class="employee-profile__group" :title="$group">
                    <i class="{{ $groupDefinition['icon'] }}" aria-hidden="true"></i>
                    <span>{{ $groupDefinition['label'] }}</span>
                </x-ui.buttons.button-basic>
            @endforeach
        </nav>
        <div class="employee-profile__tab-row">
            <x-operations.panel.tabs :tabs="$profileGroups[$profileGroup]->all()"
                :label="$profileGroup" :id-prefix="$profilePrefix" :active="$profileTab"
                action="setProfileTab" class="employee-profile__tabs" />
        </div>
        <div class="employee-profile__loading" wire:loading.delay wire:target="setProfileTab" role="status">
            <i class="far fa-spinner fa-spin" aria-hidden="true"></i> Bereich wird geladen …
        </div>
      </div>

    <div class="employee-profile__content" x-data="{}" wire:loading.class="employee-profile__content--loading" wire:target="setProfileTab">
        @foreach($profileGroups[$profileGroup] as $tabId => $definition)
            @if($tabId !== $profileTab)
                <section hidden role="tabpanel" id="{{ $profilePrefix }}-panel-{{ $tabId }}" aria-labelledby="{{ $profilePrefix }}-tab-{{ $tabId }}"></section>
            @endif
        @endforeach
        {{-- Native inline-edit actions remain within the existing server-owned tab. --}}
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

    {{-- Compose-Modal fuer den Nachrichten-Tab --}}
    @if(Gate::allows('users.messages.create') && $profileTab === 'userMessages')
        <livewire:admin.users.messages.message-form :key="'profile-message-form'" />
    @endif
</div>
