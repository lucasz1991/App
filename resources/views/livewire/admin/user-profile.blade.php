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
        <x-back-button :href="route($employeesRoute)" />

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
                        x-on:click.prevent='$dispatch("rt-confirm", {
                            title: @js(__("app.delete_user")),
                            message: @js(__("app.delete_user_confirm")),
                            variant: "destructive",
                            confirmLabel: @js(__("app.delete")),
                            action: () => $wire.deleteUser()
                        })'
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

      {{-- Identitaets-Card: klar, horizontal, ohne Cover/ueberlappenden Avatar.
           Die Aurora nimmt die Fluid-Farbwelt des Lade-Orbs dezent auf. --}}
      <div class="relative overflow-hidden rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up" data-rt-glow>
        <span class="rt-profile-aurora" aria-hidden="true"></span>
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            <span class="relative shrink-0">
                <img
                    src="{{ $user->profile_photo_url }}"
                    alt="{{ $user->name }}"
                    class="h-20 w-20 rounded-2xl object-cover shadow-rt-sm ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60"
                >
                @if ($isUserOnline)
                    <span
                        class="absolute -bottom-1 -right-1 grid h-4 w-4 place-items-center rounded-full bg-rt-surface dark:bg-rt-dark-surface"
                        title="{{ __('app.online') }}"
                    >
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
                    </span>
                @endif
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="min-w-[12rem] max-w-full flex-1 sm:max-w-xl">
                        <x-ui.inline-edit-field
                            id="employee-header-name"
                            field="name"
                            type="text"
                            :can-edit="$canEditEmployee"
                            autocomplete="name"
                            align="left"
                        >
                            <span class="truncate text-xl font-semibold tracking-tight">{{ $user->name }}</span>
                        </x-ui.inline-edit-field>
                    </div>
                    <x-ui.badge :color="$roleColor">
                        <i class="far fa-user-tag"></i>
                        {{ $roleLabel }}
                    </x-ui.badge>
                </div>
                <div class="mt-0.5 max-w-full sm:max-w-xl">
                    <x-ui.inline-edit-field
                        id="employee-header-email"
                        field="email"
                        type="email"
                        :can-edit="$canEditEmployee"
                        autocomplete="email"
                        align="left"
                    >
                        <span class="truncate text-sm text-rt-muted dark:text-rt-dark-muted">{{ $user->email }}</span>
                    </x-ui.inline-edit-field>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$user->isActive() ? 'green' : 'red'">
                        <span class="h-1.5 w-1.5 rounded-full {{ $user->isActive() ? 'bg-emerald-500 dark:bg-emerald-400' : 'bg-red-500 dark:bg-red-400' }}"></span>
                        {{ $user->isActive() ? ucfirst(__('app.active')) : ucfirst(__('app.inactive')) }}
                    </x-ui.badge>

                    @if ($isUserOnline)
                        <x-ui.badge color="green">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"></span>
                            {{ __('app.online') }}
                        </x-ui.badge>
                    @endif

                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rt-surface-muted px-2.5 py-0.5 text-xs text-rt-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                        <i class="far fa-clock"></i>
                        {{ __('app.last_online') }}:
                        <span class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $lastActivityAt ? $lastActivityAt->format('d.m.Y H:i') : __('app.never') }}</span>
                    </span>

                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rt-surface-muted px-2.5 py-0.5 text-xs text-rt-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                        <i class="far fa-hashtag"></i>
                        {{ __('app.user_id') }}: <span class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $user->id }}</span>
                    </span>
                </div>
            </div>
        </div>
      </div>

    @php
        $employeeProfileTabs = [
            'userDetails' => ['label' => __('app.details'), 'icon' => 'fad fa-id-card'],
            'userNotes' => ['label' => __('app.notes'), 'icon' => 'fad fa-sticky-note'],
            'userFiles' => ['label' => __('app.files'), 'icon' => 'fad fa-folder-open'],
            'userMessages' => ['label' => __('app.messages'), 'icon' => 'fad fa-envelope'],
        ];
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
        <x-ui.accordion.tab-panel for="userDetails" panelClass="space-y-4">
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
        <x-ui.accordion.tab-panel for="userNotes" panelClass="space-y-4 rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 z-10 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <livewire:admin.user-profile.user-notes :user-id="$user->id" :key="'user-notes-'.$user->id" />
        </x-ui.accordion.tab-panel>

        {{-- TAB: Dateien --}}
        <x-ui.accordion.tab-panel for="userFiles" panelClass="space-y-4 rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 z-10 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <livewire:tools.file-pools.manage-file-pools
                :model-type="\App\Models\User::class"
                :model-id="$user->id"
                :read-only="false"
                :key="'user-files-'.$user->id"
            />
        </x-ui.accordion.tab-panel>

        {{-- TAB: Nachrichten --}}
        <x-ui.accordion.tab-panel for="userMessages" panelClass="space-y-4 rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 z-10 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            @if (class_exists(\App\Livewire\Admin\UserProfile\UserMessages::class))
                <livewire:admin.user-profile.user-messages :user-id="$user->id" :key="'user-messages-'.$user->id" />
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <i class="fad fa-info-circle mr-2"></i>
                    {{ __('app.module_not_available') }}
                </div>
            @endif
        </x-ui.accordion.tab-panel>

        @if ($canViewMasterData)
            <x-ui.accordion.tab-panel for="masterData" panelClass="space-y-4">
                @include('livewire.admin.user-profile.partials.master-data', ['profile' => $profile])
            </x-ui.accordion.tab-panel>
            <x-ui.accordion.tab-panel for="documents" panelClass="space-y-4">
                <livewire:admin.user-profile.employee-documents :user-id="$user->id" :key="'employee-documents-'.$user->id" />
            </x-ui.accordion.tab-panel>
        @endif

        @if ($canViewCompensation)
            <x-ui.accordion.tab-panel for="compensation" panelClass="space-y-4">
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
