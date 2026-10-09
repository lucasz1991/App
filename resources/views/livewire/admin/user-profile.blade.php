<div class="employee-workspace employee-profile" data-employee-profile-entry="{{ \App\Support\Operations\OperationsPages::url('people') }}">
  @php
    $roleLabel = match ($user->role) {
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

  <x-dynamic-component :component="$embedded ? 'operations.surface' : 'ui.page'" :title="$embedded ? null : $user->name" :eyebrow="__('app.employees')" :description="$user->email" :auto-intro="!$embedded">
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
        {{-- TAB: Details — klare zweispaltige Info-Sektionen --}}
        {{-- Wichtig: kein display-Utility (grid) direkt auf dem x-show-Panel,
             sonst gewinnt Tailwinds !important gegen Alpines inline display:none. --}}
        @if($profileTab === 'userDetails')
        <x-operations.panel.tab name="userDetails" :id-prefix="$profilePrefix" model="$wire.profileTab" class="employee-profile__panel space-y-4">
          <div class="grid gap-4 lg:grid-cols-2" data-anim-stagger>
            {{-- Persoenliche Daten --}}
            <section class="employee-detail-group rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                        <i class="far fa-user text-sm"></i>
                    </span>
                    {{ __('app.personal_data') }}
                </h3>
                <dl class="employee-detail-list mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                    <div class="employee-detail-row flex items-start justify-between gap-4 py-2.5">
                        <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.username') }}</dt>
                        <dd class="min-w-0 flex-1">
                            <x-ui.inline-edit-field id="employee-details-name" field="name" :can-edit="$canEditEmployee">
                                {{ $user->name }}
                            </x-ui.inline-edit-field>
                        </dd>
                    </div>
                    <div class="employee-detail-row flex items-start justify-between gap-4 py-2.5">
                        <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.birth_date') }}</dt>
                        <dd class="min-w-0 flex-1">
                            <x-ui.inline-edit-field id="employee-details-birth-date" field="birth_date" type="date" :can-edit="$canEditEmployee">
                                {{ $profile?->birth_date?->format('d.m.Y') ?: __('app.not_set') }}
                            </x-ui.inline-edit-field>
                        </dd>
                    </div>
                    @if ($canViewMasterData)
                        <div class="employee-detail-row flex items-start justify-between gap-4 py-2.5">
                            <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.personnel_nr') }}</dt>
                            <dd class="min-w-0 flex-1">
                                <x-ui.inline-edit-field id="employee-details-personnel-nr" field="personnel_nr" :can-edit="$canEditMasterData">
                                    {{ $profile?->personnel_nr ?: __('app.not_set') }}
                                </x-ui.inline-edit-field>
                            </dd>
                        </div>
                    @endif
                    <div class="employee-detail-row flex items-start justify-between gap-4 py-2.5">
                        <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.registered_at') }}</dt>
                        <dd class="pt-2 text-right text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $user->created_at->format('d.m.Y') }}</dd>
                    </div>
                </dl>
            </section>

            {{-- Kontakt & Anschrift --}}
            <section class="employee-detail-group rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                        <i class="far fa-address-card text-sm"></i>
                    </span>
                    {{ __('app.contact') }}
                </h3>
                <dl class="employee-detail-list mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
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
                        <div class="employee-detail-row flex items-start justify-between gap-4 py-2.5">
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
