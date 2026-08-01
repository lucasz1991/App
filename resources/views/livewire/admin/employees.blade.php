<div>
    <x-ui.page
        :title="__('app.employees')"
        :count="number_format($employeesTotal, 0, ',', '.')"
    >
        <x-slot:actions>
            <x-ui.dropdown.page-actions>
                <x-dropdown-link wire:click.prevent="openCreate" :can="'employees.create'" :title="__('app.new_employee_hint')">
                    <i class="far fa-plus mr-2"></i>
                    {{ __('app.new_employee') }}
                </x-dropdown-link>
                <x-dropdown-link wire:click.prevent="openInvite" :can="'employees.create'" :title="__('app.invite_employee_hint')">
                    <i class="far fa-paper-plane mr-2"></i>
                    {{ __('app.invite_employee') }}
                </x-dropdown-link>
                <x-dropdown-link wire:click.prevent="openTeamRbacModal" :can="'roles.manage'" :title="__('app.teams_permissions_hint')">
                    <i class="far fa-shield-alt mr-2"></i>
                    {{ __('app.teams_permissions') }}
                </x-dropdown-link>
            </x-ui.dropdown.page-actions>
        </x-slot:actions>

        {{-- Listen-Toolbar: links Massenauswahl, rechts Suche/Filter --}}
        <x-tables.toolbar
            data-anim="fade-up"
            :single-line="true"
            :filter-count="$this->activeFilterCount"
        >
            <x-slot:bulk>
                <x-tables.bulk-actions :count="count($selectedEmployees)" select-all="toggleSelectAll">
                    <x-dropdown-link href="#" wire:click.prevent="clearSelection" tone="danger">
                        <i class="far fa-align-slash mr-2"></i>
                        {{ __('app.clear_selection') }}
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="messageSelected" :can="'users.messages.create'">
                        <i class="far fa-paper-plane mr-2"></i>
                        {{ __('app.compose_message') }}
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="exportSelected" tone="success">
                        <i class="far fa-download mr-2"></i>
                        {{ __('app.export') }}
                    </x-dropdown-link>
                </x-tables.bulk-actions>
            </x-slot:bulk>

            <x-slot:search>
                <x-tables.search-field
                    resultsCount="{{ $employees->count() }}"
                    wire:model.live="search"
                />
            </x-slot:search>

            {{-- Rolle --}}
            <div class="w-full lg:w-40">
                <x-ui.forms.select wire:model.live="role" :aria-label="__('app.role')">
                    <option value="">{{ __('app.all_roles') }}</option>
                    <option value="admin">{{ __('app.role_admin') }}</option>
                    <option value="staff">{{ __('app.role_staff') }}</option>
                </x-ui.forms.select>
            </div>
            {{-- Kontostatus --}}
            <div class="w-full lg:w-40">
                <x-ui.forms.select wire:model.live="accountStatus" :aria-label="__('app.status')">
                    <option value="">{{ __('app.all_statuses') }}</option>
                    <option value="active">{{ ucfirst(__('app.active')) }}</option>
                    <option value="inactive">{{ ucfirst(__('app.inactive')) }}</option>
                </x-ui.forms.select>
            </div>
            {{-- Team --}}
            <div class="w-full lg:w-44">
                <x-ui.forms.select wire:model.live="teamId" :aria-label="__('app.team')">
                    <option value="">{{ __('app.all_teams') }}</option>
                    @foreach($teams as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </x-ui.forms.select>
            </div>
            {{-- Pro Seite --}}
            <div class="w-full lg:w-36">
                <x-ui.forms.select wire:model.live="perPage" :aria-label="__('app.per_page', ['count' => $perPage])">
                    <option value="15">{{ __('app.per_page', ['count' => 15]) }}</option>
                    <option value="30">{{ __('app.per_page', ['count' => 30]) }}</option>
                    <option value="50">{{ __('app.per_page', ['count' => 50]) }}</option>
                    <option value="100">{{ __('app.per_page', ['count' => 100]) }}</option>
                </x-ui.forms.select>
            </div>

            @if ($this->activeFilterCount > 0)
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="inline-flex h-11 shrink-0 items-center gap-2 rounded-lg px-3 text-sm font-medium text-rt-muted transition-all duration-300 ease-rt-spring hover:bg-rt-red/10 hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent/10 dark:hover:text-rt-dark-accent"
                    title="{{ __('app.reset_filters') }}"
                >
                    <i class="far fa-filter-slash" aria-hidden="true"></i>
                    <span class="hidden xl:inline">{{ __('app.reset_filters') }}</span>
                </button>
            @endif
        </x-tables.toolbar>

        {{-- Tabelle --}}
        <div class="w-full" data-anim="fade-up" data-anim-delay="0.05">
            <div class="rt-employee-mobile-header grid grid-cols-[minmax(0,1.3fr)_minmax(5.5rem,0.7fr)] gap-2 px-3 py-2.5 pr-14 text-[10px] font-bold uppercase tracking-[0.08em] md:hidden">
                <span>{{ __('app.name') }}</span>
                <span>{{ __('app.status') }}</span>
            </div>
            <x-tables.table
                class="rt-employee-table"
                :flush-top="true"
                :columns="[
                    ['label'=>__('app.name'),'key'=>'name','width'=>'34%','sortable'=>true,'hideOn'=>'none'],
                    ['label'=>__('app.status'),'key'=>'status','width'=>'26%','sortable'=>false,'hideOn'=>'none'],
                    ['label'=>__('app.position'),'key'=>'position','width'=>'20%','sortable'=>false,'hideOn'=>'md'],
                    ['label'=>__('app.last_active'),'key'=>'last_activity_at','width'=>'20%','sortable'=>true,'hideOn'=>'md'],
                ]"
                :items="$employees"
                :selected-items="$selectedEmployees"
                selection-action="toggleEmployeeSelection"
                :detail-route="auth()->user()->canViewManagementDashboard()
                    ? (auth()->user()->usesAdminLayout() ? 'admin.user-profile' : 'employees.show')
                    : null"
                row-view="components.tables.rows.employees.employee-row"
                actions-view="components.tables.rows.employees.employee-actions"
                :sort-by="$sortBy ?? null"
                :sort-dir="$sortDir ?? 'asc'"
            />
            <div class="py-4">
                {{ $employees->links() }}
            </div>
        </div>
    </x-ui.page>

    <livewire:admin.employees.employee-form-modal :key="'employee-form-modal'" />
    <livewire:admin.employees.team-rbac-modal :key="'employee-team-rbac-modal'" />
    <livewire:admin.employees.invite-employee-modal :key="'employee-invite-modal'" />
    <livewire:admin.users.messages.message-form :key="'employee-message-form'" />
</div>
